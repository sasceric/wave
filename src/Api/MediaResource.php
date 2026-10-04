<?php

namespace App\Api;

use App\Entity\Media;

final class MediaResource
{
    public static function fromEntity(Media $media): array
    {
        return [
            'id' => $media->getId(),
            'name' => $media->getOriginalName(),
            'url' => $media->getUrl(),
            'mimeType' => $media->getMimeType(),
            'fileSize' => $media->getFileSize(),
            'folder' => [
                'id' => $media->getFolder()->getId(),
                'slug' => $media->getFolder()->getSlug(),
                'name' => $media->getFolder()->getName(),
            ],
            'createdAt' => $media->getCreatedAt()->format(DATE_ATOM),
        ];
    }
}
