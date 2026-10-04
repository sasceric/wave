<?php

namespace App\Account;

use App\Entity\User;
use App\Entity\UserActionToken;
use Closure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class UserActionTokenManager
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function issue(User $user, string $purpose, DateTimeImmutable $expiresAt): string
    {
        $rawToken = bin2hex(random_bytes(32));
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $now = new DateTimeImmutable();
            $nowString = $now->format('Y-m-d H:i:s');
            $connection->executeStatement('DELETE FROM user_action_token WHERE expires_at <= ?', [$nowString]);
            $connection->executeStatement('DELETE FROM user_action_token WHERE user_id = ? AND purpose = ?', [$user->getId(), $purpose]);
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
     * @param Closure(User): void $action
     */
    public function consume(string $rawToken, string $purpose, Closure $action, bool $revokeAll = false): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $rawToken)) {
            return false;
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $hash = hash('sha256', $rawToken);
            $row = $connection->fetchAssociative(
                'SELECT id, user_id FROM user_action_token WHERE token_hash = ? AND purpose = ? AND expires_at > ?',
                [$hash, $purpose, $now],
            );
            if ($row === false) {
                $connection->rollBack();

                return false;
            }

            $deleted = $connection->executeStatement(
                'DELETE FROM user_action_token WHERE id = ? AND expires_at > ?',
                [$row['id'], $now],
            );
            $user = $deleted === 1 ? $this->entityManager->find(User::class, (int) $row['user_id']) : null;
            if (!$user instanceof User) {
                $connection->rollBack();

                return false;
            }

            $action($user);
            if ($revokeAll) {
                $connection->executeStatement('DELETE FROM user_action_token WHERE user_id = ?', [$user->getId()]);
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
