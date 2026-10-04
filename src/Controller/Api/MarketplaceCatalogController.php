<?php

namespace App\Controller\Api;

use App\Entity\CreatorFaq;
use App\Entity\MarketplaceCategory;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class MarketplaceCatalogController
{
    #[Route('/api/marketplace/categories', name: 'api_marketplace_categories', methods: ['GET'])]
    public function categories(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }

        $categories = $entityManager->getRepository(MarketplaceCategory::class)->findBy(
            ['active' => true],
            ['position' => 'ASC', 'id' => 'ASC'],
        );

        return new JsonResponse([
            'data' => array_map(static fn (MarketplaceCategory $category): array => [
                'value' => $category->getValue(),
                'label' => $category->label($locale),
            ], $categories),
        ]);
    }

    #[Route('/api/marketplace/creator-faqs', name: 'api_marketplace_creator_faqs', methods: ['GET'])]
    public function creatorFaqs(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }

        $faqs = $entityManager->getRepository(CreatorFaq::class)->findBy(
            ['active' => true],
            ['position' => 'ASC', 'id' => 'ASC'],
        );

        return new JsonResponse([
            'data' => array_map(static fn (CreatorFaq $faq): array => [
                'id' => $faq->getId(),
                ...$faq->localized($locale),
            ], $faqs),
        ]);
    }
}
