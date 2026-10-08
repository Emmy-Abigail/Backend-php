<?php

declare(strict_types=1);

namespace App\Application\Service\User;

use App\Application\Exception\DniAlreadyExists;
use App\Application\Exception\EmailAlreadyExists;
use App\Application\Exception\InvalidCatalogReference;
use App\Application\Port\In\User\CreateUserCommand;
use App\Application\Port\In\User\CreateUserResult;
use App\Application\Port\In\User\CreateUserUseCase;
use App\Application\Port\Out\Catalog\CatalogRepository;
use App\Application\Port\Out\Notification\UserCredentialsMailer;
use App\Application\Port\Out\Persistence\UserRepository;
use App\Domain\User\PasswordPolicy;
use RuntimeException;
use Throwable;

final readonly class CreateUserService implements CreateUserUseCase
{
    public function __construct(
        private UserRepository $userRepository,
        private CatalogRepository $catalogRepository,
        private UserCredentialsMailer $userCredentialsMailer,
    ) {
    }

    public function execute(CreateUserCommand $command): CreateUserResult
    {
        if ($this->userRepository->existsByEmail($command->email)) {
            throw new EmailAlreadyExists('Ya existe un usuario con ese correo');
        }

        if ($this->userRepository->existsByDni($command->dni)) {
            throw new DniAlreadyExists('Ya existe un usuario con ese DNI');
        }

        if ($command->role === 'OPERADOR') {
            $sedeIds = array_map(
                static fn ($sede) => $sede->id,
                $this->catalogRepository->getSedes(),
            );

            if (!in_array($command->idSede, $sedeIds, true)) {
                throw new InvalidCatalogReference('La sede indicada no existe');
            }
        }

        if ($command->role === 'CONDUCTOR') {
            $vehicleTypeIds = array_map(
                static fn ($vehicleType) => $vehicleType->id,
                $this->catalogRepository->getVehicleTypes(),
            );

            if (!in_array($command->idTipoVehiculo, $vehicleTypeIds, true)) {
                throw new InvalidCatalogReference('El tipo de vehículo indicado no existe');
            }
        }

        $temporaryPassword = PasswordPolicy::generateTemporaryPassword(12);
        $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);

        if ($passwordHash === false) {
            throw new RuntimeException('No fue posible generar el hash de la contraseña temporal');
        }

        $user = $this->userRepository->create(
            $command->names,
            $command->dni,
            $command->email,
            $command->phone,
            $passwordHash,
            $command->role,
            $command->idSede,
            $command->idTipoVehiculo,
        );

        $emailSent = true;
        try {
            $this->userCredentialsMailer->sendTemporaryPassword(
                $user->names,
                $user->email,
                $temporaryPassword,
            );
        } catch (Throwable $exception) {
            error_log('Error al enviar correo con credenciales temporales: ' . $exception->getMessage());
            $emailSent = false;
        }

        return new CreateUserResult(
            $user->id,
            $user->names,
            $user->dni,
            $user->email,
            $user->role,
            $user->idSede,
            $user->idTipoVehiculo,
            $temporaryPassword,
            $emailSent,
        );
    }
}