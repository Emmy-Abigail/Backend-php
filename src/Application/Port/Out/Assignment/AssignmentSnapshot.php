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
        return [
            'id_sede' => $this->context->sedeId,
            'ubigeo_destino' => $this->context->destinationUbigeo,
            'paquete' => $this->details->measurements(),
            'vehiculos' => $this->vehicles,
            'lotes_candidatos' => $this->batches,
        ];
    }

    /**
     * Asignación que exigen las reglas R2 a R6 del enunciado: vehículo de menor nivel compatible
     * y lote más antiguo con capacidad (FIFO estricto). PHP la usa para validar a Python y como respaldo.
     */
    public function expectedAssignment(): BatchAssignment
    {
        $compatible = array_values(array_filter($this->vehicles, fn (array $vehicle): bool =>
            $this->details->weightUnits <= DecimalQuantity::units($vehicle['peso_max_total_kg'], 2)
            && max($this->details->lengthUnits, $this->details->widthUnits, $this->details->heightUnits)
                <= DecimalQuantity::units($vehicle['lado_max_cm'], 1)));
        usort($compatible, static fn (array $a, array $b): int => [$a['nivel'], $a['id']] <=> [$b['nivel'], $b['id']]);
        $vehicle = $compatible[0] ?? null;
        if ($vehicle === null) {
            throw new AssignmentUnavailable('Ningun vehiculo admite el paquete');
        }

        $available = array_values(array_filter($this->batches, fn (array $batch): bool =>
            $batch['id_tipo_vehiculo'] === $vehicle['id']
            && $batch['cantidad_paquetes'] + 1 <= $vehicle['max_paquetes']
            && DecimalQuantity::units($batch['peso_total_kg'], 2) + $this->details->weightUnits
                <= DecimalQuantity::units($vehicle['peso_max_total_kg'], 2)));
        usort($available, static fn (array $a, array $b): int =>
            [new \DateTimeImmutable($a['created_at']), $a['id']] <=> [new \DateTimeImmutable($b['created_at']), $b['id']]);
        $batchId = $available[0]['id'] ?? null;

        return new BatchAssignment($batchId === null ? 'CREAR_LOTE' : 'USAR_LOTE', $vehicle['id'], $batchId);
    }

    /** Valida la recomendación externa antes de usar los identificadores para guardar datos. */
    public function assertValid(BatchAssignment $assignment): void
    {
        $expected = $this->expectedAssignment();
        if ($assignment->vehicleId !== $expected->vehicleId) {
            throw new AssignmentUnavailable('El servicio de asignacion devolvio un vehiculo incompatible');
        }
        if ($assignment->batchId !== $expected->batchId) {
            throw new AssignmentUnavailable('El servicio de asignacion devolvio un lote incompatible');
        }
    }
}
