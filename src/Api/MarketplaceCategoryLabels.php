<?php

namespace App\Api;

use App\Entity\MarketplaceCategory;
use Doctrine\ORM\EntityManagerInterface;

final class MarketplaceCategoryLabels
{
    public static function forLocale(EntityManagerInterface $entityManager, string $locale): array
    {
        $labels = [];
        foreach ($entityManager->getRepository(MarketplaceCategory::class)->findAll() as $category) {
            if ($category instanceof MarketplaceCategory) {
                $labels[$category->getValue()] = $category->label($locale);
            }
        }

        return $labels;
    }
}
