<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Persistence;

use App\Application\Port\In\Package\RegisterPackageCommand;
use App\Application\Port\In\Package\RegisterPackageResult;
use App\Application\Port\Out\Assignment\AssignmentSnapshot;
use App\Application\Port\Out\Assignment\BatchAssignment;
use App\Application\Port\Out\Package\RegistrationContext;
use App\Application\Port\Out\Pricing\PackageQuote;
use App\Domain\Package\PackageDetails;

interface PackageRegistrationRepository
{
    public function contextFor(int $sedeId, string $destinationUbigeo): RegistrationContext;

    public function snapshot(RegistrationContext $context, PackageDetails $details): AssignmentSnapshot;

    /** Revalida la instantánea y guarda de forma atómica, o revierte e indica que la instantánea está desactualizada. */
    public function register(
        RegisterPackageCommand $command,
        AssignmentSnapshot $snapshot,
        BatchAssignment $assignment,
        PackageQuote $quote,
        string $tracking,
    ): RegisterPackageResult;
}
