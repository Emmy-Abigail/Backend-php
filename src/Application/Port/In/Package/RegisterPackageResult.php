<?php

declare(strict_types=1);

namespace App\Application\Port\In\Package;

use App\Application\Port\In\Batch\LimaTime;
use App\Application\Port\Out\Pricing\PackageQuote;
use App\Domain\Package\DeliveryEstimate;
use DateTimeImmutable;
use DateTimeZone;

final readonly class RegisterPackageResult
{
    /**
     * @param array<string, mixed> $batch
     * @param array{remitente: ?bool, destinatario: ?bool} $emailsSent null = sin correo registrado
     */
    public function __construct(
        public int $id,
        public string $tracking,
        public string $registeredAt,
        public PackageQuote $quote,
        public array $batch,
        public array $emailsSent = ['remitente' => null, 'destinatario' => null],
        public bool $assignmentFallback = false,
    ) {
    }

    /** @param array{remitente: ?bool, destinatario: ?bool} $emailsSent */
    public function withEmailsSent(array $emailsSent): self
    {
        return new self($this->id, $this->tracking, $this->registeredAt, $this->quote, $this->batch, $emailsSent, $this->assignmentFallback);
    }

    /** Indica que el lote lo asignó el respaldo FIFO de PHP porque Python no respondió. */
    public function withAssignmentFallback(bool $assignmentFallback): self
    {
        return new self($this->id, $this->tracking, $this->registeredAt, $this->quote, $this->batch, $this->emailsSent, $assignmentFallback);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $registeredAt = new DateTimeImmutable($this->registeredAt, new DateTimeZone('UTC'));

        return [
            'id' => $this->id,
            'tracking_id' => $this->tracking,
            'estado' => 'EN_ALMACEN',
            'fecha_ingreso' => LimaTime::format($registeredAt),
            'fecha_estimada_entrega' => LimaTime::format(DeliveryEstimate::from($registeredAt)),
            'cotizacion' => ['id_tarifa' => $this->quote->tariffId, 'monto' => (float) $this->quote->amount],
            'lote' => $this->batch,
            'correo_enviado' => $this->emailsSent,
            'asignacion_respaldo' => $this->assignmentFallback,
        ];
    }
}
