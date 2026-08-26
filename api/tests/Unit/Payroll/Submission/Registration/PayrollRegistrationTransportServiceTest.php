<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationTransportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

\DG\BypassFinals::allowPaths([
    '*/api/src/Repository/Payroll/PayrollSubmissionRepository.php',
    '*/api/src/Repository/Payroll/PayrollSubmissionTransportAttemptRepository.php',
    '*/api/src/Service/Payroll/Submission/Jmhz/JmhzFrozenPayloadReader.php',
    '*/api/src/Service/Payroll/Submission/Jmhz/Transport/JmhzDispatchService.php',
]);

final class PayrollRegistrationTransportServiceTest extends TestCase
{
    private const SUPPLIER = 11;
    private const SUBMISSION = 42;

    /** @return iterable<string,array{string,string,string}> */
    public static function registrations(): iterable
    {
        yield 'PREZEC P1/P2' => [
            'PREZEC26',
            'CSSZ_PREZEC',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\r\n"
                . '<PREZEC xmlns="http://schemas.cssz.cz/PREZEC/2026">'
                . '<employees><employee act="9"><comp vs="1234567890"/></employee></employees>'
                . '</PREZEC>',
        ];
        yield 'REGZEC A1' => [
            'REGZEC25',
            'CSSZ_REGZEC',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\r\n"
                . '<REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025">'
                . '<employees><employee act="1"><comp vs="1234567890"/></employee></employees>'
                . '</REGZEC>',
        ];
    }

    #[DataProvider('registrations')]
    public function testExplicitSendPassesTheFrozenBytesUnchangedToTheExistingVrepLedger(
        string $agenda,
        string $submissionClass,
        string $xml,
    ): void {
        $repository = $this->repository($agenda);
        $frozen = $this->createMock(JmhzFrozenPayloadReader::class);
        $frozen->expects(self::once())
            ->method('bytes')
            ->with(self::SUPPLIER, 'test', self::SUBMISSION)
            ->willReturn($xml);
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::once())
            ->method('send')
            ->with(
                self::SUPPLIER,
                'test',
                self::SUBMISSION,
                self::identicalTo($xml),
                '1234567890',
                'registration-click-1',
                7,
                $submissionClass,
            )
            ->willReturn(new JmhzDispatchOutcome(self::attemptRow() + [
                'request_sha256' => hash('sha256', $xml),
            ]));

        $result = (new PayrollRegistrationTransportService(
            $repository,
            $this->createStub(PayrollSubmissionTransportAttemptRepository::class),
            $frozen,
            $dispatch,
        ))->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            'registration-click-1',
            7,
        );

        self::assertSame(hash('sha256', $xml), $result['payload_sha256']);
        self::assertSame($agenda, $result['agenda_code']);
        self::assertSame($submissionClass, $result['submission_class']);
        self::assertSame('awaiting_protocol', $result['attempt']['status']);
    }

    public function testTenantAndEnvironmentMismatchNeverReachTheTransport(): void
    {
        $repository = $this->createStub(PayrollSubmissionRepository::class);
        $repository->method('findSubmission')->willReturn([
            'id' => self::SUBMISSION,
            'status' => 'ready',
            'environment' => 'production',
        ]);
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('send');
        $frozen = $this->createMock(JmhzFrozenPayloadReader::class);
        $frozen->expects(self::never())->method('bytes');
        $service = new PayrollRegistrationTransportService(
            $repository,
            $this->createStub(PayrollSubmissionTransportAttemptRepository::class),
            $frozen,
            $dispatch,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('jinému prostředí');

        $service->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            'registration-click-2',
            7,
        );
    }

    public function testForeignTenantSubmissionNeverReachesTheTransport(): void
    {
        $repository = $this->createMock(PayrollSubmissionRepository::class);
        $repository->expects(self::once())
            ->method('findSubmission')
            ->with(self::SUPPLIER, self::SUBMISSION)
            ->willReturn(null);
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('send');
        $frozen = $this->createMock(JmhzFrozenPayloadReader::class);
        $frozen->expects(self::never())->method('bytes');
        $service = new PayrollRegistrationTransportService(
            $repository,
            $this->createStub(PayrollSubmissionTransportAttemptRepository::class),
            $frozen,
            $dispatch,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('stejné firmě');

        $service->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            'foreign-tenant',
            7,
        );
    }

    public function testRegzecA2NeverReachesTheTransport(): void
    {
        $xml = '<REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025"><employees>'
            . '<employee act="2"><comp vs="1234567890"/></employee></employees></REGZEC>';
        $frozen = $this->createStub(JmhzFrozenPayloadReader::class);
        $frozen->method('bytes')->willReturn($xml);
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('send');
        $service = new PayrollRegistrationTransportService(
            $this->repository('REGZEC25'),
            $this->createStub(PayrollSubmissionTransportAttemptRepository::class),
            $frozen,
            $dispatch,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('A1');

        $service->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            'unsupported-a2',
            7,
        );
    }

    public function testIdempotencyKeyIsForwardedAndAReplayDoesNotCreateAnotherAction(): void
    {
        $xml = '<REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025"><employees>'
            . '<employee act="1"><comp vs="1234567890"/></employee></employees></REGZEC>';
        $frozen = $this->createStub(JmhzFrozenPayloadReader::class);
        $frozen->method('bytes')->willReturn($xml);
        $lookup = 0;
        $attempts = $this->createMock(PayrollSubmissionTransportAttemptRepository::class);
        $attempts->expects(self::exactly(2))
            ->method('findByIdempotencyKey')
            ->with('same-explicit-click')
            ->willReturnCallback(function () use (&$lookup, $xml): ?array {
                ++$lookup;

                return $lookup === 1 ? null : self::attemptRow() + [
                    'request_sha256' => hash('sha256', $xml),
                ];
            });
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::once())
            ->method('send')
            ->with(
                self::SUPPLIER,
                'test',
                self::SUBMISSION,
                $xml,
                '1234567890',
                'same-explicit-click',
                7,
                'CSSZ_REGZEC',
            )
            ->willReturn(new JmhzDispatchOutcome(self::attemptRow() + [
                'request_sha256' => hash('sha256', $xml),
            ]));
        $service = new PayrollRegistrationTransportService(
            $this->repository('REGZEC25'),
            $attempts,
            $frozen,
            $dispatch,
        );

        $first = $service->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            'same-explicit-click',
            7,
        );
        $replay = $service->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            'same-explicit-click',
            7,
        );

        self::assertSame($first, $replay);
    }

    public function testReplayStillWorksAfterTheSubmissionMovedPastReady(): void
    {
        $xml = '<PREZEC xmlns="http://schemas.cssz.cz/PREZEC/2026"><employees>'
            . '<employee act="9"><comp vs="1234567890"/></employee></employees></PREZEC>';
        $frozen = $this->createStub(JmhzFrozenPayloadReader::class);
        $frozen->method('bytes')->willReturn($xml);
        $attempts = $this->createMock(
            PayrollSubmissionTransportAttemptRepository::class,
        );
        $attempts->expects(self::once())->method('findByIdempotencyKey')
            ->with('accountant-click-replay')
            ->willReturn(self::attemptRow() + [
                'request_sha256' => hash('sha256', $xml),
            ]);
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('send');

        $result = (new PayrollRegistrationTransportService(
            $this->repository('PREZEC26', 'submitted'),
            $attempts,
            $frozen,
            $dispatch,
        ))->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            'accountant-click-replay',
            7,
        );

        self::assertSame(5, $result['attempt']['id']);
        self::assertSame(hash('sha256', $xml), $result['payload_sha256']);
    }

    public function testCompletedAttemptCannotBePolledAgain(): void
    {
        $attempts = $this->createStub(PayrollSubmissionTransportAttemptRepository::class);
        $attempts->method('find')->willReturn(array_replace(self::attemptRow(), [
            'status' => 'completed',
        ]));
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('poll');
        $frozen = $this->createMock(JmhzFrozenPayloadReader::class);
        $frozen->expects(self::never())->method('bytes');
        $service = new PayrollRegistrationTransportService(
            $this->createStub(PayrollSubmissionRepository::class),
            $attempts,
            $frozen,
            $dispatch,
        );

        $this->expectException(JmhzTransportException::class);
        $this->expectExceptionMessage('čeká na protokol');

        $service->poll(self::SUPPLIER, 'test', 5);
    }

    public function testAnotherKeyCannotResendAnAlreadySubmittedRegistration(): void
    {
        $xml = '<PREZEC xmlns="http://schemas.cssz.cz/PREZEC/2026"><employees>'
            . '<employee act="9"><comp vs="1234567890"/></employee></employees></PREZEC>';
        $frozen = $this->createStub(JmhzFrozenPayloadReader::class);
        $frozen->method('bytes')->willReturn($xml);
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('send');
        $service = new PayrollRegistrationTransportService(
            $this->repository('PREZEC26', 'submitted'),
            $this->createStub(PayrollSubmissionTransportAttemptRepository::class),
            $frozen,
            $dispatch,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('už bylo odesláno');

        $service->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            'different-click',
            7,
        );
    }

    private function repository(
        string $agenda,
        string $status = 'ready',
    ): PayrollSubmissionRepository
    {
        $repository = $this->createStub(PayrollSubmissionRepository::class);
        $repository->method('findSubmission')->willReturn([
            'id' => self::SUBMISSION,
            'status' => $status,
            'environment' => 'test',
        ]);
        $repository->method('findObligationOfSubmission')->willReturn([
            'agenda_code' => $agenda,
            'subject_type' => 'employment',
            'subject_reference' => 'employment:9',
        ]);

        return $repository;
    }

    /** @return array<string,mixed> */
    private static function attemptRow(): array
    {
        return [
            'id' => 5,
            'supplier_id' => self::SUPPLIER,
            'environment' => 'test',
            'submission_id' => self::SUBMISSION,
            'channel' => 'vrep_apep',
            'status' => 'awaiting_protocol',
            'correlation_reference' => 'CID-1',
        ];
    }
}
