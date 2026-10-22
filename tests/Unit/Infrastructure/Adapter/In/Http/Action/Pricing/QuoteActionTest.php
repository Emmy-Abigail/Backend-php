<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\Pricing;

use App\Application\Exception\InvalidCatalogReference;
use App\Application\Exception\TariffNotConfigured;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Pricing\QuoteShipmentCommand;
use App\Application\Port\In\Pricing\QuoteShipmentResult;
use App\Application\Port\In\Pricing\QuoteShipmentUseCase;
use App\Domain\Pricing\InvalidPricingInput;
use App\Domain\Pricing\Quote;
use App\Infrastructure\Adapter\In\Http\Action\Pricing\QuoteAction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class QuoteActionTest extends TestCase
{
    private function operator(?int $sedeId = 1): AuthenticatedUser
    {
        return new AuthenticatedUser(5, 'Ana Torres', 'ana@email.com', 'OPERADOR', false, $sedeId, 'Sede Norte');
    }

    private function request(mixed $body, ?AuthenticatedUser $user = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/quotes');
        $request = $user === null ? $request : $request->withAttribute('authUser', $user);

        return $body === null ? $request : $request->withParsedBody($body);
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'ubigeo_destino' => '150140',
            'peso_kg' => 25,
            'largo_cm' => 12,
            'ancho_cm' => 30,
            'alto_cm' => 20,
        ], $overrides);
    }

    private function mariaResult(): QuoteShipmentResult
    {
        return new QuoteShipmentResult(new Quote(144, 2500, 15, 250, 9375, 1, 3), 1, 'Sede Norte', 'NORTE', 'SUR');
    }

    private function useCaseThatNeverRuns(): QuoteShipmentUseCase
    {
        $useCase = $this->createMock(QuoteShipmentUseCase::class);
        $useCase->expects($this->never())->method('execute');

        return $useCase;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function responde_la_cotizacion_con_la_sede_de_la_sesion(): void
    {
        $useCase = $this->createMock(QuoteShipmentUseCase::class);
        $useCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(static fn (QuoteShipmentCommand $command): bool => $command->originSedeId === 1
                && $command->destinationUbigeo === '150140'
                && $command->weightKg === '25'
                && $command->lengthCm === '12'
                && $command->widthCm === '30'
                && $command->heightCm === '20'))
            ->willReturn($this->mariaResult());

        $response = (new QuoteAction($useCase))($this->request($this->body(), $this->operator()), $this->response());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        self::assertSame([
            'peso_volumetrico_kg' => 1.44,
            'peso_facturable_kg' => 25.0,
            'zona_origen' => 'NORTE',
            'zona_destino' => 'SUR',
            'factor' => 1.5,
            'tarifa_base_kg' => 2.5,
            'monto' => 93.75,
            'sede_origen' => ['id' => 1, 'nombre' => 'Sede Norte'],
        ], $this->decode($response));
    }

    #[Test]
    public function toma_la_sede_de_origen_del_usuario_autenticado_y_no_del_cuerpo(): void
    {
        $useCase = $this->createMock(QuoteShipmentUseCase::class);
        $useCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(static fn (QuoteShipmentCommand $command): bool => $command->originSedeId === 4))
            ->willReturn($this->mariaResult());

        $body = $this->body(['id_sede' => 1, 'sede' => 1]);

        $response = (new QuoteAction($useCase))($this->request($body, $this->operator(4)), $this->response());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function convierte_los_numeros_del_json_a_texto_decimal(): void
    {
        $useCase = $this->createMock(QuoteShipmentUseCase::class);
        $useCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(static fn (QuoteShipmentCommand $command): bool => $command->weightKg === '2.5'
                && $command->lengthCm === '12.5'
                && $command->widthCm === '30'
                && $command->heightCm === '20'))
            ->willReturn($this->mariaResult());

        $body = $this->body(['peso_kg' => 2.5, 'largo_cm' => '12.5', 'ancho_cm' => 30.0, 'alto_cm' => ' 20 ']);

        $response = (new QuoteAction($useCase))($this->request($body, $this->operator()), $this->response());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function mantiene_el_decimal_exacto_de_un_float(): void
    {
        $useCase = $this->createMock(QuoteShipmentUseCase::class);
        $useCase->expects($this->once())
            ->method('execute')
            ->with($this->callback(static fn (QuoteShipmentCommand $command): bool => $command->weightKg === '0.1'))
            ->willReturn($this->mariaResult());

        $response = (new QuoteAction($useCase))(
            $this->request($this->body(['peso_kg' => 0.1]), $this->operator()),
            $this->response(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredFields(): array
    {
        return [
            'ubigeo_destino' => ['ubigeo_destino'],
            'peso_kg' => ['peso_kg'],
            'largo_cm' => ['largo_cm'],
            'ancho_cm' => ['ancho_cm'],
            'alto_cm' => ['alto_cm'],
        ];
    }

    #[Test]
    #[DataProvider('requiredFields')]
    public function exige_todos_los_campos(string $field): void
    {
        $body = $this->body();
        unset($body[$field]);

        $response = (new QuoteAction($this->useCaseThatNeverRuns()))($this->request($body, $this->operator()), $this->response());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('campo_obligatorio', $this->decode($response)['error']);
        self::assertStringContainsString($field, $this->decode($response)['message']);
    }

    #[Test]
    #[DataProvider('requiredFields')]
    public function trata_un_campo_nulo_como_faltante(string $field): void
    {
        $response = (new QuoteAction($this->useCaseThatNeverRuns()))(
            $this->request($this->body([$field => null]), $this->operator()),
            $this->response(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('campo_obligatorio', $this->decode($response)['error']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidUbigeos(): array
    {
        return [
            'cinco dígitos' => ['15014'],
            'siete dígitos' => ['1501400'],
            'con letras' => ['15014a'],
            'número en vez de texto' => [150140],
            'dígitos de ancho completo' => ['１５０１４０'],
            'arreglo' => [['150140']],
        ];
    }

    #[Test]
    #[DataProvider('invalidUbigeos')]
    public function rechaza_un_ubigeo_invalido(mixed $ubigeo): void
    {
        $response = (new QuoteAction($this->useCaseThatNeverRuns()))(
            $this->request($this->body(['ubigeo_destino' => $ubigeo]), $this->operator()),
            $this->response(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('campo_invalido', $this->decode($response)['error']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidNumbers(): array
    {
        return [
            'booleano' => [true],
            'arreglo' => [[1]],
            'objeto' => [['a' => 1]],
        ];
    }

    #[Test]
    #[DataProvider('invalidNumbers')]
    public function rechaza_valores_que_no_son_numeros(mixed $value): void
    {
        $response = (new QuoteAction($this->useCaseThatNeverRuns()))(
            $this->request($this->body(['peso_kg' => $value]), $this->operator()),
            $this->response(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('campo_invalido', $this->decode($response)['error']);
        self::assertStringContainsString('peso_kg', $this->decode($response)['message']);
    }

    #[Test]
    public function rechaza_un_cuerpo_que_no_es_un_objeto(): void
    {
        $response = (new QuoteAction($this->useCaseThatNeverRuns()))($this->request(new \stdClass(), $this->operator()), $this->response());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('cuerpo_invalido', $this->decode($response)['error']);
    }

    #[Test]
    public function rechaza_una_peticion_sin_cuerpo(): void
    {
        $response = (new QuoteAction($this->useCaseThatNeverRuns()))($this->request(null, $this->operator()), $this->response());

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function rechaza_a_un_usuario_sin_sede(): void
    {
        $response = (new QuoteAction($this->useCaseThatNeverRuns()))(
            $this->request($this->body(), $this->operator(null)),
            $this->response(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('sede_requerida', $this->decode($response)['error']);
    }

    #[Test]
    public function rechaza_una_peticion_sin_usuario_autenticado(): void
    {
        $response = (new QuoteAction($this->useCaseThatNeverRuns()))($this->request($this->body()), $this->response());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function traduce_medidas_invalidas_a_422(): void
    {
        $useCase = $this->createStub(QuoteShipmentUseCase::class);
        $useCase->method('execute')->willThrowException(new InvalidPricingInput('El campo peso_kg debe estar entre 0.1 y 50'));

        $response = (new QuoteAction($useCase))($this->request($this->body(['peso_kg' => 51]), $this->operator()), $this->response());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('campo_invalido', $this->decode($response)['error']);
        self::assertSame('El campo peso_kg debe estar entre 0.1 y 50', $this->decode($response)['message']);
    }

    #[Test]
    public function traduce_una_referencia_inexistente_a_422(): void
    {
        $useCase = $this->createStub(QuoteShipmentUseCase::class);
        $useCase->method('execute')->willThrowException(new InvalidCatalogReference('El distrito de destino no existe o no está activo'));

        $response = (new QuoteAction($useCase))($this->request($this->body(), $this->operator()), $this->response());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('referencia_invalida', $this->decode($response)['error']);
    }

    #[Test]
    public function responde_503_si_no_hay_tarifa(): void
    {
        $useCase = $this->createStub(QuoteShipmentUseCase::class);
        $useCase->method('execute')->willThrowException(new TariffNotConfigured('sin tarifa'));

        $response = (new QuoteAction($useCase))($this->request($this->body(), $this->operator()), $this->response());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('tarifa_no_configurada', $this->decode($response)['error']);
    }
}
