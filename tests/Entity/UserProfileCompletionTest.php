<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Creator;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserProfileCompletionTest extends TestCase
{
    public function testPhoneIsOptionalButCityAndCountryAreRequired(): void
    {
        $user = new User('creator@example.test', 'ROLE_CREATOR');
        $user->setCreator(new Creator('creator', 'Creator Name', 'Beauty', 'Zenica', '', []));
        $user->setCity('Zenica');
        $user->setCountryCode('BA');
        $user->setPhone(null);
        self::assertTrue($user->hasCompleteProfile());

        $user->setCity('');
        self::assertFalse($user->hasCompleteProfile());
        $user->setCity('Zenica');
        $user->setCountryCode(null);
        self::assertFalse($user->hasCompleteProfile());
    }
}
