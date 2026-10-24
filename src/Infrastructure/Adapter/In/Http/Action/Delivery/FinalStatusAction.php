<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\In\Http\Action\Delivery;

use App\Application\Exception\EvidenceStorageUnavailable;
use App\Application\Exception\FinalStatusConflict;
use App\Application\Exception\InvalidFinalStatus;
use App\Application\Exception\InvalidPackageTransition;
use App\Application\Exception\PackageAccessDenied;
use App\Application\Exception\PackageNotFound;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Delivery\EvidencePhoto;
use App\Application\Port\In\Delivery\FinalStatusCommand;
use App\Application\Port\In\Delivery\FinalStatusUseCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final readonly class FinalStatusAction
{
    public function __construct(private FinalStatusUseCase $useCase)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $driver = $request->getAttribute('authUser');
        if (!$driver instanceof AuthenticatedUser) {
            return $this->json($response, 401, ['error' => 'no_autenticado', 'message' => 'Token no proporcionado']);
        }
        if ($driver->role !== 'CONDUCTOR' || $driver->mustChangePassword) {
            return $this->json($response, 403, ['error' => 'sin_permisos', 'message' => 'No tiene permisos para finalizar entregas']);
        }
        try {
            $rawId = $args['id'] ?? '';
            if (!is_string($rawId) || preg_match('/^[0-9]{1,18}$/D', $rawId) !== 1 || (int) $rawId < 1) {
                throw new InvalidFinalStatus('El id del paquete no es valido');
            }
            $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
            if ($contentType !== 'multipart/form-data') {
                throw new InvalidFinalStatus('Enviar multipart/form-data con estado, observacion, id_motivo o foto segun corresponda');
            }
            $body = $request->getParsedBody();
            if (!is_array($body)) {
                throw new InvalidFinalStatus('No se pudo leer el formulario; comprueba el tamano de la solicitud');
            }
            $status = $body['estado'] ?? null;
            $observation = $body['observacion'] ?? null;
            $rawReason = $body['id_motivo'] ?? null;
            if (!is_string($status) || ($observation !== null && !is_string($observation))) {
                throw new InvalidFinalStatus('estado y observacion deben ser texto');
            }
            $reasonId = null;
            if ($rawReason !== null) {
                if ((!is_int($rawReason) && !is_string($rawReason)) || preg_match('/^[0-9]{1,18}$/D', (string) $rawReason) !== 1 || (int) $rawReason < 1) {
                    throw new InvalidFinalStatus('id_motivo debe ser un entero positivo');
                }
                $reasonId = (int) $rawReason;
            }
            $uploads = $request->getUploadedFiles();
            if (array_diff(array_keys($uploads), ['foto']) !== []) {
                throw new InvalidFinalStatus('El unico archivo permitido es foto');
            }
            $photo = null;
            if (isset($uploads['foto'])) {
                $upload = $uploads['foto'];
                if (!$upload instanceof UploadedFileInterface) {
                    throw new InvalidFinalStatus('foto debe contener un unico archivo');
                }
                if ($upload->getError() !== UPLOAD_ERR_NO_FILE) {
                    if ($status !== 'ENTREGADO') {
                        throw new InvalidFinalStatus('FALLIDO no admite foto');
                    }
                    $photo = $this->readPhoto($upload);
                }
            }
            $command = new FinalStatusCommand($driver, (int) $rawId, $status, $reasonId, $observation, $photo);
            $result = $this->useCase->execute($command);
            return $this->json($response, 200, $result->toArray());
        } catch (InvalidFinalStatus $exception) {
            return $this->json($response, 422, ['error' => 'datos_invalidos', 'message' => $exception->getMessage()]);
        } catch (PackageNotFound $exception) {
            return $this->json($response, 404, ['error' => 'paquete_no_encontrado', 'message' => $exception->getMessage()]);
        } catch (PackageAccessDenied | UserNotAllowed) {
            return $this->json($response, 403, ['error' => 'sin_permisos', 'message' => 'No tiene permisos para finalizar este paquete']);
        } catch (InvalidPackageTransition $exception) {
            return $this->json($response, 400, ['error' => 'transicion_invalida', 'message' => $exception->getMessage()]);
        } catch (FinalStatusConflict $exception) {
            return $this->json($response, 409, ['error' => 'estado_final_en_conflicto', 'message' => $exception->getMessage()]);
        } catch (EvidenceStorageUnavailable) {
            return $this->json($response, 503, ['error' => 'evidencia_no_disponible', 'message' => 'No se pudo guardar la evidencia; vuelve a intentarlo']);
        } catch (\Throwable) {
            // No exponer SQL, claves de evidencia ni rutas locales, tampoco en desarrollo.
            error_log('Fallo al registrar el estado final de un paquete');
            return $this->json($response, 500, ['error' => 'error_interno', 'message' => 'No se pudo registrar el estado final; consulta el paquete antes de reintentar']);
        }
    }

    private function readPhoto(UploadedFileInterface $upload): EvidencePhoto
    {
        if (in_array($upload->getError(), [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE], true)) {
            throw new EvidenceStorageUnavailable();
        }
        if ($upload->getError() !== UPLOAD_ERR_OK || ($upload->getSize() ?? 0) > EvidencePhoto::MAX_BYTES) {
            throw new InvalidFinalStatus('No se recibio la foto completa o supera 5 MiB (5242880 bytes)');
        }
        try {
            $stream = $upload->getStream();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $bytes = '';
            while (!$stream->eof() && strlen($bytes) <= EvidencePhoto::MAX_BYTES) {
                $chunk = $stream->read(min(65536, EvidencePhoto::MAX_BYTES + 1 - strlen($bytes)));
                if ($chunk === '') {
                    break;
                }
                $bytes .= $chunk;
            }
        } catch (\Throwable) {
            throw new InvalidFinalStatus('No se pudo leer la foto completa');
        }
        return new EvidencePhoto($bytes);
    }

    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
