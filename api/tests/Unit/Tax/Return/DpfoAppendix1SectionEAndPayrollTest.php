<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DpfoReturnCalculator;
use MyInvoice\Service\Tax\Return\DpfoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Příloha č. 1 DPFO — položkový rozpis oddílu E (P-5) a údaj „Mzdy" (P-8),
 * viz private/DANE-PLAN.md.
 *
 * P-5: `DpfoReturnCalculator` uměl `s7_increase_items`/`s7_decrease_items` a builder na
 * ně byl připravený, ale podklady je nikdy nepředávaly (čtly se jen součty), takže se
 * v produkci VŽDY jel fallback „jeden souhrnný řádek + varování".
 *
 * P-8: `kc_dpfmz18` (celkový objem zúčtovaných mezd za období) neměl builder v mapě
 * VetaU vůbec — tiskopis ho chce, aplikace ho neposílala. NENÍ to devátá položka tabulky
 * majetku a dluhů, proto k němu neexistuje protějšek `kc_z_dpfmz18`.
 */
final class DpfoAppendix1SectionEAndPayrollTest extends TestCase
{
    /** @return array<string,mixed> */
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

    /** @return array<string,mixed> */
    private function closing(): array
    {
        return [
            'status' => 'final',
            'opening_balances' => ['fixed_assets' => 100000, 'cash' => 5000],
            'closing_balances' => ['fixed_assets' => 90000, 'cash' => 7000],
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $inputs
     * @return array{xml:string,warnings:list<string>}
     */
    private function build(array $data, array $inputs = []): array
    {
        $calc = (new DpfoReturnCalculator())->compute(
            ['s7_income' => 500000, 's7_expenses' => 200000, 'expense_mode' => 'actual', 'expense_rate' => 0] + $data,
            $inputs + ['tax_paid_advances' => 0],
            [],
            TaxConstants::forYear(2025),
        );
        return (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc, []);
    }

    // ── P-5: položkový oddíl E ──────────────────────────────────────────────

    public function testAdjustmentItemsBecomeIndividualVetaCRows(): void
    {
        $result = $this->build([
            's7_increase' => 30000,
            's7_increase_items' => [
                ['amount' => 20000, 'description' => 'Nepeněžní příjem ze zápočtu'],
                ['amount' => 10000, 'description' => 'Osobní spotřeba zásob'],
            ],
        ]);
        $xml = $result['xml'];

        self::assertSame(2, substr_count($xml, '<VetaC'));
        self::assertStringContainsString('kc_uprzvys_235="20000" uprzvys_235="Nepeněžní příjem ze zápočtu"', $xml);
        self::assertStringContainsString('kc_uprzvys_235="10000" uprzvys_235="Osobní spotřeba zásob"', $xml);
        self::assertStringNotContainsString('souhrn)', $xml);
        self::assertSame([], array_values(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'JEDEN souhrnný řádek'),
        )));
    }

    public function testDecreaseItemsBecomeIndividualVetaERows(): void
    {
        $xml = $this->build([
            's7_decrease' => 15000,
            's7_decrease_items' => [
                ['amount' => 9000, 'description' => 'Zaplacené pojistné z minulého období'],
                ['amount' => 6000, 'description' => 'Oprava duplicitního výnosu'],
            ],
        ])['xml'];

        self::assertSame(2, substr_count($xml, '<VetaE'));
        self::assertStringContainsString('uprsniz_235="Zaplacené pojistné z minulého období"', $xml);
        self::assertStringContainsString('uprsniz_235="Oprava duplicitního výnosu"', $xml);
    }

    /** Součet položek oddílu E musí sedět na úhrn ř. 105/106 (VetaT.kc_uhzvys/kc_uhsniz). */
    public function testSectionEItemsSumMatchesAppendixTotals(): void
    {
        $xml = $this->build([
            's7_increase' => 30000,
            's7_increase_items' => [
                ['amount' => 20000, 'description' => 'Nepeněžní příjem ze zápočtu'],
                ['amount' => 10000, 'description' => 'Osobní spotřeba zásob'],
            ],
            's7_decrease' => 15000,
            's7_decrease_items' => [
                ['amount' => 9000, 'description' => 'Zaplacené pojistné z minulého období'],
                ['amount' => 6000, 'description' => 'Oprava duplicitního výnosu'],
            ],
        ])['xml'];

        self::assertStringContainsString('kc_uhzvys="30000"', $xml);
        self::assertStringContainsString('kc_uhsniz="15000"', $xml);

        preg_match_all('/kc_uprzvys_235="(\d+)"/', $xml, $increase);
        preg_match_all('/kc_uprsniz_235="(\d+)"/', $xml, $decrease);
        self::assertSame(30000, array_sum(array_map('intval', $increase[1])));
        self::assertSame(15000, array_sum(array_map('intval', $decrease[1])));
    }

    /** Fallback zůstává pro případ, kdy položky opravdu nejsou. */
    public function testWithoutItemsFallbackStillProducesSingleRowAndWarning(): void
    {
        $result = $this->build(['s7_increase' => 30000]);

        self::assertSame(1, substr_count($result['xml'], '<VetaC'));
        self::assertStringContainsString('kc_uprzvys_235="30000"', $result['xml']);
        self::assertNotEmpty(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'JEDEN souhrnný řádek'),
        ));
    }

    /** Nesouhlas součtu položek s úhrnem se nesmí ztratit. */
    public function testItemsNotMatchingTotalProduceWarning(): void
    {
        $result = $this->build([
            's7_increase' => 30000,
            's7_increase_items' => [['amount' => 12000, 'description' => 'Neúplný rozpis']],
        ]);

        self::assertNotEmpty(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'neodpovídá částce na ř. 105'),
        ));
    }

    public function testSectionEOverflowPreservesAmountsWithinSchemaLimit(): void
    {
        foreach (['increase' => ['VetaC', 'kc_uprzvys_235'], 'decrease' => ['VetaE', 'kc_uprsniz_235']] as $direction => [$element, $attribute]) {
            foreach ([100, 99, 150] as $count) {
                $result = $this->build([
                    's7_' . $direction => $count * 100.49,
                    's7_' . $direction . '_items' => array_fill(0, $count, ['amount' => 100.49, 'description' => 'Syntetická úprava']),
                ]);
                $dom = new \DOMDocument();
                $dom->loadXML($result['xml']);
                $xpath = new \DOMXPath($dom);
                self::assertSame(min(99, $count), $dom->getElementsByTagName($element)->length);
                self::assertSame((float) round($count * 100.49), $xpath->evaluate('sum(//' . $element . '/@' . $attribute . ')'));
                self::assertTrue($dom->schemaValidate(dirname(__DIR__, 4) . '/xsd/dpfdp7_epo2.xsd'));
                if ($count > 99) {
                    self::assertStringContainsString('Souhrn ' . ($count - 98) . ' dalších úprav', $result['xml']);
                    self::assertNotEmpty(array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'limit 99')));
                }
            }
        }
    }

    public function testSectionERoundingMatchesRoundedActualItemSum(): void
    {
        foreach (['increase' => 'kc_uprzvys_235', 'decrease' => 'kc_uprsniz_235'] as $direction => $attribute) {
            foreach ([100.49, 100.51, 0.49, 0.51] as $amount) {
                $result = $this->build([
                    's7_' . $direction => $amount * 10,
                    's7_' . $direction . '_items' => array_fill(0, 10, ['amount' => $amount, 'description' => 'Syntetická úprava']),
                ]);
                $dom = new \DOMDocument();
                $dom->loadXML($result['xml']);
                self::assertSame((float) round($amount * 10), (new \DOMXPath($dom))->evaluate('sum(//@' . $attribute . ')'));
                foreach ($dom->getElementsByTagName($direction === 'increase' ? 'VetaC' : 'VetaE') as $row) {
                    self::assertGreaterThanOrEqual(0, (int) $row->getAttribute($attribute));
                    self::assertLessThanOrEqual(1.0, abs((float) $row->getAttribute($attribute) - $amount));
                }
                self::assertEmpty(array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'neodpovídá částce')));
            }
        }
    }

    public function testSectionERoundingDoesNotHideActualMismatch(): void
    {
        $result = $this->build([
            's7_increase' => 1005.5,
            's7_increase_items' => array_fill(0, 10, ['amount' => 100.49, 'description' => 'Syntetická úprava']),
        ]);
        $dom = new \DOMDocument();
        $dom->loadXML($result['xml']);
        self::assertSame(1005.0, (new \DOMXPath($dom))->evaluate('sum(//VetaC/@kc_uprzvys_235)'));
        self::assertNotEmpty(array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'neodpovídá částce na ř. 105')));
    }

    // ── P-8: kc_dpfmz18 ─────────────────────────────────────────────────────

    public function testPayrollGrossReachesVetaU(): void
    {
        $xml = $this->build(['closing' => $this->closing(), 'payroll_gross' => 1234567.0])['xml'];

        self::assertStringContainsString('kc_dpfmz18="1234567"', $xml);
        // Toková veličina — protějšek „stav ke konci období" u ní neexistuje.
        self::assertStringNotContainsString('kc_z_dpfmz18', $xml);
    }

    public function testNoPayrollDataLeavesAttributeOutWithoutWarning(): void
    {
        $result = $this->build(['closing' => $this->closing()]);

        self::assertStringContainsString('<VetaU', $result['xml']);
        self::assertStringNotContainsString('kc_dpfmz18', $result['xml']);
        self::assertSame([], array_values(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'Mzd'),
        )));
    }

    public function testZeroPayrollIsSentAsExplicitZero(): void
    {
        $xml = $this->build(['closing' => $this->closing(), 'payroll_gross' => 0.0])['xml'];

        self::assertStringContainsString('kc_dpfmz18="0"', $xml);
    }

    public function testManualInputOverridesPayrollModule(): void
    {
        $xml = $this->build(
            ['closing' => $this->closing(), 'payroll_gross' => 1234567.0],
            ['s7_payroll_gross' => 900000],
        )['xml'];

        self::assertStringContainsString('kc_dpfmz18="900000"', $xml);
        self::assertStringNotContainsString('kc_dpfmz18="1234567"', $xml);
    }

    /**
     * Bez dokončené uzávěrky daňové evidence VetaU nevzniká (nese údaje podle § 7b),
     * takže by se údaj o mzdách tiše zahodil — o tom se musí varovat.
     */
    public function testPayrollWithoutClosingWarnsInsteadOfSilentDrop(): void
    {
        $result = $this->build(['payroll_gross' => 500000.0]);

        self::assertStringNotContainsString('<VetaU', $result['xml']);
        self::assertNotEmpty(array_filter(
            $result['warnings'],
            static fn (string $w): bool => str_contains($w, 'kc_dpfmz18'),
        ));
    }
}
