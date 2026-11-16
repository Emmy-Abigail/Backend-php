<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Security;

use App\Application\Exception\GoogleIdentityUnavailable;
use App\Application\Exception\InvalidGoogleToken;
use App\Application\Port\Out\Security\GoogleIdentityVerifier;
use App\Infrastructure\Adapter\Out\Http\HttpTransport;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use JsonException;
use Throwable;

/**
 * Valida el ID token que Google entrega al front ("Continuar con Google"):
 * firma RS256 con las claves publicas de Google, emisor, audiencia (nuestro client ID),
 * vigencia y correo verificado. Las claves se guardan en cache una hora.
 */
final readonly class GoogleIdTokenVerifier implements GoogleIdentityVerifier
{
    public const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];
    private const CACHE_TTL_SECONDS = 3600;
    private const MAX_TOKEN_LENGTH = 8192;

    public function __construct(
        private HttpTransport $transport,
        private string $clientId,
        private ?string $cacheFile = null,
        private float $timeout = 5.0,
        private string $certsUrl = self::CERTS_URL,
    ) {
    }

    public function verify(string $idToken): string
    {
        $kid = $this->keyId($idToken);

        $jwks = $this->keys(false);
        if (!$this->hasKey($jwks, $kid)) {
            // Google rota sus claves: si el kid no esta en cache se piden de nuevo una sola vez.
            $jwks = $this->keys(true);
            if (!$this->hasKey($jwks, $kid)) {
                throw new InvalidGoogleToken('Token de Google invalido');
            }
        }

        try {
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($jwks, 'RS256'));
        } catch (Throwable) {
            throw new InvalidGoogleToken('Token de Google invalido');
        }

        $audience = $claims['aud'] ?? null;
        $audienceMatches = is_array($audience)
            ? in_array($this->clientId, $audience, true)
            : $audience === $this->clientId;
        $email = $claims['email'] ?? null;
        $emailVerified = $claims['email_verified'] ?? false;

        if (!in_array($claims['iss'] ?? null, self::ISSUERS, true)
            || !$audienceMatches
            || !is_string($email)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ($emailVerified !== true && $emailVerified !== 'true')) {
            throw new InvalidGoogleToken('Token de Google invalido');
        }

        return strtolower($email);
    }

    private function keyId(string $idToken): string
    {
        $parts = explode('.', $idToken);
        if (strlen($idToken) > self::MAX_TOKEN_LENGTH || count($parts) !== 3) {
            throw new InvalidGoogleToken('Token de Google invalido');
        }

        try {
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidGoogleToken('Token de Google invalido');
        }

        if (!is_array($header) || ($header['alg'] ?? null) !== 'RS256'
            || !is_string($header['kid'] ?? null) || $header['kid'] === '') {
            throw new InvalidGoogleToken('Token de Google invalido');
        }

        return $header['kid'];
    }

    /** @param array{keys: list<array<string, mixed>>} $jwks */
    private function hasKey(array $jwks, string $kid): bool
    {
        foreach ($jwks['keys'] as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid) {
                return true;
            }
        }

        return false;
    }

    /** @return array{keys: list<array<string, mixed>>} */
    private function keys(bool $refresh): array
    {
        if (!$refresh && ($cached = $this->cachedKeys()) !== null) {
            return $cached;
        }

        try {
            $response = $this->transport->get($this->certsUrl, $this->timeout);
        } catch (Throwable) {
            throw new GoogleIdentityUnavailable('No se pudieron obtener las claves de Google');
        }

        $jwks = $response->status === 200 ? $this->parseJwks($response->body) : null;
        if ($jwks === null) {
            throw new GoogleIdentityUnavailable('No se pudieron obtener las claves de Google');
        }

        if ($this->cacheFile !== null) {
            // Si no se puede escribir la cache, solo se pierde la optimizacion.
            @file_put_contents(
                $this->cacheFile,
                json_encode(['fetched_at' => time(), 'jwks' => $jwks]),
                LOCK_EX,
            );
        }

        return $jwks;
    }

    /** @return array{keys: list<array<string, mixed>>}|null */
    private function cachedKeys(): ?array
    {
        if ($this->cacheFile === null || !is_file($this->cacheFile)) {
            return null;
        }

        $content = @file_get_contents($this->cacheFile);
        try {
            $cache = is_string($content) ? json_decode($content, true, 16, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            return null;
        }

        if (!is_array($cache) || !is_int($cache['fetched_at'] ?? null)
            || time() - $cache['fetched_at'] >= self::CACHE_TTL_SECONDS) {
            return null;
        }

        return $this->validJwks($cache['jwks'] ?? null);
    }

    /** @return array{keys: list<array<string, mixed>>}|null */
    private function parseJwks(string $body): ?array
    {
        try {
            return $this->validJwks(json_decode($body, true, 16, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return null;
        }
    }

    /** @return array{keys: list<array<string, mixed>>}|null */
    private function validJwks(mixed $jwks): ?array
    {
        if (!is_array($jwks) || !is_array($jwks['keys'] ?? null) || $jwks['keys'] === []
            || !array_is_list($jwks['keys'])) {
            return null;
        }

        return ['keys' => $jwks['keys']];
    }
}
