<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/** Shared work progress for campaign and direct-collaboration hires. */
trait CollaborationWork
{
    #[ORM\Column(length: 24, options: ['default' => 'in_progress'])]
    private string $workStatus = 'in_progress';

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $workSubmittedAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $workCompletedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $workChangesReason = null;

    public function getWorkStatus(): string { return $this->workStatus; }
    public function getWorkSubmittedAt(): ?DateTimeImmutable { return $this->workSubmittedAt; }
    public function getWorkCompletedAt(): ?DateTimeImmutable { return $this->workCompletedAt; }
    public function getWorkChangesReason(): ?string { return $this->workChangesReason; }

    public function submitWork(): void
    {
        $this->workStatus = 'submitted';
        $this->workSubmittedAt = new DateTimeImmutable();
        $this->workChangesReason = null;
    }

    public function requestWorkChanges(string $reason): void
    {
        $this->workStatus = 'changes_requested';
        $this->workChangesReason = $reason;
    }

    public function completeWork(): void
    {
        $this->workStatus = 'completed';
        $this->workCompletedAt ??= new DateTimeImmutable();
        $this->workChangesReason = null;
    }
}
