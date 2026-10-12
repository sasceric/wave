<?php

declare(strict_types=1);

namespace App\Credits;

use App\Account\AccountEmailSender;
use App\Background\JobDispatcher;
use App\Background\PayloadCipher;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final class CreditService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JobDispatcher $jobs,
        private readonly AccountEmailSender $emails,
        private readonly PayloadCipher $cipher,
    ) {
    }

    private function db(): Connection
    {
        return $this->entityManager->getConnection();
    }

    public function settings(): array
    {
        $db = $this->db();
        $db->executeStatement('INSERT INTO credit_settings (id, paid, unit_price_minor, application_cost, campaign_cost, welcome_grant, activation, version) VALUES (1, false, 20, 10, 30, 0, 0, 1) ON CONFLICT (id) DO NOTHING');
        $row = $db->fetchAssociative('SELECT * FROM credit_settings WHERE id = 1');

        return [
            'paid' => (bool) $row['paid'], 'unitPriceMinor' => (int) $row['unit_price_minor'],
            'applicationCost' => (int) $row['application_cost'], 'campaignCost' => (int) $row['campaign_cost'],
            'welcomeGrant' => (int) $row['welcome_grant'], 'activatedAt' => $row['activated_at'],
            'activation' => (int) $row['activation'], 'version' => (int) $row['version'],
            'packs' => array_map(static fn (int $amount): array => ['amount' => $amount, 'priceMinor' => $amount * (int) $row['unit_price_minor']], [50, 100, 200]),
        ];
    }

    /** Lock mode/prices before wallets, consistently across every mutation. */
    private function lockSettings(): array
    {
        $this->settings();
        $this->db()->executeStatement('UPDATE credit_settings SET id = id WHERE id = 1');

        return $this->settings();
    }

    public function account(User $user, int $page = 1, string $type = 'all'): array
    {
        $settings = $this->settings();
        $db = $this->db();
        $id = $user->getId();
        $filter = match ($type) {
            'added' => ' AND amount > 0',
            'spent' => ' AND amount < 0',
            default => '',
        };
        $total = (int) $db->fetchOne('SELECT COUNT(*) FROM credit_entry WHERE user_id = ?' . $filter, [$id]);
        $page = min(max(1, $page), max(1, (int) ceil($total / 10)));
        $rows = $db->fetchAllAssociative('SELECT amount, balance_after, kind, reference, created_at FROM credit_entry WHERE user_id = ?' . $filter . ' ORDER BY sequence DESC LIMIT 10 OFFSET ' . (($page - 1) * 10), [$id]);

        return [
            'balance' => (int) $db->fetchOne('SELECT balance FROM credit_wallet WHERE user_id = ?', [$id]),
            'unlimited' => !$settings['paid'], 'settings' => $settings,
            'history' => array_map(static fn (array $row): array => [
                'amount' => (int) $row['amount'], 'balanceAfter' => (int) $row['balance_after'],
                'kind' => $row['kind'], 'reference' => $row['reference'], 'createdAt' => str_replace(' ', 'T', $row['created_at']) . 'Z',
            ], $rows), 'page' => $page, 'total' => $total,
        ];
    }

    public function saveSettings(array $data): array
    {
        if (!is_bool($data['paid'] ?? null)) {
            throw new CreditException('credit_invalid_settings');
        }
        foreach (['unitPriceMinor' => [1, 100000], 'applicationCost' => [1, 100000], 'campaignCost' => [1, 100000], 'welcomeGrant' => [0, 100000], 'version' => [1, PHP_INT_MAX]] as $key => [$min, $max]) {
            if (!is_int($data[$key] ?? null) || $data[$key] < $min || $data[$key] > $max) {
                throw new CreditException('credit_invalid_settings');
            }
        }

        return $this->db()->transactional(function () use ($data): array {
            $old = $this->lockSettings();
            if ($old['version'] !== $data['version']) {
                throw new CreditException('credit_settings_changed', 409);
            }
            $activating = !$old['paid'] && $data['paid'];
            $activation = $old['activation'] + (int) $activating;
            $at = gmdate('Y-m-d H:i:s');
            $this->db()->update('credit_settings', [
                'paid' => $data['paid'] ? 1 : 0, 'unit_price_minor' => $data['unitPriceMinor'],
                'application_cost' => $data['applicationCost'], 'campaign_cost' => $data['campaignCost'],
                'welcome_grant' => $data['welcomeGrant'], 'activation' => $activation,
                'activated_at' => $activating ? $at : $old['activatedAt'], 'version' => $old['version'] + 1,
            ], ['id' => 1]);
            if ($activating) {
                foreach ($this->db()->fetchFirstColumn('SELECT id FROM wave_user ORDER BY id') as $userId) {
                    $userId = (int) $userId;
                    $event = 'activation:' . $activation . ':' . $userId;
                    if ($data['welcomeGrant'] > 0) {
                        $this->add($userId, $data['welcomeGrant'], 'welcome', (string) $activation, $event);
                    }
                    $this->db()->insert('credit_announcement', [
                        'id' => $event, 'user_id' => $userId, 'grant_amount' => $data['welcomeGrant'],
                        'application_cost' => $data['applicationCost'], 'campaign_cost' => $data['campaignCost'], 'activated_at' => $at,
                    ]);
                    $this->jobs->enqueue('CreditsAnnouncementMessage', ['announcementId' => $event], $event);
                }
            }

            return $this->settings();
        });
    }

    /** The charge and successful domain write must share one database transaction. */
    public function spend(User $user, string $kind, \Closure $write): mixed
    {
        if (!in_array($kind, ['application', 'campaign'], true)) {
            throw new \InvalidArgumentException('Unknown credit action.');
        }

        return $this->db()->transactional(function () use ($user, $kind, $write): mixed {
            $settings = $this->lockSettings();
            $userId = (int) $user->getId();
            $cost = $settings[$kind === 'application' ? 'applicationCost' : 'campaignCost'];
            if ($settings['paid']) {
                $this->wallet($userId);
                $updated = $this->db()->executeStatement('UPDATE credit_wallet SET balance = balance - ? WHERE user_id = ? AND balance >= ?', [$cost, $userId, $cost]);
                if ($updated !== 1) {
                    throw new CreditException('credit_insufficient', 402);
                }
            }
            // Callback flushes the campaign/application and returns its ID and title.
            $result = $write();
            if ($settings['paid']) {
                $this->entry($userId, -$cost, $kind, $result['title'], $kind . ':' . $result['id']);
            }

            return $result;
        });
    }

    public function issue(User $admin, int $amount): array
    {
        if (!in_array($amount, [50, 100, 200], true)) {
            throw new CreditException('credit_invalid_pack');
        }

        return $this->db()->transactional(function () use ($admin, $amount): array {
            $settings = $this->lockSettings();
            $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $code = '';
            for ($i = 0; $i < 8; ++$i) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $id = bin2hex(random_bytes(16));
            $price = $amount * $settings['unitPriceMinor'];
            $this->db()->insert('credit_voucher', [
                'id' => $id, 'code_hash' => hash('sha256', $code), 'suffix' => substr($code, -4),
                'encrypted_code' => $this->cipher->encrypt(['code' => $code]),
                'amount' => $amount, 'price_minor' => $price, 'issued_by_id' => $admin->getId(), 'created_at' => gmdate('Y-m-d H:i:s'),
            ]);

            return ['id' => $id, 'code' => $code, 'amount' => $amount, 'priceMinor' => $price];
        });
    }

    public function vouchers(int $page, int $limit = 25, string $sort = 'created_at', string $direction = 'desc'): array
    {
        $status = "CASE WHEN v.revoked_at IS NOT NULL THEN 'revoked' WHEN v.redeemed_at IS NOT NULL THEN 'used' WHEN v.assigned_at IS NOT NULL THEN 'assigned' ELSE 'created' END";
        $columns = ['amount' => 'v.amount', 'price_minor' => 'v.price_minor', 'created_at' => 'v.created_at', 'status' => $status,
            'recipient' => "LOWER(COALESCE(c.display_name, p.name, u.email, ''))", 'email' => "LOWER(COALESCE(u.email, ''))"];
        if (!in_array($limit, [25, 50, 100], true) || $page < 1 || $page > 1000000 || !isset($columns[$sort]) || !in_array($direction, ['asc', 'desc'], true)) {
            throw new CreditException('invalid_request');
        }
        $total = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM credit_voucher');
        $page = min($page, max(1, (int) ceil($total / $limit)));
        $order = strtoupper($direction);
        $rows = $this->db()->fetchAllAssociative("SELECT v.id, v.suffix, v.encrypted_code, v.amount, v.price_minor, v.created_at, v.redeemed_at, v.revoked_at, v.assigned_at, v.assigned_to_id,
            u.email, COALESCE(c.display_name, p.name) AS recipient, $status AS status
            FROM credit_voucher v LEFT JOIN wave_user u ON u.id = COALESCE(v.assigned_to_id, v.redeemed_by_id)
            LEFT JOIN creator c ON c.owner_id = u.id LEFT JOIN company p ON p.owner_id = u.id
            ORDER BY {$columns[$sort]} $order, v.id $order LIMIT $limit OFFSET " . (($page - 1) * $limit));
        foreach ($rows as &$row) {
            $row['code'] = $this->fullCode($row['encrypted_code']);
            unset($row['encrypted_code']);
            $row['amount'] = (int) $row['amount'];
            $row['price_minor'] = (int) $row['price_minor'];
            $row['assigned_to_id'] = $row['assigned_to_id'] === null ? null : (int) $row['assigned_to_id'];
        }
        unset($row);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    private function fullCode(?string $encrypted): ?string
    {
        if ($encrypted === null) {
            return null;
        }
        $payload = $this->cipher->decrypt($encrypted);
        return is_string($payload['code'] ?? null) ? $payload['code'] : null;
    }

    public function assign(string $id, int $userId, string $accountType): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || $userId < 1 || !in_array($accountType, ['creator', 'company'], true)) {
            throw new CreditException('invalid_request');
        }
        return $this->db()->transactional(function () use ($id, $userId, $accountType): array {
            $this->lockSettings();
            // Share the account deletion lock so a code cannot be mailed after erasure.
            $this->db()->executeStatement('UPDATE wave_user SET id = id WHERE id = ?', [$userId]);
            $user = $this->entityManager->find(User::class, $userId);
            if (!$user instanceof User) {
                throw new CreditException('credit_recipient_invalid', 404);
            }
            $this->entityManager->refresh($user);
            if ($user->isDeleted() || !$user->hasRole($accountType === 'creator' ? 'ROLE_CREATOR' : 'ROLE_COMPANY')
                || ($accountType === 'creator' ? $user->getCreator() === null : $user->getCompany() === null)) {
                throw new CreditException('credit_recipient_invalid', 404);
            }
            $voucher = $this->db()->fetchAssociative('SELECT * FROM credit_voucher WHERE id = ?', [$id]);
            if (!$voucher || $voucher['redeemed_at'] !== null || $voucher['revoked_at'] !== null) {
                throw new CreditException('credit_invalid_code', 409);
            }
            if ($voucher['assigned_at'] !== null) {
                if ((int) $voucher['assigned_to_id'] === $userId) {
                    return ['id' => $id, 'status' => 'assigned', 'assignedToId' => $userId];
                }
                throw new CreditException('credit_already_assigned', 409);
            }
            $code = $this->fullCode($voucher['encrypted_code']);
            if ($code === null) {
                throw new CreditException('credit_code_unavailable', 409);
            }
            $this->db()->update('credit_voucher', ['assigned_to_id' => $userId, 'assigned_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
            $this->emails->sendCreditCodeAssigned($user, $code, (int) $voucher['amount']);
            // Reservation only: wallets and credit entries change on redemption.
            return ['id' => $id, 'status' => 'assigned', 'assignedToId' => $userId];
        });
    }

    /** @param list<string> $ids */
    public function deleteVouchers(array $ids): int
    {
        if ($ids === [] || count($ids) > 100 || count(array_unique($ids)) !== count($ids)) {
            throw new CreditException('invalid_request');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
                throw new CreditException('invalid_request');
            }
        }
        return $this->db()->transactional(function () use ($ids): int {
            $this->lockSettings();
            $existing = (int) $this->db()->fetchOne('SELECT COUNT(*) FROM credit_voucher WHERE id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::STRING]);
            if ($existing !== count($ids)) {
                throw new CreditException('credit_invalid_code', 404);
            }
            // Immutable redemption entries and balances survive removal of code history.
            return $this->db()->executeStatement('DELETE FROM credit_voucher WHERE id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::STRING]);
        });
    }

    public function revoke(string $id): void
    {
        $this->db()->transactional(function () use ($id): void {
            $this->lockSettings();
            if ($this->db()->executeStatement('UPDATE credit_voucher SET revoked_at = ? WHERE id = ? AND redeemed_at IS NULL AND revoked_at IS NULL', [gmdate('Y-m-d H:i:s'), $id]) !== 1) {
                throw new CreditException('credit_invalid_code', 409);
            }
        });
    }

    public function redeem(User $user, string $code): int
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9]{8}$/D', $code)) {
            throw new CreditException('credit_invalid_code');
        }

        return $this->db()->transactional(function () use ($user, $code): int {
            $this->lockSettings();
            $voucher = $this->db()->fetchAssociative('SELECT * FROM credit_voucher WHERE code_hash = ?', [hash('sha256', $code)]);
            if (!$voucher || $voucher['redeemed_at'] !== null || $voucher['revoked_at'] !== null) {
                throw new CreditException('credit_invalid_code');
            }
            $id = (int) $user->getId();
            if ($voucher['assigned_at'] !== null && (int) $voucher['assigned_to_id'] !== $id) {
                throw new CreditException('credit_invalid_code');
            }
            $this->db()->update('credit_voucher', ['redeemed_by_id' => $id, 'redeemed_at' => gmdate('Y-m-d H:i:s')], ['id' => $voucher['id']]);
            $this->add($id, (int) $voucher['amount'], 'voucher', '••••' . $voucher['suffix'], 'voucher:' . $voucher['id']);

            return (int) $voucher['amount'];
        });
    }

    public function announce(string $id): void
    {
        $this->db()->transactional(function () use ($id): void {
            // Serialize retries with other credit writes; queued email and outbox commit together.
            $this->lockSettings();
            $row = $this->db()->fetchAssociative('SELECT * FROM credit_announcement WHERE id = ?', [$id]);
            if (!$row || $row['queued_at'] !== null) {
                return;
            }
            $user = $this->entityManager->find(User::class, (int) $row['user_id']);
            if ($user instanceof User) {
                $this->emails->sendCreditsActivated($user, (int) $row['grant_amount'], (int) $row['application_cost'], (int) $row['campaign_cost'], substr($row['activated_at'], 0, 10));
            }
            $this->db()->update('credit_announcement', ['queued_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
        });
    }

    private function wallet(int $id): void
    {
        $this->db()->executeStatement('INSERT INTO credit_wallet (user_id, balance) VALUES (?, 0) ON CONFLICT (user_id) DO NOTHING', [$id]);
    }

    private function add(int $id, int $amount, string $kind, string $reference, string $event): void
    {
        $this->wallet($id);
        $this->db()->executeStatement('UPDATE credit_wallet SET balance = balance + ? WHERE user_id = ?', [$amount, $id]);
        $this->entry($id, $amount, $kind, $reference, $event);
    }

    private function entry(int $id, int $amount, string $kind, string $reference, string $event): void
    {
        $this->db()->insert('credit_entry', [
            'id' => bin2hex(random_bytes(16)), 'user_id' => $id, 'amount' => $amount,
            'sequence' => 1 + (int) $this->db()->fetchOne('SELECT COALESCE(MAX(sequence), 0) FROM credit_entry WHERE user_id = ?', [$id]),
            'balance_after' => (int) $this->db()->fetchOne('SELECT balance FROM credit_wallet WHERE user_id = ?', [$id]),
            'kind' => $kind, 'reference' => mb_substr($reference, 0, 255), 'event_key' => $event, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
