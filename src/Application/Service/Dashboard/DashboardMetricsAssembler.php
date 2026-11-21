<?php

declare(strict_types=1);

namespace App\Application\Service\Dashboard;

use App\Application\Port\In\Batch\LimaTime;
use App\Application\Port\Out\Dashboard\DispatchedPackage;
use App\Application\Port\Out\Dashboard\FailedDelivery;
use App\Application\Port\Out\Dashboard\OperatorReference;
use App\Application\Port\Out\Dashboard\RegistrationGroup;
use App\Application\Port\Out\Dashboard\TerminalEvent;
use App\Domain\Catalog\FailureReason;
use App\Domain\Catalog\Sede;
use App\Domain\Catalog\VehicleType;
use App\Domain\Dashboard\DashboardMath;
use App\Domain\Dashboard\LowestSedeRule;

/**
 * Convierte los hechos leídos de la BD en el payload del dashboard (Demo 4, métricas 1 a 9).
 * No accede a infraestructura: recibe todo por parámetro y es determinista, por eso se prueba aislado.
 */
final class DashboardMetricsAssembler
{
    private const FLOW_ORDER = ['EN_ALMACEN', 'ASIGNADO', 'EN_RUTA', 'ENTREGADO', 'FALLIDO'];
    private const WRONG_ADDRESS = 'DIRECCION_ERRONEA';
    private const TOP_DRIVERS = 5;

    /**
     * @param list<RegistrationGroup> $registrations
     * @param list<TerminalEvent> $events
     * @param list<DispatchedPackage> $dispatched
     * @param list<OperatorReference> $operators
     * @param list<FailedDelivery> $latestFailures
     * @param list<Sede> $sedes
     * @param list<FailureReason> $failureReasons
     * @param list<VehicleType> $vehicleTypes
     * @param array{id: int, nombre: string}|null $sede sede filtrada; null = todas las sedes
     * @return array<string, mixed>
     */
    public function assemble(
        string $role,
        int $userId,
        ?array $sede,
        array $registrations,
        array $events,
        array $dispatched,
        array $operators,
        array $latestFailures,
        array $sedes,
        array $failureReasons,
        array $vehicleTypes,
    ): array {
        $delivered = array_values(array_filter($events, static fn (TerminalEvent $e): bool => $e->isDelivered()));
        $failed = array_values(array_filter($events, static fn (TerminalEvent $e): bool => $e->isFailed()));
        $registered = $this->sumCounts($registrations);

        $metrics = [
            'kpis' => [
                'paquetes_registrados' => $registered,
                'entregas_exitosas_pct' => DashboardMath::percent(count($delivered), count($delivered) + count($failed)),
                'entregas_exitosas_qty' => count($delivered),
                'finalizados_qty' => count($delivered) + count($failed),
                'tiempo_promedio_horas' => $this->averageDeliveryHours($delivered),
            ],
            'flujo_paquetes' => $this->packageFlow($registrations, $registered),
        ];

        // El ranking solo tiene sentido comparando sedes: administrador con «Todas las sedes».
        if ($role === 'ADMINISTRADOR' && $sede === null) {
            $metrics['ranking_sedes'] = $this->sedeRanking($sedes, $registrations, $events);
        }

        $metrics['fallidos_motivo'] = $this->failuresByReason($failed, $failureReasons, $latestFailures);
        $metrics['top_conductores'] = $this->topDrivers($delivered, $vehicleTypes);
        $metrics['entregas_vehiculo'] = $this->deliveriesByVehicle($delivered, $vehicleTypes);

        if ($role === 'ADMINISTRADOR') {
            $metrics['operadores'] = $this->operatorTable($operators, $registrations, $dispatched);
        } else {
            $metrics['desempeno_operador'] = $this->operatorStats($userId, $registrations, $dispatched);
        }

        $metrics['sede'] = $sede;

        return $metrics;
    }

    /** @param list<RegistrationGroup> $groups */
    private function sumCounts(array $groups): int
    {
        return array_sum(array_map(static fn (RegistrationGroup $g): int => $g->count, $groups));
    }

    /** @param list<TerminalEvent> $delivered */
    private function averageDeliveryHours(array $delivered): ?float
    {
        return DashboardMath::averageHours(array_map(
            static fn (TerminalEvent $e): float => DashboardMath::hoursBetween($e->registeredAt, $e->occurredAt),
            $delivered,
        ));
    }

    /**
     * Métrica 4: paquetes registrados en el período según su estado actual.
     *
     * @param list<RegistrationGroup> $registrations
     * @return array<string, mixed>
     */
    private function packageFlow(array $registrations, int $total): array
    {
        $counts = array_fill_keys(self::FLOW_ORDER, 0);
        foreach ($registrations as $group) {
            if (array_key_exists($group->status, $counts)) {
                $counts[$group->status] += $group->count;
            }
        }

        $flow = ['total' => $total];
        foreach ($counts as $status => $count) {
            $flow[strtolower($status)] = ['cantidad' => $count, 'porcentaje' => DashboardMath::share($count, $total)];
        }

        return $flow;
    }

    /**
     * Métrica 5: por sede, calculada igual que las métricas 1 a 3, con la regla «Sede más baja».
     *
     * @param list<Sede> $sedes
     * @param list<RegistrationGroup> $registrations
     * @param list<TerminalEvent> $events
     * @return list<array<string, mixed>>
     */
    private function sedeRanking(array $sedes, array $registrations, array $events): array
    {
        $rows = [];
        foreach ($sedes as $sede) {
            $rows[$sede->id] = ['sede' => $sede, 'registered' => 0, 'delivered' => [], 'failed' => 0];
        }
        foreach ($registrations as $group) {
            if (isset($rows[$group->sedeId])) {
                $rows[$group->sedeId]['registered'] += $group->count;
            }
        }
        foreach ($events as $event) {
            if (!isset($rows[$event->sedeId])) {
                continue;
            }
            if ($event->isDelivered()) {
                $rows[$event->sedeId]['delivered'][] = $event;
            } else {
                $rows[$event->sedeId]['failed']++;
            }
        }

        // Las sedes inactivas solo aparecen si tuvieron movimiento en el período.
        $rows = array_filter($rows, static fn (array $row): bool => $row['sede']->activa
            || $row['registered'] > 0 || $row['delivered'] !== [] || $row['failed'] > 0);

        $lowest = LowestSedeRule::lowest(array_values(array_map(static fn (array $row): array => [
            'id' => $row['sede']->id,
            'finished' => count($row['delivered']) + $row['failed'],
            'delivered' => count($row['delivered']),
            'failed' => $row['failed'],
        ], $rows)));

        $ranking = [];
        foreach ($rows as $row) {
            $deliveredCount = count($row['delivered']);
            $finished = $deliveredCount + $row['failed'];
            $ranking[] = [
                'id_sede' => $row['sede']->id,
                'nombre_sede' => $row['sede']->nombre,
                'paquetes_registrados' => $row['registered'],
                'entregas_exitosas_qty' => $deliveredCount,
                'entregas_exitosas_pct' => DashboardMath::percent($deliveredCount, $finished),
                'finalizados_qty' => $finished,
                'fallidos_qty' => $row['failed'],
                'tiempo_promedio_horas' => $this->averageDeliveryHours($row['delivered']),
                'es_mas_baja' => $row['sede']->id === $lowest,
                'datos_insuficientes' => !LowestSedeRule::hasEnoughData($finished),
            ];
        }

        return $ranking;
    }

    /**
     * Métrica 6: fallidos por motivo, direcciones corregidas y últimos fallidos.
     *
     * @param list<TerminalEvent> $failed
     * @param list<FailureReason> $reasons
     * @param list<FailedDelivery> $latestFailures
     * @return array<string, mixed>
     */
    private function failuresByReason(array $failed, array $reasons, array $latestFailures): array
    {
        $total = count($failed);
        $counts = [];
        foreach ($failed as $event) {
            if ($event->failureReason !== null) {
                $counts[$event->failureReason] = ($counts[$event->failureReason] ?? 0) + 1;
            }
        }

        $motives = [];
        foreach ($reasons as $position => $reason) {
            $count = $counts[$reason->codigo] ?? 0;
            $motives[] = [
                'position' => $position,
                'codigo' => $reason->codigo,
                'motivo' => $reason->nombre,
                'cantidad' => $count,
                'porcentaje' => DashboardMath::share($count, $total),
            ];
        }
        // De mayor a menor; con empate se respeta el orden del catálogo.
        usort($motives, static fn (array $a, array $b): int => [$b['cantidad'], $a['position']] <=> [$a['cantidad'], $b['position']]);

        $wrongAddress = array_filter($failed, static fn (TerminalEvent $e): bool => $e->failureReason === self::WRONG_ADDRESS);

        return [
            'total_fallidos' => $total,
            'motivos' => array_map(static function (array $motive): array {
                unset($motive['position']);

                return $motive;
            }, $motives),
            'direcciones_corregidas' => [
                'total_fallidos_direccion' => count($wrongAddress),
                'reintentados' => count(array_filter($wrongAddress, static fn (TerminalEvent $e): bool => $e->retried)),
                'entregados' => count(array_filter($wrongAddress, static fn (TerminalEvent $e): bool => $e->retryStatus === 'ENTREGADO')),
            ],
            'ultimos_fallidos' => array_map(static fn (FailedDelivery $f): array => [
                'id_paquete' => $f->packageId,
                'tracking_id' => $f->trackingId,
                'destino' => $f->district,
                'sede' => $f->sedeName,
                'motivo' => $f->reasonName,
                'motivo_codigo' => $f->reasonCode,
                'fecha' => LimaTime::format($f->failedAt),
            ], $latestFailures),
        ];
    }

    /**
     * Métrica 7: top 5 por entregas, en general y por tipo de vehículo del lote.
     *
     * @param list<TerminalEvent> $delivered
     * @param list<VehicleType> $vehicleTypes
     * @return array<string, list<array<string, mixed>>>
     */
    private function topDrivers(array $delivered, array $vehicleTypes): array
    {
        $labels = [];
        foreach ($vehicleTypes as $type) {
            $labels[$type->codigo] = $type->nombre;
        }

        $overall = [];
        $byType = array_fill_keys(array_keys($labels), []);
        foreach ($delivered as $event) {
            if ($event->driverId === null) {
                continue;
            }
            $overallType = $event->driverVehicleType ?? $event->batchVehicleType;
            $this->countDelivery($overall, $event, $overallType);
            if ($event->batchVehicleType !== null && isset($byType[$event->batchVehicleType])) {
                $this->countDelivery($byType[$event->batchVehicleType], $event, $event->batchVehicleType);
            }
        }

        $top = ['todos' => $this->rankDrivers($overall, $labels)];
        foreach ($byType as $code => $drivers) {
            $top[strtolower($code)] = $this->rankDrivers($drivers, $labels);
        }

        return $top;
    }

    /** @param array<int, array<string, mixed>> $drivers */
    private function countDelivery(array &$drivers, TerminalEvent $event, ?string $vehicleType): void
    {
        $id = (int) $event->driverId;
        $drivers[$id] ??= [
            'id' => $id,
            'name' => (string) $event->driverName,
            'plate' => (string) $event->driverPlate,
            'type' => $vehicleType,
            'count' => 0,
        ];
        $drivers[$id]['count']++;
    }

    /**
     * @param array<int, array<string, mixed>> $drivers
     * @param array<string, string> $labels
     * @return list<array<string, mixed>>
     */
    private function rankDrivers(array $drivers, array $labels): array
    {
        usort($drivers, static fn (array $a, array $b): int => [$b['count'], $a['name'], $a['id']] <=> [$a['count'], $b['name'], $b['id']]);

        return array_map(static fn (array $d): array => [
            'id_conductor' => $d['id'],
            'nombre' => $d['name'],
            'tipo_vehiculo' => $d['type'],
            'tipo_vehiculo_label' => $d['type'] === null ? null : ($labels[$d['type']] ?? $d['type']),
            'placa' => $d['plate'],
            'entregas_qty' => $d['count'],
        ], array_slice($drivers, 0, self::TOP_DRIVERS));
    }

    /**
     * Métrica 8: porcentaje de entregas según el tipo de vehículo del lote.
     *
     * @param list<TerminalEvent> $delivered
     * @param list<VehicleType> $vehicleTypes
     * @return array<string, mixed>
     */
    private function deliveriesByVehicle(array $delivered, array $vehicleTypes): array
    {
        $counts = [];
        foreach ($vehicleTypes as $type) {
            $counts[$type->codigo] = 0;
        }
        foreach ($delivered as $event) {
            if ($event->batchVehicleType !== null && isset($counts[$event->batchVehicleType])) {
                $counts[$event->batchVehicleType]++;
            }
        }

        $total = array_sum($counts);
        $result = ['total' => $total];
        foreach ($counts as $code => $count) {
            $result[strtolower($code)] = ['cantidad' => $count, 'porcentaje' => DashboardMath::share($count, $total)];
        }

        return $result;
    }

    /**
     * Métrica 9 (administrador): tabla completa; el cliente ordena, busca y pagina.
     *
     * @param list<OperatorReference> $operators
     * @param list<RegistrationGroup> $registrations
     * @param list<DispatchedPackage> $dispatched
     * @return list<array<string, mixed>>
     */
    private function operatorTable(array $operators, array $registrations, array $dispatched): array
    {
        $table = [];
        foreach ($operators as $operator) {
            $stats = $this->operatorStats($operator->id, $registrations, $dispatched);
            // Los operadores desactivados solo aparecen si trabajaron en el período.
            if (!$operator->active && $stats['registrados'] === 0 && $stats['tiempo_asignacion_horas'] === null) {
                continue;
            }
            $table[] = ['id_usuario' => $operator->id, 'nombre' => $operator->name, 'sede' => $operator->sedeName] + $stats;
        }

        return $table;
    }

    /**
     * Métrica 9 para un operador. Registrados, bien registrados y entregas exitosas se miden sobre
     * los paquetes que registró en el período; el tiempo de asignación, sobre los lotes que despachó.
     *
     * @param list<RegistrationGroup> $registrations
     * @param list<DispatchedPackage> $dispatched
     * @return array<string, int|float|null>
     */
    private function operatorStats(int $operatorId, array $registrations, array $dispatched): array
    {
        $registered = 0;
        $wrongAddress = 0;
        $delivered = 0;
        $finished = 0;
        foreach ($registrations as $group) {
            if ($group->operatorId !== $operatorId) {
                continue;
            }
            $registered += $group->count;
            if ($group->status === 'ENTREGADO') {
                $delivered += $group->count;
                $finished += $group->count;
            } elseif ($group->status === 'FALLIDO') {
                $finished += $group->count;
                if ($group->failureReason === self::WRONG_ADDRESS) {
                    $wrongAddress += $group->count;
                }
            }
        }

        $assignmentHours = [];
        foreach ($dispatched as $package) {
            if ($package->operatorId === $operatorId) {
                $assignmentHours[] = DashboardMath::hoursBetween($package->registeredAt, $package->dispatchedAt);
            }
        }

        return [
            'registrados' => $registered,
            'bien_registrados_pct' => DashboardMath::percent($registered - $wrongAddress, $registered),
            'errores_direccion_qty' => $wrongAddress,
            'entregas_exitosas_pct' => DashboardMath::percent($delivered, $finished),
            'tiempo_asignacion_horas' => DashboardMath::averageHours($assignmentHours),
        ];
    }
}
