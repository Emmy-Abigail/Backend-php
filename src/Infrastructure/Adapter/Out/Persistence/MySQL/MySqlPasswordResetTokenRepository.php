<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Port\Out\Persistence\PasswordResetTokenRepository;
use App\Domain\Auth\PasswordResetToken;
use DateTimeImmutable;

final class MySqlPasswordResetTokenRepository implements PasswordResetTokenRepository
{
    public function issue(
        int $userId,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $issuedAt,
    ): PasswordResetToken {
        // Solo el último enlace de recuperación debe poder usarse.
        PasswordResetTokenRecord::query()
            ->where('id_usuario', $userId)
            ->whereNull('usado_en')
            ->update(['usado_en' => $issuedAt->format('Y-m-d H:i:s')]);

        $record = PasswordResetTokenRecord::query()->create([
            'id_usuario' => $userId,
            'token_hash' => $tokenHash,
            'expira_en' => $expiresAt->format('Y-m-d H:i:s'),
            'created_at' => $issuedAt->format('Y-m-d H:i:s'),
        ]);

        return $this->toDomain($record);
    }

    public function findUsableByHash(string $hash, DateTimeImmutable $now): ?PasswordResetToken
    {
        $record = PasswordResetTokenRecord::query()
            ->where('token_hash', $hash)
            ->whereNull('usado_en')
            ->where('expira_en', '>', $now->format('Y-m-d H:i:s'))
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function markAsUsed(int $id, DateTimeImmutable $usedAt): void
    {
        PasswordResetTokenRecord::query()
            ->where('id', $id)
            ->whereNull('usado_en')
            ->update(['usado_en' => $usedAt->format('Y-m-d H:i:s')]);
    }

    private function toDomain(PasswordResetTokenRecord $record): PasswordResetToken
    {
        $usedAt = $record->getAttribute('usado_en');

        return new PasswordResetToken(
            (int) $record->getAttribute('id'),
            (int) $record->getAttribute('id_usuario'),
            new DateTimeImmutable((string) $record->getAttribute('expira_en')),
            $usedAt === null ? null : new DateTimeImmutable((string) $usedAt),
        );
    }
}
