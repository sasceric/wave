<?php

namespace App\Account;

use App\Entity\Company;

/** Structured agency capabilities. Prices and delivery terms belong to packages. */
final class AgencyServices
{
    public const PLATFORMS = ['Instagram', 'TikTok', 'YouTube', 'Facebook', 'LinkedIn', 'Twitch', 'Spotify', 'Website'];

    public static function saved(Company $company): array
    {
        if ($company->getServices() !== []) return $company->getServices();
        $provider = $company->getOwner()?->getCreator();
        return $provider?->isAgencyProfile() ? self::parse($provider->getTags()) : [];
    }

    public static function parse(mixed $input, array $previous = []): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) > 10) throw new \InvalidArgumentException('services');
        $rows = [];
        $ids = [];
        foreach ($input as $index => $raw) {
            // Accept legacy clients and preserve their service labels without inventing descriptions.
            if (is_string($raw)) {
                $label = $raw;
                $raw = null;
                foreach ($previous as $existing) {
                    if ($existing['title'] === trim($label)) { $raw = $existing; break; }
                }
                $raw ??= ['id' => 'legacy-'.substr(hash('sha256', $index.':'.$label), 0, 20), 'title' => $label];
            }
            if (!is_array($raw)) throw new \InvalidArgumentException('services');
            $id = $raw['id'] ?? bin2hex(random_bytes(12));
            if (!is_string($id) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id) !== 1 || isset($ids[$id])) throw new \InvalidArgumentException('id');
            $title = self::text($raw['title'] ?? '', 120);
            if ($title === '') throw new \InvalidArgumentException('title');
            $description = self::text($raw['description'] ?? '', 800);
            $deliverables = $raw['deliverables'] ?? [];
            $platforms = $raw['platforms'] ?? [];
            $engagement = $raw['engagement'] ?? 'both';
            if (!is_array($deliverables) || !array_is_list($deliverables) || count($deliverables) > 10
                || !is_array($platforms) || !array_is_list($platforms) || count($platforms) > count(self::PLATFORMS)
                || !is_string($engagement) || !in_array($engagement, ['project', 'monthly', 'both'], true)) throw new \InvalidArgumentException('service');
            $deliverables = array_map(static function (mixed $value): string {
                $text = self::text($value, 180);
                if ($text === '') throw new \InvalidArgumentException('deliverables');
                return $text;
            }, $deliverables);
            foreach ($platforms as $platform) if (!is_string($platform) || !in_array($platform, self::PLATFORMS, true)) throw new \InvalidArgumentException('platforms');
            $ids[$id] = true;
            $rows[] = compact('id', 'title', 'description', 'deliverables', 'platforms', 'engagement');
        }
        return $rows;
    }

    private static function text(mixed $value, int $limit): string
    {
        if (!is_string($value) || mb_strlen($value) > $limit) throw new \InvalidArgumentException('service');
        return trim($value);
    }
}
