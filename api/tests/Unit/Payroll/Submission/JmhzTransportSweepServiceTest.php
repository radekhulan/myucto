<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Repository\Payroll\PayrollSigningProfileRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollSubmissionTransportAttemptRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenPayloadReader;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzDispatchService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzPollSchedule;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzSoftwareIdentification;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportSweepService;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzVrepClient;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Signing\PersonalCertificateVaultService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Dotažení protokolu a uzavření transakce BEZ UŽIVATELE.
 *
 * Testuje se proti falešnému VREP (Guzzle MockHandler) a mockovanému ledgeru;
 * SÍŤ SE NIKDY NEPOUŽIJE. Vzorky odpovědí jsou tytéž, jaké vrací testovací
 * prostředí ČSSZ.
 */
final class JmhzTransportSweepServiceTest extends TestCase
{
    private const SUPPLIER = 11;
    private const SUBMISSION = 42;
    private const ATTEMPT = 7;
    private const OBLIGATION = 91;
    private const CORRELATION = 'CCCC9999DDDD0000EEEE1111FFFF2222';

    /** @var list<array<string,mixed>> */
    private array $history = [];

    /**
     * Řádky, které ledger postupně vrací z `find()`. Napodobuje skutečnost:
     * dotaz na stav si pokus načte v jednom stavu, uzavření transakce už
     * v tom následujícím.
     *
     * @var list<array<string,mixed>>
     */
    private array $ledgerRows = [];

    protected function setUp(): void
    {
        $this->history = [];
        $this->ledgerRows = [];
    }

    /**
     * Protokol dorazí napoprvé: pokus se uzavře jako dotažený a hned nato se
     * uzavře i transakce u VREP — podací protokol to vyžaduje a uživatel na to
     * nesmí být odkázaný.
     */
    public function testProtocolOnTheFirstSweepCompletesTheAttemptAndClosesTheTransaction(): void
    {
        $attempts = $this->attempts();
        $attempts->method('listDuePolls')->willReturn([self::sentRow()]);
        $attempts->method('listDueCloses')->willReturn([]);
        $attempts->method('recordPoll')->willReturn(self::sentRow([
            'poll_count' => 1,
            'row_version' => 2,
        ]));
        $attempts->expects(self::once())->method('markCompleted')
            ->willReturn(self::completedRow());
        // Uzavření se dohledává znovu z ledgeru, aby se pracovalo s čerstvou
        // verzí řádku, ne s tou, se kterou se začínalo.
        $this->ledgerRows = [self::sentRow(), self::completedRow(), self::completedRow()];
        $attempts->expects(self::once())->method('markClosed')
            ->willReturn(self::completedRow(['closed_at' => '2026-08-15 09:00:00']));
        $attempts->expects(self::never())->method('markExpired');

        $result = $this->sweep($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], self::protocol()),
            new Response(200, ['Content-Type' => 'text/xml'], '<GovTalkMessage/>'),
        ])->run();

        self::assertSame(1, $result['polled']);
        self::assertSame(1, $result['completed']);
        self::assertSame(1, $result['closed']);
        self::assertSame(0, $result['expired']);
        self::assertSame(0, $result['errors']);
        self::assertCount(2, $this->history);
    }

    /**
     * Zpracování ještě běží. Pokus zůstává otevřený, dostane nový termín podle
     * intervalu, který si řekla brána, a NIC se neuzavírá — potvrzení převzetí
     * není výsledek.
     */
    public function testStillProcessingSchedulesAnotherAskAndSettlesNothing(): void
    {
        $attempts = $this->attempts();
        $attempts->method('listDuePolls')->willReturn([self::sentRow()]);
        $attempts->method('listDueCloses')->willReturn([]);
        $this->ledgerRows = [self::sentRow()];
        $attempts->expects(self::once())->method('recordPoll')
            ->with(
                self::ATTEMPT,
                self::callback(static fn (mixed $value): bool => is_string($value)),
                null,
                1,
            )
            ->willReturn(self::sentRow(['poll_count' => 1, 'row_version' => 2]));
        $attempts->expects(self::never())->method('markCompleted');
        $attempts->expects(self::never())->method('markClosed');
        $attempts->expects(self::never())->method('markExpired');

        $result = $this->sweep($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], self::acknowledgement()),
        ])->run();

        self::assertSame(1, $result['polled']);
        self::assertSame(1, $result['pending']);
        self::assertSame(0, $result['completed']);
        self::assertSame(0, $result['closed']);
    }

    /**
     * Nesrozumitelná odpověď NEUZAVŘE nic. Prázdná nebo neznámá zpráva není
     * „nic tu není", ale „nevíme" — a vydávat ji za vyřízené podání je přesně
     * ta záměna, po které uživatel přestane sledovat výsledek.
     */
    public function testUnintelligibleAnswerSettlesNothingAndKeepsTheReasonInTheLedger(): void
    {
        $attempts = $this->attempts();
        $attempts->method('listDuePolls')->willReturn([self::sentRow()]);
        $attempts->method('listDueCloses')->willReturn([]);
        $this->ledgerRows = [self::sentRow()];
        $recorded = null;
        $attempts->expects(self::once())->method('recordPoll')
            ->willReturnCallback(function (
                int $id,
                ?string $next,
                ?string $error,
                int $expectedVersion,
            ) use (&$recorded): array {
                $recorded = $error;

                return self::sentRow(['poll_count' => 1, 'row_version' => 2]);
            });
        $attempts->expects(self::never())->method('markCompleted');
        $attempts->expects(self::never())->method('markClosed');

        $result = $this->sweep($attempts, [
            new Response(200, ['Content-Type' => 'text/xml'], '<GovTalkMessage/>'),
        ])->run();

        self::assertSame(1, $result['errors']);
        self::assertSame(0, $result['completed']);
        self::assertSame(0, $result['closed']);
        self::assertIsString($recorded);
        self::assertNotSame('', $recorded);
    }

    /**
     * Po stropu se to vzdá — ale NAHLAS: pokus skončí jako `expired` s větou,
     * podle které se dá jednat, a povinnost se překlopí do `manual_review`,
     * takže z ní inbox podání udělá položku k ruční kontrole.
     */
    public function testExhaustedAttemptExpiresAndRaisesTheSubmissionInbox(): void
    {
        $attempts = $this->attempts();
        $attempts->method('listDuePolls')->willReturn([
            self::sentRow(['sent_at' => '2026-08-01 08:00:00']),
        ]);
        $attempts->method('listDueCloses')->willReturn([]);
        $attempts->expects(self::never())->method('recordPoll');
        $attempts->expects(self::never())->method('markCompleted');
        $expiry = null;
        $attempts->expects(self::once())->method('markExpired')
            ->willReturnCallback(function (
                int $id,
                string $code,
                string $message,
                int $expectedVersion,
            ) use (&$expiry): array {
                $expiry = ['code' => $code, 'message' => $message];

                return self::sentRow(['status' => 'expired', 'row_version' => 2]);
            });

        $submissionRepository = $this->submissionRepository();
        $submissionRepository->expects(self::once())->method('updateObligationStatus')
            ->with(self::SUPPLIER, 'test', self::OBLIGATION, 4, 'manual_review');

        $result = $this->sweep($attempts, [], $submissionRepository)->run();

        self::assertSame(1, $result['expired']);
        self::assertSame(0, $result['polled']);
        self::assertSame([], $this->history, 'Vzdaný pokus se u ČSSZ už nemá na co ptát.');
        self::assertIsArray($expiry);
        self::assertSame('jmhz_protocol_not_delivered', $expiry['code']);
        self::assertStringContainsString('ePortálu ČSSZ', $expiry['message']);
    }

    /**
     * Druhý běh nad týmž ledgerem nesmí založit druhý pokus ani druhé uzavření.
     * Fronta bere jen řádky, kterým dozrál termín — po prvním průchodu tam
     * dotažený a uzavřený pokus prostě není.
     */
    public function testSecondSweepOverAnAlreadySettledLedgerDoesNothing(): void
    {
        $attempts = $this->attempts();
        $attempts->method('listDuePolls')->willReturn([]);
        $attempts->method('listDueCloses')->willReturn([]);
        $attempts->expects(self::never())->method('recordPoll');
        $attempts->expects(self::never())->method('markCompleted');
        $attempts->expects(self::never())->method('markClosed');
        $attempts->expects(self::never())->method('markExpired');

        $result = $this->sweep($attempts, [])->run();

        self::assertSame(
            ['polled' => 0, 'completed' => 0, 'pending' => 0, 'expired' => 0,
                'closed' => 0, 'close_failed' => 0, 'errors' => 0],
            $result,
        );
        self::assertSame([], $this->history);
    }

    /**
     * Uzavřený pokus se ve frontě na uzavření objevit nemá, ale kdyby ho tam
     * souběžný běh přesto podal, druhé uzavření se NEODEŠLE.
     */
    public function testAlreadyClosedAttemptIsNotClosedTwice(): void
    {
        $attempts = $this->attempts();
        $attempts->method('listDuePolls')->willReturn([]);
        $attempts->method('listDueCloses')->willReturn([
            self::completedRow(['closed_at' => '2026-08-15 09:00:00']),
        ]);
        $attempts->expects(self::never())->method('markClosed');

        $result = $this->sweep($attempts, [])->run();

        self::assertSame(0, $result['closed']);
        self::assertSame([], $this->history);
    }

    /**
     * Neúspěšné uzavření se nesmí tvářit jako uzavřené a po vyčerpání stropu
     * musí skončit na stole u člověka — tlačítko v UI zůstává právě pro tenhle
     * případ.
     */
    public function testCloseFailureAtTheCapEscalatesInsteadOfPretendingSuccess(): void
    {
        $attempts = $this->attempts();
        $attempts->method('listDuePolls')->willReturn([]);
        $attempts->method('listDueCloses')->willReturn([
            self::completedRow(['close_attempts' => JmhzPollSchedule::MAX_CLOSE_ATTEMPTS - 1]),
        ]);
        $this->ledgerRows = [
            self::completedRow(['close_attempts' => JmhzPollSchedule::MAX_CLOSE_ATTEMPTS - 1]),
        ];
        $attempts->expects(self::never())->method('markClosed');
        $attempts->expects(self::once())->method('recordCloseFailure')
            ->willReturn(self::completedRow([
                'close_attempts' => JmhzPollSchedule::MAX_CLOSE_ATTEMPTS,
                'row_version' => 3,
            ]));

        $submissionRepository = $this->submissionRepository();
        $submissionRepository->expects(self::once())->method('updateObligationStatus');

        $result = $this->sweep($attempts, [
            new Response(503, ['Content-Type' => 'text/plain'], 'service unavailable'),
        ], $submissionRepository)->run();

        self::assertSame(0, $result['closed']);
        self::assertSame(1, $result['close_failed']);
    }

    /**
     * @param list<Response> $queue
     * @param (MockObject&PayrollSubmissionRepository)|null $submissionRepository
     */
    private function sweep(
        PayrollSubmissionTransportAttemptRepository $attempts,
        array $queue,
        ?PayrollSubmissionRepository $submissionRepository = null,
    ): JmhzTransportSweepService {
        if ($submissionRepository === null) {
            $submissionRepository = $this->submissionRepository();
            // Bez vyčerpaného stropu se do inboxu NIC neeskaluje: běžný průchod
            // frontou nesmí povinnost překlopit do ruční kontroly.
            $submissionRepository->expects(self::never())
                ->method('updateObligationStatus');
        }

        $submissions = $this->createStub(PayrollSubmissionService::class);
        $submissions->method('artifactBytes')->willReturn(self::frozenPayload());

        $profiles = $this->createStub(PayrollSigningProfileRepository::class);
        $profiles->method('find')->willReturn(null);

        $dispatch = new JmhzDispatchService(
            $attempts,
            $profiles,
            $this->createStub(PersonalCertificateVaultService::class),
            $this->createStub(SecretEncryption::class),
            new JmhzSoftwareIdentification('MyUcto', '1.0'),
            $this->vrep($queue),
        );

        return new JmhzTransportSweepService(
            $attempts,
            $submissionRepository,
            new JmhzFrozenPayloadReader($submissionRepository, $submissions),
            $dispatch,
        );
    }

    /** @return MockObject&PayrollSubmissionRepository */
    private function submissionRepository(): MockObject
    {
        $repository = $this->createMock(PayrollSubmissionRepository::class);
        $repository->method('findOutboundXmlArtifactId')->willReturn(501);
        $repository->method('findObligationOfSubmission')->willReturn([
            'id' => self::OBLIGATION,
            'status' => 'submitted',
            'row_version' => 4,
            'agenda_code' => 'JMHZ25',
            'subject_type' => 'payroll_run',
            'subject_reference' => 'payroll_run:5',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);

        return $repository;
    }

    /** @return MockObject&PayrollSubmissionTransportAttemptRepository */
    private function attempts(): MockObject
    {
        $attempts = $this->createMock(PayrollSubmissionTransportAttemptRepository::class);
        $attempts->method('isAvailable')->willReturn(true);
        $attempts->method('find')->willReturnCallback(function (): ?array {
            if ($this->ledgerRows === []) {
                return null;
            }

            return count($this->ledgerRows) === 1
                ? $this->ledgerRows[0]
                : array_shift($this->ledgerRows);
        });

        return $attempts;
    }

    /** @param list<Response> $queue */
    private function vrep(array $queue): JmhzVrepClient
    {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        return new JmhzVrepClient(
            new Client(['handler' => $stack, 'http_errors' => false]),
            'test',
        );
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function sentRow(array $overrides = []): array
    {
        return array_merge([
            'id' => self::ATTEMPT,
            'supplier_id' => self::SUPPLIER,
            'environment' => 'test',
            'submission_id' => self::SUBMISSION,
            'channel' => JmhzDispatchService::CHANNEL,
            'attempt_no' => 1,
            'status' => 'awaiting_protocol',
            'correlation_reference' => self::CORRELATION,
            'request_sha256' => str_repeat('a', 64),
            'response_http_status' => 200,
            'error_code' => null,
            'error_message' => null,
            'next_retry_at' => null,
            'poll_count' => 0,
            'last_polled_at' => null,
            'last_poll_error' => null,
            // Čerstvé odeslání: gmdate, aby test nezestárl spolu se stropem stáří.
            'sent_at' => gmdate('Y-m-d H:i:s', time() - 600),
            'completed_at' => null,
            'closed_at' => null,
            'close_attempts' => 0,
            'close_error' => null,
            'row_version' => 1,
            'created_by' => 3,
        ], $overrides);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function completedRow(array $overrides = []): array
    {
        return self::sentRow(array_merge([
            'status' => 'completed',
            'completed_at' => gmdate('Y-m-d H:i:s'),
            'poll_count' => 1,
            'row_version' => 2,
        ], $overrides));
    }

    /** Zmrazená datová věta — jen hlavička, syntetické hodnoty. */
    private static function frozenPayload(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<jmhz xmlns="http://schemas.cssz.cz/JMHZ/podani/1.0" verze="1.4.3.4">'
            . '<hlavicka>'
            . '<idPodani>AAAABBBB-1111-7222-8333-CCCCDDDDEEEE</idPodani>'
            . '<typPodani>R</typPodani>'
            . '<variabilniSymbol>' . JmhzTransportSample::VARIABLE_SYMBOL . '</variabilniSymbol>'
            . '<mesic>7</mesic><rok>2026</rok>'
            . '</hlavicka></jmhz>';
    }

    private static function acknowledgement(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
            . '<EnvelopeVersion>2.0</EnvelopeVersion>'
            . '<Header><MessageDetails>'
            . '<Class>CSSZ_JMHZ</Class>'
            . '<Qualifier>acknowledgement</Qualifier>'
            . '<Function>submit</Function>'
            . '<CorrelationID>' . self::CORRELATION . '</CorrelationID>'
            . '<ResponseEndPoint PollInterval="120">https://t-epodani.cssz.cz/VREP/poll</ResponseEndPoint>'
            . '<GatewayTimestamp>2026-08-15T02:24:15.182</GatewayTimestamp>'
            . '</MessageDetails><SenderDetails /></Header>'
            . '<GovTalkDetails><Keys /></GovTalkDetails><Body />'
            . '</GovTalkMessage>';
    }

    private static function protocol(): string
    {
        return JmhzTransportSample::partialProtocol(
            'OK',
            [],
            'response',
            '',
            '0',
            'OK',
            0,
            self::CORRELATION,
        );
    }
}
