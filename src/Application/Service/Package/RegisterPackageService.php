<?php

declare(strict_types=1);

namespace App\Application\Service\Package;

use App\Application\Exception\PackageRegistrationConflict;
use App\Application\Exception\RegistrationSnapshotChanged;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Package\RegisterPackageCommand;
use App\Application\Port\In\Package\RegisterPackageResult;
use App\Application\Port\In\Package\RegisterPackageUseCase;
use App\Application\Port\Out\Assignment\BatchAssignmentGateway;
use App\Application\Port\Out\Notification\TrackingCodeMailer;
use App\Application\Port\Out\Persistence\PackageRegistrationRepository;
use App\Application\Port\Out\Pricing\PackagePricing;
use App\Application\Port\Out\Security\TrackingGenerator;
use Throwable;

final readonly class RegisterPackageService implements RegisterPackageUseCase
{
    public function __construct(
        private PackageRegistrationRepository $repository,
        private PackagePricing $pricing,
        private BatchAssignmentGateway $assignment,
        private TrackingGenerator $tracking,
        private ?TrackingCodeMailer $mailer = null,
    ) {
    }

    public function execute(RegisterPackageCommand $command): RegisterPackageResult
    {
        if ($command->operator->role !== 'OPERADOR' || $command->operator->idSede === null || $command->operator->mustChangePassword) {
            throw new UserNotAllowed('Solo un operador habilitado con sede puede registrar paquetes');
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $context = $this->repository->contextFor($command->operator->idSede, $command->details->address['ubigeo_distrito']);

            // No abrir transacciones, llamar a Python ni guardar datos antes de disponer de una cotización real.
            $quote = $this->pricing->quote($command->details, $context);
            $snapshot = $this->repository->snapshot($context, $command->details);
            $assignment = $this->assignment->evaluate($snapshot);
            $snapshot->assertValid($assignment);

            try {
                $result = $this->repository->register($command, $snapshot, $assignment, $quote, $this->tracking->generate());
            } catch (RegistrationSnapshotChanged) {
                // Otro registro o despacho cambió el grupo, o hubo una colisión del código de seguimiento.
                // El repositorio revirtió la transacción; consultar los datos actuales y volver a pedir la asignación a Python.
                continue;
            }

            return $result->withEmailsSent($this->sendTrackingCodes($command, $result->tracking));
        }

        throw new PackageRegistrationConflict('La asignacion cambio durante el registro; vuelve a intentarlo');
    }

    /**
     * Se envía después de guardar: el paquete ya existe, así que un fallo del correo no revierte el registro.
     *
     * @return array{remitente: ?bool, destinatario: ?bool}
     */
    private function sendTrackingCodes(RegisterPackageCommand $command, string $tracking): array
    {
        $sent = ['remitente' => null, 'destinatario' => null];
        if ($this->mailer === null) {
            return $sent;
        }

        $people = ['remitente' => $command->details->sender, 'destinatario' => $command->details->recipient];
        foreach ($people as $role => $person) {
            if (($person['correo'] ?? null) === null) {
                continue;
            }
            try {
                $this->mailer->sendTrackingCode((string) $person['nombres'], $person['correo'], $tracking, $role === 'remitente');
                $sent[$role] = true;
            } catch (Throwable) {
                // Sin datos personales en el log.
                error_log("No se pudo enviar el codigo de seguimiento al {$role}");
                $sent[$role] = false;
            }
        }

        return $sent;
    }
}
