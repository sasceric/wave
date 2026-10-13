<?php

namespace App\Api;

use Symfony\Component\String\Slugger\AsciiSlugger;

final class ProfileSlug
{
    public static function fromName(string $name): string
    {
        $slug = (new AsciiSlugger('bs'))->slug($name)->lower()->toString();

        return $slug !== '' ? rtrim(substr($slug, 0, 80), '-') : 'wave-profile';
    }
}
