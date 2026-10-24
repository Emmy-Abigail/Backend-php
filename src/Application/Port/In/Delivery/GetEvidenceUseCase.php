<?php

declare(strict_types=1);

namespace App\Application\Port\In\Delivery;

use App\Application\Port\Out\Delivery\StoredEvidence;

interface GetEvidenceUseCase
{
    public function execute(GetEvidenceQuery $query): StoredEvidence;
}
