<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Account\AgencyProfiles;
use App\Entity\{Company, Creator, Media, MediaFolder, User};
use App\Media\ImageVariants;
use App\Localization\ApiMessages;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\WebTestCase};

final class AdminProfileReviewTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata); $tool->createSchema($metadata);
        $this->admin = new User('review-admin@example.test', 'ROLE_COMPANY');
        $this->admin->setPassword('unused'); $this->admin->setAdmin(true);
        $this->em->persist($this->admin); $this->em->flush();
    }

    public function testListsShowSavedSectionsAndAdminCanReviewPrivateContent(): void
    {
        $owner = $this->account('review-creator', false, false);
        $creator = $owner->getCreator();
        $creator->updateProfile('Saved Creator', 'Beauty', 'Sarajevo', '<p>Saved biography</p>', [['platform' => 'Instagram', 'handle' => '@saved', 'followers' => 20]], ['Beauty'], null, 'Saved headline', [['id' => 'private', 'title' => 'Private project', 'publicPermission' => false]], [['id' => 'offer', 'title' => 'Saved package', 'price' => 100]], ['Beauty'], [['question' => 'A question?', 'answer' => 'An answer.']]);
        $folder = new MediaFolder('creator-avatar', 'Avatars');
        $media = new Media($folder, $owner, 'avatar.webp', 'test/admin-review.webp', 'image/webp', 10);
        $media->setDimensions(600, 600); $creator->setAvatarMedia($media);
        $this->em->persist($folder); $this->em->persist($media); $this->em->flush();
        $agency = $this->account('review-agency', true, true);
        $agency->getCompany()->setCompanyType('agency');
        $agency->getCompany()->setServices([['id' => 'service', 'title' => 'Production', 'description' => 'Saved service', 'deliverables' => [], 'platforms' => [], 'engagement' => 'project']]);
        $studio = AgencyProfiles::ensure($agency, $this->em);
        $studio->updateProfile('Agency', 'Beauty', 'Sarajevo', '', [], [], null, '', [['title' => 'Agency project', 'publicPermission' => false]], [['title' => 'Agency package']], ['Beauty'], []);
        $this->em->flush();
        $url = '/api/admin/catalog/registrations/'.$owner->getId().'/profile';
        $this->client->request('GET', $url); self::assertResponseStatusCodeSame(401);
        $this->client->loginUser($owner); $this->client->request('GET', $url); self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/api/admin/catalog/registrations');
        self::assertResponseIsSuccessful();
        $row = $this->payload()['data'][0];
        self::assertSame($owner->getId(), $row['id']);
        self::assertSame('/api/media/'.$media->getId().'/thumbnail/'.ImageVariants::VERSION.'/96', $row['profileImage']['src']);
        self::assertSame(['contact' => 1, 'identity' => 1, 'image' => 1, 'description' => 1, 'networks' => 1, 'works' => 1, 'packages' => 1, 'faqs' => 1], $row['completion']);
        self::assertFalse($row['publicVisible']); self::assertFalse($row['canShowProfile']);
        $this->client->request('GET', $url); self::assertResponseIsSuccessful();
        $details = $this->payload()['data'];
        self::assertSame('Private project', $details['profile']['portfolio'][0]['title']);
        self::assertSame($owner->getPhone(), $details['phone']);
        self::assertSame('Saved biography', strip_tags($details['profile']['bio']));
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        foreach (['password', 'passwordHash', 'oauthIdentities', 'emailVerificationToken'] as $secret) self::assertArrayNotHasKey($secret, $details);
        $this->client->request('GET', '/api/admin/catalog/companies');
        $row = $this->payload()['data'][0];
        self::assertSame(1, $row['completion']['services']); self::assertSame(1, $row['completion']['works']);
        self::assertSame(1, $row['completion']['packages']);
        $this->client->request('GET', '/api/admin/catalog/companies/'.$agency->getCompany()->getId().'/profile');
        self::assertSame('Agency project', $this->payload()['data']['profile']['portfolio'][0]['title']);
        $this->client->request('GET', '/api/admin/catalog/creators/'.$studio->getId().'/profile');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminVisibilityRequiresCsrfAndPreservesApprovalAndSavedData(): void
    {
        foreach ([false, true] as $company) {
            $owner = $this->account($company ? 'visible-company' : 'visible-creator', true, $company);
            $profile = $company ? $owner->getCompany() : $owner->getCreator();
            $kind = $company ? 'companies' : 'creators';
            $url = '/api/admin/catalog/'.$kind.'/'.$profile->getId().'/visibility';
            $this->client->loginUser($owner);
            $this->client->jsonRequest('PUT', $url, ['hideMyAccount' => true], ['HTTP_X_CSRF_TOKEN' => $this->csrf()]);
            self::assertResponseStatusCodeSame(403);
            $this->client->loginUser($this->admin);
            $this->client->jsonRequest('PUT', $url, ['hideMyAccount' => true]); self::assertResponseStatusCodeSame(403);
            $token = $this->csrf();
            $this->client->jsonRequest('PUT', $url, ['hideMyAccount' => 'true'], ['HTTP_X_CSRF_TOKEN' => $token]); self::assertResponseStatusCodeSame(400);
            $this->client->jsonRequest('PUT', $url, ['hideMyAccount' => true], ['HTTP_X_CSRF_TOKEN' => $token]); self::assertResponseIsSuccessful();
            self::assertFalse($this->payload()['data']['publicVisible']);
            $owner = $this->em->find(User::class, $owner->getId());
            self::assertTrue($owner->isHideMyAccount()); self::assertTrue($owner->isApproved());
            $this->client->loginUser($this->account('viewer-'.$kind, true, false));
            $this->client->request('GET', '/api/'.$kind.'/'.$profile->getSlug()); self::assertResponseStatusCodeSame(404);
            $this->client->loginUser($this->admin);
            $this->client->jsonRequest('PUT', $url, ['hideMyAccount' => false], ['HTTP_X_CSRF_TOKEN' => $this->csrf()]); self::assertResponseIsSuccessful();
            self::assertTrue($this->payload()['data']['publicVisible']);
            $owner = $this->em->find(User::class, $owner->getId());
            self::assertFalse($owner->isHideMyAccount()); self::assertTrue($owner->isApproved());
            self::assertSame($company ? 'Saved company' : 'Saved creator', $company ? $profile->getName() : $profile->getDisplayName());
            self::assertSame('unused-private-password', $owner->getPassword());
        }
    }

    public function testPublishingCannotBypassApprovalVerificationOrRequiredFields(): void
    {
        $owner = $this->account('not-publishable', false, false);
        $owner->setHideMyAccount(true); $this->em->flush();
        $url = '/api/admin/catalog/registrations/'.$owner->getId().'/visibility';
        $this->client->loginUser($this->admin);
        $csrf = $this->csrf();
        foreach (['pending', 'unverified', 'incomplete'] as $state) {
            $owner = $this->em->find(User::class, $owner->getId());
            $owner->setApproved($state !== 'pending');
            $owner->setEmailVerified($state !== 'unverified');
            $owner->setPhone($state === 'incomplete' ? null : '+38761123456');
            $this->em->flush();
            $this->client->jsonRequest('PUT', $url, ['hideMyAccount' => false], ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseStatusCodeSame(400);
            self::assertSame(ApiMessages::get(match ($state) { 'pending' => 'account_pending_approval', 'unverified' => 'approval_requires_verified_email', default => 'profile_incomplete' }, 'bs'), $this->payload()['error']);
            self::assertTrue($this->em->find(User::class, $owner->getId())->isHideMyAccount());
        }
    }

    public function testAdminCanGrantAndRemoveAdminRightsForCreatorsAndCompanies(): void
    {
        foreach ([false, true] as $company) {
            $owner = $this->account($company ? 'role-company' : 'role-creator', true, $company);
            $profile = $company ? $owner->getCompany() : $owner->getCreator();
            $url = '/api/admin/catalog/'.($company ? 'companies' : 'creators').'/'.$profile->getId().'/admin';
            $this->client->loginUser($owner);
            $this->client->jsonRequest('PUT', $url, ['admin' => true], ['HTTP_X_CSRF_TOKEN' => $this->csrf()]);
            self::assertResponseStatusCodeSame(403);
            $this->client->loginUser($this->admin);
            $this->client->jsonRequest('PUT', $url, ['admin' => true]); self::assertResponseStatusCodeSame(403);
            $token = $this->csrf();
            $this->client->jsonRequest('PUT', $url, ['admin' => 'yes'], ['HTTP_X_CSRF_TOKEN' => $token]); self::assertResponseStatusCodeSame(400);
            $this->client->jsonRequest('PUT', $url, ['admin' => true], ['HTTP_X_CSRF_TOKEN' => $token]); self::assertResponseIsSuccessful();
            self::assertTrue($this->payload()['data']['isAdmin']);
            self::assertTrue($this->em->find(User::class, $owner->getId())->hasRole('ROLE_ADMIN'));
            $this->client->jsonRequest('PUT', $url, ['admin' => false], ['HTTP_X_CSRF_TOKEN' => $this->csrf()]); self::assertResponseIsSuccessful();
            self::assertFalse($this->payload()['data']['isAdmin']);
            $this->em->clear();
            self::assertFalse($this->em->find(User::class, $owner->getId())->hasRole('ROLE_ADMIN'));
            $this->admin = $this->em->find(User::class, $this->admin->getId());
        }
    }

    public function testAdminRightsCannotChangeOwnOrUnverifiedAccounts(): void
    {
        $this->admin->setEmailVerified(true);
        $this->admin->setCompany(new Company('review-admin', 'Admin company', 'Beauty'));
        $this->em->flush();
        $unverified = $this->account('role-unverified', true, false);
        $unverified->setEmailVerified(false); $this->em->flush();
        $this->client->loginUser($this->admin);
        $token = $this->csrf();
        $this->client->jsonRequest('PUT', '/api/admin/catalog/companies/'.$this->admin->getCompany()->getId().'/admin', ['admin' => false], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(ApiMessages::get('admin_self_change', 'bs'), $this->payload()['error']);
        $this->client->jsonRequest('PUT', '/api/admin/catalog/creators/'.$unverified->getCreator()->getId().'/admin', ['admin' => true], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(404);
        $this->em->clear();
        self::assertTrue($this->em->find(User::class, $this->admin->getId())->hasRole('ROLE_ADMIN'));
        self::assertFalse($this->em->find(User::class, $unverified->getId())->hasRole('ROLE_ADMIN'));
    }

    private function account(string $key, bool $approved, bool $company): User
    {
        $owner = new User($key.'@example.test', $company ? 'ROLE_COMPANY' : 'ROLE_CREATOR');
        $owner->setPassword('unused-private-password'); $owner->setApproved($approved); $owner->setEmailVerified(true);
        $owner->setPhone('+38761123456'); $owner->setCity('Sarajevo'); $owner->setCountryCode('BA');
        if ($company) $owner->setCompany(new Company($key, 'Saved company', 'Beauty'));
        else $owner->setCreator(new Creator($key, 'Saved creator', 'Beauty', 'Sarajevo', 'Biography', []));
        $this->em->persist($owner); $this->em->flush();
        return $owner;
    }

    private function csrf(): string
    {
        $this->client->request('GET', '/api/auth/csrf');
        return $this->payload()['csrfToken'];
    }

    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
