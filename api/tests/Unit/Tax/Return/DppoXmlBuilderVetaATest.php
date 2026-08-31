<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * VetaA (přehled transakcí se spojenými osobami, § 23 odst. 7 ZDP) — schéma ji staví
 * úplně (dppdp9_epo2.xsd:4083), ale `DppoXmlBuilder` ji dosud vůbec negeneroval, přestože
 * podklad (RelatedPartyService/DppoReturnDataProvider::relatedPartyAppendix) existoval jen
 * jako návrh, nikdy se nedostal do XML (viz private/AUDIT-DPPO-XML.md, mezera č. 2).
 */
final class DppoXmlBuilderVetaATest extends TestCase
{
    private function sampleSupplier(): array
    {
        return [
            'company_name' => 'Ukázková firma s.r.o.', 'street' => 'Zkušební 123/4',
            'city' => 'Vzorov', 'zip' => '100 00', 'country_iso2' => 'CZ',
            'ic' => '12345678', 'dic' => 'CZ12345678', 'taxpayer_type' => 'po',
            'financial_office_code' => '451', 'cz_nace_code' => '62020',
        ];
    }

    /** @param array<string,mixed> $extraData */
    private function calc(array $extraData = [], array $inputs = ['tax_paid_advances' => 0]): array
    {
        return (new DppoReturnCalculator())->compute(
            ['vh' => 500000, 'depreciation' => ['tax' => 0, 'accounting' => 0]] + $extraData,
            $inputs,
            TaxConstants::forYear(2025)
        );
    }

    private function build(array $calc, array $meta = [], array $appendix = []): array
    {
        return (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc, $meta, $appendix);
    }

    public function testVetaAOmittedWithoutRelatedPartyTransactions(): void
    {
        $xml = $this->build($this->calc())['xml'];
        self::assertStringNotContainsString('<VetaA', $xml);
        self::assertStringContainsString('sam_pr="0"', $xml, 'Bez VetaA je sam_pr 0, ale musí být přítomen (reálná podání ho nesou vždy).');
    }

    /**
     * `sam_pr` (počet samostatných příloh) — zkušební EPO 31. 8. 2026 pojmenovalo VetaA
     * doslova „List č. N sam. přílohy k pol. 12" (private/AUDIT-DPPO-XML.md §11), takže
     * VetaA patří do sam_pr, NE do p_pr_2od/zvl_pr (viz testVetaADoesNotAffectPPr2odOrZvlPrCounts).
     */
    public function testSamPrCountsVetaAElements(): void
    {
        $calc = $this->calc(['related_party_appendix' => [
            ['name' => 'Dcera s.r.o.', 'country_iso2' => 'CZ', 'ic' => '19123450', 'issued_total' => 500_000.0, 'received_total' => 0.0],
            ['name' => 'Sister GmbH', 'country_iso2' => 'DE', 'ic' => null, 'issued_total' => 200_000.0, 'received_total' => 0.0],
        ]]);
        $xml = $this->build($calc)['xml'];
        self::assertStringContainsString('sam_pr="2"', $xml);
    }

    public function testSamPrZeroWhenRelatedPartyRowsRoundAwayToNothing(): void
    {
        $calc = $this->calc(['related_party_appendix' => [
            ['name' => 'Drobná spojená osoba', 'country_iso2' => 'CZ', 'ic' => null, 'issued_total' => 400.0, 'received_total' => 0.0],
        ]]);
        $xml = $this->build($calc)['xml'];
        self::assertStringContainsString('sam_pr="0"', $xml, 'Věta se do XML nedostala (zaokrouhleno na 0 tis.), sam_pr proto zůstává 0.');
    }

    public function testVetaABuiltForDomesticPartnerWithIssuedTransactions(): void
    {
        $calc = $this->calc(['related_party_appendix' => [
            ['name' => 'Dcera s.r.o.', 'country_iso2' => 'CZ', 'ic' => '27604977', 'issued_total' => 1_250_000.0, 'received_total' => 0.0],
        ]]);
        $xml = $this->build($calc)['xml'];

        self::assertStringContainsString(
            '<VetaA naz_spojos="Dcera s.r.o." stat_spojos="CZ" ic_spojos="27604977" ost_trans_sl1="1250"',
            $xml,
        );
        self::assertStringNotContainsString('ost_trans_sl2', $xml);
    }

    public function testVetaABuiltForForeignPartnerWithReceivedTransactionsOnly(): void
    {
        $calc = $this->calc(['related_party_appendix' => [
            ['name' => 'Sister GmbH', 'country_iso2' => 'DE', 'ic' => null, 'issued_total' => 0.0, 'received_total' => 87_500.0],
        ]]);
        $xml = $this->build($calc)['xml'];

        self::assertStringContainsString('<VetaA naz_spojos="Sister GmbH" stat_spojos="DE" ost_trans_sl2="88"', $xml);
        self::assertStringNotContainsString('ic_spojos', $xml);
        self::assertStringNotContainsString('ost_trans_sl1', $xml);
    }

    public function testVetaABuildsOneElementPerPartnerBothDirections(): void
    {
        $calc = $this->calc(['related_party_appendix' => [
            ['name' => 'Dcera s.r.o.', 'country_iso2' => 'CZ', 'ic' => '27604977', 'issued_total' => 500_000.0, 'received_total' => 300_000.0],
            ['name' => 'Sister GmbH', 'country_iso2' => 'DE', 'ic' => 'DE123456789', 'issued_total' => 200_000.0, 'received_total' => 0.0],
        ]]);
        $xml = $this->build($calc)['xml'];

        self::assertSame(2, substr_count($xml, '<VetaA '));
        self::assertStringContainsString('ost_trans_sl1="500" ost_trans_sl2="300"', $xml);
        self::assertStringContainsString('ost_trans_sl1="200"', $xml);
    }

    /** Kritická kontrola XSD zakazuje duplicitní (naz_spojos, stat_spojos) — dva záznamy stejné protistrany se musí sečíst do JEDNÉ věty. */
    public function testVetaADedupesSameNameAndCountryPairAndSumsAmounts(): void
    {
        $calc = $this->calc(['related_party_appendix' => [
            ['name' => 'Dcera s.r.o.', 'country_iso2' => 'CZ', 'ic' => '27604977', 'issued_total' => 500_000.0, 'received_total' => 0.0],
            ['name' => 'Dcera s.r.o.', 'country_iso2' => 'CZ', 'ic' => null, 'issued_total' => 250_000.0, 'received_total' => 0.0],
        ]]);
        $xml = $this->build($calc)['xml'];

        self::assertSame(1, substr_count($xml, '<VetaA '));
        self::assertStringContainsString('ost_trans_sl1="750"', $xml, 'Obě položky stejné protistrany se sečtou.');
        self::assertStringContainsString('ic_spojos="27604977"', $xml, 'IČO z jednoho záznamu se nesmí ztratit sloučením s druhým bez IČO.');
    }

    /** Zaokrouhlení na tisíce (VetaA, jako zbytek přílohy) — objem pod 500 Kč se zaokrouhlí na nulu a věta se vůbec nepošle. */
    public function testVetaAOmittedWhenBothTotalsRoundToZeroThousand(): void
    {
        $calc = $this->calc(['related_party_appendix' => [
            ['name' => 'Drobná spojená osoba', 'country_iso2' => 'CZ', 'ic' => null, 'issued_total' => 400.0, 'received_total' => 0.0],
        ]]);
        $xml = $this->build($calc)['xml'];
        self::assertStringNotContainsString('<VetaA', $xml);
    }

    public function testVetaAEmitsWarningAboutMissingCategorization(): void
    {
        $calc = $this->calc(['related_party_appendix' => [
            ['name' => 'Dcera s.r.o.', 'country_iso2' => 'CZ', 'ic' => null, 'issued_total' => 500_000.0, 'received_total' => 0.0],
        ]]);
        $result = $this->build($calc);

        self::assertNotEmpty(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'ostatní transakce') && str_contains($w, '1x'),
        ));
    }

    public function testVetaANoWarningWithoutRelatedPartyTransactions(): void
    {
        $result = $this->build($this->calc());
        self::assertSame([], array_values(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'VetaA'),
        )));
    }

    /** Sekvence bez appendixu: VetaA se staví i tak, hned za VetaS/VetaR, nezávisle na příloze účetní závěrky. */
    public function testVetaABuildsWithoutAccountingAppendix(): void
    {
        $calc = $this->calc(['related_party_appendix' => [
            ['name' => 'Dcera s.r.o.', 'country_iso2' => 'CZ', 'ic' => null, 'issued_total' => 500_000.0, 'received_total' => 0.0],
        ]]);
        $xml = $this->build($calc)['xml'];

        self::assertStringContainsString('<VetaA', $xml);
        self::assertStringNotContainsString('<VetaUA', $xml);
        self::assertStringNotContainsString('<VetaUZ', $xml);
    }

    /** Sekvence s appendixem: dppdp9_epo2.xsd řadí VetaA až ZA celý blok VetaUA/UB/UD, PŘED VetaUZ. */
    public function testVetaASequencedAfterAccountingAppendixAndBeforeVetaUZ(): void
    {
        $appendix = [
            'balance_sheet' => ['assets' => [
                ['row_code' => 'AKTIVA', 'gross' => 1000.0, 'correction' => 0.0, 'net' => 1000.0, 'prev_net' => 1000.0],
            ], 'liabilities' => [
                ['row_code' => 'PASIVA', 'amount' => 1000.0, 'prev_amount' => 1000.0],
            ]],
            'income_statement' => ['rows' => [
                ['row_code' => 'I.', 'amount' => 1000000.0, 'prev_amount' => 0.0],
            ]],
        ];
        $calc = $this->calc(['related_party_appendix' => [
            ['name' => 'Dcera s.r.o.', 'country_iso2' => 'CZ', 'ic' => null, 'issued_total' => 500_000.0, 'received_total' => 0.0],
        ]]);
        $xml = $this->build($calc, [], $appendix)['xml'];

        $vetaUDPos = strpos($xml, '<VetaUD');
        $vetaAPos = strpos($xml, '<VetaA ');
        $vetaUZPos = strpos($xml, '<VetaUZ');
        self::assertNotFalse($vetaUDPos);
        self::assertNotFalse($vetaAPos);
        self::assertNotFalse($vetaUZPos);
        self::assertGreaterThan($vetaUDPos, $vetaAPos, 'VetaA musí následovat AŽ za celým blokem VetaUA/UB/UD.');
        self::assertGreaterThan($vetaAPos, $vetaUZPos, 'VetaA musí předcházet VetaUZ (dppdp9_epo2.xsd sekvence).');
    }

    /**
     * Regrese: VetaA NENÍ ani „příloha II. oddílu" (VetaE/VetaF, p_pr_2od), ani „zvláštní
     * příloha" (VetaR, zvl_pr) — je to strukturně jiný typ přílohy (dppdp9_epo2.xsd ji řadí
     * až za celý blok VetaUA–VetaUU, mimo skupinu VetaE/F/G…/Z před VetaS). Přidání VetaA
     * nesmí tyhle dva počítadla nijak změnit.
     */
    public function testVetaADoesNotAffectPPr2odOrZvlPrCounts(): void
    {
        $calc = $this->calc([
            'non_deductible_costs' => 24800,
            'related_party_appendix' => [
                ['name' => 'Dcera s.r.o.', 'country_iso2' => 'CZ', 'ic' => null, 'issued_total' => 500_000.0, 'received_total' => 300_000.0],
                ['name' => 'Sister GmbH', 'country_iso2' => 'DE', 'ic' => null, 'issued_total' => 200_000.0, 'received_total' => 0.0],
            ],
        ], [
            'tax_paid_advances' => 0,
            'manual_increase_items' => [['text' => 'Reprezentace §25/1/t', 'amount' => 24800]],
        ]);
        $xml = $this->build($calc)['xml'];

        self::assertStringContainsString('<VetaA', $xml);
        // Beze změny oproti chování bez VetaA (viz DppoXmlBuilderVetaEAndRTest): jen VetaE
        // (p_pr_2od=1) a jedna VetaR (zvl_pr=1). sam_pr (jiné počítadlo, viz testSamPrCountsVetaAElements)
        // naopak VetaA počítat MUSÍ — 2 protistrany = sam_pr=2.
        self::assertStringContainsString('p_pr_2od="1"', $xml);
        self::assertStringContainsString('zvl_pr="1"', $xml);
        self::assertStringContainsString('sam_pr="2"', $xml);
    }
}
