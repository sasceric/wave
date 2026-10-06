<?php

namespace App\Background;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PayloadCipher
{
    public function __construct(#[Autowire('%kernel.secret%')] private readonly string $secret)
    {
        if ($secret === '') {
            throw new \LogicException('APP_SECRET is required for protected background jobs.');
        }
    }

    public function encrypt(array $payload): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(json_encode($payload, JSON_THROW_ON_ERROR), 'aes-256-gcm', hash('sha256', $this->secret, true), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Cannot protect job payload.');
        }

        return base64_encode($nonce . $tag . $cipher);
    }

    public function decrypt(string $encoded): array
    {
        $bytes = base64_decode($encoded, true);
        $json = $bytes === false || strlen($bytes) < 28 ? false : openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', hash('sha256', $this->secret, true), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16));
        if ($json === false) {
            throw new \Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException('Job payload could not be authenticated. Preserve APP_SECRET across deployments.');
        }

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
