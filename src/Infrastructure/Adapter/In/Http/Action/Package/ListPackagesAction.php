<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Package;

use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Package\ListPackagesQuery;
use App\Application\Port\In\Package\ListPackagesUseCase;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class ListPackagesAction
{
    private const STATUSES = ['EN_ALMACEN', 'ASIGNADO', 'EN_RUTA', 'ENTREGADO', 'FALLIDO'];
    private const MAX_PER_PAGE = 100;

    public function __construct(private ListPackagesUseCase $useCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $operator = $request->getAttribute('authUser');
        if (!$operator instanceof AuthenticatedUser) {
            return $this->json($response, ['error' => 'no_autenticado', 'message' => 'Token no proporcionado'], 401);
        }

        $params = $request->getQueryParams();
        $errors = [];

        $search = $this->text($params['q'] ?? null);
        if ($search !== null && mb_strlen($search) > 100) {
            $errors['q'] = 'La búsqueda no puede superar 100 caracteres';
        }

        $status = $this->text($params['estado'] ?? null);
        if ($status !== null) {
            $status = strtoupper($status);
            if (!in_array($status, self::STATUSES, true)) {
                $errors['estado'] = 'Estado no válido. Use: ' . implode(', ', self::STATUSES);
            }
        }

        $from = $this->text($params['desde'] ?? null);
        if ($from !== null && !$this->isDate($from)) {
            $errors['desde'] = 'Use el formato AAAA-MM-DD';
        }
        $to = $this->text($params['hasta'] ?? null);
        if ($to !== null && !$this->isDate($to)) {
            $errors['hasta'] = 'Use el formato AAAA-MM-DD';
        }
        if (!isset($errors['desde']) && !isset($errors['hasta']) && $from !== null && $to !== null && $from > $to) {
            $errors['desde'] = 'La fecha "desde" no puede ser posterior a "hasta"';
        }

        $page = $this->positiveInt($params['page'] ?? null, 1);
        if ($page === null) {
            $errors['page'] = 'Debe ser un entero mayor o igual a 1';
        }
        $perPage = $this->positiveInt($params['per_page'] ?? null, 20);
        if ($perPage === null || $perPage > self::MAX_PER_PAGE) {
            $errors['per_page'] = 'Debe ser un entero entre 1 y ' . self::MAX_PER_PAGE;
        }

        if ($errors !== []) {
            return $this->json($response, ['error' => 'parametros_invalidos', 'message' => 'Parámetros de búsqueda inválidos', 'campos' => $errors], 422);
        }

        try {
            $result = $this->useCase->execute(new ListPackagesQuery($operator, $search, $status, $from, $to, $page, $perPage));
        } catch (UserNotAllowed) {
            return $this->json($response, ['error' => 'sin_permisos', 'message' => 'No tiene permisos para listar paquetes'], 403);
        }

        return $this->json($response, $result->toArray(), 200);
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function positiveInt(mixed $value, int $default): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, array $payload, int $status): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
