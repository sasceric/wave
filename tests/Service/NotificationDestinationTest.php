<?php

namespace App\Tests\Service;

use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\Notification;
use App\Entity\User;
use App\Localization\LocalizedRouteMap;
use App\Service\UnreadInboxCounter;
use App\Service\WebPushNotificationSender;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NotificationDestinationTest extends TestCase
{
    public function testApplicationPushOpensThePrivateCampaignPageInEachLocale(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $routes = new LocalizedRouteMap(dirname(__DIR__, 2));
        $sender = new WebPushNotificationSender($em, new NullLogger(), '', '', '', new UnreadInboxCounter($em), routes: $routes);
        $target = new \ReflectionMethod($sender, 'targetFor');
        $company = new Company('brand', 'Brand', 'Food');
        $campaign = new Campaign('test-kampanja', 'Campaign', 'Summary', 'Brief', 'Food', [], [], 100, 200, 'Zenica', 3,
            new \DateTimeImmutable('+7 days'), new \DateTimeImmutable('today'), $company);
        foreach ($routes->locales() as $locale) {
            $recipient = new User('company@example.test', 'ROLE_COMPANY');
            $recipient->setPreferredLocale($locale);
            $notification = new Notification($recipient, 'application_received', campaign: $campaign);
            self::assertSame($routes->localizedPath('account-campaign-detail', $locale, ['slug' => 'test-kampanja']), $target->invoke($sender, $notification));
        }
        $recipient = new User('fallback@example.test', 'ROLE_COMPANY');
        self::assertSame('/account/campaigns', $target->invoke($sender, new Notification($recipient, 'application_received')));
    }
}
