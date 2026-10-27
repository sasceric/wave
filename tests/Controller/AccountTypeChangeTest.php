<?php

namespace App\Tests\Controller;

use App\Api\{CompanyResource, UserResource};
use App\Entity\{AccountTypeRequest, Application, Campaign, Company, Creator, Media, MediaFolder, Notification, Offer, User};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AccountTypeChangeTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $adminId;
    private int $creatorId;
    private int $brandId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = $this->em();
        $schema = new SchemaTool($em); $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata); $schema->createSchema($metadata);
        $admin = $this->user('admin', 'ROLE_COMPANY'); $admin->setAdmin(true);
        $creator = $this->user('creator', 'ROLE_CREATOR');
        $profile = new Creator('sample-creator', 'Creator Name', 'Travel', 'Sarajevo', '<p>Creator biography</p>', [['platform' => 'Instagram', 'url' => 'https://instagram.com/example', 'followers' => 500]]);
        $profile->setPortfolio([['id' => 'existing-work', 'title' => 'Existing work', 'url' => 'https://example.com/work', 'type' => 'video']]);
        $profile->setPackages([['id' => 'existing-package', 'title' => 'Existing package']]);
        $creator->setCreator($profile);
        $brand = $this->user('brand', 'ROLE_COMPANY');
        $company = new Company('sample-company', 'Company Name', 'Beauty', about: '<p>Company biography</p>');
        $company->setSocialLinks([['platform' => 'Instagram', 'url' => 'https://instagram.com/company']]);
        $brand->setCompany($company);
        $em->persist($admin); $em->persist($creator); $em->persist($brand); $em->flush();
        $this->adminId = $admin->getId(); $this->creatorId = $creator->getId(); $this->brandId = $brand->getId();
    }

    public function testRequestsArePrivateRequireCsrfAndCannotDuplicateOrChangeRolesDirectly(): void
    {
        $this->client->request('GET', '/api/me/account-type-requests'); self::assertResponseStatusCodeSame(401);
        $this->login($this->creatorId);
        $this->client->jsonRequest('POST', '/api/me/account-type-requests', $this->request('agency')); self::assertResponseStatusCodeSame(403);
        $this->write('POST', '/api/me/account-type-requests', $this->request('agency')); self::assertResponseStatusCodeSame(201);
        $id = $this->payload()['data']['id'];
        self::assertSame('pending', $this->payload()['data']['status']);
        self::assertTrue($this->em()->find(User::class, $this->creatorId)->hasRole('ROLE_CREATOR'));
        $this->write('POST', '/api/me/account-type-requests', $this->request('brand')); self::assertResponseStatusCodeSame(409);
        $this->write('POST', '/api/me/account-type-requests', $this->request('admin')); self::assertResponseStatusCodeSame(422);
        $this->login($this->brandId);
        $this->client->request('GET', '/api/me/account-type-requests'); self::assertSame([], $this->payload()['data']);
        $this->write('DELETE', '/api/me/account-type-requests/'.$id); self::assertResponseStatusCodeSame(404);
        $this->write('PUT', '/api/admin/account-type-requests/'.$id, ['decision' => 'approved']); self::assertResponseStatusCodeSame(403);
        $this->login($this->creatorId);
        $this->write('DELETE', '/api/me/account-type-requests/'.$id); self::assertResponseIsSuccessful();
        self::assertSame('withdrawn', $this->payload()['data']['status']);
        $this->write('POST', '/api/me/account-type-requests', $this->request('brand')); self::assertResponseStatusCodeSame(201);
    }

    public function testApprovalMergesCreatorIntoAgencyAndKeepsIdentityPortfolioMediaAndNotifications(): void
    {
        $creator = $this->em()->find(User::class, $this->creatorId);
        $providerId = $creator->getCreator()->getId();
        $folder = new MediaFolder('creator-avatar', 'Avatars'); $this->em()->persist($folder);
        $image = new Media($folder, $creator, 'avatar.webp', 'test/type-avatar.webp', 'image/webp', 20); $this->em()->persist($image);
        $creator->getCreator()->setAvatarMedia($image); $this->em()->flush(); $imageId = $image->getId();
        $this->login($this->creatorId);
        $this->write('POST', '/api/me/account-type-requests', $this->request('agency')); $id = $this->payload()['data']['id'];
        $this->login($this->adminId);
        $this->client->request('GET', '/api/admin/account-type-requests'); self::assertSame(1, $this->payload()['meta']['total']);
        $this->write('PUT', '/api/admin/account-type-requests/'.$id, ['decision' => 'approved', 'response' => 'Approved after review.']); self::assertResponseIsSuccessful();
        $user = $this->em()->find(User::class, $this->creatorId);
        self::assertSame($this->creatorId, $user->getId());
        self::assertTrue($user->isAgency());
        self::assertSame($providerId, $user->getCreator()->getId());
        self::assertTrue($user->getCreator()->isAgencyProfile());
        self::assertSame($imageId, $user->getCompany()->getLogoMedia()->getId());
        self::assertSame('<p>Creator biography</p>', $user->getCompany()->getAbout());
        self::assertSame('Existing work', $user->getCreator()->getPortfolio()[0]['title']);
        self::assertSame('Existing package', $user->getCreator()->getPackages()[0]['title']);
        self::assertCount(1, $user->getCompany()->getSocialLinks());
        self::assertSame(1, $this->em()->getRepository(Notification::class)->count(['recipient' => $user, 'type' => 'account_type_changed']));
        self::assertSame(1, $this->em()->getRepository(Notification::class)->count(['recipient' => $this->em()->find(User::class, $this->adminId), 'type' => 'account_type_change_requested']));
        $this->write('PUT', '/api/admin/account-type-requests/'.$id, ['decision' => 'approved']); self::assertResponseStatusCodeSame(409);
        self::assertSame(1, $this->em()->getRepository(Notification::class)->count(['recipient' => $user, 'type' => 'account_type_changed']));
        $this->login($this->creatorId);
        $this->client->request('GET', '/api/auth/session'); self::assertSame('company', $this->payload()['data']['accountType']);
        self::assertSame('agency', $this->payload()['data']['profile']['companyType']);
        // Transferred, already-associated avatars remain valid when saved as a company logo.
        $method = new \ReflectionMethod(\App\Controller\Api\ProfileController::class, 'ownedMedia');
        $controller = static::getContainer()->get(\App\Controller\Api\ProfileController::class);
        self::assertInstanceOf(Media::class, $method->invoke($controller, $imageId, $this->em()->find(User::class, $this->creatorId), 'company-logo', $this->em()));
    }

    public function testCompanyToCreatorPreservesCompanyHistoryAndHidesArchivedPublicProfile(): void
    {
        $brand = $this->em()->find(User::class, $this->brandId);
        $companyId = $brand->getCompany()->getId();
        $brand->getCompany()->setBusinessDetails('COMP-123', 'VAT-456', 'Private Owner Name');
        $campaign = $this->campaign($brand->getCompany()); $this->em()->persist($campaign); $this->em()->flush(); $campaignId = $campaign->getId();
        $this->login($this->brandId);
        $this->write('POST', '/api/me/account-type-requests', $this->request('creator')); $id = $this->payload()['data']['id'];
        $this->login($this->adminId);
        $this->write('PUT', '/api/admin/account-type-requests/'.$id, ['decision' => 'approved']); self::assertResponseStatusCodeSame(409);
        self::assertSame('pending', $this->em()->find(AccountTypeRequest::class, $id)->getStatus());
        $this->em()->find(Campaign::class, $campaignId)->setStatus('finished'); $this->em()->flush();
        $this->write('PUT', '/api/admin/account-type-requests/'.$id, ['decision' => 'approved']); self::assertResponseIsSuccessful();
        $user = $this->em()->find(User::class, $this->brandId);
        self::assertTrue($user->hasRole('ROLE_CREATOR'));
        self::assertSame($companyId, $user->getCompany()->getId());
        self::assertTrue($user->getCompany()->isArchivedProfile());
        self::assertSame('COMP-123', $user->getCompany()->getRegistrationId());
        self::assertSame('<p>Company biography</p>', $user->getCreator()->getBio());
        self::assertSame('Beauty', $user->getCreator()->getCategories()[0]);
        self::assertFalse($user->getCreator()->isAgencyProfile());
        self::assertSame($companyId, $this->em()->find(Campaign::class, $campaignId)->getCompany()->getId());
        $this->client->request('GET', '/api/companies'); self::assertResponseIsSuccessful();
        self::assertNotContains($companyId, array_column($this->payload()['data'], 'id'));
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/api/companies/sample-company'); self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/companies/filters'); self::assertResponseIsSuccessful();
    }

    public function testRejectionAndActiveProviderWorkDoNotChangeAccountData(): void
    {
        $user = $this->em()->find(User::class, $this->creatorId);
        $campaign = $this->campaign($this->em()->find(User::class, $this->brandId)->getCompany());
        $application = new Application($campaign, $user->getCreator(), 'Existing collaboration');
        $offer = new Offer($application, 100, 'Existing offer'); $offer->respond('accepted');
        foreach ([$campaign, $application, $offer] as $item) $this->em()->persist($item);
        $this->em()->flush(); $offerId = $offer->getId();
        $this->login($this->creatorId);
        $this->write('POST', '/api/me/account-type-requests', $this->request('brand')); $id = $this->payload()['data']['id'];
        $this->login($this->adminId);
        $this->write('PUT', '/api/admin/account-type-requests/'.$id, ['decision' => 'approved']); self::assertResponseStatusCodeSame(409);
        self::assertTrue($this->em()->find(User::class, $this->creatorId)->hasRole('ROLE_CREATOR'));
        self::assertNotNull($this->em()->find(Offer::class, $offerId));
        $this->write('PUT', '/api/admin/account-type-requests/'.$id, ['decision' => 'rejected', 'response' => 'Please finish the active work first.']); self::assertResponseIsSuccessful();
        self::assertSame('rejected', $this->payload()['data']['status']);
        self::assertSame(1, $this->em()->getRepository(Notification::class)->count(['recipient' => $this->em()->find(User::class, $this->creatorId), 'type' => 'account_type_change_rejected']));
    }

    public function testAgencyCanReturnToCreatorWithoutDuplicatingItsProviderOrLosingStudio(): void
    {
        $this->login($this->creatorId);
        $providerId = $this->em()->find(User::class, $this->creatorId)->getCreator()->getId();
        $this->write('POST', '/api/me/account-type-requests', $this->request('agency')); $first = $this->payload()['data']['id'];
        $this->login($this->adminId);
        $this->write('PUT', '/api/admin/account-type-requests/'.$first, ['decision' => 'approved']); self::assertResponseIsSuccessful();
        $companyId = $this->em()->find(User::class, $this->creatorId)->getCompany()->getId();
        $this->login($this->creatorId);
        $this->write('POST', '/api/me/account-type-requests', $this->request('creator')); $second = $this->payload()['data']['id'];
        $this->login($this->adminId);
        $this->write('PUT', '/api/admin/account-type-requests/'.$second, ['decision' => 'approved']); self::assertResponseIsSuccessful();
        $user = $this->em()->find(User::class, $this->creatorId);
        self::assertSame($providerId, $user->getCreator()->getId());
        self::assertFalse($user->getCreator()->isAgencyProfile());
        self::assertSame($companyId, $user->getCompany()->getId());
        self::assertTrue($user->getCompany()->isArchivedProfile());
        self::assertSame('Existing work', $user->getCreator()->getPortfolio()[0]['title']);
        self::assertSame('Existing package', $user->getCreator()->getPackages()[0]['title']);
        self::assertSame(2, $this->em()->getRepository(Notification::class)->count(['recipient' => $user, 'type' => 'account_type_changed']));
        self::assertSame(1, $this->em()->getRepository(Creator::class)->count(['owner' => $user]));
    }

    public function testPrivateCompanyDetailsAreOptionalProtectedAndClearedOnAnonymization(): void
    {
        $this->login($this->creatorId);
        $this->write('PUT', '/api/me/company-details', ['registrationId' => 'x']); self::assertResponseStatusCodeSame(403);
        $this->login($this->brandId);
        $this->write('PUT', '/api/me/company-details', ['registrationId' => ' 123 ', 'vatId' => 'VAT-456', 'ownerFullName' => 'Private Owner']); self::assertResponseIsSuccessful();
        self::assertSame('123', $this->payload()['data']['profile']['businessDetails']['registrationId']);
        $company = $this->em()->find(User::class, $this->brandId)->getCompany();
        self::assertArrayNotHasKey('businessDetails', CompanyResource::fromEntity($company, 'en'));
        self::assertArrayHasKey('businessDetails', CompanyResource::fromEntity($company, 'en', public: false));
        $this->write('PUT', '/api/me/company-details', ['registrationId' => str_repeat('a', 61)]); self::assertResponseStatusCodeSame(422);
        self::assertSame('123', $this->em()->find(User::class, $this->brandId)->getCompany()->getRegistrationId());
        $this->write('PUT', '/api/me/company-details', ['registrationId' => '', 'vatId' => '', 'ownerFullName' => '']); self::assertResponseIsSuccessful();
        self::assertNull($this->payload()['data']['profile']['businessDetails']['vatId']);
        $company = $this->em()->find(User::class, $this->brandId)->getCompany(); $company->setBusinessDetails('1', '2', 'Owner'); $company->anonymize();
        self::assertNull($company->getOwnerFullName()); self::assertNull($company->getRegistrationId());
    }

    private function user(string $name, string $role): User
    {
        $user = new User($name.'@example.test', $role); $user->setPassword('unused'); $user->setPhone('+38761123456'); $user->setCity('Sarajevo'); $user->setCountryCode('BA'); return $user;
    }
    private function campaign(Company $company): Campaign { return new Campaign('type-test', 'Test campaign', 'Summary', 'Campaign description', 'Beauty', ['Instagram'], ['Video'], 100, 200, 'Sarajevo', 2, new \DateTimeImmutable('+1 month'), new \DateTimeImmutable(), $company); }
    private function request(string $type): array { return ['targetType' => $type, 'profileName' => 'New Profile Name', 'message' => 'Please change my account type.']; }
    private function em(): EntityManagerInterface { return static::getContainer()->get(EntityManagerInterface::class); }
    private function login(int $id): void { $this->client->loginUser($this->em()->find(User::class, $id)); }
    private function write(string $method, string $path, array $body = []): void
    {
        $this->client->request('GET', '/api/auth/csrf'); $csrf = $this->payload()['csrfToken'];
        $this->client->jsonRequest($method, $path, $body, ['HTTP_X_CSRF_TOKEN' => $csrf]);
    }
    private function payload(): array { return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR); }
}
