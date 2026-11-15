<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Assignment;

use App\Application\Exception\AssignmentUnavailable;
use App\Application\Port\Out\Package\RegistrationContext;
use App\Domain\Package\DecimalQuantity;
use App\Domain\Package\PackageDetails;

final readonly class AssignmentSnapshot
{
    /** @param list<array<string, mixed>> $vehicles @param list<array<string, mixed>> $batches */
    public function __construct(
        public RegistrationContext $context,
        public PackageDetails $details,
        public array $vehicles,
        public array $batches,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'id_sede' => $this->context->sedeId,
            'ubigeo_destino' => $this->context->destinationUbigeo,
            'paquete' => $this->details->measurements(),
            'vehiculos' => $this->vehicles,
            'lotes_candidatos' => $this->batches,
        ];
        $address = $this->details->address;
        if ($address['lat'] !== null && $address['lng'] !== null) {
            // Punto elegido en el mapa: Python lo usa para agrupar paquetes cercanos en un lote.
            $data['destino_lat'] = (float) $address['lat'];
            $data['destino_lng'] = (float) $address['lng'];
        }

        return $data;
    }

    /** Valida la recomendación externa antes de usar los identificadores para guardar datos. */
    public function assertValid(BatchAssignment $assignment): void
    {
        $compatible = array_values(array_filter($this->vehicles, fn (array $vehicle): bool =>
            $this->details->weightUnits <= DecimalQuantity::units($vehicle['peso_max_total_kg'], 2)
            && max($this->details->lengthUnits, $this->details->widthUnits, $this->details->heightUnits)
                <= DecimalQuantity::units($vehicle['lado_max_cm'], 1)));
        usort($compatible, static fn (array $a, array $b): int => [$a['nivel'], $a['id']] <=> [$b['nivel'], $b['id']]);
        $vehicle = $compatible[0] ?? null;
        if ($vehicle === null || $vehicle['id'] !== $assignment->vehicleId) {
            throw new AssignmentUnavailable('El servicio de asignacion devolvio un vehiculo incompatible');
        }

        $available = array_values(array_filter($this->batches, fn (array $batch): bool =>
            $batch['id_tipo_vehiculo'] === $assignment->vehicleId
            && $batch['cantidad_paquetes'] + 1 <= $vehicle['max_paquetes']
            && DecimalQuantity::units($batch['peso_total_kg'], 2) + $this->details->weightUnits
                <= DecimalQuantity::units($vehicle['peso_max_total_kg'], 2)));
        // Python decide el lote (por antigüedad y cercanía); PHP solo verifica que sea seguro usarlo.
        // CREAR_LOTE siempre es válido: Python puede crear uno nuevo si los abiertos quedan lejos.
        if ($assignment->batchId !== null
            && !in_array($assignment->batchId, array_column($available, 'id'), true)) {
            throw new AssignmentUnavailable('El servicio de asignacion devolvio un lote incompatible');
        }
    }
}
