<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Account\EmailTemplateRenderer;
use App\Service\SiteOrigin;
use PHPUnit\Framework\TestCase;

final class EmailTemplateRendererTest extends TestCase
{
    public function testSubjectVariablesCannotIntroduceHeaderLineBreaks(): void
    {
        $renderer = new EmailTemplateRenderer(new SiteOrigin('https://wave.ba'));
        self::assertSame('Hello Amina Next', $renderer->renderSubject('Hello {{name}}', ['name' => "Amina\r\nNext"]));
    }

    public function testEveryDefaultLayoutUsesTheActualBrandArtwork(): void
    {
        $renderer = new EmailTemplateRenderer(new SiteOrigin('https://wave.ba'));
        foreach (['verify', 'verification_reminder', 'pre_approval', 'profile_tip', 'reset', 'registered', 'approval', 'contact', 'subscribed', 'campaign_alert', 'application_received', 'creator_inquiry_received', 'creator_inquiry_accepted', 'creator_hired', 'unread_message_reminder', 'credits_activated', 'credit_code_assigned'] as $key) {
            $html = $renderer->getDefaultHtml($key);
            self::assertStringContainsString('src="https://wave.ba/images/email/wave-icon-v1.png"', $html, $key);
            self::assertStringContainsString('src="https://wave.ba/images/email/wave-logo-v1.png"', $html, $key);
            self::assertStringContainsString('alt="Wave"', $html, $key);
            self::assertStringNotContainsString('<!-- wave:brand -->', $html, $key);
            self::assertSame([], $renderer->unknownVariables($key, $html), $key);
            self::assertFalse($renderer->containsUnsafeMarkup($html), $key);
        }
    }

    public function testSavedStockHeadersUpgradeWithoutChangingCustomCopyOrEscaping(): void
    {
        $renderer = new EmailTemplateRenderer(new SiteOrigin('https://wave.ba'));
        $source = <<<'HTML'
<html><body><table role="presentation" cellspacing="0" cellpadding="0" border="0">
  <tr>
    <td align="center" valign="middle" width="34" height="34" style="width:34px;height:34px;border-radius:50%;background-color:#e7967c;color:#173c35;font-family:Georgia,serif;font-size:19px;font-weight:bold;">w</td>
    <td style="padding-left:10px;color:#173c35;font-family:Georgia,'Times New Roman',serif;font-size:23px;font-weight:bold;letter-spacing:-1px;">wave</td>
  </tr>
</table><h1>{{heading}}</h1><p>My custom copy and layout.</p></body></html>
HTML;
        $prepared = $renderer->prepareHtml($source);
        self::assertStringContainsString('wave-icon-v1.png', $prepared);
        self::assertStringContainsString('My custom copy and layout.', $prepared);
        self::assertStringContainsString('{{heading}}', $prepared);
        self::assertSame($prepared, $renderer->prepareHtml($prepared), 'Repeated previews must not duplicate the header.');
        $variables = array_fill_keys($renderer->variables('verify'), 'Example');
        $variables['heading'] = 'Custom <heading>';
        $sent = $renderer->render('verify', $variables, $source);
        self::assertStringContainsString('wave-logo-v1.png', $sent);
        self::assertStringContainsString('<h1>Custom &lt;heading&gt;</h1>', $sent);
        self::assertStringContainsString('My custom copy and layout.', $sent);

        $custom = '<html><body><h1>My custom branding</h1><p>{{heading}}</p></body></html>';
        self::assertSame($custom, $renderer->prepareHtml($custom));
    }

    public function testCircularIconIsBakedIntoTheAssetAndWordmarkHasRetinaResolution(): void
    {
        $root = dirname(__DIR__, 2).'/frontend/public/images/email/';
        $icon = imagecreatefrompng($root.'wave-icon-v1.png');
        self::assertSame(120, imagesx($icon));
        self::assertSame(120, imagesy($icon));
        self::assertSame(127, imagecolorsforindex($icon, imagecolorat($icon, 0, 0))['alpha']);
        self::assertSame(0, imagecolorsforindex($icon, imagecolorat($icon, 60, 5))['alpha']);
        $logo = getimagesize($root.'wave-logo-v1.png');
        self::assertSame([330, 114], array_slice($logo, 0, 2));
    }

    public function testRecipientNameIsAnEditableEscapedVariableWithAnEmptyFallback(): void
    {
        $renderer = new EmailTemplateRenderer(new SiteOrigin('https://wave.ba'));
        $source = '<p>{{recipientName}}</p><p>{{greeting}}</p>';
        self::assertContains('recipientName', $renderer->variables('verify'));
        self::assertSame([], $renderer->unknownVariables('verify', $source));
        $variables = array_fill_keys($renderer->variables('verify'), 'Example');
        $variables['recipientName'] = 'Amina <Studio> & Co.';
        self::assertStringContainsString('<p>Amina &lt;Studio&gt; &amp; Co.</p>', $renderer->render('verify', $variables, $source));
        unset($variables['recipientName']);
        self::assertStringContainsString('<p></p>', $renderer->render('verify', $variables, $source));
    }
}
