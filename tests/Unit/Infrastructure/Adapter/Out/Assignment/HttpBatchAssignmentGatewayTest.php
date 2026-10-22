<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\Out\Assignment;

use App\Application\Exception\AssignmentUnavailable;
use App\Infrastructure\Adapter\Out\Assignment\HttpBatchAssignmentGateway;
use App\Infrastructure\Adapter\Out\Http\HttpResponse;
use App\Infrastructure\Adapter\Out\Http\HttpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PackageFixture;

require_once __DIR__ . '/../../../../../Support/PackageFixture.php';

final class HttpBatchAssignmentGatewayTest extends TestCase
{
    public function test_sends_the_agreed_json_and_internal_key(): void
    {
        $snapshot = PackageFixture::snapshot();
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())->method('post')
            ->with('http://python:8000/api/v1/batch-assignments/evaluate', $snapshot->toArray(), 'test-key', 3.0)
            ->willReturn(new HttpResponse(200, '{"accion":"CREAR_LOTE","id_tipo_vehiculo":1,"id_lote":null}'));
        $result = (new HttpBatchAssignmentGateway($transport, 'http://python:8000/', 'test-key'))->evaluate($snapshot);
        self::assertSame('CREAR_LOTE', $result->action);
        self::assertNull($result->batchId);
    }

    public function test_accepts_a_compatible_existing_batch(): void
    {
        $snapshot = PackageFixture::snapshot([['id' => 101, 'id_tipo_vehiculo' => 1, 'cantidad_paquetes' => 19, 'peso_total_kg' => '17.00', 'created_at' => '2026-10-21T08:00:00+00:00']]);
        $transport = $this->createStub(HttpTransport::class);
        $transport->method('post')->willReturn(new HttpResponse(200, '{"accion":"USAR_LOTE","id_tipo_vehiculo":1,"id_lote":101}'));
        self::assertSame(101, (new HttpBatchAssignmentGateway($transport, 'http://python:8000', 'test-key'))->evaluate($snapshot)->batchId);
    }

    #[DataProvider('invalidResponses')]
    public function test_rejects_upstream_errors_or_invalid_recommendations(int $status, string $body): void
    {
        $transport = $this->createStub(HttpTransport::class);
        $transport->method('post')->willReturn(new HttpResponse($status, $body));
        try {
            (new HttpBatchAssignmentGateway($transport, 'http://python:8000', 'test-key'))->evaluate(PackageFixture::snapshot());
            self::fail('Invalid response was accepted');
        } catch (AssignmentUnavailable $exception) {
            self::assertSame('Servicio de asignacion no disponible', $exception->getMessage());
        }
    }

    public static function invalidResponses(): array
    {
        return [
            [401, '{"error":"datos_invalidos","detalle":"private upstream message"}'],
            [422, '{"error":"datos_invalidos","detalle":"private upstream message"}'],
            [500, '{"error":"error_interno","detalle":"No se pudo procesar la solicitud."}'],
            [302, 'redirect'],
            [200, 'not json'],
            [200, 'null'],
            [200, '{"accion":"CREAR_LOTE","id_tipo_vehiculo":"1","id_lote":null}'],
            [200, '{"accion":"CREAR_LOTE","id_tipo_vehiculo":1,"id_lote":101}'],
            [200, '{"accion":"USAR_LOTE","id_tipo_vehiculo":1,"id_lote":null}'],
            [200, '{"accion":"USAR_LOTE","id_tipo_vehiculo":1,"id_lote":999}'],
            [200, '{"accion":"CREAR_LOTE","id_tipo_vehiculo":2,"id_lote":null}'],
            [200, '{"accion":"CREAR_LOTE","id_tipo_vehiculo":1,"id_lote":null,"extra":1}'],
        ];
    }

    public function test_transport_failure_does_not_leak_internal_details(): void
    {
        $transport = $this->createStub(HttpTransport::class);
        $transport->method('post')->willThrowException(new \RuntimeException('secret-key and internal URL'));
        $this->expectExceptionMessage('Servicio de asignacion no disponible');
        (new HttpBatchAssignmentGateway($transport, 'http://python:8000', 'test-key'))->evaluate(PackageFixture::snapshot());
    }

    public function test_missing_configuration_does_not_make_a_request(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->never())->method('post');
        $this->expectException(AssignmentUnavailable::class);
        (new HttpBatchAssignmentGateway($transport, '', ''))->evaluate(PackageFixture::snapshot());
    }
}
