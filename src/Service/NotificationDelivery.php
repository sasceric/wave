<?php

namespace App\Service;

use App\Entity\CampaignMessage;
use App\Entity\Notification;

final class NotificationDelivery
{
    public function __construct(
        private readonly RealtimeUpdatePublisher $realtimeUpdatePublisher,
        private readonly WebPushNotificationSender $webPushNotificationSender,
        private readonly bool $enabled = true,
    ) {
    }

    public function deliver(Notification $notification, ?CampaignMessage $message = null): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->realtimeUpdatePublisher->publish($notification, $message);
        $this->webPushNotificationSender->send($notification);
    }
}
