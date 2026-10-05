<?php

namespace App\Controller\Api;

use App\Account\AccountEmailSender;
use App\Api\ApiAccess;
use App\Api\Currency;
use App\Api\JsonPayload;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\CreatorInquiry;
use App\Entity\InquiryMessage;
use App\Entity\Notification;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Service\NotificationDelivery;
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
        AccountEmailSender $accountEmailSender,
        NotificationDelivery $notificationDelivery,
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
        $packageSelectionProvided = is_array($data)
            && (array_key_exists('packageIds', $data)
                || array_key_exists('servicePackage', $data)
                || array_key_exists('other', $data));
        $packageIds = [];
        if (is_array($data) && array_key_exists('packageIds', $data)) {
            if (!is_array($data['packageIds']) || !array_is_list($data['packageIds'])) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
            }
            foreach ($data['packageIds'] as $packageId) {
                if (!is_string($packageId) || '' === trim($packageId) || mb_strlen(trim($packageId)) > 64) {
                    return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
                }
                $packageIds[] = trim($packageId);
            }
        } elseif (is_array($data) && is_string($data['packageId'] ?? null) && '' !== trim($data['packageId'])) {
            $packageIds[] = trim($data['packageId']);
        }
        $servicePackage = is_array($data) ? ($data['servicePackage'] ?? false) : false;
        $other = is_array($data) ? ($data['other'] ?? false) : false;
        $proposedAmount = is_array($data) ? ($data['proposedAmount'] ?? null) : null;
        $currency = is_array($data) ? ($data['currency'] ?? 'BAM') : null;
        if ($data === null
            || mb_strlen($message) < 10
            || mb_strlen($message) > 2000
            || count($packageIds) > 20
            || count(array_unique($packageIds)) !== count($packageIds)
            || !is_bool($servicePackage)
            || !is_bool($other)
            || ($packageSelectionProvided && [] === $packageIds && !$servicePackage && !$other)
            || !Currency::isSupported($currency)
            || ($proposedAmount !== null && (!is_int($proposedAmount) || $proposedAmount < 1 || $proposedAmount > 10_000_000))
        ) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $selectedPackages = [];
        foreach ($packageIds as $packageId) {
            $selectedPackage = null;
            foreach ($creator->getPackages() as $package) {
                if (is_array($package) && ($package['id'] ?? null) === $packageId) {
                    $selectedPackage = $package;
                    break;
                }
            }
            if ($selectedPackage === null) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
            }

            $selectedPackages[] = [
                'type' => 'package',
                'id' => $packageId,
                'title' => is_string($selectedPackage['title'] ?? null) ? $selectedPackage['title'] : '',
                'listedPrice' => is_int($selectedPackage['price'] ?? null) ? $selectedPackage['price'] : null,
                'currency' => Currency::isSupported($selectedPackage['currency'] ?? null)
                    ? $selectedPackage['currency']
                    : 'BAM',
            ];
        }
        if ($servicePackage) {
            $selectedPackages[] = [
                'type' => 'service',
                'id' => null,
                'title' => null,
                'listedPrice' => null,
                'currency' => 'BAM',
            ];
        }
        if ($other) {
            $selectedPackages[] = [
                'type' => 'other',
                'id' => null,
                'title' => null,
                'listedPrice' => null,
                'currency' => 'BAM',
            ];
        }

        $singlePackage = 1 === count($selectedPackages) && 'package' === $selectedPackages[0]['type']
            ? $selectedPackages[0]
            : null;
        $packageTitles = array_values(array_filter(array_map(
            static fn (array $package): ?string => 'package' === $package['type'] && '' !== $package['title']
                ? $package['title']
                : null,
            $selectedPackages,
        )));
        $inquiry = new CreatorInquiry(
            $creator,
            $company,
            $singlePackage['id'] ?? null,
            [] === $packageTitles ? null : mb_substr(implode(', ', $packageTitles), 0, 120),
            $singlePackage['listedPrice'] ?? null,
            $proposedAmount,
            $message,
            $currency,
            $singlePackage['currency'] ?? 'BAM',
            $selectedPackages,
        );
        $entityManager->persist($inquiry);
        $entityManager->persist(new InquiryMessage($inquiry, $user, $message));
        $creatorOwner = $creator->getOwner();
        $notification = null;
        if ($creatorOwner instanceof User) {
            $notification = new Notification($creatorOwner, 'creator_inquiry_received', $user);
            $entityManager->persist($notification);
        }
        $entityManager->flush();
        if ($notification instanceof Notification) {
            $notificationDelivery->deliver($notification);
        }
        if ($creatorOwner instanceof User) {
            $accountEmailSender->sendCreatorInquiryReceived(
                $creatorOwner,
                $company->getName(),
                $creator->getDisplayName(),
                $selectedPackages,
                $proposedAmount,
                $currency,
                $message,
            );
        }

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
                static function (CreatorInquiry $inquiry) use ($entityManager, $user): array {
                    $lastMessage = $inquiry->getStatus() === 'accepted'
                        ? $entityManager->getRepository(InquiryMessage::class)->findOneBy(
                            ['inquiry' => $inquiry],
                            ['createdAt' => 'DESC', 'id' => 'DESC'],
                        )
                        : null;

                    return self::inquiryResource($inquiry, $user, $lastMessage);
                },
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
        AccountEmailSender $accountEmailSender,
        NotificationDelivery $notificationDelivery,
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
        $companyOwner = $inquiry->getCompany()->getOwner();
        $creatorOwner = $inquiry->getCreator()->getOwner();
        $notification = null;
        if ($decision === 'accept' && $companyOwner instanceof User) {
            $notification = new Notification(
                $companyOwner,
                'creator_inquiry_accepted',
                $creatorOwner,
            );
            $entityManager->persist($notification);
        }
        $entityManager->flush();
        if ($notification instanceof Notification) {
            $notificationDelivery->deliver($notification);
        }
        if ($decision === 'accept' && $companyOwner instanceof User) {
            $inquiryId = $inquiry->getId();
            if ($inquiryId === null) {
                throw new \LogicException('Persist an accepted inquiry before sending its notification.');
            }
            $accountEmailSender->sendCreatorInquiryAccepted(
                $companyOwner,
                $inquiryId,
                $inquiry->getCreator()->getDisplayName(),
                $inquiry->getCompany()->getName(),
                $inquiry->getMessage(),
            );
        }

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

    private static function inquiryResource(
        CreatorInquiry $inquiry,
        User $user,
        ?InquiryMessage $lastMessage = null,
    ): array
    {
        $isCreator = $inquiry->getCreator()->getOwner()?->getId() === $user->getId();

        return [
            'id' => $inquiry->getId(),
            'role' => $isCreator ? 'creator' : 'company',
            'creator' => [
                'slug' => $inquiry->getCreator()->getSlug(),
                'displayName' => $inquiry->getCreator()->getDisplayName(),
                'avatarUrl' => $inquiry->getCreator()->getAvatarMedia()?->getUrl()
                    ?? $inquiry->getCreator()->getAvatarUrl(),
            ],
            'company' => [
                'slug' => $inquiry->getCompany()->getSlug(),
                'name' => $inquiry->getCompany()->getName(),
                'logoUrl' => $inquiry->getCompany()->getLogoMedia()?->getUrl()
                    ?? $inquiry->getCompany()->getLogoUrl(),
            ],
            'packageId' => $inquiry->getPackageId(),
            'packageTitle' => $inquiry->getPackageTitle(),
            'selectedPackages' => $inquiry->getSelectedPackages(),
            'listedPrice' => $inquiry->getListedPrice(),
            'listedPriceCurrency' => $inquiry->getListedPriceCurrency(),
            'proposedAmount' => $inquiry->getProposedAmount(),
            'currency' => $inquiry->getCurrency(),
            'message' => $inquiry->getMessage(),
            'status' => $inquiry->getStatus(),
            'createdAt' => $inquiry->getCreatedAt()->format(DateTimeInterface::ATOM),
            'respondedAt' => $inquiry->getRespondedAt()?->format(DateTimeInterface::ATOM),
            'lastMessage' => $lastMessage?->getBody() ?? $inquiry->getMessage(),
            'lastMessageAt' => $lastMessage?->getCreatedAt()->format(DateTimeInterface::ATOM)
                ?? $inquiry->getCreatedAt()->format(DateTimeInterface::ATOM),
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
