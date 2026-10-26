<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\{Application, Campaign, CampaignInvitation, Company, Creator, Notification, User};
use App\Localization\ApiMessages;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\WebTestCase};

final class AdminCampaignInvitationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private Campaign $campaign;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata); $tool->createSchema($metadata);
        $this->admin = $this->user('admin', 'ROLE_COMPANY');
        $this->admin->setAdmin(true);
        $this->admin->setCompany(new Company('wave-ba', 'Wave.ba', 'Technology'));
        $this->em->flush();
        $this->campaign = $this->campaign('predstavi-wave', $this->admin->getCompany());
    }

    public function testAdminPagesEligibleCreatorsAndInvitesSelectedOrAll(): void
    {
        $creators = [];
        foreach (range(1, 24) as $number) {
            $creators[] = $this->creator(sprintf('creator-%02d', $number), sprintf('Creator %02d', $number));
        }
        $this->creator('hidden', 'Hidden Creator')->getOwner()->setHideMyAccount(true);
        $this->creator('unverified', 'Unverified Creator')->getOwner()->setEmailVerified(false);
        $this->em->persist(new Application($this->campaign, $creators[0], 'I already applied to this campaign.'));
        $this->em->flush();

        $brand = $this->user('brand', 'ROLE_COMPANY');
        $brand->setCompany(new Company('brand', 'Brand', 'Beauty')); $this->em->flush();
        $paidElsewhere = $this->campaign('brand-campaign', $brand->getCompany());
        $this->client->loginUser($brand);
        $this->client->request('GET', '/api/admin/campaign-invitations'); self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/api/admin/campaign-invitations');
        self::assertResponseIsSuccessful();
        self::assertSame([$this->campaign->getId()], array_column($this->payload()['data'], 'id'));
        self::assertTrue($this->payload()['data'][0]['voluntary']);
        $this->client->request('GET', '/api/admin/campaign-invitations/'.$paidElsewhere->getId().'/creators'); self::assertResponseStatusCodeSame(404);

        $url = '/api/admin/campaign-invitations/'.$this->campaign->getId();
        $this->client->request('GET', $url.'/creators');
        self::assertResponseIsSuccessful();
        $first = $this->payload()['data'];
        self::assertCount(20, $first['rows']); self::assertTrue($first['hasMore']);
        self::assertSame(24, $first['total']); self::assertSame(23, $first['available']);
        self::assertSame('Creator 01', $first['rows'][0]['name']); self::assertSame('applied', $first['rows'][0]['status']);
        self::assertIsInt($first['rows'][0]['profileStrength']);
        $this->client->request('GET', $url.'/creators?cursor='.$first['nextCursor']);
        $second = $this->payload()['data'];
        self::assertSame(['Creator 21', 'Creator 22', 'Creator 23', 'Creator 24'], array_column($second['rows'], 'name'));
        self::assertFalse($second['hasMore']); self::assertNull($second['total']);
        $this->client->request('GET', $url.'/creators?q=creator%2007');
        self::assertSame(['Creator 07'], array_column($this->payload()['data']['rows'], 'name'));

        $message = "Pozdrav {{name}},\n\nPridruži se kampanji.\n\nS poštovanjem,\n{{ companyName }}";
        $this->post($url, ['message' => $message, 'creatorIds' => [$creators[0]->getId()]], 'X');
        self::assertResponseStatusCodeSame(403);
        $this->post($url, ['message' => 'Pozdrav {{ime}}, pridruži se.', 'creatorIds' => [$creators[1]->getId()]]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(ApiMessages::get('invitation_unknown_variable', 'bs'), $this->payload()['error']);
        $this->post($url, ['message' => $message, 'creatorIds' => [$creators[0]->getId(), $creators[1]->getId(), $creators[2]->getId()]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['invited' => 2, 'skipped' => 1], $this->payload()['data']);
        self::assertEmailCount(2);
        $email = self::getMailerMessage();
        self::assertInstanceOf(\Symfony\Component\Mime\Email::class, $email);
        self::assertEmailHtmlBodyContains($email, 'Predstavi Wave - rastimo zajedno');
        self::assertEmailHtmlBodyContains($email, 'Wave.ba');
        self::assertEmailHtmlBodyContains($email, 'Dobrovoljna saradnja — bez naknade');
        self::assertMatchesRegularExpression('/Pozdrav Creator 0[23],/', (string) $email->getTextBody());
        $invitation = $this->em->getRepository(CampaignInvitation::class)->findOneBy(['creator' => $creators[1]->getId()]);
        self::assertSame("Pozdrav Creator 02,\n\nPridruži se kampanji.\n\nS poštovanjem,\nWave.ba", $invitation->getMessage());
        self::assertSame($this->admin->getId(), $invitation->getInviter()->getId());

        $this->post($url, ['message' => $message, 'all' => true]);
        self::assertSame(['invited' => 21, 'skipped' => 0], $this->payload()['data']);
        self::assertEmailCount(21);
        $this->post($url, ['message' => $message, 'all' => true]);
        self::assertSame(['invited' => 0, 'skipped' => 0], $this->payload()['data']);
        self::assertSame(23, $this->em->getRepository(CampaignInvitation::class)->count([]));
        self::assertSame(23, $this->em->getRepository(Notification::class)->count(['type' => 'campaign_invitation']));
        $this->client->request('GET', $url.'/creators');
        self::assertSame(0, $this->payload()['data']['available']);

        $this->client->loginUser($this->em->find(User::class, $creators[1]->getOwner()->getId()));
        $this->client->request('GET', '/api/me/notifications');
        self::assertResponseIsSuccessful();
        $notification = $this->payload()['data'][0];
        self::assertSame('campaign_invitation', $notification['type']);
        self::assertSame('voluntary', $notification['campaign']['compensationType']);
    }

    private function post(string $url, array $body, ?string $token = null): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $this->client->jsonRequest('POST', $url, $body, ['HTTP_X_CSRF_TOKEN' => $token ?? $this->payload()['csrfToken']]);
    }

    private function campaign(string $slug, Company $company): Campaign
    {
        $campaign = new Campaign($slug, 'Predstavi Wave - rastimo zajedno', 'Podijeli Wave na svojim mrežama.', '<p>Objavi video i označi Wave profil.</p>', 'Technology', ['Instagram'], ['1 objava'], 0, 0, 'Sarajevo', 50, new DateTimeImmutable('+30 days'), new DateTimeImmutable('today'), $company, compensationType: Campaign::COMPENSATION_VOLUNTARY);
        $this->em->persist($campaign); $this->em->flush();

        return $campaign;
    }

    private function creator(string $slug, string $name): Creator
    {
        $owner = $this->user($slug, 'ROLE_CREATOR');
        $owner->setCreator(new Creator($slug, $name, 'Lifestyle', 'Sarajevo', 'Biography', []));
        $this->em->flush();

        return $owner->getCreator();
    }

    private function user(string $key, string $role): User
    {
        $user = new User($key.'@example.test', $role);
        $user->setPassword('unused'); $user->setApproved(true); $user->setEmailVerified(true);
        $user->setPhone('+38761123456'); $user->setCity('Sarajevo'); $user->setCountryCode('BA');
        $this->em->persist($user); $this->em->flush();

        return $user;
    }

    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
