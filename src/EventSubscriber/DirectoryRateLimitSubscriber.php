<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Localization\ApiMessages;
use App\Localization\LocaleContext;
use App\Security\SearchCrawlerVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Slows bulk copying of the public creator, company and campaign directory.
 *
 * Limits are far above what a person browsing needs; signed-in, approved accounts get
 * more. Pages (HTML) are never limited, so link previews and search results are not
 * affected. A visitor that passes a limit is still served if it is a verified search
 * engine crawler (Google renders profiles through these endpoints), so SEO is kept.
 */
final class DirectoryRateLimitSubscriber implements EventSubscriberInterface
{
    private const DIRECTORY = '~^/api/(creators|companies|campaigns)(/|$)~';
    private const PROFILE = '~^/api/(creators|companies|campaigns)/(?!filters$)[^/]+$~';

    public function __construct(
        private readonly Security $security,
        private readonly SearchCrawlerVerifier $crawlers,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
        #[Target('wave_directory_visitor')] private readonly RateLimiterFactoryInterface $visitorRequests,
        #[Target('wave_directory_member')] private readonly RateLimiterFactoryInterface $memberRequests,
        #[Target('wave_profile_views_visitor')] private readonly RateLimiterFactoryInterface $visitorProfiles,
        #[Target('wave_profile_views_member')] private readonly RateLimiterFactoryInterface $memberProfiles,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the firewall (priority 8) so signed-in members are recognised.
        return [KernelEvents::REQUEST => ['limit', 4]];
    }

    public function limit(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!$event->isMainRequest() || !$request->isMethod('GET') || preg_match(self::DIRECTORY, $path) !== 1) {
            return;
        }
        $ip = $request->getClientIp();
        // A missing, local or private address means the real visitor is unknown
        // (a misconfigured proxy would make every visitor share it): never limit it.
        if ($ip === null || IpUtils::isPrivateIp($ip)) {
            return;
        }
        $user = $this->security->getUser();
        $member = $user instanceof User && !$user->isDeleted() && $user->isApproved() && $user->isEmailVerified();
        $key = $member ? 'user:'.$user->getId() : 'ip:'.$ip;
        $limits = [($member ? $this->memberRequests : $this->visitorRequests)->create($key)->consume()];
        if (preg_match(self::PROFILE, $path) === 1) {
            $limits[] = ($member ? $this->memberProfiles : $this->visitorProfiles)->create($key)->consume();
        }
        $rejected = array_values(array_filter($limits, static fn ($limit): bool => !$limit->isAccepted()));
        if ($rejected === []) {
            return;
        }
        $agent = $request->headers->get('User-Agent');
        if ($this->crawlers->isVerified($ip, $agent)) {
            return;
        }
        $this->report($ip, $path, $agent, $member);
        $retryAfter = max(1, $rejected[0]->getRetryAfter()->getTimestamp() - time());
        $locale = LocaleContext::fromRequest($request) ?? 'bs';
        $event->setResponse(new JsonResponse(
            ['error' => ApiMessages::get('rate_limited', $locale), 'availableAt' => $rejected[0]->getRetryAfter()->format(DATE_ATOM)],
            429,
            ['Retry-After' => (string) $retryAfter, 'Cache-Control' => 'private, no-store'],
        ));
    }

    /** One warning per visitor every 10 minutes, so a scraper cannot flood the log. */
    private function report(string $ip, string $path, ?string $agent, bool $member): void
    {
        $this->cache->get('directory_limit_report_'.hash('sha256', $ip), function (ItemInterface $item) use ($ip, $path, $agent, $member): bool {
            $item->expiresAfter(600);
            $this->logger->warning('directory.rate_limited', [
                'ip' => $ip, 'path' => $path, 'member' => $member,
                'userAgent' => mb_substr((string) $agent, 0, 200),
                'claimsCrawler' => $this->crawlers->claimsToBeCrawler($agent),
            ]);

            return true;
        });
    }
}
