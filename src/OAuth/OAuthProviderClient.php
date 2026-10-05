<?php

namespace App\OAuth;

use JsonException;
use OpenSSLAsymmetricKey;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OAuthProviderClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $googleClientId,
        private readonly string $googleClientSecret,
        private readonly string $googleRedirectUri,
        private readonly string $appleClientId,
        private readonly string $appleTeamId,
        private readonly string $appleKeyId,
        private readonly string $applePrivateKey,
        private readonly string $appleRedirectUri,
    ) {
    }

    public function isEnabled(string $provider): bool
    {
        return match ($provider) {
            'google' => $this->googleClientId !== '' && $this->googleClientSecret !== '' && $this->googleRedirectUri !== '',
            'apple' => $this->appleClientId !== '' && $this->appleTeamId !== '' && $this->appleKeyId !== '' && $this->applePrivateKey !== '' && $this->appleRedirectUri !== '',
            default => false,
        };
    }

    public function authorizationUrl(string $provider, string $state, string $nonce): string
    {
        if (!$this->isEnabled($provider)) {
            throw new OAuthProviderException('OAuth provider is not configured.');
        }

        if ($provider === 'google') {
            return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
                'client_id' => $this->googleClientId,
                'redirect_uri' => $this->googleRedirectUri,
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'state' => $state,
                'nonce' => $nonce,
                'prompt' => 'select_account',
            ], '', '&', PHP_QUERY_RFC3986);
        }

        return 'https://appleid.apple.com/auth/authorize?'.http_build_query([
            'client_id' => $this->appleClientId,
            'redirect_uri' => $this->appleRedirectUri,
            'response_type' => 'code',
            'response_mode' => 'form_post',
            'scope' => 'name email',
            'state' => $state,
            'nonce' => $nonce,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{subject: string, email: string, givenName: ?string, familyName: ?string}
     */
    public function authenticateCode(string $provider, string $code, string $expectedNonce): array
    {
        if (!$this->isEnabled($provider)) {
            throw new OAuthProviderException('OAuth provider is not configured.');
        }

        if ($provider === 'google') {
            $tokenResponse = $this->requestJson('POST', 'https://oauth2.googleapis.com/token', [
                'body' => [
                    'client_id' => $this->googleClientId,
                    'client_secret' => $this->googleClientSecret,
                    'redirect_uri' => $this->googleRedirectUri,
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                ],
            ]);
            $audience = $this->googleClientId;
            $issuer = ['https://accounts.google.com', 'accounts.google.com'];
            $keysUrl = 'https://www.googleapis.com/oauth2/v3/certs';
        } else {
            $tokenResponse = $this->requestJson('POST', 'https://appleid.apple.com/auth/token', [
                'body' => [
                    'client_id' => $this->appleClientId,
                    'client_secret' => $this->appleClientSecret(),
                    'redirect_uri' => $this->appleRedirectUri,
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                ],
            ]);
            $audience = $this->appleClientId;
            $issuer = ['https://appleid.apple.com'];
            $keysUrl = 'https://appleid.apple.com/auth/keys';
        }

        $idToken = $tokenResponse['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') {
            throw new OAuthProviderException('OAuth provider did not return an identity token.');
        }

        $segments = explode('.', $idToken);
        if (count($segments) !== 3) {
            throw new OAuthProviderException('OAuth identity token is malformed.');
        }
        $header = $this->decodeJwtSegment($segments[0]);
        $claims = $this->decodeJwtSegment($segments[1]);
        if (($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null)) {
            throw new OAuthProviderException('OAuth identity token uses an unsupported signature.');
        }

        $keys = $this->requestJson('GET', $keysUrl);
        $key = null;
        foreach ($keys['keys'] ?? [] as $candidate) {
            if (is_array($candidate) && ($candidate['kid'] ?? null) === $header['kid']) {
                $key = $candidate;
                break;
            }
        }
        if (!is_array($key) || ($key['kty'] ?? null) !== 'RSA' || !is_string($key['n'] ?? null) || !is_string($key['e'] ?? null)) {
            throw new OAuthProviderException('OAuth identity token signing key is unknown.');
        }

        $publicKey = openssl_pkey_get_public($this->rsaPublicKeyPem($key['n'], $key['e']));
        if (!$publicKey instanceof OpenSSLAsymmetricKey
            || openssl_verify($segments[0].'.'.$segments[1], $this->base64UrlDecode($segments[2]), $publicKey, OPENSSL_ALGO_SHA256) !== 1
        ) {
            throw new OAuthProviderException('OAuth identity token signature is invalid.');
        }

        $issuedAt = $claims['iat'] ?? null;
        $expiresAt = $claims['exp'] ?? null;
        $audiences = $claims['aud'] ?? null;
        $authorizedParty = $claims['azp'] ?? null;
        $notBefore = $claims['nbf'] ?? null;
        $emailVerified = $claims['email_verified'] ?? false;
        $verified = $emailVerified === true || $emailVerified === 'true';
        $validAudience = is_string($audiences)
            ? hash_equals($audience, $audiences)
            : (is_array($audiences) && in_array($audience, $audiences, true));
        $validAuthorizedParty = ($authorizedParty === null || (is_string($authorizedParty) && hash_equals($audience, $authorizedParty)))
            && (!is_array($audiences) || count($audiences) <= 1 || $authorizedParty === $audience);
        $validIssuer = is_string($claims['iss'] ?? null) && in_array($claims['iss'], $issuer, true);
        $validTimes = is_numeric($issuedAt)
            && is_numeric($expiresAt)
            && (int) $issuedAt <= time() + 300
            && (int) $expiresAt > time()
            && ($notBefore === null || (is_numeric($notBefore) && (int) $notBefore <= time() + 300));
        $validNonce = is_string($claims['nonce'] ?? null) && hash_equals($expectedNonce, $claims['nonce']);
        $subject = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        if (!$validAudience
            || !$validAuthorizedParty
            || !$validIssuer
            || !$validTimes
            || !$validNonce
            || !$verified
            || !is_string($subject)
            || $subject === ''
            || mb_strlen($subject) > 255
            || !is_string($email)
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || mb_strlen($email) > 180
        ) {
            throw new OAuthProviderException('OAuth identity token claims are invalid.');
        }

        return [
            'subject' => $subject,
            'email' => mb_strtolower(trim($email)),
            'givenName' => is_string($claims['given_name'] ?? null) ? mb_substr(trim($claims['given_name']), 0, 60) : null,
            'familyName' => is_string($claims['family_name'] ?? null) ? mb_substr(trim($claims['family_name']), 0, 60) : null,
        ];
    }

    private function appleClientSecret(): string
    {
        $privateKey = openssl_pkey_get_private($this->applePrivateKey);
        if (!$privateKey instanceof OpenSSLAsymmetricKey) {
            throw new OAuthProviderException('Apple OAuth signing key is invalid.');
        }

        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'ES256', 'kid' => $this->appleKeyId], JSON_THROW_ON_ERROR));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $this->appleTeamId,
            'iat' => $now,
            'exp' => $now + 3600,
            'aud' => 'https://appleid.apple.com',
            'sub' => $this->appleClientId,
        ], JSON_THROW_ON_ERROR));
        $message = $header.'.'.$claims;
        $derSignature = '';
        if (!openssl_sign($message, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new OAuthProviderException('Apple OAuth client secret could not be signed.');
        }

        return $message.'.'.$this->base64UrlEncode($this->ecdsaDerToJose($derSignature));
    }

    private function requestJson(string $method, string $url, array $options = []): array
    {
        try {
            $response = $this->httpClient->request($method, $url, ['timeout' => 10, 'max_redirects' => 0] + $options);
            $statusCode = $response->getStatusCode();
            $payload = json_decode($response->getContent(false), true, 32, JSON_THROW_ON_ERROR);
        } catch (TransportExceptionInterface|JsonException $exception) {
            throw new OAuthProviderException('OAuth provider request failed.', previous: $exception);
        }

        if ($statusCode < 200 || $statusCode >= 300 || !is_array($payload)) {
            throw new OAuthProviderException('OAuth provider returned an unsuccessful response.');
        }

        return $payload;
    }

    private function decodeJwtSegment(string $segment): array
    {
        try {
            $decoded = json_decode($this->base64UrlDecode($segment), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OAuthProviderException('OAuth identity token is malformed.', previous: $exception);
        }
        if (!is_array($decoded)) {
            throw new OAuthProviderException('OAuth identity token is malformed.');
        }

        return $decoded;
    }

    private function rsaPublicKeyPem(string $modulus, string $exponent): string
    {
        $rsaKey = $this->derSequence($this->derInteger($this->base64UrlDecode($modulus)).$this->derInteger($this->base64UrlDecode($exponent)));
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $subjectPublicKey = "\x03".$this->derLength(strlen("\x00".$rsaKey))."\x00".$rsaKey;

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($this->derSequence($algorithm.$subjectPublicKey)), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    private function derInteger(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '' || (ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }

        return "\x02".$this->derLength(strlen($value)).$value;
    }

    private function derSequence(string $value): string
    {
        return "\x30".$this->derLength(strlen($value)).$value;
    }

    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $encoded = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($encoded)).$encoded;
    }

    private function ecdsaDerToJose(string $signature): string
    {
        $offset = 0;
        if (ord($signature[$offset++] ?? "\x00") !== 0x30) {
            throw new OAuthProviderException('Apple OAuth signature is malformed.');
        }
        $this->readDerLength($signature, $offset);
        $r = $this->readDerInteger($signature, $offset);
        $s = $this->readDerInteger($signature, $offset);
        if ($offset !== strlen($signature)) {
            throw new OAuthProviderException('Apple OAuth signature is malformed.');
        }

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT)
            .str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }

    private function readDerInteger(string $signature, int &$offset): string
    {
        if (ord($signature[$offset++] ?? "\x00") !== 0x02) {
            throw new OAuthProviderException('Apple OAuth signature is malformed.');
        }
        $length = $this->readDerLength($signature, $offset);
        $value = substr($signature, $offset, $length);
        $offset += $length;
        if (strlen($value) !== $length) {
            throw new OAuthProviderException('Apple OAuth signature is malformed.');
        }

        return $value;
    }

    private function readDerLength(string $value, int &$offset): int
    {
        $first = ord($value[$offset++] ?? "\x00");
        if (($first & 0x80) === 0) {
            return $first;
        }

        $byteCount = $first & 0x7f;
        if ($byteCount === 0 || $byteCount > 4 || $offset + $byteCount > strlen($value)) {
            throw new OAuthProviderException('Apple OAuth signature is malformed.');
        }
        $length = 0;
        for ($index = 0; $index < $byteCount; $index++) {
            $length = ($length << 8) | ord($value[$offset++]);
        }

        return $length;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (!is_string($decoded)) {
            throw new OAuthProviderException('OAuth identity token is malformed.');
        }

        return $decoded;
    }
}
