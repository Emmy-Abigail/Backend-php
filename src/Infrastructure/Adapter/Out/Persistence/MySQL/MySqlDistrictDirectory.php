<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Port\Out\Geocoding\DistrictDirectory;
use Illuminate\Database\Connection;

final readonly class MySqlDistrictDirectory implements DistrictDirectory
{
    public function __construct(private Connection $connection)
    {
    }

    public function activeDistricts(): array
    {
        $districts = [];
        foreach ($this->connection->table('distritos')->where('activo', true)->orderBy('ubigeo')
            ->get(['ubigeo', 'nombre_oficial', 'nombre_mostrado']) as $row) {
            $districts[] = [
                'ubigeo' => (string) $row->ubigeo,
                'nombre_oficial' => (string) $row->nombre_oficial,
                'nombre_mostrado' => (string) $row->nombre_mostrado,
            ];
        }

        return $districts;
    }
}
