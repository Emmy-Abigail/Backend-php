<?php

declare(strict_types=1);

namespace App\Application\Port\In\Package;

interface RegisterPackageUseCase
{
    public function execute(RegisterPackageCommand $command): RegisterPackageResult;
}
