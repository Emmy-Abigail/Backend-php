<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Package;

use App\Domain\Package\InvalidPackageData;
use App\Domain\Package\PackageDetails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PackageFixture;

require_once __DIR__ . '/../../../Support/PackageFixture.php';

final class PackageDetailsTest extends TestCase
{
    public function test_normalizes_phone_optional_address_and_fixed_point_values(): void
    {
        $details = PackageDetails::fromArray(PackageFixture::body());
        self::assertSame('987654321', $details->sender['celular']);
        self::assertNull($details->address['manzana']);
        self::assertNull($details->address['lote']);
        self::assertSame(['peso_kg' => '3.00', 'largo_cm' => '30.0', 'ancho_cm' => '22.0', 'alto_cm' => '10.0'], $details->measurements());
    }

    public function test_accepts_ruc_and_physical_boundaries(): void
    {
        $body = PackageFixture::body();
        $body['remitente']['tipo_documento'] = 'RUC';
        $body['remitente']['numero_documento'] = '20123456789';
        $body['peso_kg'] = '0.10';
        $body['largo_cm'] = 150;
        self::assertSame(10, PackageDetails::fromArray($body)->weightUnits);
        $body['peso_kg'] = 50;
        self::assertSame(5000, PackageDetails::fromArray($body)->weightUnits);
    }

    public function test_correo_y_punto_del_mapa_son_opcionales(): void
    {
        $details = PackageDetails::fromArray(PackageFixture::body());
        self::assertNull($details->sender['correo']);
        self::assertNull($details->recipient['correo']);
        self::assertNull($details->address['lat']);
        self::assertNull($details->address['lng']);

        $body = PackageFixture::body();
        $body['remitente']['correo'] = '';
        $body['direccion']['lat'] = null;
        $body['direccion']['lng'] = null;
        self::assertNull(PackageDetails::fromArray($body)->sender['correo']);
    }

    public function test_normaliza_correos_y_coordenadas(): void
    {
        $body = PackageFixture::body();
        $body['remitente']['correo'] = '  Maria.Perez@Example.COM ';
        $body['destinatario']['correo'] = 'rosa@example.com';
        $body['direccion']['lat'] = -12.12123456789;
        $body['direccion']['lng'] = -77;
        $details = PackageDetails::fromArray($body);

        self::assertSame('maria.perez@example.com', $details->sender['correo']);
        self::assertSame('rosa@example.com', $details->recipient['correo']);
        self::assertSame('-12.121235', $details->address['lat']);
        self::assertSame('-77.000000', $details->address['lng']);
    }

    #[DataProvider('invalidContactOrLocation')]
    public function test_rechaza_correo_o_ubicacion_invalidos(array $changes, string $field): void
    {
        $body = PackageFixture::body();
        foreach ($changes as $path => $value) {
            [$group, $key] = explode('.', $path);
            $body[$group][$key] = $value;
        }
        try {
            PackageDetails::fromArray($body);
            self::fail('Invalid input was accepted');
        } catch (InvalidPackageData $exception) {
            self::assertArrayHasKey($field, $exception->errors);
        }
    }

    public static function invalidContactOrLocation(): array
    {
        return [
            'correo sin arroba' => [['remitente.correo' => 'maria.example.com'], 'remitente.correo'],
            'correo como numero' => [['destinatario.correo' => 123], 'destinatario.correo'],
            'correo muy largo' => [['destinatario.correo' => str_repeat('a', 140) . '@example.com'], 'destinatario.correo'],
            'solo latitud' => [['direccion.lat' => -12.1], 'direccion.lng'],
            'solo longitud' => [['direccion.lng' => -77.0], 'direccion.lat'],
            'latitud como texto' => [['direccion.lat' => '-12.1', 'direccion.lng' => -77.0], 'direccion.lat'],
            'longitud booleana' => [['direccion.lat' => -12.1, 'direccion.lng' => true], 'direccion.lng'],
            'fuera de Lima (Arequipa)' => [['direccion.lat' => -16.39, 'direccion.lng' => -71.53], 'direccion.lat'],
            'latitud y longitud invertidas' => [['direccion.lat' => -77.03, 'direccion.lng' => -12.12], 'direccion.lat'],
            'longitud en el mar' => [['direccion.lat' => -12.1, 'direccion.lng' => -77.5], 'direccion.lng'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_rejects_invalid_fields_without_silent_rounding(array $path, mixed $value, string $field): void
    {
        $body = PackageFixture::body();
        $target = &$body;
        foreach ($path as $part) {
            $target = &$target[$part];
        }
        $target = $value;
        try {
            PackageDetails::fromArray($body);
            self::fail('Invalid input was accepted');
        } catch (InvalidPackageData $exception) {
            self::assertArrayHasKey($field, $exception->errors);
        }
    }

    public static function invalidFields(): array
    {
        return [
            [['peso_kg'], '0.09', 'peso_kg'],
            [['peso_kg'], 50.01, 'peso_kg'],
            [['peso_kg'], true, 'peso_kg'],
            [['peso_kg'], '3.001', 'peso_kg'],
            [['peso_kg'], INF, 'peso_kg'],
            [['largo_cm'], 9.9, 'largo_cm'],
            [['ancho_cm'], 150.1, 'ancho_cm'],
            [['alto_cm'], '10.01', 'alto_cm'],
            [['remitente', 'numero_documento'], '123', 'remitente.numero_documento'],
            [['destinatario', 'dni'], 41234567, 'destinatario.dni'],
            [['destinatario', 'celular'], '812345678', 'destinatario.celular'],
            [['direccion', 'ubigeo_distrito'], '15012x', 'direccion.ubigeo_distrito'],
            [['direccion', 'nombre_via'], '', 'direccion.nombre_via'],
            [['direccion', 'numero'], str_repeat('1', 11), 'direccion.numero'],
            [['remitente'], null, 'remitente'],
            [['id_sede'], 5, 'id_sede'],
            [['costo_estimado'], 0, 'costo_estimado'],
        ];
    }
}
