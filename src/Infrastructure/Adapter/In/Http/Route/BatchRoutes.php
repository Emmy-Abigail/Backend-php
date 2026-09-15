<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;

return static function (App $app): void {
    $notImplemented = static function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        $response->getBody()->write(json_encode(['message' => 'Endpoint aún no implementado']));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(501);
    };

    $app->post('/api/v1/batches', $notImplemented);
    $app->get('/api/v1/batches', $notImplemented);
    $app->get('/api/v1/batches/{id}', $notImplemented);
    $app->patch('/api/v1/batches/{id}/assignment', $notImplemented);
};
