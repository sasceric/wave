<?php

namespace App\Tests\Search;

use App\Entity\{Campaign, Company};
use App\Localization\LocalizedRouteMap;
use App\Search\SearchDocuments;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class SearchDocumentsTest extends TestCase
{
    public function testCampaignSearchIncludesFullLocalizedSummaryAndDescription(): void
    {
        $summary = str_repeat('Summary context ', 10).'summaryneedlequartz';
        $campaign = new Campaign('summary-campaign', 'Campaign title', $summary, '<p>descriptionneedleamber</p>', 'Beauty', [], [], 100, 300, 'Sarajevo', 1, new \DateTimeImmutable('+10 days'), new \DateTimeImmutable(), new Company('brand', 'Brand', 'Beauty'));
        $campaign->setTranslations(['de' => ['summary' => '<p>zusammenfassungnadelquarz</p>', 'description' => '<p>beschreibungnadelbernstein</p>']]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('find')->with(Campaign::class, 1)->willReturn($campaign);
        $routes = new LocalizedRouteMap(dirname(__DIR__, 2));
        $documents = (new SearchDocuments($em, $routes))->build('campaign', 1, array_fill_keys($routes->locales(), []));

        self::assertCount(7, $documents);
        foreach ($documents as $document) {
            self::assertStringNotContainsString('<p>', $document['text']);
            if ($document['locale'] === 'de') {
                self::assertStringContainsString('zusammenfassungnadelquarz', $document['text']);
                self::assertStringContainsString('beschreibungnadelbernstein', $document['text']);
                self::assertStringNotContainsString('summaryneedlequartz', $document['text']);
            } else {
                self::assertStringContainsString('summaryneedlequartz', $document['text']);
                self::assertStringContainsString('descriptionneedleamber', $document['text']);
                self::assertStringStartsWith($summary, $document['text']);
            }
        }
    }
}
