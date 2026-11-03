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
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

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
            self::clock(),
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
            self::clock(),
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
            self::clock(),
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
            self::clock(),
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
            self::clock(),
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
            self::clock(),
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
            self::clock(),
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
            self::clock(),
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

    /**
     * Zkušební podání do TEST ČSSZ (8. 10. 2026): plná přihláška A1 se nedala
     * odeslat vůbec, protože brána posílala do business matice místo druhu
     * činnosti `null`. Druh činnosti i bližší určení jsou ve zmrazené větě.
     */
    public function testCompleteRegzecA1ReachesTheTransportWithActivityFromTheFrozenSentence(): void
    {
        $xml = self::regzecA1Xml();
        $frozen = $this->createStub(JmhzFrozenPayloadReader::class);
        $frozen->method('bytes')->willReturn($xml);
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::once())
            ->method('send')
            ->with(
                self::SUPPLIER,
                'test',
                self::SUBMISSION,
                self::identicalTo($xml),
                '1234567890',
                'complete-a1',
                7,
                'CSSZ_REGZEC',
            )
            ->willReturn(new JmhzDispatchOutcome(self::attemptRow() + [
                'request_sha256' => hash('sha256', $xml),
            ]));

        $result = (new PayrollRegistrationTransportService(
            $this->repository('REGZEC25'),
            $this->createStub(PayrollSubmissionTransportAttemptRepository::class),
            $frozen,
            $dispatch,
            self::clock(),
        ))->send(self::SUPPLIER, 'test', self::SUBMISSION, 'complete-a1', 7);

        self::assertSame('CSSZ_REGZEC', $result['submission_class']);
    }

    /**
     * REGZEC27 (události od 1. 1. 2027) patří pod tutéž agendu REGZEC25 a jde
     * stejnou bránou; ročník `eType` obálky určí transport z jmenného prostoru.
     * Jiný jmenný prostor (tady PREZEC v agendě REGZEC) brána dál odmítá.
     */
    public function testRegzec27SentenceReachesTheTransportUnderTheSameAgenda(): void
    {
        $xml = str_replace('REGZEC/2025', 'REGZEC/2027', self::regzecA1Xml());
        $frozen = $this->createStub(JmhzFrozenPayloadReader::class);
        $frozen->method('bytes')->willReturn($xml);
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::once())
            ->method('send')
            ->with(
                self::SUPPLIER,
                'test',
                self::SUBMISSION,
                self::identicalTo($xml),
                '1234567890',
                'regzec27-a1',
                7,
                'CSSZ_REGZEC',
            )
            ->willReturn(new JmhzDispatchOutcome(self::attemptRow() + [
                'request_sha256' => hash('sha256', $xml),
            ]));

        (new PayrollRegistrationTransportService(
            $this->repository('REGZEC25'),
            $this->createStub(PayrollSubmissionTransportAttemptRepository::class),
            $frozen,
            $dispatch,
            self::clock(),
        ))->send(self::SUPPLIER, 'test', self::SUBMISSION, 'regzec27-a1', 7);

        $wrong = $this->createStub(JmhzFrozenPayloadReader::class);
        $wrong->method('bytes')->willReturn(str_replace('REGZEC/2025', 'PREZEC/2026', self::regzecA1Xml()));
        $this->expectException(\DomainException::class);
        (new PayrollRegistrationTransportService(
            $this->repository('REGZEC25'),
            $this->createStub(PayrollSubmissionTransportAttemptRepository::class),
            $wrong,
            $this->createStub(JmhzDispatchService::class),
            self::clock(),
        ))->send(self::SUPPLIER, 'test', self::SUBMISSION, 'regzec27-wrong', 7);
    }

    /** Druh činnosti, který matice A1 nepřipouští, se pořád neodešle. */
    public function testRegzecA1WithUnknownActivityNeverReachesTheTransport(): void
    {
        $frozen = $this->createStub(JmhzFrozenPayloadReader::class);
        $frozen->method('bytes')->willReturn(self::regzecA1Xml('XX'));
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('send');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('„XX" není v číselníku ČSSZ');

        (new PayrollRegistrationTransportService(
            $this->repository('REGZEC25'),
            $this->createStub(PayrollSubmissionTransportAttemptRepository::class),
            $frozen,
            $dispatch,
            self::clock(),
        ))->send(self::SUPPLIER, 'test', self::SUBMISSION, 'unknown-activity', 7);
    }

    /**
     * Dotaz na výsledek se ptá na podání, které ČSSZ už má. Brána odeslání
     * ho zastavit nesmí, jinak pokus zůstane navždy čekat na protokol.
     */
    public function testRegzecA1PollAndCloseDoNotRunTheSendGate(): void
    {
        $xml = '<REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025"><employees>'
            . '<employee act="1"><comp vs="1234567890"/></employee></employees></REGZEC>';
        $frozen = $this->createStub(JmhzFrozenPayloadReader::class);
        $frozen->method('bytes')->willReturn($xml);
        $attempts = $this->createStub(PayrollSubmissionTransportAttemptRepository::class);
        $attempts->method('find')->willReturn(self::attemptRow());
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::once())
            ->method('poll')
            ->with(self::SUPPLIER, 'test', 5, '1234567890', 1, 'CSSZ_REGZEC')
            ->willReturn(new JmhzDispatchOutcome(self::attemptRow()));
        $dispatch->expects(self::once())
            ->method('close')
            ->with(self::SUPPLIER, 'test', 5, '1234567890', 'CSSZ_REGZEC')
            ->willReturn([
                'closed' => true,
                'already_closed' => false,
                'attempt' => self::attemptRow(),
            ]);
        $service = new PayrollRegistrationTransportService(
            $this->repository('REGZEC25', 'submitted'),
            $attempts,
            $frozen,
            $dispatch,
            self::clock(),
        );

        $service->poll(self::SUPPLIER, 'test', 5);
        $closed = $service->close(self::SUPPLIER, 'test', 5);

        self::assertTrue($closed['closed']);
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
            self::clock(),
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
            self::clock(),
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
            self::clock(),
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
            self::clock(),
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
            self::clock(),
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

    /**
     * Změna A3 připravená jako REGZEC25 bez data skončení: od 23. 12. 2026
     * ji ČSSZ přijme jen jako REGZEC27 (odpovědi 4, 5 a 13 k NV2027), proto
     * se neodešle a účetní ji připraví znovu.
     */
    public function testStaleRegzec25ChangeWithoutEndIsNotSentFrom23December(): void
    {
        $frozen = $this->createStub(JmhzFrozenPayloadReader::class);
        $frozen->method('bytes')->willReturn(self::regzecChangeXml(null));
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::never())->method('send');

        try {
            (new PayrollRegistrationTransportService(
                $this->repository('REGZEC25'),
                $this->createStub(PayrollSubmissionTransportAttemptRepository::class),
                $frozen,
                $dispatch,
                self::clock('2026-12-23'),
            ))->send(self::SUPPLIER, 'test', self::SUBMISSION, 'stale-a3', 7);
            self::fail('Zastaralá změna REGZEC25 se nesmí odeslat.');
        } catch (JmhzTransportException $exception) {
            self::assertSame('registration_regzec25_change_stale', $exception->errorCode);
            self::assertStringContainsString('Připravte ho znovu', $exception->getMessage());
        }
    }

    /** Den před otevřením příjmu REGZEC27 a změna s 10224 odejdou beze změny. */
    #[DataProvider('acceptedChanges')]
    public function testRegzec25ChangeIsSentBefore23DecemberOrWithEndDate(?string $endOn, string $today): void
    {
        $xml = self::regzecChangeXml($endOn);
        $frozen = $this->createStub(JmhzFrozenPayloadReader::class);
        $frozen->method('bytes')->willReturn($xml);
        $dispatch = $this->createMock(JmhzDispatchService::class);
        $dispatch->expects(self::once())
            ->method('send')
            ->willReturn(new JmhzDispatchOutcome(self::attemptRow() + [
                'request_sha256' => hash('sha256', $xml),
            ]));

        $result = (new PayrollRegistrationTransportService(
            $this->repository('REGZEC25'),
            $this->createStub(PayrollSubmissionTransportAttemptRepository::class),
            $frozen,
            $dispatch,
            self::clock($today),
        ))->send(self::SUPPLIER, 'test', self::SUBMISSION, 'fresh-a3', 7);

        self::assertSame(hash('sha256', $xml), $result['payload_sha256']);
    }

    /** @return iterable<string,array{?string,string}> */
    public static function acceptedChanges(): iterable
    {
        yield 'bez 10224 22. 12. 2026' => [null, '2026-12-22'];
        yield 'skončený vztah v roce 2026, odesláno 2027' => ['2026-11-30', '2027-01-05'];
    }

    private static function regzecChangeXml(?string $endOn): string
    {
        return '<REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025"><employees>'
            . '<employee sqnr="1" act="3" fro="2026-12-01"><comp vs="1234567890"/>'
            . '<job oid="1" rel="1" relDetail="1"' . ($endOn === null ? '' : ' to="' . $endOn . '"') . '/>'
            . '</employee></employees></REGZEC>';
    }

    private static function clock(string $today = '2026-11-04'): ClockInterface
    {
        return new MockClock(new \DateTimeImmutable($today . ' 12:00:00', new \DateTimeZone('Europe/Prague')));
    }

    private static function regzecA1Xml(string $activity = '1'): string
    {
        return '<REGZEC xmlns="http://schemas.cssz.cz/REGZEC/2025"><employees>'
            . '<employee sqnr="1" act="1"><comp vs="1234567890"/>'
            . '<job fro="2026-10-05" rel="' . $activity . '" relDetail="1"/>'
            . '</employee></employees></REGZEC>';
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
