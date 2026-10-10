<?php

namespace App\Account;

use App\Background\PayloadCipher;
use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\Media;
use App\Entity\SupportTicket;
use App\Entity\User;
use App\Service\StoredFileCleanup;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Erase private account data, retaining only anonymous shared-history references. */
final class AccountDeletion
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StoredFileCleanup $files,
        private readonly PayloadCipher $cipher,
    ) {
    }

    /** @param list<User|Creator|Company> $accounts */
    public function delete(array $accounts): void
    {
        $this->entityManager->wrapInTransaction(function () use ($accounts): void {
            foreach ($accounts as $account) {
                $user = $account instanceof User ? $account : $account->getOwner();
                if ($user === null) {
                    // Legacy profiles have no login owner, but still need an anonymous
                    // reference so their public directory eligibility is removed.
                    $user = new User('deleted-'.bin2hex(random_bytes(16)).'@deleted.invalid', 'ROLE_DELETED');
                    if ($account instanceof Creator) {
                        $user->setCreator($account);
                    } else {
                        $user->setCompany($account);
                    }
                    $this->entityManager->persist($user);
                }
                if ($user->getId() !== null) {
                    $this->entityManager->lock($user, LockMode::PESSIMISTIC_WRITE);
                    $this->entityManager->refresh($user);
                }
                if ($user->isDeleted()) {
                    continue;
                }
                if ($user->hasRole('ROLE_ADMIN') || $user->hasRole('ROLE_MODERATOR')) {
                    throw new \InvalidArgumentException('Operator accounts cannot be deleted through profile deletion.');
                }
                $db = $this->entityManager->getConnection();
                if ($user->getId() !== null) {
                    foreach ($this->entityManager->getRepository(Media::class)->findBy(['owner' => $user]) as $media) {
                        $this->files->schedule('media', $media->getStoragePath());
                        $this->entityManager->remove($media);
                    }
                    $tickets = $this->entityManager->getRepository(SupportTicket::class)->createQueryBuilder('ticket')
                        ->where('ticket.owner = :user OR (ticket.owner IS NULL AND ticket.email = :email)')
                        ->setParameter('user', $user)
                        ->setParameter('email', $user->getEmail())
                        ->getQuery()->getResult();
                    foreach ($tickets as $ticket) {
                        $this->files->schedule('support', $ticket->getTrackingToken());
                        $this->entityManager->remove($ticket);
                    }
                    foreach ([
                        'wave_notification' => 'recipient_id',
                        'user_push_subscription' => 'user_id',
                        'user_action_token' => 'user_id',
                        'oauth_identity' => 'user_id',
                        'campaign_bookmark' => 'user_id',
                        'credit_entry' => 'user_id',
                        'credit_wallet' => 'user_id',
                    ] as $table => $column) {
                        $db->delete($table, [$column => $user->getId()]);
                    }
                    $db->update('credit_voucher', ['redeemed_by_id' => null], ['redeemed_by_id' => $user->getId()]);
                    $db->delete('newsletter_subscriber', ['email' => $user->getEmail()]);
                    $this->discardDeliveries($user);
                }
                $creator = $user->getCreator();
                if ($creator !== null) {
                    $db->delete('directory_index', ['id' => 'creator:'.$creator->getId()]);
                    $creator->anonymize();
                }
                $company = $user->getCompany();
                if ($company !== null) {
                    foreach ($this->entityManager->getRepository(Campaign::class)->findBy(['company' => $company]) as $campaign) {
                        if ($campaign->getStatus() !== 'finished') {
                            $campaign->setStatus('closed');
                        }
                        $campaign->setFeatured(false);
                        $campaign->setCoverMedia(null);
                        $db->delete('directory_index', ['id' => 'campaign:'.$campaign->getId()]);
                    }
                    $db->delete('directory_index', ['id' => 'company:'.$company->getId()]);
                    $company->anonymize();
                }
                $user->anonymize();
            }
        });
    }

    private function discardDeliveries(User $user): void
    {
        $db = $this->entityManager->getConnection();
        $rows = $db->iterateAssociative("SELECT id, type, payload FROM background_job WHERE type IN ('SendEmailMessage', 'SendWebPushMessage', 'PublishRealtimeMessage') AND payload <> ''");
        foreach ($rows as $row) {
            $payload = $this->cipher->decrypt($row['payload']);
            $targetsUser = ($payload['userId'] ?? null) === $user->getId()
                || in_array($user->getEmail(), $payload['recipients'] ?? [], true);
            if ($targetsUser) {
                // Transport envelopes contain only the job ID; the private payload is erased.
                $db->update('background_job', ['status' => 'discarded', 'payload' => '', 'finished_at' => gmdate('Y-m-d H:i:s')], ['id' => $row['id']]);
            }
        }
    }

    public static function sharedHistoryDeleted(Creator $creator, Company $company): bool
    {
        return $creator->getOwner()?->isDeleted() === true || $company->getOwner()?->isDeleted() === true;
    }
}
