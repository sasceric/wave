<?php

namespace App\Tests\Service;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class WebPushDiscoveryTest extends TestCase
{
    public function testWebPushHttpClientAndMessageFactoriesAreDiscoverable(): void
    {
        self::assertInstanceOf(ClientInterface::class, Psr18ClientDiscovery::find());
        self::assertInstanceOf(RequestFactoryInterface::class, Psr17FactoryDiscovery::findRequestFactory());
        self::assertInstanceOf(ResponseFactoryInterface::class, Psr17FactoryDiscovery::findResponseFactory());
        self::assertInstanceOf(StreamFactoryInterface::class, Psr17FactoryDiscovery::findStreamFactory());
        self::assertInstanceOf(WebPush::class, new WebPush());
    }
}
