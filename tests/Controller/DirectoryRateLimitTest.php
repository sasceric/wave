<?php

namespace App\Tests\Controller;

use App\EventSubscriber\DirectoryRateLimitSubscriber;
use App\Security\SearchCrawlerVerifier;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class DirectoryRateLimitTest extends KernelTestCase
{
    private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    private DirectoryRateLimitSubscriber $subscriber;

    protected function setUp(): void
    {
        self::bootKernel();
        $storage = new InMemoryStorage();
        $factory = static fn (string $name, int $limit): RateLimiterFactory => new RateLimiterFactory(['id' => $name, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'], $storage);
        // Real crawlers are confirmed by DNS; this fake accepts only one "Google" address.
        $crawlers = new class(new ArrayAdapter()) extends SearchCrawlerVerifier {
            protected function resolves(string $ip, array $suffixes): bool
            {
                return $ip === '66.249.66.1' && in_array('.googlebot.com', $suffixes, true);
            }
        };
        $this->subscriber = new DirectoryRateLimitSubscriber(
            static::getContainer()->get(Security::class), $crawlers, new NullLogger(), new ArrayAdapter(),
            $factory('visitor', 3), $factory('member', 50), $factory('profile_visitor', 2), $factory('profile_member', 50),
        );
    }

    public function testVisitorsAreSlowedButVerifiedSearchEnginesAndLocalAddressesAreNot(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            self::assertNull($this->request('/api/creators', '85.10.20.30'));
        }
        $response = $this->request('/api/creators', '85.10.20.30');
        self::assertSame(429, $response?->getStatusCode());
        self::assertTrue($response->headers->has('Retry-After'));

        // Copying Googlebot's user agent does not help without Google's DNS.
        self::assertSame(429, $this->request('/api/creators', '85.10.20.30', self::GOOGLEBOT)?->getStatusCode());
        for ($i = 0; $i < 6; ++$i) {
            self::assertNull($this->request('/api/creators?offset='.$i, '66.249.66.1', self::GOOGLEBOT));
        }
        // A private address means the real visitor is unknown (proxy setup): never limited.
        for ($i = 0; $i < 6; ++$i) {
            self::assertNull($this->request('/api/creators', '10.0.0.5'));
        }
        // Other endpoints, pages and writes are not limited here.
        self::assertNull($this->request('/api/marketplace/categories', '85.10.20.30'));
        self::assertNull($this->request('/kreatori/amina', '85.10.20.30'));
        self::assertNull($this->request('/api/creators', '85.10.20.30', method: 'POST'));
    }

    public function testProfileViewsHaveTheirOwnAllowance(): void
    {
        self::assertNull($this->request('/api/creators/one', '85.10.20.40'));
        self::assertNull($this->request('/api/creators/two', '85.10.20.40'));
        self::assertSame(429, $this->request('/api/creators/three', '85.10.20.40')?->getStatusCode());
        self::assertNull($this->request('/api/creators/filters', '85.10.20.50'));
        self::assertNull($this->request('/api/creators/filters', '85.10.20.50'));
        self::assertNull($this->request('/api/creators/filters', '85.10.20.50'));
    }

    private function request(string $path, string $ip, string $agent = 'Mozilla/5.0', string $method = 'GET'): ?\Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create($path, $method, server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $agent]);
        $event = new RequestEvent(static::$kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $this->subscriber->limit($event);

        return $event->getResponse();
    }
}
