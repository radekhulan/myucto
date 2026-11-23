<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaSelfAssessedCurrency;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use PHPUnit\Framework\TestCase;

final class PohodaSelfAssessedCurrencyTest extends TestCase
{
    private const ASSESSED = ['description' => 'Přeprava', 'base' => 2500.0, 'vat' => 0.0, 'rate' => 21.0, 'code' => '24e', 'rate_id' => 7];
    private const DIFFERENCE = ['description' => PohodaSelfAssessedCurrency::RATE_DIFFERENCE, 'base' => 30.0, 'vat' => 0.0, 'rate' => 0.0, 'code' => 'mimo', 'rate_id' => 9];

    public function testRateDifferenceMergesIntoOneForeignItemWithSourceTaxBase(): void
    {
        $merged = PohodaSelfAssessedCurrency::merge([self::ASSESSED, self::DIFFERENCE], 100.0);
        self::assertIsArray($merged);
        self::assertCount(1, $merged);
        self::assertEquals(['base' => 2530.0, 'vat' => 0.0, 'foreign_base' => 100.0, 'foreign_vat' => 0.0, 'tax_base_czk' => 2500.0, 'code' => '24e', 'rate' => 21.0, 'rate_id' => 7],
            array_intersect_key($merged[0], array_flip(['base', 'vat', 'foreign_base', 'foreign_vat', 'tax_base_czk', 'code', 'rate', 'rate_id'])));
        // Pojistka kurzu převodu: 100 EUR × 25,30 = 2 530 Kč faktury.
        self::assertNull(ForeignCurrencyTakeover::check(25.3, $merged, 2530.0));
    }

    public function testSameRateNeedsNoSourceTaxBase(): void
    {
        $merged = PohodaSelfAssessedCurrency::merge([['base' => 14290.18] + self::ASSESSED], 588.8);
        self::assertIsArray($merged);
        self::assertNull($merged[0]['tax_base_czk']);
        self::assertNull(ForeignCurrencyTakeover::check(24.27, $merged, 14290.18));
    }

    public function testMixedOrSplitSelfAssessmentStaysInCrowns(): void
    {
        self::assertSame('samovyměření u faktury s tuzemskou daní dodavatele',
            PohodaSelfAssessedCurrency::merge([self::ASSESSED, ['description' => 'Doprava', 'base' => 100.0, 'vat' => 21.0, 'rate' => 21.0, 'code' => '40']], 120.0));
        self::assertSame('samovyměření ve více sazbách nebo řádcích',
            PohodaSelfAssessedCurrency::merge([self::ASSESSED, ['rate' => 12.0] + self::ASSESSED], 200.0));
        self::assertSame('doklad nemá řádek samovyměření', PohodaSelfAssessedCurrency::merge([self::DIFFERENCE], 1.0));
    }

    public function testNote(): void
    {
        self::assertNull(PohodaSelfAssessedCurrency::note(null, 'EUR'));
        self::assertSame('samovyměření DPH ze základu 12 558,00 Kč kurzem ke dni plnění jako v POHODĚ, ne přepočtem EUR kurzem dokladu',
            PohodaSelfAssessedCurrency::note(12558.0, 'EUR'));
    }
}
