<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\SocialInsurance;

use MyInvoice\Service\Payroll\HealthInsurance\HealthAssessmentComponent;
use MyInvoice\Service\Payroll\HealthInsurance\HealthCalculationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthComponentTreatment;
use MyInvoice\Service\Payroll\HealthInsurance\HealthCorrectionTreatment;
use MyInvoice\Service\Payroll\HealthInsurance\HealthEmploymentKind;
use MyInvoice\Service\Payroll\HealthInsurance\HealthIncomeAttribution;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthCalculator;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceMonthInput;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsuranceRelationshipInput;
use MyInvoice\Service\Payroll\HealthInsurance\HealthInsurerSnapshotStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthJurisdictionEvidence;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpEmployerSelection;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibility;
use MyInvoice\Service\Payroll\HealthInsurance\HealthParticipationStatus;
use MyInvoice\Service\Payroll\HealthInsurance\HealthPersonMonthInput;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\SocialInsurance\SocialAssessmentComponent;
use MyInvoice\Service\Payroll\SocialInsurance\SocialCalculationStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialComponentTreatment;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmploymentKind;
use MyInvoice\Service\Payroll\SocialInsurance\SocialIncomeAttribution;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthCalculator;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceMonthInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialInsuranceRelationshipInput;
use MyInvoice\Service\Payroll\SocialInsurance\SocialJurisdictionEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialParticipationStatus;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPersonMonthInput;
use MyInvoice\Tests\Fixtures\Payroll\ActivePayrollRulesetFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AUDIT MZDOVÉHO MODULU (private/MZDY-AUDIT.md) — účast na SP a ZP podle
 * druhu vztahu, ručně spočítaná (rok 2026: DPP 12 000 Kč, rozhodná částka
 * 4 500 Kč, min. VZ ZP 22 400 Kč, SP zaměstnanec 7,1 %, zaměstnavatel 24,8 %,
 * ZP 4,5 % + 9 %).
 */
final class SocialHealthAuditRelationshipKindsTest extends TestCase
{
    /**
     * @return iterable<string,array{SocialEmploymentKind,?int,int,SocialParticipationStatus,int,int}>
     *   kind, sjednaný příjem, skutečný příjem, účast, pojistné zaměstnance, zaměstnavatele
     */
    public static function socialCases(): iterable
    {
        yield 'DPP 11 999 Kč → bez účasti' => [
            SocialEmploymentKind::Dpp, null, 1_199_900, SocialParticipationStatus::DoesNotParticipate, 0, 0,
        ];
        // 7,1 % z 12 000 = 852; 24,8 % = 2 976.
        yield 'DPP 12 000 Kč → účast' => [
            SocialEmploymentKind::Dpp, null, 1_200_000, SocialParticipationStatus::Participates, 85_200, 297_600,
        ];
        yield 'DPČ sjednáno 4 000, vyplaceno 4 499 → bez účasti' => [
            SocialEmploymentKind::Dpc, 400_000, 449_900, SocialParticipationStatus::DoesNotParticipate, 0, 0,
        ];
        // 7,1 % z 4 500 = 319,5 → 320; 24,8 % = 1 116.
        yield 'DPČ sjednáno 4 000, vyplaceno 4 500 → účast (skutečný příjem)' => [
            SocialEmploymentKind::Dpc, 400_000, 450_000, SocialParticipationStatus::Participates, 32_000, 111_600,
        ];
        yield 'DPČ sjednáno 5 000, vyplaceno 3 000 → účast od počátku (sjednaný příjem)' => [
            SocialEmploymentKind::Dpc, 500_000, 300_000, SocialParticipationStatus::Participates, 21_300, 74_400,
        ];
        yield 'jednatel 3 000 Kč (odměna neurčena smlouvou) → bez účasti' => [
            SocialEmploymentKind::CorporateBody, null, 300_000, SocialParticipationStatus::DoesNotParticipate, 0, 0,
        ];
        // 7,1 % z 30 000 = 2 130; 24,8 % = 7 440.
        yield 'jednatel 30 000 Kč → účast' => [
            SocialEmploymentKind::CorporateBody, null, 3_000_000, SocialParticipationStatus::Participates, 213_000, 744_000,
        ];
        // Pracovní poměr má účast vždy, i při 3 000 Kč (7,1 % = 213; 24,8 % = 744).
        yield 'pracovní poměr 3 000 Kč → účast bez limitu' => [
            SocialEmploymentKind::Employment, 3_000_000, 300_000, SocialParticipationStatus::Participates, 21_300, 74_400,
        ];
    }

    #[DataProvider('socialCases')]
    public function testSocialParticipationByRelationshipKind(
        SocialEmploymentKind $kind,
        ?int $agreedMinor,
        int $incomeMinor,
        SocialParticipationStatus $status,
        int $employeeMinor,
        int $employerMinor,
    ): void {
        $result = (new SocialInsuranceMonthCalculator(
            ActivePayrollRulesetFixture::provider(PayrollRulesetDomain::SocialInsurance),
        ))->calculate(new SocialInsuranceMonthInput('2026-08-03', [
            $this->socialPerson('person-1', [$this->socialRelationship('r1', $kind, $agreedMinor, $incomeMinor)]),
        ]));

        self::assertSame(SocialCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
        self::assertSame($status, $result->people[0]->relationships[0]->participation->status);
        self::assertSame($employeeMinor, $result->employeeContributionMinorUnits);
        self::assertSame($employerMinor, $result->employerContributionMinorUnits);
    }

    /** Dvě DPP u téhož zaměstnavatele se od 2025 sčítají: 7 000 + 5 000 = 12 000 → účast obou. */
    public function testMultipleDppAtOneEmployerAreAggregatedForSocialParticipation(): void
    {
        $result = (new SocialInsuranceMonthCalculator(
            ActivePayrollRulesetFixture::provider(PayrollRulesetDomain::SocialInsurance),
        ))->calculate(new SocialInsuranceMonthInput('2026-08-03', [
            $this->socialPerson('person-1', [
                $this->socialRelationship('dpp-1', SocialEmploymentKind::Dpp, null, 700_000),
                $this->socialRelationship('dpp-2', SocialEmploymentKind::Dpp, null, 500_000),
            ]),
        ]));

        self::assertSame(SocialCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
        foreach ($result->people[0]->relationships as $relationship) {
            self::assertSame(SocialParticipationStatus::Participates, $relationship->participation->status);
        }
        self::assertSame(85_200, $result->employeeContributionMinorUnits);
    }

    /**
     * @return iterable<string,array{HealthEmploymentKind,int,HealthParticipationStatus,int,int,int}>
     *   kind, příjem, účast, zaměstnanec, zaměstnavatel, celkem
     */
    public static function healthCases(): iterable
    {
        yield 'DPP 11 999 Kč → bez účasti na ZP' => [
            HealthEmploymentKind::Dpp, 1_199_900, HealthParticipationStatus::DoesNotParticipate, 0, 0, 0,
        ];
        // Účast, ale pod minimem 22 400: celkem 13,5 % z 22 400 = 3 024; zaměstnavatel 9 % z 12 000 = 1 080;
        // zaměstnanec = zbytek 1 944 (4,5 % z 12 000 = 540 + 13,5 % z dopočtu 10 400 = 1 404).
        yield 'DPP 12 000 Kč → účast + dopočet do minima platí zaměstnanec' => [
            HealthEmploymentKind::Dpp, 1_200_000, HealthParticipationStatus::Participates, 194_400, 108_000, 302_400,
        ];
        yield 'DPČ 4 499 Kč → bez účasti' => [
            HealthEmploymentKind::Dpc, 449_900, HealthParticipationStatus::DoesNotParticipate, 0, 0, 0,
        ];
        // Jednatel 3 000 Kč: účast bez limitu; zaměstnavatel 9 % = 270; celkem 3 024; zaměstnanec 2 754.
        yield 'jednatel 3 000 Kč → účast + dopočet do minima' => [
            HealthEmploymentKind::CorporateBody, 300_000, HealthParticipationStatus::Participates, 275_400, 27_000, 302_400,
        ];
        // 30 000: zaměstnanec 4,5 % = 1 350; zaměstnavatel 9 % = 2 700; celkem 4 050.
        yield 'pracovní poměr 30 000 Kč' => [
            HealthEmploymentKind::Employment, 3_000_000, HealthParticipationStatus::Participates, 135_000, 270_000, 405_000,
        ];
    }

    #[DataProvider('healthCases')]
    public function testHealthParticipationAndMinimumTopUpByRelationshipKind(
        HealthEmploymentKind $kind,
        int $incomeMinor,
        HealthParticipationStatus $status,
        int $employeeMinor,
        int $employerMinor,
        int $totalMinor,
    ): void {
        $result = (new HealthInsuranceMonthCalculator(
            ActivePayrollRulesetFixture::provider(PayrollRulesetDomain::HealthInsurance),
        ))->calculate(new HealthInsuranceMonthInput('2026-08-31', [
            $this->healthPerson('person-1', [$this->healthRelationship('r1', $kind, $incomeMinor)]),
        ]));

        self::assertSame(HealthCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
        self::assertSame($status, $result->people[0]->relationships[0]->participation->status);
        self::assertSame($employeeMinor, $result->employeeContributionMinorUnits);
        self::assertSame($employerMinor, $result->employerContributionMinorUnits);
        self::assertSame($totalMinor, $result->totalContributionMinorUnits);
    }

    /** @param non-empty-list<SocialInsuranceRelationshipInput> $relationships */
    private function socialPerson(string $id, array $relationships): SocialPersonMonthInput
    {
        return new SocialPersonMonthInput(
            $id,
            SocialJurisdictionEvidence::CzechRegimeVerified,
            0,
            $relationships,
        );
    }

    private function socialRelationship(
        string $id,
        SocialEmploymentKind $kind,
        ?int $agreedIncome,
        int $amount,
    ): SocialInsuranceRelationshipInput {
        return new SocialInsuranceRelationshipInput(
            $id,
            $kind,
            $agreedIncome,
            true,
            SocialIncomeAttribution::CurrentEmploymentMonth,
            [new SocialAssessmentComponent(
                'wage',
                $amount,
                SocialComponentTreatment::Included,
                SocialComponentTreatment::Included,
            )],
        );
    }

    /** @param non-empty-list<HealthInsuranceRelationshipInput> $relationships */
    private function healthPerson(string $id, array $relationships): HealthPersonMonthInput
    {
        return new HealthPersonMonthInput(
            $id,
            HealthJurisdictionEvidence::CzechRegimeVerified,
            null,
            HealthInsurerSnapshotStatus::Verified,
            '111',
            'insurer:synthetic-snapshot',
            $relationships,
            [],
            [],
            HealthMinimumTopUpResponsibility::Employee,
            null,
            null,
            HealthMinimumTopUpEmployerSelection::Unverified,
        );
    }

    private function healthRelationship(
        string $id,
        HealthEmploymentKind $kind,
        int $amountMinorUnits,
    ): HealthInsuranceRelationshipInput {
        return new HealthInsuranceRelationshipInput(
            $id,
            $kind,
            '2026-08-01',
            null,
            HealthIncomeAttribution::CurrentEmploymentMonth,
            [new HealthAssessmentComponent(
                'wage',
                $amountMinorUnits,
                HealthComponentTreatment::Included,
                HealthComponentTreatment::Included,
                HealthCorrectionTreatment::CurrentMonth,
            )],
        );
    }
}
