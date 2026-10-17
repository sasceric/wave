<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Account\AccountEmailSender;
use App\Account\CampaignAlertEmailCopy;
use App\Entity\MarketplaceCategory;
use App\Localization\ApiMessages;
use App\Localization\GermanTranslations;
use App\Localization\LocaleContext;
use App\Localization\LocalizedRouteMap;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use DoctrineMigrations\Version20261016160000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class GermanLocaleTest extends WebTestCase
{
    public function testGermanRoutesSeoApiEmailsAndCatalogMigration(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $routes = static::getContainer()->get(LocalizedRouteMap::class);
        self::assertSame('de', LocaleContext::fromRequest(Request::create('/api/homepage?locale=de')));
        self::assertSame('/de/konto/uebersicht', $routes->localizedPath('account-dashboard', 'de'));
        self::assertSame('/kreatori/amina', $routes->localizedPath('creator-profile', 'de', ['slug' => 'amina']));
        self::assertSame('de', $routes->resolve('/de/kampagnen')['locale']);
        foreach (['home', 'creators', 'companies', 'campaigns', 'seo-guides', 'creator-guides', 'support'] as $name) {
            $crawler = $client->request('GET', $routes->localizedPath($name, 'de'));
            self::assertResponseIsSuccessful();
            self::assertSame('de', $crawler->filter('html')->attr('lang'));
            self::assertSame('de_DE', $crawler->filter('meta[property="og:locale"]')->attr('content'));
            $graph = json_decode($crawler->filter('#wave-schema')->text(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('https://schema.org', $graph['@context']);
            self::assertSame('de', $graph['@graph'][2]['inLanguage']);
            self::assertCount(8, $crawler->filter('link[hreflang]'));
        }
        $client->request('GET', '/api/homepage?locale=de');
        self::assertResponseIsSuccessful();
        self::assertSame('Kampagne nicht gefunden.', ApiMessages::get('campaign_not_found', 'de'));
        self::assertSame('Neue Kampagnen für dich', CampaignAlertEmailCopy::forLocale('de')['subject']);
        $emails = static::getContainer()->get(AccountEmailSender::class);
        foreach (['verify', 'reset', 'registered', 'approval', 'application_received', 'creator_inquiry_received', 'creator_inquiry_accepted', 'creator_hired', 'unread_message_reminder', 'credits_activated', 'credit_code_assigned'] as $template) {
            self::assertSame('de', $emails->previewVariables($template, 'de')['locale']);
            self::assertNotSame($emails->defaultSubject($template, 'en'), $emails->defaultSubject($template, 'de'));
        }
        foreach (GermanTranslations::section('api') as $message) self::assertNotEmpty($message);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $standard = new MarketplaceCategory('Technology', ['bs' => 'Tehnologija', 'en' => 'Technology'], 0);
        $custom = new MarketplaceCategory('Photography', ['bs' => 'Fotografija', 'de' => 'Eigene Bezeichnung'], 1);
        $em->persist($standard); $em->persist($custom); $em->flush();
        require_once dirname(__DIR__, 2).'/migrations/Version20261016160000.php';
        $db = $em->getConnection();
        $migration = new Version20261016160000($db, new NullLogger());
        $migration->up($db->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) $db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        $em->refresh($standard); $em->refresh($custom);
        self::assertSame('Technologie und Elektronik', $standard->label('de'));
        self::assertSame('Technology', $standard->label('en'));
        self::assertSame('Eigene Bezeichnung', $custom->label('de'));
    }
}
