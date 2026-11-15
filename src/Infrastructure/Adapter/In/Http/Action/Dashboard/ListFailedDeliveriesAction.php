<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Dashboard;

use App\Application\Exception\InvalidCatalogReference;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Dashboard\ListFailedDeliveriesQuery;
use App\Application\Port\In\Dashboard\ListFailedDeliveriesUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/v1/dashboard/failed-deliveries?desde&hasta[&id_sede][&page][&per_page] */
final readonly class ListFailedDeliveriesAction
{
    public const DEFAULT_PER_PAGE = 8;
    public const MAX_PER_PAGE = 50;

    public function __construct(private ListFailedDeliveriesUseCase $useCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $request->getAttribute('authUser');
        if (!$user instanceof AuthenticatedUser) {
            return DashboardJson::write($response, ['error' => 'no_autenticado', 'message' => 'Token no proporcionado'], 401);
        }

        $query = $request->getQueryParams();
        $params = DashboardQueryParams::fromQuery($query);
        $errors = $params->errors;

        $page = $this->pageParam($query['page'] ?? null, 1);
        if ($page === null) {
            $errors['page'] = 'Debe ser un entero mayor o igual a 1';
        }
        $perPage = $this->pageParam($query['per_page'] ?? null, self::DEFAULT_PER_PAGE);
        if ($perPage === null || $perPage > self::MAX_PER_PAGE) {
            $errors['per_page'] = 'Debe ser un entero entre 1 y ' . self::MAX_PER_PAGE;
        }

        if ($errors !== []) {
            return DashboardJson::invalidParams($response, $params, $errors);
        }

        try {
            $result = $this->useCase->execute(new ListFailedDeliveriesQuery(
                $user,
                (string) $params->from,
                (string) $params->to,
                $params->sedeId,
                (int) $page,
                (int) $perPage,
            ));
        } catch (UserNotAllowed) {
            return DashboardJson::write($response, ['error' => 'sin_permisos', 'message' => 'No tiene permisos para ver el dashboard'], 403);
        } catch (InvalidCatalogReference $exception) {
            return DashboardJson::write($response, [
                'error' => 'parametros_invalidos',
                'message' => $exception->getMessage(),
                'campos' => ['id_sede' => $exception->getMessage()],
            ], 422);
        }

        return DashboardJson::write($response, $result->toArray(), 200);
    }

    private function pageParam(mixed $value, int $default): ?int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return DashboardQueryParams::positiveInt($value);
    }
}
