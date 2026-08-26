<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpEmployerSelection;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibility;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibilitySource;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\OtherWithholdingEligibility;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxRegime;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Run\PayrollRunStatutoryInputAssembler;
use MyInvoice\Service\Payroll\SocialInsurance\SocialDiscountEvidence;
use MyInvoice\Service\Payroll\SocialInsurance\SocialEmployerRateCategory;
use MyInvoice\Service\Payroll\SocialInsurance\SocialPartTimeDiscountReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PayrollRunStatutoryInputAssemblerTest extends TestCase
{
    public function testBuildsCanonicalInputsFromCompleteVersionTwoSnapshot(): void
    {
        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble(
            $this->completeSnapshot(),
        );

        self::assertSame([], $bundle->issues);
        self::assertNotNull($bundle->socialInsurance);
        self::assertNotNull($bundle->healthInsurance);
        self::assertCount(1, $bundle->incomeTax);

        $socialPerson = $bundle->socialInsurance->people[0];
        self::assertSame('employee:42', $socialPerson->personId);
        self::assertSame(12_300_000, $socialPerson->yearToDateAssessmentBaseBeforeMonthMinorUnits);
        self::assertSame(
            'employment:84',
            $socialPerson->relationships[0]->relationshipId,
        );
        self::assertSame(
            'input.420.mzda_mesicni',
            $socialPerson->relationships[0]->components[0]->code,
        );

        $healthPerson = $bundle->healthInsurance->people[0];
        self::assertSame('employee:42', $healthPerson->personId);
        self::assertSame('111', $healthPerson->insurerCode);
        self::assertSame(
            HealthMinimumTopUpEmployerSelection::ThisEmployer,
            $healthPerson->topUpEmployerSelection,
        );

        $tax = $bundle->incomeTax[0];
        self::assertSame('employee:42', $tax->employeeReference);
        self::assertSame('supplier:7', $tax->payerReference);
        self::assertSame('employment:84', $tax->relationships[0]->relationshipReference);
        self::assertSame(5, $tax->annualAccumulator?->completedMonths);
    }

    /**
     * Osvobozený benefit se zmrazeným rozpadem koše se do výpočtu DOSTANE
     * a nadlimitní část v něm vystupuje jako vlastní zdanitelná složka, která
     * vstupuje i do obou vyměřovacích základů.
     *
     * Bez rozpadu je osvobození nedoložené tvrzení a výpočet se u něj zastaví —
     * to se nemění, ověřuje to
     * {@see self::testExemptBenefitWithoutABasketStaysUnevidenced()}.
     */
    public function testOverLimitBenefitEntersTaxAndBothAssessmentBases(): void
    {
        $snapshot = $this->completeSnapshot();
        $person = &$snapshot['people'][0];
        $person['employments'][0]['inputs'][] = [
            'id' => 421,
            'amount_minor' => 3_000_000,
            'source_period_start' => null,
            'benefit_basket' => 'non_cash_leisure',
            'benefit_exempt_minor' => 2_448_350,
            'benefit_taxable_minor' => 551_650,
            'component' => [
                'code' => 'REKREACE_VOLNY_CAS',
                'tax_treatment' => 'exempt',
                'social_participation_treatment' => 'excluded',
                'social_treatment' => 'excluded',
                'health_participation_treatment' => 'excluded',
                'health_treatment' => 'excluded',
                'exemption_basket' => 'non_cash_leisure',
                'exemption_basis' => 'benefit_basket',
                'valid_from' => '2026-01-01',
                'valid_to' => null,
            ],
        ];
        unset($person);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertSame([], $bundle->issues);
        self::assertNotNull($bundle->socialInsurance);
        self::assertNotNull($bundle->healthInsurance);

        $social = $bundle->socialInsurance->people[0]->relationships[0]->components;
        self::assertSame([
            'input.420.mzda_mesicni',
            'input.421.rekreace_volny_cas',
            'input.421.rekreace_volny_cas.nadlimit',
        ], array_map(static fn ($item): string => $item->code, $social));
        self::assertSame(551_650, $social[2]->amountMinorUnits);

        $health = $bundle->healthInsurance->people[0]->relationships[0]->components;
        self::assertSame(
            'input.421.rekreace_volny_cas.nadlimit',
            $health[2]->code,
        );
        self::assertSame(551_650, $health[2]->amountMinorUnits);

        $tax = $bundle->incomeTax[0]->relationships[0]->components;
        self::assertSame(
            'input.421.rekreace_volny_cas.nadlimit',
            $tax[2]->code,
        );
        self::assertSame(551_650, $tax[2]->amountMinorUnits);
        // Osvobozená část zůstává osvobozená; do základu daně přispívá nulou.
        self::assertSame(2_448_350, $tax[1]->amountMinorUnits);
    }

    /** Osvobození bez koše zůstává nedoložené — brána se neuvolnila plošně. */
    public function testExemptBenefitWithoutABasketStaysUnevidenced(): void
    {
        $snapshot = $this->completeSnapshot();
        $person = &$snapshot['people'][0];
        $person['employments'][0]['inputs'][0]['component']['tax_treatment'] = 'exempt';
        unset($person);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertSame([], $bundle->incomeTax);
        self::assertContains(
            'income_tax|tax_component_exemption_evidence_missing|employee:42|employment:84',
            array_map(
                static fn ($issue): string => implode('|', [
                    $issue->toArray()['domain'],
                    $issue->toArray()['code'],
                    (string) $issue->toArray()['person_reference'],
                    (string) $issue->toArray()['relationship_reference'],
                ]),
                $bundle->issues,
            ),
        );
    }

    /**
     * Sazbová kategorie § 5a odst. 1 se musí dostat ze smluvních podmínek do
     * vstupu výpočtu. Dokud se nedostávala, měl vztah označený jako rizikový
     * ve vstupu běžnou sazbu a mzdový běh mu spočítal 24,8 % místo 27,8 % —
     * o rozdílu se uživatel nedozvěděl, protože zaškrtnuté políčko na kartě
     * vztahu vypadalo, že se uplatnilo.
     */
    public function testEmployerRateCategoryReachesTheSocialInputFromTheEmploymentTerms(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['employments'][0]['term']['social_employer_rate_category'] =
            'risk_employment';
        $snapshot['people'][0]['employments'][0]['term']['social_employer_rate_category_evidence'] =
            'kategorizace-praci/2026/17';

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertSame([], $bundle->issues);
        $relationship = $bundle->socialInsurance?->people[0]->relationships[0];
        self::assertSame(
            SocialEmployerRateCategory::RiskEmployment,
            $relationship?->employerRateCategory,
        );
        self::assertSame(
            'kategorizace-praci/2026/17',
            $relationship?->employerRateCategoryEvidenceReference,
        );
    }

    public function testMandatoryRiskySavingsOverridesIncreasedEmployerRate(): void
    {
        $snapshot = $this->completeSnapshot();
        $relationship = &$snapshot['people'][0]['employments'][0];
        $relationship['term']['social_employer_rate_category'] = 'risk_employment';
        $relationship['term']['social_employer_rate_category_evidence'] =
            'synthetic-risk-category';
        $relationship['risky_savings_evidence'] = [
            'id' => 91,
            'status' => 'approved',
            'risk_factor' => 'vibration',
            'work_category' => 3,
            'qualifying_shift_eighths' => 24,
            'right_claimed_on' => '2026-05-31',
            'employee_informed_on' => '2026-05-01',
            'pension_company' => 'Testovací penzijní společnost',
            'product_reference' => 'SYNTHETIC-PRODUCT',
            'institution_account_id' => 44,
            'institution_account_row_version' => 2,
            'institution_account_hash' => str_repeat('a', 64),
            'institution_account_masked' => '******0005 / 0100',
            'variable_symbol' => '123456',
            'specific_symbol' => null,
        ];
        unset($relationship);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertSame([], $bundle->issues);
        $socialRelationship = $bundle->socialInsurance?->people[0]->relationships[0];
        self::assertSame(
            SocialEmployerRateCategory::Ordinary,
            $socialRelationship?->employerRateCategory,
        );
        self::assertNull($socialRelationship?->employerRateCategoryEvidenceReference);
    }

    public function testClaimMadeInCurrentMonthKeepsRiskEmployerRateUntilNextMonth(): void
    {
        $snapshot = $this->completeSnapshot();
        $relationship = &$snapshot['people'][0]['employments'][0];
        $relationship['term']['social_employer_rate_category'] = 'risk_employment';
        $relationship['term']['social_employer_rate_category_evidence'] =
            'synthetic-risk-category';
        $relationship['risky_savings_evidence'] = [
            'id' => 92,
            'status' => 'approved',
            'risk_factor' => 'heat',
            'work_category' => 3,
            'qualifying_shift_eighths' => 24,
            'right_claimed_on' => '2026-06-01',
            'employee_informed_on' => null,
            'pension_company' => 'Testovací penzijní společnost',
            'product_reference' => 'SYNTHETIC-PRODUCT',
            'institution_account_id' => 44,
            'institution_account_row_version' => 2,
            'institution_account_hash' => str_repeat('b', 64),
            'institution_account_masked' => '******0005 / 0100',
            'variable_symbol' => '123456',
            'specific_symbol' => null,
        ];
        unset($relationship);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertSame([], $bundle->issues);
        $socialRelationship = $bundle->socialInsurance?->people[0]->relationships[0];
        self::assertSame(
            SocialEmployerRateCategory::RiskEmployment,
            $socialRelationship?->employerRateCategory,
        );
        self::assertSame(
            'synthetic-risk-category',
            $socialRelationship?->employerRateCategoryEvidenceReference,
        );
    }

    /**
     * Sleva podle § 7a se musí dostat ze smluvních podmínek do vstupu výpočtu.
     * Dokud se nedostávala, byl `partTimeEmployerDiscount` mrtvý vstup: nárok
     * šlo doložit, ale sleva se neuplatnila nikdy a zaměstnavatel platil o 5 %
     * vyměřovacího základu víc, než musel.
     */
    public function testPartTimeDiscountReachesTheSocialInputFromTheEmploymentTerms(): void
    {
        $snapshot = $this->completeSnapshot();
        $term = &$snapshot['people'][0]['employments'][0]['term'];
        $term['social_part_time_discount_reason'] = 'age_55_plus';
        $term['social_part_time_discount_evidence'] = null;
        $term['social_part_time_discount_notified_on'] = '2026-05-20';
        $term['weekly_hours'] = '20.00';
        unset($term);
        $snapshot['people'][0]['employments'][0]['time_month'] = $this->workMonth(90_000, 8_000);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertSame([], $bundle->issues);
        $relationship = $bundle->socialInsurance?->people[0]->relationships[0];
        self::assertSame(SocialDiscountEvidence::Verified, $relationship?->partTimeEmployerDiscount);
        self::assertSame(
            SocialPartTimeDiscountReason::Age55Plus,
            $relationship?->partTimeEmployerDiscountReason,
        );
        self::assertNull($relationship?->partTimeEmployerDiscountEvidenceReference);
        self::assertSame(98_000, $relationship?->partTimeDiscountAssessableMillihours);
        self::assertSame(20_000, $relationship?->agreedWeeklyWorkingMillihours);
        self::assertSame(30, $relationship?->partTimeDiscountMonthDays);
        self::assertSame(30, $relationship?->partTimeDiscountEmploymentDays);
    }

    /**
     * § 7a odst. 5 — bez oznámení záměru ČSSZ sleva NENÁLEŽÍ. Chybějící nebo
     * pozdní datum proto nesmí skončit tichou uplatněnou slevou: podle § 7c
     * odst. 3 by z ní byl dluh na pojistném.
     */
    public function testPartTimeDiscountWithoutTimelyNotificationBecomesUnverified(): void
    {
        foreach ([null, '2026-07-01'] as $notifiedOn) {
            $snapshot = $this->completeSnapshot();
            $term = &$snapshot['people'][0]['employments'][0]['term'];
            $term['social_part_time_discount_reason'] = 'age_55_plus';
            $term['social_part_time_discount_evidence'] = 'osobni-spis/2026/42';
            $term['social_part_time_discount_notified_on'] = $notifiedOn;
            $term['weekly_hours'] = '20.00';
            unset($term);

            $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

            self::assertSame(
                SocialDiscountEvidence::Unverified,
                $bundle->socialInsurance?->people[0]->relationships[0]->partTimeEmployerDiscount,
            );
        }
    }

    /**
     * Zmrazená revize starší než sloupec důvodu klíč vůbec nemá. Čte se jako
     * neuplatněná sleva — přesně tak, jak se z ní tehdy počítalo.
     */
    public function testSnapshotWithoutTheDiscountKeyStaysNotClaimed(): void
    {
        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($this->completeSnapshot());

        self::assertSame(
            SocialDiscountEvidence::NotClaimed,
            $bundle->socialInsurance?->people[0]->relationships[0]->partTimeEmployerDiscount,
        );
    }

    /**
     * Jakmile snapshot nese evidenci záměrů, rozhoduje ONA — ne ručně opsané
     * datum. Chybějící přijatý záměr slevu zavře, i kdyby bylo
     * `social_part_time_discount_notified_on` vyplněné a v termínu; § 7a odst. 5
     * váže nárok na doručení oznámení ČSSZ, které z ručního políčka neplyne.
     */
    public function testDiscountNeedsAnAcceptedIntentOnceTheSnapshotCarriesEvidence(): void
    {
        $snapshot = $this->completeSnapshot();
        $term = &$snapshot['people'][0]['employments'][0]['term'];
        $term['social_part_time_discount_reason'] = 'age_55_plus';
        $term['social_part_time_discount_evidence'] = 'osobni-spis/2026/42';
        $term['social_part_time_discount_notified_on'] = '2026-05-20';
        $term['social_part_time_discount_intent'] = null;
        $term['weekly_hours'] = '20.00';
        unset($term);
        $snapshot['people'][0]['employments'][0]['time_month'] =
            $this->workMonth(90_000, 8_000);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertSame(
            SocialDiscountEvidence::Unverified,
            $bundle->socialInsurance?->people[0]->relationships[0]->partTimeEmployerDiscount,
        );
    }

    /**
     * A naopak: přijatý záměr pokrývající období slevu uplatní i bez ručního
     * data. Tohle je celý smysl přesunu — doložení je podání, ne políčko.
     */
    public function testAcceptedIntentAloneVerifiesTheDiscount(): void
    {
        $snapshot = $this->completeSnapshot();
        $term = &$snapshot['people'][0]['employments'][0]['term'];
        $term['social_part_time_discount_reason'] = 'age_55_plus';
        $term['social_part_time_discount_evidence'] = null;
        $term['social_part_time_discount_notified_on'] = null;
        $term['social_part_time_discount_intent'] = [
            'status' => 'accepted',
            'intent_from' => '2026-01-01',
            'intent_to' => null,
            'accepted_on' => '2025-12-15',
        ];
        $term['weekly_hours'] = '20.00';
        unset($term);
        $snapshot['people'][0]['employments'][0]['time_month'] =
            $this->workMonth(90_000, 8_000);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        $relationship = $bundle->socialInsurance?->people[0]->relationships[0];
        self::assertSame(
            SocialDiscountEvidence::Verified,
            $relationship?->partTimeEmployerDiscount,
        );
        self::assertSame(
            SocialPartTimeDiscountReason::Age55Plus,
            $relationship?->partTimeEmployerDiscountReason,
        );
        self::assertNull($relationship?->partTimeEmployerDiscountEvidenceReference);
    }

    /**
     * Záměr ukončený uprostřed vykazovaného měsíce ho už nepokrývá
     * (§ 7b odst. 4 a kontrola 291 bod 1).
     */
    public function testIntentEndedInsideThePeriodClosesTheDiscount(): void
    {
        $snapshot = $this->completeSnapshot();
        $term = &$snapshot['people'][0]['employments'][0]['term'];
        $term['social_part_time_discount_reason'] = 'age_55_plus';
        $term['social_part_time_discount_evidence'] = 'osobni-spis/2026/42';
        $term['social_part_time_discount_notified_on'] = '2026-05-20';
        $term['social_part_time_discount_intent'] = [
            'status' => 'ended',
            'intent_from' => '2026-01-01',
            'intent_to' => '2026-06-15',
            'accepted_on' => '2025-12-15',
        ];
        $term['weekly_hours'] = '20.00';
        unset($term);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertSame(
            SocialDiscountEvidence::Unverified,
            $bundle->socialInsurance?->people[0]->relationships[0]->partTimeEmployerDiscount,
        );
    }

    /** @return array<string,mixed> */
    private function workMonth(int $workedMillihours, int $paidUnworkedMillihours): array
    {
        return [
            'id' => 7,
            'status' => 'approved',
            'jmhz_work_summary' => [
                'derivation_version' => 'jmhz-work-month.v2',
                'values' => [
                    'worked_millihours' => $workedMillihours,
                    'unworked_paid_millihours' => $paidUnworkedMillihours,
                ],
            ],
        ];
    }

    public function testRateCategoryDoesNotRequireEvidenceReference(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['employments'][0]['term']['social_employer_rate_category'] =
            'rescue_and_company_fire_service';
        $snapshot['people'][0]['employments'][0]['term']['social_employer_rate_category_evidence'] =
            '   ';

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        $relationship = $bundle->socialInsurance?->people[0]->relationships[0];
        self::assertSame(
            SocialEmployerRateCategory::RescueAndCompanyFireService,
            $relationship?->employerRateCategory,
        );
        self::assertNull($relationship?->employerRateCategoryEvidenceReference);
    }

    /**
     * Revize zmrazená dřív, než sloupec kategorie existoval, klíč vůbec nemá.
     * Ta se čte jako běžná sazba — tak se z ní tehdy počítalo a dosadit do ní
     * dnešní fail-closed by přepsalo hotovou historii.
     */
    public function testSnapshotFrozenBeforeTheCategoryColumnStaysOrdinary(): void
    {
        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble(
            $this->completeSnapshot(),
        );

        self::assertSame(
            SocialEmployerRateCategory::Ordinary,
            $bundle->socialInsurance?->people[0]->relationships[0]->employerRateCategory,
        );
        self::assertNull(
            $bundle->socialInsurance?->people[0]->relationships[0]
                ->employerRateCategoryEvidenceReference,
        );
    }

    public function testMissingAnnualAccumulatorsBlockInputsInsteadOfInventingZero(): void
    {
        $snapshot = $this->completeSnapshot();
        unset($snapshot['people'][0]['statutory_accumulators']);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNull($bundle->socialInsurance);
        self::assertNotNull($bundle->healthInsurance);
        self::assertSame([], $bundle->incomeTax);
        self::assertSame([
            [
                'domain' => 'income_tax',
                'code' => 'annual_accumulator_missing',
                'person_reference' => 'employee:42',
                'relationship_reference' => null,
            ],
            [
                'domain' => 'social_insurance',
                'code' => 'annual_accumulator_missing',
                'person_reference' => 'employee:42',
                'relationship_reference' => null,
            ],
        ], array_map(
            static fn ($issue): array => $issue->toArray(),
            $bundle->issues,
        ));
    }

    public function testUnverifiedOverridesAndCorrectionsReturnDeterministicScopedIssues(): void
    {
        $snapshot = $this->completeSnapshot();
        $person = &$snapshot['people'][0];
        $person['statutory_evidence']['social']['jurisdiction'] = [
            'id' => 5,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'row_version' => 1,
            'jurisdiction' => 'foreign_regime_verified',
            'foreign_country_code' => 'DE',
            'jurisdiction_evidence_reference' => 'document:foreign-regime',
            'a1_status' => 'unverified',
            'a1_certificate_reference' => null,
            'a1_valid_until' => null,
        ];
        $person['employments'][0]['term']['social_insurance_participation'] =
            'included';
        $person['employments'][0]['inputs'][0]['source_period_start'] =
            '2026-05-01';
        $person['employments'][0]['inputs'][0]['component']['tax_treatment'] =
            'exempt';
        unset($person);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNull($bundle->socialInsurance);
        self::assertNull($bundle->healthInsurance);
        self::assertSame([], $bundle->incomeTax);
        self::assertSame([
            'health_insurance|prior_period_component_requires_revision|employee:42|employment:84',
            'income_tax|prior_period_component_requires_revision|employee:42|employment:84',
            'income_tax|tax_component_exemption_evidence_missing|employee:42|employment:84',
            'social_insurance|participation_override_unsupported|employee:42|employment:84',
            'social_insurance|prior_period_component_requires_revision|employee:42|employment:84',
            'social_insurance|social_a1_evidence_unverified|employee:42|',
        ], array_map(
            static fn ($issue): string => implode('|', [
                $issue->domain,
                $issue->code,
                $issue->personReference,
                $issue->relationshipReference,
            ]),
            $bundle->issues,
        ));
    }

    public function testContradictoryTaxDeclarationEvidenceBlocksOnlyTaxDomain(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['employments'][0]['term']['tax_declaration_signed'] =
            false;

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNotNull($bundle->socialInsurance);
        self::assertNotNull($bundle->healthInsurance);
        self::assertSame([], $bundle->incomeTax);
        self::assertSame(
            'tax_declaration_term_conflict',
            $bundle->issues[0]->code,
        );
        self::assertSame(
            'employment:84',
            $bundle->issues[0]->relationshipReference,
        );
    }

    public function testUnverifiedAndCrossTenantAccumulatorStatesFailClosed(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['statutory_accumulators']['social_insurance'] = [
            'status' => 'unverified',
            'issue_code' => 'annual_accumulator_opening_missing',
            'state' => null,
        ];
        $snapshot['people'][0]['statutory_accumulators']['income_tax']['state']
            ['supplier_id'] = 8;

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNull($bundle->socialInsurance);
        self::assertSame([], $bundle->incomeTax);
        self::assertSame([
            'income_tax|annual_accumulator_invalid',
            'social_insurance|annual_accumulator_opening_missing',
        ], array_map(
            static fn ($issue): string => "{$issue->domain}|{$issue->code}",
            $bundle->issues,
        ));
    }

    /**
     * Chybějící měsíční evidence zdravotního minima není mezera v podkladech,
     * ale zákonný výchozí stav podle § 3 odst. 10 zákona č. 592/1992 Sb.:
     * doplatek hradí zaměstnanec. Ve vstupu je proto vidět, že hodnota je
     * odvozená, ne prohlášená — a doklad k ní nepatří.
     */
    public function testMissingHealthMonthEvidenceMeansTheStatutoryDefault(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['statutory_evidence']['health']['month_evidence']
            = null;

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertSame([], array_map(
            static fn ($issue): string => "{$issue->domain}|{$issue->code}",
            $bundle->issues,
        ));
        self::assertNotNull($bundle->healthInsurance);
        $person = $bundle->healthInsurance->people[0];
        self::assertSame(
            HealthMinimumTopUpResponsibility::Employee,
            $person->topUpResponsibility,
        );
        self::assertSame(
            HealthMinimumTopUpResponsibilitySource::StatutoryDefault,
            $person->topUpResponsibilitySource,
        );
        self::assertNull($person->topUpResponsibilityEvidenceReference);
    }

    /**
     * Zapsaný řádek default přebíjí a zůstává prohlášením uživatele — proto
     * `declared`. Bez tohohle rozlišení by schválená mzda po letech neuměla
     * říct, jestli plátce doplatku někdo doložil, nebo se odvodil ze zákona.
     */
    public function testDeclaredHealthMonthEvidenceOverridesTheStatutoryDefault(): void
    {
        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble(
            $this->completeSnapshot(),
        );

        self::assertNotNull($bundle->healthInsurance);
        self::assertSame(
            HealthMinimumTopUpResponsibilitySource::Declared,
            $bundle->healthInsurance->people[0]->topUpResponsibilitySource,
        );
    }

    /**
     * Prohlásit „nevíme" je pořád možné a pořád to znamená ruční posouzení.
     * Zjednodušení se týká CHYBĚJÍCÍHO záznamu, ne záznamu, který říká, že
     * odpověď nikdo nezná.
     */
    public function testExplicitlyUnverifiedResponsibilityStillBlocksHealthInputs(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['statutory_evidence']['health']['month_evidence']
            ['top_up_responsibility'] = 'unverified';

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNull($bundle->healthInsurance);
        self::assertSame(
            ['health_insurance|health_minimum_responsibility_unverified'],
            array_map(
                static fn ($issue): string => "{$issue->domain}|{$issue->code}",
                $bundle->issues,
            ),
        );
    }

    public function testEmployerObstacleDoesNotRequireEvidenceReference(): void
    {
        $snapshot = $this->completeSnapshot();
        $month = &$snapshot['people'][0]['statutory_evidence']['health']
            ['month_evidence'];
        $month['top_up_responsibility'] = 'employer_obstacle_verified';
        $month['top_up_responsibility_evidence_reference'] = null;
        unset($month);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNotNull($bundle->healthInsurance);
        self::assertSame([], $bundle->issues);
        $person = $bundle->healthInsurance->people[0];
        self::assertSame(
            HealthMinimumTopUpResponsibility::EmployerObstacleVerified,
            $person->topUpResponsibility,
        );
        self::assertNull($person->topUpResponsibilityEvidenceReference);
    }

    /**
     * Zařazení podle § 6 odst. 4 písm. b) ZDP se u pracovního poměru, zaměstnání
     * malého rozsahu a DPP neptá — plyne ze zákona samo, takže výpočet dostane
     * `automatic` a doklad o zařazení k němu nepatří.
     */
    public function testRelationshipsClassifiedByLawKeepAutomaticEligibility(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['employments'][0]['term']
            ['other_withholding_eligibility'] = 'eligible';

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        $relationship = $bundle->incomeTax[0]->relationships[0];
        self::assertSame(
            OtherWithholdingEligibility::Automatic,
            $relationship->otherWithholdingEligibility,
        );
        self::assertNull($relationship->classificationEvidenceReference);
    }

    /**
     * Odměna jednatele naopak zařazení ze zákona nemá — nese ho prohlášení
     * plátce ve smluvních podmínkách. Sestavovač ho posílal natvrdo jako
     * `automatic`, takže výpočet každého jednatele bez podepsaného prohlášení
     * odmítl s `other-withholding-eligibility-unverified`, ať uživatel nastavil
     * cokoli. Doklad o zařazení míří na verzi podmínek, ve které prohlášení je.
     *
     * @param array{0:string,1:OtherWithholdingEligibility} $case
     */
    #[DataProvider('payerStatements')]
    public function testStatutoryBodyTakesEligibilityFromEmploymentTerms(
        string $stored,
        OtherWithholdingEligibility $expected,
    ): void {
        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble(
            $this->directorSnapshot($stored),
        );

        self::assertSame([], $bundle->issues);
        $relationship = $bundle->incomeTax[0]->relationships[0];
        self::assertSame($expected, $relationship->otherWithholdingEligibility);
        self::assertSame(
            'employment-term:99',
            $relationship->classificationEvidenceReference,
        );
    }

    /** @return iterable<string,array{string,OtherWithholdingEligibility}> */
    public static function payerStatements(): iterable
    {
        yield 'nezakládá účast' => [
            'eligible',
            OtherWithholdingEligibility::EligibleVerified,
        ];
        yield 'zakládá účast' => [
            'ineligible',
            OtherWithholdingEligibility::IneligibleVerified,
        ];
    }

    /**
     * Fail-closed: snapshot bez prohlášení (typicky běh uzamčený před migrací
     * 1403) se nesmí dopočítat jinak, než jak by ho spočítal tehdejší kód.
     */
    public function testMissingPayerStatementFallsBackToUnverified(): void
    {
        $snapshot = $this->directorSnapshot('eligible');
        unset($snapshot['people'][0]['employments'][0]['term']
            ['other_withholding_eligibility']);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        $relationship = $bundle->incomeTax[0]->relationships[0];
        self::assertSame(
            OtherWithholdingEligibility::Unverified,
            $relationship->otherWithholdingEligibility,
        );
        self::assertNull($relationship->classificationEvidenceReference);
    }

    /**
     * Celá cesta, kvůli které tahle větev vznikla: jednatel s odměnou 4 500 Kč
     * bez podepsaného prohlášení. Sestavovač vezme prohlášení plátce ze
     * smluvních podmínek a výpočet doběhne — dřív skončil ručním posouzením,
     * které se nedalo přebít, protože to byl issue zákonného balíku, ne
     * validace řádku.
     */
    public function testDirectorAtDecisiveAmountCompletesTheStatutoryCalculation(): void
    {
        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble(
            $this->directorSnapshot('eligible'),
        );

        self::assertSame([], $bundle->issues);
        $result = (new MonthlyEmploymentIncomeTaxCalculator(
            new PayrollRulesetProvider([
                CzechPayrollRulesets2026::provider()
                    ->forDate(PayrollRulesetDomain::IncomeTax, '2026-06-30'),
            ]),
        ))->calculate($bundle->incomeTax[0]);

        self::assertSame([], $result->issues);
        self::assertSame(TaxCalculationStatus::Calculated, $result->status);
        // 4 500 Kč je sama rozhodná částka, test § 6 odst. 4 ZDP je ostrý —
        // účast na nemocenském pojištění vzniká a daní se zálohou.
        self::assertSame(TaxRegime::Advance, $result->relationships[0]->regime);
        self::assertSame(450_000, $result->advanceTax?->taxableIncomeMinorUnits);
    }

    /**
     * Snapshot jednatele s odměnou 4 500 Kč, který u plátce nepodepsal
     * prohlášení k dani.
     *
     * @return array<string,mixed>
     */
    private function directorSnapshot(string $eligibility): array
    {
        $snapshot = $this->completeSnapshot();
        $person = &$snapshot['people'][0];
        $person['statutory_evidence']['income_tax']['declaration']['status'] =
            'not-signed';
        $employment = &$person['employments'][0];
        $employment['employment']['relation_type'] = 'statutory_body';
        $employment['employment']['monthly_gross_minor'] = 450_000;
        $employment['term']['tax_declaration_signed'] = false;
        $employment['term']['other_withholding_eligibility'] = $eligibility;
        $employment['inputs'][0]['amount_minor'] = 450_000;
        unset($person, $employment);

        // Sleva na poplatníka se bez podepsaného prohlášení uplatnit nedá;
        // ponechaný nárok by shodil výpočet na `tax-credit-requires-signed-declaration`
        // a test by měřil něco jiného, než měřit má.
        $snapshot['people'][0]['statutory_evidence']['income_tax']['credit_claims'] = [];

        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function completeSnapshot(): array
    {
        return [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 7,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'payment_date' => '2026-07-15',
            'statutory_period' => [
                'period_start' => '2026-06-01',
                'period_end' => '2026-06-30',
                'payment_date' => '2026-07-15',
                'tax_calculation_date' => '2026-06-30',
                'social_calculation_date' => '2026-06-30',
                'health_calculation_date' => '2026-06-30',
            ],
            'people' => [[
                'employee' => [
                    'id' => 42,
                    'full_name' => 'Testovací Zaměstnanec',
                ],
                'statutory_accumulators' => [
                    'schema_version' =>
                        'payroll-person-statutory-accumulators.v1',
                    'social_insurance' => [
                        'status' => 'verified',
                        'issue_code' => null,
                        'state' => [
                            'schema_version' =>
                                'payroll-statutory-accumulator-state.v1',
                            'supplier_id' => 7,
                            'employee_id' => 42,
                            'calculation_kind' => 'social_insurance',
                            'year' => 2026,
                            'before_period_start' => '2026-06-01',
                            'totals' => [
                                'assessment_base_minor_units' => 12_300_000,
                            ],
                        ],
                    ],
                    'income_tax' => [
                        'status' => 'verified',
                        'issue_code' => null,
                        'state' => [
                            'schema_version' =>
                                'payroll-statutory-accumulator-state.v1',
                            'supplier_id' => 7,
                            'employee_id' => 42,
                            'calculation_kind' => 'income_tax',
                            'year' => 2026,
                            'before_period_start' => '2026-06-01',
                            'totals' => [
                                'completed_months' => 5,
                                'advance_base_minor_units' => 12_300_000,
                                'withholding_base_minor_units' => 0,
                                'advance_tax_minor_units' => 1_845_000,
                                'withholding_tax_minor_units' => 0,
                                'applied_non_refundable_credits_minor_units' =>
                                    154_200,
                                'applied_child_credit_minor_units' => 0,
                                'tax_bonus_minor_units' => 0,
                                'bonus_qualifying_income_minor_units' =>
                                    12_300_000,
                            ],
                        ],
                    ],
                ],
                'statutory_evidence' => $this->completeEvidence(),
                'employments' => [[
                    'employment' => [
                        'id' => 84,
                        'employee_id' => 42,
                        'relation_type' => 'employment',
                        'start_date' => '2025-01-01',
                        'actual_start_date' => '2025-01-02',
                        'end_date' => null,
                        'monthly_gross_minor' => 4_500_000,
                    ],
                    'term' => [
                        'id' => 99,
                        'effective_from' => '2025-01-01',
                        'effective_to' => null,
                        'social_insurance_participation' => 'automatic',
                        'health_insurance_participation' => 'automatic',
                        'tax_regime' => 'advance',
                        'tax_declaration_signed' => true,
                    ],
                    'inputs' => [[
                        'id' => 420,
                        'amount_minor' => 4_500_000,
                        'source_period_start' => null,
                        'component' => [
                            'code' => 'MZDA_MESICNI',
                            'tax_treatment' => 'included',
                            'social_participation_treatment' => 'included',
                            'social_treatment' => 'included',
                            'health_participation_treatment' => 'included',
                            'health_treatment' => 'included',
                        ],
                    ]],
                ]],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function completeEvidence(): array
    {
        return [
            'schema_version' => 'payroll-person-statutory-evidence.v1',
            'employee_id' => 42,
            'effective_on' => '2026-06-30',
            'health' => [
                'coverage' => [
                    'id' => 1,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'jurisdiction' => 'czech_regime_verified',
                    'foreign_country_code' => null,
                    'jurisdiction_evidence_reference' => null,
                    'insurer_status' => 'verified',
                    'insurer_code' => '111',
                    'insurer_evidence_reference' => 'document:health-insurer',
                ],
                'minimum_reductions' => [],
                'month_evidence' => [
                    'id' => 2,
                    'period_start' => '2026-06-01',
                    'row_version' => 1,
                    'top_up_responsibility' => 'employee',
                    'top_up_responsibility_evidence_reference' => null,
                    'selected_top_up_employer_reference' => null,
                    'selected_top_up_employer_evidence_reference' => null,
                ],
                'other_employer_bases' => [],
            ],
            'income_tax' => [
                'declaration' => [
                    'id' => 3,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'status' => 'signed',
                    'evidence_reference' => 'document:tax-declaration',
                ],
                'residence' => [
                    'id' => 4,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'residence' => 'czech-resident',
                    'country_code' => 'CZ',
                    'evidence_reference' => 'document:tax-residence',
                ],
                'credit_claims' => [[
                    'id' => 5,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'credit_kind' => 'taxpayer',
                    'evidence_status' => 'verified',
                    'evidence_reference' => 'document:taxpayer-credit',
                ]],
                'child_claims' => [],
            ],
            'social' => [
                'jurisdiction' => [
                    'id' => 6,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'jurisdiction' => 'czech_regime_verified',
                    'foreign_country_code' => null,
                    'jurisdiction_evidence_reference' => null,
                    'a1_status' => 'not_applicable',
                    'a1_certificate_reference' => null,
                    'a1_valid_until' => null,
                ],
                'working_pensioner_discount' => [
                    'id' => 7,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                    'row_version' => 1,
                    'status' => 'not_claimed',
                    'evidence_reference' => null,
                ],
            ],
        ];
    }
}
