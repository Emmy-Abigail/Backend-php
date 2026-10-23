<?php

declare(strict_types=1);

namespace App\Application\Port\In\Batch;

final readonly class GetBatchResult
{
    private const PACKAGE_STATES = ['EN_ALMACEN', 'ASIGNADO', 'EN_RUTA', 'ENTREGADO', 'FALLIDO'];

    /**
     * @param list<BatchPackage> $packages
     * @param list<DriverCandidate> $drivers
     */
    public function __construct(
        public BatchSummary $summary,
        public array $packages,
        public array $drivers,
        public string $audience,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $byStatus = array_fill_keys(self::PACKAGE_STATES, 0);
        foreach ($this->packages as $package) {
            $byStatus[$package->status] = ($byStatus[$package->status] ?? 0) + 1;
        }

        $base = $this->summary->toArray() + [
            'progreso' => ['total' => count($this->packages), 'por_estado' => $byStatus],
        ];

        if ($this->audience === 'CONDUCTOR') {
            return $base + [
                'paradas' => array_map(static fn (BatchPackage $package): array => $package->toStopArray(), $this->packages),
            ];
        }

        return $base + [
            'paquetes' => array_map(static fn (BatchPackage $package): array => $package->toOperatorArray(), $this->packages),
            'conductores_disponibles' => array_map(static fn (DriverCandidate $driver): array => $driver->toArray(), $this->drivers),
        ];
    }
}
