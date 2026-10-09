<?php

namespace App\Support;

use App\Account\EmailTemplateRenderer;
use App\Entity\Notification;
use App\Entity\SupportTicket;
use App\Entity\User;
use App\Localization\LocalizedRouteMap;
use App\Service\NotificationDelivery;
use App\Service\SiteOrigin;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class TicketAlerts
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationDelivery $delivery,
        private readonly MailerInterface $mailer,
        private readonly EmailTemplateRenderer $templates,
        private readonly SupportCopy $copy,
        private readonly SiteOrigin $origin,
        private readonly LocalizedRouteMap $routes,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAIL_FROM_ADDRESS)%')] private readonly string $fromAddress,
        #[Autowire('%env(MAIL_FROM_NAME)%')] private readonly string $fromName,
    ) {
    }

    public function send(SupportTicket $ticket, ?User $actor, string $type, bool $toStaff): void
    {
        if ($toStaff) {
            $assigned = $ticket->getAssignedTo();
            $recipients = $assigned?->hasRole('ROLE_ADMIN') ? [$assigned] : $this->em->getRepository(User::class)->findBy(['admin' => true]);
        } else {
            $recipients = $ticket->getOwner() ? [$ticket->getOwner()] : [];
        }
        foreach ($recipients as $recipient) {
            if ($actor?->getId() === $recipient->getId()) continue;
            try {
                $notification = new Notification($recipient, $type, $actor, supportTicket: $ticket);
                $this->em->persist($notification);
                $this->em->flush();
                try {
                    $this->delivery->deliver($notification);
                } catch (\Throwable $error) {
                    $this->logger->error('support.ticket.notification_failed', ['ticket_id' => $ticket->getId(), 'exception' => $error]);
                }
                $this->email($ticket, $recipient->getEmail(), $recipient->getPreferredLocale(), $toStaff);
            } catch (\Throwable $error) {
                // A saved reply remains successful even if an alert provider fails.
                $this->logger->error('support.ticket.alert_failed', ['ticket_id' => $ticket->getId(), 'exception' => $error]);
            }
        }
        if (!$toStaff && !$ticket->getOwner()) {
            try {
                $this->email($ticket, $ticket->getEmail(), $ticket->getLocale(), false);
            } catch (\Throwable $error) {
                $this->logger->error('support.ticket.alert_failed', ['ticket_id' => $ticket->getId(), 'exception' => $error]);
            }
        }
    }

    private function email(SupportTicket $ticket, string $email, string $locale, bool $staff): void
    {
        $url = $ticket->getOwner() || $staff
            ? $this->origin->url($this->routes->localizedPath($staff ? 'admin-support' : 'account-support', $locale)).'?ticket='.$ticket->getId()
            : $this->origin->url($this->routes->localizedPath('support-track', $locale)).'#token='.$ticket->getTrackingToken();
        $heading = $this->copy->get($locale, 'ticketUpdated').' #'.$ticket->getNumber();
        $message = $this->copy->get($locale, 'replyEmail');
        $this->mailer->send((new Email())->from(new Address($this->fromAddress, $this->fromName))->to($email)
            ->subject($heading)->text($heading."\n\n".$message."\n\n".$url)
            ->html($this->templates->render('account_action', [
                'locale' => $locale, 'preheader' => $message, 'eyebrow' => 'WAVE', 'heading' => $heading,
                'greeting' => '', 'message' => $message, 'buttonLabel' => $this->copy->get($locale, 'view'),
                'actionUrl' => $url, 'expiration' => '', 'security' => $this->copy->get($locale, 'keepLink'),
                'fallback' => $this->copy->get($locale, 'emailFallback'), 'footer' => $this->copy->get($locale, 'emailFooter'),
            ])));
    }
}
