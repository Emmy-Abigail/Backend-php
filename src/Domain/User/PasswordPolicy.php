<?php

declare(strict_types=1);

namespace App\Domain\User;

use InvalidArgumentException;

final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 72;

    private const LOWERCASE = 'abcdefghjkmnpqrstuvwxyz';
    private const UPPERCASE = 'ABCDEFGHJKMNPQRSTUVWXYZ';
    private const NUMBERS = '23456789';
    private const SYMBOLS = '!@#$%^&*_-+=';

    public static function isValid(string $password): bool
    {
        return strlen($password) >= self::MIN_LENGTH
            && strlen($password) <= self::MAX_LENGTH
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/\d/', $password) === 1
            && preg_match('/[^A-Za-z0-9]/', $password) === 1;
    }

    public static function generateTemporaryPassword(int $length = self::MIN_LENGTH): string
    {
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidArgumentException('La longitud de la contraseña temporal no es válida');
        }

        $characters = [
            self::randomCharacter(self::LOWERCASE),
            self::randomCharacter(self::UPPERCASE),
            self::randomCharacter(self::NUMBERS),
            self::randomCharacter(self::SYMBOLS),
        ];
        $allCharacters = self::LOWERCASE . self::UPPERCASE . self::NUMBERS . self::SYMBOLS;

        while (count($characters) < $length) {
            $characters[] = self::randomCharacter($allCharacters);
        }

        for ($position = count($characters) - 1; $position > 0; $position--) {
            $swapPosition = random_int(0, $position);
            [$characters[$position], $characters[$swapPosition]] = [$characters[$swapPosition], $characters[$position]];
        }

        return implode('', $characters);
    }

    private static function randomCharacter(string $characters): string
    {
        return $characters[random_int(0, strlen($characters) - 1)];
    }
}
