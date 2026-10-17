<?php

namespace App\Tests\Controller;

use App\Entity\Company;
use App\Entity\User;
use App\Admin\OverviewStatistics;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AgencyServicesTest extends WebTestCase
{
    public function testStructuredServicesPreserveLegacyLabelsAndSurviveUnrelatedSavesAndTypeChanges(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em); $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata); $tool->createSchema($metadata);
        $user = new User('structured-agency@example.test', 'ROLE_COMPANY');
        $user->setPassword('unused'); $user->setPhone('+38761123456'); $user->setCity('Sarajevo'); $user->setCountryCode('BA');
        $company = new Company('structured-agency', 'Studio Most', 'Marketing'); $company->setCompanyType('agency'); $user->setCompany($company);
        $em->persist($user); $em->flush();
        $provider = \App\Account\AgencyProfiles::ensure($user, $em);
        $provider->updateProfile('Studio Most', 'Marketing', 'Sarajevo', '', [], ['Existing service'], null, '', [], [], ['Marketing'], []);
        $em->flush();
        $client->loginUser($user);
        $client->request('GET', '/api/auth/session');
        $legacy = $this->payload($client)['data']['profile']['services'];
        self::assertSame('Existing service', $legacy[0]['title']);
        $client->request('GET', '/api/auth/csrf'); $csrf = $this->payload($client)['csrfToken'];
        $service = ['id' => 'campaign-management', 'title' => 'Vođenje influencer kampanja', 'description' => 'Od izbora kreatora do završnog izvještaja.', 'deliverables' => ['Odabir kreatora', 'Priprema briefa', 'Izvještaj'], 'platforms' => ['Instagram', 'TikTok'], 'engagement' => 'both'];
        $save = function (array $body) use ($client, $csrf): void { $client->jsonRequest('PUT', '/api/me/profile', $body, ['HTTP_X_CSRF_TOKEN' => $csrf]); };
        $save(['name' => 'Studio Most', 'services' => [$service, ...$legacy]]);
        self::assertResponseIsSuccessful();
        self::assertSame($service, $this->payload($client)['data']['profile']['services'][0]);
        $client->request('GET', '/api/companies/structured-agency');
        self::assertSame([$service, ...$legacy], $this->payload($client)['data']['services']);
        $save(['name' => 'Studio Most', 'services' => array_column([$service, ...$legacy], 'title')]);
        self::assertResponseIsSuccessful();
        self::assertSame([$service, ...$legacy], $this->payload($client)['data']['profile']['services']);
        self::assertSame(1, self::getContainer()->get(OverviewStatistics::class)->snapshot(7)['agencyProfileCompletion']['services']);
        foreach ([['platforms' => ['Invalid']], ['engagement' => 'weekly'], ['deliverables' => ['']], ['description' => str_repeat('a', 801)]] as $invalid) {
            $save(['name' => 'Unwanted name', 'services' => [[...$service, ...$invalid]]]);
            self::assertResponseStatusCodeSame(400);
        }
        $save(['name' => 'Updated studio']); self::assertResponseIsSuccessful();
        self::assertSame([$service, ...$legacy], $this->payload($client)['data']['profile']['services']);
        $save(['name' => 'Updated studio', 'companyType' => 'brand']); self::assertResponseIsSuccessful();
        $client->request('GET', '/api/companies/structured-agency');
        self::assertArrayNotHasKey('services', $this->payload($client)['data']);
        $save(['name' => 'Updated studio', 'companyType' => 'agency']); self::assertResponseIsSuccessful();
        self::assertSame([$service, ...$legacy], $this->payload($client)['data']['profile']['services']);
        $save(['name' => 'Updated studio', 'services' => []]); self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload($client)['data']['profile']['services']);
        self::assertSame(0, self::getContainer()->get(OverviewStatistics::class)->snapshot(7)['agencyProfileCompletion']['services']);
        $save(['name' => 'Updated studio']); self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload($client)['data']['profile']['services']);
        $company = self::getContainer()->get(EntityManagerInterface::class)->find(Company::class, $company->getId());
        $company->setServices([$service]); $company->anonymize();
        self::assertSame([], $company->getServices());
    }

    private function payload(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        return json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
