<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Nonprofit\Membership;

use MyInvoice\Repository\Nonprofit\Membership\MembershipBalanceRepository;
use MyInvoice\Service\Nonprofit\Membership\MembershipReconciliationService;
use PHPUnit\Framework\TestCase;

final class MembershipSettlementReviewTest extends TestCase
{
    private function settlement(array $changes = []): array
    {
        $balances = (new \ReflectionClass(MembershipBalanceRepository::class))->newInstanceWithoutConstructor();
        $service = new MembershipReconciliationService($balances);
        return (new \ReflectionMethod($service, 'settlement'))->invoke($service, $changes + [
            'reversal_claim_id' => null, 'posted_at' => '2026-01-01 00:00:00', 'reversed_by' => null,
            'amount_cents' => 50000, 'line_no' => 1, 'account_id' => 1, 'receipt_valid' => 1,
        ], null);
    }

    public function testNoPairingMeansTheWholeClaimIsUnpaid(): void
    {
        $result = $this->settlement();
        self::assertSame('unreconciled', $result['status']);
        self::assertSame(0, $result['paid_cents']);
        self::assertSame(50000, $result['open_cents']);
        self::assertSame('no_native_pairing', $result['evidence']);
    }

    public function testMissingJournalLineIsAmbiguousInsteadOfOrdinaryNonpayment(): void
    {
        $result = $this->settlement(['line_no' => 0, 'account_id' => 0]);
        self::assertSame('ambiguous', $result['status']);
        self::assertNull($result['open_cents']);
    }

    public function testZeroIsAValidJournalLineNumber(): void
    {
        $result = $this->settlement(['line_no' => 0]);
        self::assertSame('unreconciled', $result['status']);
        self::assertSame(50000, $result['open_cents']);
    }

    public function testExistingButMismatchedReceiptCannotBecomeOrdinaryUnpaidClaim(): void
    {
        $result = $this->settlement(['receipt_valid' => 0]);
        self::assertSame('ambiguous', $result['status']);
        self::assertNull($result['paid_cents']);
        self::assertNull($result['open_cents']);
    }
}
