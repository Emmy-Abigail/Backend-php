<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\Out\Security;

use App\Infrastructure\Adapter\Out\Security\FileRateLimiter;
use PHPUnit\Framework\TestCase;

final class FileRateLimiterTest extends TestCase
{
    private string $directory;
    private int $now = 600_020; // 600 000 es múltiplo de 60: estamos 20 segundos dentro de la ventana

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rate-limit-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    private function limiter(int $limit = 3): FileRateLimiter
    {
        return new FileRateLimiter($this->directory, $limit, 60, fn (): int => $this->now, cleanupEvery: 0);
    }

    public function test_permite_hasta_el_limite_y_bloquea_la_siguiente_con_los_segundos_que_faltan(): void
    {
        $limiter = $this->limiter(3);

        self::assertSame([2, 1, 0], array_map(static fn () => $limiter->hit('tracking|1.2.3.4')->remaining, range(1, 3)));

        $blocked = $limiter->hit('tracking|1.2.3.4');
        self::assertFalse($blocked->allowed);
        self::assertSame(40, $blocked->retryAfterSeconds);
    }

    public function test_cada_clave_tiene_su_propio_contador(): void
    {
        $limiter = $this->limiter(1);
        self::assertTrue($limiter->hit('tracking|1.1.1.1')->allowed);
        self::assertFalse($limiter->hit('tracking|1.1.1.1')->allowed);
        self::assertTrue($limiter->hit('tracking|2.2.2.2')->allowed);
    }

    public function test_el_contador_vuelve_a_empezar_en_la_siguiente_ventana(): void
    {
        $limiter = $this->limiter(1);
        $limiter->hit('k');
        self::assertFalse($limiter->hit('k')->allowed);

        $this->now += 40; // empieza la ventana siguiente
        self::assertTrue($limiter->hit('k')->allowed);
    }

    public function test_un_proceso_nuevo_continua_el_conteo_guardado_en_disco(): void
    {
        $this->limiter(2)->hit('k');
        $this->limiter(2)->hit('k');
        self::assertFalse($this->limiter(2)->hit('k')->allowed);
    }

    public function test_no_guarda_la_ip_en_el_nombre_del_archivo(): void
    {
        $this->limiter()->hit('tracking|203.0.113.7');
        $files = glob($this->directory . '/*.cnt');
        self::assertCount(1, $files);
        self::assertStringNotContainsString('203.0.113.7', $files[0]);
    }

    public function test_borra_solo_los_contadores_viejos(): void
    {
        $limiter = $this->limiter();
        $limiter->hit('vieja');
        $limiter->hit('reciente');
        [$old] = array_values(array_filter(glob($this->directory . '/*.cnt'), static fn (string $f): bool => str_contains($f, hash('sha256', 'vieja'))));
        touch($old, time() - 600);

        $limiter->purgeExpired();

        self::assertFileDoesNotExist($old);
        self::assertCount(1, glob($this->directory . '/*.cnt'));
    }

    public function test_si_no_puede_guardar_deja_pasar_la_consulta(): void
    {
        $file = $this->directory . '-archivo';
        file_put_contents($file, 'no es una carpeta');
        try {
            $limiter = new FileRateLimiter($file, 1, 60, fn (): int => $this->now, cleanupEvery: 0);
            set_error_handler(static fn (): bool => true);
            $ini = ini_set('error_log', '/dev/null');
            self::assertTrue($limiter->hit('k')->allowed);
            self::assertTrue($limiter->hit('k')->allowed);
        } finally {
            restore_error_handler();
            ini_set('error_log', $ini ?: '');
            unlink($file);
        }
    }
}
