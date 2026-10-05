<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\NotificationResource;
use App\Entity\Notification;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Service\UnreadInboxCounter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SortDirection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class NotificationController
{
    #[Route('/api/me/notifications', name: 'api_my_notifications', methods: ['GET'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        UnreadInboxCounter $unreadInboxCounter,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = ApiAccess::requireParticipant($security, $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $notifications = $entityManager->getRepository(Notification::class)->createQueryBuilder('notification')
            ->andWhere('notification.recipient = :recipient')
            ->andWhere('notification.type <> :chatMessage')
            ->setParameter('recipient', $user)
            ->setParameter('chatMessage', 'chat_message')
            ->orderBy('notification.createdAt', SortDirection::Descending)
            ->setMaxResults(30)
            ->getQuery()
            ->getResult();

        return new JsonResponse([
            'unreadCount' => $unreadInboxCounter->notifications($user),
            'data' => array_map(
                static fn (Notification $notification): array => NotificationResource::fromEntity($notification, $locale),
                $notifications,
            ),
        ]);
    }

    #[Route('/api/me/notifications/{id}/read', name: 'api_my_notification_read', methods: ['POST'])]
    public function markRead(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = ApiAccess::requireParticipant($security, $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $notification = $entityManager->getRepository(Notification::class)->findOneBy([
            'id' => $id,
            'recipient' => $user,
        ]);
        if (!$notification instanceof Notification) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 404);
        }
        $notification->markRead();
        $entityManager->flush();

        return new JsonResponse(['data' => NotificationResource::fromEntity($notification, $locale)]);
    }

    #[Route('/api/me/notifications/read-all', name: 'api_my_notifications_read_all', methods: ['POST'])]
    public function markAllRead(
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = ApiAccess::requireParticipant($security, $locale, requireVerified: true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $entityManager->createQueryBuilder()
            ->update(Notification::class, 'notification')
            ->set('notification.readAt', ':readAt')
            ->where('notification.recipient = :recipient')
            ->andWhere('notification.type <> :chatMessage')
            ->andWhere('notification.readAt IS NULL')
            ->setParameter('readAt', new DateTimeImmutable())
            ->setParameter('recipient', $user)
            ->setParameter('chatMessage', 'chat_message')
            ->getQuery()
            ->execute();

        return new JsonResponse(['data' => ['success' => true]]);
    }
}
