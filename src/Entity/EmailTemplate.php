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

    #[ORM\Column(length: 180)]
    private string $subject;

    #[ORM\Column(name: 'html_body', type: 'text')]
    private string $htmlBody;

    public function __construct(string $templateKey, string $locale, string $subject, string $htmlBody)
    {
        $this->templateKey = $templateKey;
        $this->locale = $locale;
        $this->subject = $subject;
        $this->htmlBody = $htmlBody;
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

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): void
    {
        $this->subject = $subject;
    }

    public function getHtmlBody(): string
    {
        return $this->htmlBody;
    }

    public function setHtmlBody(string $htmlBody): void
    {
        $this->htmlBody = $htmlBody;
    }
}
