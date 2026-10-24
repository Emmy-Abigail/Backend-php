<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Storage;

use App\Application\Exception\EvidenceStorageUnavailable;
use App\Application\Exception\EvidenceNotFound;
use App\Application\Port\In\Delivery\EvidencePhoto;
use App\Application\Port\Out\Delivery\EvidenceStorage;
use App\Application\Port\Out\Delivery\StoredEvidence;
use Throwable;

final readonly class LocalEvidenceStorage implements EvidenceStorage
{
    public function __construct(private string $directory, private string $publicDirectory)
    {
    }

    private function root(bool $create = true): string
    {
        if (!is_dir($this->directory) && (!$create || (!@mkdir($this->directory, 0700, true) && !is_dir($this->directory)))) {
            throw new EvidenceStorageUnavailable();
        }
        $root = realpath($this->directory);
        $public = realpath($this->publicDirectory);
        if ($root === false || $public === false) {
            throw new EvidenceStorageUnavailable();
        }
        // realpath tambien resuelve enlaces simbolicos y rutas con '..'.
        $normalizedRoot = strtolower(str_replace('\\', '/', $root));
        $normalizedPublic = rtrim(strtolower(str_replace('\\', '/', $public)), '/');
        if ($normalizedRoot === $normalizedPublic || str_starts_with($normalizedRoot, $normalizedPublic . '/')) {
            throw new EvidenceStorageUnavailable();
        }
        return $root;
    }

    public function store(EvidencePhoto $photo): string
    {
        $root = $this->root();
        $handle = false;
        $path = null;
        try {
            $key = bin2hex(random_bytes(32)) . '.' . $photo->extension;
            $path = $root . DIRECTORY_SEPARATOR . $key;
            $handle = @fopen($path, 'xb');
            if ($handle === false) {
                throw new EvidenceStorageUnavailable();
            }
            if (PHP_OS_FAMILY !== 'Windows' && !@chmod($path, 0600)) {
                throw new EvidenceStorageUnavailable();
            }
            $offset = 0;
            $length = strlen($photo->bytes);
            while ($offset < $length) {
                $written = @fwrite($handle, substr($photo->bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new EvidenceStorageUnavailable();
                }
                $offset += $written;
            }
            if (!@fflush($handle) || !@fsync($handle)) {
                throw new EvidenceStorageUnavailable();
            }
            @fclose($handle);
            return $key;
        } catch (Throwable) {
            // Solo eliminar el archivo que este intento creo de forma exclusiva.
            if (is_resource($handle)) {
                @fclose($handle);
                if ($path !== null) {
                    @unlink($path);
                }
            }
            throw new EvidenceStorageUnavailable();
        }
    }

    public function delete(string $key): void
    {
        if (preg_match('/^[a-f0-9]{64}\.(?:jpg|png)$/D', $key) !== 1) {
            throw new EvidenceStorageUnavailable();
        }
        $path = $this->root() . DIRECTORY_SEPARATOR . $key;
        if (is_file($path) && !@unlink($path)) {
            throw new EvidenceStorageUnavailable();
        }
    }

    public function read(string $key): StoredEvidence
    {
        // La clave viene del historial, pero tampoco se confia en rutas heredadas.
        if (preg_match('/^[a-f0-9]{64}\.(?:jpg|png)$/D', $key) !== 1) {
            throw new EvidenceNotFound();
        }
        $root = $this->root(false);
        $path = $root . DIRECTORY_SEPARATOR . $key;
        $resolved = realpath($path);
        if (is_link($path) || $resolved === false || dirname($resolved) !== $root || !is_file($resolved)) {
            throw new EvidenceNotFound();
        }
        $handle = @fopen($resolved, 'rb');
        if ($handle === false) {
            throw new EvidenceStorageUnavailable();
        }
        try {
            $stat = fstat($handle);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] < 1 || $stat['size'] > EvidencePhoto::MAX_BYTES) {
                throw new EvidenceNotFound();
            }
            $bytes = @stream_get_contents($handle, EvidencePhoto::MAX_BYTES + 1);
            if ($bytes === false) {
                throw new EvidenceStorageUnavailable();
            }
            if (strlen($bytes) !== $stat['size'] || strlen($bytes) > EvidencePhoto::MAX_BYTES) {
                throw new EvidenceNotFound();
            }
        } finally {
            fclose($handle);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $info = @getimagesizefromstring($bytes);
        $expectedMime = str_ends_with($key, '.jpg') ? 'image/jpeg' : 'image/png';
        $hasEnd = $expectedMime === 'image/png'
            ? str_ends_with($bytes, "\x00\x00\x00\x00IEND\xae\x42\x60\x82")
            : str_ends_with(rtrim($bytes, "\r\n\t "), "\xff\xd9");
        if ($mime !== $expectedMime || $info === false || ($info['mime'] ?? null) !== $expectedMime || !$hasEnd) {
            throw new EvidenceNotFound();
        }
        // No descomprimir: la foto ya fue validada al subirla. Servir JPEG no requiere GD.
        return new StoredEvidence($bytes, $expectedMime);
    }
}
