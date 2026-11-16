<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Adapter\Out\Security;

use App\Application\Exception\CaptchaUnavailable;
use App\Infrastructure\Adapter\Out\Http\HttpResponse;
use App\Infrastructure\Adapter\Out\Http\HttpTransport;
use App\Infrastructure\Adapter\Out\Security\GoogleRecaptchaVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GoogleRecaptchaVerifierTest extends TestCase
{
    #[Test]
    public function envia_la_clave_secreta_y_el_token_como_formulario_a_siteverify(): void
    {
        $transport = $this->createMock(HttpTransport::class);
        $transport->expects($this->once())
            ->method('postForm')
            ->with(
                'https://www.google.com/recaptcha/api/siteverify',
                ['secret' => 'clave-secreta', 'response' => 'token-del-front'],
                4.0,
            )
            ->willReturn(new HttpResponse(200, '{"success": true, "hostname": "riva-envios.vercel.app"}'));

        self::assertTrue((new GoogleRecaptchaVerifier($transport, 'clave-secreta', 4.0))->verify('token-del-front'));
    }

    /** @return array<string, array{string}> */
    public static function rejectedBodies(): array
    {
        return [
            'success false' => ['{"success": false, "error-codes": ["invalid-input-response"]}'],
            'success como texto' => ['{"success": "true"}'],
            'sin success' => ['{}'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedBodies')]
    public function rechaza_si_google_no_confirma_el_exito(string $body): void
    {
        $transport = $this->createStub(HttpTransport::class);
        $transport->method('postForm')->willReturn(new HttpResponse(200, $body));

        self::assertFalse((new GoogleRecaptchaVerifier($transport, 'clave-secreta'))->verify('token'));
    }

    /** @return array<string, array{HttpResponse|null}> */
    public static function unavailableResponses(): array
    {
        return [
            'error de red' => [null],
            'error 500' => [new HttpResponse(500, 'error')],
            'json roto' => [new HttpResponse(200, '<html>')],
        ];
    }

    #[Test]
    #[DataProvider('unavailableResponses')]
    public function si_google_falla_lanza_captcha_no_disponible_sin_filtrar_la_clave(?HttpResponse $response): void
    {
        $transport = $this->createStub(HttpTransport::class);
        if ($response === null) {
            $transport->method('postForm')->willThrowException(new RuntimeException('fallo con clave-secreta'));
        } else {
            $transport->method('postForm')->willReturn($response);
        }

        try {
            (new GoogleRecaptchaVerifier($transport, 'clave-secreta'))->verify('token');
            self::fail('Debio lanzar CaptchaUnavailable');
        } catch (CaptchaUnavailable $exception) {
            self::assertStringNotContainsString('clave-secreta', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }
}
