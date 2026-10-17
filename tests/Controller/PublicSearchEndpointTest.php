<?php

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicSearchEndpointTest extends WebTestCase
{
    public function testValidationAndUnavailableEngineNeverLeakConfiguration(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em); $metadata = $em->getMetadataFactory()->getAllMetadata(); $tool->dropSchema($metadata); $tool->createSchema($metadata);
        $client->request('GET', '/api/search?locale=en');
        self::assertResponseIsSuccessful();
        $empty = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $empty['data']); self::assertSame(0, $empty['meta']['total']);
        foreach (['type=unknown', 'mode=unknown', 'page=0', 'page=-1', 'q='.str_repeat('a', 201)] as $invalid) {
            $client->request('GET', '/api/search?locale=en&'.$invalid);
            self::assertResponseStatusCodeSame(400);
        }
        $client->request('GET', '/api/search?q=test&locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame(0, json_decode($client->getResponse()->getContent(), true)['meta']['total']);
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertStringNotContainsString('MEILISEARCH', $client->getResponse()->getContent());
    }
}
