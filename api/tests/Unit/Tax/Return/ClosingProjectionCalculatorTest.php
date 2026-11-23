<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\ClosingProjectionCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Jednotkové testy projekce závěrkových operací do VH (Feature 1, Epic DP). Ověřuje směr
 * (+/−) a zahrnutí dopadů do vh_projected: 381 defer (+), fx zisk−ztráta (±), rozpuštění 381
 * minulého období (−); informativní návrhy (opravné položky, dohady) se do vh_projected NEpočítají.
 */
final class ClosingProjectionCalculatorTest extends TestCase
{
    private ClosingProjectionCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new ClosingProjectionCalculator();
    }

    /** Sanity dle zadání (FY2024): 5 685 370 + §DM 90 140 + prepaid 41 128 = 5 816 638. */
    public function testDeferralsRaiseProjectedVhTowardsSanityTarget(): void
    {
        $r = $this->calc->project(5685370.0, [
            'small_asset' => ['existing' => null, 'total' => 90140.0],
            'prepaid' => ['existing' => null, 'total' => 41128.0],
        ]);
        self::assertTrue($r['is_projection']);
        self::assertSame(5685370.0, $r['vh_posted']);
        self::assertSame(5816638.0, $r['vh_projected']);
        self::assertCount(2, $r['items']);
        self::assertSame(1, $r['items'][0]['sign']);
    }

    /** Už zaúčtované 381 (existing != null) se do projekce NEzahrne (jinak dvojí započtení). */
    /** Agenda Časové rozlišení: nezaúčtované rozpuštění výnosu 384 zvýší VH, nákladu 381 sníží. */
    public function testAccrualAgendaPendingReleaseNetsRevenueAgainstExpense(): void
    {
        $r = $this->calc->project(100_000.0, ['accrual_agenda' => ['expense' => 1_500.0, 'revenue' => 4_000.0, 'items' => 3]]);
        self::assertSame(102_500.0, $r['vh_projected']);
        self::assertSame('accrual_agenda_release', $r['items'][0]['key']);
        self::assertSame(1, $r['items'][0]['sign']);
        self::assertSame(2_500.0, $r['items'][0]['amount']);

        $loss = $this->calc->project(100_000.0, ['accrual_agenda' => ['expense' => 700.0, 'revenue' => 0.0]]);
        self::assertSame(99_300.0, $loss['vh_projected']);
        self::assertSame(100_000.0, $this->calc->project(100_000.0, ['accrual_agenda' => ['expense' => 0.0, 'revenue' => 0.0]])['vh_projected']);
    }

    public function testPostedDeferralsAreSkipped(): void
    {
        $r = $this->calc->project(1000000.0, [
            'small_asset' => ['existing' => ['entry_id' => 5], 'total' => 90140.0],
            'prepaid' => ['existing' => null, 'total' => 41128.0],
        ]);
        self::assertSame(1041128.0, $r['vh_projected']);
        self::assertCount(1, $r['items']);
        self::assertSame('prepaid_expense_accrual', $r['items'][0]['key']);
    }

    /** Kurzové rozdíly: zisk 663 (+) − ztráta 563 (−). Čistá ztráta → −VH. */
    public function testFxNetLossReducesProjectedVh(): void
    {
        $r = $this->calc->project(1000000.0, [
            'fx' => ['totals' => ['gain' => 2000.0, 'loss' => 5000.0]],
        ]);
        self::assertSame(997000.0, $r['vh_projected']);
        self::assertSame('fx_revaluation', $r['items'][0]['key']);
        self::assertSame(-1, $r['items'][0]['sign']);
        self::assertSame(3000.0, $r['items'][0]['amount']);
    }

    /** Rozpuštění 381 minulého období zvyšuje náklad → −VH. */
    public function testPriorReleaseReducesProjectedVh(): void
    {
        $r = $this->calc->project(1000000.0, [
            'prior_release' => ['applicable' => true, 'total' => 25000.0],
        ]);
        self::assertSame(975000.0, $r['vh_projected']);
        self::assertSame('prior_deferral_release', $r['items'][0]['key']);
        self::assertSame(-1, $r['items'][0]['sign']);
    }

    /** Opravné položky a dohady jsou INFORMATIVNÍ — zobrazí se, ale do vh_projected nevstupují. */
    public function testProvisionsAndEstimatesAreOptionalAndExcludedFromProjectedVh(): void
    {
        $r = $this->calc->project(1000000.0, [
            'provisions' => ['totals' => ['suggested_legal' => 50000.0, 'existing_legal' => 10000.0]],
            'estimates' => ['totals' => ['suggested_amount' => 8000.0]],
        ]);
        // Žádný neinformativní dopad → není to „projekce“ VH, číslo se nemění.
        self::assertFalse($r['is_projection']);
        self::assertSame(1000000.0, $r['vh_projected']);
        self::assertCount(2, $r['items']);
        foreach ($r['items'] as $it) {
            self::assertTrue($it['optional']);
            self::assertSame(-1, $it['sign']);
        }
        // Opravná položka = jen pending část nad rámec už vytvořených OP (50 000 − 10 000).
        $prov = array_values(array_filter($r['items'], static fn ($i) => $i['key'] === 'provision'))[0];
        self::assertSame(40000.0, $prov['amount']);
    }

    /** Nezaúčtované odpisy: účetní snižují VH, daňové jdou dál do rozdílu odpisů (ř. 50/150). */
    public function testPendingDepreciationReducesProjectedVhAndCarriesTaxPart(): void
    {
        $r = $this->calc->project(1000000.0, [
            'depreciation' => ['pending_accounting' => 24000.0, 'pending_tax' => 13200.0],
        ]);
        self::assertTrue($r['is_projection']);
        self::assertSame(976000.0, $r['vh_projected']);
        self::assertSame(['accounting' => 24000.0, 'tax' => 13200.0], $r['depreciation']);
        self::assertSame(['depreciation', -1, false], [$r['items'][0]['key'], $r['items'][0]['sign'], $r['items'][0]['optional']]);
    }

    /** Jen daňový odpis bez účetního (např. účetní už zaúčtovaný) je projekcí, i když VH nemění. */
    public function testPendingTaxDepreciationAloneIsProjection(): void
    {
        $r = $this->calc->project(1000000.0, ['depreciation' => ['pending_accounting' => 0.0, 'pending_tax' => 5000.0]]);
        self::assertTrue($r['is_projection']);
        self::assertSame(1000000.0, $r['vh_projected']);
        self::assertSame([], $r['items']);
    }

    /** Konečný stav zásob (způsob B) zvyšuje VH. */
    public function testStockClosingRaisesProjectedVh(): void
    {
        $r = $this->calc->project(1000000.0, ['stock' => ['applicable' => true, 'posted' => false, 'total' => 35000.0]]);
        self::assertSame(1035000.0, $r['vh_projected']);
        self::assertSame(['stock_closing', 1, false], [$r['items'][0]['key'], $r['items'][0]['sign'], $r['items'][0]['optional']]);
    }

    /** Prázdné zdroje → žádná projekce, vh_projected == vh_posted. */
    public function testEmptySourcesYieldNoProjection(): void
    {
        $r = $this->calc->project(1234.56, []);
        self::assertFalse($r['is_projection']);
        self::assertSame(1234.56, $r['vh_projected']);
        self::assertSame([], $r['items']);
    }
}
