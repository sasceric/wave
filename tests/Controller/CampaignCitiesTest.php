<?php

namespace App\Tests\Controller;

use App\Entity\{Company, User};
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CampaignCitiesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    public function testAllCitiesAndSeveralCitiesAreSavedAndFiltered(): void
    {
        $brand = new User('brand@example.test', 'ROLE_COMPANY', true);
        $brand->setPassword('unused'); $brand->setEmailVerified(true); $brand->setHideMyAccount(false);
        $brand->setCity('Sarajevo'); $brand->setCountryCode('BA'); $brand->setAdmin(true);
        $brand->setCompany(new Company('cities-brand', 'Cities Brand', 'Travel'));
        $this->em->persist($brand); $this->em->flush();
        $this->client->loginUser($brand);

        // An empty city means every city in the country.
        $this->write('POST', '/api/company/campaigns', $this->fields('Whole country campaign', ''));
        self::assertResponseStatusCodeSame(201);
        self::assertSame('', $this->payload()['data']['city']);
        self::assertSame([], $this->payload()['data']['cities']);

        // Several cities are trimmed, de-duplicated (ignoring case) and joined.
        $this->write('POST', '/api/company/campaigns', $this->fields('Two cities campaign', ' Sarajevo,  mostar ,sarajevo,, '));
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Sarajevo, Mostar', $this->payload()['data']['city']);
        self::assertSame(['Sarajevo', 'Mostar'], $this->payload()['data']['cities']);

        $this->write('POST', '/api/company/campaigns', $this->fields('Zenica campaign', 'Zenica'));
        self::assertResponseStatusCodeSame(201);

        // A city that is too long, or more than 30 cities, is rejected.
        $this->write('POST', '/api/company/campaigns', $this->fields('Too long city name', str_repeat('a', 81)));
        self::assertResponseStatusCodeSame(400);
        self::assertContains('city', $this->payload()['fields']);
        $this->write('POST', '/api/company/campaigns', $this->fields('Too many cities here', implode(', ', array_map(static fn (int $i): string => 'City '.$i, range(1, 31)))));
        self::assertResponseStatusCodeSame(400);

        // Filtering by a city also returns campaigns open to every city.
        $this->client->request('GET', '/api/campaigns?city=Mostar&locale=en');
        self::assertResponseIsSuccessful();
        $titles = array_column($this->payload()['data'], 'title');
        sort($titles);
        self::assertSame(['Two cities campaign', 'Whole country campaign'], $titles);
    }

    public function testJsonCitiesSurviveSavingEditingReloadingAndSearch(): void
    {
        $brand = new User('json-cities@example.test', 'ROLE_COMPANY', true);
        $brand->setPassword('unused'); $brand->setEmailVerified(true); $brand->setHideMyAccount(false);
        $brand->setCity('Sarajevo'); $brand->setCountryCode('BA'); $brand->setAdmin(true);
        $brand->setCompany(new Company('json-cities-brand', 'JSON Cities Brand', 'Travel'));
        $this->em->persist($brand); $this->em->flush();
        $this->client->loginUser($brand);
        $fields = $this->fields('JSON cities campaign', '');
        unset($fields['city']);
        $fields['cities'] = [' čačak ', 'mostar', 'Čačak', 'Washington, D.C.'];
        $this->write('POST', '/api/company/campaigns', $fields);
        self::assertResponseStatusCodeSame(201);
        $campaignId = $this->payload()['data']['id'];
        $expected = ['Čačak', 'Mostar', 'Washington, D.C.'];
        self::assertSame($expected, $this->payload()['data']['cities']);
        $db = $this->em->getConnection();
        self::assertSame($expected, json_decode($db->fetchOne('SELECT city FROM campaign WHERE id = ?', [$campaignId]), true, flags: JSON_THROW_ON_ERROR));
        $this->em->clear();
        $campaign = $this->em->find(\App\Entity\Campaign::class, $campaignId);
        self::assertSame($expected, $campaign->getCities());
        self::assertSame('Čačak, Mostar, Washington, D.C.', $campaign->getCity());
        foreach (['city', 'q'] as $filter) {
            $this->client->request('GET', '/api/campaigns?locale=en&'.$filter.'='.rawurlencode('čačak'));
            self::assertResponseIsSuccessful();
            self::assertSame([$campaignId], array_column($this->payload()['data'], 'id'));
        }
        $search = self::getContainer()->get(\App\Search\DatabaseSearch::class)->search('čačak', 'en', 'campaigns', 1, false);
        self::assertSame(1, $search['meta']['total']);
        self::getContainer()->get(\App\Background\DirectoryIndexer::class)->index('campaign', [$campaignId]);
        self::assertStringContainsString('čačak', $db->fetchOne('SELECT search_text FROM directory_index WHERE kind = ? AND entity_id = ?', ['campaign', $campaignId]));
        $this->client->request('GET', '/api/campaigns?locale=en&q='.rawurlencode('čačak'));
        self::assertResponseIsSuccessful();
        self::assertSame([$campaignId], array_column($this->payload()['data'], 'id'));

        foreach ([null, ['bad' => 'shape'], [123], [str_repeat('a', 81)], array_fill(0, 31, 'Sarajevo')] as $invalid) {
            $this->write('PUT', '/api/company/campaigns/'.$campaignId, array_replace($fields, ['cities' => $invalid]));
            self::assertResponseStatusCodeSame(400);
            self::assertContains('cities', $this->payload()['fields']);
        }
        $this->write('PUT', '/api/company/campaigns/'.$campaignId, array_replace($fields, ['cities' => []]));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['data']['cities']);
        self::assertSame('', $this->payload()['data']['city']);
        self::assertSame([], json_decode($db->fetchOne('SELECT city FROM campaign WHERE id = ?', [$campaignId]), true, flags: JSON_THROW_ON_ERROR));
    }

    private function fields(string $title, string $city): array
    {
        return ['title' => $title, 'summary' => 'A campaign summary for the city test.', 'description' => '<p>A campaign description that is long enough.</p>', 'categories' => ['Travel'], 'channels' => ['Instagram'], 'deliverables' => ['One video'], 'budgetMin' => 100, 'budgetMax' => 200, 'compensationType' => 'paid', 'currency' => 'BAM', 'city' => $city, 'countryCode' => 'BA', 'creatorCount' => 2, 'closesAt' => (new DateTimeImmutable('+20 days'))->format('Y-m-d'), 'status' => 'open'];
    }

    private function write(string $method, string $url, array $body): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $this->client->jsonRequest($method, $url, $body, ['HTTP_X_CSRF_TOKEN' => $this->payload()['csrfToken']]);
    }

    private function payload(): array { return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR); }
}
