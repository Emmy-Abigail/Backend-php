<?php

declare(strict_types=1);

namespace App\Application\Port\In\Package;

interface RetryPackageUseCase
{
    public function execute(RetryPackageCommand $command): RegisterPackageResult;
}
