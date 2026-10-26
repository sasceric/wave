<?php

namespace App\Newsletter;

use App\Account\EmailTemplateRenderer;
use App\Account\EmailRecipient;
use App\Account\EmailPreferences;
use App\Entity\NewsletterSubscriber;
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
        private readonly EmailPreferences $preferences,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%env(MAIL_FROM_ADDRESS)%')] private readonly string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')] private readonly string $fromName,
    ) {
    }

    public function sendSubscribed(string $email, string $locale): void
    {
        $copy = NewsletterSubscriberEmailCopy::forLocale($locale);
        $customization = $this->customization($locale);
        $copy = EmailTemplate::applyCopy($copy, $customization?->getCopy());
        $variables = $copy + ['locale' => NewsletterSubscriberEmailCopy::languageTag($locale)];
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim($email)), 'deletedAt' => null]);
        $name = EmailRecipient::name($user);
        $variables['recipientName'] = $name;
        $variables['greeting'] = EmailRecipient::greeting($copy['greeting'], $name);

        $subscriber = $this->entityManager->getRepository(NewsletterSubscriber::class)->findOneBy(['email' => mb_strtolower(trim($email))]);
        $id = $subscriber?->getId();
        if ($id === null) throw new \LogicException('Persist the newsletter subscriber before sending.');
        $unsubscribeUrl = $this->preferences->subscriberPageUrl($id, $locale);
        $label = $copy['unsubscribeLabel'];
        $html = $this->templates->render('subscribed', $variables, $customization?->getHtmlBody());
        // Keep withdrawal available even when an administrator customizes the template.
        $footer = '<p style="text-align:center;font:13px Arial,sans-serif;padding:16px;"><a href="'.htmlspecialchars($unsubscribeUrl, ENT_QUOTES).'">'.htmlspecialchars($label, ENT_QUOTES).'</a></p>';
        $confirmation = '<p style="text-align:center;font:16px Arial,sans-serif;padding:16px;"><a href="'.htmlspecialchars($this->preferences->subscriberConfirmationUrl($id, $locale), ENT_QUOTES).'">'.htmlspecialchars($copy['confirmLabel'], ENT_QUOTES).'</a></p>';
        $footer = $confirmation.$footer;
        $html = str_contains($html, '</body>') ? str_replace('</body>', $footer.'</body>', $html) : $html.$footer;

        $message = new Email()
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to(new Address($email, $name))
            ->subject($this->templates->renderSubject($customization?->getSubject() ?? $copy['subject'], $variables))
            ->text(implode("\n\n", [
                $variables['greeting'],
                $copy['message'],
                $copy['footer'],
                $copy['confirmLabel'].': '.$this->preferences->subscriberConfirmationUrl($id, $locale),
                $label.': '.$unsubscribeUrl,
            ]))
            ->html($html);
        $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$this->preferences->subscriberOneClickUrl($id).'>');
        $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

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
