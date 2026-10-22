<?php

namespace App\Tests\Controller;

use App\Entity\Creator;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class CreatorAgeFilterTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::mockTime('2026-10-23 12:00:00 UTC');
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testAgeBoundsRespectBirthdaysVisibilityTopicsPaginationAndPrivacy(): void
    {
        foreach ([
            ['a-eighteen', '2008-10-23'],
            ['b-thirty', '1996-10-23'],
            ['c-seventy-today', '1956-10-23'],
            ['d-seventy-last-day', '1955-10-24'],
            ['e-seventeen', '2008-10-24'],
            ['f-seventy-one', '1955-10-23'],
            ['g-missing', null],
        ] as [$name, $birthday]) {
            $this->creator($name, $birthday);
        }
        foreach (['hidden', 'pending'] as $state) {
            $user = new User($state.'@example.test', 'ROLE_CREATOR');
            $user->setPassword('unused');
            $user->setApproved($state !== 'pending');
            $user->setHideMyAccount($state === 'hidden');
            $user->setCreator($this->creator($state, '1996-10-23'));
            $this->em->persist($user);
        }
        $this->creator('agency', '1996-10-23')->markAgencyProfile();
        $this->creator('other-topic', '1996-10-23', 'Beauty');
        $this->em->flush();

        $query = ['ageMin' => 18, 'ageMax' => 70, 'sort' => 'name', 'categories' => '["Travel"]', 'pagination' => 'cursor', 'limit' => 2, 'view' => 'card'];
        $first = $this->get($query);
        self::assertSame(4, $first['meta']['total']);
        self::assertSame(['a-eighteen', 'b-thirty'], array_column($first['data'], 'slug'));
        self::assertTrue($first['meta']['hasMore']);
        foreach ($first['data'] as $creator) self::assertArrayNotHasKey('birthday', $creator);
        $second = $this->get($query + ['cursor' => $first['meta']['nextCursor']]);
        self::assertSame(['c-seventy-today', 'd-seventy-last-day'], array_column($second['data'], 'slug'));
        self::assertFalse($second['meta']['hasMore']);
        foreach (['ageMin' => 19, 'ageMax' => 69] as $key => $value) {
            $this->client->request('GET', '/api/creators?'.http_build_query(array_replace($query, [$key => $value, 'cursor' => $first['meta']['nextCursor']])));
            self::assertResponseStatusCodeSame(400);
        }
        $singleAge = $this->get(['ageMin' => 30, 'ageMax' => 30, 'categories' => '["Travel"]']);
        self::assertSame(['b-thirty'], array_column($singleAge['data'], 'slug'));
        $default = $this->get(['categories' => '["Travel"]']);
        self::assertContains('g-missing', array_column($default['data'], 'slug'));
    }

    public function testLeapYearsDoNotIncludePeopleBeforeTheirBirthday(): void
    {
        $this->creator('february', '2010-02-28');
        $this->creator('march', '2010-03-01');
        $this->creator('oldest', '2009-03-01');
        $this->creator('too-old', '2009-02-28');
        $this->creator('leap-birthday', '2008-02-29');
        $this->em->flush();
        self::mockTime('2028-02-29 12:00:00 UTC');
        self::assertSame(['february', 'oldest'], array_column($this->get(['ageMin' => 18, 'ageMax' => 18, 'sort' => 'name'])['data'], 'slug'));
        self::mockTime('2027-02-28 12:00:00 UTC');
        self::assertSame([], $this->get(['ageMin' => 19, 'ageMax' => 19])['data']);
        self::mockTime('2027-03-01 12:00:00 UTC');
        self::assertSame(['leap-birthday'], array_column($this->get(['ageMin' => 19, 'ageMax' => 19])['data'], 'slug'));
    }

    public function testRejectsBoundsOutside18To70AndMalformedOrInvertedRanges(): void
    {
        foreach ([['ageMin' => 17], ['ageMax' => 71], ['ageMin' => 50, 'ageMax' => 30], ['ageMin' => '18.5'], ['ageMax' => 'abc'], ['ageMin' => ''], ['ageMin' => ['18']], ['ageMax' => '700000000000']] as $query) {
            $this->client->request('GET', '/api/creators?'.http_build_query($query));
            self::assertResponseStatusCodeSame(400);
        }
    }

    private function creator(string $name, ?string $birthday, string $category = 'Travel'): Creator
    {
        $creator = new Creator($name, $name, $category, 'Sarajevo', '', []);
        $creator->setBirthday($birthday === null ? null : new \DateTimeImmutable($birthday));
        $this->em->persist($creator);

        return $creator;
    }

    private function get(array $query): array
    {
        $this->client->request('GET', '/api/creators?'.http_build_query($query));
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
