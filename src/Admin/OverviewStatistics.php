<?php

namespace App\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final class OverviewStatistics
{
    public function __construct(private readonly Connection $db) {}

    public function snapshot(int $days): array
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $from = $today->modify('-'.($days - 1).' days');
        $parameters = ['from' => $from->format('Y-m-d H:i:s'), 'until' => $today->modify('+1 day')->format('Y-m-d H:i:s')];
        $attention = $this->db->fetchAssociative("SELECT
            (SELECT COUNT(*) FROM wave_user WHERE deleted_at IS NULL AND approved = FALSE AND admin = FALSE AND moderator = FALSE AND role IN ('ROLE_CREATOR', 'ROLE_COMPANY')) AS approvals,
            (SELECT COUNT(*) FROM marketplace_report WHERE status IN ('received', 'processing')) AS reports,
            (SELECT COUNT(*) FROM wave_support_ticket WHERE status IN ('open', 'in_progress', 'on_hold')) AS tickets");
        // Timestamp casts and substrings work with PostgreSQL and historical SQLite databases.
        $daily = $this->db->fetchAllAssociative("SELECT day, kind, COUNT(*) AS total FROM (
            SELECT SUBSTR(CAST(c.created_at AS VARCHAR), 1, 10) AS day, 'creators' AS kind FROM creator c
            JOIN wave_user u ON u.id = c.owner_id WHERE u.deleted_at IS NULL AND c.created_at >= :from AND c.created_at < :until
            UNION ALL SELECT SUBSTR(CAST(published_at AS VARCHAR), 1, 10), 'campaigns' FROM campaign WHERE published_at >= :from AND published_at < :until
            UNION ALL SELECT SUBSTR(CAST(created_at AS VARCHAR), 1, 10), 'applications' FROM campaign_application WHERE created_at >= :from AND created_at < :until
            UNION ALL SELECT SUBSTR(CAST(created_at AS VARCHAR), 1, 10), 'invitations' FROM campaign_invitation WHERE created_at >= :from AND created_at < :until
            UNION ALL SELECT SUBSTR(CAST(responded_at AS VARCHAR), 1, 10), 'hires' FROM campaign_offer WHERE status = 'accepted' AND responded_at >= :from AND responded_at < :until
            UNION ALL SELECT SUBSTR(CAST(finished_at AS VARCHAR), 1, 10), 'completed' FROM campaign WHERE status = 'finished' AND finished_at >= :from AND finished_at < :until
        ) activity GROUP BY day, kind ORDER BY day", $parameters);
        $series = [];
        for ($index = 0; $index < $days; ++$index) {
            $day = $from->modify('+'.$index.' days')->format('Y-m-d');
            $series[$day] = ['date' => $day, 'creators' => 0, 'campaigns' => 0, 'applications' => 0, 'invitations' => 0, 'hires' => 0, 'completed' => 0];
        }
        $totals = ['creators' => 0, 'campaigns' => 0, 'applications' => 0, 'invitations' => 0, 'hires' => 0, 'completed' => 0];
        foreach ($daily as $row) {
            $count = (int) $row['total'];
            $series[$row['day']][$row['kind']] = $count;
            $totals[$row['kind']] += $count;
        }
        // This distribution describes campaigns published in the period, by their current status.
        $statuses = ['open' => 0, 'closed' => 0, 'finished' => 0, 'cancelled' => 0];
        foreach ($this->db->fetchAllAssociative('SELECT status, COUNT(*) AS total FROM campaign WHERE published_at >= :from AND published_at < :until GROUP BY status', $parameters) as $row) {
            if (array_key_exists($row['status'], $statuses)) $statuses[$row['status']] = (int) $row['total'];
        }
        $recent = $this->db->fetchAllAssociative('SELECT id, title, status, published_at FROM campaign ORDER BY published_at DESC, id DESC LIMIT 5');
        return ['days' => $days, 'from' => $from->format('Y-m-d'), 'to' => $today->format('Y-m-d'), 'timezone' => 'UTC',
            'attention' => array_map(intval(...), $attention ?: []), 'totals' => $totals,
            'series' => array_values($series), 'campaignStatuses' => $statuses, 'recentCampaigns' => $recent];
    }
}
