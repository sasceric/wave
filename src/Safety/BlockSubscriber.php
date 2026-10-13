<?php

namespace App\Safety;

use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Enforce direct-link writes and anonymize shared history without removing records. */
final class BlockSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly BlockPolicy $blocks, private readonly Connection $db, private readonly \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrf) {}
    public static function getSubscribedEvents(): array { return [KernelEvents::CONTROLLER => 'access', KernelEvents::RESPONSE => 'history']; }
    public function access(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $request = $event->getRequest();
        $this->blocks->reset();
        $path = $request->getPathInfo();
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null || (!$request->isMethodSafe() && \App\Api\ApiAccess::requireCsrf($request, $this->csrf, $locale) !== null)) { return; }
        $ids = $this->blocks->ids();
        if ($ids === [] || !str_starts_with($path, '/api/') || str_starts_with($path, '/api/admin/')) { return; }
        $other = [];
        if (preg_match('~^/api/(creators|companies|campaigns)/([^/]+)(?:/|$)~', $path, $match)) {
            $target = $this->blocks->target(['creators' => 'creator', 'companies' => 'company', 'campaigns' => 'campaign'][$match[1]], rawurldecode($match[2]));
            $other[] = $target['user_id'] ?? null;
        }
        if (preg_match('~^/api/media/(\d+)/(?:file|thumbnail)~', $path, $match)) {
            // Staff retain access to submitted private evidence while public profiles stay blocked.
            $evidence = $this->blocks->staff() && $this->db->fetchOne('SELECT 1 FROM marketplace_report_media WHERE media_id = ? LIMIT 1', [(int) $match[1]]);
            if (!$evidence) { $other[] = $this->db->fetchOne('SELECT owner_id FROM media WHERE id = ?', [(int) $match[1]]); }
        }
        if (!$request->isMethodSafe() && !str_ends_with($path, '/read')) {
            $relations = [
                'applications' => ['campaign_application', 't.campaign_id'], 'offers' => ['campaign_offer', 'a.campaign_id'],
                'invitations' => ['campaign_invitation', 't.campaign_id'], 'conversations' => ['campaign_conversation', 't.campaign_id'],
                'inquiries' => ['creator_inquiry', null],
            ];
            if (preg_match('~^/api/(?:me|company)/(applications|offers|invitations|conversations|inquiries)/(\d+)(?:/|$)~', $path, $match)) {
                [$table, $campaign] = $relations[$match[1]];
                $offer = $table === 'campaign_offer';
                $sql = $campaign === null ? "SELECT p.owner_id AS company_user, r.owner_id AS creator_user FROM $table t JOIN company p ON p.id = t.company_id JOIN creator r ON r.id = t.creator_id WHERE t.id = ?"
                    : "SELECT p.owner_id AS company_user, r.owner_id AS creator_user FROM $table t ".($offer ? 'JOIN campaign_application a ON a.id = t.application_id ' : '')."JOIN campaign c ON c.id = $campaign JOIN company p ON p.id = c.company_id JOIN creator r ON r.id = ".($offer ? 'a.creator_id' : 't.creator_id').' WHERE t.id = ?';
                $row = $this->db->fetchAssociative($sql, [(int) $match[2]]);
                if ($row && in_array($this->blocks->viewerId(), array_map(intval(...), array_values($row)), true)) { $other = [...$other, ...array_values($row)]; }
            }
            if (preg_match('~^/api/company/campaigns/[^/]+/(?:invitations|conversations)$~', $path)) {
                $data = json_decode($request->getContent(), true);
                if (is_int($data['creatorId'] ?? null)) { $other[] = $this->db->fetchOne('SELECT owner_id FROM creator WHERE id = ?', [$data['creatorId']]); }
            }
        }
        foreach ($other as $id) {
            if ($id !== null && $id !== false && in_array((int) $id, $ids, true)) {
                $locale = LocaleContext::fromRequest($request) ?? 'bs';
                $status = $request->isMethodSafe() ? 404 : 409;
                $event->setController(static fn (): JsonResponse => new JsonResponse(['error' => ApiMessages::get('blocked_history', $locale)], $status, ['Cache-Control' => 'private, no-store']));
                return;
            }
        }
    }
    public function history(ResponseEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        $response = $event->getResponse();
        if ($event->isMainRequest() && $response instanceof JsonResponse && str_starts_with($path, '/api/') && $this->blocks->viewerId() !== null) { $response->headers->set('Cache-Control', 'private, no-store'); }
        if (!$event->isMainRequest() || !$response instanceof JsonResponse || $response->getStatusCode() >= 400 || !(str_starts_with($path, '/api/me/') || (str_starts_with($path, '/api/company/campaigns/') && str_ends_with($path, '/applications'))) || str_starts_with($path, '/api/me/safety')) { return; }
        $ids = $this->blocks->ids();
        if ($ids === []) { return; }
        $profiles = ['creator' => [], 'company' => [], 'campaign' => []];
        foreach (['creator', 'company'] as $kind) {
            foreach ($this->db->fetchAllAssociative("SELECT id, slug FROM $kind WHERE owner_id IN (?)", [$ids], [ArrayParameterType::INTEGER]) as $row) { $profiles[$kind][] = $row; }
        }
        foreach ($this->db->fetchAllAssociative('SELECT c.id, c.slug FROM campaign c JOIN company p ON p.id = c.company_id WHERE p.owner_id IN (?)', [$ids], [ArrayParameterType::INTEGER]) as $row) { $profiles['campaign'][] = $row; }
        $locale = LocaleContext::fromRequest($event->getRequest()) ?? 'bs';
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $response->setData($this->redact($payload, $profiles, $ids, $locale));
        $response->headers->set('Cache-Control', 'private, no-store');
    }
    private function matches(array $value, array $profiles): bool
    {
        foreach ($profiles as $row) {
            if ((isset($value['slug']) && $value['slug'] === $row['slug']) || (isset($value['id']) && (int) $value['id'] === (int) $row['id'])) { return true; }
        }
        return false;
    }
    private function redact(array $data, array $profiles, array $ids, string $locale): array
    {
        $blocked = false;
        foreach ($data as $key => &$value) {
            if (!is_array($value)) { continue; }
            if (is_string($key) && isset($profiles[$key]) && $this->matches($value, $profiles[$key])) {
                $name = ApiMessages::get('blocked_account', $locale);
                $value = $key === 'campaign' ? ['id' => $value['id'] ?? null, 'slug' => null, 'title' => $name, 'status' => $value['status'] ?? 'closed', 'summary' => '', 'description' => '', 'categories' => [], 'channels' => [], 'deliverables' => [], 'budgetMin' => $value['budgetMin'] ?? 0, 'budgetMax' => $value['budgetMax'] ?? 0, 'currency' => $value['currency'] ?? 'BAM', 'closesAt' => $value['closesAt'] ?? null, 'city' => '', 'readOnly' => true, 'blocked' => true, 'company' => ['name' => $name, 'slug' => null, 'deleted' => true, 'logoUrl' => null]]
                    : ['id' => null, 'slug' => null, 'name' => $name, 'displayName' => $name, 'deleted' => true, 'blocked' => true, 'avatarUrl' => null, 'logoUrl' => null];
                $blocked = true;
            } else { $value = $this->redact($value, $profiles, $ids, $locale); if ($value['blocked'] ?? false) { $blocked = true; } }
        }
        if (isset($data['event']['campaignSlug']) && $this->matches(['slug' => $data['event']['campaignSlug']], $profiles['campaign'])) {
            $data['event']['campaignSlug'] = null;
            $data['event']['campaignTitle'] = ApiMessages::get('blocked_account', $locale);
            $blocked = true;
        }
        unset($value);
        if (isset($data['senderId']) && in_array((int) $data['senderId'], $ids, true)) { $data['senderId'] = null; $data['blocked'] = true; }
        if (isset($data['actorId']) && in_array((int) $data['actorId'], $ids, true)) {
            $data['actorId'] = null; $data['actorName'] = ApiMessages::get('blocked_account', $locale); $data['actorImageUrl'] = null; $data['reviewApplicationId'] = null; $blocked = true;
        }
        if ($blocked) {
            $data['blocked'] = true; $data['readOnly'] = true;
            // canChat also denotes a selectable historical thread. readOnly disables sending.
            if (isset($data['unreadCount'])) { $data['unreadCount'] = 0; }
            if (isset($data['packageTitle'])) { $data['packageTitle'] = null; $data['selectedPackages'] = []; }
        }
        return $data;
    }
}
