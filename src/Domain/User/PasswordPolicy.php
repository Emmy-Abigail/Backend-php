<?php

declare(strict_types=1);

namespace App\Domain\User;

final class PasswordPolicy
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 72;

    public static function isValid(string $password): bool
    {
        return strlen($password) >= self::MIN_LENGTH
            && strlen($password) <= self::MAX_LENGTH
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/\d/', $password) === 1
            && preg_match('/[^A-Za-z0-9]/', $password) === 1;
    }
}
