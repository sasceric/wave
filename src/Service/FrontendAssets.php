<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class FrontendAssets
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/build/manifest.json')]
        private readonly string $manifestPath,
    ) {
    }

    /** @return list<string> */
    public function homepageStyles(): array
    {
        if (!is_readable($this->manifestPath)) {
            return [];
        }

        $content = file_get_contents($this->manifestPath);
        $manifest = is_string($content) ? json_decode($content, true) : null;
        $styles = is_array($manifest) ? ($manifest['src/views/HomeView.vue']['css'] ?? []) : [];
        if (!is_array($styles)) {
            return [];
        }

        $paths = [];
        foreach ($styles as $style) {
            if (is_string($style) && preg_match('~^build/assets/[a-zA-Z0-9_-]+\.css$~D', $style) === 1) {
                $paths[] = '/'.$style;
            }
        }

        return array_values(array_unique($paths));
    }
}
