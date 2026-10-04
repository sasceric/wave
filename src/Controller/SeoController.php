<?php

namespace App\Controller;

use App\Service\SitemapGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SeoController
{
    #[Route('/sitemap.xml', name: 'seo_sitemap', methods: ['GET'], priority: 100)]
    public function sitemap(SitemapGenerator $sitemapGenerator): Response
    {
        return new Response(
            $sitemapGenerator->generate(),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Cache-Control' => 'public, max-age=3600',
            ],
        );
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
