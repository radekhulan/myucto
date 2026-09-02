<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\PayrollDispatchCapability;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchCapabilityCatalog;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchGate;
use PHPUnit\Framework\TestCase;

/**
 * Věty, které účetní ve frontě čte, a rozhodnutí, které za nimi stojí.
 *
 * Zrovna tohle je potřeba mít pokryté: „nejde to odeslat" bez důvodu je ta
 * situace, kvůli které uživatel neví, jestli na podání zapomněl, nebo ho
 * aplikace neumí.
 */
final class PayrollDispatchGateTest extends TestCase
{
    private PayrollDispatchGate $gate;

    protected function setUp(): void
    {
        $this->gate = new PayrollDispatchGate();
    }

    public function testReadySubmissionOnDocumentedChannelPasses(): void
    {
        self::assertNull($this->gate->blockedReason(
            self::row(),
            self::jmhz(),
            'test',
            0,
        ));
    }

    /** Nezmrazené podání je návod, ne uzávěr — proto stojí první. */
    public function testUnfrozenSubmissionExplainsWhatToDo(): void
    {
        foreach (['validated', 'prepared'] as $status) {
            $reason = $this->gate->blockedReason(
                self::row(['submission_status' => $status]),
                self::jmhz(),
                'test',
                0,
            );
            self::assertNotNull($reason);
            self::assertStringContainsString('zmrazené', (string) $reason);
        }
    }

    /** Agenda bez kanálu si nese svůj vlastní důvod z katalogu. */
    public function testUndispatchableAgendaUsesCatalogReason(): void
    {
        $capability = new PayrollDispatchCapability(
            'ELDP',
            PayrollDispatchCapabilityCatalog::MODE_NONE,
            'Evidenční list aplikace neodesílá.',
        );

        self::assertSame(
            'Evidenční list aplikace neodesílá.',
            $this->gate->blockedReason(self::row(), $capability, 'test', 0),
        );
    }

    /**
     * Schránky pojišťoven jsou doložené jen pro produkci. V testu se řádek
     * musí UKÁZAT s důvodem, ne zmizet.
     */
    public function testProductionOnlyChannelBlocksInTestEnvironment(): void
    {
        $capability = self::health();

        $reason = $this->gate->blockedReason(self::row(), $capability, 'test', 0);
        self::assertNotNull($reason);
        self::assertStringContainsString('ostré prostředí', (string) $reason);

        self::assertNull(
            $this->gate->blockedReason(self::row(), $capability, 'production', 0),
            'V ostrém prostředí tenhle kanál blokovat nemá co.',
        );
    }

    /**
     * Odeslané podání se neodesílá podruhé. Duplicitu u úřadu nejde vzít zpět.
     */
    public function testAlreadySentSubmissionIsBlocked(): void
    {
        foreach ([
            ['status' => 'sent', 'sent_at' => '2026-09-01 10:00:00'],
            ['status' => 'awaiting_protocol', 'sent_at' => '2026-09-01 10:00:00'],
            ['status' => 'completed', 'sent_at' => '2026-09-01 10:00:00'],
            ['status' => 'expired', 'sent_at' => '2026-09-01 10:00:00'],
            ['status' => 'prepared', 'sent_at' => null],
            // `failed` PO odeslání blokuje: neví se, co úřad přijal.
            ['status' => 'failed', 'sent_at' => '2026-09-01 10:00:00'],
        ] as $attempt) {
            $reason = $this->gate->blockedReason(
                self::row(['attempt' => [
                    'attempt_no' => 1,
                    'status' => $attempt['status'],
                    'sent_at' => $attempt['sent_at'],
                ]]),
                self::jmhz(),
                'test',
                0,
            );
            self::assertNotNull(
                $reason,
                "Pokus ve stavu {$attempt['status']} měl odeslání zablokovat.",
            );
            self::assertStringContainsString('duplicita', (string) $reason);
        }
    }

    /**
     * Neúspěch PŘED odesláním naopak blokovat NESMÍ — u úřadu po něm nic
     * nezůstalo. Dokud tohle nefungovalo, stačil jeden spadlý pokus a účetní
     * neměla připravené podání jak odeslat vůbec.
     */
    public function testFailedAttemptBeforeSendingAllowsRetry(): void
    {
        self::assertNull($this->gate->blockedReason(
            self::row(['attempt' => [
                'attempt_no' => 1,
                'status' => 'failed',
                'sent_at' => null,
            ]]),
            self::jmhz(),
            'test',
            0,
        ));
        self::assertTrue(PayrollDispatchGate::attemptAllowsRetry([
            'status' => 'failed',
            'sent_at' => null,
        ]));
        self::assertFalse(PayrollDispatchGate::attemptAllowsRetry([
            'status' => 'failed',
            'sent_at' => '2026-09-01 10:00:00',
        ]));
    }

    /** Podání už zařazené v odchozí frontě datovky se nezařazuje podruhé. */
    public function testQueuedOutboxRowBlocksSecondEnqueue(): void
    {
        $reason = $this->gate->blockedReason(
            self::row(['outbox' => ['dispatch_state' => 'sent']]),
            self::jmhz(),
            'test',
            0,
        );
        self::assertNotNull($reason);
        self::assertStringContainsString('odchozí frontě', (string) $reason);
    }

    /** Zrušené či neúspěšné zařazení naopak druhý pokus dovolit musí. */
    public function testCancelledOutboxRowAllowsRetry(): void
    {
        foreach (['failed', 'cancelled'] as $state) {
            self::assertNull(
                $this->gate->blockedReason(
                    self::row(['outbox' => ['dispatch_state' => $state]]),
                    self::jmhz(),
                    'test',
                    0,
                ),
                "Stav fronty {$state} neměl blokovat druhý pokus.",
            );
        }
    }

    public function testOpenBlockingIssuesAreReportedWithCount(): void
    {
        $reason = $this->gate->blockedReason(self::row(), self::jmhz(), 'test', 3);

        self::assertNotNull($reason);
        self::assertStringContainsString('3 nevyřešených chyb', (string) $reason);
    }

    /**
     * Každý blokující důvod je CELÁ VĚTA, ne kód chyby. Fronta je ukazuje
     * přímo u řádku, takže tam nesmí být `payroll_isds_agenda_undocumented`.
     */
    public function testEveryReasonIsAHumanSentence(): void
    {
        $reasons = [
            $this->gate->blockedReason(
                self::row(['submission_status' => 'prepared']),
                self::jmhz(),
                'test',
                0,
            ),
            $this->gate->blockedReason(self::row(), self::health(), 'test', 0),
            $this->gate->blockedReason(
                self::row(['attempt' => [
                    'attempt_no' => 2,
                    'status' => 'sent',
                    'sent_at' => '2026-09-01 10:00:00',
                ]]),
                self::jmhz(),
                'test',
                0,
            ),
            $this->gate->blockedReason(self::row(), self::jmhz(), 'test', 1),
        ];

        foreach ($reasons as $reason) {
            self::assertIsString($reason);
            self::assertStringEndsWith('.', $reason);
            self::assertGreaterThan(40, mb_strlen($reason));
            self::assertDoesNotMatchRegularExpression(
                '/\b[a-z]+_[a-z_]+\b/',
                $reason,
                "Důvod „{$reason}\" vypadá jako kód chyby, ne jako věta.",
            );
        }
    }

    private static function jmhz(): PayrollDispatchCapability
    {
        return new PayrollDispatchCapability(
            'JMHZ25',
            PayrollDispatchCapabilityCatalog::MODE_VREP_JMHZ,
            null,
        );
    }

    private static function health(): PayrollDispatchCapability
    {
        return new PayrollDispatchCapability(
            'PPZ_2026',
            PayrollDispatchCapabilityCatalog::MODE_ISDS_HEALTH,
            null,
            productionOnly: true,
        );
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function row(array $overrides = []): array
    {
        return [
            'submission_id' => 7,
            'submission_status' => 'ready',
            'agenda_code' => 'JMHZ25',
            'attempt' => null,
            'outbox' => null,
            ...$overrides,
        ];
    }
}
