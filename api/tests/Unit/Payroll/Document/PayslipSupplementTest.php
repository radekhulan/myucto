<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Document;

use MyInvoice\Service\Payroll\Document\PayrollFrozenMonthFacts;
use MyInvoice\Service\Payroll\Document\PayslipDocumentData;
use MyInvoice\Service\Payroll\Document\PayslipPdfRenderer;
use MyInvoice\Service\Payroll\Document\PayslipSupplement;
use MyInvoice\Tests\Fixtures\Payroll\SyntheticPayslipFixture;
use PHPUnit\Framework\TestCase;

final class PayslipSupplementTest extends TestCase
{
    public function testPayslipWithoutSupplementDropsAuditTrailAndUnknownFacts(): void
    {
        $html = (new PayslipPdfRenderer())->renderHtml(SyntheticPayslipFixture::document());

        self::assertStringNotContainsString('Účetní a výpočtová stopa', $html);
        self::assertStringNotContainsString('Kontace', $html);
        self::assertStringNotContainsString('SHA-256', $html);
        self::assertStringNotContainsString('Renderer', $html);
        self::assertStringNotContainsString('neevidováno', $html);
        // Starší páska docházku nenese — blok se vynechá, nevypíše nuly.
        self::assertStringNotContainsString('Zbývající dovolená', $html);
        self::assertStringContainsString('Hrubá mzda', $html);
        self::assertStringContainsString('Osobní náklady', $html);
        self::assertStringContainsString('K výplatě', $html);
    }

    public function testSupplementPrintsAttendanceVacationComponentsAndAccount(): void
    {
        $document = new PayslipDocumentData(...[
            ...get_object_vars(SyntheticPayslipFixture::document(
                taxBeforeCreditsMinorUnits: 718_500,
                taxNonRefundableCreditsMinorUnits: 257_000,
                taxChildCreditMinorUnits: 511_500,
            )),
            'supplement' => PayslipSupplement::fromFrozen(
                self::inputPerson(),
                self::resultPerson(),
                5_010,
            ),
        ]);
        $html = (new PayslipPdfRenderer())->renderHtml($document);

        self::assertStringContainsString('Zbývající dovolená', $html);
        self::assertStringContainsString('83,5 h', $html);
        self::assertStringContainsString('16 d / 120 h', $html);
        self::assertStringContainsString('45 h', $html);
        self::assertStringContainsString('10 955,00', $html);
        self::assertStringContainsString('1 858,00', $html);
        self::assertStringContainsString('243,44 Kč/h', $html);
        self::assertStringContainsString('Z9001', $html);
        self::assertStringContainsString('na účet ••••0005/0100', $html);
        self::assertStringContainsString('Sleva na poplatníka', $html);
        self::assertStringContainsString('Stravenkový paušál', $html);
    }

    public function testComponentGroupsFollowCodeAndKind(): void
    {
        self::assertSame('vacation_pay', PayrollFrozenMonthFacts::group('NAHRADA_MZDY_DOVOLENA', 'compensation'));
        self::assertSame('compensations', PayrollFrozenMonthFacts::group('NAHRADA_MZDY_DPN', 'compensation'));
        self::assertSame('overtime_pay', PayrollFrozenMonthFacts::group('PRIPLATEK_PRESCAS', 'premium'));
        self::assertSame('bonuses', PayrollFrozenMonthFacts::group('ODMENA', 'bonus'));
        self::assertSame('base_wage', PayrollFrozenMonthFacts::group('MZDA_MESICNI', 'base_wage'));
        self::assertSame('meal_allowance', PayrollFrozenMonthFacts::group('PRISPEVEK_STRAVOVANI', 'benefit_meal'));
        self::assertSame('reimbursements', PayrollFrozenMonthFacts::group('NAHRADA_VYDAJU_PREVZATA', 'other'));
        self::assertSame('other', PayrollFrozenMonthFacts::group('NEPENEZNI_PRIJEM', 'non_cash'));
    }

    public function testWageSheetGroupsAchievedOvertimeWithItsPremium(): void
    {
        $input = self::inputPerson();
        $input['employments'][0]['inputs'][] = ['id' => 14, 'component' => ['code' => 'MZDA_DOSAZENA_PRESCAS', 'name' => 'Dosažená mzda za přesčas', 'component_kind' => 'hourly_wage']];
        $input['employments'][0]['inputs'][] = ['id' => 15, 'component' => ['code' => 'PRIPLATEK_PRESCAS', 'name' => 'Příplatek za přesčas', 'component_kind' => 'premium']];
        $result = self::resultPerson();
        $result['employments'][0]['inputs'][] = ['input_id' => 14, 'totals' => ['source_amount_minor' => 16800]];
        $result['employments'][0]['inputs'][] = ['input_id' => 15, 'totals' => ['source_amount_minor' => 4200]];
        $totals = PayrollFrozenMonthFacts::fromFrozen($input, $result)->groupTotals();
        self::assertSame(21000, $totals['overtime_pay']);
        self::assertSame(2407300, $totals['base_wage']);
    }

    public function testMissingAttendanceStaysUnknownNotZero(): void
    {
        $input = self::inputPerson();
        $input['employments'][0]['time_month'] = null;
        $facts = PayrollFrozenMonthFacts::fromFrozen($input, self::resultPerson());

        self::assertFalse($facts->hasTime());
        self::assertNull($facts->time['worked_millihours']);
        self::assertSame(1_095_500, $facts->groupTotals()['vacation_pay']);
    }

    public function testCreditBreakdownOnlyWhenWholeClaimWasApplied(): void
    {
        $result = self::resultPerson();
        $result['statutory']['income_tax']['applied_non_refundable_credits_minor_units'] = 100_000;
        $partial = PayslipSupplement::fromFrozen(self::inputPerson(), $result, null);

        self::assertSame([], $partial->creditLines());
        self::assertSame(
            [['label' => 'Sleva na poplatníka', 'amount_minor_units' => 257_000]],
            PayslipSupplement::fromFrozen(self::inputPerson(), self::resultPerson(), null)->creditLines(),
        );
    }

    /** @return array<string,mixed> */
    private static function inputPerson(): array
    {
        return [
            'employee' => ['id' => 7, 'full_name' => 'Testovací Zaměstnanec'],
            'payout_accounts' => [['bank_account_masked' => '••••0005/0100']],
            'employments' => [[
                'employment' => ['id' => 3, 'code' => 'Z9001', 'relation_type' => 'employment'],
                'average_earning' => ['average_hourly_minor' => 24_344],
                'time_month' => ['jmhz_work_summary' => ['values' => [
                    'evidence_days' => 30,
                    'agreed_fund_millihours' => 165_000,
                    'worked_days' => 16,
                    'worked_millihours' => 120_000,
                    'holiday_millihours' => 0,
                    'vacation_millihours' => 45_000,
                    'overtime_millihours' => 0,
                ]]],
                'inputs' => [
                    ['id' => 11, 'component' => ['code' => 'MZDA_MESICNI', 'name' => 'Základní měsíční mzda', 'component_kind' => 'base_wage']],
                    ['id' => 12, 'component' => ['code' => 'NAHRADA_MZDY_DOVOLENA', 'name' => 'Náhrada mzdy za dovolenou', 'component_kind' => 'compensation']],
                    ['id' => 13, 'component' => ['code' => 'PRISPEVEK_STRAVOVANI', 'name' => 'Příspěvek na stravování', 'component_kind' => 'benefit_meal']],
                ],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private static function resultPerson(): array
    {
        return [
            'employee_id' => 7,
            'employments' => [[
                'employment_id' => 3,
                'inputs' => [
                    ['input_id' => 11, 'totals' => ['source_amount_minor' => 2_407_300]],
                    ['input_id' => 12, 'totals' => ['source_amount_minor' => 1_095_500]],
                    ['input_id' => 13, 'totals' => ['source_amount_minor' => 185_800]],
                ],
            ]],
            'statutory' => ['income_tax' => [
                'claimed_non_refundable_credit_breakdown' => ['taxpayer' => 257_000],
                'applied_non_refundable_credits_minor_units' => 257_000,
                'advance_tax' => ['taxable_income_minor_units' => 4_384_900],
            ]],
        ];
    }
}
