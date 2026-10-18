<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Account\EmailRecipient;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class EmailRecipientTest extends TestCase
{
    public function testNamesUseRecipientProfilesWithCompanyPriorityForAgencies(): void
    {
        $user = new User('do-not-guess-my-name@example.test', 'ROLE_COMPANY');
        self::assertSame('', EmailRecipient::name($user));
        self::assertSame('', EmailRecipient::name(null));
        $user->setCreator(new Creator('creator', "  Amina\n Kovač  ", '', '', '', []));
        self::assertSame('Amina Kovač', EmailRecipient::name($user));
        $user->setCompany(new Company('agency', 'Studio & Co.', 'Marketing'));
        $user->getCompany()->setCompanyType('agency');
        self::assertSame('Studio & Co.', EmailRecipient::name($user));
        $user->anonymize();
        self::assertSame('', EmailRecipient::name($user));
    }

    public function testGreetingsNormalizePunctuationAndHandleUnknownNames(): void
    {
        foreach (['Zdravo', 'Pozdrav!', 'Pozdravljeni!', 'Hello!', 'Hallo,', 'Živjo,'] as $base) {
            $prefix = rtrim($base, '!,');
            self::assertSame($prefix.', Amina Kovač!', EmailRecipient::greeting($base, " Amina\r\nKovač "));
            self::assertSame($prefix.'!', EmailRecipient::greeting($base, ''));
        }
    }
}
