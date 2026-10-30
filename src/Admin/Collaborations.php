<?php

namespace App\Admin;

use App\Localization\ApiMessages;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/** Read-only operational reporting. One inquiry/application per row, even with multiple packages or offers. */
final class Collaborations
{
    public const STATUSES = ['pending', 'discussing', 'offered', 'booked', 'completed', 'rejected', 'cancelled'];

    public function __construct(private readonly Connection $db) {}

    private function statusSql(string $kind): string
    {
        $source = $kind === 'packages' ? 'q' : 'a';
        $completed = $kind === 'packages' ? 'o.work_completed_at IS NOT NULL' : "(o.work_completed_at IS NOT NULL OR campaign.status = 'finished')";
        $cancelled = $kind === 'packages' ? "o.status = 'cancelled'" : "(o.status = 'cancelled' OR campaign.status = 'cancelled')";
        return "CASE WHEN o.status = 'accepted' AND $completed THEN 'completed'
            WHEN $cancelled THEN 'cancelled' WHEN o.status = 'accepted' THEN 'booked'
            WHEN $source.status = 'rejected' THEN 'rejected' WHEN o.status = 'pending' THEN 'offered'
            WHEN o.status = 'rejected' THEN 'rejected'
            WHEN $source.status IN ('accepted', 'shortlisted', 'offered') THEN 'discussing' ELSE 'pending' END";
    }

    private function invitedSql(): string
    {
        return "EXISTS (SELECT 1 FROM campaign_invitation invitation WHERE invitation.campaign_id = a.campaign_id AND invitation.creator_id = a.creator_id AND invitation.status IN ('accepted', 'cancelled'))";
    }

    public function page(Request $request, string $locale): array
    {
        $kind = $request->query->getString('kind', 'packages');
        $status = $request->query->getString('status', 'all');
        $origin = $request->query->getString('origin', 'all');
        $sort = $request->query->getString('sort', 'createdAt');
        $direction = strtoupper($request->query->getString('direction', 'desc'));
        $limit = $request->query->getInt('limit', 25);
        $page = $request->query->getInt('page', 1);
        $search = trim($request->query->getString('q'));
        $providerType = $request->query->getString('providerType', '');
        $sorts = ['createdAt' => 'created_at', 'provider' => 'provider_name', 'client' => 'client_name', 'subject' => 'subject', 'status' => 'status'];
        if (!in_array($origin, ['all', 'application', 'invitation'], true) || ($kind === 'packages' && $origin !== 'all')
            || !in_array($kind, ['packages', 'campaigns'], true) || !in_array($status, ['all', 'requests', 'engaged', ...self::STATUSES], true)
            || !isset($sorts[$sort]) || !in_array($direction, ['ASC', 'DESC'], true) || !in_array($limit, [25, 50, 100], true)
            || $page < 1 || $page > 1000000 || mb_strlen($search) > 200 || !in_array($providerType, ['', 'creator', 'agency'], true)) {
            throw new \InvalidArgumentException('Invalid collaboration query.');
        }
        $parameters = ['deleted' => ApiMessages::get('deleted_account', $locale)];
        $conditions = [];
        if ($origin !== 'all') { $conditions[] = 'origin = :origin'; $parameters['origin'] = $origin; }
        if ($search !== '') {
            $conditions[] = "(LOWER(provider_name) LIKE :q ESCAPE '!' OR LOWER(client_name) LIKE :q ESCAPE '!' OR LOWER(subject) LIKE :q ESCAPE '!')";
            $parameters['q'] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
        }
        if ($providerType !== '') { $conditions[] = 'provider_type = :providerType'; $parameters['providerType'] = $providerType; }
        foreach (['providerId' => 'provider_id', 'clientId' => 'client_id', 'campaignId' => 'campaign_id'] as $key => $column) {
            $value = $request->query->getString($key);
            if ($value === '') continue;
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', $value) || (int) $value > 2147483647) throw new \InvalidArgumentException('Invalid participant.');
            $conditions[] = "$column = :$key";
            $parameters[$key] = (int) $value;
        }
        $dates = [];
        foreach (['from', 'to'] as $key) {
            $value = $request->query->getString($key);
            if ($value === '') continue;
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
            if ($date === false || $date->format('Y-m-d') !== $value || $value < '1900-01-01') throw new \InvalidArgumentException('Invalid date.');
            $dates[$key] = $date;
            $conditions[] = 'created_at '.($key === 'from' ? '>=' : '<')." :$key";
            $parameters[$key] = ($key === 'to' ? $date->modify('+1 day') : $date)->format('Y-m-d H:i:s');
        }
        if (isset($dates['from'], $dates['to']) && $dates['from'] > $dates['to']) throw new \InvalidArgumentException('Invalid date range.');
        $statusSql = $this->statusSql($kind);
        $campaignColumns = $kind === 'packages' ? "NULL::integer AS campaign_id, NULL::text AS campaign_status, COALESCE((SELECT string_agg(item->>'title', ', ') FROM jsonb_array_elements(q.selected_packages::jsonb) item), q.package_title, '') AS subject, q.selected_packages, q.package_title" : "campaign.id AS campaign_id, campaign.status AS campaign_status, campaign.title AS subject, '[]'::json AS selected_packages, NULL::text AS package_title";
        $source = $kind === 'packages' ? 'q' : 'a';
        $from = $kind === 'packages'
            ? 'FROM creator_inquiry q JOIN creator c ON c.id = q.creator_id JOIN company client ON client.id = q.company_id LEFT JOIN inquiry_offer o ON o.id = q.offer_id'
            : 'FROM campaign_application a JOIN creator c ON c.id = a.creator_id JOIN campaign ON campaign.id = a.campaign_id JOIN company client ON client.id = campaign.company_id LEFT JOIN campaign_offer o ON o.id = a.offer_id';
        $currency = $kind === 'packages' ? 'o.currency' : 'campaign.currency';
        $completedAt = $kind === 'packages' ? 'o.work_completed_at' : "CASE WHEN o.status = 'accepted' THEN COALESCE(o.work_completed_at, CASE WHEN campaign.status = 'finished' THEN campaign.finished_at END) END";
        $originSql = $kind === 'packages' ? "'inquiry'" : "CASE WHEN ".$this->invitedSql()." THEN 'invitation' ELSE 'application' END";
        $sql = "SELECT $source.id, $originSql AS origin, $source.created_at, $campaignColumns, $statusSql AS status,
            CASE WHEN c.agency_profile THEN agency.id ELSE c.id END AS provider_id,
            CASE WHEN c.agency_profile THEN 'agency' ELSE 'creator' END AS provider_type,
            CASE WHEN provider_user.deleted_at IS NOT NULL THEN :deleted ELSE CASE WHEN c.agency_profile THEN COALESCE(agency.name, c.display_name) ELSE c.display_name END END AS provider_name,
            client.id AS client_id, CASE WHEN client_user.deleted_at IS NOT NULL THEN :deleted ELSE client.name END AS client_name,
            CASE WHEN o.status = 'accepted' THEN o.amount END AS amount, $currency AS currency,
            CASE WHEN o.status = 'accepted' THEN o.responded_at END AS accepted_at, $completedAt AS completed_at,
            o.work_status, o.work_submitted_at
            $from LEFT JOIN wave_user provider_user ON provider_user.id = c.owner_id
            LEFT JOIN company agency ON agency.owner_id = c.owner_id AND agency.archived_profile = FALSE AND c.agency_profile = TRUE
            LEFT JOIN wave_user client_user ON client_user.id = client.owner_id";
        $filtered = ' FROM ('.$sql.') activity WHERE '.($conditions ? implode(' AND ', $conditions) : 'TRUE');
        // Summary ignores the selected status so its cards remain useful filter shortcuts.
        $summaryRow = $this->db->fetchAssociative("SELECT COUNT(*) FILTER (WHERE status IN ('pending','discussing','offered')) AS requests, COUNT(*) FILTER (WHERE status = 'booked') AS engaged, COUNT(*) FILTER (WHERE status = 'completed') AS completed".$filtered, $parameters);
        $summary = array_map(intval(...), $summaryRow ?: []);
        if ($status !== 'all') {
            if ($status === 'requests') {
                $filtered .= " AND status IN ('pending', 'discussing', 'offered')";
            } else {
                $filtered .= ' AND status = :status';
                $parameters['status'] = $status === 'engaged' ? 'booked' : $status;
            }
        }
        $total = (int) $this->db->fetchOne('SELECT COUNT(*)'.$filtered, $parameters);
        $page = min($page, max(1, (int) ceil($total / $limit)));
        $rows = $this->db->fetchAllAssociative('SELECT *'.$filtered.' ORDER BY '.$sorts[$sort].' '.$direction.', id '.$direction.' LIMIT '.$limit.' OFFSET '.(($page - 1) * $limit), $parameters);
        return ['data' => array_map(fn (array $row): array => $this->resource($row), $rows), 'summary' => $summary,
            'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total]];
    }

    private function resource(array $row): array
    {
        $selected = is_string($row['selected_packages']) ? json_decode($row['selected_packages'], true) : $row['selected_packages'];
        $packages = array_values(array_filter(array_map(static fn (array $package): string => (string) ($package['title'] ?? ''), is_array($selected) ? array_filter($selected, is_array(...)) : [])));
        if ($packages === [] && $row['package_title']) $packages[] = $row['package_title'];
        $date = static fn (?string $value): ?string => $value === null ? null : (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format(DATE_ATOM);
        return ['id' => (int) $row['id'], 'origin' => $row['origin'], 'createdAt' => $date($row['created_at']), 'status' => $row['status'],
            'provider' => ['id' => $row['provider_id'] === null ? null : (int) $row['provider_id'], 'type' => $row['provider_type'], 'name' => $row['provider_name']],
            'client' => ['id' => (int) $row['client_id'], 'name' => $row['client_name']],
            'subject' => $packages ? implode(', ', $packages) : $row['subject'], 'packages' => $packages,
            'campaignId' => $row['campaign_id'] === null ? null : (int) $row['campaign_id'], 'campaignStatus' => $row['campaign_status'],
            'amount' => $row['amount'] === null ? null : (int) $row['amount'], 'currency' => $row['currency'],
            'acceptedAt' => $date($row['accepted_at']), 'completedAt' => $date($row['completed_at']),
            'workStatus' => $row['work_status'], 'submittedAt' => $date($row['work_submitted_at'])];
    }

    /** Batched activity for only the visible catalog rows; no per-row API/SQL queries. */
    public function counts(string $kind, array $ids): array
    {
        if ($ids === [] || !in_array($kind, ['creators', 'companies', 'campaigns'], true)) return [];
        $result = array_fill_keys($ids, ['packages' => ['requests' => 0, 'engaged' => 0, 'completed' => 0], 'campaigns' => ['campaigns' => 0, 'applications' => 0, 'engaged' => 0, 'completed' => 0], 'providerCampaigns' => ['applications' => 0, 'engaged' => 0, 'completed' => 0]]);
        $parameters = ['ids' => $ids]; $types = ['ids' => ArrayParameterType::INTEGER];
        $packageStatus = $this->statusSql('packages');
        $campaignStatus = $this->statusSql('campaigns');
        if ($kind !== 'campaigns') {
            $identity = $kind === 'creators' ? 'q.creator_id' : 'agency.id';
            $join = $kind === 'creators' ? '' : 'JOIN creator studio ON studio.id = q.creator_id AND studio.agency_profile = TRUE JOIN company agency ON agency.owner_id = studio.owner_id AND agency.company_type = \'agency\' AND agency.archived_profile = FALSE';
            $rows = $this->db->fetchAllAssociative("SELECT $identity AS id, COUNT(*) FILTER (WHERE ($packageStatus) IN ('pending','discussing','offered')) AS requests, COUNT(*) FILTER (WHERE ($packageStatus) = 'booked') AS engaged, COUNT(*) FILTER (WHERE ($packageStatus) = 'completed') AS completed FROM creator_inquiry q $join LEFT JOIN inquiry_offer o ON o.id = q.offer_id WHERE $identity IN (:ids) GROUP BY $identity", $parameters, $types);
            foreach ($rows as $row) $result[(int) $row['id']]['packages'] = array_map(intval(...), array_diff_key($row, ['id' => true]));
        }
        $identity = match ($kind) { 'creators' => 'a.creator_id', 'companies' => 'campaign.company_id', default => 'campaign.id' };
        $joins = $kind === 'creators' ? 'FROM campaign_application a JOIN campaign ON campaign.id = a.campaign_id' : 'FROM campaign LEFT JOIN campaign_application a ON a.campaign_id = campaign.id';
        $invited = $this->invitedSql();
        $rows = $this->db->fetchAllAssociative("SELECT $identity AS id, COUNT(DISTINCT campaign.id) AS campaigns, COUNT(a.id) FILTER (WHERE NOT $invited) AS applications,
            COUNT(*) FILTER (WHERE ($campaignStatus) = 'booked') AS engaged,
            COUNT(*) FILTER (WHERE ($campaignStatus) = 'completed') AS completed
            $joins LEFT JOIN campaign_offer o ON o.id = a.offer_id WHERE $identity IN (:ids) GROUP BY $identity", $parameters, $types);
        foreach ($rows as $row) $result[(int) $row['id']]['campaigns'] = array_map(intval(...), array_diff_key($row, ['id' => true]));
        if ($kind === 'companies') {
            $rows = $this->db->fetchAllAssociative("SELECT agency.id, COUNT(a.id) FILTER (WHERE NOT $invited) AS applications,
                COUNT(*) FILTER (WHERE ($campaignStatus) = 'booked') AS engaged,
                COUNT(*) FILTER (WHERE ($campaignStatus) = 'completed') AS completed
                FROM campaign_application a JOIN creator studio ON studio.id = a.creator_id AND studio.agency_profile = TRUE
                JOIN company agency ON agency.owner_id = studio.owner_id AND agency.company_type = 'agency' AND agency.archived_profile = FALSE
                JOIN campaign ON campaign.id = a.campaign_id LEFT JOIN campaign_offer o ON o.id = a.offer_id
                WHERE agency.id IN (:ids) GROUP BY agency.id", $parameters, $types);
            foreach ($rows as $row) $result[(int) $row['id']]['providerCampaigns'] = array_map(intval(...), array_diff_key($row, ['id' => true]));
        }
        return $result;
    }
}
