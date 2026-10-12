<?php

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'campaign_review')]
#[ORM\UniqueConstraint(name: 'uniq_review_application_author', columns: ['application_id', 'author_id'])]
#[ORM\Index(name: 'idx_review_recipient_id', columns: ['recipient_id', 'id'])]
#[ORM\Index(name: 'idx_review_application', columns: ['application_id'])]
#[ORM\Index(name: 'idx_review_author', columns: ['author_id'])]
class CampaignReview
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Application::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Application $application;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\Column(type: 'smallint')]
    private int $rating;

    #[ORM\Column(type: 'text')]
    private string $comment;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(Application $application, User $author, User $recipient, int $rating, string $comment)
    {
        $this->application = $application;
        $this->author = $author;
        $this->recipient = $recipient;
        $this->rating = $rating;
        $this->comment = $comment;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApplication(): Application
    {
        return $this->application;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function getComment(): string
    {
        return $this->comment;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

}
