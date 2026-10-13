<?php

namespace App\Safety;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/** Personal blocks apply in either direction; admin moderation remains separate. */
final class BlockPolicy implements ResetInterface
{
    private ?array $cached = null;
    public function __construct(private readonly Connection $db, private readonly Security $security) {}
    public function reset(): void { $this->cached = null; }
    public function viewerId(): ?int { $user = $this->security->getUser(); return $user instanceof User ? $user->getId() : null; }
    public function staff(): bool { $user = $this->security->getUser(); return $user instanceof User && $user->hasRole('ROLE_ADMIN'); }
    public function ids(?int $viewer = null): array
    {
        $viewer ??= $this->viewerId();
        if ($viewer === null) { return []; }
        if ($this->cached !== null && $this->cached['viewer'] === $viewer) { return $this->cached['ids']; }
        $ids = array_map(intval(...), $this->db->fetchFirstColumn('SELECT CASE WHEN blocker_id = :viewer THEN blocked_id ELSE blocker_id END FROM user_block WHERE cleared_at IS NULL AND (blocker_id = :viewer OR blocked_id = :viewer)', ['viewer' => $viewer]));
        $this->cached = ['viewer' => $viewer, 'ids' => array_values(array_unique($ids))];
        return $this->cached['ids'];
    }
    public function blocked(?int $other): bool { return $other !== null && in_array($other, $this->ids(), true); }
    public function pair(?int $one, ?int $two): bool
    {
        return $one !== null && $two !== null && $one !== $two && (bool) $this->db->fetchOne('SELECT 1 FROM user_block WHERE cleared_at IS NULL AND ((blocker_id = :one AND blocked_id = :two) OR (blocker_id = :two AND blocked_id = :one)) LIMIT 1', ['one' => $one, 'two' => $two]);
    }
    /** Trusted SQL/DQL column expression; values come only from persisted user IDs. */
    public function visible(string $ownerColumn): string
    {
        $ids = $this->ids();
        return $ids === [] ? '1 = 1' : "($ownerColumn IS NULL OR $ownerColumn NOT IN (".implode(',', $ids).'))';
    }
    public function target(string $kind, string $slug): ?array
    {
        $sql = match ($kind) {
            'creator' => 'SELECT p.owner_id AS user_id, p.display_name AS name, p.slug FROM creator p WHERE p.slug = ?',
            'company' => 'SELECT p.owner_id AS user_id, p.name, p.slug FROM company p WHERE p.slug = ?',
            'campaign' => 'SELECT p.owner_id AS user_id, c.title AS name, c.slug FROM campaign c JOIN company p ON p.id = c.company_id WHERE c.slug = ?',
            default => null,
        };
        return $sql === null ? null : ($this->db->fetchAssociative($sql, [$slug]) ?: null);
    }
    public function eventAllowed(int $viewer, array $payload): bool
    {
        $sender = $payload['senderId'] ?? $payload['message']['senderId'] ?? null;
        if (is_int($sender) && $this->pair($viewer, $sender)) { return false; }
        if (isset($payload['notificationId'])) {
            $actor = $this->db->fetchOne('SELECT actor_id FROM wave_notification WHERE id = ?', [$payload['notificationId']]);
            if ($actor !== false && $actor !== null && $this->pair($viewer, (int) $actor)) { return false; }
        }
        $query = [];
        if (is_string($payload['url'] ?? null)) { parse_str(parse_url($payload['url'], PHP_URL_QUERY) ?? '', $query); }
        foreach (['conversation' => 'campaign_conversation', 'inquiry' => 'creator_inquiry'] as $kind => $table) {
            $id = $payload[$kind.'Id'] ?? $query[$kind] ?? null;
            if (!is_scalar($id) || !ctype_digit((string) $id)) { continue; }
            $company = $kind === 'conversation' ? 'JOIN campaign c ON c.id = t.campaign_id JOIN company p ON p.id = c.company_id' : 'JOIN company p ON p.id = t.company_id';
            $owners = $this->db->fetchAssociative("SELECT r.owner_id AS creator, p.owner_id AS company FROM $table t JOIN creator r ON r.id = t.creator_id $company WHERE t.id = ?", [(int) $id]);
            if ($owners && $this->pair((int) $owners['creator'], (int) $owners['company'])) { return false; }
        }
        return true;
    }
}
