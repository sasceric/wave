<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Creator;
use App\Entity\CreatorPortfolioMedia;
use App\Entity\Media;
use App\Localization\LocalizedRouteMap;
use App\Reviews\CollaborationFeedback;
use App\Service\ProfileStructuredData;
use App\Service\SiteOrigin;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ProfileStructuredDataTest extends TestCase
{
    public function testGalleryIncludesOnlyPublicImagesWithoutAssumingImageRights(): void
    {
        $creator = new Creator('example', 'Example creator', 'Photography', '', '', [], portfolio: [
            ['url' => '/uploads/public.jpg', 'title' => 'Client work'],
            ['url' => 'https://images.example.test/external.jpg', 'publicPermission' => true],
            ['mediaId' => 42],
            ['url' => '/uploads/private.jpg', 'publicPermission' => false],
            ['url' => 'https://example.test/video', 'type' => 'video'],
            ['mediaId' => 999, 'url' => '/uploads/stale.jpg'],
            ['url' => ''],
        ]);
        $media = $this->createStub(Media::class);
        $media->method('getId')->willReturn(42);
        $media->method('getUrl')->willReturn('/uploads/stored.webp');
        $creator->addPortfolioMedia(new CreatorPortfolioMedia($creator, $media, 0, 'Stored work', ''));

        $schema = $this->factory()->creator($creator, 'bs');

        self::assertSame('ImageGallery', $schema['subjectOf']['@type']);
        self::assertSame([
            'https://wave.ba/uploads/public.jpg',
            'https://images.example.test/external.jpg',
            'https://wave.ba/uploads/stored.webp',
        ], $schema['subjectOf']['image']);
        self::assertSame(['@id' => 'https://wave.ba/kreatori/example#webpage'], $schema['subjectOf']['isPartOf']);
        $json = json_encode($schema, JSON_THROW_ON_ERROR);
        foreach (['ImageObject', 'copyrightNotice', 'creditText', 'acquireLicensePage', '"license"'] as $claim) {
            self::assertStringNotContainsString($claim, $json);
        }
    }

    public function testGalleryPreservesFaqAndIsOmittedWhenNoPublicImageExists(): void
    {
        $creator = new Creator('example', 'Example creator', 'Photography', '', '', [],
            portfolio: [['url' => '/uploads/private.jpg', 'publicPermission' => false]],
            faqs: [['question' => 'Where?', 'answer' => 'Sarajevo.']]);
        $factory = $this->factory();
        $schema = $factory->creator($creator, 'en');
        self::assertSame('FAQPage', $schema['subjectOf']['@type']);
        self::assertSame('Where?', $schema['subjectOf']['mainEntity'][0]['name']);

        $creator->setPortfolio([['url' => '/uploads/public.jpg']]);
        $schema = $factory->creator($creator, 'en');
        self::assertSame(['FAQPage', 'ImageGallery'], array_column($schema['subjectOf'], '@type'));
        self::assertSame('Sarajevo.', $schema['subjectOf'][0]['mainEntity'][0]['acceptedAnswer']['text']);

        $creator->setFaqs([]);
        $creator->setPortfolio([]);
        self::assertArrayNotHasKey('subjectOf', $factory->creator($creator, 'en'));
    }

    private function factory(): ProfileStructuredData
    {
        return new ProfileStructuredData(
            new LocalizedRouteMap(dirname(__DIR__, 2)),
            new SiteOrigin('https://wave.ba'),
            new CollaborationFeedback($this->createStub(EntityManagerInterface::class)),
        );
    }
}
