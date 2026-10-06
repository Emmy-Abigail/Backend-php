<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\User;

use App\Domain\User\PasswordPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PasswordPolicyTest extends TestCase
{
    #[Test]
    public function validPasswordPasses(): void
    {
        $this->assertTrue(PasswordPolicy::isValid('Admin2026#Seguro'));
        $this->assertTrue(PasswordPolicy::isValid('Riva2026@'));
        $this->assertTrue(PasswordPolicy::isValid('PASSWORD123$'));
        $this->assertTrue(PasswordPolicy::isValid('CLAVE123%'));
        $this->assertTrue(PasswordPolicy::isValid('PRUEBA2026&'));
    }

    #[Test]
    public function passwordWithoutUppercaseFails(): void
    {
        $this->assertFalse(PasswordPolicy::isValid('admin2026#seguro'));
    }

    #[Test]
    public function passwordWithoutNumberFails(): void
    {
        $this->assertFalse(PasswordPolicy::isValid('Admin####Seguro'));
    }

    #[Test]
    public function passwordWithExclamationAsOnlySymbolFails(): void
    {
        $this->assertFalse(PasswordPolicy::isValid('Admin2026!Seguro'));
    }

    #[Test]
    public function passwordWithSevenCharactersFails(): void
    {
        // 7 caracteres
        $this->assertFalse(PasswordPolicy::isValid('Riv26@A'));
    }

    #[Test]
    public function passwordWithSeventyThreeCharactersFails(): void
    {
        // 73 caracteres
        $longPassword = str_repeat('A', 70) . '1@#';
        $this->assertSame(73, strlen($longPassword));
        $this->assertFalse(PasswordPolicy::isValid($longPassword));
    }

    #[Test]
    public function twoHundredGeneratedTemporaryPasswordsAreAllValid(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $temporary = PasswordPolicy::generateTemporaryPassword(12);

            $this->assertSame(12, strlen($temporary));
            $this->assertTrue(
                PasswordPolicy::isValid($temporary),
                "La contrasea temporal '{$temporary}' no cumple con PasswordPolicy::isValid",
            );
        }
    }
}