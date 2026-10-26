<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'email_template')]
#[ORM\UniqueConstraint(name: 'uniq_email_template_key_locale', columns: ['template_key', 'locale'])]
class EmailTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'template_key', length: 40)]
    private string $templateKey;

    #[ORM\Column(length: 5)]
    private string $locale;

    /** Null: the built-in subject for this language. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $subject;

    /** Null: the built-in layout, so later layout improvements still reach this language. */
    #[ORM\Column(name: 'html_body', type: 'text', nullable: true)]
    private ?string $htmlBody;

    /** Admin wording for text variables (heading, message, button…), applied over the built-in copy. @var array<string, string>|null */
    #[ORM\Column(name: 'copy_overrides', type: 'json', nullable: true)]
    private ?array $copyOverrides = null;

    /** @param array<string, string> $copy */
    public function __construct(string $templateKey, string $locale, ?string $subject, ?string $htmlBody, array $copy = [])
    {
        $this->templateKey = $templateKey;
        $this->locale = $locale;
        $this->subject = $subject;
        $this->htmlBody = $htmlBody;
        $this->setCopy($copy);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplateKey(): string
    {
        return $this->templateKey;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): void
    {
        $this->subject = $subject;
    }

    public function getHtmlBody(): ?string
    {
        return $this->htmlBody;
    }

    public function setHtmlBody(?string $htmlBody): void
    {
        $this->htmlBody = $htmlBody;
    }

    /** @return array<string, string> */
    public function getCopy(): array
    {
        return $this->copyOverrides ?? [];
    }

    /** @param array<string, string> $copy */
    public function setCopy(array $copy): void
    {
        $this->copyOverrides = $copy === [] ? null : $copy;
    }

    public function isEmpty(): bool
    {
        return $this->subject === null && $this->htmlBody === null && $this->getCopy() === [];
    }

    /**
     * Admin wording replaces only text the email already has; data such as names or links is never overridden.
     *
     * @param array<string, string> $variables
     * @param array<string, string>|null $copy
     * @return array<string, string>
     */
    public static function applyCopy(array $variables, ?array $copy): array
    {
        foreach ($copy ?? [] as $name => $value) {
            if (is_string($value) && array_key_exists($name, $variables)) {
                $variables[$name] = $value;
            }
        }

        return $variables;
    }
}
