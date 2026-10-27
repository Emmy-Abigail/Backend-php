<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\Out\Route;

use App\Application\Exception\RouteUnavailable;
use App\Application\Port\Out\Route\RouteStop;
use App\Infrastructure\Adapter\Out\Http\HttpResponse;
use App\Infrastructure\Adapter\Out\Http\HttpTransport;
use App\Infrastructure\Adapter\Out\Route\HttpRouteOptimizerGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpRouteOptimizerGatewayTest extends TestCase
{
    /** @return list<RouteStop> */
    private function stops(): array
    {
        return [new RouteStop(5021, '150122'), new RouteStop(5022, '150104'), new RouteStop(5023, '150140')];
    }

    private function gateway(HttpTransport $transport, string $url = 'http://python:8000/', string $key = 'test-key', float $timeout = 3.0): HttpRouteOptimizerGateway
    {
        return new HttpRouteOptimizerGateway($transport, $url, $key, $timeout);
    }

    #[Test]
    public function envia_el_json_acordado_con_la_clave_interna_y_devuelve_el_orden(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with(
                'http://python:8000/api/v1/optimize-route',
                [
                    'id_sede' => 3,
                    'paradas' => [
                        ['id_paquete' => 5021, 'ubigeo_destino' => '150122'],
                        ['id_paquete' => 5022, 'ubigeo_destino' => '150104'],
                        ['id_paquete' => 5023, 'ubigeo_destino' => '150140'],
                    ],
                ],
                'test-key',
                3.0,
            )
            ->willReturn(new HttpResponse(200, '{"orden":[5023,5022,5021],"algoritmo":"greedy-nearest-neighbor"}'));

        self::assertSame([5023, 5022, 5021], $this->gateway($transport)->order(3, $this->stops()));
    }

    #[Test]
    public function envia_el_punto_del_mapa_solo_en_las_paradas_que_lo_tienen(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                [
                    'id_sede' => 3,
                    'paradas' => [
                        ['id_paquete' => 5021, 'ubigeo_destino' => '150122', 'lat' => -12.121234, 'lng' => -77.030987],
                        ['id_paquete' => 5022, 'ubigeo_destino' => '150104'],
                    ],
                ],
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(new HttpResponse(200, '{"orden":[5022,5021]}'));

        $stops = [new RouteStop(5021, '150122', -12.121234, -77.030987), new RouteStop(5022, '150104')];

        self::assertSame([5022, 5021], $this->gateway($transport)->order(3, $stops));
    }

    #[Test]
    public function tolera_campos_adicionales_en_la_respuesta(): void
    {
        $transport = $this->createStub(HttpTransport::class);
        $transport->method('post')->willReturn(new HttpResponse(200, '{"orden":[5021,5022,5023],"algoritmo":"otro","extra":true}'));

        self::assertSame([5021, 5022, 5023], $this->gateway($transport)->order(3, $this->stops()));
    }

    #[Test]
    public function reintenta_una_vez_si_python_responde_con_un_error_de_servidor(): void
    {
        $calls = 0;
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->exactly(2))->method('post')->willReturnCallback(
            static function () use (&$calls): HttpResponse {
                return ++$calls === 1
                    ? new HttpResponse(500, '{"error":"error_interno"}')
                    : new HttpResponse(200, '{"orden":[5023,5022,5021]}');
            },
        );

        self::assertSame([5023, 5022, 5021], $this->gateway($transport)->order(3, $this->stops()));
    }

    #[Test]
    public function reintenta_una_vez_si_falla_la_red(): void
    {
        $calls = 0;
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->exactly(2))->method('post')->willReturnCallback(
            static function () use (&$calls): HttpResponse {
                if (++$calls === 1) {
                    throw new RuntimeException('timeout');
                }

                return new HttpResponse(200, '{"orden":[5023,5022,5021]}');
            },
        );

        self::assertSame([5023, 5022, 5021], $this->gateway($transport)->order(3, $this->stops()));
    }

    #[Test]
    public function se_rinde_tras_dos_intentos_fallidos(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->exactly(2))->method('post')->willReturn(new HttpResponse(503, 'no disponible'));

        $this->expectException(RouteUnavailable::class);
        $this->expectExceptionMessage('Servicio de rutas no disponible');

        $this->gateway($transport)->order(3, $this->stops());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function clientErrors(): array
    {
        return [
            'clave incorrecta' => [401],
            'datos invalidos' => [422],
            'no encontrado' => [404],
            'redireccion' => [302],
        ];
    }

    #[Test]
    #[DataProvider('clientErrors')]
    public function no_reintenta_los_errores_que_no_son_de_servidor(int $status): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())->method('post')->willReturn(
            new HttpResponse($status, '{"error":"datos_invalidos","detalle":"mensaje privado de python"}'),
        );

        try {
            $this->gateway($transport)->order(3, $this->stops());
            self::fail('Se acepto una respuesta de error');
        } catch (RouteUnavailable $exception) {
            // El mensaje del servicio externo nunca llega al cliente.
            self::assertSame('Servicio de rutas no disponible', $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidBodies(): array
    {
        return [
            'no es json' => ['esto no es json'],
            'null' => ['null'],
            'objeto vacio' => ['{}'],
            'orden no es una lista' => ['{"orden":"5023"}'],
            'orden como objeto' => ['{"orden":{"a":5023,"b":5022,"c":5021}}'],
            'faltan paquetes' => ['{"orden":[5023,5022]}'],
            'sobra un paquete' => ['{"orden":[5023,5022,5021,5021]}'],
            'paquete repetido' => ['{"orden":[5023,5023,5021]}'],
            'paquete desconocido' => ['{"orden":[5023,5022,9999]}'],
            'ids como texto' => ['{"orden":["5023",5022,5021]}'],
            'ids decimales' => ['{"orden":[5023,5022,5021.0]}'],
            'lista vacia' => ['{"orden":[]}'],
        ];
    }

    #[Test]
    #[DataProvider('invalidBodies')]
    public function rechaza_respuestas_que_no_son_una_permutacion_de_los_paquetes_enviados(string $body): void
    {
        $transport = $this->createStub(HttpTransport::class);
        $transport->method('post')->willReturn(new HttpResponse(200, $body));

        $this->expectException(RouteUnavailable::class);

        $this->gateway($transport)->order(3, $this->stops());
    }

    #[Test]
    public function un_fallo_de_transporte_no_filtra_detalles_internos(): void
    {
        $transport = $this->createStub(HttpTransport::class);
        $transport->method('post')->willThrowException(new RuntimeException('http://interno:8000 X-API-Key: secreta'));

        try {
            $this->gateway($transport)->order(3, $this->stops());
            self::fail('Se acepto un fallo de transporte');
        } catch (RouteUnavailable $exception) {
            self::assertSame('Servicio de rutas no disponible', $exception->getMessage());
            self::assertStringNotContainsString('secreta', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /**
     * @return array<string, array{string, string, float}>
     */
    public static function invalidConfigurations(): array
    {
        return [
            'url vacia' => ['', 'test-key', 3.0],
            'esquema no http' => ['ftp://python:8000', 'test-key', 3.0],
            'sin host' => ['http://', 'test-key', 3.0],
            'url con usuario' => ['http://user:pass@python:8000', 'test-key', 3.0],
            'url con query' => ['http://python:8000?x=1', 'test-key', 3.0],
            'url con fragmento' => ['http://python:8000#x', 'test-key', 3.0],
            'clave vacia' => ['http://python:8000', '', 3.0],
            'clave en blanco' => ['http://python:8000', '   ', 3.0],
            'clave con salto de linea' => ['http://python:8000', "clave\r\nX-Otro: 1", 3.0],
            'timeout cero' => ['http://python:8000', 'test-key', 0.0],
            'timeout excesivo' => ['http://python:8000', 'test-key', 31.0],
            'timeout infinito' => ['http://python:8000', 'test-key', INF],
        ];
    }

    #[Test]
    #[DataProvider('invalidConfigurations')]
    public function una_configuracion_invalida_no_hace_ninguna_peticion(string $url, string $key, float $timeout): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->never())->method('post');

        $this->expectException(RouteUnavailable::class);

        $this->gateway($transport, $url, $key, $timeout)->order(3, $this->stops());
    }
}
