<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * VetaS (`poc_zam`/`kc_dpp_i1`/`cisobr_mena`) — reálné podání 30. 8. 2026 vrátilo chyby
 * EPO 1704 („poc_zam by měl být vyplněn, a to včetně případné hodnoty 0") a 1703 („Roční
 * úhrn čistého obratu není naplněn"), protože builder VetaS vůbec nestavěl.
 */
final class DppoXmlBuilderVetaSTest extends TestCase
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

    private function sampleCalc(): array
    {
        return (new DppoReturnCalculator())->compute(
            ['vh' => 500000, 'depreciation' => ['tax' => 0, 'accounting' => 0]],
            ['tax_paid_advances' => 0],
            TaxConstants::forYear(2025)
        );
    }

    private function build(array $meta = [], array $appendix = []): array
    {
        return (new DppoXmlBuilder())->build($this->sampleSupplier(), 2025, $this->sampleCalc(), $meta, $appendix);
    }

    /** Explicitní $meta['poc_zam'] má přednost a nevyvolá varování. */
    public function testPocZamFromMetaTakesPrecedence(): void
    {
        $result = $this->build(['poc_zam' => 5], ['settings' => ['avg_employees' => 99]]);
        self::assertStringContainsString('poc_zam="5"', $result['xml']);
        self::assertSame([], array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'zaměstnanců')));
    }

    /** Bez $meta se bere hodnota z appendix['settings']['avg_employees'] (Nastavení výkaznictví). */
    public function testPocZamFromAppendixSettings(): void
    {
        $result = $this->build([], ['settings' => ['avg_employees' => 3]]);
        self::assertStringContainsString('poc_zam="3"', $result['xml']);
        self::assertSame([], array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'zaměstnanců')));
    }

    /** Legitimní nula (skutečně žádný zaměstnanec) se vyplní bez varování — je to platná hodnota, ne chybějící údaj. */
    public function testPocZamExplicitZeroDoesNotWarn(): void
    {
        $result = $this->build([], ['settings' => ['avg_employees' => 0]]);
        self::assertStringContainsString('poc_zam="0"', $result['xml']);
        self::assertSame([], array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'zaměstnanců')));
    }

    /**
     * Chybějící údaj (žádný $meta, žádné settings) — atribut se PŘESTO vyplní nulou
     * (XSD dokumentace k poc_zam: „při nule nebo bez zaměstnanců uvede se hodnota 0"),
     * ale varování upozorní účetní, že hodnotu má ověřit.
     */
    public function testPocZamMissingDefaultsToZeroWithWarning(): void
    {
        $result = $this->build();
        self::assertStringContainsString('poc_zam="0"', $result['xml']);
        $matches = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, '1704'));
        self::assertNotEmpty($matches, 'chybí varování o chybějícím počtu zaměstnanců (EPO 1704)');
    }

    /** kc_dpp_i1 = součet řádků VZZ 'I.' (výrobky a služby) + 'II.' (zboží), ne 'OBRAT'. */
    public function testKcDppI1SumsTurnoverRowsFromIncomeStatement(): void
    {
        $appendix = ['income_statement' => ['rows' => [
            ['row_code' => 'I.', 'amount' => 1000000.0, 'prev_amount' => 0.0],
            ['row_code' => 'II.', 'amount' => 200000.0, 'prev_amount' => 0.0],
            // Širší 'OBRAT' (I.–VII.) MUSÍ se ignorovat — jiný pojem než čistý obrat §1d.
            ['row_code' => 'OBRAT', 'amount' => 9999999.0, 'prev_amount' => 0.0],
        ]]];
        $result = $this->build([], $appendix);
        self::assertStringContainsString('kc_dpp_i1="1200000"', $result['xml']);
        self::assertStringContainsString('cisobr_mena="CZK"', $result['xml']);
    }

    /**
     * Bez income_statement se vynechá jen `kc_dpp_i1` a přijde varování (chyba EPO 1703).
     * `cisobr_mena` zůstává: zkušební EPO vrátilo KRITICKOU chybu „Měna čistého obratu
     * v tabulce K musí být vyplněna“ i u věty, která nesla jen `poc_zam` — měna visí na
     * existenci tabulky K, ne na obratu.
     */
    public function testKcDppI1OmittedWithoutIncomeStatementAndWarns(): void
    {
        $result = $this->build();
        self::assertStringNotContainsString('kc_dpp_i1', $result['xml']);
        self::assertStringContainsString('cisobr_mena="CZK"', $result['xml']);
        $matches = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, '1703'));
        self::assertNotEmpty($matches, 'chybí varování o nenaplněném čistém obratu (EPO 1703)');
    }

    /** VetaS musí v XML sekvenci (XSD) předcházet VetaUA — jinak schema validace spadne. */
    public function testVetaSPrecedesAppendixBlocks(): void
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
            'settings' => ['avg_employees' => 2],
        ];
        $xml = $this->build([], $appendix)['xml'];
        $vetaSPos = strpos($xml, '<VetaS');
        $vetaUAPos = strpos($xml, '<VetaUA');
        self::assertNotFalse($vetaSPos);
        self::assertNotFalse($vetaUAPos);
        self::assertLessThan($vetaUAPos, $vetaSPos, 'VetaS musí předcházet VetaUA v XSD sekvenci');
    }

    /** Zpětná kompatibilita: build() bez $appendix (stávající volání/testy) nesmí spadnout. */
    public function testBuildWithoutAppendixStillWorks(): void
    {
        $result = $this->build();
        self::assertStringContainsString('<VetaS', $result['xml']);
    }
}
