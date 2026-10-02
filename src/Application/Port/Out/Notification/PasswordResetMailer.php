<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Notification;

interface PasswordResetMailer
{
    public function sendPasswordReset(string $recipientName, string $recipientEmail, string $token): void;
}
