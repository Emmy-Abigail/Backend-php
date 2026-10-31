<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Port\Out\Persistence\PackageRetrySource;
use App\Application\Port\Out\Persistence\PackageRetrySourceRepository;
use Illuminate\Database\Connection;

final readonly class MySqlPackageRetrySourceRepository implements PackageRetrySourceRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findRetrySource(int $packageId): ?PackageRetrySource
    {
        $row = $this->connection->table('paquetes as p')
            ->join('estados_paquete as e', 'e.id', '=', 'p.estado_actual_id')
            ->join('clientes as c', 'c.id', '=', 'p.id_remitente')
            ->where('p.id', $packageId)
            ->first([
                'p.id_sede',
                'e.codigo as estado',
                'p.id_paquete_origen',
                'c.tipo_documento',
                'c.numero_documento',
                'c.nombres_razon_social',
                'c.celular as remitente_celular',
                'c.correo as remitente_correo',
                'p.destinatario_nombres',
                'p.destinatario_dni',
                'p.destinatario_celular',
                'p.destinatario_correo',
                'p.ubigeo_destino',
                'p.tipo_via',
                'p.nombre_via',
                'p.numero',
                'p.manzana',
                'p.lote_predio',
                'p.referencia',
                'p.destino_lat',
                'p.destino_lng',
                'p.peso_kg',
                'p.largo_cm',
                'p.ancho_cm',
                'p.alto_cm',
            ]);

        if ($row === null) {
            return null;
        }

        $alreadyRetried = $this->connection->table('paquetes')->where('id_paquete_origen', $packageId)->exists();
        $last = $this->connection->table('historial_estados as h')
            ->join('estados_paquete as e', 'e.id', '=', 'h.estado_nuevo_id')
            ->leftJoin('motivos_fallo as m', 'm.id', '=', 'h.id_motivo_fallo')
            ->where('h.id_paquete', $packageId)
            ->orderByDesc('h.created_at')->orderByDesc('h.id')
            ->first(['e.codigo as estado', 'm.codigo as motivo']);

        return new PackageRetrySource(
            (int) $row->id_sede,
            (string) $row->estado,
            $alreadyRetried,
            $this->previousRetries($row->id_paquete_origen),
            [
                'remitente' => [
                    'tipo_documento' => $row->tipo_documento,
                    'numero_documento' => $row->numero_documento,
                    'nombres' => $row->nombres_razon_social,
                    'celular' => $row->remitente_celular,
                    'correo' => $row->remitente_correo,
                ],
                'destinatario' => [
                    'nombres' => $row->destinatario_nombres,
                    'dni' => $row->destinatario_dni,
                    'celular' => $row->destinatario_celular,
                    'correo' => $row->destinatario_correo,
                ],
                'direccion' => [
                    'ubigeo_distrito' => $row->ubigeo_destino,
                    'tipo_via' => $row->tipo_via,
                    'nombre_via' => $row->nombre_via,
                    'numero' => $row->numero,
                    'manzana' => $row->manzana,
                    'lote' => $row->lote_predio,
                    'referencia' => $row->referencia,
                    'lat' => $row->destino_lat === null ? null : (float) $row->destino_lat,
                    'lng' => $row->destino_lng === null ? null : (float) $row->destino_lng,
                ],
                // Como texto: así PackageDetails los lee sin pérdidas de decimales.
                'peso_kg' => (string) $row->peso_kg,
                'largo_cm' => (string) $row->largo_cm,
                'ancho_cm' => (string) $row->ancho_cm,
                'alto_cm' => (string) $row->alto_cm,
            ],
            $last?->estado,
            $last?->motivo,
        );
    }

    /** Recorre id_paquete_origen hacia atrás hasta el paquete original y cuenta los saltos. */
    private function previousRetries(mixed $originId): int
    {
        $count = 0;
        // El tope solo protege de un ciclo imposible en datos corruptos; la cadena real nunca pasa del máximo de reintentos.
        while ($originId !== null && $count < 50) {
            $count++;
            $originId = $this->connection->table('paquetes')->where('id', (int) $originId)->value('id_paquete_origen');
        }

        return $count;
    }
}
