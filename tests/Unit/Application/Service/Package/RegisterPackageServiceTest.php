<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service\Package;

use App\Application\Exception\AssignmentUnavailable;
use App\Application\Exception\InvalidCatalogReference;
use App\Application\Exception\PackageRegistrationConflict;
use App\Application\Exception\PricingUnavailable;
use App\Application\Exception\RegistrationSnapshotChanged;
use App\Application\Exception\UserNotAllowed;
use App\Application\Port\In\Auth\AuthenticatedUser;
use App\Application\Port\In\Package\RegisterPackageCommand;
use App\Application\Port\In\Package\RegisterPackageResult;
use App\Application\Port\Out\Assignment\AssignmentSnapshot;
use App\Application\Port\Out\Assignment\BatchAssignment;
use App\Application\Port\Out\Assignment\BatchAssignmentGateway;
use App\Application\Exception\EmailDeliveryFailed;
use App\Application\Port\Out\Notification\TrackingCodeMailer;
use App\Domain\Package\PackageDetails;
use App\Application\Port\Out\Persistence\PackageRegistrationRepository;
use App\Application\Port\Out\Pricing\PackagePricing;
use App\Application\Port\Out\Pricing\TariffRepository;
use App\Application\Port\Out\Security\TrackingGenerator;
use App\Application\Service\Package\RegisterPackageService;
use App\Infrastructure\Adapter\Out\Pricing\LocalPackagePricing;
use PHPUnit\Framework\TestCase;
use Tests\Support\FixedPackagePricing;
use Tests\Support\PackageFixture;

require_once __DIR__ . '/../../../../Support/PackageFixture.php';
require_once __DIR__ . '/../../../../Support/FixedPackagePricing.php';

final class RegisterPackageServiceTest extends TestCase
{
    public function test_missing_tariff_stops_before_python_tracking_or_writes(): void
    {
        $repository = $this->createMock(PackageRegistrationRepository::class);
        $repository->expects($this->once())->method('contextFor')->with(1, '150122')->willReturn(PackageFixture::context());
        $repository->expects($this->never())->method('snapshot');
        $repository->expects($this->never())->method('register');
        $assignment = $this->createMock(BatchAssignmentGateway::class);
        $assignment->expects($this->never())->method('evaluate');
        $tracking = $this->createMock(TrackingGenerator::class);
        $tracking->expects($this->never())->method('generate');

        $this->expectException(PricingUnavailable::class);
        (new RegisterPackageService($repository, new LocalPackagePricing($this->createStub(TariffRepository::class)), $assignment, $tracking))->execute(PackageFixture::command());
    }

    public function test_registers_with_a_quote_supplied_only_by_the_test_adapter(): void
    {
        $repository = $this->createMock(PackageRegistrationRepository::class);
        $snapshot = PackageFixture::snapshot();
        $repository->method('contextFor')->willReturn($snapshot->context);
        $repository->method('snapshot')->willReturn($snapshot);
        $repository->expects($this->once())->method('register')->willReturnCallback(
            static function ($command, $receivedSnapshot, $decision, $quote, $tracking): RegisterPackageResult {
                self::assertSame('CREAR_LOTE', $decision->action);
                self::assertSame(1, $quote->tariffId);
                self::assertSame('15.50', $quote->amount);
                self::assertSame('AB23456789', $tracking);
                return new RegisterPackageResult(10, $tracking, '2026-10-21T12:00:00+00:00', $quote, ['id' => 20]);
            },
        );
        $assignment = $this->createMock(BatchAssignmentGateway::class);
        $assignment->expects($this->once())->method('evaluate')->with($snapshot)->willReturn(new BatchAssignment('CREAR_LOTE', 1, null));
        $tracking = $this->createStub(TrackingGenerator::class);
        $tracking->method('generate')->willReturn('AB23456789');

        $result = (new RegisterPackageService($repository, new FixedPackagePricing(), $assignment, $tracking))->execute(PackageFixture::command());
        self::assertSame('EN_ALMACEN', $result->toArray()['estado']);
        self::assertSame(15.5, $result->toArray()['cotizacion']['monto']);
    }

    public function test_refreshes_the_snapshot_and_tracking_after_a_concurrent_change(): void
    {
        $repository = $this->createMock(PackageRegistrationRepository::class);
        $first = PackageFixture::snapshot();
        $second = PackageFixture::snapshot([['id' => 101, 'id_tipo_vehiculo' => 1, 'cantidad_paquetes' => 1, 'peso_total_kg' => '3.00', 'created_at' => '2026-10-21T08:00:00+00:00']]);
        $repository->expects($this->exactly(2))->method('contextFor')->willReturn($first->context);
        $repository->expects($this->exactly(2))->method('snapshot')->willReturnOnConsecutiveCalls($first, $second);
        $attempt = 0;
        $repository->expects($this->exactly(2))->method('register')->willReturnCallback(
            static function ($command, $snapshot, $assignment, $quote, $tracking) use (&$attempt): RegisterPackageResult {
                if ($attempt++ === 0) {
                    throw new RegistrationSnapshotChanged();
                }
                self::assertSame(101, $assignment->batchId);
                self::assertSame('BC23456789', $tracking);
                return new RegisterPackageResult(11, $tracking, '2026-10-21T12:00:00+00:00', $quote, ['id' => 101]);
            },
        );
        $assignment = $this->createMock(BatchAssignmentGateway::class);
        $assignment->expects($this->exactly(2))->method('evaluate')->willReturnOnConsecutiveCalls(new BatchAssignment('CREAR_LOTE', 1, null), new BatchAssignment('USAR_LOTE', 1, 101));
        $tracking = $this->createMock(TrackingGenerator::class);
        $tracking->expects($this->exactly(2))->method('generate')->willReturnOnConsecutiveCalls('AB23456789', 'BC23456789');

        self::assertSame(11, (new RegisterPackageService($repository, new FixedPackagePricing(), $assignment, $tracking))->execute(PackageFixture::command())->id);
    }

    public function test_stops_after_three_concurrent_changes(): void
    {
        $repository = $this->createMock(PackageRegistrationRepository::class);
        $repository->method('contextFor')->willReturn(PackageFixture::context());
        $repository->method('snapshot')->willReturn(PackageFixture::snapshot());
        $repository->expects($this->exactly(3))->method('register')->willThrowException(new RegistrationSnapshotChanged());
        $assignment = $this->createStub(BatchAssignmentGateway::class);
        $assignment->method('evaluate')->willReturn(new BatchAssignment('CREAR_LOTE', 1, null));
        $tracking = $this->createStub(TrackingGenerator::class);
        $tracking->method('generate')->willReturn('AB23456789');

        $this->expectException(PackageRegistrationConflict::class);
        (new RegisterPackageService($repository, new FixedPackagePricing(), $assignment, $tracking))->execute(PackageFixture::command());
    }

    public function test_rejects_an_unknown_district_before_requesting_a_quote(): void
    {
        $repository = $this->createStub(PackageRegistrationRepository::class);
        $repository->method('contextFor')->willThrowException(new InvalidCatalogReference());
        $pricing = $this->createMock(PackagePricing::class);
        $pricing->expects($this->never())->method('quote');
        $this->expectException(InvalidCatalogReference::class);
        (new RegisterPackageService($repository, $pricing, $this->createStub(BatchAssignmentGateway::class), $this->createStub(TrackingGenerator::class)))->execute(PackageFixture::command());
    }

    public function test_rejects_a_recommendation_for_a_larger_vehicle_without_writing(): void
    {
        $repository = $this->createMock(PackageRegistrationRepository::class);
        $repository->method('contextFor')->willReturn(PackageFixture::context());
        $repository->method('snapshot')->willReturn(PackageFixture::snapshot());
        $repository->expects($this->never())->method('register');
        $assignment = $this->createStub(BatchAssignmentGateway::class);
        $assignment->method('evaluate')->willReturn(new BatchAssignment('CREAR_LOTE', 2, null));
        $this->expectException(AssignmentUnavailable::class);
        (new RegisterPackageService($repository, new FixedPackagePricing(), $assignment, $this->createStub(TrackingGenerator::class)))->execute(PackageFixture::command());
    }

    /** Registro que siempre se guarda, para probar solo el envío del código. */
    private function registeringService(?TrackingCodeMailer $mailer): RegisterPackageService
    {
        $repository = $this->createStub(PackageRegistrationRepository::class);
        $snapshot = PackageFixture::snapshot();
        $repository->method('contextFor')->willReturn($snapshot->context);
        $repository->method('snapshot')->willReturn($snapshot);
        $repository->method('register')->willReturnCallback(
            static fn ($command, $snapshot, $decision, $quote, $tracking): RegisterPackageResult => new RegisterPackageResult(10, $tracking, '2026-10-21T12:00:00+00:00', $quote, ['id' => 20]),
        );
        $assignment = $this->createStub(BatchAssignmentGateway::class);
        $assignment->method('evaluate')->willReturn(new BatchAssignment('CREAR_LOTE', 1, null));
        $tracking = $this->createStub(TrackingGenerator::class);
        $tracking->method('generate')->willReturn('AB23456789');

        return new RegisterPackageService($repository, new FixedPackagePricing(), $assignment, $tracking, $mailer);
    }

    private function commandWithEmails(?string $sender, ?string $recipient): RegisterPackageCommand
    {
        $body = PackageFixture::body();
        if ($sender !== null) {
            $body['remitente']['correo'] = $sender;
        }
        if ($recipient !== null) {
            $body['destinatario']['correo'] = $recipient;
        }

        return new RegisterPackageCommand(PackageFixture::command()->operator, PackageDetails::fromArray($body));
    }

    public function test_sends_the_tracking_code_to_sender_and_recipient_after_saving(): void
    {
        $calls = [];
        $mailer = $this->createMock(TrackingCodeMailer::class);
        $mailer->expects($this->exactly(2))->method('sendTrackingCode')->willReturnCallback(
            static function (string $name, string $email, string $tracking, bool $isSender) use (&$calls): void {
                $calls[] = [$name, $email, $tracking, $isSender];
            },
        );

        $result = $this->registeringService($mailer)->execute($this->commandWithEmails('juan@example.com', 'rosa@example.com'));

        self::assertSame([
            ['Remitente de prueba', 'juan@example.com', 'AB23456789', true],
            ['Destinatario de prueba', 'rosa@example.com', 'AB23456789', false],
        ], $calls);
        self::assertSame(['remitente' => true, 'destinatario' => true], $result->toArray()['correo_enviado']);
    }

    public function test_only_writes_to_whoever_has_an_email(): void
    {
        $mailer = $this->createMock(TrackingCodeMailer::class);
        $mailer->expects($this->once())->method('sendTrackingCode')->with('Destinatario de prueba', 'rosa@example.com', 'AB23456789', false);

        $result = $this->registeringService($mailer)->execute($this->commandWithEmails(null, 'rosa@example.com'));

        self::assertSame(['remitente' => null, 'destinatario' => true], $result->toArray()['correo_enviado']);
    }

    public function test_a_failed_email_does_not_undo_the_registration(): void
    {
        $mailer = $this->createStub(TrackingCodeMailer::class);
        $mailer->method('sendTrackingCode')->willReturnCallback(static function (string $name, string $email): void {
            if ($email === 'juan@example.com') {
                throw new EmailDeliveryFailed('SMTP caido');
            }
        });

        $logged = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        try {
            $data = $this->registeringService($mailer)->execute($this->commandWithEmails('juan@example.com', 'rosa@example.com'))->toArray();
        } finally {
            ini_set('error_log', (string) $logged);
        }

        self::assertSame('AB23456789', $data['tracking_id']);
        self::assertSame(['remitente' => false, 'destinatario' => true], $data['correo_enviado']);
    }

    public function test_without_a_mailer_nothing_is_sent(): void
    {
        $data = $this->registeringService(null)->execute($this->commandWithEmails('juan@example.com', 'rosa@example.com'))->toArray();

        self::assertSame(['remitente' => null, 'destinatario' => null], $data['correo_enviado']);
    }

    public function test_authorization_is_enforced_also_at_the_use_case_boundary(): void
    {
        $repository = $this->createMock(PackageRegistrationRepository::class);
        $repository->expects($this->never())->method('contextFor');
        $command = new RegisterPackageCommand(new AuthenticatedUser(1, 'Admin', 'admin@example.invalid', 'ADMINISTRADOR'), PackageFixture::command()->details);
        $this->expectException(UserNotAllowed::class);
        (new RegisterPackageService($repository, new FixedPackagePricing(), $this->createStub(BatchAssignmentGateway::class), $this->createStub(TrackingGenerator::class)))->execute($command);
    }
}
