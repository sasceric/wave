<?php

namespace App\Api;

use App\Entity\CreatorInquiry;
use App\Entity\InquiryMessage;
use App\Entity\User;
use App\Account\AccountDeletion;
use App\Localization\ApiMessages;

final class CreatorInquiryResource
{
    public static function fromEntity(
        CreatorInquiry $inquiry,
        User $user,
        ?InquiryMessage $lastMessage = null,
        string $locale = 'bs',
    ): array {
        $isCreator = $inquiry->getCreator()->getOwner()?->getId() === $user->getId();

        return [
            'id' => $inquiry->getId(),
            'readOnly' => AccountDeletion::sharedHistoryDeleted($inquiry->getCreator(), $inquiry->getCompany()),
            'role' => $isCreator ? 'creator' : 'company',
            'creator' => [
                'slug' => $inquiry->getCreator()->getSlug(),
                'displayName' => $inquiry->getCreator()->getOwner()?->isDeleted() ? ApiMessages::get('deleted_account', $locale) : $inquiry->getCreator()->getDisplayName(),
                'deleted' => $inquiry->getCreator()->getOwner()?->isDeleted() ?? false,
                'avatarUrl' => $inquiry->getCreator()->getAvatarMedia()?->getUrl()
                    ?? $inquiry->getCreator()->getAvatarUrl(),
            ],
            'company' => [
                'slug' => $inquiry->getCompany()->getSlug(),
                'name' => $inquiry->getCompany()->getOwner()?->isDeleted() ? ApiMessages::get('deleted_account', $locale) : $inquiry->getCompany()->getName(),
                'deleted' => $inquiry->getCompany()->getOwner()?->isDeleted() ?? false,
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
            'createdAt' => $inquiry->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'respondedAt' => $inquiry->getRespondedAt()?->format(\DateTimeInterface::ATOM),
            'lastMessage' => $lastMessage?->getBody() ?? $inquiry->getMessage(),
            'lastMessageAt' => $lastMessage?->getCreatedAt()->format(\DateTimeInterface::ATOM)
                ?? $inquiry->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'canChat' => $inquiry->getStatus() === 'accepted',
        ];
    }
}
