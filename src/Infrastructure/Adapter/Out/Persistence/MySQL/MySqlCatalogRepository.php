<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Port\Out\Catalog\CatalogRepository;
use App\Domain\Catalog\Department;
use App\Domain\Catalog\District;
use App\Domain\Catalog\FailureReason;
use App\Domain\Catalog\Province;
use App\Domain\Catalog\Sede;
use App\Domain\Catalog\VehicleType;
use Illuminate\Database\Capsule\Manager as Capsule;

final class MySqlCatalogRepository implements CatalogRepository
{
    /**
     * @return list<Department>
     */
    public function getGeography(): array
    {
        $depRecords = Capsule::table('departamentos')->orderBy('ubigeo')->get();
        $provRecords = Capsule::table('provincias')->orderBy('ubigeo')->get();
        $distRecords = Capsule::table('distritos')->where('activo', true)->orderBy('ubigeo')->get();

        $districtsByProvince = [];
        foreach ($distRecords as $dist) {
            $districtsByProvince[(string) $dist->ubigeo_provincia][] = new District(
                ubigeo: (string) $dist->ubigeo,
                provinceUbigeo: (string) $dist->ubigeo_provincia,
                zoneId: (int) $dist->id_zona,
                officialName: (string) $dist->nombre_oficial,
                displayName: (string) $dist->nombre_mostrado,
                lat: (float) $dist->lat,
                lng: (float) $dist->lng,
                active: (bool) $dist->activo,
            );
        }

        $provincesByDepartment = [];
        foreach ($provRecords as $prov) {
            $provUbigeo = (string) $prov->ubigeo;
            $provincesByDepartment[(string) $prov->ubigeo_departamento][] = new Province(
                ubigeo: $provUbigeo,
                departmentUbigeo: (string) $prov->ubigeo_departamento,
                name: (string) $prov->nombre,
                districts: $districtsByProvince[$provUbigeo] ?? [],
            );
        }

        $departments = [];
        foreach ($depRecords as $dep) {
            $depUbigeo = (string) $dep->ubigeo;
            $departments[] = new Department(
                ubigeo: $depUbigeo,
                name: (string) $dep->nombre,
                provinces: $provincesByDepartment[$depUbigeo] ?? [],
            );
        }

        return $departments;
    }

    /**
     * @return list<Sede>
     */
    public function getSedes(): array
    {
        $records = Capsule::table('sedes')
            ->join('zonas', 'sedes.id_zona', '=', 'zonas.id')
            ->join('distritos', 'sedes.ubigeo_distrito', '=', 'distritos.ubigeo')
            ->select([
                'sedes.id',
                'sedes.nombre',
                'sedes.id_zona',
                'zonas.codigo as zona_codigo',
                'zonas.nombre as zona_nombre',
                'sedes.ubigeo_distrito',
                'distritos.nombre_mostrado as distrito_nombre',
                'sedes.direccion',
                'sedes.lat',
                'sedes.lng',
                'sedes.activa',
            ])
            ->orderBy('sedes.id')
            ->get();

        $sedes = [];
        foreach ($records as $record) {
            $sedes[] = new Sede(
                id: (int) $record->id,
                nombre: (string) $record->nombre,
                idZona: (int) $record->id_zona,
                zonaCodigo: (string) $record->zona_codigo,
                zonaNombre: (string) $record->zona_nombre,
                ubigeoDistrito: (string) $record->ubigeo_distrito,
                distritoNombre: (string) $record->distrito_nombre,
                direccion: (string) $record->direccion,
                lat: (float) $record->lat,
                lng: (float) $record->lng,
                activa: (bool) $record->activa,
            );
        }

        return $sedes;
    }

    /**
     * @return list<VehicleType>
     */
    public function getVehicleTypes(): array
    {
        $records = Capsule::table('tipos_vehiculo')
            ->orderBy('nivel')
            ->get();

        $vehicleTypes = [];
        foreach ($records as $record) {
            $vehicleTypes[] = new VehicleType(
                id: (int) $record->id,
                codigo: (string) $record->codigo,
                nombre: (string) $record->nombre,
                nivel: (int) $record->nivel,
                pesoMaxTotalKg: (float) $record->peso_max_total_kg,
                ladoMaxCm: (float) $record->lado_max_cm,
                maxPaquetes: (int) $record->max_paquetes,
            );
        }

        return $vehicleTypes;
    }

    /**
     * @return list<FailureReason>
     */
    public function getFailureReasons(): array
    {
        $records = Capsule::table('motivos_fallo')
            ->orderBy('id')
            ->get();

        $reasons = [];
        foreach ($records as $record) {
            $reasons[] = new FailureReason(
                id: (int) $record->id,
                codigo: (string) $record->codigo,
                nombre: (string) $record->nombre,
            );
        }

        return $reasons;
    }
}