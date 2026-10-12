<?php

namespace App\Tests\Controller;

use App\Entity\Creator;
use App\Entity\User;
use App\Entity\UserPushSubscription;
use App\Service\UnreadInboxCounter;
use App\Service\WebPushNotificationSender;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PushAccountIsolationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    public function testLogoutDetachesOnlyThisDeviceAndNewAccountRebindsIt(): void
    {
        $a = $this->account('account-a');
        $b = $this->account('account-b');
        $payload = ['userId' => $a->getId(), 'endpoint' => 'https://web.push.apple.com/device-one', 'keys' => ['p256dh' => str_repeat('a', 40), 'auth' => str_repeat('b', 20)]];
        $other = new UserPushSubscription($a, 'https://web.push.apple.com/device-two', str_repeat('a', 40), str_repeat('b', 20), 'bs');
        $this->em->persist($other);
        $this->em->flush();
        $this->client->loginUser($a, 'main');
        $this->write('POST', '/api/me/push-subscriptions', $payload);
        self::assertResponseStatusCodeSame(201);
        $this->write('POST', '/api/auth/logout', []);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->em->getRepository(UserPushSubscription::class)->count(['user' => $a]));
        self::assertSame($other->getEndpoint(), $this->em->getRepository(UserPushSubscription::class)->findOneBy(['user' => $a])->getEndpoint());
        $this->client->loginUser($b, 'main');
        $this->write('POST', '/api/me/push-subscriptions', $payload);
        self::assertResponseStatusCodeSame(409);
        $payload['userId'] = $b->getId();
        $this->write('POST', '/api/me/push-subscriptions', $payload);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->em->getRepository(UserPushSubscription::class)->count(['user' => $b]));
        // A logout cannot detach somebody else's device by supplying its URL.
        $this->write('POST', '/api/auth/logout', ['pushEndpoint' => $other->getEndpoint()]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->em->getRepository(UserPushSubscription::class)->count(['user' => $a]));
        self::assertSame(0, $this->em->getRepository(UserPushSubscription::class)->count(['user' => $b]));
    }

    public function testExistingDeviceReassignmentRejectsOldQueuedPushAndLegacyLogoutDetachesEndpoint(): void
    {
        $a = $this->account('old-account');
        $b = $this->account('new-account');
        $endpoint = 'https://fcm.googleapis.com/fcm/send/shared-device';
        $subscription = new UserPushSubscription($a, $endpoint, str_repeat('a', 40), str_repeat('b', 20), 'bs');
        $this->em->persist($subscription);
        $this->em->flush();
        $id = $subscription->getId();
        $this->client->loginUser($b, 'main');
        $this->write('POST', '/api/me/push-subscriptions', ['userId' => $b->getId(), 'endpoint' => $endpoint, 'keys' => ['p256dh' => str_repeat('a', 40), 'auth' => str_repeat('b', 20)]]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(0, $this->em->getRepository(UserPushSubscription::class)->count(['user' => $a]));
        self::assertSame(1, $this->em->getRepository(UserPushSubscription::class)->count(['user' => $b]));
        $sender = new WebPushNotificationSender($this->em, new NullLogger(), '', '', '', new UnreadInboxCounter($this->em));
        // Blank keys would throw if this stale job ever reached the transport.
        $sender->deliver(['subscriptionId' => $id, 'userId' => $a->getId()]);
        // Simulate a device registered before session endpoint tracking existed.
        $this->client->getRequest()->getSession()->remove('wave_push_endpoint_hash');
        $this->write('POST', '/api/auth/logout', ['pushEndpoint' => $endpoint]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->em->getRepository(UserPushSubscription::class)->count([]));
        $sender->deliver(['subscriptionId' => $id, 'userId' => $b->getId()]);
    }

    private function account(string $slug): User
    {
        $user = new User($slug.'@example.test', 'ROLE_CREATOR');
        $user->setPassword('unused');
        $user->setCreator(new Creator($slug, $slug, 'Food', 'Sarajevo', '', []));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function write(string $method, string $path, array $data): void
    {
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        $csrf = json_decode($this->client->getResponse()->getContent(), true)['csrfToken'];
        $this->client->request($method, $path.'?locale=en', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], content: json_encode($data, JSON_THROW_ON_ERROR));
    }
}
