<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DpfoReturnCalculator;
use MyInvoice\Service\Tax\Return\DpfoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * VetaV/VetaJ — Příloha č. 2 (§9 nájem, §10 ostatní příjmy), viz private/DANE-PLAN.md
 * mezera č. 3. Věty dřív chyběly úplně, ačkoli VetaO.kc_zd9/kc_zd10 se posílaly bez podkladu.
 *
 * Hodnoty kc_vyd10/uhrn_vydaje10 a chování při jednořádkovém (nepoložkovém) vstupu jsou
 * ověřené proti zkušebnímu EPO 31. 8. 2026 (bisekce) — viz komentáře v DpfoXmlBuilder::buildAppendix2.
 */
final class DpfoXmlBuilderAppendix2Test extends TestCase
{
    private function sampleSupplier(): array
    {
        return [
            'id' => 1,
            'company_name' => 'Jan Novák',
            'street' => 'Krátká 12/3',
            'city' => 'Praha',
            'zip' => '110 00',
            'country_iso2' => 'CZ',
            'ic' => '87654321',
            'dic' => 'CZ7801011234',
            'taxpayer_type' => 'fo',
            'financial_office_code' => '451',
            'cz_nace_code' => '62020',
        ];
    }

    /** @param array<string,mixed> $inputs */
    private function calc(array $inputs, array $data = []): array
    {
        return (new DpfoReturnCalculator())->compute(
            ['s7_base' => 0, 'expense_mode' => 'pausal', 'expense_rate' => 60] + $data,
            $inputs + ['tax_paid_advances' => 0],
            [],
            TaxConstants::forYear(2025),
        );
    }

    private function build(array $calc, array $meta = []): array
    {
        return (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc, $meta);
    }

    public function testNoAppendix2WhenNoRentalOrOtherIncome(): void
    {
        $result = $this->build($this->calc([]));
        self::assertStringNotContainsString('<VetaV', $result['xml']);
        self::assertStringNotContainsString('<VetaJ', $result['xml']);
        self::assertStringNotContainsString('priloha2=', $result['xml']);
    }

    public function testVetaVBuiltWithOnlyRentalData(): void
    {
        $result = $this->build($this->calc(['s9_rental' => ['income' => 180000, 'expenses' => 60000]]));
        $xml = $result['xml'];

        self::assertStringContainsString('priloha2="1"', $xml);
        self::assertStringContainsString('<VetaV', $xml);
        self::assertStringContainsString('kc_prij9="180000"', $xml);
        self::assertStringContainsString('kc_vyd9="60000"', $xml);
        self::assertStringContainsString('kc_rozdil9="120000"', $xml);
        self::assertStringContainsString('kc_zd9p="120000"', $xml);
        self::assertStringContainsString('vyd9proc="N"', $xml);
        // Žádná §10 aktivita → VetaV nesmí tvrdit §10 atributy, VetaJ nesmí vzniknout.
        self::assertStringNotContainsString('kc_prij10=', $xml);
        self::assertStringNotContainsString('<VetaJ', $xml);
    }

    public function testVetaVAndVetaJBuiltWithItemizedOtherIncome(): void
    {
        $result = $this->build($this->calc(['s10_items' => [
            ['kind' => 'Prodej movité věci', 'income' => 150000, 'expenses' => 90000],
            ['kind' => 'Příležitostný příjem', 'income' => 25000, 'expenses' => 40000],
        ]]));
        $xml = $result['xml'];

        self::assertStringContainsString('priloha2="1"', $xml);
        self::assertStringContainsString('<VetaV', $xml);
        self::assertStringContainsString('kc_prij10="175000"', $xml);
        // kc_vyd10 MUSÍ být doslovný součet sloupce 3 (90000+40000=130000, nekrácené),
        // NE součet krácený na výši příjmu položky (90000+25000=115000) — zkušební EPO
        // 31. 8. 2026 s kráceným součtem odmítalo (viz DpfoXmlBuilder::buildAppendix2).
        self::assertStringContainsString('kc_vyd10="130000"', $xml);
        self::assertStringContainsString('uhrn_vydaje10="130000"', $xml);
        // kc_zd10p (ř.40, konečný dílčí základ) je naopak součet jen KLADNÝCH rozdílů:
        // 150000-90000=60000 (kladný), 25000-40000=-15000 (záporný, nezapočítá se) → 60000.
        self::assertStringContainsString('kc_zd10p="60000"', $xml);
        self::assertStringContainsString('uhrn_rozdil10="60000"', $xml);

        self::assertSame(2, substr_count($xml, '<VetaJ'));
        self::assertStringContainsString('prijmy10="150000" vydaje10="90000" rozdil10="60000" druh_prij10="Prodej movité věci"', $xml);
        self::assertStringContainsString('prijmy10="25000" vydaje10="40000" rozdil10="-15000" druh_prij10="Příležitostný příjem"', $xml);

        // Zákonná klasifikace druhu (A–H) appka nikdy nevyplní — jen varuje.
        self::assertStringNotContainsString('kod_dr_prij10=', $xml);
        $matches = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'kod_dr_prij10'));
        self::assertNotEmpty($matches, 'chybí varování o nevyplněné klasifikaci druhu §10');
    }

    public function testItemWithoutKindProducesWarningAndOmitsDruhPrij10(): void
    {
        $result = $this->build($this->calc(['s10_items' => [
            ['kind' => '', 'income' => 50000, 'expenses' => 10000],
        ]]));
        $xml = $result['xml'];

        self::assertStringContainsString('<VetaJ prijmy10="50000" vydaje10="10000" rozdil10="40000"/>', $xml);
        self::assertStringNotContainsString('druh_prij10', $xml);
        $matches = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'druh příjmu'));
        self::assertNotEmpty($matches, 'chybí varování o nevyplněném druhu příjmu položky');
    }

    /**
     * Legacy jednořádkový vstup (`s10_other`, bez rozpadu podle druhu) — appka Přílohu 2
     * NESMÍ sestavit, protože zkušební EPO ji bez podkladových VetaJ položek odmítá třemi
     * křížovými kontrolami (ověřeno 31. 8. 2026: "hodnota úhrnu příjmů/výdajů/rozdílů dle
     * § 10 ... neodpovídá součtu hodnot uvedeného sloupce" — prázdná tabulka nikdy nesedí
     * na nenulový souhrn). Prázdnou/nepodloženou přílohu netvrdíme, jen varujeme.
     */
    public function testAggregateOnlyOtherIncomeDoesNotBuildAppendix2(): void
    {
        $result = $this->build($this->calc(['s10_other' => ['income' => 60000, 'expenses' => 20000]]));

        self::assertStringNotContainsString('<VetaV', $result['xml']);
        self::assertStringNotContainsString('<VetaJ', $result['xml']);
        self::assertStringNotContainsString('priloha2=', $result['xml']);
        $matches = array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'souhrnné číslo bez rozpadu'),
        );
        self::assertNotEmpty($matches, 'chybí varování o nepodloženém souhrnu §10');
    }

    public function testVetaVFollowsVetaTAndPrecedesVetaN(): void
    {
        $calc = $this->calc(
            ['s9_rental' => ['income' => 180000, 'expenses' => 60000], 'tax_paid_advances' => 999999999],
        );
        $calc['bank_account'] = ['account_number' => '2000145399', 'bank_code' => '0100', 'bank_name' => 'Testovací banka', 'iban' => null];
        $xml = $this->build($calc)['xml'];

        $vetaTPos = strpos($xml, '<VetaT ');
        $vetaVPos = strpos($xml, '<VetaV ');
        $vetaNPos = strpos($xml, '<VetaN ');
        self::assertNotFalse($vetaTPos);
        self::assertNotFalse($vetaVPos);
        self::assertNotFalse($vetaNPos);
        self::assertLessThan($vetaVPos, $vetaTPos, 'VetaV musí následovat za VetaT v XSD sekvenci.');
        self::assertLessThan($vetaNPos, $vetaVPos, 'VetaV musí předcházet VetaN v XSD sekvenci.');
    }
}
