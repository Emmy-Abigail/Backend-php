<?php

declare(strict_types=1);

namespace App\Application\Port\Out\Assignment;

interface BatchAssignmentGateway
{
    public function evaluate(AssignmentSnapshot $snapshot): BatchAssignment;
}
