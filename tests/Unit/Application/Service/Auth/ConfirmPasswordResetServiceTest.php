<?php

namespace Tests\Unit\Application\Service\Auth;

use App\Application\Exception\InvalidPasswordResetToken;
use App\Application\Port\In\Auth\ConfirmPasswordResetCommand;
use App\Application\Port\Out\Persistence\PasswordResetTokenRepository;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Service\Auth\ConfirmPasswordResetService;
use App\Domain\Auth\PasswordResetToken;
use App\Domain\User\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConfirmPasswordResetServiceTest extends TestCase
{
    #[Test]
    public function test_actualiza_la_contrasena_e_inutiliza_un_token_valido(): void
    {
        $plainToken = str_repeat('a', 64);
        $user = new User(1, 'Ana PÃ©rez', 'ana@test.com', password_hash('ClaveAnterior2026!', PASSWORD_DEFAULT), 'OPERADOR', true);
        $resetToken = new PasswordResetToken(7, 1, new DateTimeImmutable('+30 minutes'), null);

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->once())->method('findById')->with(1)->willReturn($user);
        $userRepository->expects($this->once())
            ->method('updatePassword')
            ->with(1, $this->callback(static fn (string $hash): bool => password_verify('NuevaClave2026#', $hash)), false);

        $tokenRepository = $this->createMock(PasswordResetTokenRepository::class);
        $tokenRepository->expects($this->once())
            ->method('findUsableByHash')
            ->with(hash('sha256', $plainToken), $this->isInstanceOf(DateTimeImmutable::class))
            ->willReturn($resetToken);
        $tokenRepository->expects($this->once())
            ->method('markAsUsed')
            ->with(7, $this->isInstanceOf(DateTimeImmutable::class));

        (new ConfirmPasswordResetService($userRepository, $tokenRepository))
            ->execute(new ConfirmPasswordResetCommand($plainToken, 'NuevaClave2026#'));
    }

    #[Test]
    public function test_rechaza_token_inexistente_expirado_o_ya_utilizado(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->never())->method('findById');
        $tokenRepository = $this->createMock(PasswordResetTokenRepository::class);
        $tokenRepository->method('findUsableByHash')->willReturn(null);

        $this->expectException(InvalidPasswordResetToken::class);
        (new ConfirmPasswordResetService($userRepository, $tokenRepository))
            ->execute(new ConfirmPasswordResetCommand(str_repeat('a', 64), 'NuevaClave2026#'));
    }

    #[Test]
    public function test_inutiliza_el_token_si_el_usuario_ya_no_esta_activo(): void
    {
        $resetToken = new PasswordResetToken(7, 1, new DateTimeImmutable('+30 minutes'), null);
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findById')->willReturn(null);
        $tokenRepository = $this->createMock(PasswordResetTokenRepository::class);
        $tokenRepository->method('findUsableByHash')->willReturn($resetToken);
        $tokenRepository->expects($this->once())->method('markAsUsed')->with(7, $this->isInstanceOf(DateTimeImmutable::class));

        $this->expectException(InvalidPasswordResetToken::class);
        (new ConfirmPasswordResetService($userRepository, $tokenRepository))
            ->execute(new ConfirmPasswordResetCommand(str_repeat('a', 64), 'NuevaClave2026#'));
    }
}
