<?php

declare(strict_types=1);

namespace App\Demo;

use App\Account\CreatorPresentation;
use App\Entity\Application;
use App\Entity\Campaign;
use App\Entity\CampaignConversation;
use App\Entity\CampaignMessage;
use App\Entity\CampaignReview;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\CreatorPortfolioMedia;
use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\Offer;
use App\Entity\User;
use App\Service\MediaStorage;
use App\Service\PublicUrlSlugs;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Explicit, repeatable showcase import. Never claims an unmarked existing account. */
final class PresentationDemoSeeder
{
    public const ACCOUNTS = [
        'amina' => ['amina.kovac@wave.ba', 'Amina Kovač', 'ROLE_CREATOR'],
        'dn' => ['dnkozmetik@wave.ba', 'DN kozmetik', 'ROLE_COMPANY'],
        'agency' => ['nova.digital@wave.ba', 'Nova Digital', 'ROLE_COMPANY'],
    ];
    private array $createdPaths = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PublicUrlSlugs $slugs,
        private readonly MediaStorage $storage,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function preflight(): void
    {
        foreach (self::ACCOUNTS as $key => [$email, , $role]) {
            $byKey = $this->em->getRepository(User::class)->findOneBy(['presentationDemoKey' => 'wave-showcase-'.$key]);
            $byEmail = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($byEmail && $byEmail !== $byKey) throw new \RuntimeException('Refusing to overwrite an existing non-demo account: '.$email);
            if ($byKey && ($byKey->isDeleted() || !$byKey->hasRole($role) || $byKey->hasRole('ROLE_ADMIN') || $byKey->hasRole('ROLE_MODERATOR'))) {
                throw new \RuntimeException('The showcase identity was deleted or its role changed: '.$key);
            }
        }
        foreach (['amina', 'cosmetics', 'coffee', 'bag', 'agency-cover', 'dn-logo', 'agency-logo'] as $asset) {
            if (!is_file($this->projectDir.'/resources/presentation-demo/'.$asset.'.webp')) throw new \RuntimeException('Missing showcase image: '.$asset);
        }
    }

    public function seed(string $password, bool $resetPasswords = false): array
    {
        if (strlen($password) < 8) throw new \InvalidArgumentException('The seed password must contain at least 8 characters.');
        $this->preflight();
        $this->createdPaths = [];
        try {
            return $this->em->wrapInTransaction(function () use ($password, $resetPasswords): array {
                $users = [];
                foreach (self::ACCOUNTS as $key => [$email, $name, $role]) {
                    $user = $this->em->getRepository(User::class)->findOneBy(['presentationDemoKey' => 'wave-showcase-'.$key]);
                    if (!$user) {
                        $user = new User($email, $role);
                        $user->markPresentationDemo('wave-showcase-'.$key);
                        $user->setPassword($this->hasher->hashPassword($user, $password));
                        $user->setApproved(true);
                        $user->setEmailVerified(true);
                        $user->setHideMyAccount(false);
                        $user->setNotificationsEnabled(false);
                        $user->setPreferredLocale('bs');
                        $user->setPhone('+38761123456');
                        $user->setCity($key === 'dn' ? 'Mostar' : 'Sarajevo');
                        $user->setCountryCode('BA');
                        if ($key === 'amina') {
                            $user->setCreator(new Creator($this->slugs->reserve('creator', $name), $name, 'Beauty', 'Sarajevo', '', []));
                        } else {
                            $user->setCompany(new Company($this->slugs->reserve('company', $name), $name, $key === 'dn' ? 'Beauty' : 'Marketing'));
                        }
                        $this->em->persist($user);
                    } elseif ($resetPasswords) {
                        $user->setPassword($this->hasher->hashPassword($user, $password));
                    }
                    $users[$key] = $user;
                }
                $this->em->flush(); // Media storage is scoped to the newly assigned user IDs.
                $creator = $users['amina']->getCreator();
                $dn = $users['dn']->getCompany();
                $agency = $users['agency']->getCompany();
                if (!$creator || !$dn || !$agency) throw new \RuntimeException('Showcase profile type does not match its identity.');
                $this->company($users['dn'], $dn, false);
                $this->company($users['agency'], $agency, true);
                $campaigns = $this->campaigns($users, $creator, $dn, $agency);
                $this->creator($users['amina'], $creator, $campaigns);
                $this->em->flush();
                return array_map(static fn (User $user): array => [
                    'email' => $user->getEmail(),
                    'path' => ($user->getCreator() ? '/kreatori/' : '/kompanije/').($user->getCreator()?->getSlug() ?? $user->getCompany()?->getSlug()),
                    'hidden' => $user->isHideMyAccount(),
                ], $users);
            });
        } catch (\Throwable $error) {
            foreach ($this->createdPaths as $path) $this->storage->removePath($path);
            throw $error;
        }
    }

    private function media(User $owner, string $folderSlug, string $asset): Media
    {
        $folder = $this->em->getRepository(MediaFolder::class)->findOneBy(['slug' => $folderSlug]);
        if (!$folder) {
            $folder = new MediaFolder($folderSlug, $folderSlug);
            $this->em->persist($folder);
            $this->em->flush();
        }
        $name = 'wave-showcase-'.$asset.'.webp';
        $media = $this->em->getRepository(Media::class)->findOneBy(['owner' => $owner, 'folder' => $folder, 'originalName' => $name]);
        if ($media) {
            if (!is_file($this->storage->absolutePath($media->getStoragePath()))) throw new \RuntimeException('A showcase media file is missing; restore it before reseeding: '.$name);
            return $media;
        }
        $file = new UploadedFile($this->projectDir.'/resources/presentation-demo/'.$asset.'.webp', $name, 'image/webp', null, true);
        $stored = $this->storage->store($file, $folder, $owner);
        $this->createdPaths[] = $stored['path'];
        $media = new Media($folder, $owner, $name, $stored['path'], $stored['mimeType'], $stored['fileSize']);
        $media->setDimensions($stored['width'], $stored['height']);
        $this->em->persist($media);
        $this->em->flush();
        return $media;
    }

    private function company(User $owner, Company $company, bool $agency): void
    {
        $company->updateProfile($agency ? 'Nova Digital' : 'DN kozmetik', $agency ? 'Marketing' : 'Beauty', null,
            $agency
                ? '<p>Nova Digital je izmišljena digitalna agencija koja osmišljava kampanje za brendove i sarađuje s lokalnim kreatorima. Od kreativnog zadatka i odabira kreatora do pregleda sadržaja i rezultata, sve dogovaramo na jednom mjestu.</p><p>Primjer: kampanja za izmišljeni brend Kafa Ritual. Podaci, saradnje i recenzije služe isključivo za predstavljanje mogućnosti Wavea.</p>'
                : '<p>DN kozmetik je izmišljeni brend njege kože. Ovaj primjer pokazuje kako kompanija može predstaviti svoju priču, objaviti kampanju i sarađivati s kreatorima.</p><p>U demo kampanjama prikazujemo jednostavne rutine, teksture proizvoda i prirodan sadržaj za društvene mreže. Svi proizvodi, dogovoreni iznosi i recenzije su izmišljeni.</p>');
        $company->setCompanyType($agency ? 'agency' : 'brand');
        $company->setIndustries($agency ? ['Marketing', 'Technology'] : ['Beauty', 'Lifestyle']);
        $company->setLogoMedia($this->media($owner, 'company-logo', $agency ? 'agency-logo' : 'dn-logo'));
        $company->setCoverMedia($this->media($owner, 'company-cover', $agency ? 'agency-cover' : 'cosmetics'));
        // Reserved example domains cannot be mistaken for an actual brand's social accounts.
        $company->setSocialLinks([['platform' => 'Instagram', 'url' => 'https://example.com/'.($agency ? 'nova-digital' : 'dn-kozmetik').'/instagram'], ['platform' => 'Website', 'url' => 'https://example.com/'.($agency ? 'nova-digital' : 'dn-kozmetik')]]);
    }

    private function campaigns(array $users, Creator $creator, Company $dn, Company $agency): array
    {
        $specs = [
            ['dn-rutina', $dn, 'Njega bez komplikacija', 'Dva kratka videa o jutarnjoj rutini njege.', 'cosmetics', 250, 5, 5],
            ['dn-teksture', $dn, 'Teksture koje pričaju priču', 'Fotografije proizvoda i kratki prikaz nanošenja.', 'cosmetics', 400, 5, 4],
            ['agency-kafa', $agency, 'Kafa Ritual — mali jutarnji ritual', 'Nova Digital vodi kampanju za izmišljeni brend Kafa Ritual.', 'coffee', 350, 4, 5],
            ['agency-torba', $agency, 'Svaki dan, manje plastike', 'Priča o platnenoj torbi za izmišljeni brend Mali Korak.', 'bag', 450, 5, 5],
            ['dn-poziv', $dn, 'Tvoja večernja rutina', 'Otvoreni demo poziv za kreatore sadržaja o njezi kože.', 'cosmetics', null, 0, 0],
            ['agency-poziv', $agency, 'Kafa Ritual — priče iz komšiluka', 'Otvoreni demo poziv agencije za lokalne kreativne priče.', 'coffee', null, 0, 0],
        ];
        $campaigns = [];
        foreach ($specs as [$key, $company, $title, $summary, $asset, $amount, $creatorScore, $companyScore]) {
            // The stable seed namespace is also a permanently reserved public URL.
            $campaign = $this->em->getRepository(Campaign::class)->findOneBy(['presentationDemoKey' => 'wave-showcase-'.$key]);
            if ($campaign && $campaign->getCompany() !== $company) throw new \RuntimeException('A showcase campaign belongs to another account: '.$key);
            $new = !$campaign;
            if (!$campaign) {
                $slug = $this->slugs->reserve('campaign', $title);
                $campaign = new Campaign($slug, $title, $summary, '', 'Beauty', ['Instagram', 'TikTok'], [], 250, 600, 'Bosna i Hercegovina', 2, new DateTimeImmutable('+30 days'), new DateTimeImmutable('-40 days'), $company, status: $amount === null ? 'open' : 'finished');
                $campaign->markPresentationDemo('wave-showcase-'.$key);
                $this->em->persist($campaign);
            }
            $description = '<p><strong>Prototip · izmišljeni podaci.</strong> Ova kampanja služi kao primjer rada na Waveu.</p><p>'.$summary.'</p><h2>Šta kreiramo</h2><ul><li>Prirodan video do 30 sekundi</li><li>Tri fotografije proizvoda</li><li>Verzija prilagođena Instagramu i TikToku</li></ul><h2>Dogovor i isporuka</h2><p>Kreator priprema ideju i sadržaj u roku od sedam dana. Uključena je jedna izmjena. Objavu i prava korištenja dogovaramo prije angažmana.</p>';
            $campaign->update($title, $summary, $description, $company === $dn ? 'Beauty' : 'Lifestyle', ['Instagram', 'TikTok'], ['1 kratki video do 30 sekundi', '3 fotografije proizvoda'], 250, 600, 'Sarajevo', 2, $new ? new DateTimeImmutable($amount === null ? '+30 days' : '-7 days') : $campaign->getClosesAt(), $new ? ($amount === null ? 'open' : 'finished') : $campaign->getStatus(), 'BAM', 'BA', $company === $dn ? ['Beauty', 'Lifestyle'] : ['Lifestyle', 'Food']);
            $owner = $company->getOwner();
            if (!$owner) throw new \RuntimeException('Demo company has no owner.');
            $campaign->setCoverMedia($this->media($owner, 'campaign-cover', $asset));
            $campaigns[$key] = $campaign;
            if ($amount === null) continue;
            $application = $this->em->getRepository(Application::class)->findOneBy(['campaign' => $campaign, 'creator' => $creator]);
            if (!$application) {
                $application = new Application($campaign, $creator, 'Voljela bih pripremiti prirodan sadržaj u svom stilu. Predlažem kratak video i fotografije prema dogovorenom zadatku. Demo prijava.');
                $offer = new Offer($application, $amount, 'Dogovoreni demo iznos za navedene isporuke.');
                $offer->respond('accepted');
                $this->em->persist($application);
                $this->em->persist($offer);
                $conversation = new CampaignConversation($campaign, $creator, $owner);
                $this->em->persist($conversation);
                foreach ([[$owner, 'Pozdrav Amina! Ovo je demo razgovor za kampanju „'.$title.'“. Dogovorili smo '.$amount.' KM i isporuku u roku od sedam dana.'], [$users['amina'], 'Hvala na jasnom zadatku! Pripremit ću ideju, snimiti sadržaj i poslati materijale na pregled.'], [$owner, 'Sadržaj je pregledan i demo saradnja je završena. Hvala na ugodnoj komunikaciji!']] as [$sender, $body]) {
                    $message = new CampaignMessage($conversation, $sender, $body);
                    $message->markRead();
                    $this->em->persist($message);
                }
            }
            $this->em->flush();
            foreach ([[$owner, $users['amina'], $creatorScore, 'Odlična komunikacija i prirodan sadržaj, isporučen prema dogovoru. Izmišljena recenzija za prikaz profila.'], [$users['amina'], $owner, $companyScore, 'Jasan zadatak i ugodna saradnja od prve ideje do pregleda materijala. Izmišljena recenzija za prikaz profila.']] as [$author, $recipient, $score, $comment]) {
                if (!$this->em->getRepository(CampaignReview::class)->findOneBy(['application' => $application, 'author' => $author])) {
                    $this->em->persist(new CampaignReview($application, $author, $recipient, $score, $comment));
                }
            }
            $this->em->flush();
        }
        return $campaigns;
    }

    private function creator(User $owner, Creator $creator, array $campaigns): void
    {
        $today = (new DateTimeImmutable())->format('Y-m-d');
        $social = CreatorPresentation::social([
            ['platform' => 'Instagram', 'handle' => '@amina.kovac.demo', 'followers' => 24800, 'averageViews' => 12400, 'sampleSize' => 10, 'engagementRate' => 4.8, 'engagementBasis' => 'followers', 'lastUpdated' => $today, 'audienceCountries' => [['countryCode' => 'BA', 'percent' => 65], ['countryCode' => 'HR', 'percent' => 18], ['countryCode' => 'RS', 'percent' => 10]]],
            ['platform' => 'TikTok', 'handle' => '@amina.kovac.demo', 'followers' => 18200, 'averageViews' => 16700, 'sampleSize' => 10, 'engagementRate' => 6.2, 'engagementBasis' => 'views', 'lastUpdated' => $today, 'audienceCountries' => [['countryCode' => 'BA', 'percent' => 60], ['countryCode' => 'HR', 'percent' => 20], ['countryCode' => 'RS', 'percent' => 12]]],
        ], []);
        $portfolio = [];
        $creator->clearPortfolioMedia();
        $this->em->flush(); // Delete old position associations before recreating them.
        foreach ([['dn-rutina', 'cosmetics', 'DN kozmetik', '', true], ['agency-kafa', 'coffee', 'Kafa Ritual', 'Nova Digital', false], ['agency-torba', 'bag', 'Mali Korak', 'Nova Digital', false], ['dn-teksture', 'cosmetics', 'DN kozmetik', '', false]] as $index => [$key, $asset, $client, $agency, $featured]) {
            $media = $this->media($owner, 'creator-portfolio', $asset);
            if (!$creator->getPortfolioMedia()->exists(static fn ($index, CreatorPortfolioMedia $link): bool => $link->getMedia() === $media)) {
                $creator->addPortfolioMedia(new CreatorPortfolioMedia($creator, $media, $index, $campaigns[$key]->getTitle(), 'Instagram'));
            }
            $portfolio[] = ['id' => 'wave-demo-'.$key, 'type' => 'image', 'mediaId' => $media->getId(), 'url' => $media->getUrl(), 'title' => $campaigns[$key]->getTitle(), 'platform' => 'Instagram', 'summary' => $campaigns[$key]->getSummary(), 'source' => 'wave', 'campaignId' => $campaigns[$key]->getId(), 'client' => $client, 'agency' => $agency, 'brief' => 'Predstaviti proizvod kroz stvarnu svakodnevnu situaciju i jasan kreativni zadatak.', 'contribution' => 'Ideja, scenarij, stilizacija, snimanje i montaža sadržaja.', 'projectDate' => $today, 'postUrl' => '', 'deliverables' => [['quantity' => 1, 'format' => 'video', 'duration' => 30], ['quantity' => 3, 'format' => 'photo', 'duration' => null]], 'results' => [['metric' => 'Pregledi — demo', 'value' => 18400 + $index * 1200, 'period' => 'Prvih sedam dana'], ['metric' => 'Sačuvane objave — demo', 'value' => 120 + $index * 20, 'period' => 'Prvih sedam dana']], 'measuredAt' => $today, 'publicPermission' => true, 'featured' => $featured, 'media' => []];
        }
        $packages = [];
        foreach ([['ugc', 'UGC video', 'Video za kanale vašeg brenda, od ideje do završne montaže.', 'All', 'content', 250, 'fixed', 5, 'organic'], ['objava', 'Video + objava', 'Kreiranje sadržaja i objava na mom profilu uz tri kratke priče.', 'Instagram', 'posting', 400, 'fixed', 7, 'organic'], ['fotografije', 'Fotografije proizvoda', 'Pet stiliziranih fotografija vašeg proizvoda u prirodnom svjetlu.', 'All', 'content', 180, 'from', 5, 'organic']] as [$id, $title, $description, $platform, $mode, $price, $priceMode, $days, $usage]) {
            $packages[] = ['id' => 'wave-demo-'.$id, 'title' => $title, 'description' => $description, 'platform' => $platform, 'mode' => $mode, 'price' => $price, 'currency' => 'BAM', 'priceMode' => $priceMode, 'deliveryDays' => $days, 'revisions' => 1, 'usage' => $usage, 'usageDays' => null, 'deliverables' => $id === 'fotografije' ? [['quantity' => 5, 'format' => 'photo', 'duration' => null]] : [['quantity' => 1, 'format' => 'video', 'duration' => 30], ...($mode === 'posting' ? [['quantity' => 3, 'format' => 'story', 'duration' => null]] : [])]];
        }
        $creator->updateProfile('Amina Kovač', 'Beauty', 'Sarajevo', '<p>Najviše volim prirodno svjetlo, stvarne situacije i jasno predstavljanje proizvoda. Od ideje i scenarija do snimanja i montaže, brinem da sadržaj zadrži moj prirodan stil.</p><p>Otvorena sam za saradnje s brendovima i agencijama. Ovo je demo profil: osoba, publika, rezultati i recenzije su izmišljeni.</p>', $social, ['Prikaz proizvoda', 'Video s naracijom', 'Fotografija proizvoda', 'Tutorijal'], null, 'Pravim prirodne, tople videe koji pokazuju vaš proizvod u svakodnevnom životu.', $portfolio, $packages, ['Beauty', 'Lifestyle'], [
            ['question' => 'Objavljuješ li UGC video na svom profilu?', 'answer' => 'Kod UGC paketa isporučujem sadržaj za kanale brenda. Objavu na svom profilu dogovaramo kroz paket Video + objava.'],
            ['question' => 'Možemo li sadržaj koristiti u plaćenim oglasima?', 'answer' => 'Da, uz poseban dogovor o trajanju i načinu korištenja prije početka saradnje.'],
            ['question' => 'Koliko traje izrada sadržaja?', 'answer' => 'Uobičajeno pet do sedam dana od potvrde zadatka i prijema potrebnih materijala.'],
        ]);
        $creator->setCreatorTypes(['ugc-creator', 'influencer', 'photographer']);
        $creator->setPresentation(CreatorPresentation::settings(['languages' => ['bs', 'en'], 'availability' => 'available']));
        $creator->setBirthday(new DateTimeImmutable('2000-06-16'));
        $creator->setAvatarMedia($this->media($owner, 'creator-avatar', 'amina'));
    }
}
