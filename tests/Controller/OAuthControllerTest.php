<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OAuthControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testProvidersAreReportedUnavailableUntilCredentialsAreConfigured(): void
    {
        $this->client->request('GET', '/api/auth/oauth/providers?locale=en');

        self::assertResponseIsSuccessful();
        self::assertSame(['google' => false, 'apple' => false], $this->payload()['data']);
    }

    public function testUnconfiguredProviderReturnsToAccountPageWithLocalizedError(): void
    {
        $this->client->request('GET', '/api/auth/oauth/google/start?locale=sl&mode=register&accountType=company');

        self::assertResponseRedirects('/sl/racun?mode=register&oauth=error');
    }

    public function testCallbackWithoutSessionStateIsRejected(): void
    {
        $this->client->request('GET', '/api/auth/oauth/apple/callback?state=forged&code=unused');

        self::assertResponseRedirects('/racun?mode=login&oauth=error');
    }

    public function testAppleFormPostCallbackWithoutSessionStateIsRejected(): void
    {
        $this->client->request('POST', '/api/auth/oauth/apple/callback', [
            'state' => 'forged',
            'code' => 'unused',
        ]);

        self::assertResponseRedirects('/racun?mode=login&oauth=error');
    }

    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 32, JSON_THROW_ON_ERROR);
    }
}
