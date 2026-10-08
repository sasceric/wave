<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\QrLink;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Service\SiteOrigin;
use App\Service\QrScanStatistics;
use Doctrine\ORM\EntityManagerInterface;
use SortDirection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/api/admin/qr-links')]
final class AdminQrController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly SiteOrigin $origin,
        private readonly QrScanStatistics $statistics,
    ) {
    }

    #[Route('', methods: ['GET', 'POST'])]
    #[Route('/{id}', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function links(Request $request, ?int $id = null): JsonResponse
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
            $size = $request->query->getInt('pageSize', 25);
            if ($page < 1 || $page > 1000000 || !in_array($size, [25, 50, 100], true)) {
                return $this->invalid($locale);
            }
            $qb = $this->em->createQueryBuilder()->from(QrLink::class, 'q');
            $total = (int) (clone $qb)->select('COUNT(q.id)')->getQuery()->getSingleScalarResult();
            $page = min($page, max(1, (int) ceil($total / $size)));
            $rows = $qb->select('q')->orderBy('q.id', SortDirection::Descending)->setFirstResult(($page - 1) * $size)->setMaxResults($size)->getQuery()->getResult();

            $summaries = $this->statistics->summaries(array_map(static fn (QrLink $link): ?int => $link->getId(), $rows));

            return $this->response(['data' => array_map(fn (QrLink $link): array => $this->serialize($link) + ($summaries[$link->getId()] ?? ['scans' => 0, 'lastScanAt' => null]), $rows), 'meta' => ['page' => $page, 'pageSize' => $size, 'total' => $total]]);
        }
        if ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale)) {
            return $error;
        }
        $data = JsonPayload::fromRequest($request);
        $label = is_string($data['label'] ?? null) ? trim($data['label']) : '';
        $destination = is_string($data['destination'] ?? null) ? trim($data['destination']) : '';
        $url = parse_url($destination);
        if ($label === '' || mb_strlen($label) > 120 || strlen($destination) > 2048
            || filter_var($destination, FILTER_VALIDATE_URL) === false || !is_array($url)
            || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || isset($url['user']) || isset($url['pass'])
            || preg_match('/[\x00-\x20\x7f]/', $destination)) {
            return $this->invalid($locale);
        }
        // Avoid redirect loops to this module's own permanent links.
        if (($url['host'] ?? '') === parse_url($this->origin->base(), PHP_URL_HOST) && str_starts_with($url['path'] ?? '', '/q/')) {
            return $this->invalid($locale);
        }
        $link = $id === null ? new QrLink($label, $destination) : $this->em->find(QrLink::class, $id);
        if (!$link instanceof QrLink) {
            return $this->response(['error' => ApiMessages::get('not_found', $locale)], 404);
        }
        $link->update($label, $destination);
        $this->em->persist($link);
        $this->em->flush();

        return $this->response(['data' => $this->serialize($link)], $id === null ? 201 : 200);
    }

    #[Route('/{id}/statistics', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function statistics(Request $request, int $id): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return $this->invalid('bs');
        }
        $access = ApiAccess::requireRole($this->security, 'ROLE_ADMIN', $locale);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        $link = $this->em->find(QrLink::class, $id);
        if (!$link instanceof QrLink) {
            return $this->response(['error' => ApiMessages::get('not_found', $locale)], 404);
        }

        return $this->response(['data' => $this->statistics->details($link)]);
    }

    private function serialize(QrLink $link): array
    {
        return ['id' => $link->getId(), 'label' => $link->getLabel(), 'destination' => $link->getDestination(),
            'url' => $this->origin->url('/q/'.$link->getToken()), 'createdAt' => $link->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $link->getUpdatedAt()->format(DATE_ATOM)];
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
