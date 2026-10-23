<?php

declare(strict_types=1);

namespace App\Application\Port\In\Batch;

use DateTimeImmutable;

final readonly class BatchPackage
{
    /**
     * @param array{nombres: string, dni: string, celular: string} $recipient
     * @param array{tipo_via: string, nombre_via: string, numero: ?string, manzana: ?string, lote: ?string, referencia: ?string} $address
     */
    public function __construct(
        public int $id,
        public string $tracking,
        public string $status,
        public ?int $order,
        public string $ubigeo,
        public string $district,
        public string $weightKg,
        public string $longestSideCm,
        public DateTimeImmutable $enteredAt,
        public array $recipient,
        public array $address,
    ) {
    }

    /** @return array<string, mixed> */
    public function toOperatorArray(): array
    {
        return [
            'id' => $this->id,
            'tracking_id' => $this->tracking,
            'estado' => $this->status,
            'orden_parada' => $this->order,
            'distrito' => ['ubigeo' => $this->ubigeo, 'nombre' => $this->district],
            'peso_kg' => (float) $this->weightKg,
            'lado_mayor_cm' => (float) $this->longestSideCm,
            'fecha_ingreso' => LimaTime::format($this->enteredAt),
        ];
    }

    /** @return array<string, mixed> */
    public function toStopArray(): array
    {
        return [
            'orden' => $this->order,
            'id_paquete' => $this->id,
            'tracking_id' => $this->tracking,
            'estado' => $this->status,
            'distrito' => ['ubigeo' => $this->ubigeo, 'nombre' => $this->district],
            'destinatario' => $this->recipient,
            'direccion' => $this->address,
        ];
    }
}
