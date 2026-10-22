<?php

namespace App\Mail;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\{HttpTransportException, TransportExceptionInterface};
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/** Captures actual transport attempts in both synchronous and queued mail delivery. */
final class LoggedMailTransport implements TransportInterface
{
    public function __construct(
        #[Autowire(service: 'App\\Mail\\LoggedMailTransport.inner')] private readonly TransportInterface $inner,
        private readonly MailLog $logs,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $dsn,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $envelope ??= Envelope::create($message);
        if ($this->logs->id($message) === null) $message = $this->logs->prepare($message, $envelope);
        $started = microtime(true);
        try {
            $sent = $this->inner->send($this->logs->strip($message), $envelope);
        } catch (\Throwable $error) {
            $this->logs->record($message, $envelope, 'failed', $this->result($error instanceof TransportExceptionInterface ? $error->getDebug() : '', $error) + ['durationMs' => (int) ((microtime(true) - $started) * 1000)]);
            throw $error;
        }
        $this->logs->record($message, $envelope, str_starts_with($this->dsn, 'null://') || $sent === null ? 'not_sent' : 'accepted', $this->result($sent?->getDebug() ?? '') + ['durationMs' => (int) ((microtime(true) - $started) * 1000)]);
        return $sent;
    }

    public function __toString(): string { return (string) $this->inner; }

    private function result(string $debug, ?\Throwable $error = null): array
    {
        $protocol = null; $code = null; $response = null;
        if ($error instanceof HttpTransportException) {
            $protocol = 'HTTP';
            try {
                $code = $error->getResponse()->getStatusCode();
                $response = $error->getResponse()->getContent(false);
            } catch (\Throwable) {}
        }
        if (preg_match_all('/(?:^|\n)[^\r\n]*<\s*HTTP\/[\d.]+\s+(\d{3})[^\r\n]*/', $debug, $matches)) {
            $protocol = 'HTTP'; $code ??= (int) end($matches[1]); $response = trim((string) end($matches[0]));
        } elseif (preg_match_all('/(?:^|\n)(?:\[[^\]\r\n]+\]\s*)?<\s*(\d{3})(?:[- ][^\r\n]*)?/', $debug, $matches)) {
            $protocol = 'SMTP'; $code = (int) end($matches[1]); $response = trim((string) end($matches[0]));
        }
        $errors = [];
        for ($cause = $error; $cause !== null && count($errors) < 8; $cause = $cause->getPrevious()) {
            $errors[] = $cause::class.' ('.$cause->getCode().'): '.MailLog::redactSecrets($cause->getMessage());
        }
        $errorText = implode("\nCaused by: ", $errors);
        return ['protocol' => $protocol, 'responseCode' => $code, 'response' => $response === null ? null : mb_strcut(MailLog::redactSecrets($response), 0, 12000), 'responseTruncated' => $response !== null && strlen(MailLog::redactSecrets($response)) > 12000, 'error' => $error === null ? null : mb_strcut($errorText, 0, 16000), 'errorTruncated' => strlen($errorText) > 16000];
    }
}
