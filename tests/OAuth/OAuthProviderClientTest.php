<?php

namespace App\Tests\OAuth;

use App\OAuth\OAuthProviderClient;
use App\OAuth\OAuthProviderException;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OAuthProviderClientTest extends TestCase
{
    public function testGoogleIdentityTokenIsVerifiedBeforeReturningIdentity(): void
    {
        [$privateKey, $jwk] = $this->rsaKeys();
        $nonce = 'oauth-nonce';
        $token = $this->idToken($privateKey, $nonce, $jwk['kid'], 'https://accounts.google.com');
        $client = new OAuthProviderClient(
            new MockHttpClient([
                new MockResponse(json_encode(['id_token' => $token], JSON_THROW_ON_ERROR)),
                new MockResponse(json_encode(['keys' => [$jwk]], JSON_THROW_ON_ERROR)),
            ]),
            'google-client',
            'google-secret',
            'https://wave.example/api/auth/oauth/google/callback',
            '',
            '',
            '',
            '',
            '',
        );

        self::assertSame([
            'subject' => 'provider-user-1',
            'email' => 'creator@example.test',
            'givenName' => 'Avery',
            'familyName' => 'Creator',
        ], $client->authenticateCode('google', 'authorization-code', $nonce));
    }

    public function testGoogleIdentityTokenWithWrongNonceIsRejected(): void
    {
        [$privateKey, $jwk] = $this->rsaKeys();
        $token = $this->idToken($privateKey, 'valid-nonce', $jwk['kid'], 'https://accounts.google.com');
        $client = new OAuthProviderClient(
            new MockHttpClient([
                new MockResponse(json_encode(['id_token' => $token], JSON_THROW_ON_ERROR)),
                new MockResponse(json_encode(['keys' => [$jwk]], JSON_THROW_ON_ERROR)),
            ]),
            'google-client',
            'google-secret',
            'https://wave.example/api/auth/oauth/google/callback',
            '',
            '',
            '',
            '',
            '',
        );

        $this->expectException(OAuthProviderException::class);
        $client->authenticateCode('google', 'authorization-code', 'attacker-nonce');
    }

    public function testAppleClientAssertionAndIdentityTokenAreVerified(): void
    {
        [$rsaPrivateKey, $jwk] = $this->rsaKeys();
        $nonce = 'apple-nonce';
        $idToken = $this->idToken($rsaPrivateKey, $nonce, $jwk['kid'], 'https://appleid.apple.com');
        $applePrivateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertNotFalse($applePrivateKey);
        self::assertTrue(openssl_pkey_export($applePrivateKey, $applePrivateKeyPem));
        $applePublicKey = openssl_pkey_get_details($applePrivateKey)['key'];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($idToken, $jwk, $applePublicKey): MockResponse {
            if ($url === 'https://appleid.apple.com/auth/token') {
                self::assertSame('POST', $method);
                parse_str(is_string($options['body'] ?? null) ? $options['body'] : '', $body);
                $clientSecret = $body['client_secret'] ?? null;
                self::assertIsString($clientSecret);
                [$header, $claims, $signature] = explode('.', $clientSecret);
                self::assertSame('ES256', json_decode($this->decode($header), true)['alg']);
                self::assertSame('WAVE-TEAM', json_decode($this->decode($claims), true)['iss']);
                self::assertSame(
                    1,
                    openssl_verify(
                        $header.'.'.$claims,
                        $this->ecdsaJoseToDer($this->decode($signature)),
                        $applePublicKey,
                        OPENSSL_ALGO_SHA256,
                    ),
                );

                return new MockResponse(json_encode(['id_token' => $idToken], JSON_THROW_ON_ERROR));
            }

            self::assertSame('https://appleid.apple.com/auth/keys', $url);

            return new MockResponse(json_encode(['keys' => [$jwk]], JSON_THROW_ON_ERROR));
        });
        $client = new OAuthProviderClient(
            $httpClient,
            '',
            '',
            '',
            'apple-services-id',
            'WAVE-TEAM',
            'APPLE-KEY',
            $applePrivateKeyPem,
            'https://wave.example/api/auth/oauth/apple/callback',
        );

        self::assertSame('creator@example.test', $client->authenticateCode('apple', 'authorization-code', $nonce)['email']);
    }

    /**
     * @return array{OpenSSLAsymmetricKey, array<string, string>}
     */
    private function rsaKeys(): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        self::assertNotFalse($privateKey);
        $details = openssl_pkey_get_details($privateKey);
        self::assertIsArray($details);

        return [$privateKey, [
            'kid' => 'provider-key',
            'kty' => 'RSA',
            'n' => $this->encode($details['rsa']['n']),
            'e' => $this->encode($details['rsa']['e']),
        ]];
    }

    private function idToken(OpenSSLAsymmetricKey $privateKey, string $nonce, string $keyId, string $issuer): string
    {
        $header = $this->encode(json_encode(['alg' => 'RS256', 'kid' => $keyId], JSON_THROW_ON_ERROR));
        $claims = $this->encode(json_encode([
            'iss' => $issuer,
            'aud' => $issuer === 'https://appleid.apple.com' ? 'apple-services-id' : 'google-client',
            'iat' => time(),
            'exp' => time() + 600,
            'nonce' => $nonce,
            'sub' => 'provider-user-1',
            'email' => 'Creator@Example.Test',
            'email_verified' => true,
            'given_name' => 'Avery',
            'family_name' => 'Creator',
        ], JSON_THROW_ON_ERROR));
        $message = $header.'.'.$claims;
        self::assertTrue(openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256));

        return $message.'.'.$this->encode($signature);
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        self::assertIsString($decoded);

        return $decoded;
    }

    private function ecdsaJoseToDer(string $signature): string
    {
        $r = $this->derInteger(substr($signature, 0, 32));
        $s = $this->derInteger(substr($signature, 32, 32));
        $sequence = $r.$s;

        return "\x30".$this->derLength(strlen($sequence)).$sequence;
    }

    private function derInteger(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }

        return "\x02".$this->derLength(strlen($value)).$value;
    }

    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
