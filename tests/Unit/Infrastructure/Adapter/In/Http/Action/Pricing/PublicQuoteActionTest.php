<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\Pricing;

use App\Application\Port\In\Catalog\GetSedesUseCase;
use App\Application\Port\In\Pricing\QuoteShipmentCommand;
use App\Application\Port\In\Pricing\QuoteShipmentResult;
use App\Application\Port\In\Pricing\QuoteShipmentUseCase;
use App\Domain\Catalog\Sede;
use App\Domain\Pricing\Quote;
use App\Infrastructure\Adapter\In\Http\Action\Pricing\PublicQuoteAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PublicQuoteActionTest extends TestCase
{
    /** @return array<string, mixed> */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'id_sede' => 2,
            'ubigeo_destino' => '150140',
            'peso_kg' => 25,
            'largo_cm' => 12,
            'ancho_cm' => 30,
            'alto_cm' => 20,
        ], $overrides);
    }

    private function sedes(): GetSedesUseCase
    {
        $useCase = $this->createStub(GetSedesUseCase::class);
        $useCase->method('execute')->willReturn([
            new Sede(1, 'Sede Norte', 1, 'NORTE', 'Norte', '150117', 'Los Olivos', 'Av. Uno', -11.99, -77.07, true),
            new Sede(2, 'Sede Centro', 2, 'CENTRO', 'Centro', '150113', 'Jesús María', 'Av. Dos', -12.07, -77.04, true),
            new Sede(3, 'Sede Cerrada', 3, 'SUR', 'Sur', '150140', 'Surco', 'Av. Tres', -12.14, -77.00, false),
        ]);

        return $useCase;
    }

    private function quoteResult(): QuoteShipmentResult
    {
        return new QuoteShipmentResult(new Quote(144, 2500, 15, 250, 9375, 1, 3), 2, 'Sede Centro', 'CENTRO', 'SUR');
    }

    private function call(QuoteShipmentUseCase $quote, mixed $body): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/public/quotes');
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return (new PublicQuoteAction($quote, $this->sedes()))($request, (new ResponseFactory())->createResponse());
    }

    private function neverRuns(): QuoteShipmentUseCase
    {
        $useCase = $this->createMock(QuoteShipmentUseCase::class);
        $useCase->expects($this->never())->method('execute');

        return $useCase;
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    #[Test]
    public function cotiza_sin_sesion_desde_la_sede_elegida_y_responde_igual_que_la_cotizacion_interna(): void
    {
        $useCase = $this->createMock(QuoteShipmentUseCase::class);
        $useCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(
                fn (QuoteShipmentCommand $command) => $command->originSedeId === 2 && $command->destinationUbigeo === '150140',
            ))
            ->willReturn($this->quoteResult());

        $response = $this->call($useCase, $this->body());

        self::assertSame(200, $response->getStatusCode());
        $payload = $this->decode($response);
        self::assertSame(93.75, $payload['monto']);
        self::assertSame('CENTRO', $payload['zona_origen']);
        self::assertSame('SUR', $payload['zona_destino']);
        self::assertSame(['id' => 2, 'nombre' => 'Sede Centro'], $payload['sede_origen']);
    }

    #[Test]
    public function acepta_el_id_de_la_sede_como_texto_numerico(): void
    {
        $useCase = $this->createStub(QuoteShipmentUseCase::class);
        $useCase->method('execute')->willReturn($this->quoteResult());

        self::assertSame(200, $this->call($useCase, $this->body(['id_sede' => '2']))->getStatusCode());
    }

    #[Test]
    public function sin_id_sede_responde_422_campo_obligatorio(): void
    {
        $body = $this->body();
        unset($body['id_sede']);

        $response = $this->call($this->neverRuns(), $body);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('campo_obligatorio', $this->decode($response)['error']);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidSedeIds(): array
    {
        return [
            'texto' => ['abc'],
            'cero' => [0],
            'negativo' => [-1],
            'decimal' => [1.5],
            'booleano' => [true],
            'lista' => [[1]],
            'texto con inyeccion' => ['1; DROP TABLE sedes'],
            'cero a la izquierda' => ['02'],
        ];
    }

    #[Test]
    #[DataProvider('invalidSedeIds')]
    public function un_id_sede_mal_formado_responde_422_campo_invalido(mixed $sedeId): void
    {
        $response = $this->call($this->neverRuns(), $this->body(['id_sede' => $sedeId]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('campo_invalido', $this->decode($response)['error']);
    }

    /** @return array<string, array{int}> */
    public static function unavailableSedes(): array
    {
        return ['sede inexistente' => [99], 'sede inactiva' => [3]];
    }

    #[Test]
    #[DataProvider('unavailableSedes')]
    public function una_sede_inexistente_o_inactiva_responde_422_referencia_invalida(int $sedeId): void
    {
        $response = $this->call($this->neverRuns(), $this->body(['id_sede' => $sedeId]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('referencia_invalida', $this->decode($response)['error']);
    }

    #[Test]
    public function un_cuerpo_que_no_es_json_responde_400(): void
    {
        $response = $this->call($this->neverRuns(), null);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('cuerpo_invalido', $this->decode($response)['error']);
    }

    #[Test]
    public function conserva_las_mismas_validaciones_de_medidas_que_la_cotizacion_interna(): void
    {
        $body = $this->body();
        unset($body['peso_kg']);

        $response = $this->call($this->neverRuns(), $body);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('campo_obligatorio', $this->decode($response)['error']);
    }
}
