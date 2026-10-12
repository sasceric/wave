<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\UrlRedirect;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Service\UrlRedirects;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use SortDirection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/api/admin/redirects')]
final class AdminRedirectController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly Security $security, private readonly CsrfTokenManagerInterface $csrf, private readonly UrlRedirects $redirects)
    {
    }

    #[Route('', methods: ['GET', 'POST'])]
    #[Route('/bulk-delete', methods: ['DELETE'])]
    #[Route('/{id}', requirements: ['id' => '\d+'], methods: ['PUT', 'DELETE'])]
    public function handle(Request $request, ?int $id = null): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return $this->response(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        $access = ApiAccess::requireRole($this->security, 'ROLE_ADMIN', $locale);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        if ($request->isMethod('GET')) {
            $page = $request->query->getInt('page', 1);
            $size = $request->query->getInt('limit', $request->query->getInt('pageSize', 25));
            $sort = $request->query->getString('sort', 'sourcePath');
            $direction = $request->query->getString('direction', 'asc');
            $sortColumns = ['sourcePath' => 'sourcePath', 'targetPath' => 'targetPath', 'status' => 'enabled', 'origin' => 'origin', 'updatedAt' => 'updatedAt'];
            if ($page < 1 || !in_array($size, [25, 50, 100], true) || !isset($sortColumns[$sort]) || !in_array($direction, ['asc', 'desc'], true)) {
                return $this->invalid($locale);
            }
            $search = trim($request->query->getString('search'));
            $qb = $this->em->createQueryBuilder()->from(UrlRedirect::class, 'redirect');
            if ($search !== '') {
                $qb->andWhere('LOWER(redirect.sourcePath) LIKE :search OR LOWER(redirect.targetPath) LIKE :search')
                    ->setParameter('search', '%'.mb_strtolower($search).'%');
            }
            $total = (int) (clone $qb)->select('COUNT(redirect.id)')->getQuery()->getSingleScalarResult();
            $page = min($page, max(1, (int) ceil($total / $size)));
            $rows = $qb->select('redirect')->orderBy('redirect.'.$sortColumns[$sort], $direction === 'asc' ? SortDirection::Ascending : SortDirection::Descending)
                ->addOrderBy('redirect.id', SortDirection::Ascending)->setFirstResult(($page - 1) * $size)->setMaxResults($size)->getQuery()->getResult();

            return $this->response(['data' => array_map(self::serialize(...), $rows), 'meta' => ['page' => $page, 'pageSize' => $size, 'total' => $total]]);
        }
        if ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale)) {
            return $error;
        }
        if ($request->isMethod('DELETE') && $id === null) {
            $data = JsonPayload::fromRequest($request);
            $ids = $data['ids'] ?? null;
            if (!is_array($ids) || !array_is_list($ids) || count($ids) < 1 || count($ids) > 1000
                || array_filter($ids, static fn ($value): bool => !is_int($value) || $value < 1)) {
                return $this->invalid($locale);
            }
            $ids = array_values(array_unique($ids));
            $rows = $this->em->getRepository(UrlRedirect::class)->findBy(['id' => $ids]);
            if (count($rows) !== count($ids)) {
                return $this->response(['error' => ApiMessages::get('not_found', $locale)], 404);
            }
            foreach ($rows as $row) {
                $this->em->remove($row);
            }
            $this->em->flush();

            return $this->response(['data' => ['deleted' => count($rows)]]);
        }
        $row = $id === null ? null : $this->em->find(UrlRedirect::class, $id);
        if ($id !== null && !$row instanceof UrlRedirect) {
            return $this->response(['error' => ApiMessages::get('not_found', $locale)], 404);
        }
        if ($request->isMethod('DELETE')) {
            $this->em->remove($row);
            $this->em->flush();

            return $this->response(['data' => ['deleted' => true]]);
        }
        $data = JsonPayload::fromRequest($request);
        if (!is_string($data['sourcePath'] ?? null) || !is_string($data['targetPath'] ?? null)
            || !is_bool($data['enabled'] ?? null) || !is_bool($data['preserveQuery'] ?? null)) {
            return $this->invalid($locale);
        }
        try {
            $source = $this->redirects->path(trim($data['sourcePath']));
            $target = $this->redirects->target(trim($data['targetPath']));
            $this->redirects->validate($source, $target, $data['enabled'], $id);
            $row ??= new UrlRedirect($source, $target);
            $row->update($source, $target, $data['enabled'], $data['preserveQuery']);
            $this->em->persist($row);
            $this->em->flush();
        } catch (\InvalidArgumentException|UniqueConstraintViolationException) {
            return $this->invalid($locale);
        }

        return $this->response(['data' => self::serialize($row)], $id === null ? 201 : 200);
    }

    private static function serialize(UrlRedirect $row): array
    {
        return ['id' => $row->getId(), 'sourcePath' => $row->getSourcePath(), 'targetPath' => $row->getTargetPath(),
            'statusCode' => 301, 'enabled' => $row->isEnabled(), 'preserveQuery' => $row->preservesQuery(), 'origin' => $row->getOrigin(),
            'createdAt' => $row->getCreatedAt()->format(DATE_ATOM), 'updatedAt' => $row->getUpdatedAt()->format(DATE_ATOM)];
    }

    private function invalid(string $locale): JsonResponse
    {
        return $this->response(['error' => ApiMessages::get('invalid_request', $locale)], 400);
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store']);
    }
}
