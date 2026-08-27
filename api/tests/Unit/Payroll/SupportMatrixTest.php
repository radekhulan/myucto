<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\SupportMatrix;
use MyInvoice\Tests\Fixtures\Payroll\ShiftedYearPayrollRulesetFixture;
use PHPUnit\Framework\TestCase;

final class SupportMatrixTest extends TestCase
{
    public function testMatrixSeparatesTargetSupportFromRuntimeAvailability(): void
    {
        $matrix = $this->matrix()->all();
        self::assertSame(SupportMatrix::VERSION, $matrix['version']);
        self::assertSame([2026], $matrix['supported_years']);

        $features = array_column($matrix['features'], null, 'key');
        self::assertTrue($features['module_shell']['available']);
        self::assertTrue($features['activation']['available']);
        self::assertTrue($features['employer_settings']['available']);
        self::assertTrue($features['persons']['available']);
        self::assertTrue($features['employments']['available']);
        self::assertTrue($features['time_attendance']['available']);
        self::assertTrue($features['payroll_runs']['available']);
        self::assertSame('supported', $features['payroll_runs']['status']);
        self::assertTrue($features['payslips']['available']);
        self::assertTrue($features['employment_exit_documents']['available']);
        self::assertSame(
            'manual_review',
            $features['employment_exit_documents']['status'],
        );
        self::assertTrue($features['automatic_posting']['available']);
        self::assertTrue($features['jmhz_export']['available']);
        self::assertSame('supported', $features['jmhz_export']['status']);
        self::assertFalse($features['jmhz_special_scenarios']['available']);
        self::assertSame('manual_review', $features['jmhz_special_scenarios']['status']);
        self::assertTrue($features['jmhz_submission']['available']);
        self::assertTrue($features['registration_submission']['available']);
        self::assertTrue($features['health_insurer_submission']['available']);
        self::assertTrue($features['eldp_control_export']['available']);
        self::assertFalse($features['eldp_submission']['available']);
        self::assertSame('not_supported', $features['direct_submission']['status']);

        $employmentTypes = array_column($matrix['employment_types'], null, 'key');
        self::assertTrue($employmentTypes['hpp']['available']);
        self::assertTrue($employmentTypes['dpp']['available']);
        self::assertTrue($employmentTypes['dpc']['available']);
        self::assertTrue($employmentTypes['statutory_body']['available']);
        self::assertFalse($employmentTypes['foreign_regime']['available']);
    }

    public function testEveryCapabilityHasKnownStatusAndEpic(): void
    {
        $matrix = $this->matrix()->all();
        foreach (array_merge($matrix['employment_types'], $matrix['features']) as $capability) {
            self::assertContains($capability['status'], ['supported', 'manual_review', 'not_supported']);
            self::assertMatchesRegularExpression('/^MZ-[0-9]{2}$/', $capability['min_epic']);
            self::assertIsBool($capability['available']);
        }
    }

    public function testYearWithoutRulesetIsNotSupported(): void
    {
        $matrix = $this->matrix();

        self::assertFalse($matrix->supportsYear(2025));
        self::assertFalse($matrix->supportsYear(2027));
        self::assertTrue($matrix->supportsYear(2026));
    }

    public function testNextYearBecomesSupportedAsSoonAsItsRulesetsExist(): void
    {
        $matrix = new SupportMatrix(ShiftedYearPayrollRulesetFixture::provider(2027));

        self::assertSame([2026, 2027], $matrix->supportedYears());
        self::assertTrue($matrix->supportsYear(2027));
        self::assertFalse($matrix->supportsYear(2028));
    }

    private function matrix(): SupportMatrix
    {
        return new SupportMatrix(CzechPayrollRulesets2026::provider());
    }
}
