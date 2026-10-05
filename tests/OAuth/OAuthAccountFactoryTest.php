<?php

namespace App\Tests\OAuth;

use App\Entity\Company;
use App\Entity\Creator;
use App\OAuth\OAuthAccountFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OAuthAccountFactoryTest extends KernelTestCase
{
    public function testCreatesVerifiedIncompleteCreatorForProfileCompletion(): void
    {
        $user = static::getContainer()->get(OAuthAccountFactory::class)->create([
            'email' => 'creator@example.test',
            'givenName' => 'Avery',
            'familyName' => 'Creator',
        ], 'creator', 'en');

        self::assertSame('ROLE_CREATOR', $user->getRoles()[0]);
        self::assertTrue($user->isEmailVerified());
        self::assertFalse($user->isApproved());
        self::assertFalse($user->hasCompleteProfile());
        self::assertInstanceOf(Creator::class, $user->getCreator());
        self::assertNull($user->getCompany());
        self::assertSame('Avery Creator', $user->getCreator()?->getDisplayName());
        self::assertSame([], $user->getCreator()?->getCategories());
        self::assertNotSame('', $user->getPassword());
    }

    public function testCreatesVerifiedIncompleteCompanyForProfileCompletion(): void
    {
        $user = static::getContainer()->get(OAuthAccountFactory::class)->create([
            'email' => 'brand@example.test',
            'givenName' => 'Morgan',
            'familyName' => 'Brand',
        ], 'company', 'en');

        self::assertSame('ROLE_COMPANY', $user->getRoles()[0]);
        self::assertTrue($user->isEmailVerified());
        self::assertFalse($user->isApproved());
        self::assertFalse($user->hasCompleteProfile());
        self::assertInstanceOf(Company::class, $user->getCompany());
        self::assertNull($user->getCreator());
        self::assertSame('Morgan Brand', $user->getCompany()?->getName());
        self::assertSame('', $user->getCompany()?->getIndustry());
    }
}
