<?php

namespace App\Tests\Controller;

use App\Entity\Creator;
use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\User;
use App\Entity\{Application, Campaign, CampaignReview, Company, Offer, UserBlock};
use App\Service\MediaStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CreatorPresentationProfileTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    private function creator(string $slug): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User($slug.'@example.test', 'ROLE_CREATOR');
        $user->setPassword('unused');
        $user->setApproved(true);
        $user->setEmailVerified(true);
        $user->setHideMyAccount(false);
        $user->setCreator(new Creator($slug, $slug, 'Beauty', 'Sarajevo', '', []));
        $em->persist($user);
        $em->flush();
        return $user;
    }

    private function payload(): array { return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR); }
    private function token(): string { $this->client->request('GET', '/api/auth/csrf'); return $this->payload()['csrfToken']; }
    private function update(array $body): void
    {
        $csrf = $this->token();
        $this->client->request('PUT', '/api/me/profile', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testCompletedCreatorAndAgencyWorkCanBeSelectedAndSavedWhileCampaignStaysOpen(): void
    {
        $this->client->disableReboot();
        $creator = $this->creator('portfolio-provider');
        $stranger = $this->creator('portfolio-stranger');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $brand = new User('portfolio-brand@example.test', 'ROLE_COMPANY');
        $brand->setPassword('unused');
        $brand->setCompany(new Company('portfolio-brand', 'Portfolio Brand', 'Beauty'));
        $agency = new User('portfolio-agency@example.test', 'ROLE_COMPANY');
        $agency->setPassword('unused');
        $company = new Company('portfolio-agency', 'Portfolio Agency', 'Beauty');
        $company->setCompanyType('agency');
        $agency->setCompany($company);
        $em->persist($brand);
        $em->persist($agency);
        $em->flush();
        \App\Account\AgencyProfiles::ensure($agency, $em);
        $makeCampaign = static fn (string $slug): Campaign => new Campaign($slug, $slug, 'Campaign summary', '<p>Campaign brief.</p>', 'Beauty', ['Instagram'], ['Video'], 100, 300, 'Sarajevo', 2, new \DateTimeImmutable('+10 days'), new \DateTimeImmutable(), $brand->getCompany());
        $campaign = $makeCampaign('open-with-completed-work');
        $unfinished = $makeCampaign('work-in-progress');
        $foreign = $makeCampaign('other-provider-only');
        $legacy = $makeCampaign('legacy-finished-campaign');
        $legacy->setStatus('finished');
        foreach ([$campaign, $unfinished, $foreign, $legacy] as $item) $em->persist($item);
        $offers = [];
        foreach ([[$campaign, $creator], [$campaign, $agency], [$unfinished, $creator], [$foreign, $stranger], [$legacy, $creator]] as [$item, $provider]) {
            $application = new Application($item, $provider->getCreator(), 'Portfolio test application.');
            $offer = new Offer($application, 100, 'Agreed scope.');
            $offer->respond('accepted');
            $em->persist($application);
            $em->persist($offer);
            $offers[] = $offer;
        }
        // Another provider completing a campaign must never unlock it for this creator.
        $offers[3]->completeWork();
        $em->flush();
        $this->client->loginUser($creator, 'main');
        $this->client->request('GET', '/api/me/portfolio-campaigns');
        self::assertResponseIsSuccessful();
        self::assertSame([$legacy->getId()], array_column($this->payload()['data'], 'id'));

        foreach (array_slice($offers, 0, 2) as $offer) {
            $offer->completeWork();
            $provider = $offer->getApplication()->getCreator()->getOwner();
            $em->persist(new CampaignReview($offer->getApplication(), $brand, $provider, 5, 'Finished well.'));
            $em->persist(new CampaignReview($offer->getApplication(), $provider, $brand, 5, 'Great collaboration.'));
        }
        $em->flush();
        foreach ([$creator, $agency] as $provider) {
            $this->client->loginUser($provider, 'main');
            $this->client->request('GET', '/api/me/portfolio-campaigns');
            self::assertResponseIsSuccessful();
            $rows = $this->payload()['data'];
            self::assertContains($campaign->getId(), array_column($rows, 'id'));
            self::assertNotContains($unfinished->getId(), array_column($rows, 'id'));
            self::assertNotContains($foreign->getId(), array_column($rows, 'id'));
            $row = $rows[array_search($campaign->getId(), array_column($rows, 'id'), true)];
            self::assertSame('Campaign summary', $row['summary']);
            self::assertSame('<p>Campaign brief.</p>', $row['description']);
            self::assertSame(['Instagram'], $row['channels']);
            self::assertSame($offers[0]->getWorkCompletedAt()->format('Y-m-d'), $row['completedAt']);
            self::assertNull($row['finishedAt']);
            $body = $provider === $creator
                ? ['displayName' => 'Portfolio Provider', 'categories' => ['Beauty'], 'bio' => '', 'avatarUrl' => null, 'tags' => []]
                : ['name' => 'Portfolio Agency', 'companyType' => 'agency', 'industries' => ['Beauty']];
            $body['portfolio'] = [['title' => 'Finished work', 'type' => 'video', 'url' => 'https://youtu.be/abcdefghijk', 'source' => 'wave', 'campaignId' => $campaign->getId()]];
            $this->update($body);
            self::assertResponseIsSuccessful();
            $savedProfile = $provider === $creator ? $this->payload()['data'] : $this->payload()['data']['profile'];
            self::assertSame('Portfolio Brand', $savedProfile['portfolio'][0]['client']);
            self::assertSame($row['completedAt'], $savedProfile['portfolio'][0]['projectDate']);
            foreach ([$unfinished, $foreign] as $invalid) {
                $body['portfolio'][0]['campaignId'] = $invalid->getId();
                $this->update($body);
                self::assertResponseStatusCodeSame(400);
            }
        }
        self::assertSame('open', $campaign->getStatus());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new UserBlock(bin2hex(random_bytes(16)), $em->getReference(User::class, $creator->getId()), $em->getReference(User::class, $brand->getId()), new \DateTimeImmutable(), null));
        $em->flush();
        $this->client->loginUser($creator, 'main');
        $this->client->request('GET', '/api/me/portfolio-campaigns');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['data']);
    }

    public function testAgencyCanSelectItsOpenCampaignAsSoonAsAHiredCreatorCompletesWork(): void
    {
        $this->client->disableReboot();
        $creator = $this->creator('agency-client-provider');
        $otherCreator = $this->creator('agency-client-second-provider');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $agency = new User('organizer@example.test', 'ROLE_COMPANY');
        $agency->setPassword('unused');
        $company = new Company('organizer', 'Organizing Agency', 'Beauty');
        $company->setCompanyType('agency');
        $agency->setCompany($company);
        $foreignOwner = new User('other-organizer@example.test', 'ROLE_COMPANY');
        $foreignOwner->setPassword('unused');
        $foreignOwner->setCompany(new Company('other-organizer', 'Other Organizer', 'Beauty'));
        $em->persist($agency);
        $em->persist($foreignOwner);
        $makeCampaign = static fn (string $slug, Company $owner): Campaign => new Campaign($slug, $slug, 'Campaign summary', '<p>Campaign brief.</p>', 'Beauty', ['Instagram'], ['Video'], 100, 300, 'Sarajevo', 2, new \DateTimeImmutable('+10 days'), new \DateTimeImmutable(), $owner);
        $campaign = $makeCampaign('agency-owned-open', $company);
        $unfinished = $makeCampaign('agency-owned-ongoing', $company);
        $foreign = $makeCampaign('another-agencys-work', $foreignOwner->getCompany());
        foreach ([$campaign, $unfinished, $foreign] as $item) $em->persist($item);
        $offers = [];
        foreach ([[$campaign, $creator], [$campaign, $otherCreator], [$unfinished, $creator], [$foreign, $creator]] as [$item, $provider]) {
            $application = new Application($item, $provider->getCreator(), 'Agency organizer test.');
            $offer = new Offer($application, 100, 'Agreed scope.');
            $offer->respond('accepted');
            $em->persist($application);
            $em->persist($offer);
            $offers[] = $offer;
        }
        $offers[3]->completeWork();
        $em->flush();
        $campaignId = $campaign->getId();
        $offerIds = array_map(static fn (Offer $offer): int => $offer->getId(), $offers);
        self::assertNull($agency->getCreator(), 'An organizing agency does not need a provider studio.');
        $this->client->loginUser($agency, 'main');
        $this->client->request('GET', '/api/me/portfolio-campaigns');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['data']);

        // Completion alone unlocks the campaign immediately, without closing it or waiting for reviews.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $offer = $em->find(Offer::class, $offerIds[0]);
        $offer->completeWork();
        $em->flush();
        $completedAt = $offer->getWorkCompletedAt()->format('Y-m-d');
        $this->client->request('GET', '/api/me/portfolio-campaigns');
        self::assertResponseIsSuccessful();
        $rows = $this->payload()['data'];
        self::assertSame([$campaignId], array_column($rows, 'id'));
        self::assertSame($completedAt, $rows[0]['completedAt']);
        self::assertSame('Organizing Agency', $rows[0]['company']);
        self::assertSame('agency', $rows[0]['companyType']);
        self::assertNull($rows[0]['finishedAt']);
        $body = ['name' => 'Organizing Agency', 'companyType' => 'agency', 'industries' => ['Beauty'], 'portfolio' => [
            ['title' => 'Our completed collaboration', 'type' => 'video', 'url' => 'https://youtu.be/abcdefghijk', 'source' => 'wave', 'campaignId' => $campaignId],
        ]];
        $this->update($body);
        self::assertResponseIsSuccessful();
        $saved = $this->payload()['data']['profile']['portfolio'][0];
        self::assertSame($campaignId, $saved['campaignId']);
        self::assertSame('Organizing Agency', $saved['agency']);
        self::assertSame($completedAt, $saved['projectDate']);
        foreach ([$unfinished, $foreign] as $invalid) {
            $body['portfolio'][0]['campaignId'] = $invalid->getId();
            $this->update($body);
            self::assertResponseStatusCodeSame(400);
        }

        // Multiple completed providers must not duplicate a campaign or reduce a 20-campaign page.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->find(Offer::class, $offerIds[1])->completeWork();
        $company = $em->find(Company::class, $company->getId());
        $provider = $em->find(Creator::class, $creator->getCreator()->getId());
        for ($i = 0; $i < 20; ++$i) {
            $item = $makeCampaign('agency-owned-page-'.$i, $company);
            $application = new Application($item, $provider, 'Another finished collaboration.');
            $offer = new Offer($application, 100, 'Agreed scope.');
            $offer->respond('accepted');
            $offer->completeWork();
            $em->persist($item);
            $em->persist($application);
            $em->persist($offer);
        }
        $em->flush();
        $this->client->request('GET', '/api/me/portfolio-campaigns');
        self::assertResponseIsSuccessful();
        $page = $this->payload();
        self::assertCount(20, $page['data']);
        self::assertCount(20, array_unique(array_column($page['data'], 'id')));
        self::assertTrue($page['meta']['hasMore']);
        $this->client->request('GET', '/api/me/portfolio-campaigns?before='.$page['meta']['nextCursor']);
        self::assertResponseIsSuccessful();
        self::assertSame([$campaignId], array_column($this->payload()['data'], 'id'));
        self::assertFalse($this->payload()['meta']['hasMore']);
        self::assertNull($this->payload()['meta']['nextCursor']);
        self::assertSame('open', $em->find(Campaign::class, $campaignId)->getStatus());
    }

    public function testExtendedFieldsSurviveAndForgedReferencesCannotOverwriteTheProfile(): void
    {
        $owner = $this->creator('studio-owner');
        $this->client->loginUser($owner, 'main');
        $body = [
            'displayName' => 'Studio Owner', 'categories' => ['Beauty'], 'bio' => '', 'avatarUrl' => null, 'tags' => [],
            'presentation' => ['languages' => ['bs', 'en'], 'availability' => 'from', 'availableFrom' => '2026-10-20'],
            'socialProfiles' => [['platform' => 'Instagram', 'handle' => '@studio', 'followers' => 2000, 'averageViews' => 500, 'lastUpdated' => '2026-09-01', 'audienceCountries' => [['countryCode' => 'BA', 'percent' => 80]]]],
            'portfolio' => [['type' => 'video', 'title' => 'My project', 'url' => 'https://youtu.be/abcdefghijk', 'source' => 'external', 'client' => 'A brand', 'publicPermission' => false, 'results' => [['metric' => 'Views', 'value' => 100, 'period' => '7 days']]]],
            'packages' => [['title' => 'Custom content', 'description' => 'Product video for the brand channels.', 'platform' => 'All', 'price' => 999, 'priceMode' => 'agreement', 'mode' => 'content', 'deliveryDays' => 5, 'revisions' => 0, 'usage' => 'ads', 'usageDays' => 30]],
        ];
        $this->update($body);
        self::assertResponseIsSuccessful();
        self::assertSame(['bs', 'en'], $this->payload()['data']['presentation']['languages']);
        self::assertNull($this->payload()['data']['packages'][0]['price']);
        self::assertSame(0, $this->payload()['data']['packages'][0]['revisions']);
        $this->client->request('GET', '/api/creators/studio-owner');
        self::assertSame([], $this->payload()['data']['portfolio']);
        $this->client->request('GET', '/api/creators');
        self::assertCount(1, $this->payload()['data']);
        self::assertSame([], $this->payload()['data'][0]['portfolio']);
        unset($body['socialProfiles'][0]['lastUpdated']);
        $body['bio'] = 'Updated introduction';
        $this->update($body);
        self::assertResponseIsSuccessful();
        self::assertSame('2026-09-01', $this->payload()['data']['socialProfiles'][0]['lastUpdated']);
        $body['portfolio'][0]['source'] = 'wave';
        $body['portfolio'][0]['campaignId'] = 999999;
        $this->update($body);
        self::assertResponseStatusCodeSame(400);
        $body['portfolio'][0]['source'] = 'external';
        $body['portfolio'][0]['coverMediaId'] = 999999;
        $this->update($body);
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/api/auth/me');
        self::assertSame('external', $this->payload()['data']['profile']['portfolio'][0]['source']);
    }

    public function testVisitsExcludeOwnersDeduplicateSessionsAndStatisticsAreOwnerScoped(): void
    {
        $owner = $this->creator('visit-owner');
        $other = $this->creator('visit-other');
        $this->client->loginUser($owner, 'main');
        $csrf = $this->token();
        $this->client->request('POST', '/api/creators/visit-owner/view', server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertFalse($this->payload()['recorded']);
        $this->client->restart();
        $csrf = $this->token();
        foreach ([true, false] as $recorded) {
            $this->client->request('POST', '/api/creators/visit-owner/view', server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
            self::assertResponseIsSuccessful();
            self::assertSame($recorded, $this->payload()['recorded']);
        }
        $this->client->request('GET', '/api/me/profile-statistics');
        self::assertResponseStatusCodeSame(401);
        $this->client->loginUser($owner, 'main');
        $this->client->request('GET', '/api/me/profile-statistics?days=30');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload()['data']['counts']['views']);
        $this->client->loginUser($other, 'main');
        $this->client->request('GET', '/api/me/profile-statistics?creator='.$owner->getCreator()->getId());
        self::assertSame(0, $this->payload()['data']['counts']['views']);
        self::assertSame($other->getId(), $this->payload()['userId']);
    }

    public function testExistingImageWorksCanBeSavedRepeatedlyAndDraftFilesArePrivate(): void
    {
        $owner = $this->creator('image-owner');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $storage = self::getContainer()->get(MediaStorage::class);
        $folder = new MediaFolder('creator-portfolio', 'Portfolio');
        $em->persist($folder);
        $em->flush();
        $file = new UploadedFile(dirname(__DIR__, 2).'/public/images/home-faq-creator.webp', 'work.webp', test: true);
        $media = [];
        try {
            for ($index = 0; $index < 2; ++$index) {
                $stored = $storage->store($file, $folder, $owner);
                $image = new Media($folder, $owner, 'work.webp', $stored['path'], $stored['mimeType'], $stored['fileSize']);
                $em->persist($image);
                $media[] = $image;
            }
            $em->flush();
            $this->client->loginUser($owner, 'main');
            $body = [
                'displayName' => 'Image Owner', 'categories' => ['Beauty'], 'bio' => '', 'avatarUrl' => null, 'tags' => [],
                'portfolio' => [[
                    'id' => 'image-work-one', 'type' => 'image', 'title' => 'Private work', 'mediaId' => $media[0]->getId(),
                    'coverMediaId' => $media[1]->getId(), 'publicPermission' => false,
                ]],
            ];
            foreach ([1, 2] as $attempt) {
                $this->update($body);
                self::assertResponseIsSuccessful('Saving the same images twice must not conflict with unique positions.');
                self::assertSame($media[1]->getId(), $this->payload()['data']['portfolio'][0]['coverMediaId']);
            }
            $this->client->request('GET', $media[0]->getUrl());
            self::assertResponseIsSuccessful();
            $this->client->restart();
            foreach ($media as $image) {
                $this->client->request('GET', $image->getUrl());
                self::assertResponseStatusCodeSame(404);
            }
            $this->client->loginUser($owner, 'main');
            $body['portfolio'][0]['publicPermission'] = true;
            $this->update($body);
            self::assertResponseIsSuccessful();
            $this->client->restart();
            foreach ($media as $image) {
                $this->client->request('GET', $image->getUrl());
                self::assertResponseIsSuccessful();
            }
        } finally {
            foreach ($media as $image) {
                $storage->remove($image);
            }
        }
    }
}
