<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\Currency;
use App\Api\JsonPayload;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\CreatorInquiry;
use App\Entity\InquiryMessage;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CreatorInquiryController
{
    #[Route('/api/creators/{slug}/inquiries', name: 'api_creator_inquiry_create', methods: ['POST'])]
    public function create(
        string $slug,
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
        $user = ApiAccess::requireRole($security, 'ROLE_COMPANY', $locale, true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $company = $user->getCompany();
        if (!$company instanceof Company) {
            return new JsonResponse(['error' => ApiMessages::get('profile_unavailable', $locale)], 409);
        }

        $creator = $entityManager->getRepository(Creator::class)->findOneBy(['slug' => $slug]);
        if (!$creator instanceof Creator
            || ($creator->getOwner() !== null
                && (!$creator->getOwner()->isApproved() || $creator->getOwner()->isHideMyAccount()))
        ) {
            return new JsonResponse(['error' => ApiMessages::get('creator_not_found', $locale)], 404);
        }

        $data = JsonPayload::fromRequest($request);
        $message = is_array($data) && is_string($data['message'] ?? null) ? trim($data['message']) : '';
        $packageId = is_array($data) && is_string($data['packageId'] ?? null) ? trim($data['packageId']) : null;
        $proposedAmount = is_array($data) ? ($data['proposedAmount'] ?? null) : null;
        $currency = is_array($data) ? ($data['currency'] ?? 'BAM') : null;
        if ($data === null
            || mb_strlen($message) < 10
            || mb_strlen($message) > 2000
            || !Currency::isSupported($currency)
            || ($proposedAmount !== null && (!is_int($proposedAmount) || $proposedAmount < 1 || $proposedAmount > 10_000_000))
        ) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $selectedPackage = null;
        if ($packageId !== null && $packageId !== '') {
            foreach ($creator->getPackages() as $package) {
                if (is_array($package) && ($package['id'] ?? null) === $packageId) {
                    $selectedPackage = $package;
                    break;
                }
            }
            if ($selectedPackage === null) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
            }
        } else {
            $packageId = null;
        }

        $inquiry = new CreatorInquiry(
            $creator,
            $company,
            $packageId,
            is_array($selectedPackage) && is_string($selectedPackage['title'] ?? null) ? $selectedPackage['title'] : null,
            is_array($selectedPackage) && is_int($selectedPackage['price'] ?? null) ? $selectedPackage['price'] : null,
            $proposedAmount,
            $message,
            $currency,
            is_array($selectedPackage) && Currency::isSupported($selectedPackage['currency'] ?? null)
                ? $selectedPackage['currency']
                : 'BAM',
        );
        $entityManager->persist($inquiry);
        $entityManager->persist(new InquiryMessage($inquiry, $user, $message));
        $entityManager->flush();

        return new JsonResponse(['data' => self::inquiryResource($inquiry, $user)], 201);
    }

    #[Route('/api/me/inquiries', name: 'api_my_inquiries', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = $security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => ApiMessages::get('authentication_required', $locale)], 401);
        }
        $role = $user->getCreator() instanceof Creator ? 'creator' : ($user->getCompany() instanceof Company ? 'company' : null);
        if ($role === null) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);
        }

        $criteria = $role === 'creator'
            ? ['creator' => $user->getCreator()]
            : ['company' => $user->getCompany()];
        $inquiries = $entityManager->getRepository(CreatorInquiry::class)->findBy($criteria, ['createdAt' => 'DESC']);

        return new JsonResponse([
            'data' => array_map(
                static fn (CreatorInquiry $inquiry): array => self::inquiryResource($inquiry, $user),
                $inquiries,
            ),
        ]);
    }

    #[Route('/api/me/inquiries/{id}/decision', name: 'api_creator_inquiry_decision', methods: ['POST'])]
    public function decide(
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
        $user = ApiAccess::requireRole($security, 'ROLE_CREATOR', $locale, true);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $inquiry = $entityManager->getRepository(CreatorInquiry::class)->find($id);
        if (!$inquiry instanceof CreatorInquiry) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 404);
        }
        if ($inquiry->getCreator()->getOwner()?->getId() !== $user->getId()) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);
        }
        if ($inquiry->getStatus() !== 'pending') {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 409);
        }

        $data = JsonPayload::fromRequest($request);
        $decision = is_array($data) ? ($data['decision'] ?? null) : null;
        if (!in_array($decision, ['accept', 'reject'], true)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        $inquiry->respond($decision === 'accept' ? 'accepted' : 'rejected');
        $entityManager->flush();

        return new JsonResponse(['data' => self::inquiryResource($inquiry, $user)]);
    }

    #[Route('/api/me/inquiries/{id}/messages', name: 'api_creator_inquiry_messages', methods: ['GET'])]
    public function messages(
        int $id,
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $user = $security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => ApiMessages::get('authentication_required', $locale)], 401);
        }
        $inquiry = $entityManager->getRepository(CreatorInquiry::class)->find($id);
        if (!$inquiry instanceof CreatorInquiry) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 404);
        }
        if (!self::isParticipant($inquiry, $user)) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);
        }
        if ($inquiry->getStatus() !== 'accepted') {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 409);
        }

        $messages = $entityManager->getRepository(InquiryMessage::class)->findBy(
            ['inquiry' => $inquiry],
            ['createdAt' => 'ASC', 'id' => 'ASC'],
        );

        return new JsonResponse([
            'data' => array_map(static fn (InquiryMessage $message): array => self::messageResource($message, $inquiry), $messages),
        ]);
    }

    #[Route('/api/me/inquiries/{id}/messages', name: 'api_creator_inquiry_message_create', methods: ['POST'])]
    public function sendMessage(
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
        $user = $security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => ApiMessages::get('authentication_required', $locale)], 401);
        }
        $inquiry = $entityManager->getRepository(CreatorInquiry::class)->find($id);
        if (!$inquiry instanceof CreatorInquiry) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 404);
        }
        if (!self::isParticipant($inquiry, $user)) {
            return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);
        }
        if ($inquiry->getStatus() !== 'accepted') {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 409);
        }

        $data = JsonPayload::fromRequest($request);
        $body = is_array($data) && is_string($data['body'] ?? null) ? trim($data['body']) : '';
        if (mb_strlen($body) < 1 || mb_strlen($body) > 2000) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $message = new InquiryMessage($inquiry, $user, $body);
        $entityManager->persist($message);
        $entityManager->flush();

        return new JsonResponse(['data' => self::messageResource($message, $inquiry)], 201);
    }

    private static function isParticipant(CreatorInquiry $inquiry, User $user): bool
    {
        return $inquiry->getCreator()->getOwner()?->getId() === $user->getId()
            || $inquiry->getCompany()->getOwner()?->getId() === $user->getId();
    }

    private static function inquiryResource(CreatorInquiry $inquiry, User $user): array
    {
        $isCreator = $inquiry->getCreator()->getOwner()?->getId() === $user->getId();

        return [
            'id' => $inquiry->getId(),
            'role' => $isCreator ? 'creator' : 'company',
            'creator' => [
                'slug' => $inquiry->getCreator()->getSlug(),
                'displayName' => $inquiry->getCreator()->getDisplayName(),
            ],
            'company' => [
                'slug' => $inquiry->getCompany()->getSlug(),
                'name' => $inquiry->getCompany()->getName(),
            ],
            'packageId' => $inquiry->getPackageId(),
            'packageTitle' => $inquiry->getPackageTitle(),
            'listedPrice' => $inquiry->getListedPrice(),
            'listedPriceCurrency' => $inquiry->getListedPriceCurrency(),
            'proposedAmount' => $inquiry->getProposedAmount(),
            'currency' => $inquiry->getCurrency(),
            'message' => $inquiry->getMessage(),
            'status' => $inquiry->getStatus(),
            'createdAt' => $inquiry->getCreatedAt()->format(DateTimeInterface::ATOM),
            'respondedAt' => $inquiry->getRespondedAt()?->format(DateTimeInterface::ATOM),
            'canChat' => $inquiry->getStatus() === 'accepted',
        ];
    }

    private static function messageResource(InquiryMessage $message, CreatorInquiry $inquiry): array
    {
        $sender = $message->getSender();
        $creatorOwnerId = $inquiry->getCreator()->getOwner()?->getId();

        return [
            'id' => $message->getId(),
            'senderRole' => $sender->getId() === $creatorOwnerId ? 'creator' : 'company',
            'body' => $message->getBody(),
            'createdAt' => $message->getCreatedAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
