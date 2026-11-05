<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\PayrollHourlyAgreementTerms;
use PHPUnit\Framework\TestCase;

final class PayrollHourlyAgreementTermsTest extends TestCase
{
    public function testContractKeepsExplicitZeroAndDiscardsClientClaimTotals(): void
    {
        $input = self::terms();
        $input['included_overtime']['prior_consumed_duration_units'] = 450000;
        $result = PayrollHourlyAgreementTerms::normalize($input, '2026-01-01', 'included_in_wage');
        self::assertSame(0, $result['included_overtime']['opening_used_duration_units']);
        self::assertArrayNotHasKey('prior_consumed_duration_units', $result['included_overtime']);
        self::assertNull(PayrollHourlyAgreementTerms::normalize(null, '2026-01-01', 'surcharge'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidContracts')]
    public function testInvalidContractsAreValidationErrors(array $change): void
    {
        $input = self::terms();
        $input['included_overtime'] = array_replace($input['included_overtime'], $change);
        $this->expectException(\InvalidArgumentException::class);
        PayrollHourlyAgreementTerms::normalize($input, '2026-01-01', 'included_in_wage');
    }

    public static function invalidContracts(): array
    {
        return [
            'jednostranné určení' => [['agreement_kind' => 'wage_statement']],
            'neznámé počáteční čerpání' => [['opening_used_duration_units' => null]],
            'nezadaný důkaz nuly' => [['opening_reference' => '']],
            'nedoložené vedení' => [['annual_limit_duration_units' => 450001]],
            'neexistující datum' => [['opening_as_of' => '2026-02-31']],
            'jiný rok' => [['calendar_year' => 2027]],
        ];
    }

    public function testAgreementPremiumRequiresScopeAndPositiveHourlyAmount(): void
    {
        $premium = ['kind' => 'night', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31',
            'scope_duration_units' => 300000, 'included_hourly_minor' => 2000,
            'agreement_reference' => 'Syntetická dohoda', 'opening_used_duration_units' => 0,
            'opening_as_of' => '2026-01-01', 'opening_reference' => 'Syntetické počáteční doložení'];
        $result = PayrollHourlyAgreementTerms::normalize(['included_agreement_premiums' => [$premium]], '2026-01-01', 'surcharge');
        self::assertSame($premium, $result['included_agreement_premiums'][0]);
        $premium['kind'] = 'holiday';
        $this->expectException(\InvalidArgumentException::class);
        PayrollHourlyAgreementTerms::normalize(['included_agreement_premiums' => [$premium]], '2026-01-01', 'surcharge');
    }

    private static function terms(): array
    {
        return ['included_overtime' => ['agreement_kind' => 'contract', 'agreement_reference' => 'Syntetická smlouva',
            'scope_kind' => 'annual_hours', 'calendar_year' => 2026, 'annual_limit_duration_units' => 450000,
            'opening_used_duration_units' => 0, 'opening_as_of' => '2026-01-01', 'opening_reference' => 'Doložený začátek roku']];
    }
}
