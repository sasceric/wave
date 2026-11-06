<?php

declare(strict_types=1);

namespace App\Referral;

use App\Account\AccountEmailSender;
use App\Entity\Campaign;
use App\Entity\CreatorReferral;
use App\Entity\Notification;
use App\Entity\ReferralReward;
use App\Entity\User;
use App\Service\NotificationDelivery;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Pozovi kreatore: creators share wave.ba/?ref=CODE. A referred creator counts once their email is
 * confirmed and an administrator approved the profile. 20, 35 and 50 counted creators reach the bronze,
 * silver and gold ambassador tiers; an administrator approves each tier's rewards (badge, featured
 * placement). Suspicious referrals are flagged and count only after an administrator accepts them.
 */
final class ReferralProgram
{
    public const TIERS = [20, 35, 50];
    public const TIER_KEYS = [20 => 'bronze', 35 => 'silver', 50 => 'gold'];
    /** How long an approved tier keeps the creator featured (homepage and first in the directory). */
    public const FEATURED = [20 => '+14 days', 35 => '+1 month', 50 => '+3 months'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountEmailSender $emails,
        private readonly NotificationDelivery $notifications,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%env(APP_BASE_URL)%')] private readonly string $appBaseUrl,
    ) {}

    public static function normalizeCode(mixed $code): ?string
    {
        if (!is_string($code)) return null;
        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z0-9]{3,24}$/D', $code) === 1 ? $code : null;
    }

    public function networkHash(?string $address): ?string
    {
        return $address === null || $address === '' ? null : hash_hmac('sha256', $address, $this->secret.'|referral');
    }

    /** The creator's code, created from their name the first time (AMAR, AMAR27…). */
    public function codeFor(User $user): string
    {
        if ($user->getReferralCode() !== null) return $user->getReferralCode();
        $name = $user->getCreator()?->getDisplayName() ?? strstr($user->getEmail(), '@', true) ?: 'wave';
        $ascii = strtr(mb_strtolower($name), ['č' => 'c', 'ć' => 'c', 'š' => 's', 'ž' => 'z', 'đ' => 'dj', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss']);
        $words = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $base = strtoupper(substr($words[0] ?? '', 0, 10));
        if (strlen($base) < 3) $base = strtoupper(substr(implode('', $words), 0, 10));
        if (strlen($base) < 3) $base = 'WAVE';
        $repository = $this->em->getRepository(User::class);
        $candidate = $base;
        for ($attempt = 0; $repository->findOneBy(['referralCode' => $candidate]) instanceof User; ++$attempt) {
            $candidate = $base.random_int($attempt < 20 ? 10 : 1000, $attempt < 20 ? 99 : 99999);
        }
        $user->setReferralCode($candidate);
        $this->em->flush();

        return $candidate;
    }

    public function link(string $code): string
    {
        return rtrim($this->appBaseUrl, '/').'/?ref='.rawurlencode($code);
    }

    /** The creator behind a code, if they can still refer people. */
    public function referrer(mixed $code): ?User
    {
        $code = self::normalizeCode($code);
        if ($code === null) return null;
        $user = $this->em->getRepository(User::class)->findOneBy(['referralCode' => $code]);

        return $user instanceof User && !$user->isDeleted() && $user->hasRole('ROLE_CREATOR') && $user->getCreator() !== null ? $user : null;
    }

    /** Records who invited a newly registered creator. The caller flushes with the new account. */
    public function attach(User $referred, mixed $code, ?string $address): ?CreatorReferral
    {
        if (!$referred->hasRole('ROLE_CREATOR')) return null;
        $referrer = $this->referrer($code);
        if (!$referrer instanceof User || $referrer === $referred || $referrer->getEmail() === $referred->getEmail()) return null;
        if ($referred->getId() !== null && $this->em->getRepository(CreatorReferral::class)->findOneBy(['referred' => $referred]) instanceof CreatorReferral) return null;
        $hash = $this->networkHash($address);
        $flag = null;
        if ($hash !== null && $hash === $referrer->getReferralNetworkHash()) {
            $flag = 'same_network';
        } elseif ($hash !== null && $this->recentFromNetwork($referrer, $hash) >= 2) {
            $flag = 'many_from_network';
        } elseif (self::similarEmails($referrer->getEmail(), $referred->getEmail())) {
            $flag = 'similar_email';
        }
        $referral = new CreatorReferral($referrer, $referred, (string) $referrer->getReferralCode(), $hash, $flag);
        $this->em->persist($referral);

        return $referral;
    }

    /** Same mailbox name once dots, digits and +tags are ignored (amar.h@…, amarh2@…). */
    public static function similarEmails(string $first, string $second): bool
    {
        $local = static function (string $email): string {
            $name = strtolower((string) strstr($email, '@', true));
            $name = explode('+', $name)[0];

            return (string) preg_replace('/[^a-z]/', '', $name);
        };
        $a = $local($first);

        return strlen($a) >= 4 && $a === $local($second);
    }

    private function recentFromNetwork(User $referrer, string $hash): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(r.id) FROM '.CreatorReferral::class.' r WHERE r.referrer = :referrer AND r.networkHash = :hash AND r.createdAt > :since')
            ->setParameter('referrer', $referrer)->setParameter('hash', $hash)->setParameter('since', new \DateTimeImmutable('-24 hours'))
            ->getSingleScalarResult();
    }

    /** counted | review | profile | email | rejected */
    public static function status(CreatorReferral $referral): string
    {
        $user = $referral->getReferred();
        if ($referral->getDecision() === 'rejected' || $user->isDeleted() || !$user->hasRole('ROLE_CREATOR') || $user->getCreator() === null) return 'rejected';
        if (!$user->isEmailVerified()) return 'email';
        if (!$user->isApproved()) return $user->hasCompleteProfile() ? 'review' : 'profile';

        return $referral->needsReview() ? 'review' : 'counted';
    }

    /** @return list<CreatorReferral> */
    public function referrals(User $referrer): array
    {
        return $this->em->getRepository(CreatorReferral::class)->findBy(['referrer' => $referrer], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function countedFor(User $referrer): int
    {
        return count(array_filter($this->referrals($referrer), static fn (CreatorReferral $r): bool => self::status($r) === 'counted'));
    }

    /** @return list<ReferralReward> */
    public function rewards(User $user): array
    {
        return $this->em->getRepository(ReferralReward::class)->findBy(['user' => $user], ['tier' => 'ASC']);
    }

    /** After an approval or an administrator's decision: record newly reached tiers, email the creator, tell admins. */
    public function sync(User $referrer): void
    {
        if ($referrer->isDeleted() || !$referrer->hasRole('ROLE_CREATOR') || $referrer->getCreator() === null) return;
        $counted = $this->countedFor($referrer);
        $existing = array_map(static fn (ReferralReward $reward): int => $reward->getTier(), $this->rewards($referrer));
        $reached = [];
        foreach (self::TIERS as $tier) {
            if ($counted >= $tier && !in_array($tier, $existing, true)) {
                $this->em->persist(new ReferralReward($referrer, $tier));
                $reached[] = $tier;
            }
        }
        if ($reached === []) return;
        $admins = $this->em->getRepository(User::class)->findBy(['admin' => true, 'deletedAt' => null]);
        $notices = [];
        foreach ($admins as $admin) {
            $notice = new Notification($admin, 'referral_tier_reached', $referrer);
            $this->em->persist($notice);
            $notices[] = $notice;
        }
        $this->em->flush();
        try {
            $this->emails->sendReferralTier($referrer, max($reached));
        } catch (TransportExceptionInterface $exception) {
            $this->logger->warning('Unable to send the referral tier email.', ['userId' => $referrer->getId(), 'exception' => $exception]);
        }
        foreach ($notices as $notice) $this->deliver($notice);
    }

    /** A referred creator was approved: their referrer may have reached a tier. */
    public function afterApproval(User $referred): void
    {
        $referral = $this->em->getRepository(CreatorReferral::class)->findOneBy(['referred' => $referred]);
        if ($referral instanceof CreatorReferral && !$referral->getReferrer()->isDeleted()) $this->sync($referral->getReferrer());
    }

    public function decide(CreatorReferral $referral, bool $accept): void
    {
        $this->em->wrapInTransaction(function () use ($referral, $accept): void {
            $this->em->lock($referral->getReferrer(), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($referral);
            $referral->decide($accept);
        });
        if ($accept) $this->sync($referral->getReferrer());
    }

    /** Activates a reached tier: badge on cards and profile, featured placement for the tier's period. */
    public function approve(ReferralReward $reward): bool
    {
        $notice = $this->em->wrapInTransaction(function () use ($reward): Notification|false|null {
            $user = $reward->getUser();
            // Share the inviter lock with referral decisions and serialize tier activations.
            $this->em->lock($user, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($user);
            $this->em->lock($reward, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($reward);
            if ($reward->isApproved()) return null;
            $creator = $user->getCreator();
            if ($user->isDeleted() || !$user->hasRole('ROLE_CREATOR') || !$user->isApproved()
                || !$user->isEmailVerified() || $creator === null || $this->countedFor($user) < $reward->getTier()) return false;
            $this->em->lock($creator, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($creator);
            $reward->approve();
            $creator->setAmbassadorTier(max($creator->getAmbassadorTier(), $reward->getTier()));
            // Permanently featured creators stay so; otherwise extend from today or the current end date.
            if (!($creator->isFeaturedConfigured() && $creator->getFeaturedUntil() === null)) {
                $now = new \DateTimeImmutable();
                $from = $creator->isFeatured() && $creator->getFeaturedUntil() > $now ? $creator->getFeaturedUntil() : $now;
                $creator->setFeatured(true);
                $creator->setFeaturedUntil($from->modify(self::FEATURED[$reward->getTier()]));
            }
            $notice = new Notification($user, 'referral_reward_active');
            $this->em->persist($notice);

            return $notice;
        });
        if ($notice === false) return false;
        if ($notice instanceof Notification) $this->deliver($notice);

        return true;
    }

    /** Ambassadors hear about a new campaign the moment it is published. */
    public function notifyEarlyAccess(Campaign $campaign, User $actor): void
    {
        $owner = $campaign->getCompany()->getOwner();
        if (!$campaign->isListed() || $campaign->getStatus() !== 'open' || $campaign->getClosesAt() < new \DateTimeImmutable('today')
            || ($owner !== null && ($owner->isDeleted() || !$owner->isApproved() || $owner->isHideMyAccount()))) return;
        $users = $this->em->createQuery('SELECT u FROM '.User::class.' u JOIN u.creator creator WHERE u.id IN (SELECT IDENTITY(r.user) FROM '.ReferralReward::class.' r WHERE r.approvedAt IS NOT NULL) AND u.deletedAt IS NULL AND u.role = :role AND u.approved = true AND u.emailVerified = true AND creator.agencyProfile = false')
            ->setParameter('role', 'ROLE_CREATOR')->getResult();
        $notices = [];
        foreach ($users as $user) {
            if (!$user instanceof User || $user === $actor) continue;
            $notice = new Notification($user, 'campaign_early_access', $actor, $campaign);
            $this->em->persist($notice);
            $notices[] = $notice;
        }
        if ($notices === []) return;
        $this->em->flush();
        foreach ($notices as $notice) $this->deliver($notice);
    }

    private function deliver(Notification $notice): void
    {
        try {
            $this->notifications->deliver($notice);
        } catch (\Throwable $exception) {
            $this->logger->warning('Unable to deliver a referral notification.', ['exception' => $exception]);
        }
    }

    /** The creator's Pozovi kreatore page. */
    public function resource(User $user): array
    {
        $code = $this->codeFor($user);
        $referrals = $this->referrals($user);
        $statuses = array_map(static fn (CreatorReferral $r): string => self::status($r), $referrals);
        $counts = array_count_values($statuses);
        $rewards = [];
        foreach ($this->rewards($user) as $reward) {
            $rewards[$reward->getTier()] = ['reachedAt' => $reward->getReachedAt()->format(DATE_ATOM), 'approvedAt' => $reward->getApprovedAt()?->format(DATE_ATOM)];
        }

        return [
            'code' => $code,
            'link' => $this->link($code),
            'counted' => $counts['counted'] ?? 0,
            'pending' => ($counts['review'] ?? 0) + ($counts['profile'] ?? 0) + ($counts['email'] ?? 0),
            'total' => count($referrals),
            'tiers' => array_map(static fn (int $tier): array => ['at' => $tier, 'key' => self::TIER_KEYS[$tier]] + ($rewards[$tier] ?? ['reachedAt' => null, 'approvedAt' => null]), self::TIERS),
            'referrals' => array_map(static fn (CreatorReferral $r, string $status): array => [
                'id' => $r->getId(),
                'name' => $r->getReferred()->isDeleted() ? '' : ($r->getReferred()->getCreator()?->getDisplayName() ?? ''),
                'createdAt' => $r->getCreatedAt()->format(DATE_ATOM),
                'status' => $status,
            ], array_slice($referrals, 0, 100), array_slice($statuses, 0, 100)),
        ];
    }

    /** Admin → Preporuke: every referrer with counts and tiers, and flagged referrals to decide. */
    public function adminOverview(): array
    {
        $referrers = $this->em->createQuery('SELECT u FROM '.User::class.' u WHERE u.id IN (SELECT IDENTITY(r.referrer) FROM '.CreatorReferral::class.' r)')->getResult();
        $rows = [];
        foreach ($referrers as $user) {
            if (!$user instanceof User) continue;
            $statuses = array_map(static fn (CreatorReferral $r): string => self::status($r), $this->referrals($user));
            $counts = array_count_values($statuses);
            $rows[] = [
                'userId' => $user->getId(),
                'name' => $user->getCreator()?->getDisplayName() ?? $user->getEmail(),
                'email' => $user->getEmail(),
                'slug' => $user->getCreator()?->getSlug(),
                'code' => $user->getReferralCode(),
                'counted' => $counts['counted'] ?? 0,
                'pending' => ($counts['review'] ?? 0) + ($counts['profile'] ?? 0) + ($counts['email'] ?? 0),
                'rejected' => $counts['rejected'] ?? 0,
                'rewards' => array_map(static fn (ReferralReward $reward): array => [
                    'id' => $reward->getId(), 'tier' => $reward->getTier(), 'key' => self::TIER_KEYS[$reward->getTier()],
                    'reachedAt' => $reward->getReachedAt()->format(DATE_ATOM), 'approvedAt' => $reward->getApprovedAt()?->format(DATE_ATOM),
                ], $this->rewards($user)),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['counted'], $b['pending']] <=> [$a['counted'], $a['pending']]);
        $flagged = $this->em->createQuery('SELECT r FROM '.CreatorReferral::class.' r WHERE r.flag IS NOT NULL AND r.decision IS NULL ORDER BY r.createdAt DESC')->setMaxResults(100)->getResult();

        return [
            'ambassadors' => $rows,
            'review' => array_map(static fn (CreatorReferral $r): array => [
                'id' => $r->getId(),
                'name' => $r->getReferred()->getCreator()?->getDisplayName() ?? '',
                'email' => $r->getReferred()->getEmail(),
                'referrer' => $r->getReferrer()->getCreator()?->getDisplayName() ?? $r->getReferrer()->getEmail(),
                'flag' => $r->getFlag(),
                'createdAt' => $r->getCreatedAt()->format(DATE_ATOM),
            ], $flagged),
        ];
    }
}
