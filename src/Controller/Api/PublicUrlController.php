<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Service\PublicUrlRedirectWriter;
use App\Service\PublicUrlSlugs;
use App\Service\SiteOrigin;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class PublicUrlController
{
    #[Route('/api/me/public-url', methods: ['GET', 'PUT'])]
    #[Route('/api/me/campaigns/{id}/public-url', requirements: ['id' => '\d+'], methods: ['GET', 'PUT'])]
    public function handle(Request $request, Security $security, EntityManagerInterface $em, CsrfTokenManagerInterface $csrf, PublicUrlSlugs $slugs, PublicUrlRedirectWriter $redirects, SiteOrigin $origin, ?int $id = null): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        $user = $security->getUser();
        if (!$user instanceof User || $user->isDeleted()) return new JsonResponse(['error' => ApiMessages::get('authentication_required', $locale)], 401);
        $entity = $id === null ? ($user->getCreator() ?? $user->getCompany()) : $em->find(Campaign::class, $id);
        if (!$entity instanceof Creator && !$entity instanceof Company && !$entity instanceof Campaign) return new JsonResponse(['error' => ApiMessages::get('profile_unavailable', $locale)], 404);
        $owner = $entity instanceof Campaign ? $entity->getCompany()->getOwner() : $entity->getOwner();
        if ($owner?->getId() !== $user->getId()) return new JsonResponse(['error' => ApiMessages::get('forbidden', $locale)], 403);
        $kind = $entity instanceof Creator ? 'creator' : ($entity instanceof Company ? 'company' : 'campaign');
        if ($request->isMethod('PUT')) {
            if ($error = ApiAccess::requireCsrf($request, $csrf, $locale)) return $error;
            $data = JsonPayload::fromRequest($request);
            $slug = $data['slug'] ?? null;
            if (!is_string($slug) || strlen($slug) < 2 || strlen($slug) > 80 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) return new JsonResponse(['error' => ApiMessages::get('public_url_invalid', $locale)], 400);
            $db = $em->getConnection();
            $db->beginTransaction();
            try {
                // Match the directory subscriber's lock order before locking the profile row.
                if ($db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                    $keys = [$kind.':'.$entity->getId()];
                    if ($entity instanceof Campaign) $keys[] = 'company:'.$entity->getCompany()->getId();
                    sort($keys);
                    foreach ($keys as $key) $db->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['wave.index.'.$key]);
                }
                $em->lock($entity, LockMode::PESSIMISTIC_WRITE);
                $em->refresh($entity);
                $available = $entity->getPublicUrlChangedAt()?->modify('+14 days');
                if ($slug !== $entity->getSlug() && $available !== null && $available > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
                    $db->rollBack();
                    return new JsonResponse(['error' => ApiMessages::get('public_url_cooldown', $locale), 'availableAt' => $available->format(DATE_ATOM)], 409);
                }
                if ($slug !== $entity->getSlug()) {
                    $old = $entity->getSlug();
                    $slugs->remember($kind, $old);
                    $new = $slugs->reserve($kind, $slug);
                    $entity->changePublicSlug($new);
                    $redirects->changed($kind, $old, $new);
                    $em->flush();
                }
                $db->commit();
            } catch (\Throwable $exception) {
                if ($db->isTransactionActive()) $db->rollBack();
                throw $exception;
            }
        }
        return new JsonResponse(['userId' => $user->getId(), 'data' => ['slug' => $entity->getSlug(), 'path' => $slugs->path($kind, $entity->getSlug()), 'url' => $origin->url($slugs->path($kind, $entity->getSlug())), 'availableAt' => $entity->getPublicUrlChangedAt()?->modify('+14 days')->format(DATE_ATOM)]], headers: ['Cache-Control' => 'private, no-store']);
    }
}
