<?php

namespace App\Controller;

use App\Background\CachedSitemap;
use App\Service\SitemapGenerator;
use App\Service\SiteOrigin;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SeoController
{
    #[Route('/llms.txt', name: 'seo_llms', methods: ['GET'], priority: 100)]
    public function llms(SiteOrigin $origin): Response
    {
        $content = "# Wave\n\n> Wave connects creators and companies for brand collaborations.\n\n";
        $content .= "## Public directories\n\n";
        $content .= '- [Creators]('.$origin->url('/kreatori')."): Discover public creator profiles, portfolios and collaboration packages.\n";
        $content .= '- [Companies]('.$origin->url('/kompanije')."): Discover public company profiles.\n";
        $content .= '- [Campaigns]('.$origin->url('/kampanje')."): Browse open campaign briefs, budgets and requirements.\n";
        $content .= '- [Sitemap]('.$origin->url('/sitemap.xml')."): Localized public pages in Bosnian, Croatian, Serbian, Slovenian, English and Montenegrin.\n\n";
        $content .= "## Account actions\n\nApplications, invitations, package orders and messaging require a signed-in, verified and approved account. Private account and chat data are not public resources. Wave does not expose an autonomous agent or MCP service.\n";

        return new Response($content, headers: ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    #[Route('/.well-known/ai-catalog.json', name: 'seo_ai_catalog', methods: ['GET'], priority: 100)]
    #[Route('/.well-known/ard.json', name: 'seo_ard', methods: ['GET'], priority: 100)]
    public function agentCatalog(SiteOrigin $origin): JsonResponse
    {
        // No agent tools are exposed. Publish an honest, valid empty catalog;
        // public website discovery is documented in llms.txt and the sitemap.
        return new JsonResponse([
            'specVersion' => '1.0',
            'host' => ['displayName' => 'Wave', 'documentationUrl' => $origin->url('/llms.txt')],
            'entries' => [],
        ], headers: ['Cache-Control' => 'public, max-age=3600']);
    }

    #[Route('/sitemap.xml', name: 'seo_sitemap', methods: ['GET'], priority: 100)]
    public function sitemap(SitemapGenerator $sitemapGenerator, CachedSitemap $cached, #[Autowire('%wave.queue.enabled%')] bool $queued): Response
    {
        if ($queued) {
            $path = $cached->index();

            return $path === null ? new Response('Sitemap generation pending.', 503, ['Retry-After' => '60', 'Cache-Control' => 'no-store']) : new BinaryFileResponse($path, headers: ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
        }

        return new Response(
            $sitemapGenerator->generate(),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Cache-Control' => 'public, max-age=3600',
            ],
        );
    }

    #[Route('/sitemaps/{name}', requirements: ['name' => '[a-f0-9]{32}-[0-9]{1,5}\\.xml'], methods: ['GET'], priority: 100)]
    public function part(string $name, CachedSitemap $cached): Response
    {
        $path = $cached->part($name);

        return $path === null ? new Response('', 404) : new BinaryFileResponse($path, headers: ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=86400']);
    }

    #[Route('/robots.txt', name: 'seo_robots', methods: ['GET'], priority: 100)]
    public function robots(SitemapGenerator $sitemapGenerator): Response
    {
        return new Response(
            $sitemapGenerator->robotsTxt(),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'public, max-age=3600',
            ],
        );
    }
}
