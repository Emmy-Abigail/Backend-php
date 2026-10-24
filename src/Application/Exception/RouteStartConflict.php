<?php

declare(strict_types=1);

namespace App\Application\Exception;

use RuntimeException;

final class RouteStartConflict extends RuntimeException
{
    public function __construct(string $message, public readonly string $error = 'inicio_ruta_en_conflicto')
    {
        parent::__construct($message);
    }
}
