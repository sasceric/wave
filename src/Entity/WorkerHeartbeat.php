<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'worker_heartbeat')]
class WorkerHeartbeat
{
    #[ORM\Id]
    #[ORM\Column(length: 100)]
    private string $id;

    #[ORM\Column(length: 10)]
    private string $mode;

    #[ORM\Column(type: 'text')]
    private string $queues;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: 'bigint')]
    private int $memoryBytes;

    #[ORM\Column]
    private int $handled;
}
