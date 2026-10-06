<?php

namespace App\Background;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class QueuedMailer implements MailerInterface
{
    public const SECURITY_TOKEN_HEADER = 'X-Wave-Security-Token-Hash';

    private ?array $reminder = null;

    public function withReminder(array $context, \Closure $send): void
    {
        $this->reminder = $context;
        try {
            $send();
        } finally {
            $this->reminder = null;
        }
    }

    public function __construct(
        private readonly JobDispatcher $jobs,
        #[Autowire(service: 'App\\Background\\QueuedMailer.inner')] private readonly MailerInterface $inner,
        #[Autowire('%wave.queue.enabled%')] private readonly bool $enabled,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $tokenHash = null;
        if ($message instanceof Email && $message->getHeaders()->has(self::SECURITY_TOKEN_HEADER)) {
            $message = clone $message;
            $tokenHash = $message->getHeaders()->get(self::SECURITY_TOKEN_HEADER)->getBodyAsString();
            $message->getHeaders()->remove(self::SECURITY_TOKEN_HEADER);
            if (!preg_match('/^[a-f0-9]{64}$/D', $tokenHash)) {
                throw new \InvalidArgumentException('Invalid security email token metadata.');
            }
        }
        if (!$this->enabled) {
            $this->inner->send($message, $envelope);

            return;
        }
        $envelope ??= Envelope::create($message);
        $raw = $message->toString();
        $this->jobs->enqueue('SendEmailMessage', [
            'raw' => base64_encode($raw), 'sender' => $envelope->getSender()->getAddress(),
            'recipients' => array_map(static fn ($address): string => $address->getAddress(), $envelope->getRecipients()),
            'tokenHash' => $tokenHash, 'reminder' => $this->reminder,
        ], $this->reminder === null ? null : 'reminder:' . implode(':', $this->reminder));
    }
}
