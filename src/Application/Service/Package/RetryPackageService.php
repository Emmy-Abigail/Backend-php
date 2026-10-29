<?php

declare(strict_types=1);

namespace App\Application\Service\Package;

use App\Application\Exception\PackageAccessDenied;
use App\Application\Exception\PackageAlreadyRetried;
use App\Application\Exception\PackageNotFound;
use App\Application\Exception\PackageNotRetryable;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Package\RegisterPackageCommand;
use App\Application\Port\In\Package\RegisterPackageResult;
use App\Application\Port\In\Package\RegisterPackageUseCase;
use App\Application\Port\In\Package\RetryPackageCommand;
use App\Application\Port\In\Package\RetryPackageUseCase;
use App\Application\Port\Out\Persistence\PackageRetrySourceRepository;
use App\Domain\Package\PackageDetails;

/**
 * Crea el reintento de un paquete FALLIDO como un paquete nuevo (uno por paquete; si el reintento falla, se puede reintentar).
 * Reutiliza el registro completo: precio con la tarifa vigente, asignación de Python, lote y tracking nuevos.
 */
final readonly class RetryPackageService implements RetryPackageUseCase
{
    public function __construct(
        private PackageRetrySourceRepository $sources,
        private RegisterPackageUseCase $register,
    ) {
    }

    public function execute(RetryPackageCommand $command): RegisterPackageResult
    {
        $operator = $command->operator;
        if ($operator->role !== 'OPERADOR' || $operator->idSede === null || $operator->mustChangePassword) {
            throw new UserNotAllowed('Solo un operador habilitado con sede puede reintentar paquetes');
        }

        $source = $this->sources->findRetrySource($command->packageId);
        if ($source === null) {
            throw new PackageNotFound('El paquete no existe');
        }
        if ($source->sedeId !== $operator->idSede) {
            throw new PackageAccessDenied('No puede reintentar paquetes de otra sede');
        }
        if ($source->status !== 'FALLIDO') {
            throw new PackageNotRetryable('Solo se puede reintentar un paquete FALLIDO');
        }
        // Un reintento que también falla se puede reintentar; cada paquete admite un solo reintento propio.
        if ($source->alreadyRetried) {
            throw new PackageAlreadyRetried('El paquete ya tiene un reintento');
        }

        // PackageDetails valida otra vez todo, incluidas las correcciones.
        $details = PackageDetails::fromArray($this->applyCorrections($source->registrationInput, $command->corrections));

        return $this->register->execute(new RegisterPackageCommand($operator, $details, $command->packageId));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $corrections
     * @return array<string, mixed>
     */
    private function applyCorrections(array $input, array $corrections): array
    {
        if (isset($corrections['direccion']) && is_array($corrections['direccion'])) {
            $input['direccion'] = array_replace($input['direccion'], $corrections['direccion']);
        }
        if (isset($corrections['destinatario']) && is_array($corrections['destinatario'])
            && array_key_exists('celular', $corrections['destinatario'])) {
            $input['destinatario']['celular'] = $corrections['destinatario']['celular'];
        }

        return $input;
    }
}
