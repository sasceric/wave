<?php

namespace App\Background;

use App\Message\JobMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/** Failure metadata must not copy provider transcripts into unprotected queue rows. */
final class JobSerializer implements SerializerInterface
{
    public function __construct(#[Autowire(service: 'messenger.transport.symfony_serializer')] private readonly SerializerInterface $inner)
    {
    }

    public function encode(Envelope $envelope): array
    {
        if (!$envelope->getMessage() instanceof JobMessage) {
            throw new \InvalidArgumentException('Only Wave background jobs use these transports.');
        }

        return $this->inner->encode($envelope->withoutAll(ErrorDetailsStamp::class));
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        $type = $encodedEnvelope['headers']['type'] ?? '';
        $allowed = array_map(static fn (string $name): string => 'App\\Message\\' . $name, array_keys(JobCatalog::TYPES));
        if (!in_array($type, $allowed, true)) {
            throw new MessageDecodingFailedException('Unknown Wave job schema.');
        }

        return $this->inner->decode($encodedEnvelope);
    }
}
