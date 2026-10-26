<?php

namespace App\Api;

use App\Account\ProfileStrengthSql;
use App\Reviews\CollaborationFeedback;
use Symfony\Component\Clock\Clock;

/** One SQL ranking contract for discovery and homepage picks, with a cursor-pinned UTC day. */
final class RecommendedProfiles
{
    public const POSITION_TYPES = ['featured' => 'bool', 'quality' => 'int', 'jobs' => 'int', 'strength' => 'int', 'rotation' => 'string', 'id' => 'int', 'asOf' => 'date'];
    public const ORDER = 'featured DESC, quality DESC, jobs DESC, strength DESC, rotation ASC, id ASC';

    public function __construct(private readonly CollaborationFeedback $feedback) {}

    public function parameters(?array $position = null): array
    {
        $asOf = $position['asOf'] ?? Clock::get()->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $date = new \DateTimeImmutable($asOf, new \DateTimeZone('UTC'));
        return ['rankingNow' => $asOf, 'rankingCutoff' => $date->modify('-14 days')->format('Y-m-d H:i:s'), 'rankingDay' => $date->format('Y-m-d')];
    }

    public function columns(string $kind): string
    {
        $strength = $kind === 'creator' ? ProfileStrengthSql::creator() : ProfileStrengthSql::company();
        // Prior: three reviews at 4/5. One perfect review cannot outweigh a long strong track record.
        return "(c.featured AND (c.featured_until IS NULL OR c.featured_until > :rankingNow)) AS featured,
            CASE WHEN feedback.count > 0 THEN FLOOR(1000000.0 * (feedback.total + 12) / (feedback.count + 3))::integer ELSE 0 END AS quality,
            completed.count AS jobs, $strength AS strength, md5(:rankingDay || ':$kind:' || c.id::text) COLLATE \"C\" AS rotation";
    }

    public function joins(string $kind): string
    {
        $condition = str_replace(':cutoff', ':rankingCutoff', $this->feedback->publicCondition());
        $provider = 'EXISTS (SELECT 1 FROM creator provider WHERE provider.id = a.creator_id AND provider.owner_id = u.id)';
        $client = 'EXISTS (SELECT 1 FROM company client WHERE client.id = campaign.company_id AND client.owner_id = u.id)';
        $reviewRole = $kind === 'creator' ? $provider : "((c.company_type = 'agency' AND $provider) OR (c.company_type <> 'agency' AND $client))";
        $completedRole = $kind === 'creator'
            ? "$provider AND (o.work_completed_at IS NOT NULL OR campaign.status = 'finished')"
            : "((c.company_type = 'agency' AND $provider AND (o.work_completed_at IS NOT NULL OR campaign.status = 'finished')) OR (c.company_type <> 'agency' AND $client AND campaign.status = 'finished'))";
        $condition = preg_replace('/\bc\./', 'campaign.', $condition);
        $studio = $kind === 'company' ? ' LEFT JOIN creator studio ON studio.owner_id = u.id AND studio.agency_profile = TRUE ' : '';
        return $studio." LEFT JOIN LATERAL (
            SELECT COUNT(*) AS count, COALESCE(SUM(r.rating), 0) AS total FROM campaign_review r
            JOIN campaign_application a ON a.id = r.application_id JOIN campaign campaign ON campaign.id = a.campaign_id
            JOIN campaign_offer o ON o.id = a.offer_id
            WHERE r.recipient_id = u.id AND $condition AND $reviewRole
        ) feedback ON TRUE
        LEFT JOIN LATERAL (
            SELECT COUNT(DISTINCT campaign.id)::integer AS count FROM campaign_offer o
            JOIN campaign_application a ON a.id = o.application_id JOIN campaign campaign ON campaign.id = a.campaign_id
            WHERE o.status = 'accepted' AND ($completedRole)
        ) completed ON TRUE ";
    }

    public function seek(): string
    {
        $equals = [];
        $parts = [];
        foreach (['featured' => '<', 'quality' => '<', 'jobs' => '<', 'strength' => '<', 'rotation' => '>', 'id' => '>'] as $column => $operator) {
            $comparison = $column === 'rotation' ? 'rotation COLLATE "C"' : $column;
            $parts[] = '('.implode(' AND ', [...$equals, "$comparison $operator :$column"]).')';
            $equals[] = "$comparison = :$column";
        }
        return ' AND ('.implode(' OR ', $parts).')';
    }

    public function position(array $row, array $parameters): array
    {
        return ['featured' => (bool) $row['featured'], 'quality' => (int) $row['quality'], 'jobs' => (int) $row['jobs'], 'strength' => (int) $row['strength'], 'rotation' => $row['rotation'], 'id' => (int) $row['id'], 'asOf' => $parameters['rankingNow']];
    }
}
