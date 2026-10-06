<?php

namespace App\Api;

use App\Entity\CreatorInquiry;
use App\Entity\InquiryMessage;
use App\Entity\User;

final class CreatorInquiryResource
{
    public static function fromEntity(
        CreatorInquiry $inquiry,
        User $user,
        ?InquiryMessage $lastMessage = null,
    ): array {
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
            'createdAt' => $inquiry->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'respondedAt' => $inquiry->getRespondedAt()?->format(\DateTimeInterface::ATOM),
            'lastMessage' => $lastMessage?->getBody() ?? $inquiry->getMessage(),
            'lastMessageAt' => $lastMessage?->getCreatedAt()->format(\DateTimeInterface::ATOM)
                ?? $inquiry->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'canChat' => $inquiry->getStatus() === 'accepted',
        ];
    }
}
