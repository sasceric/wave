<?php

namespace App\Newsletter;

use App\Account\EmailTemplateRenderer;
use App\Entity\EmailTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class NewsletterSubscriberEmailSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EmailTemplateRenderer $templates,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%env(MAIL_FROM_ADDRESS)%')] private readonly string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')] private readonly string $fromName,
    ) {
    }

    public function sendSubscribed(string $email, string $locale): void
    {
        $copy = NewsletterSubscriberEmailCopy::forLocale($locale);
        $customization = $this->customization($locale);
        $variables = $copy + ['locale' => NewsletterSubscriberEmailCopy::languageTag($locale)];

        $message = new Email()
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($email)
            ->subject($customization instanceof EmailTemplate ? $customization->getSubject() : $copy['subject'])
            ->text(implode("\n\n", [
                $copy['greeting'],
                $copy['message'],
                $copy['footer'],
            ]))
            ->html($this->templates->render(
                'subscribed',
                $variables,
                $customization instanceof EmailTemplate ? $customization->getHtmlBody() : null,
            ));

        $this->mailer->send($message);
    }

    public function defaultSubject(string $locale): string
    {
        return NewsletterSubscriberEmailCopy::forLocale($locale)['subject'];
    }

    /**
     * @return array<string, string>
     */
    public function previewVariables(string $locale): array
    {
        return NewsletterSubscriberEmailCopy::forLocale($locale)
            + ['locale' => NewsletterSubscriberEmailCopy::languageTag($locale)];
    }

    private function customization(string $locale): ?EmailTemplate
    {
        $template = $this->entityManager->getRepository(EmailTemplate::class)->findOneBy([
            'templateKey' => 'subscribed',
            'locale' => $locale,
        ]);

        return $template instanceof EmailTemplate ? $template : null;
    }
}
