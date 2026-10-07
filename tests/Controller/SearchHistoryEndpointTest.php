<?php

namespace App\Tests\Controller;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SearchHistoryEndpointTest extends WebTestCase
{
    public function testAnonymousSearchRequiresCsrfAndUsesDedicatedLogger(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $handler = new TestHandler();
        static::getContainer()->set('monolog.logger.search', new Logger('search', [$handler]));
        $body = json_encode(['query' => 'Campaign search endpoint test', 'type' => 'campaigns', 'source' => 'submit'], JSON_THROW_ON_ERROR);
        $client->request('POST', '/api/search/history?locale=en', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $handler->getRecords());
        $client->request('GET', '/api/auth/csrf?locale=en');
        self::assertResponseIsSuccessful();
        $csrf = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['csrfToken'];
        $client->request('POST', '/api/search/history?locale=en', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf, 'REMOTE_ADDR' => '192.0.2.101'], content: $body);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $handler->getRecords());
        self::assertSame('search', $handler->getRecords()[0]->channel);
        self::assertSame('campaigns', $handler->getRecords()[0]->context['type']);
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }
}
