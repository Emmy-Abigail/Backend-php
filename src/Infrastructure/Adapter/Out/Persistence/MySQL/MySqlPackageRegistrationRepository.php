<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Persistence\MySQL;

use App\Application\Exception\InvalidCatalogReference;
use App\Application\Exception\PackageAlreadyRetried;
use App\Application\Exception\PackageRegistrationConflict;
use App\Application\Exception\PricingUnavailable;
use App\Application\Exception\RegistrationSnapshotChanged;
use App\Application\Port\In\Package\RegisterPackageCommand;
use App\Application\Port\In\Package\RegisterPackageResult;
use App\Application\Port\Out\Assignment\AssignmentSnapshot;
use App\Application\Port\Out\Assignment\BatchAssignment;
use App\Application\Port\Out\Package\RegistrationContext;
use App\Application\Port\Out\Persistence\PackageRegistrationRepository;
use App\Application\Port\Out\Pricing\PackageQuote;
use App\Domain\Package\DecimalQuantity;
use App\Domain\Package\PackageDetails;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use RuntimeException;

final readonly class MySqlPackageRegistrationRepository implements PackageRegistrationRepository
{
    public function __construct(private Connection $connection, private int $lockTimeout = 5)
    {
    }

    public function contextFor(int $sedeId, string $destinationUbigeo): RegistrationContext
    {
        return $this->loadContext($sedeId, $destinationUbigeo, false);
    }

    private function loadContext(int $sedeId, string $destinationUbigeo, bool $lock): RegistrationContext
    {
        $sedeQuery = $this->connection->table('sedes')->where('id', $sedeId)->where('activa', true);
        $districtQuery = $this->connection->table('distritos')->where('ubigeo', $destinationUbigeo)->where('activo', true);
        if ($lock) {
            $sedeQuery->lockForUpdate();
            $districtQuery->lockForUpdate();
        }
        $sede = $sedeQuery->first();
        $district = $districtQuery->first();
        if ($sede === null || $district === null) {
            throw new InvalidCatalogReference('La sede o el distrito no existe o esta inactivo');
        }
        $zones = $this->connection->table('zonas')->pluck('codigo', 'id');

        return new RegistrationContext(
            (int) $sede->id,
            (int) $sede->id_zona,
            $zones[$sede->id_zona],
            $district->ubigeo,
            (int) $district->id_zona,
            $zones[$district->id_zona],
        );
    }

    public function snapshot(RegistrationContext $context, PackageDetails $details): AssignmentSnapshot
    {
        return $this->readSnapshot($context, $details, false);
    }

    private function readSnapshot(RegistrationContext $context, PackageDetails $details, bool $lock): AssignmentSnapshot
    {
        $vehicleQuery = $this->connection->table('tipos_vehiculo')->orderBy('nivel')->orderBy('id');
        $batchQuery = $this->connection->table('lotes')
            ->where('id_sede', $context->sedeId)
            ->where('id_zona_destino', $context->destinationZoneId)
            ->where('estado', 'EN_PREPARACION')
            ->orderBy('created_at')->orderBy('id');
        if ($lock) {
            $vehicleQuery->lockForUpdate();
            $batchQuery->lockForUpdate();
        }
        $vehicles = [];
        foreach ($vehicleQuery->get() as $vehicle) {
            $vehicles[] = [
                'id' => (int) $vehicle->id,
                'nivel' => (int) $vehicle->nivel,
                'peso_max_total_kg' => (string) $vehicle->peso_max_total_kg,
                'lado_max_cm' => (string) $vehicle->lado_max_cm,
                'max_paquetes' => (int) $vehicle->max_paquetes,
            ];
        }
        $rows = $batchQuery->get();
        $packageQuery = $this->connection->table('paquetes')->whereIn('id_lote', $rows->pluck('id')->all())->orderBy('id');
        if ($lock) {
            $packageQuery->lockForUpdate();
        }
        $totals = [];
        foreach ($packageQuery->get(['id_lote', 'peso_kg']) as $package) {
            $id = (int) $package->id_lote;
            $totals[$id] ??= ['count' => 0, 'weight' => 0];
            $totals[$id]['count']++;
            $totals[$id]['weight'] += DecimalQuantity::units($package->peso_kg, 2);
        }
        $batches = [];
        foreach ($rows as $batch) {
            $total = $totals[(int) $batch->id] ?? ['count' => 0, 'weight' => 0];
            $batches[] = [
                'id' => (int) $batch->id,
                'id_tipo_vehiculo' => (int) $batch->id_tipo_vehiculo,
                'cantidad_paquetes' => $total['count'],
                'peso_total_kg' => DecimalQuantity::format($total['weight'], 2),
                'created_at' => (new DateTimeImmutable($batch->created_at, new DateTimeZone('UTC')))->format(DATE_ATOM),
            ];
        }

        return new AssignmentSnapshot($context, $details, $vehicles, $batches);
    }

    public function register(
        RegisterPackageCommand $command,
        AssignmentSnapshot $snapshot,
        BatchAssignment $assignment,
        PackageQuote $quote,
        string $tracking,
    ): RegisterPackageResult {
        $snapshot->assertValid($assignment);
        // El bloqueo del grupo también protege el caso en el que todavía no existe un lote.
        // Solo se mantiene durante el guardado; Python y la cotización se ejecutan antes.
        $context = $snapshot->context;
        $lockName = 'riva:register:' . sha1($this->connection->getDatabaseName() . ':' . $context->sedeId . ':' . $context->destinationZoneId);
        $lock = $this->connection->selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$lockName, $this->lockTimeout]);
        if ((int) $lock->acquired !== 1) {
            throw new PackageRegistrationConflict('Hay otro registro en proceso; vuelve a intentarlo');
        }

        try {
            return $this->connection->transaction(function () use ($command, $snapshot, $assignment, $quote, $tracking, $context): RegisterPackageResult {
                $currentContext = $this->loadContext($context->sedeId, $context->destinationUbigeo, true);
                $current = $this->readSnapshot($currentContext, $command->details, true);
                if ($currentContext != $context || $current->toArray() !== $snapshot->toArray()) {
                    throw new RegistrationSnapshotChanged();
                }
                $current->assertValid($assignment);
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $timestamp = $now->format('Y-m-d H:i:s');
                $tariff = $this->connection->table('tarifas')->where('id', $quote->tariffId)
                    ->where('vigente_desde', '<=', $timestamp)->lockForUpdate()->first();
                if ($tariff === null) {
                    throw new PricingUnavailable('La tarifa de la cotizacion no esta disponible');
                }
                $stateId = $this->connection->table('estados_paquete')->where('codigo', 'EN_ALMACEN')->value('id');
                if ($stateId === null) {
                    throw new RuntimeException('Falta el estado inicial del paquete');
                }

                $sender = $command->details->sender;
                $this->connection->table('clientes')->upsert([[
                    'tipo_documento' => $sender['tipo_documento'],
                    'numero_documento' => $sender['numero_documento'],
                    'nombres_razon_social' => $sender['nombres'],
                    'celular' => $sender['celular'],
                ]], ['tipo_documento', 'numero_documento'], ['nombres_razon_social', 'celular']);
                $senderId = $this->connection->table('clientes')->where('tipo_documento', $sender['tipo_documento'])
                    ->where('numero_documento', $sender['numero_documento'])->value('id');

                if ($assignment->action === 'CREAR_LOTE') {
                    $batchCode = 'L-' . strtoupper(bin2hex(random_bytes(7)));
                    $batchId = $this->connection->table('lotes')->insertGetId([
                        'codigo' => $batchCode,
                        'id_sede' => $context->sedeId,
                        'id_zona_destino' => $context->destinationZoneId,
                        'id_tipo_vehiculo' => $assignment->vehicleId,
                        'id_conductor' => null,
                        'creado_por' => $command->operator->id,
                        'estado' => 'EN_PREPARACION',
                        'version' => 0,
                        'created_at' => $timestamp,
                    ]);
                } else {
                    $batchId = $assignment->batchId;
                    $batchCode = $this->connection->table('lotes')->where('id', $batchId)->value('codigo');
                }

                $recipient = $command->details->recipient;
                $address = $command->details->address;
                $id = $this->connection->table('paquetes')->insertGetId(array_merge($command->details->measurements(), [
                    'tracking_id' => $tracking,
                    'id_paquete_origen' => $command->originPackageId,
                    'id_sede' => $context->sedeId,
                    'id_remitente' => $senderId,
                    'destinatario_nombres' => $recipient['nombres'],
                    'destinatario_dni' => $recipient['dni'],
                    'destinatario_celular' => $recipient['celular'],
                    'ubigeo_destino' => $context->destinationUbigeo,
                    'tipo_via' => $address['tipo_via'],
                    'nombre_via' => $address['nombre_via'],
                    'numero' => $address['numero'],
                    'manzana' => $address['manzana'],
                    'lote_predio' => $address['lote'],
                    'referencia' => $address['referencia'],
                    'costo_estimado' => $quote->amount,
                    'id_tarifa' => $quote->tariffId,
                    'estado_actual_id' => $stateId,
                    'id_lote' => $batchId,
                    'orden_parada' => null,
                    'fecha_ingreso' => $timestamp,
                    'creado_por' => $command->operator->id,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]));
                $this->connection->table('historial_estados')->insert([
                    'id_paquete' => $id,
                    'estado_anterior_id' => null,
                    'estado_nuevo_id' => $stateId,
                    'id_usuario_ejecutor' => $command->operator->id,
                    'created_at' => $timestamp,
                ]);
                $vehicleCode = $this->connection->table('tipos_vehiculo')->where('id', $assignment->vehicleId)->value('codigo');

                return new RegisterPackageResult((int) $id, $tracking, $now->format(DATE_ATOM), $quote, [
                    'id' => (int) $batchId,
                    'codigo' => $batchCode,
                    'estado' => 'EN_PREPARACION',
                    'id_tipo_vehiculo' => $assignment->vehicleId,
                    'vehiculo' => $vehicleCode,
                    'zona' => $context->destinationZoneCode,
                ]);
            });
        } catch (QueryException $exception) {
            $mysqlCode = (int) ($exception->errorInfo[1] ?? 0);
            // uq_paquetes_reintento: otro operador ya creó el reintento de este paquete al mismo tiempo.
            if ($mysqlCode === 1062 && str_contains($exception->getMessage(), 'uq_paquetes_reintento')) {
                throw new PackageAlreadyRetried('El paquete ya tiene un reintento');
            }
            if (in_array($mysqlCode, [1205, 1213], true)
                || ($mysqlCode === 1062 && (str_contains($exception->getMessage(), 'uq_paquetes_tracking')
                    || str_contains($exception->getMessage(), 'uq_lotes_codigo')))) {
                throw new RegistrationSnapshotChanged();
            }
            throw $exception;
        } finally {
            $this->connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }
}
