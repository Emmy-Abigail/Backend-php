<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Persistence;

use App\Domain\Auth\PasswordResetToken;
use DateTimeImmutable;

interface PasswordResetTokenRepository
{
    public function issue(int $userId, string $tokenHash, DateTimeImmutable $expiresAt, DateTimeImmutable $issuedAt): PasswordResetToken;

    public function findUsableByHash(string $tokenHash, DateTimeImmutable $now): ?PasswordResetToken;

    public function markAsUsed(int $tokenId, DateTimeImmutable $usedAt): void;
}
