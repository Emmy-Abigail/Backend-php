<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Port\In\Package\ListPackagesResult;
use App\Application\Port\Out\Persistence\PackageDetail;
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

    public function findDetail(int $packageId): ?PackageDetail
    {
        $row = $this->connection->table('paquetes as p')
            ->join('estados_paquete as e', 'e.id', '=', 'p.estado_actual_id')
            ->join('distritos as d', 'd.ubigeo', '=', 'p.ubigeo_destino')
            ->join('sedes as s', 's.id', '=', 'p.id_sede')
            ->join('clientes as c', 'c.id', '=', 'p.id_remitente')
            ->leftJoin('lotes as l', 'l.id', '=', 'p.id_lote')
            ->leftJoin('usuarios as u', 'u.id', '=', 'l.id_conductor')
            ->where('p.id', $packageId)
            ->first([
                'p.id',
                'p.tracking_id',
                'p.id_sede',
                's.nombre as sede_nombre',
                'e.codigo as estado_codigo',
                'e.nombre as estado_nombre',
                'p.destinatario_nombres',
                'p.destinatario_dni',
                'p.destinatario_celular',
                'p.ubigeo_destino',
                'd.nombre_mostrado as distrito',
                'p.tipo_via',
                'p.nombre_via',
                'p.numero',
                'p.manzana',
                'p.lote_predio',
                'p.referencia',
                'p.peso_kg',
                'p.largo_cm',
                'p.ancho_cm',
                'p.alto_cm',
                'p.costo_estimado',
                'c.tipo_documento as remitente_tipo_documento',
                'c.numero_documento as remitente_numero_documento',
                'c.nombres_razon_social as remitente_nombres',
                'c.celular as remitente_celular',
                'p.id_lote',
                'l.codigo as lote_codigo',
                'l.estado as lote_estado',
                'l.id_conductor',
                'u.nombres as conductor_nombres',
                'u.placa as conductor_placa',
                'p.orden_parada',
                'p.fecha_ingreso',
            ]);

        if ($row === null) {
            return null;
        }

        $data = [
            'id' => (int) $row->id,
            'tracking_id' => $row->tracking_id,
            'sede' => ['id' => (int) $row->id_sede, 'nombre' => $row->sede_nombre],
            'estado' => ['codigo' => $row->estado_codigo, 'nombre' => $row->estado_nombre],
            'destinatario' => [
                'nombres' => $row->destinatario_nombres,
                'dni' => $row->destinatario_dni,
                'celular' => $row->destinatario_celular,
            ],
            'direccion' => [
                'ubigeo' => $row->ubigeo_destino,
                'distrito' => $row->distrito,
                'tipo_via' => $row->tipo_via,
                'nombre_via' => $row->nombre_via,
                'numero' => $row->numero,
                'manzana' => $row->manzana,
                'lote' => $row->lote_predio,
                'referencia' => $row->referencia,
            ],
            'medidas' => [
                'peso_kg' => (float) $row->peso_kg,
                'largo_cm' => (float) $row->largo_cm,
                'ancho_cm' => (float) $row->ancho_cm,
                'alto_cm' => (float) $row->alto_cm,
            ],
            'costo_estimado' => (string) $row->costo_estimado,
            'remitente' => [
                'tipo_documento' => $row->remitente_tipo_documento,
                'numero_documento' => $row->remitente_numero_documento,
                'nombres' => $row->remitente_nombres,
                'celular' => $row->remitente_celular,
            ],
            'lote' => $row->id_lote === null ? null : [
                'id' => (int) $row->id_lote,
                'codigo' => $row->lote_codigo,
                'estado' => $row->lote_estado,
                'orden_parada' => $row->orden_parada === null ? null : (int) $row->orden_parada,
                'conductor' => $row->id_conductor === null ? null : [
                    'id' => (int) $row->id_conductor,
                    'nombres' => $row->conductor_nombres,
                    'placa' => $row->conductor_placa,
                ],
            ],
            'fecha_ingreso' => $row->fecha_ingreso,
        ];

        return new PackageDetail(
            (int) $row->id_sede,
            $row->id_conductor === null ? null : (int) $row->id_conductor,
            $row->lote_estado,
            $data,
            $this->historyOf($packageId),
        );
    }

    /** @return list<array<string, mixed>> */
    private function historyOf(int $packageId): array
    {
        $rows = $this->connection->table('historial_estados as h')
            ->join('estados_paquete as en', 'en.id', '=', 'h.estado_nuevo_id')
            ->leftJoin('estados_paquete as ea', 'ea.id', '=', 'h.estado_anterior_id')
            ->join('usuarios as u', 'u.id', '=', 'h.id_usuario_ejecutor')
            ->leftJoin('motivos_fallo as m', 'm.id', '=', 'h.id_motivo_fallo')
            ->where('h.id_paquete', $packageId)
            ->orderBy('h.created_at')
            ->orderBy('h.id')
            ->get([
                'ea.codigo as anterior',
                'en.codigo as nuevo',
                'en.nombre as nuevo_nombre',
                'u.nombres as usuario',
                'u.rol as usuario_rol',
                'm.codigo as motivo_codigo',
                'm.nombre as motivo_nombre',
                'h.comentario',
                'h.evidencia_key',
                'h.created_at',
            ]);

        $history = [];
        foreach ($rows as $row) {
            $history[] = [
                'estado_anterior' => $row->anterior,
                'estado_nuevo' => $row->nuevo,
                'estado_nuevo_nombre' => $row->nuevo_nombre,
                'fecha' => $row->created_at,
                'usuario' => ['nombres' => $row->usuario, 'rol' => $row->usuario_rol],
                'motivo_fallo' => $row->motivo_codigo === null ? null : ['codigo' => $row->motivo_codigo, 'nombre' => $row->motivo_nombre],
                'comentario' => $row->comentario,
                // La foto se sirve aparte (#10); aquí solo se indica si existe, sin exponer su ubicación.
                'tiene_evidencia' => $row->evidencia_key !== null,
            ];
        }

        return $history;
    }
}
