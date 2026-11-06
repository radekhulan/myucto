<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\PohodaAssetPlan;
use PHPUnit\Framework\TestCase;

/**
 * Karta majetku z tabulek POHODY: počáteční stavy daňových a účetních odpisů tak, aby
 * odpisy v MyÚčtu navázaly přesně za posledním měsícem zaúčtovaným v POHODĚ.
 */
final class PohodaAssetPlanTest extends TestCase
{
    /** Rok převodu je v POHODĚ zaúčtovaný do srpna, MyÚčto pokračuje zářím se stejným koncem plánu. */
    public function testContinuesAfterLastBookedMonth(): void
    {
        $plan = PohodaAssetPlan::build(self::card(), [
            ['Rok' => '2024', 'KcOdpis' => '66000', 'RelUzavreno' => '1'],
            ['Rok' => '2025', 'KcOdpis' => '133500', 'RelUzavreno' => '1'],
            ['Rok' => '2026', 'KcOdpis' => '133500'],
        ], self::months('2024-01', 60, 10000), 2026, '2026-08');

        self::assertSame([], $plan['review']);
        $card = $plan['card'];
        self::assertSame('in_use', $card['status']);
        self::assertSame('24IM0001', $card['inventory_number']);
        self::assertSame('tangible', $card['kind']);
        self::assertSame('straight', $card['tax_method']);
        self::assertSame(2, $card['tax_group']);
        self::assertSame(2, $card['opening_tax_years']);
        self::assertSame(199500.0, $card['opening_tax_amount']);
        // POHODA odpisuje od měsíce zařazení (leden 2024 až srpen 2026 = 32 měsíců),
        // MyÚčto od měsíce následujícího - navazující měsíc je tak září 2026.
        self::assertSame(31, $card['opening_acc_months']);
        self::assertSame(320000.0, $card['opening_acc_amount']);
        self::assertSame(59, $card['acc_useful_life_months']);
        self::assertSame('straight_line', $card['acc_method']);
        // Zbývá 28 měsíců a 280 000 Kč - měsíční odpis zůstává 10 000 Kč jako v POHODĚ.
        self::assertSame(280000.0 / 28, ($card['input_price'] - $card['opening_acc_amount']) / ($card['acc_useful_life_months'] - $card['opening_acc_months']));
    }

    /** Zrychlené odpisy a plně odepsaná karta: plán skončil před rokem převodu. */
    public function testFullyDepreciatedAccelerated(): void
    {
        $plan = PohodaAssetPlan::build(self::card(['RelTpOdp' => '2', 'Datum' => '2019-01-10', 'DatZar' => '2019-01-10', 'Kc' => '36000', 'KcDanova' => '36000']), [
            ['Rok' => '2019', 'KcOdpis' => '7200', 'RelUzavreno' => '1'],
            ['Rok' => '2020', 'KcOdpis' => '14400', 'RelUzavreno' => '1'],
            ['Rok' => '2021', 'KcOdpis' => '14400', 'RelUzavreno' => '1'],
        ], self::months('2019-01', 36, 1000), 2026, '2025-12');

        $card = $plan['card'];
        self::assertSame('accelerated', $card['tax_method']);
        self::assertSame(3, $card['opening_tax_years']);
        self::assertSame(36000.0, $card['opening_tax_amount']);
        self::assertSame(35, $card['opening_acc_months']);
        self::assertSame(36000.0, $card['opening_acc_amount']);
        self::assertSame(35, $card['acc_useful_life_months']);
    }

    /** Dvoumístný kód skupiny (42 u osobního auta) je skupina 2 ve zvláštním režimu: převezme se jako koncept ke kontrole. */
    public function testTwoDigitGroupCodeIsDerivedForReview(): void
    {
        $plan = PohodaAssetPlan::build(self::card(['RelSkOdp' => '42', 'Datum' => '2023-08-01', 'DatZar' => '2023-08-01', 'Kc' => '1082889.01', 'KcDanova' => '1082889.01']), [
            ['Rok' => '2023', 'KcOdpis' => '216580', 'RelUzavreno' => '1'],
            ['Rok' => '2024', 'KcOdpis' => '577541.41', 'RelUzavreno' => '1'],
            ['Rok' => '2025', 'KcOdpis' => '288767.6', 'RelUzavreno' => '1'],
        ], self::months('2023-08', 60, 18048), 2026, '2025-12');

        self::assertSame(2, $plan['card']['tax_group']);
        self::assertSame('draft', $plan['card']['status']);
        self::assertSame(1082889.01, $plan['card']['opening_tax_amount']);
        self::assertContains('odpisová skupina 2 je odvozená z kódu POHODY 42, zkontrolujte ji', $plan['review']);
    }

    /** Karta bez daňových odpisů jen s účetním plánem; neznámý typ majetku jde ke kontrole jako koncept. */
    public function testWithoutTaxDepreciationAndUnknownKindGoesToReview(): void
    {
        $plan = PohodaAssetPlan::build(self::card(['RelTpIM' => '9', 'RelTpOdp' => '6', 'RelSkOdp' => '', 'Datum' => '2025-02-01', 'DatZar' => '2025-02-01']), [],
            self::months('2025-02', 180, 700), 2026, '2026-08');

        $card = $plan['card'];
        self::assertSame('none', $card['tax_method']);
        self::assertNull($card['tax_group']);
        self::assertSame('draft', $card['status']);
        self::assertNotEmpty($plan['review']);
        self::assertSame(18, $card['opening_acc_months']);
        self::assertSame(19 * 700.0, $card['opening_acc_amount']);
        self::assertSame(179, $card['acc_useful_life_months']);
    }

    /** Rozdílná daňová vstupní cena ani chybějící účetní plán se tiše nepřevezmou. */
    public function testDifferentTaxPriceAndMissingPlanGoToReview(): void
    {
        $plan = PohodaAssetPlan::build(self::card(['KcDanova' => '590000']), [['Rok' => '2025', 'KcOdpis' => '66000', 'RelUzavreno' => '1']], [], 2026, '2025-12');
        self::assertCount(2, $plan['review']);
        self::assertSame('draft', $plan['card']['status']);
    }

    /**
     * Pohyb karty jiného druhu než zařazení (2) a odpisy (7, 8) - například technické
     * zhodnocení - převod nepřebírá; karta jde ke kontrole, zařazení a odpisy ne.
     */
    public function testUnknownMovementGoesToReview(): void
    {
        $tax = [['Rok' => '2024', 'KcOdpis' => '66000', 'RelUzavreno' => '1'], ['Rok' => '2025', 'KcOdpis' => '133500', 'RelUzavreno' => '1']];
        $plain = PohodaAssetPlan::build(self::card(), $tax, self::months('2024-01', 60, 10000), 2026, '2025-12',
            [['RelTpPoh' => '2', 'Kc' => '600000'], ['RelTpPoh' => '7', 'Kc' => '66000'], ['RelTpPoh' => '8', 'Kc' => '120000']]);
        self::assertSame([], $plain['review']);

        $improved = PohodaAssetPlan::build(self::card(), $tax, self::months('2024-01', 60, 10000), 2026, '2025-12',
            [['RelTpPoh' => '2', 'Kc' => '600000'], ['RelTpPoh' => '3', 'Kc' => '50000']]);
        self::assertSame('draft', $improved['card']['status']);
        self::assertCount(1, $improved['review']);
        self::assertStringContainsString('3', $improved['review'][0]);
    }

    /** Rok přerušení odpisů (sazba 0) se do počtu odepsaných let nepočítá - jinak by plán skončil o rok dřív. */
    public function testPausedYearIsNotCounted(): void
    {
        $plan = PohodaAssetPlan::build(self::card(['RelTpOdp' => '5']), [
            ['Rok' => '2023', 'KcOdpis' => '66000', 'Procento' => '11'],
            ['Rok' => '2024', 'KcOdpis' => '0', 'Procento' => '0'],
            ['Rok' => '2025', 'KcOdpis' => '133500', 'Procento' => '22.25'],
        ], self::months('2023-01', 72, 8333), 2026, '2025-12');

        self::assertSame([], $plan['review']);
        self::assertSame('straight', $plan['card']['tax_method'], 'Způsob 5 je varianta rovnoměrného odpisu.');
        self::assertSame(2, $plan['card']['opening_tax_years']);
        self::assertSame(199500.0, $plan['card']['opening_tax_amount']);
    }

    /** Nehmotný majetek podle § 32a (typ 3, způsob 11, ve skupině je počet měsíců) a neodpisovaný majetek (způsob 4). */
    public function testIntangibleByMonthsAndNotDepreciated(): void
    {
        $intangible = PohodaAssetPlan::build(self::card(['RelTpIM' => '3', 'RelTpOdp' => '11', 'RelSkOdp' => '36', 'Datum' => '2025-03-10', 'DatZar' => '2025-03-10', 'Kc' => '36000', 'KcDanova' => '36000']),
            [['Rok' => '2025', 'KcOdpis' => '10000', 'Procento' => '27.78']], self::months('2025-04', 36, 1000), 2026, '2025-12');
        self::assertSame([], $intangible['review']);
        self::assertSame('intangible', $intangible['card']['kind']);
        self::assertSame('by_accounting', $intangible['card']['tax_method']);
        self::assertNull($intangible['card']['tax_group']);

        $land = PohodaAssetPlan::build(self::card(['RelTpOdp' => '4', 'RelSkOdp' => '']), [['Rok' => '2017', 'KcOdpis' => '0', 'Procento' => '0']],
            self::months('2024-01', 1, 0), 2026, '2025-12');
        self::assertSame('none', $land['card']['tax_method']);
        self::assertSame(0, $land['card']['opening_tax_years']);
        self::assertNotContains('odpisová skupina chybí', $land['review']);
    }

    /** @param array<string,string> $over */
    private static function card(array $over = []): array
    {
        return $over + [
            'ID' => '1', 'Cislo' => '24IM0001', 'SText' => 'Testovací stroj', 'RelTpIM' => '1', 'RelSkOdp' => '2', 'RelTpOdp' => '1',
            'Datum' => '2024-01-20', 'DatZar' => '2024-01-20', 'Kc' => '600000', 'KcDanova' => '600000',
        ];
    }

    /** @return list<array{Mesic:string,KcOdpis:string}> */
    private static function months(string $from, int $count, int $amount): array
    {
        [$y, $m] = array_map('intval', explode('-', $from));
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = ['Mesic' => sprintf('%04d-%02d-01', $y + intdiv($m - 1 + $i, 12), ($m - 1 + $i) % 12 + 1), 'KcOdpis' => (string) $amount];
        }
        return $out;
    }
}
