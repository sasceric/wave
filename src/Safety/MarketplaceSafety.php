<?php

namespace App\Safety;

use App\Entity\User;
use App\Service\RealtimeUpdatePublisher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MarketplaceSafety
{
    public function __construct(private readonly Connection $db, private readonly EntityManagerInterface $em, private readonly BlockPolicy $blocks, private readonly RealtimeUpdatePublisher $publisher, #[Autowire('%kernel.project_dir%/config/report_reasons.json')] private readonly string $reasonFile) {}
    public function reasons(): array { return json_decode(file_get_contents($this->reasonFile), true, flags: JSON_THROW_ON_ERROR); }
    private function target(User $viewer, string $kind, string $slug): array
    {
        $target = $this->blocks->target($kind, $slug);
        $user = $target === null ? null : $this->db->fetchAssociative('SELECT id, deleted_at, approved, hide_my_account FROM wave_user WHERE id = ?', [$target['user_id']]);
        if (!$user || $user['deleted_at'] !== null || (int) $user['id'] === $viewer->getId() || !$user['approved'] || $user['hide_my_account']) { throw new HttpException(404, 'profile_unavailable'); }
        return $target;
    }
    public function report(User $viewer, string $kind, string $slug, array $data): array
    {
        $target = $this->target($viewer, $kind, $slug);
        $attachments = $data['attachmentIds'] ?? [];
        if (!is_array($attachments) || !array_is_list($attachments) || count($attachments) > 3 || array_filter($attachments, static fn ($id): bool => !is_int($id) || $id < 1) !== [] || count(array_unique($attachments)) !== count($attachments)) { throw new HttpException(400, 'invalid_media'); }
        foreach ($attachments as $mediaId) {
            if (!$this->db->fetchOne("SELECT m.id FROM media m JOIN media_folder f ON f.id = m.folder_id WHERE m.id = ? AND m.owner_id = ? AND f.slug = 'report-evidence'", [$mediaId, $viewer->getId()])) { throw new HttpException(400, 'invalid_media'); }
        }
        $category = $data['category'] ?? null; $reason = $data['reason'] ?? null;
        $description = is_string($data['description'] ?? null) ? trim($data['description']) : '';
        if (!is_string($category) || !is_string($reason) || !in_array($reason, $this->reasons()[$category] ?? [], true) || mb_strlen($description) > 2000 || ($category === 'other' && mb_strlen($description) < 10)) { throw new HttpException(400, 'invalid_request'); }
        $result = $this->db->transactional(function () use ($viewer, $kind, $slug, $target, $category, $reason, $description, $attachments): array {
            $this->db->executeStatement('UPDATE wave_user SET id = id WHERE id = ?', [$viewer->getId()]);
            if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM marketplace_report WHERE reporter_id = ? AND created_at >= ?', [$viewer->getId(), gmdate('Y-m-d H:i:s', time() - 3600)]) >= 10) { throw new HttpException(429, 'rate_limited'); }
            if ($this->db->fetchOne("SELECT id FROM marketplace_report WHERE reporter_id = ? AND target_kind = ? AND target_slug = ? AND status IN ('received', 'processing')", [$viewer->getId(), $kind, $slug])) { throw new HttpException(409, 'report_duplicate'); }
            $id = bin2hex(random_bytes(16)); $now = gmdate('Y-m-d H:i:s');
            $this->db->insert('marketplace_report', ['id' => $id, 'reporter_id' => $viewer->getId(), 'target_user_id' => $target['user_id'], 'target_kind' => $kind, 'target_slug' => $slug, 'target_name' => mb_substr($target['name'], 0, 200), 'category' => $category, 'reason' => $reason, 'description' => $description, 'status' => 'received', 'response' => '', 'version' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $this->db->insert('report_notice', ['id' => bin2hex(random_bytes(16)), 'recipient_id' => $viewer->getId(), 'report_id' => $id, 'version' => 1, 'status' => 'received', 'message' => '', 'created_at' => $now]);
            foreach ($attachments as $mediaId) { $this->db->insert('marketplace_report_media', ['report_id' => $id, 'media_id' => $mediaId]); }
            return ['id' => $id, 'status' => 'received'];
        });
        $this->publisher->publishEvent($viewer, ['type' => 'report_update']);
        return $result;
    }
    public function block(User $viewer, string $kind, string $slug): array
    {
        $target = $this->target($viewer, $kind, $slug);
        $id = $this->db->transactional(function () use ($viewer, $target): string {
            $this->db->executeStatement('UPDATE wave_user SET id = id WHERE id = ?', [$viewer->getId()]);
            $id = $this->db->fetchOne('SELECT id FROM user_block WHERE blocker_id = ? AND blocked_id = ? AND cleared_at IS NULL', [$viewer->getId(), $target['user_id']]);
            if ($id !== false) { return $id; }
            $id = bin2hex(random_bytes(16));
            $this->db->insert('user_block', ['id' => $id, 'blocker_id' => $viewer->getId(), 'blocked_id' => $target['user_id'], 'created_at' => gmdate('Y-m-d H:i:s')]);
            $this->db->executeStatement('UPDATE wave_notification SET read_at = ? WHERE read_at IS NULL AND ((recipient_id = ? AND actor_id = ?) OR (recipient_id = ? AND actor_id = ?))', [gmdate('Y-m-d H:i:s'), $viewer->getId(), $target['user_id'], $target['user_id'], $viewer->getId()]);
            return $id;
        });
        $this->blocks->reset();
        $this->changed($viewer->getId(), (int) $target['user_id']);
        return ['id' => $id];
    }
    public function unblock(User $viewer, string $id): void
    {
        $row = $this->db->fetchAssociative('SELECT * FROM user_block WHERE id = ?', [$id]);
        if (!$row || (int) $row['blocker_id'] !== $viewer->getId()) { throw new HttpException(404, 'forbidden'); }
        $this->db->executeStatement('UPDATE user_block SET cleared_at = ? WHERE id = ? AND cleared_at IS NULL', [gmdate('Y-m-d H:i:s'), $id]);
        $this->blocks->reset();
        $this->changed((int) $row['blocker_id'], (int) $row['blocked_id']);
    }
    private function changed(int $one, int $two): void
    {
        foreach ([$one, $two] as $id) { $user = $this->em->find(User::class, $id); if ($user instanceof User) { $this->publisher->publishEvent($user, ['type' => 'safety_changed']); } }
    }
    public function resolve(string $id, array $data): array
    {
        $status = $data['status'] ?? null; $version = $data['version'] ?? null; $message = is_string($data['response'] ?? null) ? trim($data['response']) : '';
        if (!in_array($status, ['processing', 'resolved', 'dismissed'], true) || !is_int($version) || mb_strlen($message) > 1000 || (in_array($status, ['resolved', 'dismissed'], true) && mb_strlen($message) < 10)) { throw new HttpException(400, 'invalid_request'); }
        $row = $this->db->transactional(function () use ($id, $status, $version, $message): array {
            $row = $this->db->fetchAssociative('SELECT * FROM marketplace_report WHERE id = ?', [$id]);
            if (!$row) { throw new HttpException(404, 'profile_unavailable'); }
            if ((int) $row['version'] !== $version || in_array($row['status'], ['resolved', 'dismissed'], true)) { throw new HttpException(409, 'report_changed'); }
            if ($row['status'] === $status && $row['response'] === $message) { return $row; }
            $now = gmdate('Y-m-d H:i:s');
            $updated = $this->db->executeStatement('UPDATE marketplace_report SET status = ?, response = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?', [$status, $message, $now, $id, $version]);
            if ($updated !== 1) { throw new HttpException(409, 'report_changed'); }
            $this->db->insert('report_notice', ['id' => bin2hex(random_bytes(16)), 'recipient_id' => $row['reporter_id'], 'report_id' => $id, 'version' => $version + 1, 'status' => $status, 'message' => $message, 'created_at' => $now]);
            return [...$row, 'status' => $status, 'version' => $version + 1];
        });
        $recipient = $this->em->find(User::class, (int) $row['reporter_id']);
        if ($recipient instanceof User) { $this->publisher->publishEvent($recipient, ['type' => 'report_update']); }
        return $row;
    }
}
