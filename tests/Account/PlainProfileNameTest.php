<?php

namespace App\Tests\Account;

use App\Account\PlainProfileName;
use App\Entity\{Creator, Company};
use PHPUnit\Framework\TestCase;

final class PlainProfileNameTest extends TestCase
{
    public function testDecorativeFontVariantsBecomePlainWhileRealLettersAndAccentsRemain(): void
    {
        self::assertSame('Amila Hamzic', PlainProfileName::normalize('𝐀𝐦𝐢𝐥𝐚 𝐇𝐚𝐦𝐳𝐢𝐜'));
        self::assertSame('Amila Hamzic', PlainProfileName::normalize('𝘈𝘮𝘪𝘭𝘢 𝘏𝘢𝘮𝘻𝘪𝘤'));
        self::assertSame('Čačić Šehić Đurić Željko', PlainProfileName::normalize('Čačić Šehić Đurić Željko'));
        self::assertSame('Émilie 李小龍 Амина', PlainProfileName::normalize("E\u{0301}milie 李小龍 Амина"));
        $creator = new Creator('same-slug', '𝐀𝐦𝐢𝐥𝐚', 'Beauty', 'Sarajevo', '', []);
        self::assertSame('Amila', $creator->getDisplayName());
        // Legacy rows are displayed plainly before the deploy migration runs too.
        (new \ReflectionProperty(Creator::class, 'displayName'))->setValue($creator, '𝐇𝐚𝐦𝐳𝐢𝐜');
        self::assertSame('Hamzic', $creator->getDisplayName()); self::assertSame('same-slug', $creator->getSlug());
        $company = new Company('same-company-slug', '𝐒𝐭𝐮𝐝𝐢𝐨', 'Beauty');
        self::assertSame('Studio', $company->getName());
        (new \ReflectionProperty(Company::class, 'name'))->setValue($company, '𝐖𝐚𝐯𝐞');
        self::assertSame('Wave', $company->getName());
    }
}
