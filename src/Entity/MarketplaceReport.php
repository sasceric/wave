<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'marketplace_report')]
#[ORM\Index(name: 'idx_report_status_date', columns: ['status', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_report_reporter', columns: ['reporter_id', 'created_at'])]
#[ORM\Index(name: 'idx_report_target', columns: ['target_user_id'])]
class MarketplaceReport
{
    /** @var \Doctrine\Common\Collections\Collection<int, Media> */
    #[ORM\ManyToMany(targetEntity: Media::class)]
    #[ORM\JoinTable(name: 'marketplace_report_media')]
    #[ORM\JoinColumn(name: 'report_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'media_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private \Doctrine\Common\Collections\Collection $attachments;

    /** @return \Doctrine\Common\Collections\Collection<int, Media> */
    public function getAttachments(): \Doctrine\Common\Collections\Collection { return $this->attachments; }

    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    public function __construct(string $id, User $reporter, User $targetUser, string $targetKind, string $targetSlug, string $targetName, string $category, string $reason, string $description, string $status, string $response, int $version, \DateTimeImmutable $createdAt, \DateTimeImmutable $updatedAt)
    {
        $this->attachments = new \Doctrine\Common\Collections\ArrayCollection();
        $this->id = $id;
        $this->reporter = $reporter;
        $this->targetUser = $targetUser;
        $this->targetKind = $targetKind;
        $this->targetSlug = $targetSlug;
        $this->targetName = $targetName;
        $this->category = $category;
        $this->reason = $reason;
        $this->description = $description;
        $this->status = $status;
        $this->response = $response;
        $this->version = $version;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): string { return $this->id; }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reporter_id', nullable: false, onDelete: 'CASCADE')]
    private User $reporter;

    public function getReporter(): User { return $this->reporter; }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'target_user_id', nullable: false, onDelete: 'CASCADE')]
    private User $targetUser;

    public function getTargetUser(): User { return $this->targetUser; }

    #[ORM\Column(length: 24)]
    private string $targetKind;

    public function getTargetKind(): string { return $this->targetKind; }

    #[ORM\Column(length: 200)]
    private string $targetSlug;

    public function getTargetSlug(): string { return $this->targetSlug; }

    #[ORM\Column(length: 200)]
    private string $targetName;

    public function getTargetName(): string { return $this->targetName; }

    #[ORM\Column(length: 40)]
    private string $category;

    public function getCategory(): string { return $this->category; }

    #[ORM\Column(length: 40)]
    private string $reason;

    public function getReason(): string { return $this->reason; }

    #[ORM\Column(type: 'text')]
    private string $description;

    public function getDescription(): string { return $this->description; }

    #[ORM\Column(length: 20)]
    private string $status;

    public function getStatus(): string { return $this->status; }

    #[ORM\Column(type: 'text')]
    private string $response;

    public function getResponse(): string { return $this->response; }

    #[ORM\Column(type: 'integer')]
    private int $version;

    public function getVersion(): int { return $this->version; }

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
