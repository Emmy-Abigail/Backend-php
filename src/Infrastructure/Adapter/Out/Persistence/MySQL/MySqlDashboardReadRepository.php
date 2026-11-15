<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Port\Out\Dashboard\DashboardReadRepository;
use App\Application\Port\Out\Dashboard\DashboardScope;
use App\Application\Port\Out\Dashboard\DispatchedPackage;
use App\Application\Port\Out\Dashboard\FailedDelivery;
use App\Application\Port\Out\Dashboard\OperatorReference;
use App\Application\Port\Out\Dashboard\RegistrationGroup;
use App\Application\Port\Out\Dashboard\TerminalEvent;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use RuntimeException;

/**
 * Consultas de solo lectura del dashboard. Usa SQL estándar (sin funciones propias de MySQL) para
 * funcionar igual en MySQL, TiDB y en el SQLite de las pruebas. La BD guarda las fechas en UTC.
 */
final class MySqlDashboardReadRepository implements DashboardReadRepository
{
    /** @var array<string, int>|null ids de estados_paquete por código; se leen una vez por petición */
    private ?array $stateIds = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function registrationGroups(DashboardScope $scope): array
    {
        $failedId = $this->stateId('FALLIDO');

        $query = $this->connection->table('paquetes as p')
            ->join('estados_paquete as e', 'e.id', '=', 'p.estado_actual_id')
            // Un paquete termina una sola vez, así que tiene como máximo un registro FALLIDO.
            ->leftJoin('historial_estados as h', static function (JoinClause $join) use ($failedId): void {
                $join->on('h.id_paquete', '=', 'p.id')->where('h.estado_nuevo_id', '=', $failedId);
            })
            ->leftJoin('motivos_fallo as m', 'm.id', '=', 'h.id_motivo_fallo');
        $this->inPeriod($query, 'p.fecha_ingreso', $scope);
        $this->inSede($query, 'p.id_sede', $scope);

        $rows = $query
            ->groupBy('p.id_sede', 'p.creado_por', 'e.codigo', 'm.codigo')
            ->get([
                'p.id_sede',
                'p.creado_por',
                'e.codigo as estado',
                'm.codigo as motivo',
                $this->connection->raw('COUNT(*) as total'),
            ]);

        $groups = [];
        foreach ($rows as $row) {
            $groups[] = new RegistrationGroup(
                (int) $row->id_sede,
                (int) $row->creado_por,
                (string) $row->estado,
                $row->estado === 'FALLIDO' && $row->motivo !== null ? (string) $row->motivo : null,
                (int) $row->total,
            );
        }

        return $groups;
    }

    public function terminalEvents(DashboardScope $scope): array
    {
        $query = $this->connection->table('historial_estados as h')
            ->join('estados_paquete as en', 'en.id', '=', 'h.estado_nuevo_id')
            ->join('paquetes as p', 'p.id', '=', 'h.id_paquete')
            ->leftJoin('motivos_fallo as m', 'm.id', '=', 'h.id_motivo_fallo')
            ->leftJoin('lotes as l', 'l.id', '=', 'p.id_lote')
            ->leftJoin('tipos_vehiculo as lv', 'lv.id', '=', 'l.id_tipo_vehiculo')
            ->leftJoin('usuarios as u', 'u.id', '=', 'l.id_conductor')
            ->leftJoin('tipos_vehiculo as uv', 'uv.id', '=', 'u.id_tipo_vehiculo')
            // Reintento: como máximo uno por paquete (UNIQUE id_paquete_origen).
            ->leftJoin('paquetes as r', 'r.id_paquete_origen', '=', 'p.id')
            ->leftJoin('estados_paquete as re', 're.id', '=', 'r.estado_actual_id')
            // Filtrar por id (no por código) permite usar el índice (created_at, estado_nuevo_id).
            ->whereIn('h.estado_nuevo_id', [$this->stateId('ENTREGADO'), $this->stateId('FALLIDO')]);
        $this->inPeriod($query, 'h.created_at', $scope);
        $this->inSede($query, 'p.id_sede', $scope);

        $rows = $query->orderBy('h.created_at')->orderBy('h.id')->get([
            'h.id_paquete',
            'p.id_sede',
            'en.codigo as estado',
            'h.created_at',
            'p.fecha_ingreso',
            'm.codigo as motivo',
            'l.id_conductor',
            'u.nombres as conductor_nombres',
            'u.placa as conductor_placa',
            'uv.codigo as conductor_vehiculo',
            'lv.codigo as lote_vehiculo',
            'r.id as reintento_id',
            're.codigo as reintento_estado',
        ]);

        $events = [];
        foreach ($rows as $row) {
            $events[] = new TerminalEvent(
                packageId: (int) $row->id_paquete,
                sedeId: (int) $row->id_sede,
                status: (string) $row->estado,
                occurredAt: $this->utc($row->created_at),
                registeredAt: $this->utc($row->fecha_ingreso),
                failureReason: $row->motivo === null ? null : (string) $row->motivo,
                driverId: $row->id_conductor === null ? null : (int) $row->id_conductor,
                driverName: $row->conductor_nombres,
                driverPlate: $row->conductor_placa,
                driverVehicleType: $row->conductor_vehiculo,
                batchVehicleType: $row->lote_vehiculo,
                retried: $row->reintento_id !== null,
                retryStatus: $row->reintento_estado,
            );
        }

        return $events;
    }

    public function dispatchedPackages(DashboardScope $scope): array
    {
        $query = $this->connection->table('lotes as l')
            ->join('paquetes as p', 'p.id_lote', '=', 'l.id')
            ->whereNotNull('l.despachado_por')
            ->whereNotNull('l.despachado_at');
        $this->inPeriod($query, 'l.despachado_at', $scope);
        $this->inSede($query, 'l.id_sede', $scope);

        $packages = [];
        foreach ($query->get(['l.despachado_por', 'l.despachado_at', 'p.fecha_ingreso']) as $row) {
            $packages[] = new DispatchedPackage(
                (int) $row->despachado_por,
                $this->utc($row->fecha_ingreso),
                $this->utc($row->despachado_at),
            );
        }

        return $packages;
    }

    public function operators(?int $sedeId): array
    {
        $query = $this->connection->table('usuarios as u')
            ->leftJoin('sedes as s', 's.id', '=', 'u.id_sede')
            ->where('u.rol', 'OPERADOR');
        if ($sedeId !== null) {
            $query->where('u.id_sede', $sedeId);
        }

        $operators = [];
        foreach ($query->orderBy('u.nombres')->orderBy('u.id')->get(['u.id', 'u.nombres', 's.nombre as sede', 'u.activo']) as $row) {
            $operators[] = new OperatorReference((int) $row->id, (string) $row->nombres, (string) $row->sede, (bool) $row->activo);
        }

        return $operators;
    }

    public function failedDeliveries(DashboardScope $scope, int $page, int $perPage): array
    {
        $rows = $this->failedQuery($scope)
            ->orderByDesc('h.created_at')
            ->orderByDesc('h.id')
            ->forPage($page, $perPage)
            ->get([
                'p.id',
                'p.tracking_id',
                'd.nombre_mostrado as distrito',
                's.nombre as sede',
                'm.codigo as motivo_codigo',
                'm.nombre as motivo_nombre',
                'h.created_at',
            ]);

        $items = [];
        foreach ($rows as $row) {
            $items[] = new FailedDelivery(
                (int) $row->id,
                (string) $row->tracking_id,
                (string) $row->distrito,
                (string) $row->sede,
                $row->motivo_codigo,
                $row->motivo_nombre,
                $this->utc($row->created_at),
            );
        }

        return $items;
    }

    public function countFailedDeliveries(DashboardScope $scope): int
    {
        return $this->failedQuery($scope)->count();
    }

    private function failedQuery(DashboardScope $scope): Builder
    {
        $query = $this->connection->table('historial_estados as h')
            ->join('paquetes as p', 'p.id', '=', 'h.id_paquete')
            ->join('distritos as d', 'd.ubigeo', '=', 'p.ubigeo_destino')
            ->join('sedes as s', 's.id', '=', 'p.id_sede')
            ->leftJoin('motivos_fallo as m', 'm.id', '=', 'h.id_motivo_fallo')
            ->where('h.estado_nuevo_id', $this->stateId('FALLIDO'));
        $this->inPeriod($query, 'h.created_at', $scope);
        $this->inSede($query, 'p.id_sede', $scope);

        return $query;
    }

    private function stateId(string $code): int
    {
        $this->stateIds ??= array_map(
            'intval',
            $this->connection->table('estados_paquete')->pluck('id', 'codigo')->all(),
        );

        if (!isset($this->stateIds[$code])) {
            throw new RuntimeException('Falta el estado de paquete ' . $code . ' en el catálogo');
        }

        return $this->stateIds[$code];
    }

    /** El período son días completos de Lima; se convierte a límites UTC [desde, hasta + 1 día). */
    private function inPeriod(Builder $query, string $column, DashboardScope $scope): void
    {
        $query->where($column, '>=', $this->dayBoundaryUtc($scope->from))
            ->where($column, '<', $this->dayBoundaryUtc($scope->to, nextDay: true));
    }

    private function inSede(Builder $query, string $column, DashboardScope $scope): void
    {
        if ($scope->sedeId !== null) {
            $query->where($column, $scope->sedeId);
        }
    }

    private function dayBoundaryUtc(string $date, bool $nextDay = false): string
    {
        $boundary = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('America/Lima'));
        if ($nextDay) {
            $boundary = $boundary->modify('+1 day');
        }

        return $boundary->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function utc(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }
}
