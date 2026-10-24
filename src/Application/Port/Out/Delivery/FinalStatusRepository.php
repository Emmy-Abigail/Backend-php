<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Delivery;

use App\Application\Port\In\Delivery\FinalStatusCommand;
use App\Application\Port\In\Delivery\FinalStatusResult;

interface FinalStatusRepository
{
    /** Prevalidar antes de guardar archivos; finalize vuelve a validar bajo bloqueo. */
    public function assertCanFinalize(FinalStatusCommand $command): void;

    public function finalize(FinalStatusCommand $command, ?string $evidenceKey): FinalStatusResult;

    /** Ante un commit ambiguo no borrar una evidencia que pudo quedar referenciada. */
    public function isEvidenceReferenced(string $key): bool;
}
