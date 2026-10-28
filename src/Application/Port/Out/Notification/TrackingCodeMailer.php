<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Notification;

interface TrackingCodeMailer
{
    /** @param bool $isSender true para el remitente, false para el destinatario */
    public function sendTrackingCode(string $recipientName, string $recipientEmail, string $tracking, bool $isSender): void;
}
