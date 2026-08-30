<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * VetaD (uv_rozsah/zvl_pr/p_pr_2od/spoj_zahr/dan_por), VetaF (příloha č. 1 II. oddílu,
 * tabulka B — odpisy) a VetaNP (žádost o vrácení přeplatku) — chyběly úplně, zjištěno
 * porovnáním s reálně podaným přiznáním (viz DppoXmlBuilder třídní docblock).
 */
final class DppoXmlBuilderVetaFAndNpTest extends TestCase
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

    // ── VetaD ────────────────────────────────────────────────────────────────

    public function testVetaDAlwaysCarriesZvlPrDanPorAndPPr2od(): void
    {
        $xml = $this->build($this->calc())['xml'];
        self::assertStringContainsString('zvl_pr="0"', $xml);
        self::assertStringContainsString('dan_por="N"', $xml);
        self::assertStringContainsString('p_pr_2od="0"', $xml, 'bez odpisů se VetaF nestaví, p_pr_2od = 0');
    }

    public function testSpojZahrDefaultsToNWithoutRelatedPartyData(): void
    {
        $xml = $this->build($this->calc())['xml'];
        self::assertStringContainsString('spoj_zahr="N"', $xml);
    }

    public function testSpojZahrReflectsRelatedPartyCountryFlag(): void
    {
        $xml = $this->build($this->calc(['related_party_country_flag' => 'A']))['xml'];
        self::assertStringContainsString('spoj_zahr="A"', $xml);
    }

    public function testUvRozsahSetOnlyAlongsideAppendix(): void
    {
        $withoutAppendix = $this->build($this->calc())['xml'];
        self::assertStringNotContainsString('uv_rozsah=', $withoutAppendix);

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
        $withAppendix = $this->build($this->calc(), [], $appendix)['xml'];
        self::assertStringContainsString('uv_rozsah="P"', $withAppendix);
    }

    public function testPPr2odIsOneWhenVetaFBuilt(): void
    {
        $xml = $this->build($this->calc(['depreciation_by_group' => ['tangible' => [1 => 1000.0], 'intangible' => 0.0, 'unclassified' => 0.0]]))['xml'];
        self::assertStringContainsString('p_pr_2od="1"', $xml);
    }

    // ── VetaF ────────────────────────────────────────────────────────────────

    public function testVetaFMapsTaxGroupsToDedicatedAttributes(): void
    {
        $xml = $this->build($this->calc(['depreciation_by_group' => [
            'tangible' => [1 => 1000.0, 2 => 2000.0, 6 => 6000.0],
            'intangible' => 300.0,
            'unclassified' => 0.0,
        ]]))['xml'];

        self::assertStringContainsString('<VetaF', $xml);
        self::assertStringContainsString('kc_dppb1="1000"', $xml);
        self::assertStringContainsString('kc_dppb2="2000"', $xml);
        self::assertStringContainsString('kc_dpp_b6="6000"', $xml);
        self::assertStringContainsString('kc_dpp_b_onm="300"', $xml);
        // ř.11 "celkem" (kc_dppb6_b8) — bez něj zkušební EPO vytýká "Hodnota ř. 11
        // Př.1/B II. oddílu není naplněna" i s korektně vyplněnými dílčími řádky.
        self::assertStringContainsString('kc_dppb6_b8="9300"', $xml);
    }

    public function testVetaFOmittedWithoutDepreciationData(): void
    {
        $result = $this->build($this->calc());
        self::assertStringNotContainsString('<VetaF', $result['xml']);
    }

    public function testUnclassifiedDepreciationWarnsAndIsExcludedFromVetaF(): void
    {
        $result = $this->build($this->calc(['depreciation_by_group' => [
            'tangible' => [],
            'intangible' => 0.0,
            'unclassified' => 500.0,
        ]]));
        self::assertStringNotContainsString('<VetaF', $result['xml']);
        $matches = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'odpisovou skupinu'));
        self::assertNotEmpty($matches, 'chybí varování o odpisech bez odpisové skupiny');
    }

    public function testVetaFPrecedesVetaMAndFollowsVetaO(): void
    {
        $xml = $this->build($this->calc(['depreciation_by_group' => ['tangible' => [1 => 1000.0], 'intangible' => 0.0, 'unclassified' => 0.0]]))['xml'];
        $vetaOPos = strpos($xml, '<VetaO');
        $vetaFPos = strpos($xml, '<VetaF');
        $vetaSPos = strpos($xml, '<VetaS');
        self::assertNotFalse($vetaOPos);
        self::assertNotFalse($vetaFPos);
        self::assertNotFalse($vetaSPos);
        self::assertGreaterThan($vetaOPos, $vetaFPos);
        self::assertLessThan($vetaSPos, $vetaFPos);
    }

    // ── VetaNP ───────────────────────────────────────────────────────────────

    private function overpaymentCalc(): array
    {
        // advances paid výrazně převyšují vypočtenou daň → balance_due záporné = přeplatek.
        return $this->calc([], ['tax_paid_advances' => 999999999]);
    }

    public function testVetaNpBuiltWhenOverpaymentAndBankAccountAvailable(): void
    {
        $calc = $this->overpaymentCalc();
        $calc['bank_account'] = ['account_number' => '19-2000145399', 'bank_code' => '0800', 'bank_name' => 'Testovací banka', 'iban' => null];
        $result = $this->build($calc);

        self::assertStringContainsString('<VetaNP', $result['xml']);
        self::assertStringContainsString('zp_vrac="U"', $result['xml']);
        self::assertStringContainsString('zvp_pbu="19"', $result['xml']);
        self::assertStringContainsString('zvp_c_komds="2000145399"', $result['xml']);
        self::assertStringContainsString('zvp_k_bank="0800"', $result['xml']);
        self::assertStringContainsString('zvp_naz_bank="Testovací banka"', $result['xml']);
        self::assertMatchesRegularExpression('/kc_preplatek="\d+"/', $result['xml']);
    }

    public function testVetaNpOmittedWithoutOverpayment(): void
    {
        $calc = $this->calc();
        $calc['bank_account'] = ['account_number' => '2000145399', 'bank_code' => '0100', 'bank_name' => 'Testovací banka', 'iban' => null];
        $result = $this->build($calc);
        self::assertStringNotContainsString('<VetaNP', $result['xml']);
    }

    public function testVetaNpOmittedAndWarnsWhenOverpaymentButNoBankAccount(): void
    {
        $calc = $this->overpaymentCalc();
        $calc['bank_account'] = null;
        $result = $this->build($calc);

        self::assertStringNotContainsString('<VetaNP', $result['xml']);
        $matches = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'bankovní účet'));
        self::assertNotEmpty($matches, 'chybí varování o chybějícím bankovním účtu pro vrácení přeplatku');
    }

    public function testVetaNpFollowsAllAppendixBlocksWhenAppendixPresent(): void
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
        $calc = $this->overpaymentCalc();
        $calc['bank_account'] = ['account_number' => '2000145399', 'bank_code' => '0100', 'bank_name' => 'Testovací banka', 'iban' => null];
        $xml = $this->build($calc, [], $appendix)['xml'];

        $vetaUZPos = strpos($xml, '<VetaUZ');
        $vetaNPPos = strpos($xml, '<VetaNP');
        self::assertNotFalse($vetaUZPos);
        self::assertNotFalse($vetaNPPos);
        self::assertGreaterThan($vetaUZPos, $vetaNPPos, 'VetaNP musí následovat za VetaUZ v XSD sekvenci');
    }
}
