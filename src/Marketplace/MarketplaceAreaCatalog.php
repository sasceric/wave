<?php

namespace App\Marketplace;

use App\Entity\MarketplaceCategory;
use Doctrine\ORM\EntityManagerInterface;

final class MarketplaceAreaCatalog
{
    public static function options(EntityManagerInterface $entityManager, string $locale): array
    {
        $categories = $entityManager->getRepository(MarketplaceCategory::class)->findBy(
            ['active' => true],
            ['position' => 'ASC', 'id' => 'ASC'],
        );

        return array_map(static fn (MarketplaceCategory $category): array => [
            'value' => $category->getValue(),
            'label' => $category->label($locale),
        ], $categories);
    }
}
