<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\NonprofitDppoDataProvider;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use PHPUnit\Framework\TestCase;

final class NonprofitDppoStatementResultTest extends TestCase
{
    public function testMissingResultRowIsAnErrorEvenForZeroResult(): void
    {
        $this->expectRejected(['rows' => [['adis_row' => 61, 'total' => 0.0]]], 0);
    }

    public function testNonNumericResultRowIsAnError(): void
    {
        $this->expectRejected(['rows' => [['adis_row' => 62, 'total' => null]]], 0);
    }

    public function testResultRowMustMatchJ6AccountingResult(): void
    {
        NonprofitDppoDataProvider::assertStatementResult(['rows' => [['adis_row' => 62, 'total' => 130400.0]]], 13040000);
        $this->expectRejected(['rows' => [['adis_row' => 62, 'total' => 130400.01]]], 13040000);
    }

    private function expectRejected(array $income, int $cents): void
    {
        try {
            NonprofitDppoDataProvider::assertStatementResult($income, $cents);
            self::fail('Statement result row must be present and reconcile.');
        } catch (TaxReturnException $e) {
            self::assertSame('nonprofit_dppo_statement_reconciliation_failed', $e->errorCode);
        }
    }
}
