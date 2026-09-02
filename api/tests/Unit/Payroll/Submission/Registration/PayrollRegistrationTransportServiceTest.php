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
        yield 'REGZEC A2' => [
            'REGZEC25',
            'CSSZ_REGZEC',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\r\n"
                . '<REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025">'
                . '<employees><employee act="2"><comp vs="1234567890"/></employee></employees>'
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
        // Hláška musí říct, CO je špatně (záměna testovacího a ostrého
        // prostředí) i CO s tím dělat. „Patří jinému prostředí" neřeklo ani
        // jedno.
        $this->expectExceptionMessage(
            'Registrační podání bylo připraveno pro jiné prostředí '
            . '(testovací × ostré), než ze kterého se teď odesílá. '
            . 'Přepněte prostředí zpět na to, ve kterém podání vzniklo.',
        );

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
        $this->expectExceptionMessage(
            'Registrační podání pod tímhle číslem u téhle firmy neexistuje. '
            . 'Otevřete kartu zaměstnance znovu a odeslání spusťte ze '
            . 'seznamu jejích podání.',
        );

        $service->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            'foreign-tenant',
            7,
        );
    }

    public function testUnknownRegzecActionNeverReachesTheTransport(): void
    {
        $xml = '<REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025"><employees>'
            . '<employee act="9"><comp vs="1234567890"/></employee></employees></REGZEC>';
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
        // Kód akce sám o sobě účetní nic neříká, takže hláška musí druh
        // podání pojmenovat lidsky ze sdíleného slovníku.
        $this->expectExceptionMessage(
            'Druh registračního podání není podporovaný pro odeslání '
            . 'na ČSSZ (Podání REGZEC25 s kódem akce 9).',
        );

        $service->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            'unsupported-a2',
            7,
        );
    }

    /**
     * Variabilní symbol je jediné, čím ČSSZ pozná zaměstnavatele. Když
     * v podání není, musí hláška ten údaj pojmenovat lidsky a poslat účetní
     * na konkrétní obrazovku — technický název smí být jen v závorce.
     */
    public function testMissingEmployerVariableSymbolNamesTheFieldAndThePlaceToFixIt(): void
    {
        $xml = '<PREZEC xmlns="http://schemas.cssz.cz/PREZEC/2026"><employees>'
            . '<employee act="9"><comp/></employee></employees></PREZEC>';
        $frozen = $this->createStub(JmhzFrozenPayloadReader::class);
        $frozen->method('bytes')->willReturn($xml);
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('send');
        $service = new PayrollRegistrationTransportService(
            $this->repository('PREZEC26'),
            $this->createStub(PayrollSubmissionTransportAttemptRepository::class),
            $frozen,
            $dispatch,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Variabilní symbol zaměstnavatele u ČSSZ musí být v celém podání '
            . 'jediný, ale připravené podání jich obsahuje víc, nebo žádný. '
            . 'Údaj doplňte na Mzdy → Nastavení mezd → Zaměstnavatel '
            . 'a účtárny — v tomhle formuláři se nezadává. Potom registraci '
            . 'připravte znovu (employer_variable_symbol).',
        );

        $service->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            'missing-vs',
            7,
        );
    }

    /** Špatný tvar musí říct, jaký tvar se čeká, a ukázat, co v podání je. */
    public function testMalformedEmployerVariableSymbolShowsTheExpectedShape(): void
    {
        $xml = '<PREZEC xmlns="http://schemas.cssz.cz/PREZEC/2026"><employees>'
            . '<employee act="9"><comp vs="12345"/></employee></employees></PREZEC>';
        $frozen = $this->createStub(JmhzFrozenPayloadReader::class);
        $frozen->method('bytes')->willReturn($xml);
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('send');
        $service = new PayrollRegistrationTransportService(
            $this->repository('PREZEC26'),
            $this->createStub(PayrollSubmissionTransportAttemptRepository::class),
            $frozen,
            $dispatch,
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            'Variabilní symbol zaměstnavatele u ČSSZ musí mít přesně deset '
            . 'číslic, ale v připraveném podání je „12345". Údaj doplňte na '
            . 'Mzdy → Nastavení mezd → Zaměstnavatel a účtárny — v tomhle '
            . 'formuláři se nezadává. Potom registraci připravte znovu '
            . '(employer_variable_symbol).',
        );

        $service->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            'short-vs',
            7,
        );
    }

    /**
     * Podání, které patří do jiné agendy, se nesmí odeslat registrační
     * cestou — a účetní musí z hlášky poznat, kam s ním jít.
     */
    public function testNonRegistrationSubmissionIsRejectedWithARoute(): void
    {
        $repository = $this->createStub(PayrollSubmissionRepository::class);
        $repository->method('findSubmission')->willReturn([
            'id' => self::SUBMISSION,
            'status' => 'ready',
            'environment' => 'test',
        ]);
        $repository->method('findObligationOfSubmission')->willReturn([
            'agenda_code' => 'JMHZ25',
            'subject_type' => 'employer',
            'subject_reference' => 'payroll_employer:11',
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
        $this->expectExceptionMessage(
            'Tohle podání není přihláška ani odhláška zaměstnance u ČSSZ, '
            . 'takže se touhle cestou odeslat nedá. Odešlete podání '
            . 'z obrazovky Mzdy → Podání a hlášení, kde vzniklo.',
        );

        $service->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            'foreign-agenda',
            7,
        );
    }

    public function testIncompleteRegzecA1NeverReachesTheTransport(): void
    {
        $xml = '<REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025"><employees>'
            . '<employee act="1"><comp vs="1234567890"/></employee></employees></REGZEC>';
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
        // Hláška musí pojmenovat CHYBĚJÍCÍ ÚDAJ lidsky a poslat účetní na
        // místo, kde se zadává — ne jen konstatovat, že podklad není úplný.
        $this->expectExceptionMessage(
            'Druh činnosti pro ČSSZ chybí',
        );

        $service->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            'incomplete-a1',
            7,
        );
    }

    public function testIdempotencyKeyIsForwardedAndAReplayDoesNotCreateAnotherAction(): void
    {
        $xml = '<REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025"><employees>'
            . '<employee act="2"><comp vs="1234567890"/></employee></employees></REGZEC>';
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
        $this->expectExceptionMessage(
            'Odpověď ČSSZ se dá zjistit jen u odeslání, které na ni teprve '
            . 'čeká. Tohle odeslání už je uzavřené — jak dopadlo, najdete '
            . 'v historii odeslání.',
        );

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
        $this->expectExceptionMessage(
            'Registrační podání na ČSSZ už bylo odesláno, takže se podruhé '
            . 'neodesílá. Jak podání ČSSZ vyřídila, zjistíte v historii '
            . 'odeslání u téhož podání.',
        );

        $service->send(
            self::SUPPLIER,
            'test',
            self::SUBMISSION,
            'different-click',
            7,
        );
    }

    public function testStatusReturnsTheLatestAttemptWithoutContactingCssz(): void
    {
        $attempts = $this->createMock(PayrollSubmissionTransportAttemptRepository::class);
        $attempts->expects(self::once())
            ->method('listForSubmission')
            ->with(self::SUPPLIER, 'test', self::SUBMISSION)
            ->willReturn([
                self::attemptRow() + ['attempt_no' => 1],
                array_replace(self::attemptRow(), [
                    'id' => 6,
                    'attempt_no' => 2,
                    'status' => 'completed',
                ]),
            ]);
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('poll');
        $dispatch->expects(self::never())->method('send');

        $result = (new PayrollRegistrationTransportService(
            $this->repository('PREZEC26', 'accepted'),
            $attempts,
            $this->createStub(JmhzFrozenPayloadReader::class),
            $dispatch,
        ))->status(self::SUPPLIER, 'test', self::SUBMISSION);

        self::assertSame('PREZEC26', $result['agenda_code']);
        self::assertSame('CSSZ_PREZEC', $result['submission_class']);
        self::assertSame(6, $result['attempt']['id']);
        self::assertSame('completed', $result['attempt']['status']);
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
