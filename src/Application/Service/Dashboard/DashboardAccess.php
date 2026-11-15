<?php

declare(strict_types=1);

namespace App\Application\Service\Dashboard;

use App\Application\Exception\InvalidCatalogReference;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\Out\Catalog\CatalogRepository;

/**
 * Decide qué sede ve cada rol (Demo 4, sección 1.5):
 * - OPERADOR: siempre su propia sede, tomada de la sesión; cualquier id_sede recibido se ignora.
 * - ADMINISTRADOR: todas las sedes, o la que elija si existe en el catálogo.
 */
final readonly class DashboardAccess
{
    public function __construct(private CatalogRepository $catalogs)
    {
    }

    /**
     * @return array{id: int, nombre: string}|null null significa «todas las sedes»
     *
     * @throws UserNotAllowed si el rol no puede ver el dashboard
     * @throws InvalidCatalogReference si el administrador pide una sede inexistente
     */
    public function sedeFor(AuthenticatedUser $user, ?int $requestedSedeId): ?array
    {
        if ($user->role === 'OPERADOR') {
            if ($user->idSede === null) {
                throw new UserNotAllowed('El operador no tiene una sede asignada');
            }

            return ['id' => $user->idSede, 'nombre' => $user->sedeNombre ?? $this->sedeName($user->idSede) ?? ''];
        }

        if ($user->role !== 'ADMINISTRADOR') {
            throw new UserNotAllowed('Solo el administrador y el operador pueden ver el dashboard');
        }

        if ($requestedSedeId === null) {
            return null;
        }

        $name = $this->sedeName($requestedSedeId);
        if ($name === null) {
            throw new InvalidCatalogReference('La sede indicada no existe');
        }

        return ['id' => $requestedSedeId, 'nombre' => $name];
    }

    private function sedeName(int $sedeId): ?string
    {
        foreach ($this->catalogs->getSedes() as $sede) {
            if ($sede->id === $sedeId) {
                return $sede->nombre;
            }
        }

        return null;
    }
}
