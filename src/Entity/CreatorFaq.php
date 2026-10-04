<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'creator_faq')]
class CreatorFaq
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'json')]
    private array $questions;

    #[ORM\Column(type: 'json')]
    private array $answers;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct(array $questions, array $answers, int $position)
    {
        $this->questions = $questions;
        $this->answers = $answers;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestions(): array
    {
        return $this->questions;
    }

    public function getAnswers(): array
    {
        return $this->answers;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function update(array $questions, array $answers, int $position, bool $active): void
    {
        $this->questions = $questions;
        $this->answers = $answers;
        $this->position = $position;
        $this->active = $active;
    }

    public function localized(string $locale): array
    {
        return [
            'question' => $this->questions[$locale] ?? $this->questions['bs'] ?? '',
            'answer' => $this->answers[$locale] ?? $this->answers['bs'] ?? '',
        ];
    }
}
