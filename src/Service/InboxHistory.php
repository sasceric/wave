<?php

namespace App\Service;

use App\Entity\User;
use App\Localization\ApiMessages;
use App\Media\ImageVariants;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/** A merged, bounded inbox; message bodies/history are loaded only on selection. */
final class InboxHistory
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function page(User $user, Request $request, string $locale): array
    {
        $limit = filter_var($request->query->get('limit', '30'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $filter = $request->query->get('filter', 'all');
        $search = trim($request->query->get('q', ''));
        if ($limit === false || !in_array($filter, ['all', 'unread'], true) || mb_strlen($search) > 200) {
            throw new \InvalidArgumentException('Invalid inbox filters.');
        }
        $cursor = $this->decodeCursor($request->query->get('cursor'));
        $isCreator = $user->getCreator() !== null;
        $scope = $isCreator ? 'creator_id' : 'company_id';
        $profileId = $isCreator ? $user->getCreator()->getId() : $user->getCompany()->getId();
        $companyScope = $isCreator ? 'thread.creator_id = :profile' : 'campaign.company_id = :profile';
        $params = ['profile' => $profileId, 'viewer' => $user->getId(), 'locale' => $locale];

        // The lateral lookup uses the inquiry/created_at/id index. It supplies the
        // latest preview and timestamp in one query instead of one ORM query per row.
        $sql = <<<SQL
            SELECT * FROM (
                SELECT thread.id, 'campaign' AS thread_type, thread.updated_at AS activity_at,
                    thread.last_message_preview AS preview, thread.last_message_sender_id AS sender_id,
                    campaign.slug AS campaign_slug,
                    COALESCE(CAST(campaign.translations AS JSONB) -> CAST(:locale AS TEXT) ->> 'title', campaign.title) AS title,
                    creator.id AS creator_id, creator.slug AS creator_slug, creator.display_name,
                    creator.avatar_url, creator.avatar_media_id,
                    company.id AS company_id, company.slug AS company_slug, company.name AS company_name,
                    company.logo_url, company.logo_media_id,
                    (creator_owner.deleted_at IS NOT NULL) AS creator_deleted, (company_owner.deleted_at IS NOT NULL) AS company_deleted,
                    (SELECT COUNT(*) FROM campaign_message message WHERE message.conversation_id = thread.id
                        AND message.sender_id <> :viewer AND message.read_at IS NULL) AS unread_count
                FROM campaign_conversation thread
                JOIN campaign ON campaign.id = thread.campaign_id
                JOIN creator ON creator.id = thread.creator_id
                JOIN company ON company.id = campaign.company_id
                LEFT JOIN wave_user creator_owner ON creator_owner.id = creator.owner_id
                LEFT JOIN wave_user company_owner ON company_owner.id = company.owner_id
                WHERE $companyScope
                UNION ALL
                SELECT thread.id, 'inquiry' AS thread_type, COALESCE(latest.created_at, thread.created_at) AS activity_at,
                    LEFT(COALESCE(latest.body, thread.message), 240) AS preview, latest.sender_id,
                    NULL AS campaign_slug, COALESCE(thread.package_title, '') AS title,
                    creator.id AS creator_id, creator.slug AS creator_slug, creator.display_name,
                    creator.avatar_url, creator.avatar_media_id,
                    company.id AS company_id, company.slug AS company_slug, company.name AS company_name,
                    company.logo_url, company.logo_media_id,
                    (creator_owner.deleted_at IS NOT NULL) AS creator_deleted, (company_owner.deleted_at IS NOT NULL) AS company_deleted,
                    (SELECT COUNT(*) FROM inquiry_message message WHERE message.inquiry_id = thread.id
                        AND message.sender_id <> :viewer AND message.read_at IS NULL) AS unread_count
                FROM creator_inquiry thread
                JOIN creator ON creator.id = thread.creator_id
                JOIN company ON company.id = thread.company_id
                LEFT JOIN wave_user creator_owner ON creator_owner.id = creator.owner_id
                LEFT JOIN wave_user company_owner ON company_owner.id = company.owner_id
                LEFT JOIN LATERAL (SELECT body, created_at, sender_id FROM inquiry_message
                    WHERE inquiry_id = thread.id ORDER BY created_at DESC, id DESC LIMIT 1) latest ON TRUE
                WHERE thread.$scope = :profile AND thread.status = 'accepted'
            ) inbox WHERE 1 = 1
            SQL;
        if ($filter === 'unread') {
            $sql .= ' AND unread_count > 0';
        }
        if ($search !== '') {
            $name = $isCreator ? 'company_name' : 'display_name';
            $sql .= " AND (LOWER($name) LIKE :search ESCAPE '!' OR LOWER(title) LIKE :search ESCAPE '!' OR LOWER(preview) LIKE :search ESCAPE '!')";
            $params['search'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%';
        }
        if ($cursor !== null) {
            $sql .= ' AND (activity_at < :at OR (activity_at = :at AND (thread_type > :type OR (thread_type = :type AND id < :id))))';
            $params += $cursor;
        }
        $sql .= ' ORDER BY activity_at DESC, thread_type ASC, id DESC LIMIT ' . ($limit + 1);
        $rows = $this->entityManager->getConnection()->fetchAllAssociative($sql, $params);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return [
            'data' => array_map(fn (array $row): array => $this->resource($row, $locale), $rows),
            'meta' => [
                'limit' => $limit,
                'hasMore' => $hasMore,
                'nextCursor' => !$hasMore || $last === null ? null : rtrim(strtr(base64_encode(json_encode([
                    'at' => $last['activity_at'], 'type' => $last['thread_type'], 'id' => (int) $last['id'],
                ], JSON_THROW_ON_ERROR)), '+/', '-_'), '='),
            ],
        ];
    }

    private function resource(array $row, string $locale): array
    {
        $type = $row['thread_type'];
        $id = (int) $row['id'];

        return [
            'id' => $id,
            'threadType' => $type,
            'threadKey' => $type . '-' . $id,
            'readOnly' => (bool) $row['creator_deleted'] || (bool) $row['company_deleted'],
            'campaign' => ['slug' => $row['campaign_slug'], 'title' => $row['title']],
            'creator' => [
                'id' => (int) $row['creator_id'], 'slug' => $row['creator_slug'], 'displayName' => $row['creator_deleted'] ? ApiMessages::get('deleted_account', $locale) : $row['display_name'],
                'deleted' => (bool) $row['creator_deleted'],
                'avatarUrl' => $row['avatar_media_id'] === null ? $row['avatar_url'] : '/api/media/' . $row['avatar_media_id'] . '/thumbnail/' . ImageVariants::VERSION . '/96',
            ],
            'company' => [
                'id' => (int) $row['company_id'], 'slug' => $row['company_slug'], 'name' => $row['company_deleted'] ? ApiMessages::get('deleted_account', $locale) : $row['company_name'],
                'deleted' => (bool) $row['company_deleted'],
                'logoUrl' => $row['logo_media_id'] === null ? $row['logo_url'] : '/api/media/' . $row['logo_media_id'] . '/thumbnail/' . ImageVariants::VERSION . '/96',
            ],
            'packageTitle' => $type === 'inquiry' ? $row['title'] : null,
            'canChat' => true,
            'lastMessage' => $row['preview'],
            'lastMessageAt' => (new \DateTimeImmutable($row['activity_at']))->format(DATE_ATOM),
            'lastMessageSenderId' => $row['sender_id'] === null ? null : (int) $row['sender_id'],
            'unreadCount' => (int) $row['unread_count'],
        ];
    }

    private function decodeCursor(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (strlen($value) > 512 || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw new \InvalidArgumentException('Invalid inbox cursor.');
        }
        try {
            $cursor = json_decode(base64_decode(strtr($value, '-_', '+/'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Invalid inbox cursor.');
        }
        if (!is_array($cursor) || !is_string($cursor['at'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/D', $cursor['at'])
            || !in_array($cursor['type'] ?? null, ['campaign', 'inquiry'], true)
            || !is_int($cursor['id'] ?? null) || $cursor['id'] < 1 || $cursor['id'] > 2147483647
            || (int) substr($cursor['at'], 0, 4) < 1) {
            throw new \InvalidArgumentException('Invalid inbox cursor.');
        }
        // Reject impossible calendar dates before sending the timestamp to PostgreSQL.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', str_contains($cursor['at'], '.') ? $cursor['at'] : $cursor['at'] . '.000000');
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new \InvalidArgumentException('Invalid inbox cursor.');
        }

        return array_intersect_key($cursor, array_flip(['at', 'type', 'id']));
    }
}
