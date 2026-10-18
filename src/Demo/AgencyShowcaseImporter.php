<?php

declare(strict_types=1);

namespace App\Demo;

use App\Account\AgencyProfiles;
use App\Account\CreatorPresentation;
use App\Entity\{Application, Campaign, CampaignReview, Company, Creator, CreatorPortfolioMedia, Media, Offer, User};
use App\Service\{CampaignApplicationCounter, PublicUrlSlugs};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Explicit Studio Luma import, using the existing owned-media and transaction boundary. */
#[Exclude]
final class AgencyShowcaseImporter
{
    private array $data;

    /** @param \Closure(User, string, string): Media $media */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PublicUrlSlugs $slugs,
        private readonly CampaignApplicationCounter $counter,
        private readonly string $projectDir,
        private readonly \Closure $media,
    ) {
        $this->data = json_decode((string) file_get_contents($projectDir.'/resources/presentation-demo/agency-luma.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function preflight(): array
    {
        foreach ($this->data['accounts'] as $spec) {
            $user = $this->em->getRepository(User::class)->findOneBy(['presentationDemoKey' => $spec['key']]);
            $email = $this->em->getRepository(User::class)->findOneBy(['email' => $spec['email']]);
            if ($email !== null && $email !== $user) throw new \RuntimeException('Refusing to overwrite an existing account: '.$spec['email']);
            if ($user !== null && ($user->isDeleted() || $user->hasRole('ROLE_ADMIN') || $user->hasRole('ROLE_MODERATOR') || !$user->hasRole($spec['type'] === 'company' ? 'ROLE_COMPANY' : 'ROLE_CREATOR') || ($spec['type'] === 'company' ? $user->getCompany() === null : $user->getCreator() === null))) {
                throw new \RuntimeException('The showcase identity was deleted or changed: '.$spec['key']);
            }
        }
        foreach (['luma-logo', 'luma-cover', 'cosmetics', 'coffee', 'bag', 'amina', 'dn-logo'] as $asset) {
            if (!is_file($this->projectDir.'/resources/presentation-demo/'.$asset.'.webp')) throw new \RuntimeException('Missing showcase image: '.$asset);
        }
        return $this->data['accounts'];
    }

    public function seed(?string $password): array
    {
        $this->preflight();
        $password ??= bin2hex(random_bytes(24));
        if (strlen($password) < 8) throw new \InvalidArgumentException('The seed password must contain at least 8 characters.');
        $users = [];
        foreach ($this->data['accounts'] as $spec) {
            $user = $this->em->getRepository(User::class)->findOneBy(['presentationDemoKey' => $spec['key']]);
            if ($user === null) {
                $user = new User($spec['email'], $spec['type'] === 'company' ? 'ROLE_COMPANY' : 'ROLE_CREATOR');
                $user->markPresentationDemo($spec['key']);
                $user->setPassword($this->hasher->hashPassword($user, $password));
                $user->setApproved(true);
                $user->setEmailVerified(true);
                $user->setHideMyAccount($spec['hidden']);
                $user->setNotificationsEnabled(false);
                $user->setPreferredLocale('bs');
                $user->setPhone('+38761123456');
                $user->setCity('Sarajevo');
                $user->setCountryCode('BA');
                if ($spec['type'] === 'company') {
                    $user->setCompany(new Company($this->slugs->reserve('company', $spec['name']), $spec['name'], 'Beauty'));
                } else {
                    $user->setCreator(new Creator($this->slugs->reserve('creator', $spec['name']), $spec['name'], 'Beauty', 'Sarajevo', '<p>Prototip · izmišljeni podaci.</p>', []));
                }
                $this->em->persist($user);
                $this->em->flush();
                if ($user->getCompany()) $user->getCompany()->setLogoMedia(($this->media)($user, 'company-logo', $spec['id'] === 'luma' ? 'luma-logo' : ($spec['id'] === 'coffee' ? 'coffee' : 'dn-logo')));
                if ($user->getCreator()) $user->getCreator()->setAvatarMedia(($this->media)($user, 'creator-avatar', 'amina'));
            }
            $users[$spec['id']] = $user;
        }
        $owner = $users['luma'];
        $company = $owner->getCompany();
        if ($company === null) throw new \LogicException('Missing agency company.');
        $company->updateProfile('Studio Luma', 'Marketing', null, $this->data['about']);
        $company->setCompanyType('agency');
        $company->setIndustries($this->data['industries']);
        $company->setServices($this->data['services']);
        $company->setSocialLinks($this->data['socialLinks']);
        $company->setLogoMedia(($this->media)($owner, 'company-logo', 'luma-logo'));
        $company->setCoverMedia(($this->media)($owner, 'company-cover', 'luma-cover'));
        $this->em->flush();
        $studio = AgencyProfiles::ensure($owner, $this->em);
        $campaigns = [];
        foreach (array_reverse($this->data['history']) as $spec) {
            $client = $users[$spec['client']];
            $provider = $users[$spec['provider']];
            $campaign = $this->campaign($spec['key'], $spec['title'], $client, 'cosmetics', 'Beauty', ['Instagram'], $spec['amount'], $spec['amount'], 1, true, $spec['review']['date'] ?? '14.10.2026.');
            $campaigns[$spec['key']] = $campaign;
            $application = $this->application($campaign, $provider, $spec['amount']);
            if (isset($spec['review'])) {
                $author = $users[$spec['review']['author']];
                $this->review($application, $author, $owner, $spec['review']['rating'], $spec['review']['comment'], $spec['review']['date']);
                $this->review($application, $owner, $author, 5, 'Jasna komunikacija i ugodna saradnja. Izmišljena recenzija za prikaz mogućnosti Wavea.', $spec['review']['date']);
            }
        }
        foreach ($this->data['open'] as $spec) {
            $campaign = $this->campaign($spec['key'], $spec['title'], $owner, $spec['asset'], $spec['category'], $spec['channels'], $spec['min'], $spec['max'], $spec['creators'], false);
            foreach ($spec['applicants'] as $index => $id) $this->application($campaign, $users[$id], $index === 0 ? 350 : null);
        }
        $portfolio = [];
        $studio->clearPortfolioMedia();
        $this->em->flush();
        foreach ($this->data['portfolio'] as $position => $item) {
            $media = ($this->media)($owner, 'creator-portfolio', $item['asset']);
            $studio->addPortfolioMedia(new CreatorPortfolioMedia($studio, $media, $position, $item['title'], 'Instagram'));
            $item['campaignId'] = $item['campaignKey'] ? $campaigns[$item['campaignKey']]->getId() : null;
            $item['url'] = $media->getUrl();
            $item['mediaId'] = $media->getId();
            unset($item['asset'], $item['campaignKey']);
            $portfolio[] = array_replace($item, CreatorPresentation::work($item));
        }
        $studio->updateProfile($company->getName(), 'Marketing', $owner->getCity() ?? '', $company->getAbout() ?? '', [], [], null, '', $portfolio, array_map(static fn (array $item): array => array_replace($item, CreatorPresentation::package($item)), $this->data['packages']), $this->data['industries'], $this->data['faqs']);
        $studio->setPresentation(CreatorPresentation::settings($this->data['presentation']));
        $this->em->flush();
        return ['path' => '/kompanije/'.$company->getSlug(), 'hidden' => $owner->isHideMyAccount(), 'accounts' => count($users), 'services' => count($company->getServices()), 'portfolio' => count($portfolio), 'packages' => count($studio->getPackages())];
    }

    private function campaign(string $key, string $title, User $owner, string $asset, string $category, array $channels, int $min, int $max, int $slots, bool $finished, ?string $finishedOn = null): Campaign
    {
        $key = 'wave-agency-luma-'.$key;
        $company = $owner->getCompany();
        if ($company === null) throw new \LogicException('A demo campaign requires a company owner.');
        $campaign = $this->em->getRepository(Campaign::class)->findOneBy(['presentationDemoKey' => $key]);
        if ($campaign !== null && $campaign->getCompany() !== $company) throw new \RuntimeException('Showcase campaign owner mismatch: '.$key);
        $new = $campaign === null;
        if ($campaign === null) {
            $campaign = new Campaign($this->slugs->reserve('campaign', $title), $title, 'Izmišljena kampanja za prikaz profila agencije Studio Luma.', '<p><strong>Prototip · izmišljeni podaci.</strong></p><p>Tražimo prirodan, jasno osmišljen sadržaj o proizvodu i njegovom mjestu u svakodnevnim ritualima. Opseg, isporuku, izmjene i prava korištenja dogovaramo prije početka saradnje.</p>', $category, $channels, ['1 kratki video do 30 sekundi', '3 fotografije proizvoda'], $min, $max, 'Sarajevo', $slots, new \DateTimeImmutable($finished ? '-7 days' : '2026-11-10'), new \DateTimeImmutable('2026-09-01'), $company, status: $finished ? 'finished' : 'open', countryCode: 'BA');
            $campaign->markPresentationDemo($key);
            $this->em->persist($campaign);
        }
        $campaign->setCoverMedia(($this->media)($owner, 'campaign-cover', $asset));
        $this->em->flush();
        if ($new && $finished && $finishedOn !== null) {
            $at = \DateTimeImmutable::createFromFormat('!d.m.Y.', $finishedOn);
            if ($at === false) throw new \LogicException('Invalid showcase completion date.');
            $this->em->getConnection()->executeStatement('UPDATE campaign SET finished_at = ? WHERE id = ?', [$at->format('Y-m-d H:i:s'), $campaign->getId()]);
            $this->em->refresh($campaign);
        }
        return $campaign;
    }

    private function application(Campaign $campaign, User $owner, ?int $amount): Application
    {
        $provider = $owner->isAgency() ? AgencyProfiles::ensure($owner, $this->em) : $owner->getCreator();
        if ($provider === null) throw new \LogicException('Missing demo provider.');
        $application = $this->em->getRepository(Application::class)->findOneBy(['campaign' => $campaign, 'creator' => $provider]);
        if ($application === null) {
            $application = new Application($campaign, $provider, 'Demo prijava: prirodan sadržaj prema jasno dogovorenom kreativnom zadatku.');
            $this->em->persist($application);
            if ($amount !== null) {
                $offer = new Offer($application, $amount, 'Izmišljena ponuda za demonstraciju saradnje.');
                $offer->respond('accepted');
                $this->em->persist($offer);
            }
            $this->em->flush();
            $this->counter->increment($campaign);
        }
        return $application;
    }

    private function review(Application $application, User $author, User $recipient, int $score, string $comment, string $date): void
    {
        if ($this->em->getRepository(CampaignReview::class)->findOneBy(['application' => $application, 'author' => $author])) return;
        $review = new CampaignReview($application, $author, $recipient, $score, $comment);
        $this->em->persist($review);
        $this->em->flush();
        $createdAt = \DateTimeImmutable::createFromFormat('!d.m.Y.', $date);
        if ($createdAt === false) throw new \LogicException('Invalid showcase review date.');
        $this->em->getConnection()->executeStatement('UPDATE campaign_review SET created_at = ? WHERE id = ?', [$createdAt->format('Y-m-d H:i:s'), $review->getId()]);
        $this->em->refresh($review);
    }
}
