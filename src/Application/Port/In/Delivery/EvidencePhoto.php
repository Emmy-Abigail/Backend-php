<?php

declare(strict_types=1);

namespace App\Application\Port\In\Delivery;

use App\Application\Exception\InvalidFinalStatus;
use App\Application\Exception\EvidenceStorageUnavailable;

/** El nombre y MIME enviados por el cliente nunca deciden el formato almacenado. */
final readonly class EvidencePhoto
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public string $extension;

    public function __construct(public string $bytes)
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidFinalStatus('La foto debe contener datos y pesar como maximo 5 MiB (5242880 bytes)');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($mime, ['image/jpeg', 'image/png'], true)
            || ($info['mime'] ?? null) !== $mime || $info[0] < 1 || $info[1] < 1) {
            throw new InvalidFinalStatus('La foto debe ser una imagen JPEG o PNG valida');
        }
        if (!function_exists('imagecreatefromstring') || ($mime === 'image/jpeg' && !(imagetypes() & IMG_JPG))
            || ($mime === 'image/png' && !(imagetypes() & IMG_PNG))) {
            throw new EvidenceStorageUnavailable();
        }
        // Evitar agotar memoria al descomprimir una imagen de dimensiones enormes.
        // El limite se adapta a PHP, sin depender de lo que declare el cliente.
        $memoryLimit = ini_parse_quantity((string) ini_get('memory_limit'));
        $requiredMemory = (float) $info[0] * $info[1] * 8 + strlen($bytes) * 2 + 16 * 1024 * 1024;
        // PHP CLI suele usar -1; mantener tambien en ese caso un presupuesto finito.
        $availableMemory = $memoryLimit > 0 ? $memoryLimit - memory_get_usage(true) : 128 * 1024 * 1024;
        if ($requiredMemory > $availableMemory) {
            throw new InvalidFinalStatus('La resolucion de la foto es demasiado alta; reduce sus dimensiones antes de enviarla');
        }
        $hasEnd = $mime === 'image/png'
            ? str_ends_with($bytes, "\x00\x00\x00\x00IEND\xae\x42\x60\x82")
            : str_ends_with(rtrim($bytes, "\r\n\t "), "\xff\xd9");
        $image = $hasEnd ? @imagecreatefromstring($bytes) : false;
        if ($image === false) {
            throw new InvalidFinalStatus('La foto esta incompleta o danada');
        }
        unset($image);
        $this->extension = $mime === 'image/jpeg' ? 'jpg' : 'png';
    }
}
