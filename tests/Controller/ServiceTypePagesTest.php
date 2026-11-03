<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ServiceTypePagesTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testEachServiceTypeHasItsOwnIndexablePage(): void
    {
        $crawler = $this->client->request('GET', '/usluge/ugc-video');
        self::assertResponseIsSuccessful();
        self::assertSame('UGC video kreatori u BiH i regiji | Wave', $crawler->filter('title')->text());
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringEndsWith('/usluge/ugc-video', (string) $crawler->filter('link[rel="canonical"]')->attr('href'));

        $crawler = $this->client->request('GET', '/en/services/fotografije');
        self::assertResponseIsSuccessful();
        self::assertSame('Product and brand photography | Wave', $crawler->filter('title')->text());
    }

    public function testFilterQueriesKeepTheTypePageAsCanonical(): void
    {
        $crawler = $this->client->request('GET', '/usluge/tiktok?price=to100&sort=price');
        self::assertResponseIsSuccessful();
        self::assertStringEndsWith('/usluge/tiktok', (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testUnknownServiceTypeIsNotFound(): void
    {
        $crawler = $this->client->request('GET', '/usluge/billboards');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
    }
}
