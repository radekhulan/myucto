<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollOperationalReconciliationService;
use PHPUnit\Framework\TestCase;

final class PayrollOperationalReconciliationServiceTest extends TestCase
{
    private PayrollOperationalReconciliationService $service;

    protected function setUp(): void
    {
        $reflection = new \ReflectionClass(PayrollOperationalReconciliationService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        self::assertInstanceOf(PayrollOperationalReconciliationService::class, $service);
        $this->service = $service;
    }

    public function testTaxEvidencePostingIsNotApplicableWhileLiabilityCanMatch(): void
    {
        $axes = $this->invoke('postingAxes', [[
            'accounting_mode' => 'tax_evidence',
            'journal_state' => 'not_applicable',
            'payments_state' => 'materialized',
            'revision' => ['id' => 42],
            'categories' => [[
                'key' => 'income_tax',
                'payroll_minor' => 900,
                'journal_minor' => null,
                'payments_liability_minor' => 900,
                'payments_paid_minor' => 900,
            ]],
        ]]);

        self::assertSame('not_applicable', $axes[0]['status']);
        self::assertSame('match', $axes[1]['status']);
        self::assertSame(0, $axes[1]['difference_minor']);
    }

    /**
     * Informativní kategorie nepeněžních plnění bez účetního dopadu nemá
     * deníkovou ani platební stranu ani u zaúčtovaného období. Porovnat ji
     * s `null` a vyhlásit rozdíl by znamenalo trvale svítící provozní přehled
     * kvůli očekávanému stavu.
     */
    public function testInformationalCategoryWithoutJournalSideIsNotADiff(): void
    {
        $axes = $this->invoke('postingAxes', [[
            'accounting_mode' => 'double_entry',
            'journal_state' => 'posted',
            'payments_state' => 'materialized',
            'revision' => ['id' => 42],
            'categories' => [[
                'key' => 'non_monetary_neutral',
                'payroll_minor' => 2_000,
                'journal_minor' => null,
                'payments_liability_minor' => null,
                'payments_paid_minor' => null,
            ]],
        ]]);

        self::assertSame('posting:journal:non_monetary_neutral', $axes[0]['key']);
        self::assertSame('not_applicable', $axes[0]['status']);
        self::assertSame('posting:liability:non_monetary_neutral', $axes[1]['key']);
        self::assertSame('not_applicable', $axes[1]['status']);
    }

    public function testPartialOutgoingPaymentIsNotNettedByReceivedRefund(): void
    {
        $axes = $this->invoke('paymentAxes', [[
            'outgoing' => [
                'liability_count' => 1,
                'required_minor' => 100_000,
                'settled_minor' => 60_000,
                'remaining_minor' => 40_000,
            ],
            'incoming' => [
                'liability_count' => 1,
                'required_minor' => 20_000,
                'settled_minor' => 20_000,
                'remaining_minor' => 0,
            ],
        ]]);

        self::assertSame('payment:settlement:outgoing', $axes[0]['key']);
        self::assertSame('diff', $axes[0]['status']);
        self::assertSame(40_000, $axes[0]['difference_minor']);
        self::assertSame('payment:settlement:incoming', $axes[1]['key']);
        self::assertSame('match', $axes[1]['status']);
        self::assertSame(0, $axes[1]['difference_minor']);
    }

    public function testJmhzOlderRevisionDiffersAndCurrentRejectionBlocks(): void
    {
        $axes = $this->invoke('jmhzAxes', [9, [
            [
                'environment' => 'production',
                'obligation_id' => 1,
                'obligation_status' => 'fulfilled',
                'submission_id' => 2,
                'submission_status' => 'accepted',
                'submission_kind' => 'regular',
                'source_revision_id' => 8,
                'source_snapshot_hash' => str_repeat('a', 64),
            ],
            [
                'environment' => 'test',
                'obligation_id' => 3,
                'obligation_status' => 'manual_review',
                'submission_id' => 4,
                'submission_status' => 'rejected',
                'submission_kind' => 'correction',
                'source_revision_id' => 9,
                'source_snapshot_hash' => str_repeat('b', 64),
            ],
        ]]);

        self::assertSame('diff', $axes[0]['status']);
        self::assertSame('blocked', $axes[1]['status']);
    }

    /** @param list<mixed> $arguments */
    private function invoke(string $method, array $arguments): array
    {
        $reflection = new \ReflectionMethod($this->service, $method);
        $result = $reflection->invokeArgs($this->service, $arguments);
        self::assertIsArray($result);

        return $result;
    }
}
