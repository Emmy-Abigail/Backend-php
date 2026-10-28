<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Tracking;

use App\Application\Exception\PackageNotFound;
use App\Application\Port\Out\Persistence\PackageQueryRepository;
use App\Application\Service\Tracking\GetTrackingService;
use PHPUnit\Framework\TestCase;

final class GetTrackingServiceTest extends TestCase
{
    public function test_devuelve_la_vista_publica_del_repositorio(): void
    {
        $tracking = ['tracking_id' => 'AB23456789', 'linea_de_tiempo' => []];
        $repository = $this->createMock(PackageQueryRepository::class);
        $repository->expects($this->once())->method('findPublicTracking')->with('AB23456789')->willReturn($tracking);

        self::assertSame($tracking, (new GetTrackingService($repository))->execute('AB23456789')->toArray());
    }

    public function test_codigo_inexistente(): void
    {
        $repository = $this->createStub(PackageQueryRepository::class);
        $repository->method('findPublicTracking')->willReturn(null);

        $this->expectException(PackageNotFound::class);
        (new GetTrackingService($repository))->execute('ZZZZZZZZZZ');
    }
}
