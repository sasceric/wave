<?php

namespace App\Api;

use App\Entity\Media;
use App\Media\ImageVariants;

final class MediaImageResource
{
    public static function fromEntity(?Media $media, int $preferredWidth = 320): ?array
    {
        if ($media === null) {
            return null;
        }
        $url = '/api/media/'.$media->getId().'/thumbnail/'.ImageVariants::VERSION.'/';
        $width = $media->getWidth();
        $height = $media->getHeight();
        $candidates = [];
        foreach (ImageVariants::WIDTHS as $variantWidth) {
            $actualWidth = $width === null ? $variantWidth : min($width, $variantWidth);
            $candidates[$actualWidth] ??= $url.$variantWidth.' '.$actualWidth.'w';
        }
        $displayWidth = $width === null ? null : min($width, $preferredWidth);

        return [
            'src' => $url.$preferredWidth,
            'srcset' => implode(', ', $candidates),
            'width' => $displayWidth,
            'height' => $width !== null && $height !== null
                ? max(1, (int) round($height * $displayWidth / $width))
                : null,
        ];
    }
}
