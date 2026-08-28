<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Payroll;

use MyInvoice\Service\Accounting\Payroll\PayrollCalculator;
use MyInvoice\Service\Accounting\Payroll\PayrollPostingAccounts;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Kontace mzdy po firmách: analytika účetní (521.100 / 336.100 / 336.200 / 342.200)
 * proti syntetikám ze směrné osnovy.
 */
final class PayrollPostingAccountsTest extends TestCase
{
    /** Analytika účetní z reálné osnovy — tenhle rozpis se má zaúčtovat. */
    private static function accountantScheme(): PayrollPostingAccounts
    {
        return PayrollPostingAccounts::fromMap([
            PayrollPostingAccounts::KEY_EMPLOYMENT_EXPENSE => '521.100',
            PayrollPostingAccounts::KEY_EMPLOYMENT_PAYABLE => '331.100',
            PayrollPostingAccounts::KEY_EMPLOYER_INSURANCE => '524.100',
            PayrollPostingAccounts::KEY_SOCIAL_PAYABLE     => '336.100',
            PayrollPostingAccounts::KEY_HEALTH_PAYABLE     => '336.200',
            PayrollPostingAccounts::KEY_INCOME_TAX_PAYABLE => '342.200',
        ]);
    }

    /** @return array<string,float> účet|strana → částka */
    private static function fingerprint(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $key = $l['account_code'] . '|' . $l['side'];
            $out[$key] = ($out[$key] ?? 0.0) + $l['amount'];
        }
        ksort($out);
        return $out;
    }

    public function testDefaultsMatchTheSharedAccountingDefaults(): void
    {
        $defaults = PayrollPostingAccounts::defaults();

        self::assertSame(PayrollAccountingDefaults::codes()['employment_gross_debit'], $defaults->employmentExpense);
        // Od W7/Ú-08 je výchozí kontace ROZDĚLENÁ: závazek vůči ČSSZ a vůči
        // zdravotním pojišťovnám se na společné 336 vzájemně vynetoval a saldo
        // proti dvěma platbám nesedělo. Platí to jen pro NOVĚ zakládanou firmu —
        // kdo má uloženou 336, účtuje na ni dál (viz migrace 1618).
        self::assertSame('336.100', $defaults->socialPayable);
        self::assertSame('336.200', $defaults->healthPayable);
        self::assertFalse($defaults->insuranceIsPooled(), 'obě instituce mají svou analytiku');
    }

    /** Kdo si 336 nechá společnou, dostane pořád jeden slitý řádek. */
    public function testPooledInsuranceStaysAvailableAsAnExplicitChoice(): void
    {
        $pooled = PayrollPostingAccounts::fromMap([
            PayrollPostingAccounts::KEY_SOCIAL_PAYABLE => '336',
            PayrollPostingAccounts::KEY_HEALTH_PAYABLE => '336',
        ]);

        self::assertTrue($pooled->insuranceIsPooled());
    }

    public function testForTypeFollowsTaxpayerType(): void
    {
        $accounts = self::accountantScheme();

        self::assertSame(
            ['expense' => '521.100', 'payable' => '331.100'],
            $accounts->forType(PayrollCalculator::TYPE_EMPLOYEE)
        );
        // Partnerské účty nejsou v mapě → zůstaly syntetiky ze směrné osnovy.
        self::assertSame(
            ['expense' => '522', 'payable' => '366'],
            $accounts->forType(PayrollCalculator::TYPE_MANAGING_PARTNER)
        );
    }

    /**
     * Zápis dle deníku účetní: 521.100/331.100 hrubá, 524.100 pojistné zaměstnavatele,
     * 336.100 sociální, 336.200 zdravotní, 342.200 sražená záloha.
     */
    public function testAccountantSchemeSplitsSocialAndHealth(): void
    {
        $b = PayrollCalculator::compute(4500.0, TaxConstants::forYear(2025));
        $lines = PayrollCalculator::lines(
            $b,
            PayrollCalculator::TYPE_EMPLOYEE,
            null,
            self::accountantScheme(),
        );

        self::assertSame([
            '331.100|credit' => 4500.0,
            '331.100|debit'  => 2723.0 + 675.0,
            '336.100|credit' => 1116.0 + 320.0,   // zaměstnavatel + zaměstnanec, SP
            '336.200|credit' => 405.0 + 2403.0,   // zaměstnavatel + zaměstnanec, ZP
            '342.200|credit' => 675.0,
            '521.100|debit'  => 4500.0,
            '524.100|debit'  => 1521.0,
        ], self::fingerprint($lines));
    }

    /** Rozdělené závazky musí sedět na úhrn, který jde příkazem na OSSZ a ZP. */
    public function testSplitMatchesRemittanceTotals(): void
    {
        $b = PayrollCalculator::compute(4500.0, TaxConstants::forYear(2026));
        $fp = self::fingerprint(PayrollCalculator::lines(
            $b,
            PayrollCalculator::TYPE_EMPLOYEE,
            null,
            self::accountantScheme(),
        ));

        self::assertSame((float) $b['social_total'], $fp['336.100|credit'], 'OSSZ — obě strany');
        self::assertSame((float) $b['health_total'], $fp['336.200|credit'], 'ZP — obě strany');
        self::assertSame(
            (float) $b['remittance_total'],
            $fp['336.100|credit'] + $fp['336.200|credit'] + $fp['342.200|credit'],
            'součet závazků = hromadný příkaz k úhradě'
        );
    }

    /** Se společnou 336 zůstává zápis přesně takový, jaký byl před rozpadem. */
    public function testPooledInsuranceKeepsLegacyLines(): void
    {
        $b = PayrollCalculator::compute(4500.0, TaxConstants::forYear(2025));
        $pooled = PayrollPostingAccounts::fromMap([
            PayrollPostingAccounts::KEY_SOCIAL_PAYABLE => '336',
            PayrollPostingAccounts::KEY_HEALTH_PAYABLE => '336',
        ]);

        $lines = PayrollCalculator::lines(
            $b,
            PayrollCalculator::TYPE_MANAGING_PARTNER,
            null,
            $pooled,
        );
        $codes = array_column($lines, 'account_code');
        self::assertCount(
            2,
            array_filter($codes, static fn (string $c): bool => $c === '336'),
            'se společnou 336 jsou právě dva řádky (zaměstnavatel + zaměstnanec), ne čtyři'
        );

        // Slítá 336 a rozdělené analytiky musí dát TÝŽ závazek — rozpad je jen
        // jiný řez též částky, ne jiné číslo.
        $split = self::fingerprint(PayrollCalculator::lines(
            $b,
            PayrollCalculator::TYPE_MANAGING_PARTNER,
            null,
            PayrollPostingAccounts::defaults(),
        ));
        self::assertSame(
            self::fingerprint($lines)['336|credit'],
            $split['336.100|credit'] + $split['336.200|credit'],
        );
    }

    /** Rozpad bez složek pojistného (starší snapshot) se nesmí rozvážit. */
    public function testFallsBackToPooledLineWhenComponentsAreMissing(): void
    {
        $legacySnapshot = [
            'gross'                => 4500,
            'employer_total'       => 1521,
            'employee_deductions'  => 2723,
            'advance_tax'          => 675,
            'advance_tax_withheld' => 675,
            'net'                  => 1102,
        ];

        $lines = PayrollCalculator::lines(
            $legacySnapshot,
            PayrollCalculator::TYPE_EMPLOYEE,
            null,
            self::accountantScheme(),
        );

        $debit = $credit = 0.0;
        foreach ($lines as $l) {
            $l['side'] === 'debit' ? $debit += $l['amount'] : $credit += $l['amount'];
        }
        self::assertSame($debit, $credit, 'zápis musí zůstat vyvážený');

        $fp = self::fingerprint($lines);
        self::assertSame(1521.0 + 2723.0, $fp['336.100|credit'], 'bez složek jde vše na sociální účet');
        self::assertArrayNotHasKey('336.200|credit', $fp, 'vymyšlený rozpad by zápis rozvážil');
    }

    /** Přeúčtování čisté mzdy zůstává na účtu z karty zaměstnance. */
    public function testSettlementAccountIsUnaffectedByScheme(): void
    {
        $b = PayrollCalculator::compute(4500.0, TaxConstants::forYear(2025));
        $fp = self::fingerprint(PayrollCalculator::lines(
            $b,
            PayrollCalculator::TYPE_EMPLOYEE,
            '365.100',
            self::accountantScheme(),
        ));

        self::assertSame(1102.0, $fp['365.100|credit']);
        self::assertSame(4500.0, $fp['331.100|credit']);
        self::assertSame(2723.0 + 675.0 + 1102.0, $fp['331.100|debit'], 'saldo 331.100 se vynuluje');
    }
}
