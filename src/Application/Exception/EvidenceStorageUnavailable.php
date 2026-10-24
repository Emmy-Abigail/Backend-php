<?php

declare(strict_types=1);

namespace App\Application\Exception;

final class EvidenceStorageUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No se pudo guardar la evidencia; vuelve a intentarlo');
    }
}
