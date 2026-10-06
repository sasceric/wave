<?php

namespace App\Tests\Service;

use App\Background\JobSerializer;
use App\Message\SendEmailMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class JobSerializerTest extends TestCase
{
    public function testProviderFailureTextCannotBeCopiedToQueueMetadata(): void
    {
        $inner = $this->createMock(SerializerInterface::class);
        $inner->expects(self::once())->method('encode')->with(self::callback(static fn (Envelope $envelope): bool => $envelope->last(ErrorDetailsStamp::class) === null))->willReturn(['body' => '{}', 'headers' => []]);
        $serializer = new JobSerializer($inner);
        $serializer->encode(new Envelope(new SendEmailMessage(str_repeat('a', 32)), [ErrorDetailsStamp::create(new \RuntimeException('password=private provider transcript'))]));
    }

    public function testUnknownSchemasCannotInvokeOtherSymfonyHandlers(): void
    {
        $inner = $this->createMock(SerializerInterface::class);
        $inner->expects(self::never())->method('decode');
        $this->expectException(MessageDecodingFailedException::class);
        (new JobSerializer($inner))->decode(['body' => '{}', 'headers' => ['type' => 'Symfony\Component\Console\Messenger\RunCommandMessage']]);
    }
}
