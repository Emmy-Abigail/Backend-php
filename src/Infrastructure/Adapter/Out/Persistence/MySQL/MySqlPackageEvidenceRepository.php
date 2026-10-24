<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Port\Out\Delivery\PackageEvidenceReference;
use App\Application\Port\Out\Delivery\PackageEvidenceRepository;
use Illuminate\Database\Connection;

final readonly class MySqlPackageEvidenceRepository implements PackageEvidenceRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function find(int $packageId): ?PackageEvidenceReference
    {
        // Subconsulta correlacionada: estado, sede y evidencia en una sola lectura.
        // La foto procede solo del evento ENTREGADO, nunca de otro tipo de evento.
        $deliveryKey = $this->connection->table('historial_estados as h')
            ->join('estados_paquete as e', 'e.id', '=', 'h.estado_nuevo_id')
            ->whereColumn('h.id_paquete', 'p.id')->where('e.codigo', 'ENTREGADO')
            ->orderByDesc('h.created_at')->orderByDesc('h.id')->limit(1)->select('h.evidencia_key');
        $row = $this->connection->table('paquetes as p')
            ->join('estados_paquete as actual', 'actual.id', '=', 'p.estado_actual_id')
            ->where('p.id', $packageId)->select(['p.id_sede', 'actual.codigo as estado'])
            ->selectSub($deliveryKey, 'evidencia_key')->first();
        if ($row === null) {
            return null;
        }
        return new PackageEvidenceReference((int) $row->id_sede, (string) $row->estado,
            $row->evidencia_key === null ? null : (string) $row->evidencia_key);
    }
}
