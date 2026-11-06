<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementBlocker;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualSettlementClaimMonths;
use MyInvoice\Service\Payroll\AnnualSettlement\AnnualTaxRates;
use MyInvoice\Service\Payroll\IncomeTax\StudentTaxCredit;
use MyInvoice\Service\Payroll\IncomeTax\TaxCreditKind;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use PHPUnit\Framework\TestCase;

/**
 * Sleva na studenta (§ 35bc ZDP od 1. 1. 2027): věková hranice, kontrola
 * intervalu a roční zúčtování.
 */
final class StudentTaxCreditTest extends TestCase
{
    public function testAgeLimitIs26AndForFullTimeDoctoralStudy28(): void
    {
        self::assertSame('2027-05-15', StudentTaxCredit::ageLimitReachedOn('2001-05-15', TaxCreditKind::Student));
        self::assertSame('2029-05-15', StudentTaxCredit::ageLimitReachedOn('2001-05-15', TaxCreditKind::StudentDoctoral));
        // Narozený 29. 2. dovrší věk v nepřestupném roce posledním únorovým dnem.
        self::assertSame('2026-02-28', StudentTaxCredit::ageLimitReachedOn('2000-02-29', TaxCreditKind::Student));
    }

    /**
     * Rozhoduje stav NA POČÁTKU měsíce (§ 35ba odst. 3): 26. narozeniny 15. 5.
     * — květen ještě ano, červen už ne. Narozeniny 1. 6. — červen už ne.
     */
    public function testMonthCountsWhenTheAgeLimitWasNotReachedAtItsStart(): void
    {
        self::assertTrue(StudentTaxCredit::isAgeEligibleMonth('2001-05-15', TaxCreditKind::Student, '2027-05-01'));
        self::assertFalse(StudentTaxCredit::isAgeEligibleMonth('2001-05-15', TaxCreditKind::Student, '2027-06-01'));
        self::assertSame('2027-05-31', StudentTaxCredit::latestEffectiveTo('2001-05-15', TaxCreditKind::Student));

        self::assertFalse(StudentTaxCredit::isAgeEligibleMonth('2001-06-01', TaxCreditKind::Student, '2027-06-01'));
        self::assertTrue(StudentTaxCredit::isAgeEligibleMonth('2001-06-01', TaxCreditKind::Student, '2027-05-01'));
        self::assertSame('2027-05-31', StudentTaxCredit::latestEffectiveTo('2001-06-01', TaxCreditKind::Student));

        // Doktorand stejného věku má nárok ještě dva roky.
        self::assertTrue(StudentTaxCredit::isAgeEligibleMonth('2001-05-15', TaxCreditKind::StudentDoctoral, '2027-12-01'));
        self::assertSame('2029-05-31', StudentTaxCredit::latestEffectiveTo('2001-05-15', TaxCreditKind::StudentDoctoral));
    }

    public function testIntervalCheckRejectsWhatTheStatuteDoesNotAllow(): void
    {
        $birth = '2001-05-15';
        self::assertNull(StudentTaxCredit::intervalProblem(TaxCreditKind::Student, '2027-01-01', '2027-05-31', $birth));
        self::assertNull(StudentTaxCredit::intervalProblem(TaxCreditKind::Taxpayer, '2020-01-01', null, null));

        self::assertNotNull(StudentTaxCredit::intervalProblem(TaxCreditKind::Student, '2027-01-01', '2027-06-30', $birth));
        self::assertNotNull(StudentTaxCredit::intervalProblem(TaxCreditKind::Student, '2027-01-01', null, $birth));
        self::assertNotNull(StudentTaxCredit::intervalProblem(TaxCreditKind::Student, '2027-01-01', '2027-05-31', null));
        self::assertNotNull(StudentTaxCredit::intervalProblem(TaxCreditKind::Student, '2026-12-01', '2027-05-31', $birth));
        self::assertNull(StudentTaxCredit::intervalProblem(TaxCreditKind::StudentDoctoral, '2027-01-01', '2027-12-31', $birth));
    }

    /**
     * Roční zúčtování 2027: 1/12 ze 4 020 Kč za každý měsíc nároku (§ 35ba
     * odst. 3), ročník 2026 slevu nezná a jeho stopa zůstává beze změny.
     */
    public function testAnnualSettlementProratesTheStudentCreditByMonths(): void
    {
        $provider = CzechPayrollRulesets::provider();
        $rates2027 = AnnualTaxRates::forRuleset($provider->forCalculation(PayrollRulesetDomain::IncomeTax, '2027-12-01'));
        self::assertTrue($rates2027->supportsCredit(TaxCreditKind::Student));
        self::assertSame(402_000, $rates2027->annualCreditMinorUnits['student']);
        self::assertSame(402_000, $rates2027->annualCreditMinorUnits['student-doctoral']);
        self::assertSame(167_500, $rates2027->creditForMonths(TaxCreditKind::Student, 5));
        self::assertSame(402_000, $rates2027->creditForMonths(TaxCreditKind::StudentDoctoral, 12));
        // Hranice pásma 2027 = 36 × 51 663 Kč, bonus = 6 × 24 900 Kč.
        self::assertSame(185_986_800, $rates2027->highRateThresholdMinorUnits);
        self::assertSame(14_940_000, $rates2027->bonusMinimumIncomeMinorUnits);

        $rates2026 = AnnualTaxRates::forRuleset($provider->forCalculation(PayrollRulesetDomain::IncomeTax, '2026-12-01'));
        self::assertFalse($rates2026->supportsCredit(TaxCreditKind::Student));
        self::assertSame(
            ['disability-basic', 'disability-extended', 'taxpayer', 'ztp-p'],
            array_keys($rates2026->toArray()['annual_credit_minor_units']),
        );
    }

    public function testAnnualClaimMonthsCountStudentMonthsAndRejectConflicts(): void
    {
        $months = new AnnualSettlementClaimMonths();
        $result = $months->credits([
            $this->row('student', '2027-01-01', '2027-05-31'),
        ], 2027, true, [$this->declaration()]);
        self::assertSame([], $result['blockers']);
        $byKind = [];
        foreach ($result['credits'] as $credit) {
            $byKind[$credit->kind->value] = $credit->months;
        }
        self::assertSame(5, $byKind['student'] ?? null);

        $conflict = $months->credits([
            $this->row('student', '2027-01-01', '2027-05-31'),
            $this->row('student-doctoral', '2027-05-01', '2027-12-31'),
        ], 2027, true, [$this->declaration()]);
        self::assertContains(AnnualSettlementBlocker::CreditEvidenceUnverified, $conflict['blockers']);

        $open = $months->credits([
            $this->row('student', '2027-01-01', null),
        ], 2027, true, [$this->declaration()]);
        self::assertContains(AnnualSettlementBlocker::CreditEvidenceUnverified, $open['blockers']);
    }

    /** @return array<string,mixed> */
    private function row(string $kind, string $from, ?string $to): array
    {
        return [
            'credit_kind' => $kind,
            'evidence_status' => 'verified',
            'effective_from' => $from,
            'effective_to' => $to,
        ];
    }

    /** @return array<string,mixed> */
    private function declaration(): array
    {
        return [
            'status' => 'signed',
            'effective_from' => '2027-01-01',
            'effective_to' => null,
        ];
    }
}
