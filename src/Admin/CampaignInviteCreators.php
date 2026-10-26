<?php

declare(strict_types=1);

namespace App\Admin;

use App\Account\AccountEmailSender;
use App\Account\ProfileStrength;
use App\Api\DirectoryCursor;
use App\Entity\Campaign;
use App\Entity\CampaignInvitation;
use App\Entity\Creator;
use App\Entity\Notification;
use App\Entity\User;
use App\Media\ImageVariants;
use App\Service\NotificationDelivery;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admins invite creators to Wave's own campaigns in bulk. The message is personalised
 * per creator ({{name}}, {{companyName}}) and becomes the first chat message on accept.
 */
final class CampaignInviteCreators
{
    public const PAGE_SIZE = 20;
    public const MESSAGE_MAX = 2000;
    private const CHUNK = 100;

    /** Approved, verified, visible creators (agency studios excluded). */
    private const ELIGIBLE = "u.role = 'ROLE_CREATOR' AND u.deleted_at IS NULL AND u.approved = TRUE AND u.email_verified = TRUE
        AND u.hide_my_account = FALSE AND p.agency_profile = FALSE";
    private const TAKEN = '(EXISTS (SELECT 1 FROM campaign_invitation i WHERE i.campaign_id = :campaign AND i.creator_id = p.id)
        OR EXISTS (SELECT 1 FROM campaign_application a WHERE a.campaign_id = :campaign AND a.creator_id = p.id))';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $db,
        private readonly DirectoryCursor $cursor,
        private readonly NotificationDelivery $notifications,
        private readonly AccountEmailSender $emails,
    ) {
    }

    /** Campaigns admins can invite to: open campaigns of companies owned by an admin. @return list<array<string, mixed>> */
    public function campaigns(): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT c.id, c.slug, c.title, c.compensation_type, c.closes_at, co.name AS company_name,
                (SELECT COUNT(*) FROM campaign_invitation i WHERE i.campaign_id = c.id) AS invited
             FROM campaign c JOIN company co ON co.id = c.company_id JOIN wave_user o ON o.id = co.owner_id
             WHERE c.status = 'open' AND c.closes_at >= :today AND o.admin = TRUE AND o.deleted_at IS NULL
             ORDER BY c.published_at DESC, c.id DESC LIMIT 100",
            ['today' => (new \DateTimeImmutable('today'))->format('Y-m-d')],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'slug' => $row['slug'], 'title' => $row['title'], 'companyName' => $row['company_name'],
            'voluntary' => $row['compensation_type'] === Campaign::COMPENSATION_VOLUNTARY,
            'closesAt' => substr((string) $row['closes_at'], 0, 10), 'invited' => (int) $row['invited'],
        ], $rows);
    }

    public function campaign(int $id): ?Campaign
    {
        $campaign = $this->entityManager->find(Campaign::class, $id);
        $owner = $campaign?->getCompany()->getOwner();

        return $campaign instanceof Campaign && $owner instanceof User && !$owner->isDeleted() && $owner->hasRole('ROLE_ADMIN')
            && $campaign->getStatus() === 'open' && $campaign->getClosesAt() >= new \DateTimeImmutable('today')
            ? $campaign : null;
    }

    /** Twenty creators at a time, sorted by name, with profile image and profile strength. @return array<string, mixed> */
    public function page(Request $request, Campaign $campaign): array
    {
        $query = trim($request->query->getString('q'));
        if (mb_strlen($query) > 200 || $request->query->getInt('limit', self::PAGE_SIZE) !== self::PAGE_SIZE) {
            throw new \InvalidArgumentException('Invalid creator search.');
        }
        $kind = 'campaign-invite-'.$campaign->getId();
        $position = $this->cursor->decode($request, $kind, ['name' => 'string', 'id' => 'int']);
        $where = self::ELIGIBLE;
        $params = ['campaign' => $campaign->getId()];
        if ($query !== '') {
            $where .= " AND (LOWER(p.display_name) LIKE :q ESCAPE '!' OR LOWER(COALESCE(p.city, '')) LIKE :q ESCAPE '!')";
            $params['q'] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query)).'%';
        }
        $from = 'FROM creator p JOIN wave_user u ON u.id = p.owner_id WHERE '.$where;
        $seek = $position === null ? '' : ' AND (LOWER(p.display_name) > :name OR (LOWER(p.display_name) = :name AND p.id > :id))';
        $rows = $this->db->fetchAllAssociative(
            'SELECT p.id, p.owner_id, p.display_name, p.city, p.avatar_media_id, p.avatar_url, LOWER(p.display_name) AS sort_name, '
            ."(SELECT 'invited' FROM campaign_invitation i WHERE i.campaign_id = :campaign AND i.creator_id = p.id) AS invited, "
            ."(SELECT 'applied' FROM campaign_application a WHERE a.campaign_id = :campaign AND a.creator_id = p.id) AS applied "
            .$from.$seek.' ORDER BY LOWER(p.display_name) ASC, p.id ASC LIMIT '.(self::PAGE_SIZE + 1),
            $params + ($position ?? []),
        );
        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);
        $owners = $rows === [] ? [] : $this->entityManager->getRepository(User::class)->findBy(['id' => array_map(static fn (array $row): int => (int) $row['owner_id'], $rows)]);
        $strength = [];
        foreach ($owners as $owner) {
            $strength[$owner->getId()] = ProfileStrength::forUser($owner)['score'] ?? null;
        }
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        $counts = $position !== null ? null : $this->db->fetchAssociative(
            'SELECT COUNT(*) AS total, SUM(CASE WHEN '.self::TAKEN.' THEN 0 ELSE 1 END) AS available '.$from,
            $params,
        );

        return [
            'rows' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => $row['display_name'],
                'city' => $row['city'],
                'avatarUrl' => $row['avatar_media_id'] !== null
                    ? '/api/media/'.$row['avatar_media_id'].'/thumbnail/'.ImageVariants::VERSION.'/96'
                    : ($row['avatar_url'] ?: null),
                'profileStrength' => $strength[(int) $row['owner_id']] ?? null,
                'status' => $row['applied'] ?? $row['invited'] ?? null,
            ], $rows),
            'hasMore' => $hasMore,
            'nextCursor' => !$hasMore || $last === null ? null : $this->cursor->encode($request, $kind, ['name' => $last['sort_name'], 'id' => (int) $last['id']]),
            'total' => $counts === null || $counts === false ? null : (int) $counts['total'],
            'available' => $counts === null || $counts === false ? null : (int) $counts['available'],
        ];
    }

    /**
     * @param list<int>|null $creatorIds null invites every eligible creator not yet invited
     * @return array{invited: int, skipped: int}
     */
    public function invite(Campaign $campaign, string $message, ?array $creatorIds): array
    {
        $campaignId = (int) $campaign->getId();
        $inviterId = (int) $campaign->getCompany()->getOwner()?->getId();
        $companyName = $campaign->getCompany()->getName();
        $campaignTitle = $campaign->getTitle();
        $voluntary = $campaign->isVoluntary();
        $sql = 'SELECT p.id FROM creator p JOIN wave_user u ON u.id = p.owner_id WHERE '.self::ELIGIBLE.' AND NOT '.self::TAKEN;
        $params = ['campaign' => $campaignId];
        $types = [];
        if ($creatorIds !== null) {
            $sql .= ' AND p.id IN (:ids)';
            $params['ids'] = $creatorIds;
            $types['ids'] = ArrayParameterType::INTEGER;
        }
        $ids = array_map('intval', $this->db->fetchFirstColumn($sql.' ORDER BY p.id', $params, $types));
        $invited = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $campaign = $this->entityManager->find(Campaign::class, $campaignId);
            $inviter = $this->entityManager->find(User::class, $inviterId);
            if (!$campaign instanceof Campaign || !$inviter instanceof User) {
                break;
            }
            $created = [];
            foreach ($this->entityManager->getRepository(Creator::class)->findBy(['id' => $chunk]) as $creator) {
                $owner = $creator->getOwner();
                if (!$owner instanceof User) {
                    continue;
                }
                $text = self::personalise($message, $creator->getDisplayName(), $companyName);
                $this->entityManager->persist(new CampaignInvitation($campaign, $creator, $inviter, $text));
                $notification = new Notification($owner, 'campaign_invitation', $inviter, $campaign);
                $this->entityManager->persist($notification);
                $created[] = [$notification, $owner, $text];
            }
            $this->entityManager->flush();
            // Same in-app notification and "campaign_invitation" email as a brand's invitation (emails are queued).
            foreach ($created as [$notification, $owner, $text]) {
                $this->notifications->deliver($notification);
                $this->emails->sendCampaignInvitation($owner, $campaignTitle, $companyName, $text, $voluntary);
            }
            $invited += count($created);
            $this->entityManager->clear();
        }

        return ['invited' => $invited, 'skipped' => $creatorIds === null ? 0 : count(array_unique($creatorIds)) - $invited];
    }

    /** Fills {{name}} and {{companyName}} (spaces inside the braces are allowed). */
    public static function personalise(string $message, string $name, string $companyName): string
    {
        $text = preg_replace_callback('/\{\{\s*(name|companyName)\s*\}\}/', static fn (array $match): string => $match[1] === 'name' ? $name : $companyName, $message) ?? $message;

        return trim($text);
    }

    /** @return list<string> */
    public static function unknownVariables(string $message): array
    {
        preg_match_all('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/', $message, $matches);

        return array_values(array_unique(array_diff($matches[1], ['name', 'companyName'])));
    }
}
