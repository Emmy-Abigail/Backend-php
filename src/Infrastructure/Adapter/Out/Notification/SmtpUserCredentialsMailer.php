<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Notification;

use App\Application\Exception\EmailDeliveryFailed;
use App\Application\Port\Out\Notification\PasswordResetMailer;
use App\Application\Port\Out\Notification\UserCredentialsMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

final readonly class SmtpUserCredentialsMailer implements UserCredentialsMailer, PasswordResetMailer
{
    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        private string $fromAddress,
        private string $fromName,
        private ?string $passwordResetUrl = null,
    ) {
    }

    public function sendTemporaryPassword(string $recipientName, string $recipientEmail, string $temporaryPassword): void
    {
        try {
            $mailer = $this->createMailer();
            $mailer->addAddress($recipientEmail, $recipientName);
            $mailer->isHTML(true);
            $mailer->Subject = 'Credenciales temporales de acceso';
            $mailer->Body = sprintf(
                '<p>Hola, %s:</p><p>Se creó tu cuenta. Tu contraseña temporal es:</p><p><strong>%s</strong></p><p>Debes cambiarla al iniciar sesión.</p>',
                htmlspecialchars($recipientName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($temporaryPassword, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
            $mailer->AltBody = sprintf(
                "Hola, %s:\n\nSe creó tu cuenta. Tu contraseña temporal es: %s\n\nDebes cambiarla al iniciar sesión.",
                $recipientName,
                $temporaryPassword,
            );
            $mailer->send();
        } catch (Exception $exception) {
            throw new EmailDeliveryFailed('No fue posible enviar las credenciales por correo', 0, $exception);
        }
    }

    public function sendPasswordReset(string $recipientName, string $recipientEmail, string $token): void
    {
        $tokenForHtml = htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $resetLink = $this->passwordResetLink($token);

        try {
            $mailer = $this->createMailer();
            $mailer->addAddress($recipientEmail, $recipientName);
            $mailer->isHTML(true);
            $mailer->Subject = 'Restablecimiento de contraseña';

            $body = sprintf(
                '<p>Hola, %s:</p><p>Recibimos una solicitud para restablecer tu contraseña.</p><p>Tu código de recuperación es:</p><p><strong>%s</strong></p><p>Este código vence en 30 minutos y solo puede usarse una vez.</p><p>Si no solicitaste este cambio, puedes ignorar este correo.</p>',
                htmlspecialchars($recipientName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $tokenForHtml,
            );
            $altBody = sprintf(
                "Hola, %s:\n\nRecibimos una solicitud para restablecer tu contraseña.\n\nTu código de recuperación es: %s\n\nEste código vence en 30 minutos y solo puede usarse una vez.\n\nSi no solicitaste este cambio, puedes ignorar este correo.",
                $recipientName,
                $token,
            );

            if ($resetLink !== null) {
                $escapedLink = htmlspecialchars($resetLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $body .= sprintf('<p><a href="%s">Restablecer contraseña</a></p>', $escapedLink);
                $altBody .= sprintf("\n\nTambién puedes abrir este enlace:\n%s", $resetLink);
            }

            $mailer->Body = $body;
            $mailer->AltBody = $altBody;
            $mailer->send();
        } catch (Exception $exception) {
            throw new EmailDeliveryFailed('No fue posible enviar el correo de recuperación', 0, $exception);
        }
    }

    private function createMailer(): PHPMailer
    {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $this->host;
        $mailer->Port = $this->port;
        $mailer->SMTPAuth = true;
        $mailer->Username = $this->username;
        $mailer->Password = $this->password;
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom($this->fromAddress, $this->fromName);

        return $mailer;
    }

    private function passwordResetLink(string $token): ?string
    {
        if ($this->passwordResetUrl === null || trim($this->passwordResetUrl) === '') {
            return null;
        }

        $separator = str_contains($this->passwordResetUrl, '?') ? '&' : '?';

        return sprintf('%s%stoken=%s', $this->passwordResetUrl, $separator, rawurlencode($token));
    }
}
