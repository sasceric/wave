<?php

namespace App\Support;

use App\Localization\LocaleContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SupportCopy
{
    private array $catalogs = [];

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    public function get(string $locale, string $key): string
    {
        if (!in_array($locale, LocaleContext::SUPPORTED, true)) $locale = 'bs';
        if (!isset($this->catalogs[$locale])) {
            $json = file_get_contents($this->projectDir.'/frontend/src/locales/'.$locale.'.json');
            $catalog = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $this->catalogs[$locale] = $catalog['support'];
        }
        $value = $this->catalogs[$locale];
        foreach (explode('.', $key) as $part) $value = $value[$part];

        return str_replace("{'@'}", '@', $value);
    }
}
