<?php

namespace App\Api;

use App\Entity\InquiryOffer;

final class InquiryOfferResource
{
    public static function fromEntity(InquiryOffer $offer): array
    {
        return [
            'id' => $offer->getId(), 'inquiryId' => $offer->getInquiry()->getId(),
            'amount' => $offer->getAmount(), 'currency' => $offer->getCurrency(),
            'dueDate' => $offer->getDueDate()->format('Y-m-d'), 'message' => $offer->getMessage(),
            'status' => $offer->getStatus(), 'createdAt' => $offer->getCreatedAt()->format(DATE_ATOM),
            'respondedAt' => $offer->getRespondedAt()?->format(DATE_ATOM),
            'cancellationReason' => $offer->getCancellationReason(),
        ];
    }
}
