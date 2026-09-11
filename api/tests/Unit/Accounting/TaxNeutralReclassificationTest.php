<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\TaxNeutralReclassification as R;
use PHPUnit\Framework\TestCase;

/**
 * Přepis zápisu v datu zamčeném podaným DPH smí projít jen tehdy, když jde o čistý
 * přesun mezi účty téže třídy bez dopadu do daní. Každý test níž je jedna cesta,
 * kudy by se daňový dopad dal protlačit „přeúčtováním".
 */
final class TaxNeutralReclassificationTest extends TestCase
{
    private const ACCOUNTS = [
        1  => ['code' => '511', 'account_type' => 'expense', 'tax_deductibility' => 'deductible'],
        2  => ['code' => '518.100', 'account_type' => 'expense', 'tax_deductibility' => 'deductible'],
        3  => ['code' => '518.990', 'account_type' => 'expense', 'tax_deductibility' => 'non_deductible'],
        4  => ['code' => '343.100', 'account_type' => 'liability', 'tax_deductibility' => 'deductible'],
        5  => ['code' => '321.100', 'account_type' => 'liability', 'tax_deductibility' => 'deductible'],
        6  => ['code' => '042.100', 'account_type' => 'asset', 'tax_deductibility' => 'deductible'],
        7  => ['code' => '511.100', 'account_type' => 'expense', 'tax_deductibility' => 'deductible'],
        8  => ['code' => '325', 'account_type' => 'liability', 'tax_deductibility' => 'deductible'],
        9  => ['code' => '710', 'account_type' => 'closing', 'tax_deductibility' => 'deductible'],
    ];

    /** Zápis přijaté faktury 205 320 + DPH 43 117,20 na nákladový účet $expenseId. */
    private static function purchase(int $expenseId, float $vat = 43117.20): array
    {
        return [
            ['account_id' => $expenseId, 'side' => 'debit', 'amount' => 205320.00],
            ['account_id' => 4, 'side' => 'debit', 'amount' => $vat],
            ['account_id' => 5, 'side' => 'credit', 'amount' => round(205320.00 + $vat, 2)],
        ];
    }

    /** Reálný případ: 511 → 518.100, DPH ani závazek se nehýbou. */
    public function testExpenseAccountSwapIsTaxNeutral(): void
    {
        self::assertNull(R::violation(self::purchase(1), self::purchase(2), self::ACCOUNTS));
    }

    public function testUnchangedEntryIsTaxNeutral(): void
    {
        self::assertNull(R::violation(self::purchase(2), self::purchase(2), self::ACCOUNTS));
    }

    /** Rozdělení nákladu na dva účty téže třídy je pořád jen přesun. */
    public function testSplittingWithinClassIsTaxNeutral(): void
    {
        $after = [
            ['account_id' => 2, 'side' => 'debit', 'amount' => 200000.00],
            ['account_id' => 7, 'side' => 'debit', 'amount' => 5320.00],
            ['account_id' => 4, 'side' => 'debit', 'amount' => 43117.20],
            ['account_id' => 5, 'side' => 'credit', 'amount' => 248437.20],
        ];

        self::assertNull(R::violation(self::purchase(1), $after, self::ACCOUNTS));
    }

    /** Změna DPH v zamčeném datu by rozešla deník s podaným přiznáním. */
    public function testVatChangeIsRejected(): void
    {
        self::assertSame(
            R::TAX_ACCOUNT_CHANGED,
            R::violation(self::purchase(1), self::purchase(1, 40000.00), self::ACCOUNTS),
        );
    }

    /** Přesun na nedaňovou analytiku mění základ daně z příjmů. */
    public function testDeductibilityChangeIsRejected(): void
    {
        self::assertSame(
            R::TAX_DEDUCTIBILITY_CHANGED,
            R::violation(self::purchase(2), self::purchase(3), self::ACCOUNTS),
        );
    }

    /** Náklad → dlouhodobý majetek je jiná třída (a jiné odpisy), ne přeúčtování. */
    public function testClassChangeIsRejected(): void
    {
        self::assertSame(
            R::ACCOUNT_CLASS_CHANGED,
            R::violation(self::purchase(1), self::purchase(6), self::ACCOUNTS),
        );
    }

    /** Snížení nákladu i závazku mění částky, ne jen účty. */
    public function testAmountChangeIsRejected(): void
    {
        $after = [
            ['account_id' => 1, 'side' => 'debit', 'amount' => 200000.00],
            ['account_id' => 4, 'side' => 'debit', 'amount' => 43117.20],
            ['account_id' => 5, 'side' => 'credit', 'amount' => 243117.20],
        ];

        self::assertSame(R::AMOUNTS_CHANGED, R::violation(self::purchase(1), $after, self::ACCOUNTS));
    }

    /** Přesun uvnitř třídy 3 bez daňového účtu projde (321 → 325). */
    public function testBalanceSheetMoveWithinClassIsTaxNeutral(): void
    {
        $after = self::purchase(1);
        $after[2]['account_id'] = 8;

        self::assertNull(R::violation(self::purchase(1), $after, self::ACCOUNTS));
    }

    public function testClosingAccountIsRejected(): void
    {
        self::assertSame(
            R::SPECIAL_ACCOUNT,
            R::violation(self::purchase(1), self::purchase(9), self::ACCOUNTS),
        );
    }

    public function testUnknownAccountIsRejected(): void
    {
        self::assertSame(
            R::UNKNOWN_ACCOUNT,
            R::violation(self::purchase(1), self::purchase(99), self::ACCOUNTS),
        );
    }
}
