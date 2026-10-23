<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Port\In\Batch\BatchPackage;
use App\Application\Port\In\Batch\BatchPage;
use App\Application\Port\In\Batch\BatchSummary;
use App\Application\Port\In\Batch\DriverCandidate;
use App\Application\Port\Out\Batch\BatchReadRepository;
use App\Domain\Batch\BatchStatus;
use App\Domain\Batch\DriverAvailability;
use App\Domain\Package\DecimalQuantity;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final readonly class MySqlBatchReadRepository implements BatchReadRepository
{
    private const SUMMARY_COLUMNS = [
        'l.id', 'l.codigo', 'l.estado', 'l.version', 'l.created_at', 'l.despachado_at',
        's.id as sede_id', 's.nombre as sede_nombre',
        'z.id as zona_id', 'z.codigo as zona_codigo', 'z.nombre as zona_nombre',
        'v.id as vehiculo_id', 'v.codigo as vehiculo_codigo', 'v.nombre as vehiculo_nombre',
        'd.id as conductor_id', 'd.nombres as conductor_nombres', 'd.placa as conductor_placa',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function search(?int $sedeId, ?int $driverId, array $statuses, int $page, int $size): BatchPage
    {
        $query = $this->summaryQuery();

        if ($sedeId !== null) {
            $query->where('l.id_sede', $sedeId);
        }
        if ($driverId !== null) {
            $query->where('l.id_conductor', $driverId);
        }
        $query->whereIn('l.estado', array_map(static fn (BatchStatus $status): string => $status->value, $statuses));

        $total = (clone $query)->count('l.id');

        // Los lotes en preparación salen del más antiguo, que es el que se despacha primero.
        $direction = $statuses === [BatchStatus::EnPreparacion] ? 'asc' : 'desc';
        $rows = $query
            ->orderBy('l.created_at', $direction)
            ->orderBy('l.id', $direction)
            ->offset(($page - 1) * $size)
            ->limit($size)
            ->get(self::SUMMARY_COLUMNS);

        $totals = $this->totals($rows->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->summary($row, $totals);
        }

        return new BatchPage($items, $page, $size, $total);
    }

    public function findSummary(int $batchId): ?BatchSummary
    {
        $row = $this->summaryQuery()->where('l.id', $batchId)->first(self::SUMMARY_COLUMNS);

        return $row === null ? null : $this->summary($row, $this->totals([$batchId]));
    }

    public function packagesOf(int $batchId): array
    {
        $rows = $this->connection->table('paquetes as p')
            ->join('distritos as d', 'd.ubigeo', '=', 'p.ubigeo_destino')
            ->join('estados_paquete as e', 'e.id', '=', 'p.estado_actual_id')
            ->where('p.id_lote', $batchId)
            ->orderByRaw('p.orden_parada IS NULL')
            ->orderBy('p.orden_parada')
            ->orderBy('p.fecha_ingreso')
            ->orderBy('p.id')
            ->selectRaw(
                'p.id, p.tracking_id, e.codigo AS estado, p.orden_parada, p.ubigeo_destino, '
                . 'd.nombre_mostrado AS distrito, p.peso_kg, '
                . 'GREATEST(p.largo_cm, p.ancho_cm, p.alto_cm) AS lado_mayor, p.fecha_ingreso, '
                . 'p.destinatario_nombres, p.destinatario_dni, p.destinatario_celular, '
                . 'p.tipo_via, p.nombre_via, p.numero, p.manzana, p.lote_predio, p.referencia',
            )
            ->get();

        $packages = [];
        foreach ($rows as $row) {
            $packages[] = new BatchPackage(
                (int) $row->id,
                (string) $row->tracking_id,
                (string) $row->estado,
                $row->orden_parada === null ? null : (int) $row->orden_parada,
                (string) $row->ubigeo_destino,
                (string) $row->distrito,
                (string) $row->peso_kg,
                (string) $row->lado_mayor,
                new DateTimeImmutable((string) $row->fecha_ingreso, new DateTimeZone('UTC')),
                [
                    'nombres' => (string) $row->destinatario_nombres,
                    'dni' => (string) $row->destinatario_dni,
                    'celular' => (string) $row->destinatario_celular,
                ],
                [
                    'tipo_via' => (string) $row->tipo_via,
                    'nombre_via' => (string) $row->nombre_via,
                    'numero' => $row->numero === null ? null : (string) $row->numero,
                    'manzana' => $row->manzana === null ? null : (string) $row->manzana,
                    'lote' => $row->lote_predio === null ? null : (string) $row->lote_predio,
                    'referencia' => $row->referencia === null ? null : (string) $row->referencia,
                ],
            );
        }

        return $packages;
    }

    public function availableDrivers(int $vehicleTypeId): array
    {
        $rows = $this->connection->table('usuarios as u')
            ->leftJoin('lotes as l', static function ($join): void {
                $join->on('l.id_conductor', '=', 'u.id')->whereIn('l.estado', ['ASIGNADO', 'EN_RUTA']);
            })
            ->where('u.rol', 'CONDUCTOR')
            ->where('u.activo', true)
            ->where('u.id_tipo_vehiculo', $vehicleTypeId)
            ->groupBy('u.id', 'u.nombres', 'u.placa')
            ->havingRaw('COUNT(l.id) < ?', [DriverAvailability::MAX_PENDING_BATCHES])
            ->orderByRaw('(en_ruta > 0) ASC')
            ->orderByRaw('pendientes ASC')
            ->orderBy('u.nombres')
            ->orderBy('u.id')
            ->selectRaw(
                'u.id, u.nombres, u.placa, COUNT(l.id) AS pendientes, '
                . "COALESCE(SUM(CASE WHEN l.estado = 'EN_RUTA' THEN 1 ELSE 0 END), 0) AS en_ruta",
            )
            ->get();

        $drivers = [];
        foreach ($rows as $row) {
            $drivers[] = new DriverCandidate(
                (int) $row->id,
                (string) $row->nombres,
                $row->placa === null ? null : (string) $row->placa,
                (int) $row->pendientes,
                (int) $row->en_ruta > 0,
            );
        }

        return $drivers;
    }

    private function summaryQuery(): Builder
    {
        return $this->connection->table('lotes as l')
            ->join('sedes as s', 's.id', '=', 'l.id_sede')
            ->join('zonas as z', 'z.id', '=', 'l.id_zona_destino')
            ->join('tipos_vehiculo as v', 'v.id', '=', 'l.id_tipo_vehiculo')
            ->leftJoin('usuarios as d', 'd.id', '=', 'l.id_conductor');
    }

    /**
     * Cantidad y peso de los paquetes de cada lote (peso en centésimas de kg).
     *
     * @param list<int> $batchIds
     * @return array<int, array{count: int, weight: int}>
     */
    private function totals(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }

        $totals = [];
        $rows = $this->connection->table('paquetes')
            ->whereIn('id_lote', $batchIds)
            ->groupBy('id_lote')
            ->selectRaw('id_lote, COUNT(*) AS cantidad, SUM(peso_kg) AS peso')
            ->get();
        foreach ($rows as $row) {
            $totals[(int) $row->id_lote] = [
                'count' => (int) $row->cantidad,
                'weight' => DecimalQuantity::units($row->peso, 2),
            ];
        }

        return $totals;
    }

    /** @param array<int, array{count: int, weight: int}> $totals */
    private function summary(object $row, array $totals): BatchSummary
    {
        $total = $totals[(int) $row->id] ?? ['count' => 0, 'weight' => 0];
        $utc = new DateTimeZone('UTC');

        return new BatchSummary(
            (int) $row->id,
            (string) $row->codigo,
            (string) $row->estado,
            (int) $row->version,
            ['id' => (int) $row->sede_id, 'nombre' => (string) $row->sede_nombre],
            ['id' => (int) $row->zona_id, 'codigo' => (string) $row->zona_codigo, 'nombre' => (string) $row->zona_nombre],
            ['id' => (int) $row->vehiculo_id, 'codigo' => (string) $row->vehiculo_codigo, 'nombre' => (string) $row->vehiculo_nombre],
            $total['count'],
            DecimalQuantity::format($total['weight'], 2),
            new DateTimeImmutable((string) $row->created_at, $utc),
            $row->despachado_at === null ? null : new DateTimeImmutable((string) $row->despachado_at, $utc),
            $row->conductor_id === null ? null : [
                'id' => (int) $row->conductor_id,
                'nombres' => (string) $row->conductor_nombres,
                'placa' => $row->conductor_placa === null ? null : (string) $row->conductor_placa,
            ],
        );
    }
}
