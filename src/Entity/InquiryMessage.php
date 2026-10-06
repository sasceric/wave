<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'inquiry_message')]
#[ORM\Index(name: 'idx_inquiry_message_latest', columns: ['inquiry_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_inquiry_message_unread', columns: ['inquiry_id', 'sender_id', 'id'], options: ['where' => '(read_at IS NULL)'])]
#[ORM\Index(name: 'idx_inquiry_message_history', columns: ['inquiry_id', 'id'])]
#[ORM\Index(name: 'idx_inquiry_message_inquiry_created', columns: ['inquiry_id', 'created_at'])]
#[ORM\Index(name: 'idx_inquiry_message_receipt', columns: ['inquiry_id', 'sender_id', 'id'], options: ['where' => '(read_at IS NOT NULL)'])]
class InquiryMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CreatorInquiry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CreatorInquiry $inquiry;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $sender;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $readAt = null;

    public function __construct(CreatorInquiry $inquiry, User $sender, string $body)
    {
        $this->inquiry = $inquiry;
        $this->sender = $sender;
        $this->body = $body;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInquiry(): CreatorInquiry
    {
        return $this->inquiry;
    }

    public function getSender(): User
    {
        return $this->sender;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }
}
