<?php

namespace App\Service;

use App\Entity\Notification;

final class NotificationDelivery
{
    public function __construct(
        private readonly RealtimeUpdatePublisher $realtimeUpdatePublisher,
        private readonly WebPushNotificationSender $webPushNotificationSender,
        private readonly bool $enabled = true,
    ) {
    }

    public function deliver(Notification $notification): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->realtimeUpdatePublisher->publish($notification);
        $this->webPushNotificationSender->send($notification);
    }
}
