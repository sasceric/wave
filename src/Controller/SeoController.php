<?php

namespace App\Controller;

use App\Background\CachedSitemap;
use App\Service\SitemapGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SeoController
{
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
