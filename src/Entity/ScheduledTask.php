<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'wave_scheduled_task')]
#[ORM\Index(name: 'idx_wave_task_due', columns: ['status', 'next_run_at'])]
class ScheduledTask
{
    #[ORM\Id]
    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column]
    private int $intervalSeconds;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column]
    private \DateTimeImmutable $nextRunAt;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $activeJobId;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastStartedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastFinishedAt;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $lastOutcome;
}
