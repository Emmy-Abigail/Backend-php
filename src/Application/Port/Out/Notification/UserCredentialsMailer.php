<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Notification;

interface UserCredentialsMailer
{
    public function sendTemporaryPassword(string $recipientName, string $recipientEmail, string $temporaryPassword): void;
}
