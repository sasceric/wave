<?php

namespace App\Background;

use App\Message\JobMessage;

final class JobCatalog
{
    public const TYPES = [
        'CreditsAnnouncementMessage' => 'mail',
        'SendEmailMessage' => 'mail',
        'SendWebPushMessage' => 'push',
        'PublishRealtimeMessage' => 'realtime',
        'UnreadMessageReminderTask' => 'background',
        'GenerateThumbnailsMessage' => 'background',
        'MediaIndexingMessage' => 'background',
        'CreatorIndexingMessage' => 'background',
        'CampaignIndexingMessage' => 'background',
        'CompanyIndexingMessage' => 'background',
        'SitemapGenerateTask' => 'background',
        'LogCleanupTask' => 'background',
        'CachePruneTask' => 'background',
        'ExpiredTokenCleanupTask' => 'background',
        'MediaMaintenanceTask' => 'background',
        'IndexReconcileTask' => 'background',
        'QueueMaintenanceTask' => 'background',
    ];

    public static function message(string $type, string $id): JobMessage
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('Unknown background job type.');
        }
        $class = 'App\\Message\\' . $type;

        return new $class($id);
    }
}
