<?php

declare(strict_types=1);

namespace App\Command;

use App\Background\DirectoryIndexer;
use App\Entity\Company;
use App\Entity\ListingAd;
use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\User;
use App\Service\MediaStorage;
use App\Service\PublicUrlSlugs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Local-only preview of a partner brand pitch: the company profile with the "Partner Wave.ba" badge
 * and its ad in the company list. Images live in var/{brand}-preview. Repeatable; refuses to run in prod.
 */
#[AsCommand(name: 'app:seed-partner-preview', description: 'Local preview: a partner company (giga, gorsen) and its company-list ad.')]
final class SeedPartnerPreviewCommand extends Command
{
    private const BRANDS = [
        'giga' => [
            'email' => 'giga.preview@wave.ba', 'name' => 'GIGA', 'industries' => ['Home', 'Family'], 'featuredPosition' => 1,
            'about' => '<p>GIGA je online trgovina namještaja, rasvjete i opreme za dom, ured i djecu, s dostavom širom Bosne i Hercegovine.</p><p>Kroz UGC program sarađujemo s kreatorima sadržaja koji naše proizvode predstavljaju u stvarnom domu, uz naknadu u proizvodima.</p>',
            'links' => [['platform' => 'Website', 'url' => 'https://giga.ba'], ['platform' => 'Facebook', 'url' => 'https://www.facebook.com/gigabih']],
            'logo' => 'giga-logo.png', 'cover' => 'giga-cover.png',
            'ad' => ['file' => 'giga-ad-wide.jpg', 'media' => 'giga-black-month-wide.jpg', 'name' => 'GIGA Black Month', 'title' => 'Black Month: uštedite do 70%', 'body' => 'Na odabrane artikle za dom, ured i djecu.', 'buttonLabel' => 'Posjetite giga.ba', 'url' => 'https://giga.ba', 'place' => ['row' => 2, 'column' => 1]],
        ],
        'gorsen' => [
            'email' => 'gorsen.preview@wave.ba', 'name' => 'Gorsen', 'industries' => ['Beauty', 'Wellness'], 'featuredPosition' => 2,
            'about' => '<p>Gorsen je organska, certificirana kozmetika s potpisom prirode iz Mostara: njega lica, tijela i kose te linija za djecu.</p><p>Kroz Gorsen UGC program surađujemo s kreatorima sadržaja koji naše proizvode predstavljaju kroz svoj stil i svoju priču, uz naknadu u proizvodima.</p>',
            'links' => [['platform' => 'Website', 'url' => 'https://gorsen.ba'], ['platform' => 'Instagram', 'url' => 'https://www.instagram.com/gorsencosmetics/'], ['platform' => 'TikTok', 'url' => 'https://www.tiktok.com/@gorsencosmetics'], ['platform' => 'Facebook', 'url' => 'https://www.facebook.com/Gorsencosmetics/']],
            'logo' => 'logo.png', 'cover' => 'cover.jpg',
            'ad' => ['file' => 'ad-wide.jpg', 'media' => 'gorsen-hydra-wide.jpg', 'name' => 'Gorsen Hydra', 'title' => 'Hydra rutina za svjež, hidratiziran ten', 'body' => 'Hydra Refresh Tonic i Hydra Complex Cream.', 'buttonLabel' => 'Posjetite gorsen.ba', 'url' => 'https://gorsen.ba', 'place' => ['row' => 3, 'column' => 3]],
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
        private readonly PublicUrlSlugs $slugs,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly DirectoryIndexer $indexer,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('brand', InputArgument::REQUIRED, implode(' or ', array_keys(self::BRANDS)));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->environment === 'prod') { $io->error('Local preview only.'); return Command::FAILURE; }
        $key = (string) $input->getArgument('brand');
        $brand = self::BRANDS[$key] ?? null;
        if ($brand === null) { $io->error('Unknown brand. Use: '.implode(', ', array_keys(self::BRANDS))); return Command::FAILURE; }
        $assets = $this->projectDir.'/var/'.$key.'-preview';
        foreach ([$brand['logo'], $brand['cover'], $brand['ad']['file']] as $file) {
            if (!is_file($assets.'/'.$file)) { $io->error('Missing '.$assets.'/'.$file); return Command::FAILURE; }
        }
        $admin = null;
        foreach ($this->em->getRepository(User::class)->findAll() as $candidate) {
            if ($candidate->hasRole('ROLE_ADMIN') && !$candidate->isDeleted()) { $admin = $candidate; break; }
        }
        if (!$admin) { $io->error('No admin account found for the ad image owner.'); return Command::FAILURE; }

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $brand['email']]);
        if (!$user) {
            $user = new User($brand['email'], 'ROLE_COMPANY');
            $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(16))));
            $user->setApproved(true);
            $user->setEmailVerified(true);
            $user->setHideMyAccount(false);
            $user->setNotificationsEnabled(false);
            $user->setPreferredLocale('bs');
            $user->setCountryCode('BA');
            $user->setCompany(new Company($this->slugs->reserve('company', $brand['name']), $brand['name'], $brand['industries'][0]));
            $this->em->persist($user);
            $this->em->flush();
        }
        $company = $user->getCompany();
        if (!$company) { $io->error($brand['email'].' is not a company account.'); return Command::FAILURE; }

        $company->updateProfile($brand['name'], $brand['industries'][0], null, $brand['about']);
        $company->setCompanyType('brand');
        $company->setIndustries($brand['industries']);
        $company->setLogoMedia($this->media($user, 'company-logo', $assets.'/'.$brand['logo'], $key.'-'.$brand['logo']));
        $company->setCoverMedia($this->media($user, 'company-cover', $assets.'/'.$brand['cover'], $key.'-'.$brand['cover']));
        $company->setSocialLinks($brand['links']);
        $company->setPartner(true);
        $company->setFeatured(true);
        $company->setFeaturedPosition($brand['featuredPosition']);

        // One ad per partner in its own slot; any other enabled ad on that slot is switched off, not deleted.
        $copy = $brand['ad'];
        $ads = $this->em->getRepository(ListingAd::class)->findBy(['kind' => 'companies']);
        $ad = null;
        foreach ($ads as $existing) if (($existing->getContent()['name'] ?? '') === $copy['name']) $ad = $existing;
        foreach ($ads as $existing) {
            if ($existing === $ad || !$existing->isEnabled()) continue;
            $span = (int) ($existing->getContent()['span'] ?? 1);
            foreach ($existing->getContent()['placements'] ?? [] as $place) {
                $covers = $place['row'] === $copy['place']['row'] && $copy['place']['column'] >= $place['column'] && $copy['place']['column'] < $place['column'] + $span;
                if ($covers) $existing->setEnabled(false);
            }
        }
        $ad ??= new ListingAd('companies');
        $ad->setContent([
            'name' => $copy['name'], 'title' => $copy['title'], 'body' => $copy['body'],
            'buttonLabel' => $copy['buttonLabel'], 'url' => $copy['url'], 'helpLabel' => '', 'helpUrl' => '',
            'artwork' => '', 'palette' => 'paper', 'span' => 1, 'placements' => [$copy['place']],
        ]);
        $ad->setImage($this->media($admin, 'listing-ad', $assets.'/'.$copy['file'], $copy['media']));
        $ad->setEnabled(true);
        $this->em->persist($ad);
        $this->em->flush();
        $this->indexer->index('company', [(int) $company->getId()]);

        $io->success(sprintf('%s ready at /kompanije/%s (partner, featured #%d) and its ad is active in the company list.', $brand['name'], $company->getSlug(), $brand['featuredPosition']));
        return Command::SUCCESS;
    }

    private function media(User $owner, string $folderSlug, string $path, string $name): Media
    {
        $folder = $this->em->getRepository(MediaFolder::class)->findOneBy(['slug' => $folderSlug]);
        if (!$folder) { $folder = new MediaFolder($folderSlug, $folderSlug); $this->em->persist($folder); $this->em->flush(); }
        $existing = $this->em->getRepository(Media::class)->findOneBy(['owner' => $owner, 'folder' => $folder, 'originalName' => $name]);
        if ($existing && is_file($this->storage->absolutePath($existing->getStoragePath()))) return $existing;
        $mime = str_ends_with($name, '.png') ? 'image/png' : 'image/jpeg';
        $stored = $this->storage->store(new UploadedFile($path, $name, $mime, null, true), $folder, $owner);
        $media = new Media($folder, $owner, $name, $stored['path'], $stored['mimeType'], $stored['fileSize']);
        $media->setDimensions($stored['width'], $stored['height']);
        $this->em->persist($media);
        $this->em->flush();
        return $media;
    }
}
