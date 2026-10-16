<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Account\AccountEmailSender;
use App\Account\EmailTemplateRenderer;
use App\Background\PayloadCipher;
use App\Credits\CreditException;
use App\Credits\CreditService;
use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CreditsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $adminId;
    private int $creatorId;
    private int $companyId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $em = $this->em();
        $schema = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $admin = new User('credits-admin@example.test', 'ROLE_COMPANY');
        $admin->setAdmin(true);
        $creator = new User('credits-creator@example.test', 'ROLE_CREATOR');
        $creator->setCreator(new Creator('credits-creator', 'Credit Creator', 'Food', 'Sarajevo', '', []));
        $company = new User('credits-company@example.test', 'ROLE_COMPANY');
        $company->setCompany(new Company('credits-company', 'Credit Company', 'Food'));
        foreach ([$admin, $creator, $company] as $user) {
            $user->setPassword('unused');
            $em->persist($user);
        }
        $em->flush();
        $this->adminId = $admin->getId();
        $this->creatorId = $creator->getId();
        $this->companyId = $company->getId();
    }

    public function testDefaultIsFreeAndEveryActivationGrantsAndQueuesExactlyOnce(): void
    {
        $credits = $this->credits();
        self::assertFalse($credits->settings()['paid']);
        self::assertSame([1000, 2000, 4000], array_column($credits->settings()['packs'], 'priceMinor'));
        self::assertTrue($credits->account($this->user($this->creatorId))['unlimited']);
        $first = $credits->saveSettings([...$credits->settings(), 'paid' => true, 'welcomeGrant' => 50]);
        self::assertSame(1, $first['activation']);
        self::assertSame(50, $credits->account($this->user($this->creatorId))['balance']);
        self::assertSame(3, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM credit_announcement'));
        self::assertSame(3, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM background_job WHERE type = 'CreditsAnnouncementMessage'"));
        $changed = $credits->saveSettings([...$first, 'unitPriceMinor' => 25]);
        self::assertSame(1, $changed['activation']);
        self::assertSame(50, $credits->account($this->user($this->creatorId))['balance']);
        $off = $credits->saveSettings([...$changed, 'paid' => false]);
        self::assertTrue($credits->account($this->user($this->creatorId))['unlimited']);
        self::assertSame(50, $credits->account($this->user($this->creatorId))['balance']);
        $second = $credits->saveSettings([...$off, 'paid' => true, 'welcomeGrant' => 20]);
        self::assertSame(2, $second['activation']);
        self::assertSame(70, $credits->account($this->user($this->creatorId))['balance']);
        self::assertSame(6, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM credit_announcement'));
        self::assertSame(6, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM background_job WHERE type = 'CreditsAnnouncementMessage'"));
        $new = new User('credits-new@example.test', 'ROLE_CREATOR');
        $new->setPassword('unused');
        $this->em()->persist($new);
        $this->em()->flush();
        self::assertSame(0, $credits->account($new)['balance']);
    }

    public function testVoucherIsHashedSingleUseAndKeepsIssuedPrice(): void
    {
        $credits = $this->credits();
        $voucher = $credits->issue($this->user($this->adminId), 50);
        self::assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $voucher['code']);
        self::assertSame(1000, $voucher['priceMinor']);
        self::assertSame(hash('sha256', $voucher['code']), $this->db()->fetchOne('SELECT code_hash FROM credit_voucher'));
        $credits->saveSettings([...$credits->settings(), 'unitPriceMinor' => 30]);
        self::assertSame(1000, (int) $this->db()->fetchOne('SELECT price_minor FROM credit_voucher'));
        self::assertSame(50, $credits->redeem($this->user($this->creatorId), strtolower($voucher['code'])));
        self::assertSame(50, $credits->account($this->user($this->creatorId))['balance']);
        self::assertTrue($credits->account($this->user($this->creatorId))['unlimited']);
        try {
            $credits->redeem($this->user($this->companyId), $voucher['code']);
            self::fail('Used code must not credit a second user.');
        } catch (CreditException $error) {
            self::assertSame('credit_invalid_code', $error->key);
        }
        self::assertSame(0, $credits->account($this->user($this->companyId))['balance']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM credit_entry'));
        $revoked = $credits->issue($this->user($this->adminId), 100);
        $credits->revoke($revoked['id']);
        $this->expectException(CreditException::class);
        $credits->redeem($this->user($this->creatorId), $revoked['code']);
    }

    public function testSpendRollsBackBalanceAndDomainWriteOnFailureAndPreventsOverdraft(): void
    {
        $credits = $this->credits();
        $creator = $this->user($this->creatorId);
        $credits->saveSettings([...$credits->settings(), 'paid' => true, 'welcomeGrant' => 10]);
        try {
            $credits->spend($creator, 'application', function (): array {
                $this->db()->executeStatement('UPDATE wave_user SET phone = ? WHERE id = ?', ['failed-write', $this->creatorId]);
                throw new \RuntimeException('Simulated write failure');
            });
            self::fail('Expected a failed domain write.');
        } catch (\RuntimeException $error) {
            self::assertSame('Simulated write failure', $error->getMessage());
        }
        self::assertSame(10, $credits->account($creator)['balance']);
        self::assertNull($this->db()->fetchOne('SELECT phone FROM wave_user WHERE id = ?', [$this->creatorId]));
        self::assertSame(1, $credits->account($creator)['total']);
        $credits->spend($creator, 'application', fn (): array => ['id' => 1, 'title' => 'Test']);
        self::assertSame(0, $credits->account($creator)['balance']);
        $called = false;
        try {
            $credits->spend($creator, 'application', function () use (&$called): array { $called = true; return ['id' => 2, 'title' => 'Test']; });
            self::fail('Must reject an overdraft.');
        } catch (CreditException $error) {
            self::assertSame(402, $error->status);
        }
        self::assertFalse($called);
        self::assertSame(0, $credits->account($creator)['balance']);
        self::assertSame(2, $credits->account($creator)['total']);
        $credits->saveSettings([...$credits->settings(), 'paid' => false]);
        $credits->spend($creator, 'campaign', fn (): array => ['id' => 3, 'title' => 'Free']);
        self::assertSame(2, $credits->account($creator)['total']);
    }

    public function testSettingsValidateBoundsAndRejectStaleSaves(): void
    {
        $credits = $this->credits();
        $old = $credits->settings();
        foreach ([['unitPriceMinor' => 0], ['applicationCost' => -1], ['campaignCost' => 1.5], ['welcomeGrant' => 100001], ['paid' => 'true']] as $invalid) {
            try {
                $credits->saveSettings([...$old, ...$invalid]);
                self::fail('Expected invalid settings.');
            } catch (CreditException $error) {
                self::assertSame('credit_invalid_settings', $error->key);
            }
        }
        $credits->saveSettings([...$old, 'welcomeGrant' => 15]);
        $this->expectException(CreditException::class);
        $credits->saveSettings([...$old, 'paid' => true]);
    }

    public function testEndpointsEnforceAuthAdminCsrfVerificationAndRateLimit(): void
    {
        $this->client->request('GET', '/api/me/credits?locale=en');
        self::assertResponseStatusCodeSame(401);
        $this->client->loginUser($this->user($this->creatorId));
        $this->client->request('GET', '/api/admin/credits/settings?locale=en');
        self::assertResponseStatusCodeSame(403);
        $this->request('POST', '/api/me/credits/redeem', ['code' => 'AAAAAAAA'], false);
        self::assertResponseStatusCodeSame(403);
        $user = $this->user($this->creatorId);
        $user->setEmailVerified(false);
        $this->em()->flush();
        $this->request('POST', '/api/me/credits/redeem', ['code' => 'AAAAAAAA']);
        self::assertResponseStatusCodeSame(403);
        $user = $this->user($this->creatorId);
        $user->setEmailVerified(true);
        $this->em()->flush();
        for ($i = 0; $i < 10; ++$i) {
            $this->request('POST', '/api/me/credits/redeem', ['code' => 'AAAAAAAA']);
            self::assertResponseStatusCodeSame(400);
        }
        $this->request('POST', '/api/me/credits/redeem', ['code' => 'AAAAAAAA']);
        self::assertResponseStatusCodeSame(429);
    }

    public function testApplicationChargesOnlySuccessfulNewApplication(): void
    {
        $campaign = new Campaign('credits-campaign', 'Credits campaign', 'Summary', 'Campaign description', 'Food', ['Instagram'], ['Post'], 10, 20, 'Sarajevo', 4, new \DateTimeImmutable('+1 month'), new \DateTimeImmutable(), $this->user($this->companyId)->getCompany());
        $this->em()->persist($campaign);
        $this->em()->flush();
        $this->credits()->saveSettings([...$this->credits()->settings(), 'paid' => true, 'welcomeGrant' => 50]);
        $this->client->loginUser($this->user($this->creatorId));
        $this->request('POST', '/api/campaigns/credits-campaign/applications', ['message' => 'short']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(50, $this->credits()->account($this->user($this->creatorId))['balance']);
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT application_count FROM campaign WHERE slug = 'credits-campaign'"));
        $this->request('POST', '/api/campaigns/credits-campaign/applications', ['message' => 'I would like to apply to this campaign.']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(40, $this->credits()->account($this->user($this->creatorId))['balance']);
        $this->request('POST', '/api/campaigns/credits-campaign/applications', ['message' => 'I would like to apply to this campaign.']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(40, $this->credits()->account($this->user($this->creatorId))['balance']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM campaign_application'));
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT application_count FROM campaign WHERE slug = 'credits-campaign'"));
    }

    public function testPublishingChargesOnlyNewCampaignAndEditingIsFree(): void
    {
        $this->client->loginUser($this->user($this->companyId));
        $body = ['title' => 'Credits campaign', 'summary' => 'A thoughtful campaign for creators.', 'description' => '<p>Share a warm story with our thoughtful campaign.</p>', 'categories' => ['Food'], 'channels' => ['Instagram'], 'deliverables' => ['One post'], 'budgetMin' => 100, 'budgetMax' => 200, 'currency' => 'BAM', 'city' => 'Sarajevo', 'countryCode' => 'BA', 'creatorCount' => 3, 'closesAt' => (new \DateTimeImmutable('+1 month'))->format('Y-m-d')];
        $this->request('POST', '/api/company/campaigns', $body);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(0, $this->credits()->account($this->user($this->companyId))['total']);
        $this->credits()->saveSettings([...$this->credits()->settings(), 'paid' => true, 'welcomeGrant' => 50]);
        $this->request('POST', '/api/company/campaigns', [...$body, 'title' => '']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(50, $this->credits()->account($this->user($this->companyId))['balance']);
        $this->request('POST', '/api/company/campaigns', $body);
        self::assertResponseStatusCodeSame(201);
        $id = $this->payload()['data']['id'];
        self::assertSame(20, $this->credits()->account($this->user($this->companyId))['balance']);
        $this->request('PUT', '/api/company/campaigns/' . $id, [...$body, 'title' => 'Edited campaign', 'status' => 'open']);
        self::assertResponseIsSuccessful();
        self::assertSame(20, $this->credits()->account($this->user($this->companyId))['balance']);
        $this->request('POST', '/api/company/campaigns', $body);
        self::assertResponseStatusCodeSame(402);
        self::assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM campaign'));
        self::assertSame(20, $this->credits()->account($this->user($this->companyId))['balance']);
    }

    public function testHistoryPaginationIsPrivateAndChronological(): void
    {
        $credits = $this->credits();
        $creator = $this->user($this->creatorId);
        for ($i = 0; $i < 12; ++$i) {
            $voucher = $credits->issue($this->user($this->adminId), 50);
            $credits->redeem($creator, $voucher['code']);
        }
        $first = $credits->account($creator);
        self::assertSame(12, $first['total']);
        self::assertCount(10, $first['history']);
        self::assertSame(600, $first['history'][0]['balanceAfter']);
        $second = $credits->account($creator, 2);
        self::assertCount(2, $second['history']);
        self::assertSame(50, $second['history'][1]['balanceAfter']);
        self::assertSame(0, $credits->account($this->user($this->companyId))['total']);
        self::assertStringNotContainsString('code_hash', json_encode($credits->vouchers(1)));
    }

    public function testHistoryTypeFiltersBeforePaginationAndKeepsTheFullBalance(): void
    {
        $credits = $this->credits();
        $creator = $this->user($this->creatorId);
        $credits->saveSettings([...$credits->settings(), 'paid' => true, 'welcomeGrant' => 1000]);
        $voucher = $credits->issue($this->user($this->adminId), 50);
        $credits->redeem($creator, $voucher['code']);
        for ($i = 0; $i < 12; ++$i) {
            $credits->spend($creator, 'application', static fn (): array => ['id' => $i + 1, 'title' => 'Campaign ' . $i]);
        }

        $this->client->loginUser($creator);
        $this->client->request('GET', '/api/me/credits?locale=en&type=spent&page=2');
        self::assertResponseIsSuccessful();
        $spent = $this->payload()['data'];
        self::assertSame(12, $spent['total']);
        self::assertSame(2, $spent['page']);
        self::assertCount(2, $spent['history']);
        self::assertSame([-10, -10], array_column($spent['history'], 'amount'));
        self::assertSame(930, $spent['balance']);

        $this->client->request('GET', '/api/me/credits?locale=en&type=added&page=2');
        $added = $this->payload()['data'];
        self::assertSame(2, $added['total']);
        self::assertSame(1, $added['page']);
        self::assertSame([50, 1000], array_column($added['history'], 'amount'));
        self::assertSame(930, $added['balance']);

        $this->client->loginUser($this->user($this->companyId));
        $this->client->request('GET', '/api/me/credits?locale=en&type=spent');
        self::assertSame(0, $this->payload()['data']['total']);
    }

    public function testAnnouncementsRenderInAllLocalesAndRetryQueuesOnce(): void
    {
        $renderer = self::getContainer()->get(EmailTemplateRenderer::class);
        $sender = self::getContainer()->get(AccountEmailSender::class);
        foreach (['bs', 'hr', 'sr', 'cnr', 'sl', 'en'] as $locale) {
            $html = $renderer->render('credits_activated', $sender->previewVariables('credits_activated', $locale));
            self::assertStringContainsString('2026-10-07', $html);
            self::assertStringNotContainsString('{{', $html);
            self::assertNotEmpty($sender->defaultSubject('credits_activated', $locale));
        }
        $credits = $this->credits();
        $credits->saveSettings([...$credits->settings(), 'paid' => true, 'welcomeGrant' => 50]);
        $id = 'activation:1:' . $this->creatorId;
        $credits->announce($id);
        self::assertNotNull($this->db()->fetchOne('SELECT queued_at FROM credit_announcement WHERE id = ?', [$id]));
        $count = (int) $this->db()->fetchOne("SELECT COUNT(*) FROM background_job WHERE type = 'SendEmailMessage'");
        $credits->announce($id);
        self::assertSame($count, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM background_job WHERE type = 'SendEmailMessage'"));
        if ($count > 0) {
            $encrypted = $this->db()->fetchOne("SELECT payload FROM background_job WHERE type = 'SendEmailMessage' ORDER BY created_at DESC LIMIT 1");
            $payload = self::getContainer()->get(PayloadCipher::class)->decrypt($encrypted);
            self::assertStringContainsString('/racun/krediti', base64_decode($payload['raw']));
        }
    }

    public function testAssignmentSendsPersonalizedCodeButCreditsOnlyOnRecipientRedemption(): void
    {
        $voucher = $this->credits()->issue($this->user($this->adminId), 100);
        $stored = $this->db()->fetchOne('SELECT encrypted_code FROM credit_voucher WHERE id = ?', [$voucher['id']]);
        self::assertNotSame($voucher['code'], $stored);
        self::assertSame($voucher['code'], self::getContainer()->get(PayloadCipher::class)->decrypt($stored)['code']);
        $this->client->loginUser($this->user($this->adminId));
        $path = '/api/admin/credits/vouchers/'.$voucher['id'].'/assign';
        $body = ['accountType' => 'creator', 'userId' => $this->creatorId];
        $this->request('POST', $path, $body, false);
        self::assertResponseStatusCodeSame(403);
        $this->client->enableProfiler();
        $this->request('POST', $path, $body);
        self::assertResponseIsSuccessful();
        self::assertSame('assigned', $this->payload()['data']['status']);
        self::assertSame(0, $this->credits()->account($this->user($this->creatorId))['balance']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM credit_entry'));
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertEmailHtmlBodyContains($mail, 'Credit Creator');
        self::assertEmailHtmlBodyContains($mail, $voucher['code']);
        self::assertEmailHtmlBodyContains($mail, '100');
        self::assertEmailAddressContains($mail, 'to', 'credits-creator@example.test');
        $this->client->enableProfiler();
        $this->request('POST', $path, $body);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
        $this->request('POST', $path, ['accountType' => 'company', 'userId' => $this->companyId]);
        self::assertResponseStatusCodeSame(409);
        try {
            $this->credits()->redeem($this->user($this->companyId), $voucher['code']);
            self::fail('Assigned codes cannot be redeemed by other accounts.');
        } catch (CreditException $error) { self::assertSame('credit_invalid_code', $error->key); }
        self::assertSame(100, $this->credits()->redeem($this->user($this->creatorId), $voucher['code']));
        self::assertSame('used', $this->credits()->vouchers(1)['rows'][0]['status']);
        $this->request('POST', $path, $body);
        self::assertResponseStatusCodeSame(409);
        $this->request('DELETE', '/api/admin/credits/vouchers', ['ids' => [$voucher['id']]]);
        self::assertResponseIsSuccessful();
        self::assertSame(100, $this->credits()->account($this->user($this->creatorId))['balance']);
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM credit_entry'));
    }

    public function testFullCodeHistorySortingPaginationDeletionAndLegacyCompatibility(): void
    {
        $codes = [];
        for ($i = 0; $i < 27; ++$i) { $codes[] = $this->credits()->issue($this->user($this->adminId), $i < 25 ? 50 : 200); }
        $first = $this->credits()->vouchers(1, 25, 'amount', 'desc');
        self::assertSame(27, $first['total']);
        self::assertCount(25, $first['rows']);
        self::assertSame(200, $first['rows'][0]['amount']);
        foreach ($first['rows'] as $row) {
            self::assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $row['code']);
            self::assertArrayNotHasKey('code_hash', $row);
            self::assertArrayNotHasKey('encrypted_code', $row);
        }
        self::assertSame(2, $this->credits()->vouchers(999)['page']);
        self::assertCount(27, $this->credits()->vouchers(1, 50)['rows']);
        $legacy = $codes[0];
        $this->db()->update('credit_voucher', ['encrypted_code' => null], ['id' => $legacy['id']]);
        try {
            $this->credits()->assign($legacy['id'], $this->creatorId, 'creator');
            self::fail('Legacy hashes cannot be mailed as full codes.');
        } catch (CreditException $error) { self::assertSame('credit_code_unavailable', $error->key); }
        self::assertSame(50, $this->credits()->redeem($this->user($this->creatorId), $legacy['code']));
        self::assertSame(2, $this->credits()->deleteVouchers([$codes[1]['id'], $codes[2]['id']]));
        try {
            $this->credits()->redeem($this->user($this->creatorId), $codes[1]['code']);
            self::fail('Deleted unused vouchers are invalid.');
        } catch (CreditException $error) { self::assertSame('credit_invalid_code', $error->key); }
        $this->client->loginUser($this->user($this->adminId));
        $this->client->request('GET', '/api/admin/credits/vouchers?sort=amount;DROP%20TABLE&locale=en');
        self::assertResponseStatusCodeSame(400);
        $this->client->loginUser($this->user($this->creatorId));
        $this->client->request('GET', '/api/admin/credits/vouchers?locale=en');
        self::assertResponseStatusCodeSame(403);
    }

    public function testRecipientSearchUsesUserIdsAndSignedTwentyRowCursorBatches(): void
    {
        $em = $this->em();
        for ($i = 0; $i < 43; ++$i) {
            $user = new User(sprintf('recipient-%02d@example.test', $i), 'ROLE_CREATOR');
            $user->setCreator(new Creator('recipient-'.$i, sprintf('Recipient %02d', $i), 'Food', 'Sarajevo', '', []));
            $user->setPassword('unused');
            $em->persist($user);
        }
        $em->flush();
        $this->client->loginUser($this->user($this->adminId));
        $base = '/api/admin/credits/recipients?locale=en&accountType=creator&q=Recipient&limit=20';
        $all = [];
        $cursor = null;
        foreach ([20, 20, 3] as $size) {
            $this->client->request('GET', $base.($cursor ? '&cursor='.urlencode($cursor) : ''));
            self::assertResponseIsSuccessful();
            $data = $this->payload()['data'];
            self::assertCount($size, $data['rows']);
            foreach ($data['rows'] as $row) {
                self::assertSame(['id', 'name', 'email'], array_keys($row));
                self::assertSame($row['email'], $this->db()->fetchOne('SELECT email FROM wave_user WHERE id = ?', [$row['id']]));
                $all[] = $row['id'];
            }
            $cursor = $data['nextCursor'];
        }
        self::assertCount(43, array_unique($all));
        self::assertNull($cursor);
        $this->client->request('GET', $base);
        $cursor = $this->payload()['data']['nextCursor'];
        $this->client->request('GET', '/api/admin/credits/recipients?locale=en&accountType=company&q=Recipient&cursor='.urlencode($cursor));
        self::assertResponseStatusCodeSame(400);
        $this->client->request('GET', '/api/admin/credits/recipients?locale=en&accountType=creator&q=recipient-42@');
        self::assertSame('Recipient 42', $this->payload()['data']['rows'][0]['name']);
        $this->client->request('GET', '/api/admin/credits/recipients?locale=en&accountType=company');
        self::assertSame('Credit Company', $this->payload()['data']['rows'][0]['name']);
        $this->client->loginUser($this->user($this->creatorId));
        $this->client->request('GET', $base);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAssignmentEmailQueueIsEncryptedAndFailureRollsBackReservation(): void
    {
        $inner = $this->createMock(\Symfony\Component\Mailer\MailerInterface::class);
        $inner->expects(self::never())->method('send');
        $queued = new \App\Background\QueuedMailer(self::getContainer()->get(\App\Background\JobDispatcher::class), $inner, true);
        $service = $this->creditsWithMailer($queued);
        $voucher = $service->issue($this->user($this->adminId), 50);
        $service->assign($voucher['id'], $this->companyId, 'company');
        $encrypted = $this->db()->fetchOne("SELECT payload FROM background_job WHERE type = 'SendEmailMessage'");
        self::assertIsString($encrypted);
        self::assertStringNotContainsString($voucher['code'], $encrypted);
        $payload = self::getContainer()->get(PayloadCipher::class)->decrypt($encrypted);
        self::assertSame(['credits-company@example.test'], $payload['recipients']);
        self::assertStringContainsString($voucher['code'], quoted_printable_decode(base64_decode($payload['raw'])));
        self::assertSame(0, $service->account($this->user($this->companyId))['balance']);
        $failed = $this->createMock(\Symfony\Component\Mailer\MailerInterface::class);
        $failed->expects(self::once())->method('send')->willThrowException(new \Symfony\Component\Mailer\Exception\TransportException('Mailer unavailable'));
        $voucher = $service->issue($this->user($this->adminId), 100);
        try {
            $this->creditsWithMailer($failed)->assign($voucher['id'], $this->creatorId, 'creator');
            self::fail('A failed email must not reserve a code.');
        } catch (\Symfony\Component\Mailer\Exception\TransportException) {
            self::assertNull($this->db()->fetchOne('SELECT assigned_at FROM credit_voucher WHERE id = ?', [$voucher['id']]));
            self::assertNull($this->db()->fetchOne('SELECT assigned_to_id FROM credit_voucher WHERE id = ?', [$voucher['id']]));
        }
    }

    private function creditsWithMailer(\Symfony\Component\Mailer\MailerInterface $mailer): CreditService
    {
        $sender = new AccountEmailSender($mailer, self::getContainer()->get(EmailTemplateRenderer::class), $this->em(), dirname(__DIR__, 2).'/config/localized_routes.json', 'no-reply@wave.ba', 'Wave', 'http://127.0.0.1:8000');
        return new CreditService($this->em(), self::getContainer()->get(\App\Background\JobDispatcher::class), $sender, self::getContainer()->get(PayloadCipher::class));
    }

    public function testCreditAssignmentEmailTemplatesRenderAllLanguages(): void
    {
        $sender = self::getContainer()->get(AccountEmailSender::class);
        $renderer = self::getContainer()->get(EmailTemplateRenderer::class);
        foreach (['bs', 'hr', 'sr', 'cnr', 'sl', 'en'] as $locale) {
            $variables = $sender->previewVariables('credit_code_assigned', $locale);
            $html = $renderer->render('credit_code_assigned', $variables);
            self::assertStringContainsString('Amina Kovač', $html);
            self::assertStringContainsString('WAVE2345', $html);
            self::assertStringContainsString('100', $html);
            self::assertStringNotContainsString('{{', $html);
        }
    }

    private function request(string $method, string $path, array $body, bool $csrf = true): void
    {
        $token = '';
        if ($csrf) {
            $this->client->request('GET', '/api/auth/csrf?locale=en');
            $token = $this->payload()['csrfToken'];
        }
        $this->client->jsonRequest($method, $path . '?locale=en', $body, ['HTTP_X_CSRF_TOKEN' => $token]);
    }

    private function payload(): array { return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR); }
    private function em(): EntityManagerInterface { return self::getContainer()->get(EntityManagerInterface::class); }
    private function db(): \Doctrine\DBAL\Connection { return $this->em()->getConnection(); }
    private function credits(): CreditService { return self::getContainer()->get(CreditService::class); }
    private function user(int $id): User { return $this->em()->find(User::class, $id); }
}
