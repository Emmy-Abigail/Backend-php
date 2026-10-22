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
