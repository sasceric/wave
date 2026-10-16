<?php

namespace App\Api;

final class CampaignApplicationRanges
{
    public static function fromCount(int $count): string
    {
        return match (true) {
            $count < 5 => 'fewerThan5',
            $count <= 10 => 'upTo10',
            $count <= 20 => '10to20',
            $count <= 50 => '20to50',
            default => '50plus',
        };
    }
}
