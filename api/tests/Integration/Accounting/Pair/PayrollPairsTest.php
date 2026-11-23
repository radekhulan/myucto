<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Service\Accounting\Payroll\PayrollCalculator;
use MyInvoice\Service\Accounting\Payroll\PayrollPostingService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Mzdy souhrnně za firmu po dvojicích předkontací (521/331, 524/336, 331/336, 331/342).
 * Mzdový modul (revize, PayrollPostingAdapter) pokrývá na úrovni builderu
 * PayrollPostingLineBuilderTest::testSingleEntryLinesArePairsWithTheSameTotals; tady
 * měsíční zápis z rekapitulace, který jde přímo do deníku.
 */
#[Group('integration')]
#[Group('pairs-e2e')]
final class PayrollPairsTest extends PairsTestCase
{
    protected const YEAR = 2026;

    public function testMonthlyPayrollEntryIsPairedByPostingPairs(): void
    {
        $result = $this->container->get(PayrollPostingService::class)
            ->post($this->supplierId, self::YEAR, 6, 40000.00, PayrollCalculator::TYPE_EMPLOYEE, ['user_id' => $this->userId]);

        $pairs = $this->assertEntryPaired($this->db->pdo(), $this->supplierId, (int) $result['journal_entry_id']);
        $byAccounts = [];
        foreach ($pairs as [$debit, $credit]) {
            $byAccounts[substr($debit, 0, 3) . '/' . substr($credit, 0, 3)] = true;
        }
        self::assertArrayHasKey('521/331', $byAccounts);
        self::assertArrayHasKey('524/336', $byAccounts);
        self::assertArrayHasKey('331/336', $byAccounts);
        self::assertArrayHasKey('331/342', $byAccounts);
    }
}
