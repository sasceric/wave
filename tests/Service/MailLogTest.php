<?php

namespace App\Tests\Service;

use App\Background\PayloadCipher;
use App\Mail\{LoggedMailTransport, MailLog};
use App\Service\AdminLogReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\{Envelope, SentMessage};
use Symfony\Component\Mailer\Exception\{HttpTransportException, TransportException};
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\{Email, RawMessage};

final class MailLogTest extends TestCase
{
    private string $directory;
    private MailLog $logs;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/wave-mail-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->logs = new MailLog($this->directory, 'test', new PayloadCipher('test-only-key'), new NullLogger());
    }
    protected function tearDown(): void { (new Filesystem())->remove($this->directory); }

    public function testCapturedHtmlIsEncryptedAndLogSelectorExposesActualSmtpAcceptance(): void
    {
        $email = $this->email(); $envelope = Envelope::create($email);
        $inner = $this->createMock(TransportInterface::class);
        $inner->expects(self::once())->method('send')->willReturnCallback(function ($message, $envelope) {
            self::assertStringNotContainsString(MailLog::HEADER, $message->toString());
            self::assertStringContainsString('Exact original text', $message->toString());
            $sent = new SentMessage($message, $envelope);
            $sent->appendDebug("[2026-10-22T10:00:00Z] > AUTH PLAIN secret-base64\n[2026-10-22T10:00:00Z] < 250 2.0.0 queued as 123\n");
            return $sent;
        });
        (new LoggedMailTransport($inner, $this->logs, 'smtp://user:secret@example.test'))->send($email, $envelope);
        $reader = new AdminLogReader($this->directory, new ArrayAdapter());
        $files = $reader->files()['files']; self::assertCount(1, $files);
        $row = $reader->numberedPage($files[0]['name'], '', 25, 1)['data'][0]['mail'];
        self::assertSame('accepted', $row['status']); self::assertSame('SMTP', $row['protocol']); self::assertSame(250, $row['responseCode']);
        self::assertSame(['recipient@example.test', 'bcc@example.test'], $row['recipients']);
        self::assertSame('Test subject', $row['subject']);
        $onDisk = file_get_contents($this->directory.'/'.$files[0]['name']);
        self::assertStringNotContainsString('secret-base64', $onDisk);
        self::assertStringNotContainsString('Original content', $onDisk);
        $contentPath = $this->directory.'/email-previews/test/'.$row['emailId'].'.enc';
        self::assertStringNotContainsString('Original content', file_get_contents($contentPath));
        self::assertSame(0600, fileperms($contentPath) & 0777);
        $preview = $this->logs->preview($row['emailId']);
        self::assertStringContainsString('Original content', $preview['html']);
        self::assertStringNotContainsString(str_repeat('a', 64), $preview['html']);
        self::assertStringNotContainsString('href=', $preview['html']);
        self::assertSame('Exact original text', $preview['text']);
    }

    public function testQueuedRawMimeRetriesKeepTheOriginalPreviewAndRemoveOnlyThePrivateHeader(): void
    {
        $email = $this->email();
        $email->text("First line\r\n".MailLog::HEADER.": customer body line\r\nLast line");
        $envelope = Envelope::create($email);
        $captured = $this->logs->prepare($email, $envelope);
        $id = $this->logs->id($captured);
        $raw = new RawMessage($captured->toString());
        $inner = $this->createMock(TransportInterface::class);
        $attempts = 0;
        $inner->expects(self::exactly(2))->method('send')->willReturnCallback(function ($message, $envelope) use (&$attempts) {
            self::assertStringContainsString(MailLog::HEADER.': customer body line', $message->toString());
            self::assertSame(1, substr_count($message->toString(), MailLog::HEADER.':'));
            if (++$attempts === 1) {
                $error = new TransportException('Expected response code "250" but got code "550", with message "550 Mailbox unavailable". smtp://user:password@example.test token=do-not-log');
                $error->appendDebug("[now] > AUTH secret-base64\n[now] < 550 5.1.1 Mailbox unavailable\n");
                throw $error;
            }
            return new SentMessage($message, $envelope);
        });
        $transport = new LoggedMailTransport($inner, $this->logs, 'smtp://example.test');
        try { $transport->send($raw, $envelope); self::fail(); } catch (TransportException) {}
        $transport->send($raw, $envelope);
        $reader = new AdminLogReader($this->directory, new ArrayAdapter());
        $rows = $reader->numberedPage('', '', 25, 1)['data'];
        self::assertSame(['accepted', 'failed'], array_column(array_column($rows, 'mail'), 'status'));
        self::assertSame([$id, $id], array_column(array_column($rows, 'mail'), 'emailId'));
        self::assertSame(550, $rows[1]['mail']['responseCode']);
        self::assertStringContainsString('Mailbox unavailable', $rows[1]['mail']['error']);
        self::assertStringContainsString(TransportException::class, $rows[1]['mail']['error']);
        self::assertStringNotContainsString('do-not-log', $rows[1]['mail']['error']);
        self::assertStringNotContainsString(':password@', $rows[1]['mail']['error']);
        self::assertStringContainsString('Original content', $this->logs->preview($id)['html']);
    }

    public function testHttpFailureUsesProviderStatusAndNullTransportNeverClaimsDelivery(): void
    {
        $inner = $this->createMock(TransportInterface::class);
        $inner->expects(self::once())->method('send')->willThrowException(new HttpTransportException('Provider rejected the request.', MockResponse::fromRequest('POST', 'https://provider.example.test', [], new MockResponse('{"message":"IP not allowed"}', ['http_code' => 403]))));
        try { (new LoggedMailTransport($inner, $this->logs, 'provider+api://secret@default'))->send($this->email()); self::fail(); } catch (HttpTransportException) {}
        $row = (new AdminLogReader($this->directory, new ArrayAdapter()))->numberedPage('', '', 25, 1)['data'][0]['mail'];
        self::assertSame(403, $row['responseCode']); self::assertSame('HTTP', $row['protocol']); self::assertSame('{"message":"IP not allowed"}', $row['response']);
        $null = $this->createMock(TransportInterface::class); $null->expects(self::once())->method('send')->willReturnCallback(fn ($email, $envelope) => new SentMessage($email, $envelope));
        (new LoggedMailTransport($null, $this->logs, 'null://null'))->send($this->email());
        $row = (new AdminLogReader($this->directory, new ArrayAdapter()))->numberedPage('', '', 25, 1)['data'][0]['mail'];
        self::assertSame('not_sent', $row['status']); self::assertNull($row['responseCode']);
    }

    public function testRetentionAndTraversalAndSymlinksAndMissingPreviewAreHandled(): void
    {
        $email = $this->logs->prepare($this->email(), Envelope::create($this->email())); $id = $this->logs->id($email);
        $path = $this->directory.'/email-previews/test/'.$id.'.enc'; touch($path, time()-31*86400);
        self::assertSame(1, $this->logs->cleanup(30)); self::assertFileDoesNotExist($path);
        foreach (['../.env', str_repeat('b',32)] as $invalid) {
            try { $this->logs->preview($invalid); self::fail(); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        symlink(__FILE__, $path);
        try { $this->logs->preview($id); self::fail(); } catch (\InvalidArgumentException) { self::assertTrue(true); }
    }

    public function testAnAuditFailureDoesNotFailAnAlreadyAcceptedMessage(): void
    {
        $bad = new MailLog($this->directory.'/missing/child', 'test', new PayloadCipher('test-key'), new NullLogger());
        $email = $bad->prepare($this->email(), Envelope::create($this->email()));
        (new Filesystem())->remove($this->directory.'/missing');
        file_put_contents($this->directory.'/missing', 'not a directory');
        $inner = $this->createMock(TransportInterface::class); $inner->expects(self::once())->method('send')->willReturnCallback(fn ($message, $envelope) => new SentMessage($message, $envelope));
        self::assertNotNull((new LoggedMailTransport($inner, $bad, 'smtp://example.test'))->send($email));
    }

    public function testAuditDiagnosticFailureCannotCauseDuplicateSending(): void
    {
        file_put_contents($this->directory.'/blocked', 'not a directory');
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('error')->willThrowException(new \RuntimeException('Log disk full'));
        $logs = new MailLog($this->directory.'/blocked/child', 'test', new PayloadCipher('test-key'), $logger);
        $inner = $this->createMock(TransportInterface::class);
        $inner->expects(self::once())->method('send')->willReturnCallback(fn ($message, $envelope) => new SentMessage($message, $envelope));
        self::assertNotNull((new LoggedMailTransport($inner, $logs, 'smtp://example.test'))->send($this->email()));
    }

    private function email(): Email
    {
        return (new Email())->from('sender@example.test')->to('recipient@example.test')->bcc('bcc@example.test')->subject('Test subject')->text('Exact original text')->html('<p>Original content</p><a href="https://wave.ba/verify-email#'.str_repeat('a',64).'">Verify</a>');
    }
}
