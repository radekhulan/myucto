<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunCommand;
use MyInvoice\Service\Payroll\Run\PayrollRunStatus;
use MyInvoice\Service\Payroll\Run\PayrollRunTransitionContext;
use MyInvoice\Service\Payroll\Run\PayrollRunWorkflow;
use PHPUnit\Framework\TestCase;

/**
 * Důvod se píše jen u opravy schváleného běhu. Zrušení neschváleného běhu
 * a jeho návrat „Začít znovu" důvod nevyžadují — obrazovka se dřív ptala
 * dvakrát za sebou a účetní psala cokoli, jen aby mohla pokračovat.
 */
final class PayrollRunWorkflowReasonTest extends TestCase
{
    public function testCancelOfUnapprovedRunNeedsNoReason(): void
    {
        $transition = (new PayrollRunWorkflow())->transition(
            PayrollRunStatus::CALCULATED,
            PayrollRunCommand::CANCEL,
            new PayrollRunTransitionContext(actorUserId: 1),
        );

        self::assertSame(PayrollRunStatus::CANCELLED, $transition->to);
    }

    /**
     * Zrušení opravné revize nad schváleným (zaúčtovaným, uzavřeným) během
     * ruší rozpracovanou opravu schváleného výsledku, důvod je povinný.
     */
    public function testCancelOfRunWithApprovedRevisionNeedsReason(): void
    {
        $this->expectException(\DomainException::class);
        (new PayrollRunWorkflow())->transition(
            PayrollRunStatus::REOPENED,
            PayrollRunCommand::CANCEL,
            new PayrollRunTransitionContext(actorUserId: 1, reason: ' ', hasApprovedRevision: true),
        );
    }

    public function testCancelOfRunWithApprovedRevisionPassesWithReason(): void
    {
        $transition = (new PayrollRunWorkflow())->transition(
            PayrollRunStatus::REOPENED,
            PayrollRunCommand::CANCEL,
            new PayrollRunTransitionContext(
                actorUserId: 1,
                reason: 'Oprava se nedělá.',
                hasApprovedRevision: true,
            ),
        );

        self::assertSame(PayrollRunStatus::CANCELLED, $transition->to);
    }

    public function testReopenOfCancelledRunNeedsNoReason(): void
    {
        $transition = (new PayrollRunWorkflow())->transition(
            PayrollRunStatus::CANCELLED,
            PayrollRunCommand::REOPEN,
            new PayrollRunTransitionContext(actorUserId: 1),
        );

        self::assertSame(PayrollRunStatus::REOPENED, $transition->to);
    }

    public function testCorrectionOfApprovedRunStillNeedsReason(): void
    {
        $this->expectException(\DomainException::class);
        (new PayrollRunWorkflow())->transition(
            PayrollRunStatus::APPROVED,
            PayrollRunCommand::REQUEST_CORRECTION,
            new PayrollRunTransitionContext(actorUserId: 1, reason: '  '),
        );
    }
}
