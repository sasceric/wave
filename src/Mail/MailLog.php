<?php

namespace App\Mail;

use App\Background\PayloadCipher;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/** File-only email audit. Content is encrypted separately so log pagination stays bounded. */
final class MailLog
{
    public const HEADER = 'X-Wave-Mail-Log';

    public function __construct(
        #[Autowire('%kernel.logs_dir%')] private readonly string $directory,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        private readonly PayloadCipher $cipher,
        private readonly LoggerInterface $logger,
    ) {}

    /** Capture the rendered message, not a template re-rendered later. Never interrupt delivery for audit failures. */
    public function prepare(RawMessage $message, Envelope $envelope): RawMessage
    {
        try {
            $id = bin2hex(random_bytes(16));
            $metadata = $this->metadata($message, $envelope);
            $html = $message instanceof Email ? $message->getHtmlBody() : null;
            $text = $message instanceof Email ? $message->getTextBody() : null;
            $content = $metadata + ['html' => $html, 'text' => $text, 'attachments' => $message instanceof Email ? array_map(static fn ($part): ?string => $part->getFilename(), $message->getAttachments()) : []];
            $path = $this->contentDirectory();
            if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) throw new \RuntimeException('Cannot create email audit directory.');
            $target = $path.'/'.$id.'.enc';
            $handle = @fopen($target, 'xb');
            if ($handle === false) throw new \RuntimeException('Cannot create email audit content.');
            try {
                chmod($target, 0600);
                $this->write($handle, $this->cipher->encrypt($content));
            } finally { fclose($handle); }
            if ($message instanceof Email) {
                $message = clone $message;
                $message->getHeaders()->remove(self::HEADER);
                $message->getHeaders()->addTextHeader(self::HEADER, $id);
                return $message;
            }
            return new RawMessage(self::HEADER.': '.$id."\r\n".$message->toString());
        } catch (\Throwable) {
            $this->report('mail.audit.content_write_failed');
            return $message;
        }
    }

    public function id(RawMessage $message): ?string
    {
        if ($message instanceof Email) $id = $message->getHeaders()->get(self::HEADER)?->getBodyAsString();
        else { preg_match('/^'.self::HEADER.': ([a-f0-9]{32})\r?$/mi', preg_split('/\r?\n\r?\n/', $message->toString(), 2)[0], $match); $id = $match[1] ?? null; }
        return is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id) ? $id : null;
    }

    public function strip(RawMessage $message): RawMessage
    {
        if ($message instanceof Email) {
            $message = clone $message;
            $message->getHeaders()->remove(self::HEADER);
            return $message;
        }
        // The private header is prepended before queuing, never forwarded to the provider.
        [$headers, $body] = array_pad(preg_split('/\r?\n\r?\n/', $message->toString(), 2), 2, '');
        return new RawMessage((preg_replace('/^'.self::HEADER.': [^\r\n]*\r?\n?/mi', '', $headers) ?? $headers)."\r\n\r\n".$body);
    }

    public function metadata(RawMessage $message, Envelope $envelope): array
    {
        if ($message instanceof Email) $subject = $message->getSubject() ?? '';
        else {
            preg_match('/^Subject: ([^\r\n]*(?:\r?\n[ \t][^\r\n]*)*)/mi', $message->toString(), $match);
            $subject = iconv_mime_decode($match[1] ?? '', ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: ($match[1] ?? '');
        }
        return ['sender' => $envelope->getSender()->getAddress(), 'recipients' => array_map(static fn ($address): string => $address->getAddress(), $envelope->getRecipients()), 'subject' => $subject];
    }

    public function record(RawMessage $message, Envelope $envelope, string $status, array $result = []): void
    {
        try {
            $id = $this->id($message);
            $mail = ['id' => bin2hex(random_bytes(16)), 'emailId' => $id, 'status' => $status] + $this->metadata($message, $envelope) + $result;
            $row = ['datetime' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'), 'channel' => 'mail', 'level_name' => in_array($status, ['failed', 'queue_failed'], true) ? 'ERROR' : 'INFO', 'message' => 'mail.'.$status, 'mail' => $mail];
            $path = $this->directory.'/'.$this->environment.'.mail-'.gmdate('Y-m-d').'.log';
            if (is_link($path)) throw new \RuntimeException('Unsafe email audit file.');
            $handle = @fopen($path, 'ab');
            if ($handle === false) throw new \RuntimeException('Cannot open email audit log.');
            try {
                chmod($path, 0600);
                if (!flock($handle, LOCK_EX)) throw new \RuntimeException('Cannot lock email audit log.');
                $this->write($handle, json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
                flock($handle, LOCK_UN);
            } finally { fclose($handle); }
        } catch (\Throwable) { $this->report('mail.audit.log_write_failed'); }
    }

    public function preview(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new \InvalidArgumentException('Invalid email preview.');
        $path = $this->contentDirectory().'/'.$id.'.enc';
        if (is_link($path) || !is_file($path) || !is_readable($path)) throw new \InvalidArgumentException('Email preview unavailable.');
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new \InvalidArgumentException('Email preview unavailable.');
        $data = $this->cipher->decrypt($bytes);
        foreach (['html', 'text'] as $field) {
            if (is_string($data[$field] ?? null)) {
                // Keep security links out of the admin response while retaining the captured original on disk.
                $data[$field] = preg_replace('/#[a-f0-9]{64}\b/i', '#[hidden]', $data[$field]) ?? $data[$field];
            }
        }
        if (is_string($data['html'] ?? null)) {
            // Inline the two fixed, versioned Wave assets without requesting remote tracking images.
            $data['html'] = preg_replace_callback('~(?:https?://[^/\s"\']+)?/images/email/(wave-(?:icon|logo)-v1\.png)~i', static function (array $match): string {
                $file = dirname(__DIR__, 2).'/public/images/email/'.$match[1];
                return is_file($file) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($file)) : $match[0];
            }, $data['html']) ?? $data['html'];
            $data['html'] = preg_replace('/(<(?:a|area)\b[^>]*?)\s(?:href|target)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '$1', $data['html']) ?? $data['html'];
        }
        return $data;
    }

    /** Same retention as application logs; bounded work for the existing LogCleanupTask. */
    public function cleanup(int $days): int
    {
        $directory = $this->contentDirectory();
        if (!is_dir($directory)) return 0;
        $count = 0;
        foreach (new \DirectoryIterator($directory) as $file) {
            if (!$file->isLink() && $file->isFile() && preg_match('/^[a-f0-9]{32}\.enc$/D', $file->getFilename()) && $file->getMTime() < time() - max(1, $days) * 86400) {
                if (!unlink($file->getPathname())) throw new \RuntimeException('Cannot remove expired email preview.');
                if (++$count === 100) break;
            }
        }
        return $count;
    }

    public static function redactSecrets(string $text): string
    {
        $text = preg_replace('~([a-z][a-z0-9+.-]*://[^\s/:@]+:)[^\s/@]+@~i', '$1[redacted]@', $text) ?? $text;
        $text = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/-]+=*/i', '$1 [redacted]', $text) ?? $text;
        $text = preg_replace('/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/', '[redacted token]', $text) ?? $text;
        return preg_replace('~((?:password|secret|token|authorization|cookie|private_?key|api[_-]?key)\s*["\']?\s*[:=]\s*)(?:"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|[^\s,;}]+)~i', '$1[redacted]', $text) ?? $text;
    }

    private function report(string $message): void
    {
        // A full log disk must not turn an accepted send into a retry and duplicate email.
        try { $this->logger->error($message); } catch (\Throwable) {}
    }

    private function contentDirectory(): string { return $this->directory.'/email-previews/'.$this->environment; }

    private function write(mixed $handle, string $value): void
    {
        while ($value !== '') {
            $bytes = fwrite($handle, $value);
            if ($bytes === false || $bytes === 0) throw new \RuntimeException('Cannot write email audit.');
            $value = substr($value, $bytes);
        }
    }
}
