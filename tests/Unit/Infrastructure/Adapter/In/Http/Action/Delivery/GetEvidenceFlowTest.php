<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\Delivery;

use App\Application\Exception\EvidenceStorageUnavailable;
use App\Application\Exception\InvalidToken;
use App\Application\Exception\TokenExpired;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\Out\Delivery\EvidenceStorage;
use App\Application\Service\Delivery\FinalStatusService;
use App\Application\Service\Delivery\GetEvidenceService;
use App\Infrastructure\Adapter\In\Http\Action\Delivery\FinalStatusAction;
use App\Infrastructure\Adapter\In\Http\Action\Delivery\GetEvidenceAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlFinalStatusRepository;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlPackageEvidenceRepository;
use App\Infrastructure\Adapter\Out\Storage\LocalEvidenceStorage;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Support\DeliveryDatabaseFixture;
use Tests\Support\DeliveryPhotoFixture;

require_once __DIR__ . '/../../../../../../../Support/DeliveryDatabaseFixture.php';
require_once __DIR__ . '/../../../../../../../Support/DeliveryPhotoFixture.php';

/** Contrato HTTP con autorizacion real, consultas SQLite y archivos privados de prueba. */
final class GetEvidenceFlowTest extends TestCase
{
    private Connection $db;
    private LocalEvidenceStorage $storage;
    private string $directory;
    private string $key;
    private string $png;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db = $capsule->getConnection();
        DeliveryDatabaseFixture::create($this->db);
        $this->directory = sys_get_temp_dir() . '/riva-evidence-' . bin2hex(random_bytes(10));
        mkdir($this->directory, 0700);
        $this->storage = new LocalEvidenceStorage($this->directory, dirname(__DIR__, 8) . '/public');
        $this->key = str_repeat('a', 64) . '.png';
        $this->png = DeliveryPhotoFixture::bytes();
        file_put_contents($this->directory . '/' . $this->key, $this->png);
        $this->db->table('paquetes')->whereIn('id', [1, 4])->update(['estado_actual_id' => 4]);
        $this->db->table('paquetes')->where('id', 2)->update(['estado_actual_id' => 5]);
        $this->history(1, $this->key);
        $this->history(4, $this->key);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($this->directory);
        $this->db->disconnect();
        parent::tearDown();
    }

    private function history(int $id, ?string $key, int $state = 4, string $date = '2026-10-24 15:00:00'): void
    {
        $this->db->table('historial_estados')->insert([
            'id_paquete' => $id, 'estado_anterior_id' => 3, 'estado_nuevo_id' => $state,
            'id_usuario_ejecutor' => 31, 'evidencia_key' => $key, 'created_at' => $date,
        ]);
    }

    private function viewer(string $role = 'OPERADOR', ?int $sede = 1, bool $password = false): AuthenticatedUser
    {
        return new AuthenticatedUser($role === 'CONDUCTOR' ? 31 : 51, 'Test', 'test@example.invalid', $role, $password, $sede);
    }

    private function send(
        string $id = '1',
        ?AuthenticatedUser $viewer = null,
        bool $token = true,
        ?\RuntimeException $authError = null,
        string $method = 'GET',
        array $headers = [],
        ?EvidenceStorage $storage = null,
        array $body = [],
    ): ResponseInterface {
        $storage ??= $this->storage;
        $authenticate = $this->createStub(AuthenticateTokenUseCase::class);
        if ($authError === null) {
            $authenticate->method('execute')->willReturn($viewer ?? $this->viewer());
        } else {
            $authenticate->method('execute')->willThrowException($authError);
        }
        $app = AppFactory::create();
        $register = require dirname(__DIR__, 8) . '/src/Infrastructure/Adapter/In/Http/Route/DeliveryRoutes.php';
        $register($app, new FinalStatusAction(new FinalStatusService(new MySqlFinalStatusRepository($this->db), $storage)),
            new JwtAuthMiddleware($authenticate), new RoleMiddleware('CONDUCTOR'), new MustChangePasswordMiddleware(),
            new GetEvidenceAction(new GetEvidenceService(new MySqlPackageEvidenceRepository($this->db), $storage)),
            new RoleMiddleware('OPERADOR', 'ADMINISTRADOR'));
        $suffix = $method === 'POST' ? '/final-status' : '/evidence';
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/v1/packages/' . $id . $suffix)
            ->withParsedBody($body);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($method === 'POST') {
            $upload = new UploadedFile((new StreamFactory())->createStream($this->png), 'foto.png', 'image/png', strlen($this->png));
            $request = $request->withHeader('Content-Type', 'multipart/form-data; boundary=test')->withUploadedFiles(['foto' => $upload]);
        }
        if ($token) {
            $request = $request->withHeader('Authorization', 'Bearer test-token');
        }
        return $app->handle($request);
    }

    private function assertPrivate(ResponseInterface $response): void
    {
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));
        self::assertSame('0', $response->getHeaderLine('Expires'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString('Authorization', $response->getHeaderLine('Vary'));
        self::assertFalse($response->hasHeader('Location'));
        self::assertFalse($response->hasHeader('ETag'));
    }

    public function test_operador_de_la_sede_recibe_la_imagen_sin_claves_rutas_ni_cache(): void
    {
        $response = $this->send();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($this->png, (string) $response->getBody());
        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        self::assertSame((string) strlen($this->png), $response->getHeaderLine('Content-Length'));
        self::assertSame('inline; filename="evidencia-1.png"', $response->getHeaderLine('Content-Disposition'));
        self::assertStringNotContainsString($this->key, json_encode($response->getHeaders()));
        self::assertStringNotContainsString($this->directory, json_encode($response->getHeaders()));
        $this->assertPrivate($response);
    }

    public function test_administrador_ve_cualquier_sede_y_operador_solo_la_suya(): void
    {
        self::assertSame(200, $this->send('4', $this->viewer('ADMINISTRADOR', null))->getStatusCode());
        self::assertSame(200, $this->send('4', $this->viewer('OPERADOR', 2))->getStatusCode());
        self::assertSame(403, $this->send('4')->getStatusCode());
    }

    public static function deniedViewers(): array
    {
        return [['CONDUCTOR', 1, false], ['CLIENTE', null, false], ['OTRO', 1, false],
            ['OPERADOR', null, false], ['OPERADOR', 2, false], ['OPERADOR', 1, true], ['ADMINISTRADOR', null, true]];
    }

    #[DataProvider('deniedViewers')]
    public function test_no_lee_archivos_para_roles_sedes_o_password_no_permitidos(string $role, ?int $sede, bool $password): void
    {
        $storage = $this->createMock(EvidenceStorage::class);
        $storage->expects($this->never())->method('read');
        $response = $this->send(viewer: $this->viewer($role, $sede, $password), storage: $storage);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString($this->key, (string) $response->getBody());
        $this->assertPrivate($response);
    }

    public static function rejectedTokens(): array
    {
        return [[false, null], [true, new InvalidToken()], [true, new TokenExpired()], [true, new UserNotAllowed()]];
    }

    #[DataProvider('rejectedTokens')]
    public function test_autenticacion_obligatoria_tambien_en_errores_sin_cache(bool $token, ?\RuntimeException $error): void
    {
        $storage = $this->createMock(EvidenceStorage::class);
        $storage->expects($this->never())->method('read');
        $response = $this->send(token: $token, authError: $error, storage: $storage);
        self::assertSame(401, $response->getStatusCode());
        $this->assertPrivate($response);
    }

    public static function nonDeliveredStates(): array
    {
        return [[1], [2], [3], [5]];
    }

    #[DataProvider('nonDeliveredStates')]
    public function test_solo_entregado_aunque_otros_estados_tengan_una_clave_heredada(int $state): void
    {
        $this->db->table('paquetes')->where('id', 1)->update(['estado_actual_id' => $state]);
        $storage = $this->createMock(EvidenceStorage::class);
        $storage->expects($this->never())->method('read');
        $response = $this->send(storage: $storage);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('evidencia_no_encontrada', json_decode((string) $response->getBody(), true)['error']);
        self::assertSame(403, $this->send(viewer: $this->viewer('OPERADOR', 2))->getStatusCode());
        $this->assertPrivate($response);
    }

    public function test_paquete_inexistente_no_se_confunde_con_evidencia_ausente(): void
    {
        $response = $this->send('999');
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('paquete_no_encontrado', json_decode((string) $response->getBody(), true)['error']);
        $this->assertPrivate($response);
    }

    public static function unavailableEvidence(): array
    {
        return [['history'], ['nullKey'], ['emptyKey'], ['missingFile'], ['emptyFile'], ['oversize'], ['html'], ['truncated'], ['directory']];
    }

    #[DataProvider('unavailableEvidence')]
    public function test_evidencia_ausente_o_archivo_invalido_no_expone_datos_internos(string $scenario): void
    {
        $path = $this->directory . '/' . $this->key;
        match ($scenario) {
            'history' => $this->db->table('historial_estados')->where('id_paquete', 1)->delete(),
            'nullKey' => $this->db->table('historial_estados')->where('id_paquete', 1)->update(['evidencia_key' => null]),
            'emptyKey' => $this->db->table('historial_estados')->where('id_paquete', 1)->update(['evidencia_key' => '']),
            'missingFile' => unlink($path),
            'emptyFile' => file_put_contents($path, ''),
            'oversize' => file_put_contents($path, str_repeat('x', 5242881)),
            'html' => file_put_contents($path, '<html>private error</html>'),
            'truncated' => file_put_contents($path, substr($this->png, 0, 30)),
            'directory' => $this->replaceFileWithDirectory($path),
        };
        $response = $this->send();
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString($this->key, (string) $response->getBody());
        self::assertStringNotContainsString($this->directory, (string) $response->getBody());
        $this->assertPrivate($response);
    }

    private function replaceFileWithDirectory(string $path): void
    {
        unlink($path);
        mkdir($path);
    }

    public static function maliciousKeys(): array
    {
        return [['../outside.png'], ['/etc/passwd'], ['C:\\Windows\\win.ini'], ['https://example.invalid/foto.png'],
            ['..%2Foutside.png'], ['x' . "\0" . '.png'], [str_repeat('a', 64) . '.svg']];
    }

    #[DataProvider('maliciousKeys')]
    public function test_no_convierte_claves_heredadas_en_rutas_arbitrarias(string $key): void
    {
        $this->db->table('historial_estados')->where('id_paquete', 1)->update(['evidencia_key' => $key]);
        self::assertSame(404, $this->send()->getStatusCode());
        self::assertSame($this->png, file_get_contents($this->directory . '/' . $this->key));
    }

    public function test_selecciona_la_ultima_entrega_con_desempate_por_id_y_nunca_otro_estado(): void
    {
        $secondKey = str_repeat('b', 64) . '.png';
        file_put_contents($this->directory . '/' . $secondKey, $this->png);
        $this->history(1, $secondKey); // misma fecha, id posterior
        unlink($this->directory . '/' . $this->key);
        $this->history(1, 'not-a-photo', 5, '2026-10-25 12:00:00');
        self::assertSame(200, $this->send()->getStatusCode());
        $this->history(1, null, 4, '2026-10-25 13:00:00');
        self::assertSame(404, $this->send()->getStatusCode(), 'No debe reutilizar una foto anterior si la ultima entrega carece de evidencia');
    }

    public function test_lee_jpeg_sin_necesitar_decodificacion_gd_y_respeta_el_mime_real(): void
    {
        $key = str_repeat('b', 64) . '.jpg';
        $bytes = DeliveryPhotoFixture::jpegBytes();
        file_put_contents($this->directory . '/' . $key, $bytes);
        $this->db->table('historial_estados')->where('id_paquete', 1)->update(['evidencia_key' => $key]);
        $response = $this->send();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($bytes, (string) $response->getBody());
        self::assertSame('image/jpeg', $response->getHeaderLine('Content-Type'));
        self::assertSame('inline; filename="evidencia-1.jpg"', $response->getHeaderLine('Content-Disposition'));
        file_put_contents($this->directory . '/' . $key, $this->png);
        self::assertSame(404, $this->send()->getStatusCode());
    }

    public function test_disponible_con_lote_cerrado_sin_modificar_paquete_historial_ni_archivo(): void
    {
        $this->db->table('lotes')->where('id', 210)->update(['estado' => 'CERRADO', 'closed_at' => '2026-10-24 15:00:00']);
        $snapshot = $this->snapshot();
        self::assertSame(200, $this->send()->getStatusCode());
        self::assertSame(200, $this->send()->getStatusCode());
        self::assertEquals($snapshot, $this->snapshot());
        self::assertSame($this->png, file_get_contents($this->directory . '/' . $this->key));
    }

    private function snapshot(): array
    {
        return array_map(fn (string $table): array => $this->db->table($table)->orderBy('id')->get()->toArray(), ['lotes', 'paquetes', 'historial_estados']);
    }

    public function test_head_y_condicionales_no_permiten_saltar_la_autorizacion(): void
    {
        $headers = ['If-None-Match' => '*', 'If-Modified-Since' => 'Sat, 24 Oct 2026 15:00:00 GMT'];
        self::assertSame(401, $this->send(token: false, headers: $headers)->getStatusCode());
        self::assertSame(403, $this->send(viewer: $this->viewer('OPERADOR', 2), headers: $headers)->getStatusCode());
        self::assertSame(200, $this->send(headers: $headers)->getStatusCode());
        $head = $this->send(method: 'HEAD');
        self::assertSame(200, $head->getStatusCode());
        self::assertSame('', (string) $head->getBody());
        self::assertSame((string) strlen($this->png), $head->getHeaderLine('Content-Length'));
        self::assertSame(403, $this->send(viewer: $this->viewer('CONDUCTOR'), method: 'HEAD')->getStatusCode());
        $this->assertPrivate($head);
    }

    public static function invalidIds(): array
    {
        return [['0'], ['-1'], ['1.2'], ['abc'], ['99999999999999999999']];
    }

    #[DataProvider('invalidIds')]
    public function test_identificadores_invalidos(string $id): void
    {
        $response = $this->send($id);
        self::assertSame(422, $response->getStatusCode());
        $this->assertPrivate($response);
    }

    public function test_no_confia_en_sede_o_clave_enviadas_por_el_cliente(): void
    {
        $response = $this->send('4', body: ['id_sede' => 2, 'rol' => 'ADMINISTRADOR', 'evidencia_key' => $this->key]);
        self::assertSame(403, $response->getStatusCode());
    }

    public function test_errores_de_storage_y_bd_se_ocultan_y_no_se_cachean(): void
    {
        $storage = $this->createMock(EvidenceStorage::class);
        $storage->expects($this->once())->method('read')->with($this->key)->willThrowException(new EvidenceStorageUnavailable());
        $snapshot = $this->snapshot();
        $response = $this->send(storage: $storage);
        self::assertSame(503, $response->getStatusCode());
        self::assertEquals($snapshot, $this->snapshot());
        $this->assertPrivate($response);
        $this->db->getSchemaBuilder()->drop('historial_estados');
        $response = $this->send();
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('historial_estados', (string) $response->getBody());
        $this->assertPrivate($response);
    }

    public function test_un_error_inesperado_al_autenticar_tampoco_expone_detalles_ni_se_cachea(): void
    {
        $response = $this->send(authError: new \RuntimeException('private authentication connection detail'));
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('private authentication connection detail', (string) $response->getBody());
        $this->assertPrivate($response);
    }

    public function test_lectura_no_crea_carpetas_y_rechaza_storage_publico(): void
    {
        $missing = $this->directory . '/not-created';
        $storage = new LocalEvidenceStorage($missing, dirname(__DIR__, 8) . '/public');
        self::assertSame(503, $this->send(storage: $storage)->getStatusCode());
        self::assertDirectoryDoesNotExist($missing);
        self::assertSame(503, $this->send(storage: new LocalEvidenceStorage($this->directory, $this->directory))->getStatusCode());
    }

    public function test_foto_subida_en_9_se_consulta_en_10_y_sigue_disponible_al_cerrar_el_lote(): void
    {
        $this->db->table('paquetes')->whereIn('id', [1, 2])->update(['estado_actual_id' => 3]);
        $this->db->table('historial_estados')->whereIn('id_paquete', [1, 2])->delete();
        self::assertSame(200, $this->send(viewer: $this->viewer('CONDUCTOR'), method: 'POST', body: ['estado' => 'ENTREGADO'])->getStatusCode());
        self::assertSame($this->png, (string) $this->send()->getBody());
        self::assertSame(200, $this->send('2', $this->viewer('CONDUCTOR'), method: 'POST', body: ['estado' => 'ENTREGADO'])->getStatusCode());
        self::assertSame('CERRADO', $this->db->table('lotes')->where('id', 210)->value('estado'));
        self::assertSame(200, $this->send()->getStatusCode());
        self::assertSame(200, $this->send('2')->getStatusCode());
        self::assertSame(403, $this->send(viewer: $this->viewer('CONDUCTOR'))->getStatusCode());
    }
}
