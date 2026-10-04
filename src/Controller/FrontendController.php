<?php

namespace App\Controller;

use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Service\SeoMetadataProvider;
use App\Service\SiteOrigin;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class FrontendController
{
    #[Route('/', name: 'frontend_home', methods: ['GET'])]
    #[Route('/{path}', name: 'frontend_route', requirements: ['path' => '.*'], methods: ['GET'], priority: -100)]
    public function __invoke(Request $request, SeoMetadataProvider $seoMetadataProvider, SiteOrigin $siteOrigin): Response
    {
        if (str_starts_with($request->getPathInfo(), '/api')) {
            $locale = LocaleContext::fromRequest($request) ?? 'bs';

            return new JsonResponse(['error' => ApiMessages::get('unknown_endpoint', $locale)], 404);
        }

        $indexPath = dirname(__DIR__, 2).'/public/index.html';
        if (!is_file($indexPath)) {
            throw new ServiceUnavailableHttpException(null, 'Build the Vue app with `npm run build` in frontend before serving the site.');
        }

        $seo = $seoMetadataProvider->forPath($request->getPathInfo());
        $html = file_get_contents($indexPath);
        if (!is_string($html)) {
            throw new ServiceUnavailableHttpException(null, 'Unable to read the built Vue app.');
        }

        return new Response(
            $this->injectSeoMetadata($html, $seo, $siteOrigin->base(), $siteOrigin->url('/pwa-512.png')),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function injectSeoMetadata(string $html, array $seo, string $siteOrigin, string $defaultImage): string
    {
        $title = $this->escape($seo['title']);
        $description = $this->escape($seo['description']);
        $language = match ($seo['locale']) {
            'sr' => 'sr-Latn',
            'cnr' => 'cnr-Latn-ME',
            default => $seo['locale'],
        };
        $html = preg_replace('/(<html\b[^>]*\blang=")[^"]*(")/i', '$1'.$language.'$2', $html, 1) ?? $html;
        $html = preg_replace('~<title>.*?</title>~is', '<title>'.$title.'</title>', $html, 1) ?? $html;
        $html = preg_replace('~\s*<meta\s+name="description"[^>]*>~i', '', $html) ?? $html;

        $head = "\n    <meta name=\"description\" content=\"".$description."\" />";
        $head .= "\n    <meta name=\"wave:origin\" content=\"".$this->escape($siteOrigin)."\" />";
        $head .= "\n    <meta name=\"robots\" content=\"".($seo['noindex'] ? 'noindex, nofollow' : 'index, follow')."\" />";
        $head .= "\n    <meta property=\"og:type\" content=\"website\" />";
        $head .= "\n    <meta property=\"og:site_name\" content=\"Wave\" />";
        $head .= "\n    <meta property=\"og:title\" content=\"".$title."\" />";
        $head .= "\n    <meta property=\"og:description\" content=\"".$description."\" />";
        $head .= "\n    <meta property=\"og:locale\" content=\"".$this->openGraphLocale($seo['locale'])."\" />";
        if ($seo['canonical'] !== null) {
            $canonical = $this->escape($seo['canonical']);
            $head .= "\n    <link rel=\"canonical\" href=\"".$canonical."\" />";
            $head .= "\n    <meta property=\"og:url\" content=\"".$canonical."\" />";
        }
        $image = $this->escape($seo['image'] ?? $defaultImage);
        $head .= "\n    <meta property=\"og:image\" content=\"".$image."\" />";
        $head .= "\n    <meta name=\"twitter:image\" content=\"".$image."\" />";
        $head .= "\n    <meta name=\"twitter:card\" content=\"summary_large_image\" />";
        $head .= "\n    <meta name=\"twitter:title\" content=\"".$title."\" />";
        $head .= "\n    <meta name=\"twitter:description\" content=\"".$description."\" />";
        foreach ($seo['alternates'] as $hreflang => $url) {
            $head .= "\n    <link rel=\"alternate\" hreflang=\"".$this->escape($hreflang)."\" href=\"".$this->escape($url)."\" />";
        }
        if ($seo['structuredData'] !== []) {
            $structuredData = json_encode(
                $seo['structuredData'],
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            $head .= "\n    <script id=\"wave-schema\" type=\"application/ld+json\">".$structuredData.'</script>';
        }

        return str_replace('</head>', $head."\n  </head>", $html);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function openGraphLocale(string $locale): string
    {
        return match ($locale) {
            'bs' => 'bs_BA',
            'hr' => 'hr_HR',
            'sr' => 'sr_RS',
            'cnr' => 'cnr_ME',
            'sl' => 'sl_SI',
            default => 'en_US',
        };
    }
}
