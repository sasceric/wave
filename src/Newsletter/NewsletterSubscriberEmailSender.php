<?php

namespace App\Newsletter;

use App\Account\EmailTemplateRenderer;
use App\Account\EmailRecipient;
use App\Entity\EmailTemplate;
use App\Entity\User;
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
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim($email)), 'deletedAt' => null]);
        $name = EmailRecipient::name($user);
        $variables['recipientName'] = $name;
        $variables['greeting'] = EmailRecipient::greeting($copy['greeting'], $name);

        $message = new Email()
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to(new Address($email, $name))
            ->subject($customization instanceof EmailTemplate ? $customization->getSubject() : $copy['subject'])
            ->text(implode("\n\n", [
                $variables['greeting'],
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
        $variables = NewsletterSubscriberEmailCopy::forLocale($locale)
            + ['locale' => NewsletterSubscriberEmailCopy::languageTag($locale)];
        $variables['recipientName'] = 'Amina Kovač';
        $variables['greeting'] = EmailRecipient::greeting($variables['greeting'], $variables['recipientName']);

        return $variables;
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
