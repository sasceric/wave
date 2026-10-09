<?php

namespace App\Controller\Api;

use App\Account\EmailTemplateRenderer;
use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\SupportTicket;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Localization\LocalizedRouteMap;
use App\Service\SiteOrigin;
use App\Support\SupportCopy;
use App\Support\TicketAttachments;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class SupportTicketController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly SupportCopy $copy,
        private readonly TicketAttachments $attachments,
        private readonly SiteOrigin $origin,
        private readonly LocalizedRouteMap $routes,
    ) {
    }

    #[Route('/api/support/tickets', methods: ['POST'])]
    public function create(
        Request $request,
        #[Target('wave_support_ip')] RateLimiterFactoryInterface $limiter,
        MailerInterface $mailer,
        EmailTemplateRenderer $templates,
        LoggerInterface $logger,
        #[Autowire('%env(MAIL_FROM_ADDRESS)%')] string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')] string $fromName,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->response(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        if ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale)) return $error;
        $limit = $limiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            return $this->response(['error' => ApiMessages::get('rate_limited', $locale)], 429, ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]);
        }
        $input = $request->getContentTypeFormat() === 'json' ? JsonPayload::fromRequest($request) : $request->request->all();
        $data = [];
        foreach (['name', 'email', 'phone', 'kind', 'category', 'title', 'description', 'submissionKey', 'trap'] as $field) {
            $data[$field] = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
        }
        if ($data['trap'] !== '' || mb_strlen($data['name']) < 2 || mb_strlen($data['name']) > 120
            || preg_match('/[\x00-\x1f\x7f]/', $data['name'])
            || strlen($data['email']) > 180 || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)
            || ($data['phone'] !== '' && !preg_match('/^\+?[0-9 ()-]{5,40}$/D', $data['phone']))
            || !in_array($data['kind'], ['problem', 'question', 'suggestion'], true)
            || !in_array($data['category'], ['account', 'campaigns', 'services', 'messages', 'technical', 'other'], true)
            || mb_strlen($data['title']) < 3 || mb_strlen($data['title']) > 160
            || preg_match('/[\x00-\x1f\x7f]/', $data['title'])
            || mb_strlen($data['description']) < 20 || mb_strlen($data['description']) > 2000
            || !preg_match('/^[a-f0-9]{64}$/D', $data['submissionKey'])) {
            return $this->response(['error' => $this->copy->get($locale, 'invalid')], 400);
        }
        $files = $request->files->all('attachments');
        if (!$this->attachments->validate($files)) return $this->response(['error' => $this->copy->get($locale, 'invalidFiles')], 400);
        // A retry after a lost response returns the existing ticket instead of creating another.
        $existing = $this->em->getRepository(SupportTicket::class)->findOneBy(['submissionKey' => $data['submissionKey']]);
        if ($existing instanceof SupportTicket) return $this->created($existing);
        $ticket = new SupportTicket($data, $locale, $data['submissionKey']);
        try {
            $stored = $this->attachments->store($ticket, $files);
            $ticket->setAttachments($stored);
            $this->em->persist($ticket);
            $this->em->flush();
        } catch (\InvalidArgumentException $error) {
            $this->attachments->remove($ticket, $ticket->getAttachments());

            return $this->response(['error' => $this->copy->get($locale, 'invalidFiles')], 400);
        } catch (\Throwable $error) {
            $this->attachments->remove($ticket, $ticket->getAttachments());
            $logger->error('support.ticket.save_failed', ['exception' => $error]);

            return $this->response(['error' => $this->copy->get($locale, 'saveFailed')], 503);
        }
        $trackingUrl = $this->trackingUrl($ticket);
        $heading = $this->copy->get($locale, 'successTitle');
        $message = $this->copy->get($locale, 'emailMessage').' #'.$ticket->getNumber();
        try {
            $email = (new Email())->from(new Address($fromAddress, $fromName))->to(new Address($ticket->getEmail(), $ticket->getName()))
                ->subject($this->copy->get($locale, 'emailSubject').' #'.$ticket->getNumber())
                ->text($heading."\n\n".$message."\n\n".$trackingUrl)
                ->html($templates->render('account_action', [
                    'locale' => $locale, 'preheader' => $message, 'eyebrow' => 'WAVE', 'heading' => $heading,
                    'greeting' => $ticket->getName(), 'message' => $message, 'buttonLabel' => $this->copy->get($locale, 'track'),
                    'actionUrl' => $trackingUrl, 'expiration' => '', 'security' => $this->copy->get($locale, 'keepLink'),
                    'fallback' => $this->copy->get($locale, 'emailFallback'), 'footer' => $this->copy->get($locale, 'emailFooter'),
                ]));
            $mailer->send($email);
            $ticket->markReceiptSent();
            $this->em->flush();
        } catch (\Throwable $error) {
            // The report remains accepted even when receipt delivery is unavailable.
            $logger->error('support.ticket.receipt_failed', ['ticket_id' => $ticket->getId(), 'exception' => $error]);
        }

        return $this->created($ticket, 201);
    }

    #[Route('/api/support/tickets/track', methods: ['POST'])]
    public function track(Request $request, #[Target('wave_support_tracking')] RateLimiterFactoryInterface $limiter): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->response(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        if ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale)) return $error;
        if (!$limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) return $this->response(['error' => ApiMessages::get('rate_limited', $locale)], 429);
        $data = JsonPayload::fromRequest($request);
        $token = $data['token'] ?? null;
        $ticket = is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)
            ? $this->em->getRepository(SupportTicket::class)->findOneBy(['trackingToken' => $token]) : null;
        if (!$ticket instanceof SupportTicket) return $this->response(['error' => $this->copy->get($locale, 'notFound')], 404);

        return $this->response(['data' => ['number' => $ticket->getNumber(), 'title' => $ticket->getTitle(), 'status' => $ticket->getStatus(), 'createdAt' => $ticket->getCreatedAt()->format(DATE_ATOM)]]);
    }

    #[Route('/api/admin/support-tickets', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->response(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $access = ApiAccess::requireRole($this->security, 'ROLE_ADMIN', $locale);
        if ($access instanceof JsonResponse) return $access;
        $page = $request->query->getInt('page', 1);
        $size = $request->query->getInt('pageSize', 25);
        if ($page < 1 || $page > 1000000 || !in_array($size, [25, 50, 100], true)) return $this->response(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        $repository = $this->em->getRepository(SupportTicket::class);
        $total = $repository->count([]);
        $page = min($page, max(1, (int) ceil($total / $size)));
        $rows = $repository->findBy([], ['id' => 'DESC'], $size, ($page - 1) * $size);

        return $this->response(['data' => array_map(static fn (SupportTicket $ticket): array => [
            'id' => $ticket->getId(), 'number' => $ticket->getNumber(), 'name' => $ticket->getName(), 'email' => $ticket->getEmail(),
            'phone' => $ticket->getPhone(), 'kind' => $ticket->getKind(), 'category' => $ticket->getCategory(), 'title' => $ticket->getTitle(),
            'description' => $ticket->getDescription(), 'locale' => $ticket->getLocale(), 'status' => $ticket->getStatus(),
            'createdAt' => $ticket->getCreatedAt()->format(DATE_ATOM),
            'attachments' => array_map(static fn (array $file, int $index): array => ['name' => $file['name'], 'size' => $file['size'], 'url' => '/api/admin/support-tickets/'.$ticket->getId().'/attachments/'.$index], $ticket->getAttachments(), array_keys($ticket->getAttachments())),
        ], $rows), 'meta' => ['page' => $page, 'pageSize' => $size, 'total' => $total]]);
    }

    #[Route('/api/admin/support-tickets/{id}/attachments/{index}', requirements: ['id' => '\d+', 'index' => '\d+'], methods: ['GET'])]
    public function download(Request $request, int $id, int $index): BinaryFileResponse|JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->response(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $access = ApiAccess::requireRole($this->security, 'ROLE_ADMIN', $locale);
        if ($access instanceof JsonResponse) return $access;
        $ticket = $this->em->find(SupportTicket::class, $id);
        $file = $ticket?->getAttachments()[$index] ?? null;
        if (!$ticket instanceof SupportTicket || !$file || !is_file($path = $this->attachments->path($ticket, $file))) return $this->response(['error' => $this->copy->get($locale, 'notFound')], 404);
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file['name'], 'attachment');
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function trackingUrl(SupportTicket $ticket): string
    {
        return $this->origin->url($this->routes->localizedPath('support-track', $ticket->getLocale())).'#token='.$ticket->getTrackingToken();
    }

    private function created(SupportTicket $ticket, int $status = 200): JsonResponse
    {
        return $this->response(['data' => ['number' => $ticket->getNumber(), 'trackingUrl' => $this->trackingUrl($ticket), 'receiptSent' => $ticket->isReceiptSent()]], $status);
    }

    private function response(array $payload, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($payload, $status, $headers + ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
