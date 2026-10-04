<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\CreatorFaq;
use App\Entity\MarketplaceCategory;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CatalogModerationController
{
    #[Route('/api/moderation/catalog', name: 'api_moderation_catalog', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $moderator = ApiAccess::requireRole($security, 'ROLE_MODERATOR', $locale);
        if ($moderator instanceof JsonResponse) {
            return $moderator;
        }

        return new JsonResponse([
            'categories' => array_map(
                self::categoryResource(...),
                $entityManager->getRepository(MarketplaceCategory::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']),
            ),
            'faqs' => array_map(
                self::faqResource(...),
                $entityManager->getRepository(CreatorFaq::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']),
            ),
        ]);
    }

    #[Route('/api/moderation/catalog/categories', name: 'api_moderation_category_create', methods: ['POST'])]
    public function createCategory(
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
        $moderator = ApiAccess::requireRole($security, 'ROLE_MODERATOR', $locale);
        if ($moderator instanceof JsonResponse) {
            return $moderator;
        }

        $data = JsonPayload::fromRequest($request);
        $value = is_array($data) && is_string($data['value'] ?? null) ? trim($data['value']) : '';
        $labels = is_array($data) ? self::localizedMap($data['labels'] ?? null, 80, 2) : null;
        $position = is_array($data) ? self::position($data['position'] ?? null) : null;
        if ($data === null || mb_strlen($value) < 2 || mb_strlen($value) > 80 || $labels === null || $position === null) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }
        $duplicate = $entityManager->getRepository(MarketplaceCategory::class)
            ->createQueryBuilder('category')
            ->andWhere('LOWER(category.value) = :value')
            ->setParameter('value', mb_strtolower($value))
            ->getQuery()
            ->getOneOrNullResult();
        if ($duplicate instanceof MarketplaceCategory) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 409);
        }

        $category = new MarketplaceCategory($value, $labels, $position);
        $entityManager->persist($category);
        $entityManager->flush();

        return new JsonResponse(['data' => self::categoryResource($category)], 201);
    }

    #[Route('/api/moderation/catalog/categories/{id}', name: 'api_moderation_category_update', methods: ['PUT'])]
    public function updateCategory(
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
        $moderator = ApiAccess::requireRole($security, 'ROLE_MODERATOR', $locale);
        if ($moderator instanceof JsonResponse) {
            return $moderator;
        }

        $category = $entityManager->getRepository(MarketplaceCategory::class)->find($id);
        if (!$category instanceof MarketplaceCategory) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 404);
        }
        $data = JsonPayload::fromRequest($request);
        $labels = is_array($data) ? self::localizedMap($data['labels'] ?? null, 80, 2) : null;
        $position = is_array($data) ? self::position($data['position'] ?? null) : null;
        $active = is_array($data) && is_bool($data['active'] ?? null) ? $data['active'] : null;
        if ($data === null || $labels === null || $position === null || $active === null) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $category->update($labels, $position, $active);
        $entityManager->flush();

        return new JsonResponse(['data' => self::categoryResource($category)]);
    }

    #[Route('/api/moderation/catalog/faqs', name: 'api_moderation_faq_create', methods: ['POST'])]
    public function createFaq(
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
        $moderator = ApiAccess::requireRole($security, 'ROLE_MODERATOR', $locale);
        if ($moderator instanceof JsonResponse) {
            return $moderator;
        }

        $data = JsonPayload::fromRequest($request);
        $questions = is_array($data) ? self::localizedMap($data['questions'] ?? null, 180, 2) : null;
        $answers = is_array($data) ? self::localizedMap($data['answers'] ?? null, 2000, 10) : null;
        $position = is_array($data) ? self::position($data['position'] ?? null) : null;
        if ($data === null || $questions === null || $answers === null || $position === null) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $faq = new CreatorFaq($questions, $answers, $position);
        $entityManager->persist($faq);
        $entityManager->flush();

        return new JsonResponse(['data' => self::faqResource($faq)], 201);
    }

    #[Route('/api/moderation/catalog/faqs/{id}', name: 'api_moderation_faq_update', methods: ['PUT'])]
    public function updateFaq(
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
        $moderator = ApiAccess::requireRole($security, 'ROLE_MODERATOR', $locale);
        if ($moderator instanceof JsonResponse) {
            return $moderator;
        }

        $faq = $entityManager->getRepository(CreatorFaq::class)->find($id);
        if (!$faq instanceof CreatorFaq) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 404);
        }
        $data = JsonPayload::fromRequest($request);
        $questions = is_array($data) ? self::localizedMap($data['questions'] ?? null, 180, 2) : null;
        $answers = is_array($data) ? self::localizedMap($data['answers'] ?? null, 2000, 10) : null;
        $position = is_array($data) ? self::position($data['position'] ?? null) : null;
        $active = is_array($data) && is_bool($data['active'] ?? null) ? $data['active'] : null;
        if ($data === null || $questions === null || $answers === null || $position === null || $active === null) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $faq->update($questions, $answers, $position, $active);
        $entityManager->flush();

        return new JsonResponse(['data' => self::faqResource($faq)]);
    }

    private static function localizedMap(mixed $value, int $maxLength, int $minLength): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $localized = [];
        foreach (LocaleContext::SUPPORTED as $locale) {
            $text = $value[$locale] ?? null;
            if (!is_string($text)) {
                return null;
            }
            $text = trim($text);
            if (mb_strlen($text) < $minLength || mb_strlen($text) > $maxLength) {
                return null;
            }
            $localized[$locale] = $text;
        }

        return $localized;
    }

    private static function position(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 && $value <= 1000 ? $value : null;
    }

    private static function categoryResource(MarketplaceCategory $category): array
    {
        return [
            'id' => $category->getId(),
            'value' => $category->getValue(),
            'labels' => $category->getLabels(),
            'position' => $category->getPosition(),
            'active' => $category->isActive(),
        ];
    }

    private static function faqResource(CreatorFaq $faq): array
    {
        return [
            'id' => $faq->getId(),
            'questions' => $faq->getQuestions(),
            'answers' => $faq->getAnswers(),
            'position' => $faq->getPosition(),
            'active' => $faq->isActive(),
        ];
    }
}
