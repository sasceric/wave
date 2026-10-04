<?php

namespace App\Tests\Service;

use App\Service\RichTextSanitizer;
use PHPUnit\Framework\TestCase;

final class RichTextSanitizerTest extends TestCase
{
    public function testItKeepsBasicFormattingAndRemovesUnsafeMarkup(): void
    {
        $sanitizer = new RichTextSanitizer();

        self::assertSame(
            '<p>Hello <strong>Wave</strong><a>link</a></p>',
            $sanitizer->sanitize(
                '<p class="profile">Hello <strong>Wave</strong><script>alert(1)</script>'
                .'<a href="javascript:alert(1)" onclick="alert(1)">link</a></p>',
            ),
        );
    }

    public function testItKeepsSafeLinksAndCountsVisibleCharacters(): void
    {
        $sanitizer = new RichTextSanitizer();
        $html = $sanitizer->sanitize('<p><a href="https://wave.example">Wave</a><br />Creator</p>');

        self::assertSame(
            '<p><a href="https://wave.example">Wave</a><br>Creator</p>',
            $html,
        );
        self::assertSame('Wave Creator', $sanitizer->plainText($html));
    }
}
