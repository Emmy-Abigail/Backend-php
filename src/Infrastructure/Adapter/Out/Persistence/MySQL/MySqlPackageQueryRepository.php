<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Port\In\Package\ListPackagesResult;
use App\Application\Port\Out\Persistence\PackageQueryRepository;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final readonly class MySqlPackageQueryRepository implements PackageQueryRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function searchBySede(
        int $sedeId,
        ?string $search,
        ?string $status,
        ?string $from,
        ?string $to,
        int $page,
        int $perPage,
    ): ListPackagesResult {
        $query = $this->connection->table('paquetes as p')
            ->join('estados_paquete as e', 'e.id', '=', 'p.estado_actual_id')
            ->join('distritos as d', 'd.ubigeo', '=', 'p.ubigeo_destino')
            ->where('p.id_sede', $sedeId);

        if ($search !== null) {
            $like = addcslashes($search, '\\%_');
            $query->where(static function (Builder $q) use ($like): void {
                $q->where('p.tracking_id', 'like', $like . '%')
                    ->orWhere('p.destinatario_dni', 'like', $like . '%')
                    ->orWhere('p.destinatario_nombres', 'like', '%' . $like . '%');
            });
        }
        if ($status !== null) {
            $query->where('e.codigo', $status);
        }
        if ($from !== null) {
            $query->where('p.fecha_ingreso', '>=', $from . ' 00:00:00');
        }
        if ($to !== null) {
            // Incluye todo el día "hasta".
            $nextDay = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
            $query->where('p.fecha_ingreso', '<', $nextDay . ' 00:00:00');
        }

        $total = (clone $query)->count();

        $rows = $query
            ->orderByDesc('p.fecha_ingreso')
            ->orderByDesc('p.id')
            ->forPage($page, $perPage)
            ->get([
                'p.id',
                'p.tracking_id',
                'p.destinatario_nombres',
                'p.destinatario_dni',
                'p.ubigeo_destino',
                'd.nombre_mostrado as distrito',
                'p.peso_kg',
                'p.costo_estimado',
                'e.codigo as estado_codigo',
                'e.nombre as estado_nombre',
                'p.id_lote',
                'p.orden_parada',
                'p.fecha_ingreso',
            ]);

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row->id,
                'tracking_id' => $row->tracking_id,
                'destinatario' => [
                    'nombres' => $row->destinatario_nombres,
                    'dni' => $row->destinatario_dni,
                ],
                'destino' => [
                    'ubigeo' => $row->ubigeo_destino,
                    'distrito' => $row->distrito,
                ],
                'peso_kg' => (float) $row->peso_kg,
                'costo_estimado' => (string) $row->costo_estimado,
                'estado' => [
                    'codigo' => $row->estado_codigo,
                    'nombre' => $row->estado_nombre,
                ],
                'id_lote' => $row->id_lote === null ? null : (int) $row->id_lote,
                'orden_parada' => $row->orden_parada === null ? null : (int) $row->orden_parada,
                'fecha_ingreso' => $row->fecha_ingreso,
            ];
        }

        return new ListPackagesResult($items, $total, $page, $perPage);
    }
}
