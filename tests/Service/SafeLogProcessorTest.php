<?php

namespace App\Tests\Service;

use App\Background\SafeLogProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class SafeLogProcessorTest extends TestCase
{
    public function testNestedPayloadsProviderErrorsAndSqlParametersAreRedactedBeforeWriting(): void
    {
        $record = new LogRecord(new \DateTimeImmutable(), 'background', Level::Error, 'Executing statement (parameters: secret-chat-text)', ['params' => ['secret-chat-text'], 'payload' => ['raw' => 'private mail'], 'exception' => new \RuntimeException('secret transcript'), 'job_id' => 'public-job-id']);
        $safe = (new SafeLogProcessor(new RequestStack()))($record);
        self::assertStringNotContainsString('secret-chat-text', $safe->message);
        self::assertSame('[redacted]', $safe->context['params']);
        self::assertSame('[redacted]', $safe->context['payload']);
        self::assertSame(['class' => \RuntimeException::class, 'code' => 0], $safe->context['exception']);
        self::assertSame('public-job-id', $safe->context['job_id']);
    }
}
