<?php

namespace App\Api;

final class ProfileSlug
{
    public static function fromName(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $slug = strtolower((string) $ascii);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '', '-');

        return ($slug !== '' ? substr($slug, 0, 80) : 'wave-profile').'-'.bin2hex(random_bytes(3));
    }
}
