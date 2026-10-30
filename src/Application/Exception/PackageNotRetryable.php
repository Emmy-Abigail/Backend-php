<?php

declare(strict_types=1);

namespace App\Application\Exception;

use RuntimeException;

/** El paquete no está en un estado que permita reintentar la entrega. */
final class PackageNotRetryable extends RuntimeException
{
}
