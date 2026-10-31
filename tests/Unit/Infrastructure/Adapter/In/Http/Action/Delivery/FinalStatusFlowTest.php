<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\In\Http\Action\Delivery;

use App\Application\Exception\EvidenceStorageUnavailable;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticateTokenUseCase;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Delivery\EvidencePhoto;
use App\Application\Port\In\Delivery\GetEvidenceUseCase;
use App\Application\Port\Out\Delivery\EvidenceStorage;
use App\Application\Service\Delivery\FinalStatusService;
use App\Infrastructure\Adapter\In\Http\Action\Delivery\FinalStatusAction;
use App\Infrastructure\Adapter\In\Http\Action\Delivery\GetEvidenceAction;
use App\Infrastructure\Adapter\In\Http\Middleware\JwtAuthMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\MustChangePasswordMiddleware;
use App\Infrastructure\Adapter\In\Http\Middleware\RoleMiddleware;
use App\Infrastructure\Adapter\Out\Persistence\MySQL\MySqlFinalStatusRepository;
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

/** Contrato HTTP con middleware real, transacciones SQLite y almacenamiento privado real. */
final class FinalStatusFlowTest extends TestCase
{
    private Connection $db;
    private string $directory;
    private LocalEvidenceStorage $storage;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db = $capsule->getConnection();
        DeliveryDatabaseFixture::create($this->db);
        $this->directory = sys_get_temp_dir() . '/riva-delivery-' . bin2hex(random_bytes(10));
        mkdir($this->directory, 0700);
        $this->storage = new LocalEvidenceStorage($this->directory, dirname(__DIR__, 8) . '/public');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        $this->db->disconnect();
        parent::tearDown();
    }

    private function photo(?string $bytes = null, int $error = UPLOAD_ERR_OK, ?int $declaredSize = null): UploadedFile
    {
        $bytes ??= DeliveryPhotoFixture::bytes();
        return new UploadedFile((new StreamFactory())->createStream($bytes), '../../evidence.php', 'application/x-php', $declaredSize ?? strlen($bytes), $error);
    }

    private function send(
        array $body = ['estado' => 'FALLIDO', 'id_motivo' => '1', 'observacion' => 'Cliente ausente'],
        array $files = [],
        string $id = '1',
        string $role = 'CONDUCTOR',
        bool $password = false,
        bool $token = true,
        string $contentType = 'multipart/form-data; boundary=test',
        ?EvidenceStorage $storage = null,
        bool $active = true,
    ): ResponseInterface {
        $app = AppFactory::create();
        $authenticate = $this->createStub(AuthenticateTokenUseCase::class);
        if ($active) {
            $authenticate->method('execute')->willReturn(new AuthenticatedUser(31, 'Juan', 'juan@example.invalid', $role, $password));
        } else {
            $authenticate->method('execute')->willThrowException(new UserNotAllowed());
        }
        $register = require dirname(__DIR__, 8) . '/src/Infrastructure/Adapter/In/Http/Route/DeliveryRoutes.php';
        $register($app, new FinalStatusAction(new FinalStatusService(new MySqlFinalStatusRepository($this->db), $storage ?? $this->storage)),
            new JwtAuthMiddleware($authenticate), new RoleMiddleware('CONDUCTOR'), new MustChangePasswordMiddleware(),
            new GetEvidenceAction($this->createStub(GetEvidenceUseCase::class)), new RoleMiddleware('OPERADOR', 'ADMINISTRADOR'));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/packages/' . $id . '/final-status')
            ->withHeader('Content-Type', $contentType)->withParsedBody($body)->withUploadedFiles($files);
        if ($token) {
            $request = $request->withHeader('Authorization', 'Bearer test-token');
        }
        return $app->handle($request);
    }

    public static function formats(): array
    {
        return [['png'], ['jpg']];
    }

    #[DataProvider('formats')]
    public function test_entrega_con_foto_real_sin_confiar_en_nombre_mime_o_ejecutor_del_cliente(string $format): void
    {
        if ($format === 'jpg' && !function_exists('imagejpeg')) {
            self::markTestSkipped('PHP local no tiene soporte JPEG en GD; Docker instala GD con JPEG');
        }
        $bytes = DeliveryPhotoFixture::bytes($format);
        $response = $this->send(['estado' => 'ENTREGADO', 'id_conductor' => 999, 'observacion' => '  Recibido  '], ['foto' => $this->photo($bytes)]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('ENTREGADO', $body['estado']);
        self::assertTrue($body['tiene_evidencia']);
        self::assertSame('EN_RUTA', $body['lote']['estado']);
        self::assertNull($body['lote']['closed_at']);
        self::assertStringEndsWith('-05:00', $body['fecha']);
        $history = $this->db->table('historial_estados')->first();
        self::assertSame(31, $history->id_usuario_ejecutor);
        self::assertSame('Recibido', $history->comentario);
        self::assertNull($history->id_motivo_fallo);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}\.' . $format . '$/', $history->evidencia_key);
        self::assertSame($bytes, file_get_contents($this->directory . '/' . $history->evidencia_key));
        self::assertStringNotContainsString($history->evidencia_key, (string) $response->getBody());
        self::assertSame(4, $this->db->table('paquetes')->where('id', 1)->value('estado_actual_id'));
    }

    public function test_fallo_sin_foto_y_ultima_parada_cierran_lote_con_su_historial(): void
    {
        self::assertSame(200, $this->send()->getStatusCode());
        $response = $this->send(id: '2');
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('CERRADO', $body['lote']['estado']);
        self::assertFalse($body['tiene_evidencia']);
        self::assertNotNull($this->db->table('lotes')->where('id', 210)->value('closed_at'));
        self::assertSame(2, $this->db->table('historial_estados')->count());
        self::assertSame([], glob($this->directory . '/*'));
        self::assertSame(409, $this->send(id: '2')->getStatusCode());
        self::assertSame(2, $this->db->table('historial_estados')->count());
    }

    public static function invalidForms(): array
    {
        return [
            [[]], [['estado' => 'EN_RUTA']], [['estado' => ['ENTREGADO']]],
            [['estado' => 'ENTREGADO']], [['estado' => 'FALLIDO']],
            [['estado' => 'FALLIDO', 'id_motivo' => '1', 'observacion' => '   ']],
            [['estado' => 'FALLIDO', 'id_motivo' => '99', 'observacion' => 'No existe']],
            [['estado' => 'FALLIDO', 'id_motivo' => '1.0', 'observacion' => 'Cliente ausente']],
            [['estado' => 'FALLIDO', 'id_motivo' => '-1', 'observacion' => 'Cliente ausente']],
            [['estado' => 'FALLIDO', 'id_motivo' => [], 'observacion' => 'Cliente ausente']],
            [['estado' => 'FALLIDO', 'id_motivo' => '1', 'observacion' => ['texto']]],
            [['estado' => 'FALLIDO', 'id_motivo' => '1', 'observacion' => str_repeat('x', 65536)]],
            'otros sin motivo_otro' => [['estado' => 'FALLIDO', 'id_motivo' => '4', 'observacion' => 'Sin detalle']],
            'otros con motivo_otro en blanco' => [['estado' => 'FALLIDO', 'id_motivo' => '4', 'motivo_otro' => '   ', 'observacion' => 'Sin detalle']],
            'otros con motivo_otro muy corto' => [['estado' => 'FALLIDO', 'id_motivo' => '4', 'motivo_otro' => 'abc', 'observacion' => 'Corto']],
            'otros con motivo_otro muy largo' => [['estado' => 'FALLIDO', 'id_motivo' => '4', 'motivo_otro' => str_repeat('x', 256), 'observacion' => 'Largo']],
            'otros con motivo_otro que no es texto' => [['estado' => 'FALLIDO', 'id_motivo' => '4', 'motivo_otro' => ['Edificio cerrado'], 'observacion' => 'Lista']],
            'otros sin observacion' => [['estado' => 'FALLIDO', 'id_motivo' => '4', 'motivo_otro' => 'Edificio cerrado']],
            'motivo_otro con otro motivo' => [['estado' => 'FALLIDO', 'id_motivo' => '1', 'motivo_otro' => 'No corresponde aqui', 'observacion' => 'Cliente ausente']],
        ];
    }

    public function test_fallo_con_motivo_otros_guarda_lo_escrito_aparte_de_la_observacion(): void
    {
        $response = $this->send(['estado' => 'FALLIDO', 'id_motivo' => '4', 'motivo_otro' => '  Edificio cerrado por fumigación  ', 'observacion' => 'Volver el lunes']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $history = $this->db->table('historial_estados')->first();
        self::assertSame(4, $history->id_motivo_fallo);
        self::assertSame('Edificio cerrado por fumigación', $history->motivo_otro);
        self::assertSame('Volver el lunes', $history->comentario);
        self::assertSame(5, $this->db->table('paquetes')->where('id', 1)->value('estado_actual_id'));
    }

    public function test_motivo_otro_vacio_con_un_motivo_normal_se_ignora(): void
    {
        // El frontend puede enviar el campo oculto vacío cuando no se eligió "Otros".
        $response = $this->send(['estado' => 'FALLIDO', 'id_motivo' => '1', 'motivo_otro' => '', 'observacion' => 'Cliente ausente']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->db->table('historial_estados')->value('motivo_otro'));
    }

    #[DataProvider('invalidForms')]
    public function test_validaciones_del_formulario_no_modifican_el_estado(array $body): void
    {
        self::assertSame(422, $this->send($body)->getStatusCode());
        $this->assertUnchanged();
    }

    public static function invalidPhotos(): array
    {
        return [['empty'], ['fake'], ['truncated'], ['corrupt'], ['hugeDimensions'], ['oversize'], ['sizeLie'],
            ['partial'], ['iniLimit'], ['noTemp'], ['writeError'], ['noFile'], ['nested'],
            ['failedWithPhoto'], ['deliveredWithReason'], ['otherFile']];
    }

    #[DataProvider('invalidPhotos')]
    public function test_rechaza_fotos_invalidas_sin_guardar_archivos_ni_estados(string $scenario): void
    {
        $bytes = DeliveryPhotoFixture::bytes();
        $hugeHeader = substr_replace($bytes, pack('NN', 1000000, 1000000), 16, 8);
        $hugeHeader = substr_replace($hugeHeader, hash('crc32b', substr($hugeHeader, 12, 17), true), 29, 4);
        $files = ['foto' => match ($scenario) {
            'empty' => $this->photo(''),
            'fake' => $this->photo('<?php echo "fake png";'),
            'truncated' => $this->photo(substr($bytes, 0, 30)),
            'corrupt' => $this->photo(substr($bytes, 0, 35) . str_repeat('X', strlen($bytes) - 47) . substr($bytes, -12)),
            'hugeDimensions' => $this->photo($hugeHeader),
            'oversize' => $this->photo($bytes, declaredSize: EvidencePhoto::MAX_BYTES + 1),
            'sizeLie' => $this->photo($bytes . str_repeat('x', EvidencePhoto::MAX_BYTES), declaredSize: 1),
            'partial' => $this->photo(error: UPLOAD_ERR_PARTIAL),
            'iniLimit' => $this->photo(error: UPLOAD_ERR_INI_SIZE),
            'noTemp' => $this->photo(error: UPLOAD_ERR_NO_TMP_DIR),
            'writeError' => $this->photo(error: UPLOAD_ERR_CANT_WRITE),
            'noFile' => $this->photo(error: UPLOAD_ERR_NO_FILE),
            'nested' => [$this->photo()],
            default => $this->photo(),
        }];
        $body = ['estado' => 'ENTREGADO'];
        if ($scenario === 'failedWithPhoto') {
            $body = ['estado' => 'FALLIDO', 'id_motivo' => '1', 'observacion' => 'Ausente'];
        } elseif ($scenario === 'deliveredWithReason') {
            $body['id_motivo'] = '1';
        } elseif ($scenario === 'otherFile') {
            $files['archivo'] = $this->photo();
        }
        $response = $this->send($body, $files);
        self::assertSame(in_array($scenario, ['noTemp', 'writeError'], true) ? 503 : 422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertUnchanged();
    }

    public function test_admite_una_foto_valida_de_exactamente_cinco_mib(): void
    {
        $png = DeliveryPhotoFixture::bytes();
        $payload = 'Comment' . "\0" . str_repeat('x', EvidencePhoto::MAX_BYTES - strlen($png) - 20);
        $chunk = pack('N', strlen($payload)) . 'tEXt' . $payload . hash('crc32b', 'tEXt' . $payload, true);
        $bytes = substr($png, 0, -12) . $chunk . substr($png, -12);
        self::assertSame(EvidencePhoto::MAX_BYTES, strlen($bytes));
        $response = $this->send(['estado' => 'ENTREGADO'], ['foto' => $this->photo($bytes)]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(1, $this->db->table('historial_estados')->count());
    }

    public static function permissions(): array
    {
        return [['CONDUCTOR', false, false, true, 401], ['CONDUCTOR', false, true, false, 401],
            ['CONDUCTOR', true, true, true, 403], ['OPERADOR', false, true, true, 403], ['ADMINISTRADOR', false, true, true, 403]];
    }

    #[DataProvider('permissions')]
    public function test_jwt_rol_actividad_y_cambio_inicial_obligatorio(string $role, bool $password, bool $token, bool $active, int $status): void
    {
        self::assertSame($status, $this->send(role: $role, password: $password, token: $token, active: $active)->getStatusCode());
        $this->assertUnchanged();
    }

    public static function invalidIds(): array
    {
        return [['0'], ['-1'], ['1.2'], ['abc'], ['99999999999999999999']];
    }

    #[DataProvider('invalidIds')]
    public function test_no_acepta_identificadores_invalidos(string $id): void
    {
        self::assertSame(422, $this->send(id: $id)->getStatusCode());
        $this->assertUnchanged();
    }

    public function test_propiedad_existencia_transicion_y_content_type(): void
    {
        self::assertSame(403, $this->send(id: '4')->getStatusCode());
        self::assertSame(404, $this->send(id: '999')->getStatusCode());
        self::assertSame(400, $this->send(id: '3')->getStatusCode());
        self::assertSame(422, $this->send(contentType: 'application/json')->getStatusCode());
        $this->assertUnchanged();
    }

    public function test_error_de_storage_devuelve_503_y_no_finaliza_el_paquete(): void
    {
        $storage = $this->createMock(EvidenceStorage::class);
        $storage->expects($this->once())->method('store')->willThrowException(new EvidenceStorageUnavailable());
        $response = $this->send(['estado' => 'ENTREGADO'], ['foto' => $this->photo()], storage: $storage);
        self::assertSame(503, $response->getStatusCode());
        $this->assertUnchanged();
    }

    public function test_error_de_bd_revierte_estado_y_elimina_su_foto_privada_sin_filtrar_sql(): void
    {
        $this->db->unprepared("CREATE TRIGGER fail_delivery_history BEFORE INSERT ON historial_estados BEGIN SELECT RAISE(ABORT, 'private database detail'); END");
        $response = $this->send(['estado' => 'ENTREGADO'], ['foto' => $this->photo()]);
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('private database detail', (string) $response->getBody());
        $this->assertUnchanged();
    }

    public function test_reintento_de_entrega_no_guarda_otra_foto_ni_reemplaza_la_anterior(): void
    {
        self::assertSame(200, $this->send(['estado' => 'ENTREGADO'], ['foto' => $this->photo()])->getStatusCode());
        $files = glob($this->directory . '/*');
        self::assertSame(409, $this->send(['estado' => 'ENTREGADO'], ['foto' => $this->photo()])->getStatusCode());
        self::assertSame($files, glob($this->directory . '/*'));
        self::assertSame(1, $this->db->table('historial_estados')->count());
    }

    public function test_rechaza_almacenamiento_dentro_del_directorio_publico(): void
    {
        $storage = new LocalEvidenceStorage($this->directory, $this->directory);
        self::assertSame(503, $this->send(['estado' => 'ENTREGADO'], ['foto' => $this->photo()], storage: $storage)->getStatusCode());
        $this->assertUnchanged();
    }

    public function test_storage_no_admite_borrar_claves_con_recorrido_de_directorios(): void
    {
        $this->expectException(EvidenceStorageUnavailable::class);
        $this->storage->delete('../otro-archivo.png');
    }

    private function assertUnchanged(): void
    {
        self::assertSame([3, 3, 2, 2], $this->db->table('paquetes')->orderBy('id')->pluck('estado_actual_id')->all());
        self::assertSame(0, $this->db->table('historial_estados')->count());
        self::assertSame('EN_RUTA', $this->db->table('lotes')->where('id', 210)->value('estado'));
        self::assertSame([], glob($this->directory . '/*'));
    }
}
