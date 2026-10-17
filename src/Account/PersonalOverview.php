<?php

namespace App\Account;

use App\Entity\User;
use App\Reviews\CollaborationFeedback;
use App\Safety\BlockPolicy;
use Doctrine\DBAL\Connection;

/** Small, owner-scoped queries; the overview never loads entire activity collections. */
final class PersonalOverview
{
    public function __construct(private readonly Connection $db, private readonly BlockPolicy $blocks, private readonly CollaborationFeedback $feedback, private readonly \App\Credits\CreditService $credits) {}

    public function snapshot(User $user): array
    {
        $creator = $user->hasRole('ROLE_COMPANY') ? null : $user->getCreator();
        $company = $user->getCompany();
        $ready = $user->hasCompleteProfile() && $user->isApproved() && $user->isEmailVerified();
        $counts = $creator !== null ? ['invitations' => 0, 'offers' => 0, 'active' => 0, 'applications' => 0] : ['campaigns' => 0, 'applications' => 0, 'offers' => 0, 'active' => 0];
        $settings = $this->credits->settings();
        $creditSummary = ['unlimited' => !$settings['paid'], 'balance' => $settings['paid'] ? (int) $this->db->fetchOne('SELECT balance FROM credit_wallet WHERE user_id = ?', [$user->getId()]) : 0];
        $result = ['credits' => $creditSummary, 'ready' => $ready, 'counts' => $counts, 'recent' => [], 'financial' => null];
        if (!$ready || ($creator === null && $company === null)) return $result;
        $params = ['owner' => $user->getId()];
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        if ($creator !== null) {
            $visible = $this->blocks->visible('p.owner_id');
            $join = "JOIN campaign c ON c.id = a.campaign_id JOIN creator r ON r.id = a.creator_id JOIN company p ON p.id = c.company_id JOIN wave_user u ON u.id = p.owner_id";
            $scope = "r.owner_id = :owner AND u.deleted_at IS NULL AND $visible";
            $counts['applications'] = (int) $this->db->fetchOne("SELECT COUNT(*) FROM campaign_application a $join WHERE $scope", $params);
            $counts['applications'] += (int) $this->db->fetchOne("SELECT COUNT(*) FROM campaign_invitation a $join WHERE $scope AND NOT EXISTS (SELECT 1 FROM campaign_application app WHERE app.campaign_id = a.campaign_id AND app.creator_id = a.creator_id)", $params);
            $counts['invitations'] = (int) $this->db->fetchOne("SELECT COUNT(*) FROM campaign_invitation a $join WHERE $scope AND a.status = 'pending' AND c.status = 'open' AND c.closes_at >= :today", [...$params, 'today' => $today]);
            $rows = $this->db->fetchAllAssociative("SELECT a.id, 'application' AS source, a.status, a.created_at, c.id AS campaign_id, c.slug, c.title, c.status AS campaign_status, p.name AS company_name, o.status AS offer_status, o.amount, c.currency, c.closes_at FROM campaign_application a $join LEFT JOIN campaign_offer o ON o.application_id = a.id WHERE $scope
                UNION ALL SELECT a.id, 'invitation' AS source, a.status, a.created_at, c.id AS campaign_id, c.slug, c.title, c.status AS campaign_status, p.name AS company_name, NULL AS offer_status, NULL AS amount, c.currency, c.closes_at FROM campaign_invitation a $join WHERE $scope AND NOT EXISTS (SELECT 1 FROM campaign_application app WHERE app.campaign_id = a.campaign_id AND app.creator_id = a.creator_id)
                ORDER BY created_at DESC, campaign_id DESC LIMIT 5", $params);
        } else {
            $counts['campaigns'] = (int) $this->db->fetchOne("SELECT COUNT(*) FROM campaign c JOIN company p ON p.id = c.company_id WHERE p.owner_id = :owner AND c.status = 'open' AND c.closes_at >= :today", [...$params, 'today' => $today]);
            $visible = $this->blocks->visible('r.owner_id');
            $join = "JOIN campaign c ON c.id = a.campaign_id JOIN creator r ON r.id = a.creator_id JOIN company p ON p.id = c.company_id JOIN wave_user u ON u.id = r.owner_id";
            $scope = "p.owner_id = :owner AND u.deleted_at IS NULL AND $visible";
            $counts['applications'] = (int) $this->db->fetchOne("SELECT COUNT(*) FROM campaign_application a $join WHERE $scope AND a.status = 'pending' AND c.status = 'open'", $params);
            $rows = $this->db->fetchAllAssociative("SELECT c.id AS campaign_id, c.slug, c.title, c.status AS campaign_status, p.name AS company_name, c.published_at AS created_at FROM campaign c JOIN company p ON p.id = c.company_id WHERE p.owner_id = :owner ORDER BY c.published_at DESC, c.id DESC LIMIT 5", $params);
        }
        $counts['offers'] = (int) $this->db->fetchOne("SELECT COUNT(*) FROM campaign_offer o JOIN campaign_application a ON a.id = o.application_id $join WHERE $scope AND o.status = 'pending' AND c.status = 'open' AND c.closes_at >= :today", [...$params, 'today' => $today]);
        $counts['active'] = (int) $this->db->fetchOne("SELECT COUNT(*) FROM campaign_offer o JOIN campaign_application a ON a.id = o.application_id $join WHERE $scope AND o.status = 'accepted' AND c.status NOT IN ('finished', 'cancelled')", $params);
        foreach ($rows as &$row) {
            $row['campaign_id'] = (int) $row['campaign_id'];
            if (isset($row['amount'])) $row['amount'] = (int) $row['amount'];
            // DBAL returns naive UTC strings on both supported databases.
            $row['created_at'] = (new \DateTimeImmutable($row['created_at'], new \DateTimeZone('UTC')))->format(DATE_ATOM);
        }
        if ($user->getCreator()?->isAgencyProfile()) {
            $service = $this->db->fetchAssociative("SELECT COUNT(*) AS applications,
                SUM(CASE WHEN o.status = 'pending' AND c.status = 'open' THEN 1 ELSE 0 END) AS offers,
                SUM(CASE WHEN o.status = 'accepted' AND c.status NOT IN ('finished', 'cancelled') THEN 1 ELSE 0 END) AS active
                FROM campaign_application a JOIN creator p ON p.id = a.creator_id JOIN campaign c ON c.id = a.campaign_id
                LEFT JOIN campaign_offer o ON o.application_id = a.id WHERE p.owner_id = ?", [$user->getId()]);
            $result['serviceCounts'] = array_map('intval', $service);
            $result['serviceCounts']['invitations'] = (int) $this->db->fetchOne("SELECT COUNT(*) FROM campaign_invitation i JOIN creator p ON p.id = i.creator_id JOIN campaign c ON c.id = i.campaign_id WHERE p.owner_id = ? AND i.status = 'pending' AND c.status = 'open' AND c.closes_at >= ?", [$user->getId(), $today]);
        }
        return [...$result, 'counts' => $counts, 'recent' => $rows, 'financial' => $this->feedback->earnings($user)];
    }
}
