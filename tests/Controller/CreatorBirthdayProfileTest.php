<?php

namespace App\Tests\Controller;

use App\Entity\Creator;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CreatorBirthdayProfileTest extends WebTestCase
{
    public function testBirthdayIsPrivateAndCanBePreservedUpdatedOrCleared(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $user = new User('birthday@example.test', 'ROLE_CREATOR');
        $user->setPassword('unused');
        $user->setApproved(true);
        $user->setEmailVerified(true);
        $creator = new Creator('birthday-creator', 'Birthday Creator', 'Travel', 'Sarajevo', '', []);
        $creator->setBirthday(new \DateTimeImmutable('2000-02-29'));
        $user->setCreator($creator);
        $em->persist($user);
        $em->persist($creator);
        $em->flush();
        $client->loginUser($user, 'main');
        $client->request('GET', '/api/auth/me?locale=en');
        self::assertSame('2000-02-29', json_decode($client->getResponse()->getContent(), true)['data']['profile']['birthday']);
        $client->request('GET', '/api/creators/birthday-creator?locale=en');
        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('birthday', json_decode($client->getResponse()->getContent(), true)['data']);
        $client->request('GET', '/api/auth/csrf?locale=en');
        $csrf = json_decode($client->getResponse()->getContent(), true)['csrfToken'];
        foreach ([[], ['birthday' => '2023-02-29'], ['birthday' => '1995-03-15'], ['birthday' => null]] as $index => $change) {
            $client->request('PUT', '/api/me/profile?locale=en', server: [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf,
            ], content: json_encode(['displayName' => 'Birthday Creator', 'categories' => ['Travel'], 'bio' => '', 'tags' => [], 'socialProfiles' => [], 'avatarUrl' => null] + $change, JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame($index === 1 ? 400 : 200);
            $client->request('GET', '/api/auth/me?locale=en');
            self::assertSame(match ($index) { 0, 1 => '2000-02-29', 2 => '1995-03-15', 3 => null }, json_decode($client->getResponse()->getContent(), true)['data']['profile']['birthday']);
        }
    }
}
