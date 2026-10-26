<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Legal\LegalPolicies;
use PHPUnit\Framework\TestCase;

final class LegalPoliciesTest extends TestCase
{
    public function testPublishedCatalogsMatchTheVersionedArchive(): void
    {
        $root = dirname(__DIR__, 2);
        $config = json_decode(file_get_contents($root.'/config/legal_documents.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(LegalPolicies::VERSION, $config['version']);
        $archivePath = $root.'/config/legal/'.LegalPolicies::VERSION.'.json';
        $archive = json_decode(file_get_contents($archivePath), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(hash_file('sha256', $archivePath), LegalPolicies::archiveHash());
        foreach (['bs', 'hr', 'sr', 'cnr', 'sl', 'en', 'de'] as $locale) {
            $catalog = json_decode(file_get_contents($root.'/frontend/src/locales/'.$locale.'.json'), true, flags: JSON_THROW_ON_ERROR);
            foreach (['terms', 'privacy', 'cookies'] as $document) {
                self::assertSame($archive[$locale][$document], $catalog['legal'][$document], $locale.': '.$document);
            }
        }
    }

    public function testConsentMustBeExplicitAndNewsletterWithdrawalPreservesItsReceipt(): void
    {
        foreach ([[], ['termsAccepted' => 'true'], ['termsAccepted' => true, 'termsVersion' => 'old']] as $payload) {
            self::assertFalse(LegalPolicies::accepted($payload));
        }
        self::assertTrue(LegalPolicies::accepted(['termsAccepted' => true, 'termsVersion' => LegalPolicies::VERSION]));
        $user = new User('consent@example.test', 'ROLE_CREATOR');
        self::assertSame([], $user->getLegalRecords(), 'Existing accounts must not gain invented consent.');
        $user->setNewsletterEnabled(false);
        LegalPolicies::registration($user, 'en', 'email_registration');
        self::assertSame(LegalPolicies::archiveHash(), $user->getLegalRecords()['terms']['archiveHash']);
        self::assertArrayNotHasKey('grantedAt', $user->getLegalRecords()['newsletter']);
        $granted = LegalPolicies::emailRecord([], true, 'account_settings', 'en');
        $withdrawn = LegalPolicies::emailRecord($granted, false, 'signed_email_preferences', 'en');
        self::assertSame($granted['grantedAt'], $withdrawn['grantedAt']);
        self::assertArrayHasKey('withdrawnAt', $withdrawn);
        self::assertFalse($withdrawn['enabled']);
        $user->anonymize();
        self::assertSame([], $user->getLegalRecords(), 'Account deletion clears identifiable acceptance records.');
    }
}
