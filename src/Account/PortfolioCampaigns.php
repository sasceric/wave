<?php

namespace App\Account;

use App\Entity\Campaign;
use App\Entity\User;
use App\Safety\BlockPolicy;
use Doctrine\ORM\EntityManagerInterface;

/** Shared eligibility for the portfolio picker and saving linked work. */
final class PortfolioCampaigns
{
    public function __construct(private readonly EntityManagerInterface $manager, private readonly BlockPolicy $blocks) {}

    public function page(User $user, string $locale, int $before = 0): array
    {
        $rows = $this->matches($user, before: $before);
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $campaigns = $this->campaigns($rows);
        $data = [];
        foreach ($rows as $row) {
            $campaign = $campaigns[(int) $row['id']];
            $translation = $campaign->getTranslations()[$locale] ?? [];
            $data[] = [
                'id' => $campaign->getId(),
                'title' => $translation['title'] ?? $campaign->getTitle(),
                'summary' => $translation['summary'] ?? $campaign->getSummary(),
                'description' => $translation['description'] ?? $campaign->getDescription(),
                'channels' => $campaign->getChannels(),
                'company' => $campaign->getCompany()->getName(),
                'companyType' => $campaign->getCompany()->getCompanyType(),
                'completedAt' => $this->date($row['completed_at']),
                'finishedAt' => $campaign->getFinishedAt()?->format('Y-m-d'),
            ];
        }
        return ['data' => $data, 'meta' => ['hasMore' => $more, 'nextCursor' => $more ? $data[array_key_last($data)]['id'] : null]];
    }

    /** @return array{campaign: Campaign, completedAt: ?string}|null */
    public function find(User $user, int $campaignId): ?array
    {
        $rows = $this->matches($user, campaignId: $campaignId);
        if ($rows === []) return null;
        return ['campaign' => $this->campaigns($rows)[$campaignId], 'completedAt' => $this->date($rows[0]['completed_at'])];
    }

    private function matches(User $user, ?int $campaignId = null, int $before = 0): array
    {
        // Providers can show their own completed hires. An agency organizing a
        // campaign can show it as soon as one of its hired providers completes work.
        // Group before pagination: several completed hires still represent one campaign.
        $conditions = '';
        $params = ['provider' => $user->getCreator()?->getId() ?? 0, 'agency' => $user->isAgency() ? ($user->getCompany()?->getId() ?? 0) : 0];
        if ($campaignId !== null) { $conditions .= ' AND c.id = :campaign'; $params['campaign'] = $campaignId; }
        if ($before > 0) { $conditions .= ' AND c.id < :before'; $params['before'] = $before; }
        $clientVisible = $this->blocks->visible('p.owner_id');
        $providerVisible = $this->blocks->visible('provider.owner_id');
        return $this->manager->getConnection()->fetchAllAssociative("SELECT c.id, COALESCE(MIN(o.work_completed_at), c.finished_at) AS completed_at
            FROM campaign c JOIN company p ON p.id = c.company_id
            JOIN campaign_application a ON a.campaign_id = c.id JOIN campaign_offer o ON o.id = a.offer_id
            JOIN creator provider ON provider.id = a.creator_id
            LEFT JOIN wave_user client_owner ON client_owner.id = p.owner_id
            LEFT JOIN wave_user provider_owner ON provider_owner.id = provider.owner_id
            WHERE o.status = 'accepted' AND (o.work_completed_at IS NOT NULL OR c.status = 'finished')
                AND (a.creator_id = :provider OR c.company_id = :agency)
                AND (client_owner.id IS NULL OR client_owner.deleted_at IS NULL)
                AND (provider_owner.id IS NULL OR provider_owner.deleted_at IS NULL)
                AND $clientVisible AND $providerVisible $conditions
            GROUP BY c.id ORDER BY c.id DESC LIMIT 21", $params);
    }

    /** @return array<int, Campaign> */
    private function campaigns(array $rows): array
    {
        if ($rows === []) return [];
        $campaigns = $this->manager->getRepository(Campaign::class)->createQueryBuilder('campaign')
            ->join('campaign.company', 'company')->addSelect('company')
            ->where('campaign.id IN (:ids)')->setParameter('ids', array_column($rows, 'id'))->getQuery()->getResult();
        $byId = [];
        foreach ($campaigns as $campaign) $byId[$campaign->getId()] = $campaign;
        return $byId;
    }

    private function date(?string $value): ?string
    {
        return $value === null ? null : (new \DateTimeImmutable($value))->format('Y-m-d');
    }
}
