<?php

declare(strict_types=1);

namespace App\Infrastructure\Adapter\Out\Security;

use App\Application\Port\Out\Security\RateLimitDecision;
use App\Application\Port\Out\Security\RateLimiter;
use Closure;

/**
 * Limita consultas por ventana fija guardando un contador por clave en archivos.
 * El bloqueo con flock mantiene el conteo correcto entre los procesos PHP del mismo servidor;
 * no hace falta tabla ni migración. Los contadores se pierden al reiniciar el contenedor.
 */
final class FileRateLimiter implements RateLimiter
{
    private Closure $clock;

    public function __construct(
        private readonly string $directory,
        private readonly int $limit,
        private readonly int $windowSeconds = 60,
        ?Closure $clock = null,
        private readonly int $cleanupEvery = 100,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function hit(string $key): RateLimitDecision
    {
        $now = ($this->clock)();
        $window = intdiv($now, $this->windowSeconds) * $this->windowSeconds;
        $retryAfter = $window + $this->windowSeconds - $now;

        $count = $this->increment($key, $window);
        if ($count === null) {
            // Sin almacenamiento no se bloquea la consulta pública: se registra y se deja pasar.
            return new RateLimitDecision(true, $this->limit, $this->limit, 0);
        }

        if ($this->cleanupEvery > 0 && random_int(1, $this->cleanupEvery) === 1) {
            $this->purgeExpired();
        }

        return new RateLimitDecision(
            $count <= $this->limit,
            $this->limit,
            max(0, $this->limit - $count),
            $count <= $this->limit ? 0 : $retryAfter,
        );
    }

    /** Borra los contadores que no se tocan desde hace más de dos ventanas. */
    public function purgeExpired(): void
    {
        $limit = time() - 2 * $this->windowSeconds;
        foreach (glob($this->directory . '/*.cnt') ?: [] as $file) {
            if (@filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }

    private function increment(string $key, int $window): ?int
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            error_log('No se pudo crear la carpeta del limitador de consultas: ' . $this->directory);

            return null;
        }

        // El nombre del archivo es un hash: la IP no queda escrita en disco.
        $handle = @fopen($this->directory . '/' . hash('sha256', $key) . '.cnt', 'c+');
        if ($handle === false) {
            error_log('No se pudo abrir el contador del limitador de consultas');

            return null;
        }

        try {
            flock($handle, LOCK_EX);
            [$storedWindow, $storedCount] = array_map('intval', explode(':', (string) stream_get_contents($handle)) + [0, 0]);
            $count = $storedWindow === $window ? $storedCount + 1 : 1;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $window . ':' . $count);
            fflush($handle);

            return $count;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
