<?php

namespace App\Listing;

use App\Entity\ListingAd;
use App\Entity\Media;
use App\Api\MediaImageResource;

final class ListingAds
{
    public const KINDS = ['creators', 'companies', 'campaigns'];
    public const ARTWORKS = ['campaign-invitation' => '/images/listing-ads/create-campaign.svg'];
    public const PALETTES = ['forest', 'coral', 'sage', 'paper', 'surface'];

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public static function content(array $input): array
    {
        $content = [];
        foreach (['name' => 100, 'title' => 100, 'body' => 300, 'buttonLabel' => 40, 'url' => 1000] as $field => $max) {
            if (!is_string($input[$field] ?? null) || mb_strlen(trim($input[$field])) > $max) throw new \InvalidArgumentException('invalid');
            $content[$field] = trim($input[$field]);
        }
        foreach (['helpLabel' => 80, 'helpUrl' => 1000] as $field => $max) {
            $value = $input[$field] ?? '';
            if (!is_string($value) || mb_strlen(trim($value)) > $max) throw new \InvalidArgumentException('invalid');
            $content[$field] = trim($value);
        }
        if (($content['helpLabel'] === '') !== ($content['helpUrl'] === '') || !self::safeUrl($content['helpUrl'])) throw new \InvalidArgumentException('invalid');
        if ($content['name'] === '' || $content['title'] === '' || !in_array($input['palette'] ?? null, self::PALETTES, true) || !in_array($input['span'] ?? null, [1, 2], true)) throw new \InvalidArgumentException('invalid');
        if (($content['url'] === '') !== ($content['buttonLabel'] === '') || !self::safeUrl($content['url'])) throw new \InvalidArgumentException('invalid');
        if (!is_string($input['artwork'] ?? '') || (($input['artwork'] ?? '') !== '' && !isset(self::ARTWORKS[$input['artwork']]))) throw new \InvalidArgumentException('invalid');
        $content['artwork'] = $input['artwork'] ?? '';
        $content['palette'] = $input['palette'];
        $content['span'] = $input['span'];
        $placements = $input['placements'] ?? null;
        if (!is_array($placements) || !array_is_list($placements) || count($placements) < 1 || count($placements) > 12) throw new \InvalidArgumentException('invalid');
        $occupied = [];
        $content['placements'] = [];
        foreach ($placements as $place) {
            if (!is_array($place) || !is_int($place['row'] ?? null) || !is_int($place['column'] ?? null)
                || $place['row'] < 1 || $place['row'] > 100 || $place['column'] < 1 || $place['column'] + $content['span'] > 5) throw new \InvalidArgumentException('invalid');
            foreach (range($place['column'], $place['column'] + $content['span'] - 1) as $column) {
                $key = $place['row'].':'.$column;
                if (isset($occupied[$key])) throw new \InvalidArgumentException('overlap');
                $occupied[$key] = true;
            }
            $content['placements'][] = ['row' => $place['row'], 'column' => $place['column']];
        }
        usort($content['placements'], static fn (array $a, array $b): int => [$a['row'], $a['column']] <=> [$b['row'], $b['column']]);
        return $content;
    }

    public static function safeUrl(string $url): bool
    {
        if ($url === '') return true;
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) return false;
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) return true;
        $parts = parse_url($url);
        return is_array($parts) && in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            && !isset($parts['user']) && !isset($parts['pass']) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /** @param list<ListingAd> $others */
    public static function assertAvailable(ListingAd $ad, array $others): void
    {
        if (!$ad->isEnabled()) return;
        $occupied = [];
        foreach ([$ad, ...array_filter($others, static fn (ListingAd $other): bool => $other->isEnabled() && $other->getId() !== $ad->getId())] as $entry) {
            $content = $entry->getContent();
            foreach ($content['placements'] as $place) {
                for ($column = $place['column']; $column < $place['column'] + $content['span']; ++$column) {
                    $key = $place['row'].':'.$column;
                    if (isset($occupied[$key])) throw new \InvalidArgumentException('overlap');
                    $occupied[$key] = true;
                }
            }
        }
    }

    /** @return array<string, mixed> */
    public static function resource(ListingAd $ad): array
    {
        return [...$ad->getContent(), 'id' => $ad->getId(), 'kind' => $ad->getKind(), 'enabled' => $ad->isEnabled(),
            'imageId' => $ad->getImage()?->getId(), 'imageUrl' => $ad->getImage()?->getUrl() ?? (self::ARTWORKS[$ad->getContent()['artwork'] ?? ''] ?? ''),
            'image' => $ad->getImage() instanceof Media ? MediaImageResource::fromEntity($ad->getImage()) : null];
    }
}
