<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\Run\PayrollRunCommand;
use MyInvoice\Service\Payroll\Run\PayrollRunStatus;
use MyInvoice\Service\Payroll\Run\PayrollRunTransitionContext;
use MyInvoice\Service\Payroll\Run\PayrollRunWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollRunWorkflowTest extends TestCase
{
    private PayrollRunWorkflow $workflow;

    protected function setUp(): void
    {
        $this->workflow = new PayrollRunWorkflow();
    }

    /** @return iterable<string,array{PayrollRunStatus,PayrollRunCommand,PayrollRunStatus}> */
    public static function validTransitions(): iterable
    {
        yield 'lock inputs' => [
            PayrollRunStatus::DRAFT,
            PayrollRunCommand::LOCK_INPUTS,
            PayrollRunStatus::INPUTS_LOCKED,
        ];
        yield 'calculate' => [
            PayrollRunStatus::INPUTS_LOCKED,
            PayrollRunCommand::CALCULATE,
            PayrollRunStatus::CALCULATED,
        ];
        yield 'review' => [
            PayrollRunStatus::CALCULATED,
            PayrollRunCommand::REVIEW,
            PayrollRunStatus::REVIEWED,
        ];
        yield 'approve' => [
            PayrollRunStatus::REVIEWED,
            PayrollRunCommand::APPROVE,
            PayrollRunStatus::APPROVED,
        ];
        yield 'post' => [
            PayrollRunStatus::APPROVED,
            PayrollRunCommand::POST,
            PayrollRunStatus::POSTED,
        ];
        yield 'payments' => [
            PayrollRunStatus::POSTED,
            PayrollRunCommand::PREPARE_PAYMENTS,
            PayrollRunStatus::PAYMENT_READY,
        ];
        yield 'paid' => [
            PayrollRunStatus::PAYMENT_READY,
            PayrollRunCommand::MARK_PAID,
            PayrollRunStatus::PAID,
        ];
        yield 'close' => [
            PayrollRunStatus::PAID,
            PayrollRunCommand::CLOSE,
            PayrollRunStatus::CLOSED,
        ];
        yield 'correction' => [
            PayrollRunStatus::CLOSED,
            PayrollRunCommand::REQUEST_CORRECTION,
            PayrollRunStatus::CORRECTION_PENDING,
        ];
        yield 'reopen' => [
            PayrollRunStatus::CORRECTION_PENDING,
            PayrollRunCommand::REOPEN,
            PayrollRunStatus::REOPENED,
        ];
    }

    #[DataProvider('validTransitions')]
    public function testValidTransitions(
        PayrollRunStatus $from,
        PayrollRunCommand $command,
        PayrollRunStatus $to,
    ): void {
        $transition = $this->workflow->transition(
            $from,
            $command,
            $this->context(reason: 'Syntetický důvod'),
        );

        self::assertSame($from, $transition->from);
        self::assertSame($to, $transition->to);
        self::assertSame($command, $transition->command);
    }

    /**
     * Celý řetěz od konceptu po uzavření musí projít jedním průchodem — jinak
     * se dá zaúčtování nebo platby v matici „ztratit" a běh skončí v
     * `approved`, jak to bylo před doplněním příkazů `post`, `prepare_payments`
     * a `mark_paid`.
     */
    public function testHappyPathReachesClosedThroughPostingAndPayments(): void
    {
        $status = PayrollRunStatus::DRAFT;
        $visited = [$status->value];
        foreach ([
            PayrollRunCommand::LOCK_INPUTS,
            PayrollRunCommand::CALCULATE,
            PayrollRunCommand::REVIEW,
            PayrollRunCommand::APPROVE,
            PayrollRunCommand::POST,
            PayrollRunCommand::PREPARE_PAYMENTS,
            PayrollRunCommand::MARK_PAID,
            PayrollRunCommand::CLOSE,
        ] as $command) {
            $status = $this->workflow
                ->transition($status, $command, $this->context())
                ->to;
            $visited[] = $status->value;
        }

        self::assertSame([
            'draft',
            'inputs_locked',
            'calculated',
            'reviewed',
            'approved',
            'posted',
            'payment_ready',
            'paid',
            'closed',
        ], $visited);
    }

    /** @return iterable<string,array{PayrollRunStatus,PayrollRunCommand}> */
    public static function forbiddenPaymentTransitions(): iterable
    {
        yield 'zaúčtování před schválením' => [
            PayrollRunStatus::REVIEWED,
            PayrollRunCommand::POST,
        ];
        yield 'zaúčtování podruhé' => [
            PayrollRunStatus::POSTED,
            PayrollRunCommand::POST,
        ];
        yield 'platby před zaúčtováním' => [
            PayrollRunStatus::APPROVED,
            PayrollRunCommand::PREPARE_PAYMENTS,
        ];
        yield 'platby podruhé' => [
            PayrollRunStatus::PAYMENT_READY,
            PayrollRunCommand::PREPARE_PAYMENTS,
        ];
        yield 'úhrada před přípravou plateb' => [
            PayrollRunStatus::POSTED,
            PayrollRunCommand::MARK_PAID,
        ];
        yield 'úhrada podruhé' => [
            PayrollRunStatus::PAID,
            PayrollRunCommand::MARK_PAID,
        ];
        yield 'uzavření před úhradou' => [
            PayrollRunStatus::PAYMENT_READY,
            PayrollRunCommand::CLOSE,
        ];
        yield 'zaúčtování zrušeného běhu' => [
            PayrollRunStatus::CANCELLED,
            PayrollRunCommand::POST,
        ];
        yield 'platby zrušeného běhu' => [
            PayrollRunStatus::CANCELLED,
            PayrollRunCommand::PREPARE_PAYMENTS,
        ];
        yield 'úhrada uzavřeného běhu' => [
            PayrollRunStatus::CLOSED,
            PayrollRunCommand::MARK_PAID,
        ];
        yield 'zaúčtování běhu čekajícího na opravu' => [
            PayrollRunStatus::CORRECTION_PENDING,
            PayrollRunCommand::POST,
        ];
    }

    #[DataProvider('forbiddenPaymentTransitions')]
    public function testForbiddenPaymentTransitions(
        PayrollRunStatus $from,
        PayrollRunCommand $command,
    ): void {
        $this->expectException(\DomainException::class);
        $this->workflow->transition($from, $command, $this->context());
    }

    /** @return iterable<string,array{PayrollRunStatus}> */
    public static function correctableStatuses(): iterable
    {
        yield 'posted' => [PayrollRunStatus::POSTED];
        yield 'payment_ready' => [PayrollRunStatus::PAYMENT_READY];
        yield 'paid' => [PayrollRunStatus::PAID];
    }

    #[DataProvider('correctableStatuses')]
    public function testPaymentStatesStayCorrectable(
        PayrollRunStatus $from,
    ): void {
        $transition = $this->workflow->transition(
            $from,
            PayrollRunCommand::REQUEST_CORRECTION,
            $this->context(reason: 'Syntetický důvod opravy'),
        );

        self::assertSame(
            PayrollRunStatus::CORRECTION_PENDING,
            $transition->to,
        );
    }

    /** @return iterable<string,array{PayrollRunStatus}> */
    public static function cancellableUnapprovedStatuses(): iterable
    {
        yield 'draft' => [PayrollRunStatus::DRAFT];
        yield 'inputs locked' => [PayrollRunStatus::INPUTS_LOCKED];
        yield 'calculated' => [PayrollRunStatus::CALCULATED];
        yield 'reviewed' => [PayrollRunStatus::REVIEWED];
        yield 'reopened' => [PayrollRunStatus::REOPENED];
    }

    #[DataProvider('cancellableUnapprovedStatuses')]
    public function testUnapprovedRunCanBeCancelledAndRecreated(
        PayrollRunStatus $from,
    ): void {
        $cancelled = $this->workflow->transition(
            $from,
            PayrollRunCommand::CANCEL,
            $this->context(reason: 'Vstupy byly opraveny po vytvoření snapshotu.'),
        );

        self::assertSame(PayrollRunStatus::CANCELLED, $cancelled->to);

        $reopened = $this->workflow->transition(
            $cancelled->to,
            PayrollRunCommand::REOPEN,
            $this->context(reason: 'Zakládám nový snapshot z opravených vstupů.'),
        );

        self::assertSame(PayrollRunStatus::REOPENED, $reopened->to);
    }

    public function testPostingAndPaymentGatesBlockWithoutEvidence(): void
    {
        foreach ([
            [PayrollRunStatus::APPROVED, PayrollRunCommand::POST, $this->context(hasPostingBatch: false)],
            [PayrollRunStatus::PAYMENT_READY, PayrollRunCommand::MARK_PAID, $this->context(hasPaymentBatch: false)],
        ] as [$from, $command, $context]) {
            try {
                $this->workflow->transition($from, $command, $context);
                self::fail($command->value);
            } catch (\DomainException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }

        // Příprava plateb žádnou předchozí evidenci nevyžaduje — brána je až
        // ve službě (materializace musí projít celá), workflow ji nesmí
        // blokovat na neexistující dávce.
        self::assertSame(
            PayrollRunStatus::PAYMENT_READY,
            $this->workflow->transition(
                PayrollRunStatus::POSTED,
                PayrollRunCommand::PREPARE_PAYMENTS,
                $this->context(
                    hasPostingBatch: false,
                    hasPaymentBatch: false,
                ),
            )->to,
        );
    }

    public function testTransitionMatrixRejectsSkippedApproval(): void
    {
        $this->expectException(\DomainException::class);
        $this->workflow->transition(
            PayrollRunStatus::CALCULATED,
            PayrollRunCommand::APPROVE,
            $this->context(),
        );
    }

    public function testSingleAccountantCanCalculateReviewAndApprove(): void
    {
        $review = $this->workflow->transition(
            PayrollRunStatus::CALCULATED,
            PayrollRunCommand::REVIEW,
            $this->context(actorUserId: 10, calculatedBy: 10),
        );
        self::assertSame(PayrollRunStatus::REVIEWED, $review->to);

        $approval = $this->workflow->transition(
            $review->to,
            PayrollRunCommand::APPROVE,
            $this->context(actorUserId: 10, calculatedBy: 10, reviewedBy: 10),
        );
        self::assertSame(PayrollRunStatus::APPROVED, $approval->to);
    }

    public function testApprovalRejectsBlockersAndUnresolvedOverrides(): void
    {
        foreach ([
            $this->context(blockerCount: 1),
            $this->context(unresolvedOverrideCount: 1),
        ] as $context) {
            try {
                $this->workflow->transition(
                    PayrollRunStatus::REVIEWED,
                    PayrollRunCommand::APPROVE,
                    $context,
                );
                self::fail('Schválení nesmí obejít validace.');
            } catch (\DomainException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testImmutableArtifactsAreRequiredAtTheirBoundaries(): void
    {
        foreach ([
            [PayrollRunStatus::DRAFT, PayrollRunCommand::LOCK_INPUTS, $this->context(hasSnapshot: false)],
            [PayrollRunStatus::CALCULATED, PayrollRunCommand::REVIEW, $this->context(hasResult: false)],
            [PayrollRunStatus::APPROVED, PayrollRunCommand::POST, $this->context(hasPostingBatch: false)],
            [PayrollRunStatus::PAYMENT_READY, PayrollRunCommand::MARK_PAID, $this->context(hasPaymentBatch: false)],
        ] as [$from, $command, $context]) {
            try {
                $this->workflow->transition($from, $command, $context);
                self::fail($command->value);
            } catch (\DomainException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    private function context(
        int $actorUserId = 20,
        ?int $calculatedBy = 10,
        ?int $reviewedBy = 20,
        int $blockerCount = 0,
        int $unresolvedOverrideCount = 0,
        bool $hasSnapshot = true,
        bool $hasResult = true,
        bool $hasPostingBatch = true,
        bool $hasPaymentBatch = true,
        ?string $reason = null,
    ): PayrollRunTransitionContext {
        return new PayrollRunTransitionContext(
            actorUserId: $actorUserId,
            calculatedBy: $calculatedBy,
            reviewedBy: $reviewedBy,
            blockerCount: $blockerCount,
            unresolvedOverrideCount: $unresolvedOverrideCount,
            hasImmutableSnapshot: $hasSnapshot,
            hasCalculatedResult: $hasResult,
            hasPostingBatch: $hasPostingBatch,
            hasPaymentBatch: $hasPaymentBatch,
            reason: $reason,
        );
    }
}
