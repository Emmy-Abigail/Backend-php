<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Security;

use App\Application\Exception\InvalidToken;
use App\Application\Exception\TokenExpired;
use App\Application\Port\Out\Security\IssuedToken;
use App\Application\Port\Out\Security\TokenClaims;
use App\Application\Port\Out\Security\TokenService;
use App\Domain\User\User;
use DateTimeImmutable;
use DomainException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use InvalidArgumentException;
use UnexpectedValueException;

final readonly class JwtTokenService implements TokenService
{
    public function __construct(
        private string $secret,
        private string $issuer,
        private int $ttlSeconds,
    ) {
        if (strlen($this->secret) < 32) {
            throw new InvalidArgumentException('El secreto JWT debe tener al menos 32 caracteres');
        }
    }

    public function issue(User $user): IssuedToken
    {
        $now = time();
        $expiresTimestamp = $now + $this->ttlSeconds;

        $payload = [
            'iss' => $this->issuer,
            'sub' => (string) $user->id,
            'correo' => $user->email,
            'nombre' => $user->names,
            'rol' => $user->role,
            'iat' => $now,
            'exp' => $expiresTimestamp,
            'jti' => bin2hex(random_bytes(8)),
        ];

        $token = JWT::encode($payload, $this->secret, 'HS256');
        $expiresAt = (new DateTimeImmutable())->setTimestamp($expiresTimestamp);

        return new IssuedToken($token, $expiresAt);
    }

    public function verify(string $token): TokenClaims
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (ExpiredException) {
            throw new TokenExpired();
        } catch (SignatureInvalidException | BeforeValidException | UnexpectedValueException | DomainException | InvalidArgumentException) {
            throw new InvalidToken();
        }

        $decodedArray = (array) $decoded;

        if (!isset($decodedArray['iss']) || $decodedArray['iss'] !== $this->issuer) {
            throw new InvalidToken();
        }

        if (!isset($decodedArray['sub']) || !is_numeric($decodedArray['sub'])) {
            throw new InvalidToken();
        }

        if (!isset($decodedArray['rol']) || !is_string($decodedArray['rol']) || $decodedArray['rol'] === '') {
            throw new InvalidToken();
        }

        return new TokenClaims(
            (int) $decodedArray['sub'],
            (string) $decodedArray['rol'],
        );
    }
}
