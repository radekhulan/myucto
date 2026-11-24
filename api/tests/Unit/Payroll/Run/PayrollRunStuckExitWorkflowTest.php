<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Payroll\Run\PayrollRunCommand;
use MyInvoice\Service\Payroll\Run\PayrollRunStatus;
use MyInvoice\Service\Payroll\Run\PayrollRunTransitionContext;
use MyInvoice\Service\Payroll\Run\PayrollRunWorkflow;
use PHPUnit\Framework\TestCase;

/** Cesty ven z mzdového běhu: přechody nových příkazů a mapování souběhu. */
final class PayrollRunStuckExitWorkflowTest extends TestCase
{
    public function testWithdrawCorrectionReturnsToRequestedState(): void
    {
        $workflow = new PayrollRunWorkflow();
        $transition = $workflow->transition(
            PayrollRunStatus::CORRECTION_PENDING,
            PayrollRunCommand::WITHDRAW_CORRECTION,
            new PayrollRunTransitionContext(1, correctionRequestedFrom: PayrollRunStatus::PAID),
        );
        self::assertSame(PayrollRunStatus::PAID, $transition->to);

        $this->expectException(\DomainException::class);
        $workflow->transition(
            PayrollRunStatus::CORRECTION_PENDING,
            PayrollRunCommand::WITHDRAW_CORRECTION,
            new PayrollRunTransitionContext(1),
        );
    }

    /** Zahození opravy vrací běh do stavu před žádostí, jen u otevřené opravy. */
    public function testDiscardCorrectionReturnsToRequestedStateOnlyForOpenCorrection(): void
    {
        $workflow = new PayrollRunWorkflow();
        foreach ([PayrollRunStatus::INPUTS_LOCKED, PayrollRunStatus::REOPENED, PayrollRunStatus::CALCULATED, PayrollRunStatus::REVIEWED] as $status) {
            self::assertContains(PayrollRunCommand::DISCARD_CORRECTION, $workflow->availableCommands($status, true));
            self::assertNotContains(PayrollRunCommand::DISCARD_CORRECTION, $workflow->availableCommands($status));
        }
        foreach ([PayrollRunStatus::APPROVED, PayrollRunStatus::CORRECTION_PENDING, PayrollRunStatus::CANCELLED, PayrollRunStatus::DRAFT] as $status) {
            self::assertNotContains(PayrollRunCommand::DISCARD_CORRECTION, $workflow->availableCommands($status, true));
        }
        foreach ([PayrollRunStatus::APPROVED, PayrollRunStatus::POSTED, PayrollRunStatus::PAYMENT_READY, PayrollRunStatus::PAID, PayrollRunStatus::CLOSED] as $target) {
            self::assertSame($target, $workflow->transition(
                PayrollRunStatus::CALCULATED,
                PayrollRunCommand::DISCARD_CORRECTION,
                new PayrollRunTransitionContext(1, correctionRequestedFrom: $target, openCorrectionRevision: true),
            )->to);
        }
        try {
            $workflow->transition(
                PayrollRunStatus::CALCULATED,
                PayrollRunCommand::DISCARD_CORRECTION,
                new PayrollRunTransitionContext(1, correctionRequestedFrom: PayrollRunStatus::APPROVED),
            );
            self::fail('Řádnou revizi nejde zahodit jako opravu.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(\DomainException::class);
        $workflow->transition(
            PayrollRunStatus::REOPENED,
            PayrollRunCommand::DISCARD_CORRECTION,
            new PayrollRunTransitionContext(1, openCorrectionRevision: true),
        );
    }

    public function testChangePaymentDateKeepsDraftAndCancelledAndRejectsApprovedRun(): void
    {
        $workflow = new PayrollRunWorkflow();
        foreach ([PayrollRunStatus::DRAFT, PayrollRunStatus::CANCELLED] as $status) {
            self::assertSame($status, $workflow->transition(
                $status,
                PayrollRunCommand::CHANGE_PAYMENT_DATE,
                new PayrollRunTransitionContext(1),
            )->to);
        }
        self::assertSame(PayrollRunStatus::INPUTS_LOCKED, $workflow->transition(
            PayrollRunStatus::CALCULATED,
            PayrollRunCommand::CHANGE_PAYMENT_DATE,
            new PayrollRunTransitionContext(1, hasImmutableSnapshot: true),
        )->to);
        self::assertNotContains(
            PayrollRunCommand::CHANGE_PAYMENT_DATE,
            $workflow->availableCommands(PayrollRunStatus::APPROVED),
        );

        $this->expectException(\DomainException::class);
        $workflow->transition(
            PayrollRunStatus::CANCELLED,
            PayrollRunCommand::CHANGE_PAYMENT_DATE,
            new PayrollRunTransitionContext(1, hasApprovedRevision: true),
        );
    }

    public function testExcludeNeedsReasonAndKeepsRevisionKind(): void
    {
        $workflow = new PayrollRunWorkflow();
        self::assertSame(PayrollRunStatus::REOPENED, $workflow->transition(
            PayrollRunStatus::CALCULATED,
            PayrollRunCommand::EXCLUDE_EMPLOYEE,
            new PayrollRunTransitionContext(1, hasImmutableSnapshot: true, reason: 'Chybí pojišťovna.', correctionRevision: true),
        )->to);

        $this->expectException(\DomainException::class);
        $workflow->transition(
            PayrollRunStatus::CALCULATED,
            PayrollRunCommand::EXCLUDE_EMPLOYEE,
            new PayrollRunTransitionContext(1, hasImmutableSnapshot: true, reason: 'x'),
        );
    }

    public function testLockWaitAndDeadlockAreConcurrencyConflicts(): void
    {
        foreach ([1205, 1213, 1020] as $code) {
            $failure = new \PDOException('lock');
            $failure->errorInfo = ['HY000', $code, 'lock'];
            self::assertTrue(TransactionFailure::lockConflict($failure), (string) $code);
            self::assertTrue(TransactionFailure::lockConflict(new \RuntimeException('wrap', 0, $failure)));
        }
        $other = new \PDOException('dup');
        $other->errorInfo = ['23000', 1062, 'dup'];
        self::assertFalse(TransactionFailure::lockConflict($other));
    }
}
