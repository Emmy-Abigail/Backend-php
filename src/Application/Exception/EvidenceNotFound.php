<?php

declare(strict_types=1);

namespace App\Application\Exception;

final class EvidenceNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No hay evidencia disponible para este paquete');
    }
}
