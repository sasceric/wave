<?php

namespace App\Background;

use App\Account\AccountEmailSender;
use App\Entity\User;
use App\Legal\LegalPolicies;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Emails existing accounts once per Terms of Use version. Runs only after
 * `notifyExistingUsers` is switched on in config/legal_documents.json, so a draft
 * version is never announced. New accounts accept the current version at sign-up.
 */
final class TermsNoticeBatch
{
    private const BATCH = 100;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountEmailSender $emails,
        private readonly JobDispatcher $jobs,
    ) {
    }

    /** @param bool|null $enabled overrides the config switch (tests and manual runs) */
    public function run(bool $continue = true, ?bool $enabled = null): int
    {
        if (!($enabled ?? LegalPolicies::notifyExistingUsers())) {
            return 0;
        }
        $db = $this->entityManager->getConnection();
        $version = LegalPolicies::VERSION;
        $ids = $db->fetchFirstColumn(
            "SELECT id FROM wave_user WHERE deleted_at IS NULL AND email_verified = TRUE AND admin = FALSE AND moderator = FALSE AND presentation_demo_key IS NULL AND role IN ('ROLE_CREATOR', 'ROLE_COMPANY') AND (terms_notice_version IS NULL OR terms_notice_version <> ?) ORDER BY id LIMIT ".self::BATCH,
            [$version],
        );
        $sent = 0;
        foreach ($ids as $id) {
            // Claim first so overlapping runs never send the same notice twice.
            $claimed = $db->executeStatement('UPDATE wave_user SET terms_notice_version = ? WHERE id = ? AND (terms_notice_version IS NULL OR terms_notice_version <> ?)', [$version, $id, $version]);
            $user = $claimed === 1 ? $this->entityManager->find(User::class, (int) $id) : null;
            if (!$user instanceof User || $user->isDeleted()) {
                continue;
            }
            $this->emails->sendTermsUpdated($user, LegalPolicies::effectiveDate());
            ++$sent;
        }
        if ($continue && count($ids) === self::BATCH) {
            $this->jobs->enqueue('TermsNoticeTask');
        }

        return $sent;
    }
}
