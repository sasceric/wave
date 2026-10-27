<?php

namespace App\Controller\Api;

use App\Account\AccountTypeChanges;
use App\Api\{ApiAccess, JsonPayload, UserResource};
use App\Entity\{AccountTypeRequest, Notification, User};
use App\Localization\{ApiMessages, LocaleContext};
use App\Service\NotificationDelivery;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AccountTypeController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly Security $security, private readonly CsrfTokenManagerInterface $csrf, private readonly AccountTypeChanges $changes, private readonly NotificationDelivery $delivery) {}

    #[Route('/api/me/company-details', methods: ['PUT'])]
    public function details(Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->json(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $user = ApiAccess::requireRole($this->security, 'ROLE_COMPANY', $locale, requireApproved: false);
        if ($user instanceof JsonResponse) return $user;
        if ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale)) return $error;
        $company = $user->getCompany();
        if ($company === null) return new JsonResponse([], 404);
        $body = JsonPayload::fromRequest($request) ?? [];
        $values = [];
        foreach (['registrationId' => 60, 'vatId' => 60, 'ownerFullName' => 120] as $key => $max) {
            $value = $body[$key] ?? '';
            if (!is_string($value) || mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) return $this->error('invalid', $locale, 422);
            $values[] = trim($value) ?: null;
        }
        $company->setBusinessDetails(...$values);
        $this->em->flush();
        return $this->json(['data' => UserResource::fromEntity($user, $locale)]);
    }

    #[Route('/api/me/account-type-requests', methods: ['GET', 'POST'])]
    public function own(Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->json(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $user = $this->security->getUser();
        if (!$user instanceof User || $user->isDeleted()) return new JsonResponse(['error' => ApiMessages::get('authentication_required', $locale)], 401);
        if ($request->isMethod('GET')) return $this->json(['data' => array_map(AccountTypeChanges::resource(...), $this->em->getRepository(AccountTypeRequest::class)->findBy(['owner' => $user], ['id' => 'DESC'], 10))]);
        if ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale)) return $error;
        if (!$user->isEmailVerified()) return new JsonResponse(['error' => ApiMessages::get('email_not_verified', $locale)], 403);
        $body = JsonPayload::fromRequest($request) ?? [];
        $source = AccountTypeChanges::type($user);
        $target = $body['targetType'] ?? null;
        $name = $body['profileName'] ?? null;
        $message = $body['message'] ?? '';
        if (!in_array($target, $source === 'creator' ? ['brand', 'agency'] : ['creator'], true) || !is_string($name) || mb_strlen(trim($name)) < 2 || mb_strlen(trim($name)) > 120 || preg_match('/[\x00-\x1f\x7f]/', $name) || !is_string($message) || mb_strlen($message) > 1000) return $this->error('invalid', $locale, 422);
        $item = new AccountTypeRequest($user, $source, $target, trim($name), trim($message));
        $this->em->beginTransaction();
        try {
            $this->em->lock($user, LockMode::PESSIMISTIC_WRITE);
            if ($this->em->getRepository(AccountTypeRequest::class)->count(['owner' => $user, 'status' => 'pending']) > 0) {
                $this->em->rollback(); return $this->error('pendingExists', $locale, 409);
            }
            $this->em->persist($item);
            $notices = [];
            foreach ($this->em->getRepository(User::class)->findBy(['admin' => true, 'deletedAt' => null]) as $admin) {
                $notice = new Notification($admin, 'account_type_change_requested', $user);
                $this->em->persist($notice);
                $notices[] = $notice;
            }
            $this->em->flush(); $this->em->commit();
        } catch (\Throwable $error) { $this->em->rollback(); throw $error; }
        foreach ($notices as $notice) { try { $this->delivery->deliver($notice); } catch (\Throwable) { /* Saved notifications remain available in the bell. */ } }
        return $this->json(['data' => AccountTypeChanges::resource($item)], 201);
    }

    #[Route('/api/me/account-type-requests/{id}', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function withdraw(int $id, Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->json(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $user = $this->security->getUser();
        if (!$user instanceof User) return new JsonResponse([], 401);
        if ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale)) return $error;
        $item = $this->em->getRepository(AccountTypeRequest::class)->findOneBy(['id' => $id, 'owner' => $user]);
        if (!$item instanceof AccountTypeRequest) return new JsonResponse([], 404);
        $this->em->beginTransaction();
        try {
            $this->em->getConnection()->executeQuery('SELECT id FROM account_type_request WHERE id = ? FOR UPDATE', [$item->getId()]);
            $this->em->refresh($item);
            if ($item->getStatus() !== 'pending') { $this->em->rollback(); return $this->error('stale', $locale, 409); }
            $item->review('withdrawn', null);
            $this->em->flush(); $this->em->commit();
        } catch (\Throwable $error) { $this->em->rollback(); throw $error; }
        return $this->json(['data' => AccountTypeChanges::resource($item)]);
    }

    #[Route('/api/admin/account-type-requests', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->json(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $admin = ApiAccess::requireRole($this->security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) return $admin;
        $status = $request->query->getString('status', 'pending');
        $page = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', 25);
        if (!in_array($status, ['all', 'pending', 'approved', 'rejected', 'withdrawn'], true) || !in_array($limit, [25, 50, 100], true)) return $this->error('invalid', $locale, 400);
        $repo = $this->em->getRepository(AccountTypeRequest::class);
        $criteria = $status === 'all' ? [] : ['status' => $status];
        return $this->json(['data' => array_map(static fn (AccountTypeRequest $item): array => AccountTypeChanges::resource($item, true), $repo->findBy($criteria, ['id' => 'DESC'], $limit, ($page - 1) * $limit)), 'meta' => ['page' => $page, 'limit' => $limit, 'total' => $repo->count($criteria)]]);
    }

    #[Route('/api/admin/account-type-requests/{id}', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function review(int $id, Request $request): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return $this->json(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $admin = ApiAccess::requireRole($this->security, 'ROLE_ADMIN', $locale);
        if ($admin instanceof JsonResponse) return $admin;
        if ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale)) return $error;
        $body = JsonPayload::fromRequest($request) ?? [];
        $decision = $body['decision'] ?? null;
        $note = $body['response'] ?? '';
        if (!in_array($decision, ['approved', 'rejected'], true) || !is_string($note) || mb_strlen($note) > 1000 || ($decision === 'rejected' && mb_strlen(trim($note)) < 5)) return $this->error('invalid', $locale, 422);
        $item = $this->em->find(AccountTypeRequest::class, $id);
        if (!$item instanceof AccountTypeRequest) return new JsonResponse([], 404);
        $this->em->beginTransaction();
        try {
            $this->em->getConnection()->executeQuery('SELECT id FROM account_type_request WHERE id = ? FOR UPDATE', [$item->getId()]);
            $this->em->refresh($item);
            $this->em->getConnection()->executeQuery('SELECT id FROM wave_user WHERE id = ? FOR UPDATE', [$item->getOwner()->getId()]);
            $this->em->refresh($item->getOwner());
            if ($item->getOwner()->getCreator()) $this->em->refresh($item->getOwner()->getCreator());
            if ($item->getOwner()->getCompany()) $this->em->refresh($item->getOwner()->getCompany());
            if ($item->getStatus() !== 'pending') throw new \DomainException('stale');
            if ($decision === 'approved') $this->changes->apply($item);
            $item->review($decision, $admin, trim($note));
            $notice = new Notification($item->getOwner(), $decision === 'approved' ? 'account_type_changed' : 'account_type_change_rejected');
            $this->em->persist($notice);
            $this->em->flush(); $this->em->commit();
        } catch (\DomainException $error) { $this->em->rollback(); return $this->error($error->getMessage(), $locale, 409); }
        catch (\Throwable $error) { $this->em->rollback(); throw $error; }
        try { $this->delivery->deliver($notice); } catch (\Throwable) { /* The persistent bell notice remains available. */ }
        return $this->json(['data' => AccountTypeChanges::resource($item, true)]);
    }

    private function error(string $key, string $locale, int $status): JsonResponse
    {
        return $this->json(['error' => ApiMessages::get('account_type_'.$key, $locale)], $status);
    }
    private function json(array $body, int $status = 200): JsonResponse { return new JsonResponse($body, $status, ['Cache-Control' => 'private, no-store']); }
}
