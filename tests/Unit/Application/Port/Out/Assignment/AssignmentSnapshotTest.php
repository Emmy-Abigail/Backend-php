<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Port\Out\Assignment;

use App\Application\Exception\AssignmentUnavailable;
use App\Application\Port\Out\Assignment\AssignmentSnapshot;
use App\Application\Port\Out\Assignment\BatchAssignment;
use App\Domain\Package\PackageDetails;
use PHPUnit\Framework\TestCase;
use Tests\Support\PackageFixture;

require_once __DIR__ . '/../../../../../Support/PackageFixture.php';

final class AssignmentSnapshotTest extends TestCase
{
    private const OLDER = ['id' => 101, 'id_tipo_vehiculo' => 1, 'cantidad_paquetes' => 2, 'peso_total_kg' => '5.00', 'created_at' => '2026-10-21T08:00:00+00:00'];
    private const NEWER = ['id' => 102, 'id_tipo_vehiculo' => 1, 'cantidad_paquetes' => 1, 'peso_total_kg' => '2.00', 'created_at' => '2026-10-21T09:00:00+00:00'];

    public function test_sends_the_map_point_when_the_package_has_one(): void
    {
        $body = PackageFixture::body();
        $body['direccion'] += ['lat' => -12.121667, 'lng' => -77.029167];
        $base = PackageFixture::snapshot();
        $snapshot = new AssignmentSnapshot($base->context, PackageDetails::fromArray($body), $base->vehicles, []);

        $data = $snapshot->toArray();
        self::assertSame(-12.121667, $data['destino_lat']);
        self::assertSame(-77.029167, $data['destino_lng']);
    }

    public function test_omits_the_point_when_the_package_has_none(): void
    {
        self::assertArrayNotHasKey('destino_lat', PackageFixture::snapshot()->toArray());
    }

    public function test_accepts_a_newer_batch_chosen_by_proximity(): void
    {
        PackageFixture::snapshot([self::OLDER, self::NEWER])->assertValid(new BatchAssignment('USAR_LOTE', 1, 102));
        $this->addToAssertionCount(1);
    }

    public function test_accepts_a_new_batch_even_if_others_have_capacity(): void
    {
        PackageFixture::snapshot([self::OLDER])->assertValid(new BatchAssignment('CREAR_LOTE', 1, null));
        $this->addToAssertionCount(1);
    }

    public function test_rejects_a_batch_without_capacity(): void
    {
        $full = ['cantidad_paquetes' => 20] + self::OLDER;
        $this->expectException(AssignmentUnavailable::class);
        PackageFixture::snapshot([$full])->assertValid(new BatchAssignment('USAR_LOTE', 1, 101));
    }

    public function test_rejects_an_unknown_batch(): void
    {
        $this->expectException(AssignmentUnavailable::class);
        PackageFixture::snapshot([self::OLDER])->assertValid(new BatchAssignment('USAR_LOTE', 1, 999));
    }
}
