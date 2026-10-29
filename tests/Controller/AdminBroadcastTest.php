<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Admin\AdminBroadcast;
use App\Entity\{Company, Creator, NewsletterSubscriber, User};
use App\Localization\ApiMessages;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\WebTestCase};
use Symfony\Component\Mime\Email;

final class AdminBroadcastTest extends WebTestCase
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
        $this->admin = $this->user('admin', 'ROLE_COMPANY');
        $this->admin->setAdmin(true);
        $this->admin->setCompany(new Company('wave-ba', 'Wave.ba', 'Technology'));
        $this->em->flush();
    }

    public function testAdminSendsToEveryAudienceWithPersonalLinksAndUnsubscribeForNewsletter(): void
    {
        $amina = $this->creator('amina', 'Amina Kovač');
        $amina->getOwner()->setNewsletterEnabled(true);
        $lejla = $this->creator('lejla', 'Lejla');
        $lejla->getOwner()->setNewsletterEnabled(false);
        $this->admin->setNewsletterEnabled(false);
        $this->creator('unverified', 'Unverified')->getOwner()->setEmailVerified(false);
        $subscriber = new NewsletterSubscriber('reader@example.test', 'en');
        $subscriber->setLegalRecords(['newsletter' => ['enabled' => true]]);
        $this->em->persist($subscriber);
        $this->em->flush();
        $content = ['subject' => 'Link u bio, {{name}}', 'heading' => 'Neka vas brendovi pronađu', 'message' => "Pozdrav {{name}},\n\nstavite {{profileUrl}} u bio.\n\nWave tim", 'buttonLabel' => 'Otvorite svoj profil', 'buttonUrl' => '{{profileUrl}}'];

        $this->client->loginUser($lejla->getOwner());
        $this->client->request('GET', '/api/admin/broadcast/audience?role=all'); self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/api/admin/broadcast/audience?role=creators');
        self::assertSame(['all' => 2, 'newsletter' => 1], $this->payload()['data']);
        $this->client->request('GET', '/api/admin/broadcast/audience?role=all');
        self::assertSame(['all' => 3, 'newsletter' => 2], $this->payload()['data'], 'Newsletter counts the confirmed footer subscriber too.');
        $this->client->request('GET', '/api/admin/broadcast/users?q=amina');
        self::assertSame(['Amina Kovač'], array_column($this->payload()['data']['rows'], 'label'));

        $this->post(array_replace($content, ['audience' => 'users', 'role' => 'all', 'message' => 'Pozdrav {{ime}}, ovo je poruka.', 'userIds' => [$lejla->getOwner()->getId()]]));
        self::assertResponseStatusCodeSame(400);
        self::assertSame(ApiMessages::get('broadcast_unknown_variable', 'bs'), $this->payload()['error']);
        $this->post(array_replace($content, ['audience' => 'emails', 'role' => 'all', 'emails' => ['not-an-email']]));
        self::assertResponseStatusCodeSame(400);

        $this->post(array_replace($content, ['audience' => 'newsletter', 'role' => 'all']));
        self::assertResponseIsSuccessful();
        self::assertSame(['sent' => 2, 'skipped' => 0], $this->payload()['data']);
        $messages = array_values(array_filter(self::getMailerMessages(), static fn ($message): bool => $message instanceof Email));
        $byAddress = [];
        foreach ($messages as $message) $byAddress[$message->getTo()[0]->getAddress()] = $message;
        self::assertSame(['amina@example.test', 'reader@example.test'], array_keys($byAddress));
        $mail = $byAddress['amina@example.test'];
        self::assertSame('Link u bio, Amina Kovač', $mail->getSubject());
        self::assertStringContainsString('Pozdrav Amina Kovač,', (string) $mail->getTextBody());
        self::assertStringContainsString('/kreatori/amina', (string) $mail->getHtmlBody());
        self::assertTrue($mail->getHeaders()->has('List-Unsubscribe'));
        self::assertStringContainsString('Pozdrav,', (string) $byAddress['reader@example.test']->getTextBody(), 'No name: the comma stays tidy.');
        self::assertStringContainsString('Unsubscribe from Wave news', (string) $byAddress['reader@example.test']->getHtmlBody());

        $this->post(array_replace($content, ['audience' => 'emails', 'role' => 'all', 'emails' => ['LEJLA@example.test', 'partner@example.org']]));
        self::assertSame(['sent' => 2, 'skipped' => 0], $this->payload()['data']);
        $this->post(array_replace($content, ['audience' => 'users', 'role' => 'all', 'userIds' => [$lejla->getOwner()->getId(), 999999]]));
        self::assertSame(['sent' => 1, 'skipped' => 1], $this->payload()['data']);
        $this->post(array_replace($content, ['audience' => 'all', 'role' => 'all', 'test' => true]));
        self::assertSame(1, $this->payload()['data']['sent']);
    }

    public function testPersonaliseKeepsGreetingTidyWithoutName(): void
    {
        self::assertSame('Pozdrav,', AdminBroadcast::personalise('Pozdrav {{name}},', '', ''));
        self::assertSame('Pozdrav Amina, https://wave.ba/kreatori/amina', AdminBroadcast::personalise('Pozdrav {{ name }}, {{profileUrl}}', 'Amina', 'https://wave.ba/kreatori/amina'));
        self::assertSame(['ime'], AdminBroadcast::unknownVariables('{{ime}} {{name}}', '{{profileUrl}}'));
    }

    private function post(array $body): void
    {
        $this->client->request('GET', '/api/auth/csrf');
        $this->client->jsonRequest('POST', '/api/admin/broadcast', $body, ['HTTP_X_CSRF_TOKEN' => $this->payload()['csrfToken']]);
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
        $user->setCity('Sarajevo'); $user->setCountryCode('BA');
        $this->em->persist($user); $this->em->flush();

        return $user;
    }

    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
