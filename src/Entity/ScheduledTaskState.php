<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'scheduled_task_state')]
class ScheduledTaskState
{
    #[ORM\Id]
    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 32)]
    private string $runId;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $exitCode = null;
}
