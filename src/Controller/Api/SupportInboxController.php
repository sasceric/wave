<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\Notification;
use App\Entity\SupportTicket;
use App\Entity\SupportTicketMessage;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Support\SupportCopy;
use App\Support\TicketAlerts;
use App\Support\TicketAttachments;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/api/{scope}/support-tickets', requirements: ['scope' => 'me|admin'])]
final class SupportInboxController
{
    private const STATUSES = ['open', 'in_progress', 'on_hold', 'resolved'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly TicketAttachments $attachments,
        private readonly TicketAlerts $alerts,
        private readonly SupportCopy $copy,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request, string $scope): JsonResponse
    {
        $user = $this->access($request, $scope);
        if ($user instanceof JsonResponse) return $user;
        // Reports sent before registration can be claimed only by the verified email owner.
        if ($scope === 'me' && $user->isEmailVerified()) {
            $this->em->createQuery('UPDATE App\Entity\SupportTicket t SET t.owner = :user WHERE t.owner IS NULL AND LOWER(t.email) = :email')
                ->setParameter('user', $user)->setParameter('email', strtolower($user->getEmail()))->execute();
        }
        $limit = $request->query->getInt('limit', 30);
        $status = $request->query->get('status', 'all');
        $q = trim((string) $request->query->get('q', ''));
        if ($limit < 1 || $limit > 50 || !in_array($status, ['all', ...self::STATUSES], true) || mb_strlen($q) > 160) return $this->invalid($request);
        $qb = $this->em->getRepository(SupportTicket::class)->createQueryBuilder('t');
        if ($scope === 'me') $qb->andWhere('t.owner = :owner')->setParameter('owner', $user);
        if ($q !== '') {
            $qb->andWhere('(LOWER(t.title) LIKE :q OR LOWER(t.name) LIKE :q)')->setParameter('q', '%'.strtolower(str_replace(['%', '_'], ['\\%', '\\_'], $q)).'%');
        }
        $counts = ['all' => 0, 'open' => 0, 'in_progress' => 0, 'on_hold' => 0, 'resolved' => 0];
        foreach ((clone $qb)->select('t.status AS status, COUNT(t.id) AS total')->groupBy('t.status')->getQuery()->getArrayResult() as $count) {
            $counts[$count['status']] = (int) $count['total'];
            $counts['all'] += (int) $count['total'];
        }
        if ($status !== 'all') $qb->andWhere('t.status = :status')->setParameter('status', $status);
        $cursor = $request->query->get('cursor');
        if ($cursor !== null) {
            $decoded = is_string($cursor) && strlen($cursor) <= 512 ? json_decode(base64_decode($cursor, true) ?: '', true) : null;
            if (!is_array($decoded) || !is_int($decoded['id'] ?? null) || $decoded['id'] < 1 || !is_string($decoded['at'] ?? null)
                || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/D', $decoded['at'])) return $this->invalid($request);
            try { $at = new \DateTimeImmutable($decoded['at']); } catch (\Exception) { return $this->invalid($request); }
            $qb->andWhere('(t.updatedAt < :at OR (t.updatedAt = :at AND t.id < :id))')->setParameter('at', $at)->setParameter('id', $decoded['id']);
        }
        $qb->leftJoin('t.owner', 'owner')->leftJoin('owner.creator', 'creator')->leftJoin('creator.avatarMedia', 'avatar')->leftJoin('owner.company', 'company')->leftJoin('company.logoMedia', 'logo')->addSelect('owner', 'creator', 'avatar', 'company', 'logo');
        $rows = $qb->orderBy('t.updatedAt', 'DESC')->addOrderBy('t.id', 'DESC')->setMaxResults($limit + 1)->getQuery()->getResult();
        $more = count($rows) > $limit;
        if ($more) array_pop($rows);
        $last = end($rows);
        $unread = [];
        if ($rows) {
            foreach ($this->em->createQuery('SELECT IDENTITY(n.supportTicket) AS ticket, COUNT(n.id) AS total FROM App\Entity\Notification n WHERE n.recipient = :user AND n.supportTicket IN (:tickets) AND n.readAt IS NULL GROUP BY n.supportTicket')
                ->setParameter('user', $user)->setParameter('tickets', $rows)->getArrayResult() as $row) $unread[$row['ticket']] = (int) $row['total'];
        }
        return $this->response(['data' => array_map(fn (SupportTicket $ticket): array => $this->summary($ticket) + ['unreadCount' => $unread[$ticket->getId()] ?? 0], $rows),
            'meta' => ['counts' => $counts, 'hasMore' => $more, 'nextCursor' => $more && $last ? base64_encode(json_encode(['at' => $last->getUpdatedAt()->format(DATE_ATOM), 'id' => $last->getId()], JSON_THROW_ON_ERROR)) : null]]);
    }

    #[Route('/staff', methods: ['GET'])]
    public function staff(Request $request, string $scope): JsonResponse
    {
        $user = $this->access($request, 'admin');
        if ($user instanceof JsonResponse) return $user;
        return $this->response(['data' => array_map(fn (User $staff): array => ['id' => $staff->getId(), 'name' => $this->userName($staff)], $this->em->getRepository(User::class)->findBy(['admin' => true]))]);
    }

    #[Route('/{id}', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(Request $request, string $scope, int $id): JsonResponse
    {
        $ticket = $this->ticket($request, $scope, $id);
        if ($ticket instanceof JsonResponse) return $ticket;
        $user = $this->security->getUser();
        $notificationId = $this->em->getRepository(Notification::class)->createQueryBuilder('n')->select('MAX(n.id)')->where('n.recipient = :user AND n.supportTicket = :ticket')->setParameter('user', $user)->setParameter('ticket', $ticket)->getQuery()->getSingleScalarResult();
        $owner = $ticket->getOwner();
        $profile = $owner?->getCreator() ?? $owner?->getCompany();
        return $this->response(['data' => $this->summary($ticket) + [
            'email' => $ticket->getEmail(), 'phone' => $ticket->getPhone(), 'kind' => $ticket->getKind(), 'category' => $ticket->getCategory(),
            'description' => $ticket->getDescription(), 'createdAt' => $ticket->getCreatedAt()->format(DATE_ATOM), 'priority' => $ticket->getPriority(),
            'assignedToId' => $ticket->getAssignedTo()?->getId(), 'attachments' => $this->files($ticket, $ticket->getAttachments(), $scope),
            'owner' => $owner ? ['id' => $owner->getId(), 'name' => $this->userName($owner), 'email' => $owner->getEmail(), 'slug' => $profile?->getSlug(), 'creator' => $owner->getCreator() !== null] : null,
            'throughNotificationId' => (int) $notificationId,
        ]]);
    }

    #[Route('/{id}/messages', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function history(Request $request, string $scope, int $id): JsonResponse
    {
        $ticket = $this->ticket($request, $scope, $id);
        if ($ticket instanceof JsonResponse) return $ticket;
        $limit = $request->query->getInt('limit', 30);
        $before = $request->query->getInt('before', 0);
        if ($limit < 1 || $limit > 50 || $before < 0) return $this->invalid($request);
        $qb = $this->em->getRepository(SupportTicketMessage::class)->createQueryBuilder('m')->where('m.ticket = :ticket')->setParameter('ticket', $ticket);
        if ($scope === 'me') $qb->andWhere('m.internal = false');
        if ($before) $qb->andWhere('m.id < :before')->setParameter('before', $before);
        $qb->leftJoin('m.sender', 'sender')->leftJoin('sender.creator', 'creator')->leftJoin('creator.avatarMedia', 'avatar')->leftJoin('sender.company', 'company')->leftJoin('company.logoMedia', 'logo')->addSelect('sender', 'creator', 'avatar', 'company', 'logo');
        $rows = $qb->orderBy('m.id', 'DESC')->setMaxResults($limit + 1)->getQuery()->getResult();
        $more = count($rows) > $limit;
        if ($more) array_pop($rows);
        $last = end($rows);
        return $this->response(['data' => array_map(fn (SupportTicketMessage $message): array => $this->message($message, $scope), array_reverse($rows)), 'meta' => ['hasMore' => $more, 'nextCursor' => $more && $last ? $last->getId() : null]]);
    }

    #[Route('/{id}/messages', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reply(Request $request, string $scope, int $id, #[Target('wave_support_reply')] RateLimiterFactoryInterface $limiter): JsonResponse
    {
        $ticket = $this->ticket($request, $scope, $id, true);
        if ($ticket instanceof JsonResponse) return $ticket;
        $user = $this->security->getUser();
        if (!$user instanceof User) return $this->missing($request);
        $data = $request->getContentTypeFormat() === 'json' ? JsonPayload::fromRequest($request) : $request->request->all();
        $body = is_string($data['body'] ?? null) ? trim($data['body']) : '';
        $key = $data['submissionKey'] ?? null;
        $internal = ($data['internal'] ?? false) === true || ($data['internal'] ?? '') === '1';
        if ($internal && $scope !== 'admin') return $this->response(['error' => ApiMessages::get('forbidden', LocaleContext::fromRequest($request) ?? 'bs')], 403);
        if (mb_strlen($body) < 1 || mb_strlen($body) > 4000 || !is_string($key) || !preg_match('/^[a-f0-9]{64}$/D', $key)) return $this->invalid($request);
        $existing = $this->em->getRepository(SupportTicketMessage::class)->findOneBy(['ticket' => $ticket, 'submissionKey' => $key]);
        if ($existing) {
            if ($existing->getSender()?->getId() !== $user->getId()) return $this->invalid($request);
            $event = $this->em->getRepository(SupportTicketMessage::class)->findOneBy(['ticket' => $ticket, 'submissionKey' => hash('sha256', 'status:'.$key)]);
            return $this->response(['data' => $this->message($existing, $scope), 'status' => $ticket->getStatus(), 'statusEvent' => $event ? $this->message($event, $scope) : null]);
        }
        if (!$limiter->create((string) $user->getId())->consume()->isAccepted()) return $this->response(['error' => ApiMessages::get('rate_limited', LocaleContext::fromRequest($request) ?? 'bs')], 429);
        $files = $request->files->all('attachments');
        if (!$this->attachments->validate($files)) return $this->response(['error' => $this->copy->get(LocaleContext::fromRequest($request) ?? 'bs', 'invalidFiles')], 400);
        $message = new SupportTicketMessage($ticket, $user, $body, $internal, $key);
        $event = null;
        try {
            $message->setAttachments($this->attachments->store($ticket, $files));
            $this->em->persist($message);
            if (!$internal) {
                $previousStatus = $ticket->getStatus();
                $ticket->touch($body);
                $ticket->setStatus($scope === 'me' ? 'open' : ($ticket->getStatus() === 'open' ? 'in_progress' : $ticket->getStatus()));
                if ($previousStatus !== $ticket->getStatus()) $event = $this->statusEvent($ticket, $user, hash('sha256', 'status:'.$key));
            }
            $this->em->flush();
        } catch (\InvalidArgumentException $error) {
            $this->attachments->remove($ticket, $message->getAttachments());
            return $this->response(['error' => $this->copy->get(LocaleContext::fromRequest($request) ?? 'bs', 'invalidFiles')], 400);
        } catch (\Throwable $error) {
            $this->attachments->remove($ticket, $message->getAttachments());
            throw $error;
        }
        if (!$internal) $this->alerts->send($ticket, $user, 'support_ticket_reply', $scope === 'me');
        return $this->response(['data' => $this->message($message, $scope), 'status' => $ticket->getStatus(), 'statusEvent' => $event ? $this->message($event, $scope) : null], 201);
    }

    #[Route('/{id}', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(Request $request, string $scope, int $id): JsonResponse
    {
        $ticket = $this->ticket($request, 'admin', $id, true);
        if ($ticket instanceof JsonResponse) return $ticket;
        $data = JsonPayload::fromRequest($request);
        if (!is_array($data) || array_diff(array_keys($data), ['status', 'priority', 'category', 'assignedToId'])) return $this->invalid($request);
        if (isset($data['status']) && !in_array($data['status'], self::STATUSES, true)) return $this->invalid($request);
        if (isset($data['priority']) && !in_array($data['priority'], ['low', 'normal', 'high'], true)) return $this->invalid($request);
        if (isset($data['category']) && !in_array($data['category'], ['account', 'campaigns', 'services', 'messages', 'technical', 'other'], true)) return $this->invalid($request);
        $assigned = null;
        if (array_key_exists('assignedToId', $data) && $data['assignedToId'] !== null) {
            if (!is_int($data['assignedToId'])) return $this->invalid($request);
            $assigned = $this->em->find(User::class, $data['assignedToId']);
            if (!$assigned?->hasRole('ROLE_ADMIN')) return $this->invalid($request);
        }
        $changedStatus = isset($data['status']) && $data['status'] !== $ticket->getStatus();
        if (isset($data['status'])) $ticket->setStatus($data['status']);
        if (isset($data['priority'])) $ticket->setPriority($data['priority']);
        if (isset($data['category'])) $ticket->setCategory($data['category']);
        if (array_key_exists('assignedToId', $data)) $ticket->setAssignedTo($assigned);
        $ticket->touch();
        $actor = $this->security->getUser();
        $event = $changedStatus && $actor instanceof User ? $this->statusEvent($ticket, $actor, bin2hex(random_bytes(32))) : null;
        $this->em->flush();
        if ($changedStatus && $actor instanceof User) $this->alerts->send($ticket, $actor, 'support_ticket_updated', false);
        $response = $this->detail($request, $scope, $id);
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $payload['statusEvent'] = $event ? $this->message($event, $scope) : null;
        return $this->response($payload);
    }

    #[Route('/{id}/read', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function read(Request $request, string $scope, int $id): JsonResponse
    {
        $ticket = $this->ticket($request, $scope, $id, true);
        if ($ticket instanceof JsonResponse) return $ticket;
        $through = JsonPayload::fromRequest($request)['throughNotificationId'] ?? null;
        if (!is_int($through) || $through < 0) return $this->invalid($request);
        $this->em->createQuery('UPDATE App\Entity\Notification n SET n.readAt = :now WHERE n.recipient = :user AND n.supportTicket = :ticket AND n.id <= :through AND n.readAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())->setParameter('user', $this->security->getUser())->setParameter('ticket', $ticket)->setParameter('through', $through)->execute();
        return $this->response(['ok' => true]);
    }

    #[Route('/{id}/attachments/{index}', requirements: ['id' => '\d+', 'index' => '\d+'], methods: ['GET'])]
    #[Route('/{id}/messages/{messageId}/attachments/{index}', requirements: ['id' => '\d+', 'messageId' => '\d+', 'index' => '\d+'], methods: ['GET'])]
    #[Route('/{id}/attachments/{index}/preview', defaults: ['preview' => true], requirements: ['id' => '\d+', 'index' => '\d+'], methods: ['GET'])]
    #[Route('/{id}/messages/{messageId}/attachments/{index}/preview', defaults: ['preview' => true], requirements: ['id' => '\d+', 'messageId' => '\d+', 'index' => '\d+'], methods: ['GET'])]
    public function download(Request $request, string $scope, int $id, int $index, ?int $messageId = null, bool $preview = false): BinaryFileResponse|JsonResponse
    {
        $ticket = $this->ticket($request, $scope, $id);
        if ($ticket instanceof JsonResponse) return $ticket;
        $files = $ticket->getAttachments();
        if ($messageId !== null) {
            $message = $this->em->find(SupportTicketMessage::class, $messageId);
            if (!$message || $message->getTicket()->getId() !== $id || ($scope !== 'admin' && $message->isInternal())) return $this->missing($request);
            $files = $message->getAttachments();
        }
        $file = $files[$index] ?? null;
        if (!$file || !is_file($path = $this->attachments->path($ticket, $file))) return $this->missing($request);
        if ($preview && !in_array($file['mime'], ['image/webp', 'image/jpeg', 'image/png', 'image/gif'], true)) return $this->missing($request);
        $response = new BinaryFileResponse($path);
        $response->setContentDisposition($preview ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file['name'], 'attachment');
        $response->headers->set('Content-Type', $preview ? $file['mime'] : 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    private function access(Request $request, string $scope, bool $write = false): User|JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->invalid($request);
        $user = $this->security->getUser();
        if (!$user instanceof User) return $this->response(['error' => ApiMessages::get('authentication_required', $locale)], 401);
        if ($scope === 'admin' && !$user->hasRole('ROLE_ADMIN')) return $this->response(['error' => ApiMessages::get('forbidden', $locale)], 403);
        // Support remains accessible while an account awaits email verification/approval.
        if ($write && ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale))) return $error;
        return $user;
    }

    private function ticket(Request $request, string $scope, int $id, bool $write = false): SupportTicket|JsonResponse
    {
        $user = $this->access($request, $scope, $write);
        if ($user instanceof JsonResponse) return $user;
        $ticket = $this->em->find(SupportTicket::class, $id);
        if (!$ticket || ($scope !== 'admin' && $ticket->getOwner()?->getId() !== $user->getId())) return $this->missing($request);
        return $ticket;
    }

    private function summary(SupportTicket $ticket): array
    {
        $owner = $ticket->getOwner();
        return ['id' => $ticket->getId(), 'number' => $ticket->getNumber(), 'title' => $ticket->getTitle(), 'name' => $ticket->getName(),
            'avatarUrl' => $owner?->getCreator()?->getAvatarMedia()?->getUrl() ?? $owner?->getCreator()?->getAvatarUrl() ?? $owner?->getCompany()?->getLogoMedia()?->getUrl() ?? $owner?->getCompany()?->getLogoUrl(),
            'preview' => $ticket->getLastReplyPreview(), 'status' => $ticket->getStatus(), 'updatedAt' => $ticket->getUpdatedAt()->format(DATE_ATOM)];
    }

    private function message(SupportTicketMessage $message, string $scope): array
    {
        return ['avatarUrl' => $message->getSender()?->getCreator()?->getAvatarMedia()?->getUrl() ?? $message->getSender()?->getCreator()?->getAvatarUrl() ?? $message->getSender()?->getCompany()?->getLogoMedia()?->getUrl() ?? $message->getSender()?->getCompany()?->getLogoUrl(),
            'id' => $message->getId(), 'senderId' => $message->getSender()?->getId(), 'senderName' => $message->getSenderName(), 'staff' => $message->isStaff(),
            'internal' => $message->isInternal(), 'eventStatus' => $message->getEventStatus(), 'body' => $message->getBody(), 'createdAt' => $message->getCreatedAt()->format(DATE_ATOM),
            'attachments' => $this->files($message->getTicket(), $message->getAttachments(), $scope, $message->getId())];
    }

    private function statusEvent(SupportTicket $ticket, User $actor, string $key): SupportTicketMessage
    {
        $label = $this->copy->get($ticket->getLocale(), 'statuses.'.$ticket->getStatus());
        $body = str_replace('{status}', $label, $this->copy->get($ticket->getLocale(), 'statusChangedTo'));
        $event = new SupportTicketMessage($ticket, $actor, $body, false, $key);
        $event->setEventStatus($ticket->getStatus());
        $this->em->persist($event);
        return $event;
    }

    private function files(SupportTicket $ticket, array $files, string $scope, ?int $messageId = null): array
    {
        return array_map(static function (array $file, int $index) use ($ticket, $scope, $messageId): array {
            $url = '/api/'.$scope.'/support-tickets/'.$ticket->getId().($messageId ? '/messages/'.$messageId : '').'/attachments/'.$index;
            return ['name' => $file['name'], 'size' => $file['size'], 'url' => $url,
                'previewUrl' => in_array($file['mime'], ['image/webp', 'image/jpeg', 'image/png', 'image/gif'], true) ? $url.'/preview' : null];
        }, $files, array_keys($files));
    }

    private function userName(User $user): string { return $user->getCreator()?->getDisplayName() ?? $user->getCompany()?->getName() ?? $user->getEmail(); }
    private function missing(Request $request): JsonResponse { return $this->response(['error' => $this->copy->get(LocaleContext::fromRequest($request) ?? 'bs', 'notFound')], 404); }
    private function invalid(Request $request): JsonResponse { return $this->response(['error' => ApiMessages::get('invalid_request', LocaleContext::fromRequest($request) ?? 'bs')], 400); }
    private function response(array $data, int $status = 200): JsonResponse { return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']); }
}
