<?php

namespace App\Tests\Controller;

use App\Entity\{Campaign, Company, User};
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CampaignProductCompensationTest extends WebTestCase
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

    public function testCampaignCanBePaidInProductsWithAProductValueRange(): void
    {
        $brand = new User('products@example.test', 'ROLE_COMPANY'); $brand->setApproved(true);
        $brand->setPassword('unused'); $brand->setEmailVerified(true); $brand->setHideMyAccount(false);
        $brand->setCity('Sarajevo'); $brand->setCountryCode('BA'); $brand->setAdmin(true);
        $brand->setCompany(new Company('products-brand', 'Products Brand', 'Home'));
        $this->em->persist($brand); $this->em->flush();
        $this->client->loginUser($brand);

        $this->write('POST', '/api/company/campaigns', $this->fields(['compensationType' => 'products', 'budgetMin' => 600, 'budgetMax' => 600]));
        self::assertResponseStatusCodeSame(201);
        $data = $this->payload()['data'];
        self::assertSame('products', $data['compensationType']);
        self::assertSame(600, $data['budgetMin']);
        self::assertTrue($this->em->find(Campaign::class, $data['id'])?->isPaidInProducts());

        // Like a paid campaign, the product value is required.
        $this->write('POST', '/api/company/campaigns', $this->fields(['compensationType' => 'products', 'budgetMin' => null, 'budgetMax' => null]));
        self::assertResponseStatusCodeSame(400);

        $this->write('POST', '/api/company/campaigns', $this->fields(['compensationType' => 'gift-card']));
        self::assertResponseStatusCodeSame(400);
        self::assertContains('compensationType', $this->payload()['fields']);
        // Creators can filter by compensation; a money budget never matches product campaigns.
        $this->write('POST', '/api/company/campaigns', $this->fields(['title' => 'Paid campaign', 'budgetMin' => 500, 'budgetMax' => 700]));
        self::assertResponseStatusCodeSame(201);
        $this->client->request('GET', '/api/campaigns?locale=en&compensation=products');
        self::assertResponseIsSuccessful();
        self::assertSame(['Products campaign'], array_column($this->payload()['data'], 'title'));
        $this->client->request('GET', '/api/campaigns?locale=en&currency=BAM&budgetMin=550&budgetMax=650');
        self::assertResponseIsSuccessful();
        self::assertSame(['Paid campaign'], array_column($this->payload()['data'], 'title'));
        $this->client->request('GET', '/api/campaigns?locale=en&compensation=gift-card');
        self::assertResponseStatusCodeSame(400);
    }

    private function fields(array $overrides): array
    {
        return array_replace(['title' => 'Products campaign', 'summary' => 'A campaign summary for the products test.', 'description' => '<p>We offer mirrors and chairs worth 600 KM.</p>', 'categories' => ['Home'], 'channels' => ['Instagram'], 'deliverables' => ['One UGC video'], 'budgetMin' => 100, 'budgetMax' => 200, 'compensationType' => 'paid', 'currency' => 'BAM', 'city' => '', 'countryCode' => 'BA', 'creatorCount' => 3, 'closesAt' => (new DateTimeImmutable('+20 days'))->format('Y-m-d'), 'status' => 'open'], $overrides);
    }

    private function write(string $method, string $url, array $body): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $this->client->jsonRequest($method, $url, $body, ['HTTP_X_CSRF_TOKEN' => $this->payload()['csrfToken']]);
    }

    private function payload(): array { return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR); }
}
