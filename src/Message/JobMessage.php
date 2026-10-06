<?php

namespace App\Message;

abstract readonly class JobMessage
{
    public function __construct(public string $jobId)
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $jobId) !== 1) {
            throw new \InvalidArgumentException('Invalid job identifier.');
        }
    }
}
