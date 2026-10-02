<?php

namespace Tests\Unit\Application\Service\Auth;

use App\Application\Exception\EmailDeliveryFailed;
use App\Application\Port\In\Auth\RequestPasswordResetCommand;
use App\Application\Port\Out\Notification\PasswordResetMailer;
use App\Application\Port\Out\Persistence\PasswordResetTokenRepository;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Application\Service\Auth\RequestPasswordResetService;
use App\Domain\Auth\PasswordResetToken;
use App\Domain\User\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RequestPasswordResetServiceTest extends TestCase
{
    #[Test]
    public function test_crea_un_token_aleatorio_solo_guardando_su_hash_y_envia_el_correo(): void
    {
        $user = new User(1, 'Ana Pérez', 'ana@test.com', 'hash', 'OPERADOR', true);
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects($this->once())->method('findByEmail')->with('ana@test.com')->willReturn($user);

        $tokenRepository = $this->createMock(PasswordResetTokenRepository::class);
        $tokenRepository->expects($this->once())
            ->method('issue')
            ->with(
                1,
                $this->callback(static fn (string $hash): bool => preg_match('/\\A[a-f0-9]{64}\\z/', $hash) === 1),
                $this->isInstanceOf(DateTimeImmutable::class),
                $this->isInstanceOf(DateTimeImmutable::class),
            )
            ->willReturn(new PasswordResetToken(7, 1, new DateTimeImmutable('+30 minutes'), null));

        $mailer = $this->createMock(PasswordResetMailer::class);
        $mailer->expects($this->once())
            ->method('sendPasswordReset')
            ->with(
                'Ana Pérez',
                'ana@test.com',
                $this->callback(static fn (string $token): bool => preg_match('/\\A[a-f0-9]{64}\\z/', $token) === 1),
            );

        (new RequestPasswordResetService($userRepository, $tokenRepository, $mailer))
            ->execute(new RequestPasswordResetCommand('ana@test.com'));
    }

    #[Test]
    public function test_no_crea_ni_envia_token_para_un_correo_ausente_o_usuario_inactivo(): void
    {
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findByEmail')->willReturn(null);

        $tokenRepository = $this->createMock(PasswordResetTokenRepository::class);
        $tokenRepository->expects($this->never())->method('issue');
        $mailer = $this->createMock(PasswordResetMailer::class);
        $mailer->expects($this->never())->method('sendPasswordReset');

        (new RequestPasswordResetService($userRepository, $tokenRepository, $mailer))
            ->execute(new RequestPasswordResetCommand('ausente@test.com'));
    }

    #[Test]
    public function test_inutiliza_el_token_si_falla_el_envio_del_correo(): void
    {
        $user = new User(1, 'Ana Pérez', 'ana@test.com', 'hash', 'OPERADOR', true);
        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('findByEmail')->willReturn($user);

        $tokenRepository = $this->createMock(PasswordResetTokenRepository::class);
        $tokenRepository->method('issue')->willReturn(new PasswordResetToken(7, 1, new DateTimeImmutable('+30 minutes'), null));
        $tokenRepository->expects($this->once())
            ->method('markAsUsed')
            ->with(7, $this->isInstanceOf(DateTimeImmutable::class));

        $mailer = $this->createMock(PasswordResetMailer::class);
        $mailer->method('sendPasswordReset')->willThrowException(new EmailDeliveryFailed());

        $this->expectException(EmailDeliveryFailed::class);
        (new RequestPasswordResetService($userRepository, $tokenRepository, $mailer))
            ->execute(new RequestPasswordResetCommand('ana@test.com'));
    }
}
