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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Local-only preview of GIGA as a partner brand, with its ad in the company list. Repeatable; never runs in prod. */
#[AsCommand(name: 'app:seed-giga-preview', description: 'Local preview: GIGA partner company and its company-list ad.')]
final class SeedGigaPreviewCommand extends Command
{
    private const EMAIL = 'giga.preview@wave.ba';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaStorage $storage,
        private readonly PublicUrlSlugs $slugs,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly DirectoryIndexer $indexer,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) { parent::__construct(); }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->environment === 'prod') { $io->error('Local preview only.'); return Command::FAILURE; }
        $assets = $this->projectDir.'/var/giga-preview';
        foreach (['giga-logo.png', 'giga-cover.png', 'giga-ad-wide.jpg'] as $file) {
            if (!is_file($assets.'/'.$file)) { $io->error('Missing '.$assets.'/'.$file); return Command::FAILURE; }
        }
        $admin = null;
        foreach ($this->em->getRepository(User::class)->findAll() as $candidate) {
            if ($candidate->hasRole('ROLE_ADMIN') && !$candidate->isDeleted()) { $admin = $candidate; break; }
        }
        if (!$admin) { $io->error('No admin account found for the ad image owner.'); return Command::FAILURE; }

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        if (!$user) {
            $user = new User(self::EMAIL, 'ROLE_COMPANY');
            $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(16))));
            $user->setApproved(true);
            $user->setEmailVerified(true);
            $user->setHideMyAccount(false);
            $user->setNotificationsEnabled(false);
            $user->setPreferredLocale('bs');
            $user->setCountryCode('BA');
            $user->setCompany(new Company($this->slugs->reserve('company', 'GIGA'), 'GIGA', 'Home'));
            $this->em->persist($user);
            $this->em->flush();
        }
        $company = $user->getCompany();
        if (!$company) { $io->error(self::EMAIL.' is not a company account.'); return Command::FAILURE; }

        $company->updateProfile('GIGA', 'Home', null, '<p>GIGA je online trgovina namještaja, rasvjete i opreme za dom, ured i djecu, s dostavom širom Bosne i Hercegovine.</p><p>Kroz UGC program sarađujemo s kreatorima sadržaja koji naše proizvode predstavljaju u stvarnom domu, uz naknadu u proizvodima.</p>');
        $company->setCompanyType('brand');
        $company->setIndustries(['Home', 'Family']);
        $company->setLogoMedia($this->media($user, 'company-logo', $assets.'/giga-logo.png', 'giga-logo.png'));
        $company->setCoverMedia($this->media($user, 'company-cover', $assets.'/giga-cover.png', 'giga-cover.png'));
        $company->setSocialLinks([['platform' => 'Website', 'url' => 'https://giga.ba'], ['platform' => 'Facebook', 'url' => 'https://www.facebook.com/gigabih']]);
        $company->setPartner(true);
        $company->setFeatured(true);
        $company->setFeaturedPosition(1);

        // Replace the companies-list ad in its slot (or take row 2, column 1) with GIGA's ad.
        $ads = $this->em->getRepository(ListingAd::class)->findBy(['kind' => 'companies']);
        $ad = null;
        foreach ($ads as $existing) if (($existing->getContent()['name'] ?? '') === 'GIGA Black Month') $ad = $existing;
        $placements = [['row' => 2, 'column' => 1]];
        foreach ($ads as $existing) {
            if ($existing === $ad || !$existing->isEnabled()) continue;
            if ($ad === null) $placements = $existing->getContent()['placements'] ?? $placements;
            $existing->setEnabled(false);
        }
        $ad ??= new ListingAd('companies');
        $ad->setContent([
            'name' => 'GIGA Black Month', 'title' => 'Black Month: uštedite do 70%', 'body' => 'Na odabrane artikle za dom, ured i djecu.',
            'buttonLabel' => 'Posjetite giga.ba', 'url' => 'https://giga.ba', 'helpLabel' => '', 'helpUrl' => '',
            'artwork' => '', 'palette' => 'paper', 'span' => 1, 'placements' => array_slice($placements, 0, 1),
        ]);
        $ad->setImage($this->media($admin, 'listing-ad', $assets.'/giga-ad-wide.jpg', 'giga-black-month-wide.jpg'));
        $ad->setEnabled(true);
        $this->em->persist($ad);
        $this->em->flush();
        $this->indexer->index('company', [(int) $company->getId()]);

        $io->success(sprintf('GIGA ready at /kompanije/%s (partner, featured #1) and its ad is active in the company list.', $company->getSlug()));
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
