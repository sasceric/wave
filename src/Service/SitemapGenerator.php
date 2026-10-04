<?php

namespace App\Service;

use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Creator;
use App\Localization\LocalizedRouteMap;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class SitemapGenerator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LocalizedRouteMap $routeMap,
        private readonly SiteOrigin $siteOrigin,
    ) {
    }

    public function generate(): string
    {
        $pages = [
            ['home', []],
            ['creators', []],
            ['companies', []],
            ['campaigns', []],
        ];

        $creators = $this->entityManager->getRepository(Creator::class)->createQueryBuilder('creator')
            ->leftJoin('creator.owner', 'owner')
            ->andWhere('owner.id IS NULL OR (owner.approved = :approved AND owner.hideMyAccount = :visible)')
            ->setParameter('approved', true)
            ->setParameter('visible', false)
            ->orderBy('creator.slug', 'ASC')
            ->getQuery()
            ->getResult();
        foreach ($creators as $creator) {
            if ($creator instanceof Creator) {
                $pages[] = ['creator-profile', ['slug' => $creator->getSlug()]];
            }
        }
        $companies = $this->entityManager->getRepository(Company::class)->createQueryBuilder('company')
            ->leftJoin('company.owner', 'owner')
            ->andWhere('owner.id IS NULL OR (owner.approved = :approved AND owner.hideMyAccount = :visible)')
            ->setParameter('approved', true)
            ->setParameter('visible', false)
            ->orderBy('company.slug', 'ASC')
            ->getQuery()
            ->getResult();
        foreach ($companies as $company) {
            if ($company instanceof Company) {
                $pages[] = ['company-profile', ['slug' => $company->getSlug()]];
            }
        }
        $campaigns = $this->entityManager->getRepository(Campaign::class)->createQueryBuilder('campaign')
            ->join('campaign.company', 'company')
            ->leftJoin('company.owner', 'companyOwner')
            ->andWhere('campaign.status = :status')
            ->andWhere('campaign.closesAt >= :today')
            ->andWhere('companyOwner.id IS NULL OR (companyOwner.approved = :approved AND companyOwner.hideMyAccount = :visible)')
            ->setParameter('status', 'open')
            ->setParameter('today', new DateTimeImmutable('today'))
            ->setParameter('approved', true)
            ->setParameter('visible', false)
            ->orderBy('campaign.slug', 'ASC')
            ->getQuery()
            ->getResult();
        foreach ($campaigns as $campaign) {
            if ($campaign instanceof Campaign) {
                $pages[] = ['campaign-detail', ['slug' => $campaign->getSlug()]];
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">';
        foreach ($pages as [$routeName, $params]) {
            $alternates = [];
            foreach ($this->routeMap->localizedAlternates($routeName, $params) as $locale => $path) {
                $alternates[$locale] = $this->siteOrigin->url($path);
            }

            foreach ($this->routeMap->locales() as $locale) {
                $path = $this->routeMap->localizedPath($routeName, $locale, $params);
                $xml .= '<url><loc>'.$this->escape($this->siteOrigin->url($path)).'</loc>';
                foreach ($alternates as $hreflang => $url) {
                    $xml .= '<xhtml:link rel="alternate" hreflang="'.$this->escape($hreflang).'" href="'.$this->escape($url).'"/>';
                }
                $xml .= '</url>';
            }
        }
        $xml .= '</urlset>';

        return $xml;
    }

    public function robotsTxt(): string
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /api/auth/',
            'Disallow: /api/me/',
            'Disallow: /api/company/',
            'Disallow: /api/moderation/',
            'Disallow: /api/admin/',
        ];
        foreach ($this->routeMap->locales() as $locale) {
            foreach ([
                'account',
                'verify-email',
                'reset-password',
                'moderation',
                'admin',
                'admin-homepage',
                'admin-creators',
                'admin-companies',
                'admin-campaigns',
            ] as $routeName) {
                $lines[] = 'Disallow: '.$this->routeMap->localizedPath($routeName, $locale);
            }
        }
        foreach ([
            '/account',
            '/verify-email',
            '/reset-password',
            '/moderation',
            '/admin',
            '/admin/homepage',
            '/admin/creators',
            '/admin/companies',
            '/admin/campaigns',
        ] as $legacyPath) {
            $lines[] = 'Disallow: '.$legacyPath;
        }
        $lines[] = 'Sitemap: '.$this->siteOrigin->url('/sitemap.xml');

        return implode("\n", $lines)."\n";
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
