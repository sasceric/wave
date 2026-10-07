<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\NewsletterSubscriber;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Newsletter\NewsletterSubscriberEmailSender;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class NewsletterSubscriberController
{
    #[Route('/api/newsletter/subscribers', name: 'api_newsletter_subscribe', methods: ['POST'])]
    public function subscribe(
        Request $request,
        CsrfTokenManagerInterface $tokenManager,
        #[Target('wave_newsletter_ip')] RateLimiterFactoryInterface $newsletterLimiter,
        EntityManagerInterface $entityManager,
        NewsletterSubscriberEmailSender $emailSender,
        LoggerInterface $logger,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }

        $limit = $newsletterLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return new JsonResponse(
                ['error' => ApiMessages::get('rate_limited', $locale)],
                429,
                ['Retry-After' => (string) $retryAfter],
            );
        }

        $data = JsonPayload::fromRequest($request);
        $website = is_array($data) && is_string($data['website'] ?? null) ? trim($data['website']) : '';
        if ($website !== '') {
            return $this->subscribedResponse($locale);
        }

        $email = is_array($data) && is_string($data['email'] ?? null)
            ? mb_strtolower(trim($data['email']))
            : '';
        if ($data === null
            || mb_strlen($email) > 180
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $repository = $entityManager->getRepository(NewsletterSubscriber::class);
        $existingSubscriber = $repository->findOneBy(['email' => $email]);
        if ($existingSubscriber instanceof NewsletterSubscriber) {
            return $this->subscribedResponse($locale);
        }

        $subscriber = new NewsletterSubscriber($email, $locale);
        $entityManager->persist($subscriber);
        $entityManager->flush();

        try {
            $emailSender->sendSubscribed($email, $locale);
        } catch (TransportExceptionInterface $exception) {
            $logger->error('Unable to send the Wave newsletter signup email.', ['exception' => $exception]);
            $entityManager->remove($subscriber);
            $entityManager->flush();

            return new JsonResponse(['error' => ApiMessages::get('newsletter_email_failed', $locale)], 503);
        }

        return $this->subscribedResponse($locale);
    }

    #[Route('/api/admin/subscribers', name: 'api_admin_newsletter_subscribers', methods: ['GET'])]
    public function list(
        Request $request,
        Security $security,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $admin = ApiAccess::requireRole($security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $page = $request->query->getInt('page', 1);
        $pageSize = $request->query->getInt('pageSize', 25);
        if ($page < 1 || !in_array($pageSize, [25, 50, 100], true)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $repository = $entityManager->getRepository(NewsletterSubscriber::class);
        $subscribers = $repository->createQueryBuilder('subscriber')
            ->orderBy('subscriber.subscribedAt', 'DESC')
            ->addOrderBy('subscriber.id', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize + 1)
            ->getQuery()
            ->getResult();
        $hasMore = count($subscribers) > $pageSize;
        if ($hasMore) {
            array_pop($subscribers);
        }

        return new JsonResponse([
            'data' => array_map(
                static fn (NewsletterSubscriber $subscriber): array => [
                    'id' => $subscriber->getId(),
                    'email' => $subscriber->getEmail(),
                    'locale' => $subscriber->getLocale(),
                    'subscribedAt' => $subscriber->getSubscribedAt()->format(DATE_ATOM),
                ],
                $subscribers,
            ),
            'meta' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'hasMore' => $hasMore,
                'total' => $repository->count([]),
            ],
        ]);
    }

    private function subscribedResponse(string $locale): JsonResponse
    {
        return new JsonResponse(['message' => ApiMessages::get('newsletter_subscribed', $locale)]);
    }
}
