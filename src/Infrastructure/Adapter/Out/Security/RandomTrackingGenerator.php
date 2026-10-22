<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Security;

use App\Application\Port\Out\Security\TrackingGenerator;

final readonly class RandomTrackingGenerator implements TrackingGenerator
{
    public function generate(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $tracking = '';
        for ($index = 0; $index < 10; $index++) {
            $tracking .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $tracking;
    }
}
