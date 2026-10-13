<?php

namespace App\Controller\Api;

use App\Api\ApiAccess;
use App\Api\JsonPayload;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Safety\MarketplaceSafety;
use App\Safety\BlockPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class MarketplaceSafetyController
{
    public function __construct(private readonly MarketplaceSafety $safety, private readonly Connection $db, private readonly Security $security, private readonly CsrfTokenManagerInterface $csrf, private readonly BlockPolicy $blocks) {}
    private function run(Request $request, bool $admin, \Closure $action): JsonResponse
    {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) { return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400); }
        if (!$request->isMethodSafe() && ($error = ApiAccess::requireCsrf($request, $this->csrf, $locale))) { return $error; }
        $user = $admin ? ApiAccess::requireRole($this->security, 'ROLE_ADMIN', $locale, true) : ApiAccess::requireParticipant($this->security, $locale, true);
        if ($user instanceof JsonResponse) { return $user; }
        try { return new JsonResponse($action($user), headers: ['Cache-Control' => 'private, no-store']); }
        catch (HttpException $error) { return new JsonResponse(['error' => ApiMessages::get($error->getMessage(), $locale)], $error->getStatusCode(), ['Cache-Control' => 'private, no-store']); }
    }
    #[Route('/api/me/safety/reasons', methods: ['GET'])]
    public function reasons(Request $request): JsonResponse { return $this->run($request, false, fn (): array => ['data' => $this->safety->reasons()]); }
    #[Route('/api/me/safety/{kind}/{slug}/report', methods: ['POST'], requirements: ['kind' => 'creator|company|campaign'])]
    public function report(string $kind, string $slug, Request $request): JsonResponse { return $this->run($request, false, fn (User $user): array => ['data' => $this->safety->report($user, $kind, $slug, JsonPayload::fromRequest($request) ?? [])]); }
    #[Route('/api/me/safety/{kind}/{slug}/block', methods: ['POST'], requirements: ['kind' => 'creator|company|campaign'])]
    public function block(string $kind, string $slug, Request $request): JsonResponse { return $this->run($request, false, fn (User $user): array => ['data' => $this->safety->block($user, $kind, $slug)]); }
    #[Route('/api/me/safety/blocks/{id}', methods: ['DELETE'])]
    public function unblock(string $id, Request $request): JsonResponse { return $this->run($request, false, function (User $user) use ($id): array { $this->safety->unblock($user, $id); return ['data' => ['id' => $id]]; }); }
    #[Route('/api/me/safety/notices', methods: ['GET'])]
    public function notices(Request $request): JsonResponse { return $this->run($request, false, fn (User $user): array => ['userId' => $user->getId(), 'data' => $this->db->fetchAllAssociative('SELECT id, report_id AS "reportId", status, message, created_at AS "createdAt" FROM report_notice WHERE recipient_id = ? AND read_at IS NULL ORDER BY created_at, report_id, version, id LIMIT 20', [$user->getId()])]); }
    #[Route('/api/me/safety/notices/{id}/read', methods: ['POST'])]
    public function read(string $id, Request $request): JsonResponse { return $this->run($request, false, function (User $user) use ($id): array { $this->db->executeStatement('UPDATE report_notice SET read_at = ? WHERE id = ? AND recipient_id = ? AND read_at IS NULL', [gmdate('Y-m-d H:i:s'), $id, $user->getId()]); return ['data' => ['id' => $id]]; }); }
    #[Route('/api/admin/safety/reports/{id}', methods: ['PUT'])]
    public function resolve(string $id, Request $request): JsonResponse { return $this->run($request, true, fn (): array => ['data' => $this->safety->resolve($id, JsonPayload::fromRequest($request) ?? [])]); }
    #[Route('/api/me/safety/reviews', methods: ['GET'])]
    public function reviews(Request $request): JsonResponse
    {
        return $this->run($request, false, function (User $user) use ($request): array {
            $locale = LocaleContext::fromRequest($request) ?? 'bs';
            $visible = $this->blocks->visible('u.id');
            $name = "CASE WHEN NOT ($visible) THEN :blockedName WHEN u.deleted_at IS NOT NULL THEN :deletedName ELSE COALESCE(c.display_name, p.name, u.email) END";
            $campaign = "CASE WHEN NOT ($visible) THEN :blockedName ELSE campaign.title END";
            // A received review stays private until both parties have reviewed or the publication window expires.
            $published = "(given.id IS NOT NULL OR campaign.finished_at <= :cutoff)";
            $receivedRating = "CASE WHEN $published THEN received.rating END";
            $date = 'COALESCE(given.created_at, received.created_at)';
            $columns = ['createdAt' => $date, 'name' => $name, 'campaign' => $campaign, 'received' => $receivedRating, 'given' => 'given.rating'];
            $limit = $request->query->getInt('limit', 25);
            $page = $request->query->getInt('page', 1);
            $sort = $request->query->getString('sort', 'createdAt');
            $direction = strtoupper($request->query->getString('direction', 'desc'));
            $q = trim($request->query->getString('q'));
            if (!in_array($limit, [25, 50, 100], true) || $page < 1 || $page > 1000000 || !isset($columns[$sort]) || !in_array($direction, ['ASC', 'DESC'], true) || mb_strlen($q) > 200) {
                throw new HttpException(400, 'invalid_request');
            }
            $params = ['viewer' => $user->getId()];
            $from = ' FROM campaign_application a JOIN campaign ON campaign.id = a.campaign_id JOIN creator creator ON creator.id = a.creator_id JOIN company company ON company.id = campaign.company_id'
                .' JOIN wave_user u ON u.id = CASE WHEN creator.owner_id = :viewer THEN company.owner_id ELSE creator.owner_id END'
                .' LEFT JOIN creator c ON c.owner_id = u.id LEFT JOIN company p ON p.owner_id = u.id'
                .' LEFT JOIN campaign_review given ON given.application_id = a.id AND given.author_id = :viewer AND given.recipient_id = u.id'
                .' LEFT JOIN campaign_review received ON received.application_id = a.id AND received.recipient_id = :viewer AND received.author_id = u.id'
                .' WHERE (creator.owner_id = :viewer OR company.owner_id = :viewer) AND (given.id IS NOT NULL OR received.id IS NOT NULL)';
            $labels = ['blockedName' => ApiMessages::get('blocked_account', $locale), 'deletedName' => ApiMessages::get('deleted_account', $locale)];
            if ($q !== '') {
                $from .= " AND (LOWER($name) LIKE :q ESCAPE '!' OR LOWER($campaign) LIKE :q ESCAPE '!')";
                $params += $labels;
                $params['q'] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)).'%';
            }
            $total = (int) $this->db->fetchOne('SELECT COUNT(*)'.$from, $params);
            $page = min($page, max(1, (int) ceil($total / $limit)));
            $params += $labels + ['cutoff' => gmdate('Y-m-d H:i:s', time() - 14 * 86400)];
            $select = "a.id, $date AS \"createdAt\", $name AS name, $campaign AS campaign, CASE WHEN $visible AND u.deleted_at IS NULL THEN u.email END AS email, given.id AS given_id, given.rating AS given_rating, given.comment AS given_comment, received.id AS received_id, $receivedRating AS received_rating, CASE WHEN $published AND $visible THEN received.comment END AS received_comment, CASE WHEN $published THEN 'published' ELSE 'pending' END AS received_status, CASE WHEN received.id IS NOT NULL OR campaign.finished_at <= :cutoff THEN 'published' ELSE 'pending' END AS given_status";
            $rows = $this->db->fetchAllAssociative('SELECT '.$select.$from.' ORDER BY '.$columns[$sort].' '.$direction.', a.id '.$direction.' LIMIT '.$limit.' OFFSET '.(($page - 1) * $limit), $params);
            foreach ($rows as &$row) {
                $row['id'] = (int) $row['id'];
                foreach (['received', 'given'] as $side) {
                    $row[$side] = $row[$side.'_id'] === null ? null : ['rating' => $row[$side.'_rating'] === null ? null : (int) $row[$side.'_rating'], 'comment' => $row[$side.'_comment'], 'status' => $row[$side.'_status']];
                    unset($row[$side.'_id'], $row[$side.'_rating'], $row[$side.'_comment']);
                }
                unset($row['received_status'], $row['given_status']);
            }
            return ['data' => $rows, 'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit]];
        });
    }
    #[Route('/api/admin/safety/{section}', methods: ['GET'], requirements: ['section' => 'reports'])]
    #[Route('/api/me/safety/blocks', methods: ['GET'])]
    public function index(Request $request, string $section = 'blocks'): JsonResponse
    {
        $admin = str_starts_with($request->getPathInfo(), '/api/admin/');
        return $this->run($request, $admin, function (User $user) use ($request, $section): array {
            $columns = ['createdAt' => 't.created_at'];
            $params = [];
            $names = "COALESCE(c.display_name, p.name, u.email) AS name, u.email, COALESCE(c2.display_name, p2.name, u2.email) AS counterpart, u2.email AS counterpart_email";
            $userJoins = ' LEFT JOIN creator c ON c.owner_id = u.id LEFT JOIN company p ON p.owner_id = u.id LEFT JOIN creator c2 ON c2.owner_id = u2.id LEFT JOIN company p2 ON p2.owner_id = u2.id';
            if ($section === 'blocks') {
                $select = "t.id, t.created_at AS \"createdAt\", $names";
                $from = ' FROM user_block t JOIN wave_user u ON u.id = t.blocker_id JOIN wave_user u2 ON u2.id = t.blocked_id'.$userJoins.' WHERE t.cleared_at IS NULL';
                $from .= ' AND t.blocker_id = :viewer'; $params['viewer'] = $user->getId();
                $columns += ['name' => 'COALESCE(c.display_name, p.name, u.email)', 'counterpart' => 'COALESCE(c2.display_name, p2.name, u2.email)'];
            } elseif ($section === 'reports') {
                $select = "t.*, t.created_at AS \"createdAt\", $names";
                $from = ' FROM marketplace_report t JOIN wave_user u ON u.id = t.reporter_id JOIN wave_user u2 ON u2.id = t.target_user_id'.$userJoins.' WHERE 1 = 1';
                $columns += ['name' => 'COALESCE(c.display_name, p.name, u.email)', 'target_name' => 't.target_name', 'status' => 't.status', 'category' => 't.category'];
            } else { throw new HttpException(404, 'invalid_request'); }
            $limit = $request->query->getInt('limit', 25); $page = $request->query->getInt('page', 1); $sort = $request->query->getString('sort', 'createdAt'); $direction = strtoupper($request->query->getString('direction', 'desc')); $q = trim($request->query->getString('q'));
            if (!in_array($limit, [25, 50, 100], true) || $page < 1 || $page > 1000000 || !isset($columns[$sort]) || !in_array($direction, ['ASC', 'DESC'], true) || mb_strlen($q) > 200) { throw new HttpException(400, 'invalid_request'); }
            $status = $request->query->getString('status', 'all');
            if ($section === 'reports' && $status !== 'all') { if (!in_array($status, ['received', 'processing', 'resolved', 'dismissed'], true)) { throw new HttpException(400, 'invalid_request'); } $from .= ' AND t.status = :status'; $params['status'] = $status; }
            if ($q !== '') {
                $search = $section === 'blocks'
                    ? "LOWER(COALESCE(c2.display_name, p2.name, u2.email)) LIKE :q ESCAPE '!' OR LOWER(u2.email) LIKE :q ESCAPE '!'"
                    : "LOWER(COALESCE(c.display_name, p.name, u.email)) LIKE :q ESCAPE '!' OR LOWER(COALESCE(c2.display_name, p2.name, u2.email)) LIKE :q ESCAPE '!' OR LOWER(u.email) LIKE :q ESCAPE '!' OR LOWER(u2.email) LIKE :q ESCAPE '!'";
                $from .= ' AND ('.$search.')';
                $params['q'] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)).'%';
            }
            $total = (int) $this->db->fetchOne('SELECT COUNT(*)'.$from, $params);
            $page = min($page, max(1, (int) ceil($total / $limit)));
            $rows = $this->db->fetchAllAssociative('SELECT '.$select.$from.' ORDER BY '.$columns[$sort].' '.$direction.', t.id '.$direction.' LIMIT '.$limit.' OFFSET '.(($page - 1) * $limit), $params);
            $attachments = [];
            if ($section === 'reports' && $rows !== []) {
                foreach ($this->db->fetchAllAssociative('SELECT rm.report_id, m.id, m.original_name AS name FROM media m JOIN marketplace_report_media rm ON rm.media_id = m.id WHERE rm.report_id IN (?) ORDER BY m.id', [array_column($rows, 'id')], [\Doctrine\DBAL\ArrayParameterType::STRING]) as $file) { $attachments[$file['report_id']][] = ['id' => (int) $file['id'], 'name' => $file['name']]; }
            }
            foreach ($rows as &$row) { if ($section === 'reports') { $row['attachments'] = $attachments[$row['id']] ?? []; } if (isset($row['version'])) { $row['version'] = (int) $row['version']; } if (isset($row['rating'])) { $row['rating'] = (int) $row['rating']; } }
            return ['data' => $rows, 'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit]];
        });
    }
}
