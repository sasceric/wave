<?php

namespace App\Tests\Controller;

use App\Entity\Creator;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CreatorTypesTest extends WebTestCase
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

    public function testTypesCanBeSavedPreservedAndClearedAndInvalidValuesCannotOverwriteThem(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('types@example.test', 'ROLE_CREATOR');
        $user->setPassword('unused');
        $user->setApproved(true);
        $user->setEmailVerified(true);
        $creator = new Creator('types-creator', 'Types Creator', 'Travel', 'Sarajevo', '', []);
        $user->setCreator($creator);
        $em->persist($user);
        $em->persist($creator);
        $em->flush();
        $this->client->loginUser($user, 'main');
        $this->client->request('GET', '/api/auth/csrf?locale=en');
        $csrf = $this->response()['csrfToken'];
        $body = ['displayName' => 'Types Creator', 'categories' => ['Travel'], 'bio' => '', 'tags' => [], 'socialProfiles' => [], 'avatarUrl' => null];
        $update = function (array $change, int $status = 200) use ($body, $csrf): void {
            $this->client->request('PUT', '/api/me/profile?locale=en', server: [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf,
            ], content: json_encode($body + $change, JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame($status);
        };
        $update(['creatorTypes' => ['photographer', 'videographer', 'photographer']]);
        self::assertSame(['photographer', 'videographer'], $this->response()['data']['creatorTypes']);
        $update([]);
        self::assertSame(['photographer', 'videographer'], $this->response()['data']['creatorTypes']);
        foreach ([['unknown'], ['Instagram'], 'photographer', null, [1], ['type' => 'model']] as $invalid) {
            $update(['creatorTypes' => $invalid], 400);
            self::assertSame(['creatorTypes'], $this->response()['fields']);
            $this->client->request('GET', '/api/auth/me?locale=en');
            self::assertSame(['photographer', 'videographer'], $this->response()['data']['profile']['creatorTypes']);
        }
        $this->client->request('GET', '/api/creators/types-creator?locale=en');
        self::assertSame(['photographer', 'videographer'], $this->response()['data']['creatorTypes']);
        $update(['creatorTypes' => []]);
        self::assertSame([], $this->response()['data']['creatorTypes']);
    }

    public function testTypeFilterMatchesAnySelectedTypeAndCombinesWithTopicsAndVisibilityAndPagination(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ([
            ['alpha', ['photographer'], 'Travel', false],
            ['beta', ['influencer', 'videographer'], 'Travel', false],
            ['gamma', ['model'], 'Beauty', false],
            ['legacy', [], 'Travel', false],
            ['hidden', ['photographer'], 'Travel', true],
        ] as [$name, $types, $category, $hidden]) {
            $creator = new Creator($name, $name, $category, 'Sarajevo', '', []);
            $creator->setCreatorTypes($types);
            if ($hidden) {
                $user = new User('hidden-types@example.test', 'ROLE_CREATOR');
                $user->setPassword('unused');
                $user->setApproved(true);
                $user->setHideMyAccount(true);
                $user->setCreator($creator);
                $em->persist($user);
            }
            $em->persist($creator);
        }
        $em->flush();
        $params = ['locale' => 'en', 'sort' => 'name', 'pagination' => 'cursor', 'limit' => 1, 'view' => 'card', 'creatorTypes' => '["photographer","videographer","model"]', 'categories' => '["Travel"]'];
        $this->client->request('GET', '/api/creators?' . http_build_query($params));
        self::assertResponseIsSuccessful();
        $first = $this->response();
        self::assertSame(2, $first['meta']['total']);
        self::assertSame('alpha', $first['data'][0]['slug']);
        self::assertSame(['photographer'], $first['data'][0]['creatorTypes']);
        self::assertTrue($first['meta']['hasMore']);
        $this->client->request('GET', '/api/creators?' . http_build_query($params + ['cursor' => $first['meta']['nextCursor']]));
        self::assertResponseIsSuccessful();
        self::assertSame('beta', $this->response()['data'][0]['slug']);
        self::assertFalse($this->response()['meta']['hasMore']);
        $this->client->request('GET', '/api/creators?' . http_build_query(array_replace($params, ['creatorTypes' => '["model"]']) + ['cursor' => $first['meta']['nextCursor']]));
        self::assertResponseStatusCodeSame(400);
        foreach (['["unknown"]', '["%"]', '{"type":"model"}', '[1]'] as $invalid) {
            $this->client->request('GET', '/api/creators?' . http_build_query(['creatorTypes' => $invalid]));
            self::assertResponseStatusCodeSame(400);
        }
        $this->client->request('GET', '/api/creators?locale=en&sort=name');
        self::assertSame(4, $this->response()['meta']['total']);
    }

    private function response(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testMigrationPreservesExistingCreatorsAndDefaultsToAnUnselectedType(): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/Version20261008150000.php';
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $creator = new Creator('existing-creator', 'Existing Creator', 'Travel', 'Sarajevo', '', []);
        $em->persist($creator);
        $em->flush();
        $connection = $em->getConnection();
        $manager = $connection->createSchemaManager();
        $platform = $connection->getDatabasePlatform();
        $before = $manager->introspectSchema();
        $legacy = clone $before;
        $legacy->getTable('creator')->dropColumn('creator_types');
        foreach ($platform->getAlterSchemaSQL($manager->createComparator()->compareSchemas($before, $legacy)) as $sql) {
            $connection->executeStatement($sql);
        }
        foreach (['up', 'down'] as $direction) {
            $before = $manager->introspectSchema();
            $after = clone $before;
            $migration = new \DoctrineMigrations\Version20261008150000($connection, new \Psr\Log\NullLogger());
            $migration->$direction($after);
            foreach ($platform->getAlterSchemaSQL($manager->createComparator()->compareSchemas($before, $after)) as $sql) {
                $connection->executeStatement($sql);
            }
            self::assertSame('Existing Creator', $connection->fetchOne('SELECT display_name FROM creator WHERE id = ?', [$creator->getId()]));
            if ($direction === 'up') {
                self::assertSame([], json_decode($connection->fetchOne('SELECT creator_types FROM creator WHERE id = ?', [$creator->getId()]), true, flags: JSON_THROW_ON_ERROR));
            } else {
                self::assertFalse($manager->introspectTable('creator')->hasColumn('creator_types'));
            }
        }
    }
}
