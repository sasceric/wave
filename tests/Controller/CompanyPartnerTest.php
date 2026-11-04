<?php

namespace App\Tests\Controller;

use App\Entity\{Company, User};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CompanyPartnerTest extends WebTestCase
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

    public function testOnlyAdministratorsMarkACompanyAsPartner(): void
    {
        $brand = $this->user('giga@example.test', 'ROLE_COMPANY');
        $brand->setCompany($company = new Company('giga', 'GIGA', 'Home'));
        $admin = $this->user('admin@example.test', 'ROLE_COMPANY');
        $admin->setAdmin(true);
        $admin->setCompany(new Company('wave', 'Wave', 'Marketing'));
        $this->em->flush();
        self::assertFalse($company->isPartner());

        // A company cannot mark itself.
        $this->client->loginUser($brand);
        $this->write('/api/admin/catalog/companies/'.$company->getId().'/partner', ['partner' => true]);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($admin);
        $this->write('/api/admin/catalog/companies/'.$company->getId().'/partner', ['partner' => 'yes']);
        self::assertResponseStatusCodeSame(400);
        $this->write('/api/admin/catalog/companies/'.$company->getId().'/partner', ['partner' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload()['data']['partner']);
        $this->em->clear();
        self::assertTrue($this->em->find(Company::class, $company->getId())?->isPartner());

        $this->write('/api/admin/catalog/companies/'.$company->getId().'/partner', ['partner' => false]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload()['data']['partner']);
    }

    private function user(string $email, string $role): User
    {
        $user = new User($email, $role);
        $user->setPassword('unused'); $user->setApproved(true); $user->setEmailVerified(true); $user->setHideMyAccount(false);
        $user->setCity('Sarajevo'); $user->setCountryCode('BA');
        $this->em->persist($user);

        return $user;
    }

    private function write(string $url, array $body): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $this->client->jsonRequest('PUT', $url, $body, ['HTTP_X_CSRF_TOKEN' => $this->payload()['csrfToken']]);
    }

    private function payload(): array { return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR); }
}
