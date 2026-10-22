<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Pricing;

use App\Application\Exception\InvalidCatalogReference;
use App\Application\Exception\TariffNotConfigured;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Pricing\QuoteShipmentCommand;
use App\Application\Port\In\Pricing\QuoteShipmentUseCase;
use App\Domain\Pricing\InvalidPricingInput;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class QuoteAction
{
    private const NUMERIC_FIELDS = ['peso_kg', 'largo_cm', 'ancho_cm', 'alto_cm'];

    public function __construct(private QuoteShipmentUseCase $quoteShipmentUseCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $authUser = $request->getAttribute('authUser');

        if (!$authUser instanceof AuthenticatedUser || $authUser->idSede === null) {
            return $this->json($response, ['message' => 'El usuario no tiene una sede asignada', 'error' => 'sede_requerida'], 403);
        }

        $body = $request->getParsedBody();

        if (!is_array($body)) {
            return $this->json($response, ['message' => 'El cuerpo debe ser un objeto JSON válido', 'error' => 'cuerpo_invalido'], 400);
        }

        $ubigeo = $body['ubigeo_destino'] ?? null;

        if ($ubigeo === null) {
            return $this->json($response, ['message' => 'El campo ubigeo_destino es obligatorio', 'error' => 'campo_obligatorio'], 422);
        }

        if (!is_string($ubigeo) || preg_match('/^[0-9]{6}$/', $ubigeo) !== 1) {
            return $this->json($response, ['message' => 'El campo ubigeo_destino debe tener exactamente 6 dígitos', 'error' => 'campo_invalido'], 422);
        }

        $numbers = [];

        foreach (self::NUMERIC_FIELDS as $field) {
            $raw = $body[$field] ?? null;

            if ($raw === null) {
                return $this->json($response, ['message' => sprintf('El campo %s es obligatorio', $field), 'error' => 'campo_obligatorio'], 422);
            }

            $text = $this->numberToString($raw);

            if ($text === null) {
                return $this->json($response, ['message' => sprintf('El campo %s debe ser numérico', $field), 'error' => 'campo_invalido'], 422);
            }

            $numbers[$field] = $text;
        }

        try {
            $result = $this->quoteShipmentUseCase->execute(new QuoteShipmentCommand(
                $authUser->idSede,
                $ubigeo,
                $numbers['peso_kg'],
                $numbers['largo_cm'],
                $numbers['ancho_cm'],
                $numbers['alto_cm'],
            ));
        } catch (InvalidPricingInput $exception) {
            return $this->json($response, ['message' => $exception->getMessage(), 'error' => 'campo_invalido'], 422);
        } catch (InvalidCatalogReference $exception) {
            return $this->json($response, ['message' => $exception->getMessage(), 'error' => 'referencia_invalida'], 422);
        } catch (TariffNotConfigured) {
            return $this->json($response, ['message' => 'No hay una tarifa base vigente', 'error' => 'tarifa_no_configurada'], 503);
        }

        $quote = $result->quote;

        return $this->json($response, [
            'peso_volumetrico_kg' => $quote->volumetricWeightKg(),
            'peso_facturable_kg' => $quote->billableWeightKg(),
            'zona_origen' => $result->originZoneCode,
            'zona_destino' => $result->destinationZoneCode,
            'factor' => $quote->factor(),
            'tarifa_base_kg' => $quote->baseRatePerKg(),
            'monto' => $quote->amount(),
            'sede_origen' => [
                'id' => $result->originSedeId,
                'nombre' => $result->originSedeName,
            ],
        ]);
    }

    /**
     * Convierte el valor del JSON a texto decimal; null si no es un número válido.
     */
    private function numberToString(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }

            return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }

        if (is_string($value)) {
            return trim($value);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
