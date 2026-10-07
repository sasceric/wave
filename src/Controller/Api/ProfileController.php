<?php

namespace App\Controller\Api;

use App\Account\CreatorBirthday;
use App\Api\ApiAccess;
use App\Api\Currency;
use App\Api\CreatorResource;
use App\Api\JsonPayload;
use App\Api\UserResource;
use App\Api\MarketplaceCategoryLabels;
use App\Entity\Company;
use App\Entity\Creator;
use App\Entity\CreatorPortfolioMedia;
use App\Entity\Media;
use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Service\RichTextSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ProfileController
{
    #[Route('/api/me/account-visibility', name: 'api_profile_visibility_update', methods: ['PUT'])]
    public function updateVisibility(
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = $security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => ApiMessages::get('authentication_required', $locale)], 401);
        }
        $data = JsonPayload::fromRequest($request);
        $hideMyAccount = is_array($data) ? ($data['hide_my_account'] ?? null) : null;
        if (!is_int($hideMyAccount) || !in_array($hideMyAccount, [0, 1], true)) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        $user->setHideMyAccount($hideMyAccount === 1);
        $entityManager->flush();

        return new JsonResponse(['data' => UserResource::fromEntity($user, $locale, MarketplaceCategoryLabels::forLocale($entityManager, $locale))]);
    }

    #[Route('/api/me/profile', name: 'api_profile_update', methods: ['PUT'])]
    public function update(
        Request $request,
        EntityManagerInterface $entityManager,
        Security $security,
        CsrfTokenManagerInterface $tokenManager,
        RichTextSanitizer $richTextSanitizer,
    ): JsonResponse {
        $locale = LocaleContext::fromRequest($request);
        if ($locale === null) {
            return new JsonResponse(['error' => ApiMessages::get('unsupported_language', 'bs')], 400);
        }
        if ($csrfError = ApiAccess::requireCsrf($request, $tokenManager, $locale)) {
            return $csrfError;
        }
        $user = $security->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => ApiMessages::get('authentication_required', $locale)], 401);
        }
        $data = JsonPayload::fromRequest($request);
        if ($data === null) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_request', $locale)], 400);
        }

        if ($user->hasRole('ROLE_CREATOR')) {
            $creator = $user->getCreator();
            if (!$creator instanceof Creator) {
                return new JsonResponse(['error' => ApiMessages::get('profile_unavailable', $locale)], 409);
            }
            $name = $this->text($data, 'displayName', 2, 120);
            $birthday = array_key_exists('birthday', $data) ? CreatorBirthday::parse($data['birthday']) : $creator->getBirthday();
            if ($birthday === false) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_birthday', $locale), 'fields' => ['birthday']], 400);
            }
            $category = $this->text($data, 'category', 2, 80);
            $categories = array_key_exists('categories', $data)
                ? $this->stringList($data['categories'], 5, 80)
                : ($category === null ? null : [$category]);
            if ($categories !== null && $categories !== []) {
                $category = $categories[0];
            }
            $bio = $this->richText($data, 'bio', $richTextSanitizer);
            $bioText = $bio === null ? null : $richTextSanitizer->plainText($bio);
            $phone = array_key_exists('phone', $data)
                ? $this->optionalText($data, 'phone', 40)
                : $user->getPhone();
            $city = array_key_exists('city', $data)
                ? $this->optionalText($data, 'city', 70)
                : $user->getCity();
            $countryCode = array_key_exists('countryCode', $data)
                ? $this->optionalCountryCode($data['countryCode'])
                : $user->getCountryCode();
            $tagline = array_key_exists('tagline', $data)
                ? $this->text($data, 'tagline', 0, 180)
                : ($bioText === null ? null : mb_substr($bioText, 0, 180));
            $avatarMedia = array_key_exists('avatarMediaId', $data)
                ? $this->ownedMedia($data['avatarMediaId'], $user, 'creator-avatar', $entityManager)
                : $creator->getAvatarMedia();
            $avatarUrl = $avatarMedia instanceof Media
                ? null
                : $this->imageUrl($data, 'avatarUrl');
            $tags = $this->stringList($data['tags'] ?? null, 10, 40);
            $socialProfiles = $this->socialProfiles($data['socialProfiles'] ?? null);
            $portfolio = $this->portfolio($data['portfolio'] ?? [], $creator, $user, $entityManager);
            $packages = $this->packages($data['packages'] ?? []);
            $faqs = $this->faqs($data['faqs'] ?? []);
            if ($name === null || $category === null || $categories === null || $categories === [] || $bio === null || $phone === false || $city === false || $countryCode === false || $tagline === null || $avatarUrl === false || $avatarMedia === false || $tags === null || $socialProfiles === null || $portfolio === null || $packages === null || $faqs === null) {
                return new JsonResponse(['error' => ApiMessages::get('invalid_profile', $locale)], 400);
            }
            $creator->updateProfile($name, $category, $city ?? '', $bio, $socialProfiles, $tags, $avatarUrl, $tagline, $portfolio['items'], $packages, $categories, $faqs);
            $creator->setAvatarMedia($avatarMedia);
            $creator->setBirthday($birthday);
            $user->setPhone($phone);
            $user->setCity($city);
            $user->setCountryCode($countryCode);
            $creator->clearPortfolioMedia();
            foreach ($portfolio['associations'] as $association) {
                $creator->addPortfolioMedia(new CreatorPortfolioMedia(
                    $creator,
                    $association['media'],
                    $association['position'],
                    $association['title'],
                    $association['platform'],
                ));
            }
            $entityManager->flush();

            return new JsonResponse(['data' => CreatorResource::fromEntity($creator, $locale) + ['birthday' => $creator->getBirthday()?->format('Y-m-d')]]);
        }

        $company = $user->getCompany();
        if (!$company instanceof Company) {
            return new JsonResponse(['error' => ApiMessages::get('profile_unavailable', $locale)], 409);
        }
        $name = $this->text($data, 'name', 2, 120);
        $industries = array_key_exists('industries', $data)
            ? (is_array($data['industries']) && array_is_list($data['industries']) ? $this->stringList($data['industries'], 20, 100) : null)
            : (isset($data['industry']) ? [$this->text($data, 'industry', 2, 100)] : $company->getIndustries());
        $industry = $industries[0] ?? null;
        $coverMedia = array_key_exists('coverMediaId', $data)
            ? $this->ownedMedia($data['coverMediaId'], $user, 'company-cover', $entityManager)
            : $company->getCoverMedia();
        $about = array_key_exists('about', $data)
            ? $this->optionalRichText($data, 'about', $richTextSanitizer)
            : $company->getAbout();
        $phone = array_key_exists('phone', $data)
            ? $this->optionalText($data, 'phone', 40)
            : $user->getPhone();
        $city = array_key_exists('city', $data)
            ? $this->optionalText($data, 'city', 70)
            : $user->getCity();
        $countryCode = array_key_exists('countryCode', $data)
            ? $this->optionalCountryCode($data['countryCode'])
            : $user->getCountryCode();
        $logoMedia = array_key_exists('logoMediaId', $data)
            ? $this->ownedMedia($data['logoMediaId'], $user, 'company-logo', $entityManager)
            : $company->getLogoMedia();
        $logoUrl = $logoMedia instanceof Media
            ? null
            : $this->imageUrl($data, 'logoUrl');
        $socialLinks = array_key_exists('socialLinks', $data)
            ? $this->socialLinks($data['socialLinks'])
            : $company->getSocialLinks();
        if ($name === null
            || $industry === null
            || $industries === null
            || $industries === []
            || array_filter($industries, static fn ($value): bool => !is_string($value) || mb_strlen($value) < 2) !== []
            || $coverMedia === false
            || $about === false
            || $phone === false
            || $city === false
            || $countryCode === false
            || $logoUrl === false
            || $logoMedia === false
            || $socialLinks === null
        ) {
            return new JsonResponse(['error' => ApiMessages::get('invalid_profile', $locale)], 400);
        }
        $company->updateProfile($name, $industry, $logoUrl, $about);
        $company->setIndustries($industries);
        $company->setCoverMedia($coverMedia);
        $company->setLogoMedia($logoMedia);
        $company->setSocialLinks($socialLinks);
        $user->setPhone($phone);
        $user->setCity($city);
        $user->setCountryCode($countryCode);
        $entityManager->flush();

        return new JsonResponse(['data' => UserResource::fromEntity($user, $locale, MarketplaceCategoryLabels::forLocale($entityManager, $locale))]);
    }

    private function text(array $data, string $key, int $minLength, int $maxLength): ?string
    {
        if (!is_string($data[$key] ?? null)) {
            return null;
        }
        $value = trim($data[$key]);

        return mb_strlen($value) >= $minLength && mb_strlen($value) <= $maxLength ? $value : null;
    }

    private function optionalText(array $data, string $key, int $maxLength): string|null|false
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return false;
        }
        $value = trim($value);

        return mb_strlen($value) <= $maxLength ? ($value !== '' ? $value : null) : false;
    }

    private function optionalCountryCode(mixed $value): string|null|false
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_string($value)) {
            return false;
        }
        $countryCode = strtoupper(trim($value));

        return preg_match('/^[A-Z]{2}$/', $countryCode) === 1 ? $countryCode : false;
    }

    private function optionalRichText(array $data, string $key, RichTextSanitizer $sanitizer): string|false
    {
        if (!is_string($data[$key] ?? null)) {
            return false;
        }

        $value = $sanitizer->sanitize(trim($data[$key]));

        return mb_strlen($sanitizer->plainText($value)) <= 1500 ? $value : false;
    }

    private function richText(array $data, string $key, RichTextSanitizer $sanitizer): ?string
    {
        if (!is_string($data[$key] ?? null)) {
            return null;
        }

        $value = $sanitizer->sanitize(trim($data[$key]));

        return mb_strlen($sanitizer->plainText($value)) <= 1500 ? $value : null;
    }

    private function imageUrl(array $data, string $key): string|null|false
    {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }
        if (!is_string($data[$key]) || mb_strlen($data[$key]) > 500 || filter_var($data[$key], FILTER_VALIDATE_URL) === false || parse_url($data[$key], PHP_URL_SCHEME) !== 'https') {
            return false;
        }

        return $data[$key];
    }

    private function stringList(mixed $value, int $maxCount, int $maxLength): ?array
    {
        if (!is_array($value) || count($value) > $maxCount) {
            return null;
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || mb_strlen(trim($item)) > $maxLength) {
                return null;
            }
            $item = trim($item);
            if ($item !== '' && !in_array($item, $items, true)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function socialProfiles(mixed $value): ?array
    {
        if (!is_array($value) || count($value) > 5) {
            return null;
        }
        $profiles = [];
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        foreach ($value as $profile) {
            if (!is_array($profile)
                || !is_string($profile['platform'] ?? null)
                || !is_string($profile['handle'] ?? null)
                || !is_int($profile['followers'] ?? null)
                || $profile['followers'] < 0
                || $profile['followers'] > 200_000_000
            ) {
                return null;
            }
            $platform = trim($profile['platform']);
            $handle = trim($profile['handle']);
            if ($platform === '' || mb_strlen($platform) > 30 || $handle === '' || mb_strlen($handle) > 120) {
                return null;
            }
            $profiles[] = [
                'platform' => $platform,
                'handle' => $handle,
                'followers' => $profile['followers'],
                'lastUpdated' => $today,
            ];
        }

        return $profiles;
    }

    /** @return list<array{platform: string, url: string}>|null */
    private function socialLinks(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 6) {
            return null;
        }

        $allowed = ['Instagram', 'TikTok', 'YouTube', 'Facebook', 'LinkedIn', 'Website'];
        $links = [];
        foreach ($value as $link) {
            if (!is_array($link)
                || !is_string($link['platform'] ?? null)
                || !is_string($link['url'] ?? null)
            ) {
                return null;
            }
            $platform = trim($link['platform']);
            $url = trim($link['url']);
            if (!in_array($platform, $allowed, true)
                || $url === ''
                || mb_strlen($url) > 500
                || filter_var($url, FILTER_VALIDATE_URL) === false
                || parse_url($url, PHP_URL_SCHEME) !== 'https'
            ) {
                return null;
            }
            if (array_filter($links, static fn (array $existing): bool => $existing['platform'] === $platform) !== []) {
                return null;
            }
            $links[] = ['platform' => $platform, 'url' => $url];
        }

        return $links;
    }

    private function portfolio(
        mixed $value,
        Creator $creator,
        User $user,
        EntityManagerInterface $entityManager,
    ): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 4) {
            return null;
        }

        $items = [];
        $associations = [];
        $mediaIds = [];
        foreach ($value as $position => $item) {
            if (!is_array($item)
                || !is_string($item['type'] ?? null)
                || !in_array($item['type'], ['image', 'video'], true)
                || !is_string($item['title'] ?? null)
            ) {
                return null;
            }

            $title = trim($item['title']);
            $platform = $item['platform'] ?? 'All';
            $id = is_string($item['id'] ?? null) && preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $item['id']) === 1
                ? $item['id']
                : bin2hex(random_bytes(8));
            if (mb_strlen($title) > 120
                || !is_string($platform)
                || !in_array($platform, ['All', 'TikTok', 'Instagram', 'YouTube'], true)
            ) {
                return null;
            }

            if ($item['type'] === 'image') {
                if (isset($item['mediaId'])) {
                    $media = $this->ownedMedia($item['mediaId'], $user, 'creator-portfolio', $entityManager);
                    if (!$media instanceof Media || isset($mediaIds[$media->getId()])) {
                        return null;
                    }
                    $mediaId = $media->getId();
                    $mediaIds[$mediaId] = true;
                    $items[] = [
                        'id' => $id,
                        'type' => 'image',
                        'mediaId' => $mediaId,
                        'title' => $title,
                        'platform' => $platform,
                    ];
                    $associations[] = [
                        'media' => $media,
                        'position' => $position,
                        'title' => $title,
                        'platform' => $platform,
                    ];

                    continue;
                }

                $url = $item['url'] ?? null;
                if (!is_string($url)
                    || $url === ''
                    || mb_strlen($url) > 1000
                    || $this->imageUrl(['image' => $url], 'image') === false
                    || !$this->isExistingLegacyImage($creator, $id, $url)
                ) {
                    return null;
                }
                $items[] = [
                    'id' => $id,
                    'type' => 'image',
                    'url' => trim($url),
                    'title' => $title,
                    'platform' => $platform,
                ];

                continue;
            } else {
                $url = $item['url'] ?? null;
                if (!is_string($url) || $url === '' || mb_strlen($url) > 1000) {
                    return null;
                }
                $embedUrl = $this->videoEmbedUrl($url);
                if ($embedUrl === null) {
                    return null;
                }
                $items[] = [
                    'id' => $id,
                    'type' => 'video',
                    'url' => trim($url),
                    'title' => $title,
                    'platform' => $platform,
                    'embedUrl' => $embedUrl,
                ];
            }
        }

        return ['items' => $items, 'associations' => $associations];
    }

    private function ownedMedia(
        mixed $value,
        User $user,
        string $folderSlug,
        EntityManagerInterface $entityManager,
    ): Media|null|false {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) || $value < 1) {
            return false;
        }

        $media = $entityManager->getRepository(Media::class)->find($value);
        if (!$media instanceof Media || $media->getOwner() !== $user || $media->getFolder()->getSlug() !== $folderSlug) {
            return false;
        }

        return $media;
    }

    private function isExistingLegacyImage(Creator $creator, string $id, string $url): bool
    {
        foreach ($creator->getPortfolio() as $existingItem) {
            if (($existingItem['id'] ?? null) === $id
                && ($existingItem['type'] ?? null) === 'image'
                && ($existingItem['url'] ?? null) === trim($url)
            ) {
                return true;
            }
        }

        return false;
    }

    private function packages(mixed $value): ?array
    {
        if (!is_array($value) || count($value) > 12) {
            return null;
        }

        $packages = [];
        $ids = [];
        foreach ($value as $package) {
            if (!is_array($package)
                || !is_string($package['platform'] ?? null)
                || !in_array($package['platform'], ['TikTok', 'Instagram', 'YouTube'], true)
                || !is_string($package['title'] ?? null)
                || !is_string($package['description'] ?? null)
            ) {
                return null;
            }
            $id = is_string($package['id'] ?? null) && preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $package['id']) === 1
                ? $package['id']
                : bin2hex(random_bytes(8));
            $title = trim($package['title']);
            $description = trim($package['description']);
            $price = $package['price'] ?? null;
            $currency = $package['currency'] ?? 'BAM';
            if (isset($ids[$id])
                || mb_strlen($title) < 3
                || mb_strlen($title) > 120
                || mb_strlen($description) < 10
                || mb_strlen($description) > 1200
                || ($price !== null && (!is_int($price) || $price < 1 || $price > 10_000_000))
                || !Currency::isSupported($currency)
            ) {
                return null;
            }

            $ids[$id] = true;
            $packages[] = [
                'id' => $id,
                'platform' => $package['platform'],
                'title' => $title,
                'description' => $description,
                'price' => $price,
                'currency' => $currency,
            ];
        }

        return $packages;
    }

    private function faqs(mixed $value): ?array
    {
        if (!is_array($value) || count($value) > 10) {
            return null;
        }

        $faqs = [];
        foreach ($value as $faq) {
            if (!is_array($faq)
                || !is_string($faq['question'] ?? null)
                || !is_string($faq['answer'] ?? null)
            ) {
                return null;
            }
            $question = trim($faq['question']);
            $answer = trim($faq['answer']);
            if (mb_strlen($question) < 3
                || mb_strlen($question) > 180
                || mb_strlen($answer) < 10
                || mb_strlen($answer) > 1500
            ) {
                return null;
            }
            $faqs[] = ['question' => $question, 'answer' => $answer];
        }

        return $faqs;
    }

    private function videoEmbedUrl(string $url): ?string
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $videoId = null;
        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'], true)) {
            if ($host === 'youtu.be') {
                $videoId = trim($path, '/');
            } else {
                $videoId = parse_url($url, PHP_URL_QUERY);
                parse_str(is_string($videoId) ? $videoId : '', $query);
                $videoId = $query['v'] ?? preg_replace('~^/(?:embed|shorts)/~', '', $path);
            }
            if (is_string($videoId) && preg_match('/^[a-zA-Z0-9_-]{11}$/', $videoId) === 1) {
                return 'https://www.youtube-nocookie.com/embed/'.$videoId;
            }
        }

        if (in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)
            && preg_match('~(?:/video)?/([0-9]{6,12})/?$~', $path, $matches) === 1
        ) {
            return 'https://player.vimeo.com/video/'.$matches[1];
        }

        return null;
    }
}
