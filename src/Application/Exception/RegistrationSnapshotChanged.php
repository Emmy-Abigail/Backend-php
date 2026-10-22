<?php

declare(strict_types=1);

namespace App\Application\Exception;

/** Señal interna: actualizar la instantánea y reintentar, sin escrituras parciales. */
final class RegistrationSnapshotChanged extends \RuntimeException
{
}
