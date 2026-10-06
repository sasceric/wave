<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'background_job')]
#[ORM\Index(name: 'idx_background_job_status_created', columns: ['status', 'created_at'])]
class BackgroundJob
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\Column(length: 100)]
    private string $type;

    #[ORM\Column(length: 32)]
    private string $queue;

    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $dedupeKey;

    #[ORM\Column(type: 'text')]
    private string $payload;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column]
    private int $attempts;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $errorCode;
}
