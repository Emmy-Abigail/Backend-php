<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Notification;

use App\Application\Exception\EmailDeliveryFailed;
use App\Application\Port\Out\Notification\UserCredentialsMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

final readonly class SmtpUserCredentialsMailer implements UserCredentialsMailer
{
    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        private string $fromAddress,
        private string $fromName,
    ) {
    }

    public function sendTemporaryPassword(string $recipientName, string $recipientEmail, string $temporaryPassword): void
    {
        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->Host = $this->host;
            $mailer->Port = $this->port;
            $mailer->SMTPAuth = true;
            $mailer->Username = $this->username;
            $mailer->Password = $this->password;
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->setFrom($this->fromAddress, $this->fromName);
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
}
