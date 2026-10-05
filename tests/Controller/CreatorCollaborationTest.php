<?php

namespace App\Tests\Controller;

use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\CreatorInquiry;
use App\Entity\Media;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

final class CreatorCollaborationTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private KernelBrowser $client;
    private array $uploadedMediaIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        if (self::$kernel !== null) {
            $entityManager = static::getContainer()->get(EntityManagerInterface::class);
            $storage = static::getContainer()->get(\App\Service\MediaStorage::class);
            foreach ($this->uploadedMediaIds as $id) {
                $media = $entityManager->getRepository(Media::class)->find($id);
                if ($media instanceof Media) {
                    $storage->remove($media);
                }
            }
        }

        parent::tearDown();
    }

    public function testProfileSupportsPortfolioPackagesAndLegacyPayloads(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('portfolio-creator@example.test', 'ROLE_CREATOR');
        $user->setPassword('unused-test-hash');
        $creator = new Creator('portfolio-creator', 'Portfolio Creator', 'Beauty', 'Sarajevo', 'A creator bio.', [], []);
        $user->setCreator($creator);
        $entityManager->persist($user);
        $entityManager->flush();

        $this->client->loginUser($user, 'main');
        $csrf = $this->csrfToken();
        $media = $this->uploadImage('creator-portfolio', $csrf);
        self::assertResponseStatusCodeSame(201);
        $this->uploadedMediaIds[] = $media['data']['id'];
        $profile = [
            'displayName' => 'Portfolio Creator',
            'category' => 'Beauty',
            'location' => 'Sarajevo',
            'bio' => 'I make thoughtful beauty and skincare videos.',
            'tagline' => 'Thoughtful beauty stories.',
            'avatarUrl' => null,
            'tags' => ['Beauty', 'Skincare'],
            'socialProfiles' => [['platform' => 'Instagram', 'handle' => '@portfolio', 'followers' => 4200]],
            'portfolio' => [
                ['id' => 'uploaded-editorial', 'type' => 'image', 'mediaId' => $media['data']['id'], 'title' => 'Editorial work', 'platform' => 'Instagram'],
                ['type' => 'video', 'url' => 'https://youtu.be/abcdefghijk', 'title' => 'Short-form video', 'platform' => 'TikTok'],
            ],
            'packages' => [
                ['id' => 'package-basic', 'platform' => 'Instagram', 'title' => 'Instagram story set', 'description' => 'A set of three story frames for your product.', 'price' => 350],
                ['id' => 'package-custom', 'platform' => 'YouTube', 'title' => 'Custom YouTube feature', 'description' => 'A custom video integration planned around your brief.', 'price' => null],
            ],
        ];
        $this->jsonRequest('PUT', '/api/me/profile', $profile, $csrf);
        self::assertResponseIsSuccessful();
        self::assertSame('Thoughtful beauty stories.', $this->payload()['data']['tagline']);

        $this->client->request('GET', '/api/creators/portfolio-creator?locale=en');
        self::assertResponseIsSuccessful();
        $creatorProfile = $this->payload()['data'];
        self::assertSame($media['data']['id'], $creatorProfile['portfolio'][0]['mediaId']);
        self::assertSame($media['data']['url'], $creatorProfile['portfolio'][0]['url']);
        self::assertSame('https://www.youtube-nocookie.com/embed/abcdefghijk', $creatorProfile['portfolio'][1]['embedUrl']);
        self::assertSame('Instagram', $creatorProfile['portfolio'][0]['platform']);
        self::assertSame(350, $creatorProfile['packages'][0]['price']);
        self::assertNull($creatorProfile['packages'][1]['price']);

        unset($profile['tagline'], $profile['portfolio'], $profile['packages']);
        $this->jsonRequest('PUT', '/api/me/profile', $profile, $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertSame('I make thoughtful beauty and skincare videos.', $this->payload()['data']['tagline']);

        $profile['portfolio'] = [[
            'type' => 'video',
            'url' => 'https://untrusted.example.test/embed/abcdefghijk',
            'title' => 'Unsafe embed',
        ]];
        $this->jsonRequest('PUT', '/api/me/profile', $profile, $this->csrfToken());
        self::assertResponseStatusCodeSame(400);
    }

    public function testCollaborationInquiryChatIsParticipantOnlyAndAcceptanceGated(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $creatorUser = new User('inquiry-creator@example.test', 'ROLE_CREATOR');
        $creatorUser->setPassword('unused-test-hash');
        $creatorUser->setPreferredLocale('en');
        $creator = new Creator(
            'inquiry-creator',
            'Inquiry Creator',
            'Food',
            'Sarajevo',
            'A creator bio.',
            [],
            [],
            packages: [[
                'id' => 'food-package',
                'platform' => 'TikTok',
                'title' => 'Recipe video',
                'description' => 'A short recipe video with a product feature.',
                'price' => 500,
            ]],
        );
        $creatorUser->setCreator($creator);
        $companyUser = new User('inquiry-company@example.test', 'ROLE_COMPANY');
        $companyUser->setPassword('unused-test-hash');
        $company = new Company('inquiry-brand', 'Inquiry Brand', 'Food');
        $companyUser->setCompany($company);
        $otherCompanyUser = new User('other-inquiry-company@example.test', 'ROLE_COMPANY');
        $otherCompanyUser->setPassword('unused-test-hash');
        $otherCompanyUser->setCompany(new Company('other-inquiry-brand', 'Other Inquiry Brand', 'Retail'));
        $entityManager->persist($creatorUser);
        $entityManager->persist($companyUser);
        $entityManager->persist($otherCompanyUser);
        $entityManager->flush();

        $this->client->loginUser($companyUser, 'main');
        $requestBody = [
            'packageId' => 'food-package',
            'proposedAmount' => 650,
            'message' => 'We would love to feature our new local ingredients in your recipe series.',
        ];
        $this->jsonRequest('POST', '/api/creators/inquiry-creator/inquiries', $requestBody, $this->csrfToken());
        self::assertResponseStatusCodeSame(201);
        $inquiry = $this->payload()['data'];
        self::assertSame('pending', $inquiry['status']);
        self::assertSame(500, $inquiry['listedPrice']);
        self::assertSame(650, $inquiry['proposedAmount']);
        self::assertSame('food-package', $inquiry['selectedPackages'][0]['id']);
        self::assertEmailCount(1);
        $inquiryEmail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $inquiryEmail);
        self::assertSame('inquiry-creator@example.test', $inquiryEmail->getTo()[0]->getAddress());
        self::assertEmailHtmlBodyContains($inquiryEmail, 'Recipe video');
        self::assertEmailHtmlBodyContains($inquiryEmail, '500 BAM');
        self::assertEmailHtmlBodyContains($inquiryEmail, '650 BAM');
        self::assertEmailHtmlBodyContains(
            $inquiryEmail,
            'We would love to feature our new local ingredients in your recipe series.',
        );
        $this->client->loginUser($creatorUser, 'main');
        $this->client->request('GET', '/api/me/notifications?locale=en');
        self::assertResponseIsSuccessful();
        self::assertContains(
            'creator_inquiry_received',
            array_column($this->payload()['data'], 'type'),
        );
        $this->client->loginUser($companyUser, 'main');
        $inquiryId = $inquiry['id'];

        $this->jsonRequest('POST', '/api/creators/inquiry-creator/inquiries', [
            'packageIds' => ['food-package'],
            'servicePackage' => true,
            'other' => true,
            'proposedAmount' => 850,
            'message' => 'We are interested in several options for a seasonal campaign.',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(201);
        $multiPackageInquiry = $this->payload()['data'];
        self::assertCount(3, $multiPackageInquiry['selectedPackages']);
        self::assertSame(['package', 'service', 'other'], array_column($multiPackageInquiry['selectedPackages'], 'type'));
        self::assertNull($multiPackageInquiry['packageId']);
        self::assertNull($multiPackageInquiry['listedPrice']);
        self::assertEmailCount(1);
        $multiPackageEmail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $multiPackageEmail);
        self::assertEmailHtmlBodyContains($multiPackageEmail, 'Recipe video');
        self::assertEmailHtmlBodyContains($multiPackageEmail, 'Service package');
        self::assertEmailHtmlBodyContains($multiPackageEmail, 'Something else');
        self::assertEmailHtmlBodyContains($multiPackageEmail, '850 BAM');
        self::assertEmailHtmlBodyContains(
            $multiPackageEmail,
            'We are interested in several options for a seasonal campaign.',
        );

        $this->jsonRequest('POST', '/api/creators/inquiry-creator/inquiries', [
            'packageIds' => ['not-a-creator-package'],
            'servicePackage' => false,
            'other' => false,
            'message' => 'We would like to explore another campaign option.',
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(400);

        $this->client->request('GET', '/api/me/inquiries/'.$inquiryId.'/messages?locale=bs');
        self::assertResponseStatusCodeSame(409);
        $this->client->loginUser($otherCompanyUser, 'main');
        $this->client->request('GET', '/api/me/inquiries/'.$inquiryId.'/messages?locale=bs');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($creatorUser, 'main');
        $this->jsonRequest('POST', '/api/me/inquiries/'.$inquiryId.'/decision', ['decision' => 'accept'], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertSame('accepted', $this->payload()['data']['status']);
        self::assertEmailCount(1);
        $acceptedEmail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $acceptedEmail);
        self::assertSame('inquiry-company@example.test', $acceptedEmail->getTo()[0]->getAddress());
        self::assertEmailHtmlBodyContains($acceptedEmail, 'Inquiry Creator');
        self::assertEmailHtmlBodyContains(
            $acceptedEmail,
            'We would love to feature our new local ingredients in your recipe series.',
        );
        self::assertEmailHtmlBodyContains($acceptedEmail, '?inquiry='.$inquiryId);
        $this->client->request('GET', '/api/me/inquiries/'.$inquiryId.'/messages?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->payload()['data']);

        $this->jsonRequest('POST', '/api/me/inquiries/'.$inquiryId.'/messages', ['body' => 'Thanks, let us discuss the timeline.'], $this->csrfToken());
        self::assertResponseStatusCodeSame(201);
        $this->client->loginUser($companyUser, 'main');
        $this->client->request('GET', '/api/me/inquiries?locale=en');
        self::assertResponseIsSuccessful();
        $acceptedInquiry = array_values(array_filter($this->payload()['data'], static fn (array $item): bool => $item['id'] === $inquiryId))[0];
        self::assertSame('Thanks, let us discuss the timeline.', $acceptedInquiry['lastMessage']);
        self::assertTrue($acceptedInquiry['canChat']);
        $this->client->request('GET', '/api/me/notifications?locale=en');
        self::assertResponseIsSuccessful();
        self::assertContains(
            'creator_inquiry_accepted',
            array_column($this->payload()['data'], 'type'),
        );
        $this->client->request('GET', '/api/me/inquiries/'.$inquiryId.'/messages?locale=bs');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->payload()['data']);

        $managedCreator = $entityManager->getRepository(Creator::class)->find($creator->getId());
        $managedCompany = $entityManager->getRepository(Company::class)->find($company->getId());
        self::assertInstanceOf(Creator::class, $managedCreator);
        self::assertInstanceOf(Company::class, $managedCompany);
        $pending = new CreatorInquiry(
            $managedCreator,
            $managedCompany,
            null,
            null,
            null,
            null,
            'Could you share availability for an upcoming product launch?',
        );
        $entityManager->persist($pending);
        $entityManager->flush();
        $this->client->request('GET', '/api/me/inquiries/'.$pending->getId().'/messages?locale=bs');
        self::assertResponseStatusCodeSame(409);
        $this->client->loginUser($creatorUser, 'main');
        $this->jsonRequest('POST', '/api/me/inquiries/'.$pending->getId().'/decision', ['decision' => 'reject'], $this->csrfToken());
        self::assertResponseIsSuccessful();
        self::assertSame('rejected', $this->payload()['data']['status']);
        $this->client->request('GET', '/api/me/inquiries/'.$pending->getId().'/messages?locale=bs');
        self::assertResponseStatusCodeSame(409);
    }

    public function testModeratorCanManageLocalizedCategoriesAndFaqs(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $moderator = new User('catalog-moderator@example.test', 'ROLE_CREATOR');
        $moderator->setPassword('unused-test-hash');
        $moderator->setModerator(true);
        $regularUser = new User('catalog-user@example.test', 'ROLE_CREATOR');
        $regularUser->setPassword('unused-test-hash');
        $entityManager->persist($moderator);
        $entityManager->persist($regularUser);
        $entityManager->flush();

        $this->client->loginUser($regularUser, 'main');
        $this->client->request('GET', '/api/moderation/catalog?locale=en');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($moderator, 'main');
        $this->jsonRequest('POST', '/api/moderation/catalog/categories', [
            'value' => 'Photography',
            'labels' => ['bs' => 'Fotografija', 'hr' => 'Fotografija', 'sr' => 'Fotografija', 'cnr' => 'Fotografija', 'sl' => 'Fotografija', 'en' => 'Photography'],
            'position' => 12,
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(201);
        $categoryId = $this->payload()['data']['id'];

        $this->client->request('GET', '/api/marketplace/categories?locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame('Photography', $this->payload()['data'][0]['label']);

        $this->jsonRequest('POST', '/api/moderation/catalog/faqs', [
            'questions' => [
                'bs' => 'Kako se dogovara saradnja?',
                'hr' => 'Kako se dogovara suradnja?',
                'sr' => 'Kako se dogovara saradnja?',
                'cnr' => 'Kako se dogovara saradnja?',
                'sl' => 'Kako se dogovori sodelovanje?',
                'en' => 'How is a collaboration arranged?',
            ],
            'answers' => [
                'bs' => 'Nakon prihvatanja upita, učesnici mogu razgovarati privatno.',
                'hr' => 'Nakon prihvaćanja upita, sudionici mogu razgovarati privatno.',
                'sr' => 'Nakon prihvatanja upita, učesnici mogu razgovarati privatno.',
                'cnr' => 'Nakon prihvatanja upita, učesnici mogu razgovarati privatno.',
                'sl' => 'Po sprejemu povpraševanja se lahko udeleženci pogovarjajo zasebno.',
                'en' => 'After a request is accepted, both participants can chat privately.',
            ],
            'position' => 20,
        ], $this->csrfToken());
        self::assertResponseStatusCodeSame(201);
        $faqId = $this->payload()['data']['id'];

        $this->client->request('GET', '/api/marketplace/creator-faqs?locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame('How is a collaboration arranged?', $this->payload()['data'][0]['question']);

        $this->jsonRequest('PUT', '/api/moderation/catalog/categories/'.$categoryId, [
            'labels' => ['bs' => 'Fotografski radovi', 'hr' => 'Fotografski radovi', 'sr' => 'Fotografski radovi', 'cnr' => 'Fotografski radovi', 'sl' => 'Fotografska dela', 'en' => 'Photography'],
            'position' => 12,
            'active' => false,
        ], $this->csrfToken());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/marketplace/categories?locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload()['data']);

        $this->jsonRequest('PUT', '/api/moderation/catalog/faqs/'.$faqId, [
            'questions' => [
                'bs' => 'Kako počinje razgovor?',
                'hr' => 'Kako počinje razgovor?',
                'sr' => 'Kako počinje razgovor?',
                'cnr' => 'Kako počinje razgovor?',
                'sl' => 'Kako se začne pogovor?',
                'en' => 'How does the conversation start?',
            ],
            'answers' => [
                'bs' => 'Nakon prihvatanja upita, učesnici mogu razgovarati privatno.',
                'hr' => 'Nakon prihvaćanja upita, sudionici mogu razgovarati privatno.',
                'sr' => 'Nakon prihvatanja upita, učesnici mogu razgovarati privatno.',
                'cnr' => 'Nakon prihvatanja upita, učesnici mogu razgovarati privatno.',
                'sl' => 'Po sprejemu povpraševanja se lahko udeleženci pogovarjajo zasebno.',
                'en' => 'After a request is accepted, both participants can chat privately.',
            ],
            'position' => 20,
            'active' => true,
        ], $this->csrfToken());
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/marketplace/creator-faqs?locale=en');
        self::assertResponseIsSuccessful();
        self::assertSame('How does the conversation start?', $this->payload()['data'][0]['question']);
    }

    private function csrfToken(): string
    {
        $this->client->request('GET', '/api/auth/csrf?locale=bs');
        self::assertResponseIsSuccessful();

        return $this->payload()['csrfToken'];
    }

    private function uploadImage(string $folder, string $csrf): array
    {
        $path = tempnam(sys_get_temp_dir(), 'wave-upload-');
        self::assertNotFalse($path);
        imagepng(imagecreatetruecolor(1, 1), $path);
        $this->client->request(
            'POST',
            '/api/media?folder='.$folder.'&locale=bs',
            [],
            ['file' => new UploadedFile($path, 'portfolio.png', 'image/png', null, true)],
            ['HTTP_X_CSRF_TOKEN' => $csrf, 'HTTP_ACCEPT' => 'application/json'],
        );
        if (is_file($path)) {
            unlink($path);
        }

        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function jsonRequest(string $method, string $path, array $body, string $csrf): void
    {
        $this->client->request($method, $path.'?locale=bs', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf,
        ], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function payload(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
