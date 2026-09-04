<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\PayrollDispatchGate;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use PHPUnit\Framework\TestCase;

/**
 * Zahození rozdělaného odeslání a návrat podání k odeslání.
 *
 * NÁLEZ, KTERÝ TO VYNUTIL
 * ------------------------------------------------------------------------------
 * ČSSZ zprávu převzala (HTTP 200, CorrelationID), ale zpracovat ji odmítla:
 * „Pověření k dané e-službě ('CSSZ_JMHZ') není zaznamenáno v registru podávajících
 * na OSSZ nebo certifikát, kterým je e-podání podepsáno, není zaznamenán v registru
 * podávajících na OSSZ." Odeslané tedy nebylo nic, jenže podání uvízlo ve stavu
 * `submitted`, ze kterého nevedla cesta nikam:
 *
 *   - na `ready` (jediný stav, ze kterého se smí odesílat) se nedalo vrátit odnikud,
 *   - klíč `uq_payroll_submissions_regular` pouští na jednu povinnost jediné řádné
 *     podání, takže nešlo založit ani nové,
 *   - odeslaný pokus blokoval další odeslání.
 *
 * Povinnost byla z aplikace trvale nepodatelná i poté, co by účetní příčinu u OSSZ
 * vyřídila.
 *
 * O opakování rozhoduje ČLOVĚK, ne automatika podle textu odpovědi: důvodů, proč
 * úřad podání nepřijme, je víc, než kolik jich umíme spolehlivě rozpoznat.
 */
final class PayrollSubmissionAbandonRulesTest extends TestCase
{
    private PayrollSubmissionStateMachine $machine;

    protected function setUp(): void
    {
        $this->machine = new PayrollSubmissionStateMachine();
    }

    /** JÁDRO NÁLEZU: z odeslaného podání musí vést cesta zpět k odeslání. */
    public function testStuckSubmissionCanReturnToReady(): void
    {
        self::assertTrue(
            $this->machine->canTransition('submitted', 'ready'),
            'Podání, které úřad nepřijal, se musí dát vrátit k odeslání.',
        );
    }

    /**
     * Komentář u mapování stavů říká, že po „nebylo přijato" i „zamítnuto" musí
     * zaměstnavatel poslat nové hlášení — bez návratu na `ready` k tomu ale
     * nevedla žádná cesta.
     */
    public function testRejectedSubmissionCanReturnToReady(): void
    {
        self::assertTrue($this->machine->canTransition('rejected', 'ready'));
    }

    public function testProcessingAndWaitingForIdentityCanReturnToReady(): void
    {
        self::assertTrue($this->machine->canTransition('processing', 'ready'));
        self::assertTrue($this->machine->canTransition('waiting_for_identity', 'ready'));
    }

    /**
     * DRUHÁ POLOVINA PRAVIDLA: přijaté podání se vrátit NESMÍ. Tam u úřadu něco JE
     * a opakované odeslání by vyrobilo duplicitu; oprava vede přes opravné podání.
     */
    public function testAcceptedSubmissionCannotReturnToReady(): void
    {
        self::assertFalse(
            $this->machine->canTransition('accepted', 'ready'),
            'Přijaté podání se znovu neodesílá — vzniklá duplicita se u úřadu nedá vzít zpět.',
        );
        self::assertFalse($this->machine->canTransition('partially_accepted', 'ready'));
    }

    public function testAcceptedIsNotAmongReopenableStatuses(): void
    {
        self::assertNotContains('accepted', PayrollSubmissionStateMachine::REOPENABLE_STATUSES);
        self::assertNotContains('partially_accepted', PayrollSubmissionStateMachine::REOPENABLE_STATUSES);
        self::assertContains('submitted', PayrollSubmissionStateMachine::REOPENABLE_STATUSES);
    }

    /** Vědomě zahozený pokus přestane blokovat další odeslání. */
    public function testAbandonedAttemptAllowsRetry(): void
    {
        self::assertTrue(PayrollDispatchGate::attemptAllowsRetry([
            'status' => 'expired',
            'error_code' => PayrollDispatchGate::ABANDONED_ERROR_CODE,
            'sent_at' => '2026-09-04 08:30:00',
        ]));
    }

    /**
     * Pokus, který vzdala AUTOMATIKA, blokuje dál — tam se pořád neví, co úřad
     * přijal. Rozdíl je právě v tom, že u zahození rozhodl člověk, který odpověď
     * úřadu viděl.
     */
    public function testAttemptExpiredByAutomationStillBlocks(): void
    {
        self::assertFalse(PayrollDispatchGate::attemptAllowsRetry([
            'status' => 'expired',
            'error_code' => 'jmhz_poll_budget_exhausted',
            'sent_at' => '2026-09-04 08:30:00',
        ]));
    }

    /** Původní pravidlo zůstává: co aplikaci neopustilo, jde poslat znovu. */
    public function testFailedBeforeSendingStillAllowsRetry(): void
    {
        self::assertTrue(PayrollDispatchGate::attemptAllowsRetry([
            'status' => 'failed',
            'sent_at' => null,
        ]));
    }

    /** A odeslaný pokus, který selhal až potom, dál blokuje. */
    public function testFailedAfterSendingStillBlocks(): void
    {
        self::assertFalse(PayrollDispatchGate::attemptAllowsRetry([
            'status' => 'failed',
            'sent_at' => '2026-09-04 08:30:00',
        ]));
    }

    /**
     * Pravidlo žije ve DVOU kopiích — v bráně a v SQL fronty odeslání. Kdyby se
     * rozešly, nabízela by fronta odeslání tam, kde ho „Stav odeslání" zakazuje.
     */
    public function testQueueSqlKnowsTheSameExceptions(): void
    {
        $sql = file_get_contents(
            dirname(__DIR__, 4) . '/src/Repository/Payroll/PayrollSubmissionTransportAttemptRepository.php',
        );
        self::assertIsString($sql);

        self::assertStringContainsString(
            'attempt.status = "failed" AND attempt.sent_at IS NULL',
            $sql,
        );
        self::assertStringContainsString(
            'attempt.status = "expired" AND attempt.error_code = "abandoned_by_user"',
            $sql,
            'Fronta odeslání musí znát tutéž výjimku jako PayrollDispatchGate.',
        );
        self::assertSame(
            'abandoned_by_user',
            PayrollDispatchGate::ABANDONED_ERROR_CODE,
            'Kód v SQL a v bráně se nesmí rozejít.',
        );
    }
}
