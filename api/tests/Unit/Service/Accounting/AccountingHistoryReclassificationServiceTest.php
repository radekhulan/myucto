<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Accounting;

use MyInvoice\Service\Accounting\Setup\AccountingHistoryReclassificationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AccountingHistoryReclassificationServiceTest extends TestCase
{
    public function testMergeReplacesOnlyExpenseAndAssetLegs(): void
    {
        $before = [
            ['account_code' => '518', 'side' => 'debit', 'amount' => 100.00],
            ['account_code' => '343.100', 'side' => 'debit', 'amount' => 21.00, 'currency_code' => 'EUR', 'fx_rate' => 25.0, 'amount_foreign' => 0.84],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 121.00, 'currency_code' => 'EUR', 'fx_rate' => 25.0, 'amount_foreign' => 4.84],
        ];
        $recalculated = [
            ['account_code' => '501', 'side' => 'debit', 'amount' => 100.00],
            ['account_code' => '343.100', 'side' => 'debit', 'amount' => 20.00],
            ['account_code' => '321', 'side' => 'credit', 'amount' => 120.00],
        ];

        $merged = $this->merge($before, $recalculated);
        $byAccount = array_column($merged, null, 'account_code');

        self::assertArrayNotHasKey('518', $byAccount);
        self::assertSame(100.0, $byAccount['501']['amount']);
        self::assertSame(21.0, $byAccount['343.100']['amount']);
        self::assertSame(121.0, $byAccount['321']['amount']);
        self::assertSame('EUR', $byAccount['321']['currency_code']);
        self::assertSame(25.0, $byAccount['321']['fx_rate']);
    }

    public function testMergeRejectsChangeThatWouldUnbalanceProtectedLegs(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsafe_total_mismatch');

        $this->merge(
            [
                ['account_code' => '518', 'side' => 'debit', 'amount' => 100.00],
                ['account_code' => '321', 'side' => 'credit', 'amount' => 100.00],
            ],
            [
                ['account_code' => '501', 'side' => 'debit', 'amount' => 99.00],
                ['account_code' => '321', 'side' => 'credit', 'amount' => 99.00],
            ],
        );
    }

    public function testSnapshotFingerprintIncludesItemClassificationAndRowVersion(): void
    {
        $method = new ReflectionMethod(AccountingHistoryReclassificationService::class, 'snapshotHash');
        $snapshot = [
            'entry_id' => 1,
            'row_version' => 2,
            'entry_date' => '2026-01-15',
            'posted_at' => '2026-01-16 10:00:00',
            'posted_by' => 7,
            'lines' => [
                ['account_code' => '518', 'side' => 'debit', 'amount' => 100],
                ['account_code' => '321', 'side' => 'credit', 'amount' => 100],
            ],
            'item_classifications' => [11 => [
                'expense_kind' => 'service',
                'expense_account_code' => '518',
                'is_fixed_asset' => false,
            ]],
        ];
        $classificationChanged = $snapshot;
        $classificationChanged['item_classifications'][11]['expense_kind'] = 'material';
        $versionChanged = $snapshot;
        $versionChanged['row_version'] = 3;

        $original = $method->invoke(null, $snapshot);
        self::assertNotSame($original, $method->invoke(null, $classificationChanged));
        self::assertNotSame($original, $method->invoke(null, $versionChanged));
    }

    private function merge(array $before, array $calculated): array
    {
        $method = new ReflectionMethod(AccountingHistoryReclassificationService::class, 'mergeReclassifiedLines');
        return $method->invoke(null, $before, $calculated);
    }
}
