<?php

namespace App\Account;

use App\Entity\User;
use App\Entity\UserActionToken;
use Doctrine\ORM\EntityManagerInterface;

final class UserActionTokenManager
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function issue(User $user, string $purpose, ?\DateTimeImmutable $expiresAt = null): string
    {
        $expiresAt = $purpose === 'verify_email' ? null : $expiresAt;
        if ($expiresAt === null && $purpose !== 'verify_email') {
            throw new \InvalidArgumentException('An expiry is required for this token purpose.');
        }
        $rawToken = bin2hex(random_bytes(32));
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            // Resending verification must not invalidate an earlier email still in the inbox.
            // Password reset tokens continue to be replaced on every request.
            if ($purpose !== 'verify_email') {
                $connection->executeStatement('DELETE FROM user_action_token WHERE user_id = ? AND purpose = ?', [$user->getId(), $purpose]);
            }
            $this->entityManager->persist(new UserActionToken($user, $purpose, hash('sha256', $rawToken), $expiresAt));
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        return $rawToken;
    }

    /**
     * @param \Closure(User): void $action
     */
    public function consume(string $rawToken, string $purpose, \Closure $action, bool $revokeAll = false): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $rawToken)) {
            return false;
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $hash = hash('sha256', $rawToken);
            $row = $connection->fetchAssociative(
                "SELECT id, user_id FROM user_action_token WHERE token_hash = ? AND purpose = ? AND (expires_at > ? OR (purpose = 'verify_email' AND expires_at IS NULL))",
                [$hash, $purpose, $now],
            );
            if ($row === false) {
                $connection->rollBack();

                return false;
            }

            $deleted = $connection->executeStatement(
                "DELETE FROM user_action_token WHERE id = ? AND (expires_at > ? OR (purpose = 'verify_email' AND expires_at IS NULL))",
                [$row['id'], $now],
            );
            $user = $deleted === 1 ? $this->entityManager->find(User::class, (int) $row['user_id']) : null;
            if (!$user instanceof User || $user->isDeleted() || ($purpose === 'verify_email' && $user->isEmailVerified())) {
                $connection->rollBack();

                return false;
            }

            $action($user);
            if ($revokeAll) {
                $connection->executeStatement('DELETE FROM user_action_token WHERE user_id = ?', [$user->getId()]);
            } elseif ($purpose === 'verify_email') {
                $connection->executeStatement('DELETE FROM user_action_token WHERE user_id = ? AND purpose = ?', [$user->getId(), $purpose]);
            }
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        return true;
    }
}
