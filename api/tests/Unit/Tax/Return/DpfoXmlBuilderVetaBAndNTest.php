<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Service\Tax\Return\DpfoReturnCalculator;
use MyInvoice\Service\Tax\Return\DpfoXmlBuilder;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * VetaB (příznaky vložených příloh) a VetaN (žádost o vrácení přeplatku) — obě věty
 * chyběly úplně, viz private/DANE-PLAN.md §4/§7 nález 1 a 2.
 */
final class DpfoXmlBuilderVetaBAndNTest extends TestCase
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
    private function calc(array $data = [], array $inputs = ['tax_paid_advances' => 0], array $profile = []): array
    {
        return (new DpfoReturnCalculator())->compute(
            ['s7_income' => 1000000, 's7_expenses' => 600000, 's7_base' => 400000, 'expense_mode' => 'pausal', 'expense_rate' => 60] + $data,
            $inputs,
            $profile,
            TaxConstants::forYear(2025)
        );
    }

    private function build(array $calc, array $meta = []): array
    {
        return (new DpfoXmlBuilder())->build($this->sampleSupplier(), 2025, $calc, $meta);
    }

    // ── VetaB ────────────────────────────────────────────────────────────────

    public function testVetaBBuiltWithPriloha1WhenSection7Present(): void
    {
        $xml = $this->build($this->calc())['xml'];
        self::assertStringContainsString('<VetaB', $xml);
        self::assertStringContainsString('priloha1="1"', $xml);
        // Příloha 2 (§9/§10) se nikdy nestaví (VetaV chybí) — priloha2 se nesmí tvrdit.
        self::assertStringNotContainsString('priloha2=', $xml);
    }

    public function testVetaBPrecedesVetaTInSchemaOrder(): void
    {
        $xml = $this->build($this->calc())['xml'];
        $vetaBPos = strpos($xml, '<VetaB');
        $vetaTPos = strpos($xml, '<VetaT');
        self::assertNotFalse($vetaBPos);
        self::assertNotFalse($vetaTPos);
        self::assertLessThan($vetaTPos, $vetaBPos, 'VetaB musí předcházet VetaT v XSD sekvenci.');
    }

    public function testVetaBOmittedWhenNoSection7BaseIsComputed(): void
    {
        // Ruční calc bez fields['kc_zd7'] vůbec — simuluje stav mimo DpfoReturnCalculator.
        $calc = ['fields' => [], 's7' => ['income' => 0], 'family' => []];
        $result = $this->build($calc);
        self::assertStringNotContainsString('<VetaB', $result['xml']);
    }

    /**
     * Zkušební EPO 30. 8. 2026: jakmile VetaB existuje, EPO navíc hlídá potv_zam
     * (potvrzení od zaměstnavatele) u §6 příjmů — appka žádné e-přílohy nepřikládá,
     * takže flag nesmí tvrdit, že doklad je vložený; místo toho jen varujeme.
     */
    public function testWarnsAboutMissingEmployerConfirmationWhenSection6IncomePresent(): void
    {
        $result = $this->build($this->calc([], ['s6_employment' => ['income' => 350000, 'withholding' => 40000], 'tax_paid_advances' => 0]));
        self::assertStringNotContainsString('potv_zam=', $result['xml']);
        $matches = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'potvrzení od zaměstnavatele'));
        self::assertNotEmpty($matches, 'chybí varování o chybějícím potvrzení od zaměstnavatele (§6)');
    }

    public function testNoEmployerConfirmationWarningWithoutSection6Income(): void
    {
        $result = $this->build($this->calc());
        $matches = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'potvrzení od zaměstnavatele'));
        self::assertEmpty($matches);
    }

    // ── VetaN ────────────────────────────────────────────────────────────────

    private function overpaymentCalc(): array
    {
        // zálohy výrazně převyšují vypočtenou daň → balance_due záporné = přeplatek.
        return $this->calc([], ['tax_paid_advances' => 999999999]);
    }

    public function testVetaNBuiltWhenOverpaymentAndBankAccountAvailable(): void
    {
        $calc = $this->overpaymentCalc();
        $calc['bank_account'] = ['account_number' => '19-2000145399', 'bank_code' => '0800', 'bank_name' => 'Testovací banka', 'iban' => null];
        $result = $this->build($calc);

        self::assertStringContainsString('<VetaN', $result['xml']);
        self::assertStringContainsString('zp_vrac="U"', $result['xml']);
        self::assertStringContainsString('zvp_pbu="19"', $result['xml']);
        self::assertStringContainsString('zvp_c_komds="2000145399"', $result['xml']);
        self::assertStringContainsString('zvp_k_bank="0800"', $result['xml']);
        self::assertStringContainsString('zvp_naz_bank="Testovací banka"', $result['xml']);
        self::assertMatchesRegularExpression('/kc_preplatek="\d+"/', $result['xml']);
    }

    public function testVetaNOmittedWithoutOverpayment(): void
    {
        $calc = $this->calc();
        $calc['bank_account'] = ['account_number' => '2000145399', 'bank_code' => '0100', 'bank_name' => 'Testovací banka', 'iban' => null];
        $result = $this->build($calc);
        self::assertStringNotContainsString('<VetaN ', $result['xml']);
    }

    public function testVetaNOmittedAndWarnsWhenOverpaymentButNoBankAccount(): void
    {
        $calc = $this->overpaymentCalc();
        $calc['bank_account'] = null;
        $result = $this->build($calc);

        self::assertStringNotContainsString('<VetaN ', $result['xml']);
        $matches = array_filter($result['warnings'], static fn (string $w): bool => str_contains($w, 'bankovní účet'));
        self::assertNotEmpty($matches, 'chybí varování o chybějícím bankovním účtu pro vrácení přeplatku');
    }

    public function testVetaNFollowsVetaUWhenClosingPresent(): void
    {
        $calc = (new DpfoReturnCalculator())->compute(
            [
                'activities' => [
                    ['name' => 'Řemeslo', 'nace_code' => '43320', 'income' => 300000, 'expense_mode' => 'pausal', 'expense_rate' => 80, 'active_months' => 12],
                ],
                'expense_mode' => 'pausal',
                'expense_rate' => 80,
                'accounting_mode' => 'tax_evidence',
                'closing' => [
                    'status' => 'final',
                    'opening_balances' => ['fixed_assets' => 100000],
                    'closing_balances' => ['fixed_assets' => 80000, 'depreciation' => 20000],
                ],
            ],
            ['tax_paid_advances' => 999999999],
            [],
            TaxConstants::forYear(2025),
        );
        $calc['bank_account'] = ['account_number' => '2000145399', 'bank_code' => '0100', 'bank_name' => 'Testovací banka', 'iban' => null];
        $xml = $this->build($calc)['xml'];

        $vetaUPos = strpos($xml, '<VetaU ');
        $vetaNPos = strpos($xml, '<VetaN ');
        self::assertNotFalse($vetaUPos);
        self::assertNotFalse($vetaNPos);
        self::assertGreaterThan($vetaUPos, $vetaNPos, 'VetaN musí následovat za VetaU v XSD sekvenci.');
    }

    /**
     * Nulová sleva se do podání neposílá vůbec.
     *
     * Zkušební EPO 31. 8. 2026 hlásilo u nulové slevy na manžela („0 = 0 měsíců
     * × 2 070"), že ř.65a neodpovídá vzorci. Po vynechání atributů výtka zmizela,
     * spolu s ř.72. Úřad nulu nečte jako „nic", ale jako vyplněný údaj, který
     * musí projít kontrolou.
     */
    public function testZeroCreditsAreOmittedNotSentAsZero(): void
    {
        $xml = $this->build($this->calc())['xml'];

        self::assertStringNotContainsString('kc_op15_1c=', $xml);
        self::assertStringNotContainsString('m_manz=', $xml);
        self::assertStringNotContainsString('kc_manztpp=', $xml);
        // Součtové a daňové řádky se naopak plní i nulou — stojí na nich křížové kontroly.
        self::assertStringContainsString('uhrn_slevy35ba=', $xml);
    }

    public function testNonZeroSpouseCreditIsStillSent(): void
    {
        $calc = $this->calc(profile: ['spouse_claim' => ['eligible_months' => 12]]);
        $xml = $this->build($calc)['xml'];

        self::assertStringContainsString('m_manz="12"', $xml);
        self::assertStringContainsString('kc_op15_1c="24840"', $xml);
    }
}