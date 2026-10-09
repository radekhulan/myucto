<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumReductionReason;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpEmployerSelection;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibility;
use MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumTopUpResponsibilitySource;
use MyInvoice\Service\Payroll\IncomeTax\MonthlyEmploymentIncomeTaxCalculator;
use MyInvoice\Service\Payroll\IncomeTax\TaxCalculationStatus;
use MyInvoice\Service\Payroll\IncomeTax\TaxRegime;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\RiskySavings\PayrollRiskySavingsRules;
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

    public function testDuplicatePersonInSnapshotBlocksAllStatutoryDomains(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][] = $snapshot['people'][0];

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNull($bundle->socialInsurance);
        self::assertNull($bundle->healthInsurance);
        self::assertSame([], $bundle->incomeTax);
        self::assertSame(
            ['snapshot|duplicate_employee_reference|employee:42'],
            array_map(
                static fn ($issue): string => implode('|', [
                    $issue->domain,
                    $issue->code,
                    (string) $issue->personReference,
                ]),
                $bundle->issues,
            ),
        );
    }

    public function testEmploymentCannotBelongToTwoPeopleInOneSnapshot(): void
    {
        $snapshot = $this->completeSnapshot();
        $duplicate = $snapshot['people'][0];
        $duplicate['employee']['id'] = 43;
        $duplicate['statutory_accumulators']['social_insurance']['state'][
            'employee_id'
        ] = 43;
        $duplicate['statutory_accumulators']['income_tax']['state'][
            'employee_id'
        ] = 43;
        $duplicate['statutory_evidence']['employee_id'] = 43;
        $duplicate['employments'][0]['employment']['employee_id'] = 43;
        $snapshot['people'][] = $duplicate;

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNull($bundle->socialInsurance);
        self::assertNull($bundle->healthInsurance);
        self::assertSame([], $bundle->incomeTax);
        self::assertSame(
            ['snapshot|duplicate_employment_reference|employee:43|employment:84'],
            array_map(
                static fn ($issue): string => implode('|', [
                    $issue->domain,
                    $issue->code,
                    (string) $issue->personReference,
                    (string) $issue->relationshipReference,
                ]),
                $bundle->issues,
            ),
        );
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

    public function testRiskySavingsWithoutLockedRulesetFailsClosed(): void
    {
        $snapshot = $this->completeSnapshot();
        unset($snapshot['risky_savings_ruleset']);
        $snapshot['people'][0]['employments'][0]['risky_savings_evidence'] = [
            'id' => 93,
            'status' => 'approved',
            'risk_factor' => 'cold',
            'work_category' => 3,
            'qualifying_shift_eighths' => 24,
            'right_claimed_on' => '2026-05-31',
            'employee_informed_on' => '2026-05-01',
            'pension_company' => 'Testovací penzijní společnost',
            'product_reference' => 'SYNTHETIC-PRODUCT',
            'institution_account_id' => 44,
            'institution_account_row_version' => 2,
            'institution_account_hash' => str_repeat('c', 64),
        ];

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNull($bundle->socialInsurance);
        self::assertContains(
            'social_insurance|risky_savings_ruleset_invalid|employee:42|employment:84',
            array_map(
                static fn ($issue): string => implode('|', [
                    $issue->domain,
                    $issue->code,
                    (string) $issue->personReference,
                    (string) $issue->relationshipReference,
                ]),
                $bundle->issues,
            ),
        );
    }

    public function testMalformedLockedRiskySavingsRulesetFailsClosed(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['risky_savings_ruleset']['rate'] = '0.0000000000000000001';
        $snapshot['people'][0]['employments'][0]['risky_savings_evidence'] = [
            'id' => 93,
            'status' => 'approved',
            'risk_factor' => 'cold',
            'work_category' => 3,
            'qualifying_shift_eighths' => 24,
            'right_claimed_on' => '2026-05-31',
            'employee_informed_on' => '2026-05-01',
            'pension_company' => 'Testovací penzijní společnost',
            'product_reference' => 'SYNTHETIC-PRODUCT',
            'institution_account_id' => 44,
            'institution_account_row_version' => 2,
            'institution_account_hash' => str_repeat('c', 64),
        ];

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNull($bundle->socialInsurance);
        self::assertContains(
            'social_insurance|risky_savings_ruleset_invalid|employee:42|employment:84',
            array_map(
                static fn ($issue): string => implode('|', [
                    $issue->domain,
                    $issue->code,
                    (string) $issue->personReference,
                    (string) $issue->relationshipReference,
                ]),
                $bundle->issues,
            ),
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
     * OZUSPOJ-formularOzuspoj-6: důvod slevy s věkovou hranicí se proti datu
     * narození nikde neověřoval. Snímek teď nese odvozený výrok a nesplněná
     * nebo neověřitelná podmínka slevu zavře, i když je záměr přijatý.
     *
     * @return array<string,mixed>
     */
    private function acceptedIntentSnapshot(?string $ageCondition, bool $withKey = true): array
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
        if ($withKey) {
            $term['social_part_time_discount_age_condition'] = $ageCondition;
        }
        $term['weekly_hours'] = '20.00';
        unset($term);
        $snapshot['people'][0]['employments'][0]['time_month'] =
            $this->workMonth(90_000, 8_000);

        return $snapshot;
    }

    public function testAgeConditionNotMetClosesTheDiscountEvenWithAnAcceptedIntent(): void
    {
        foreach (['not_met', 'unknown'] as $condition) {
            $bundle = (new PayrollRunStatutoryInputAssembler())
                ->assemble($this->acceptedIntentSnapshot($condition));

            self::assertSame(
                SocialDiscountEvidence::Unverified,
                $bundle->socialInsurance?->people[0]->relationships[0]->partTimeEmployerDiscount,
                $condition,
            );
        }
    }

    public function testProtectedLaborMarketFlagReachesTheRelationshipInput(): void
    {
        $snapshot = $this->acceptedIntentSnapshot('not_applicable');
        $snapshot['people'][0]['employments'][0]['term']['social_part_time_discount_reason'] = 'disabled_person';
        $snapshot['people'][0]['employments'][0]['term']['employer_protected_labor_market'] = true;

        $relationship = (new PayrollRunStatutoryInputAssembler())
            ->assemble($snapshot)->socialInsurance?->people[0]->relationships[0];
        self::assertTrue($relationship?->employerOnProtectedLaborMarket);

        unset($snapshot['people'][0]['employments'][0]['term']['employer_protected_labor_market']);
        $relationship = (new PayrollRunStatutoryInputAssembler())
            ->assemble($snapshot)->socialInsurance?->people[0]->relationships[0];
        self::assertFalse($relationship?->employerOnProtectedLaborMarket);
    }

    /**
     * Částečná práce s příspěvkem v měsíci (překážka `partial_work`) znamená,
     * že je zaměstnanec v přehledu nákladů podle § 120e odst. 5 zákona
     * o zaměstnanosti; jiná překážka zaměstnavatele ani částečná práce mimo
     * měsíc takový příznak nedávají.
     */
    public function testPartialWorkAbsenceInTheMonthMarksTheRelationship(): void
    {
        $snapshot = $this->acceptedIntentSnapshot('not_applicable');
        $absence = static fn (string $kind, string $from, string $to): array => [
            'id' => 9100,
            'absence_type' => 'employer_obstacle',
            'obstacle_kind' => $kind,
            'date_from' => $from,
            'date_to' => $to,
            'compensation_rate_basis_points' => 8_000,
        ];
        $assemble = static fn (array $snapshot) => (new PayrollRunStatutoryInputAssembler())
            ->assemble($snapshot)->socialInsurance?->people[0]->relationships[0];
        $period = substr((string) $snapshot['period_start'], 0, 7);

        $snapshot['people'][0]['employments'][0]['absences'] = [
            $absence('partial_work', $period . '-10', $period . '-12'),
        ];
        self::assertTrue($assemble($snapshot)?->listedInPartialWorkOverview);

        $snapshot['people'][0]['employments'][0]['absences'] = [
            $absence('downtime', $period . '-10', $period . '-12'),
            $absence('partial_work', '2000-01-01', '2000-01-31'),
        ];
        self::assertFalse($assemble($snapshot)?->listedInPartialWorkOverview);
    }

    /**
     * Příznak člena družstva nebo SVJ jde ze zmrazených podmínek vztahu do
     * vstupu výpočtu ZP. Revize bez klíče se počítají jako dřív.
     */
    public function testAssociationMemberFlagReachesTheHealthRelationshipInput(): void
    {
        $snapshot = $this->acceptedIntentSnapshot('not_applicable');
        $snapshot['people'][0]['employments'][0]['term']['health_association_member'] = true;

        $relationship = (new PayrollRunStatutoryInputAssembler())
            ->assemble($snapshot)->healthInsurance?->people[0]->relationships[0];
        self::assertTrue($relationship?->associationMember);

        unset($snapshot['people'][0]['employments'][0]['term']['health_association_member']);
        $relationship = (new PayrollRunStatutoryInputAssembler())
            ->assemble($snapshot)->healthInsurance?->people[0]->relationships[0];
        self::assertFalse($relationship?->associationMember);
    }

    public function testAgeConditionMetKeepsTheDiscount(): void
    {
        $bundle = (new PayrollRunStatutoryInputAssembler())
            ->assemble($this->acceptedIntentSnapshot('met'));

        self::assertSame(
            SocialDiscountEvidence::Verified,
            $bundle->socialInsurance?->people[0]->relationships[0]->partTimeEmployerDiscount,
        );
    }

    /** Starší zmrazená revize klíč nemá a posuzuje se tak, jak se z ní počítalo. */
    public function testSnapshotWithoutTheAgeKeyKeepsThePreviousAssessment(): void
    {
        $bundle = (new PayrollRunStatutoryInputAssembler())
            ->assemble($this->acceptedIntentSnapshot(null, withKey: false));

        self::assertSame(
            SocialDiscountEvidence::Verified,
            $bundle->socialInsurance?->people[0]->relationships[0]->partTimeEmployerDiscount,
        );
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

    /**
     * Měsíc bez svátku a bez nepřítomnosti (únor) má IN07 nenastalou a hodiny
     * s náhradou prázdné. Pro § 7a je to potvrzená nula, ne chybějící údaj; dřív
     * běh slevu zastavil hláškou „chybí skutečně odpracované hodiny", přestože
     * schválený pracovní měsíc existoval. Prázdno při IN07 nastalé nebo bez
     * interakce v souhrnu zůstává chybějícím údajem.
     */
    public function testMonthWithoutUnworkedHoursCountsWorkedHoursOnly(): void
    {
        $assessable = function (?bool $in07): ?int {
            $snapshot = $this->acceptedIntentSnapshot('not_applicable');
            $month = $this->workMonth(150_000, 0);
            $month['jmhz_work_summary']['values']['unworked_paid_millihours'] = null;
            if ($in07 !== null) {
                $month['jmhz_work_summary']['interactions'] = ['IN07' => $in07, 'IN08' => false];
            }
            $snapshot['people'][0]['employments'][0]['time_month'] = $month;

            return (new PayrollRunStatutoryInputAssembler())->assemble($snapshot)
                ->socialInsurance?->people[0]->relationships[0]->partTimeDiscountAssessableMillihours;
        };

        self::assertSame(150_000, $assessable(false));
        self::assertNull($assessable(true));
        self::assertNull($assessable(null));
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
        // Bez ročních součtů nemá osoba čistou mzdu, takže vypadne ze všech
        // tří vstupů — i ze zdravotního, kde sama problém nemá.
        self::assertNull($bundle->healthInsurance);
        self::assertSame([], $bundle->incomeTax);
        self::assertSame([42], array_keys($bundle->blockedPeople));
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
            // Podmínky vztahu tvrdí českou účast, evidence osoby cizí (DE).
            'social_insurance|social_jurisdiction_term_conflict|employee:42|employment:84',
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

    /**
     * A1 končí 15. 6., cizí příslušnost pokračuje celý měsíc: pojistné za
     * zbytek měsíce by se spočítalo bez dokladu. Zastaví se jen tahle osoba.
     */
    public function testA1ExpiringInsideTheMonthBlocksThePerson(): void
    {
        $snapshot = $this->foreignSnapshot('2026-06-15', null);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertContains(
            'social_insurance|social_a1_expired|employee:42|',
            self::issueKeys($bundle->issues),
        );
    }

    /**
     * Věta cizí příslušnosti končí s A1 (15. 6.), od 16. 6. navazuje česká:
     * A1 pokrývá celou svou větu, výpočet ho neblokuje.
     */
    public function testA1CoveringItsJurisdictionRowPasses(): void
    {
        $snapshot = $this->foreignSnapshot('2026-06-15', '2026-06-15');

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNotContains(
            'social_insurance|social_a1_expired|employee:42|',
            self::issueKeys($bundle->issues),
        );
        self::assertSame([], array_filter(
            self::issueKeys($bundle->issues),
            static fn (string $key): bool => str_contains($key, 'social_jurisdiction_term_conflict'),
        ));
    }

    /** Podmínky vztahu uvádějí jiný stát cizích předpisů než evidence osoby. */
    public function testForeignLegislationCountryOnTermMustMatchPersonEvidence(): void
    {
        $snapshot = $this->foreignSnapshot('2026-12-31', null);
        $snapshot['people'][0]['employments'][0]['term']['foreign_legislation_country_code'] = 'AT';

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertContains(
            'social_insurance|social_jurisdiction_term_conflict|employee:42|employment:84',
            self::issueKeys($bundle->issues),
        );
    }

    /** @return array<string,mixed> */
    private function foreignSnapshot(string $a1Until, ?string $rowEnd): array
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['statutory_evidence']['social']['jurisdiction'] = [
            'id' => 5,
            'effective_from' => '2026-01-01',
            'effective_to' => $rowEnd,
            'row_version' => 1,
            'jurisdiction' => 'foreign_regime_verified',
            'foreign_country_code' => 'DE',
            'jurisdiction_evidence_reference' => 'document:foreign-regime',
            'a1_status' => 'verified',
            'a1_certificate_reference' => 'document:a1',
            'a1_valid_until' => $a1Until,
        ];
        $term = &$snapshot['people'][0]['employments'][0]['term'];
        $term['social_insurance_participation'] = 'foreign';
        $term['foreign_legislation_country_code'] = 'DE';
        $term['a1_certificate_until'] = $a1Until;
        unset($term);

        return $snapshot;
    }

    /**
     * @param list<object> $issues
     * @return list<string>
     */
    private static function issueKeys(array $issues): array
    {
        return array_map(
            static fn ($issue): string => implode('|', [
                $issue->domain,
                $issue->code,
                $issue->personReference,
                $issue->relationshipReference,
            ]),
            $issues,
        );
    }

    /**
     * Prohlášení k dani má JEDEN zdroj — zákonnou evidenci osoby.
     *
     * Sloupec smluvních podmínek býval druhým, nezávisle editovatelným místem
     * pro tentýž údaj a jeho rozpor s evidencí shazoval celou daňovou doménu
     * blokátorem `tax_declaration_term_conflict`. Rozejít se přitom musely:
     * prohlášení se podepisuje kdykoliv v průběhu vztahu, kdežto smluvní
     * podmínky se kvůli podpisu neverzují. Snímek si dnes hodnotu bere z téže
     * evidence ({@see PayrollRunSnapshotBuilder}), takže zastaralý sloupec
     * nesmí výpočet zastavit.
     */
    public function testStaleTaxDeclarationOnTermDoesNotBlockTaxDomain(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['employments'][0]['term']['tax_declaration_signed'] =
            false;

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNotNull($bundle->socialInsurance);
        self::assertNotNull($bundle->healthInsurance);
        self::assertNotSame([], $bundle->incomeTax);
        self::assertSame([], array_values(array_filter(
            $bundle->issues,
            static fn ($issue): bool => $issue->code === 'tax_declaration_term_conflict',
        )));
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

    /**
     * § 3 odst. 10 věta třetí zákona č. 592/1992 Sb.: základ snížený
     * překážkou na straně zaměstnavatele doplácí zaměstnavatel. Prostoj
     * s náhradou 80 % se v měsíci bez prohlášení odvodí sám, dokladem je
     * schválená nepřítomnost.
     */
    public function testReducedEmployerObstacleShiftsTheTopUpToTheEmployer(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['statutory_evidence']['health']['month_evidence'] = null;
        $snapshot['people'][0]['employments'][0]['absences'] = [
            self::obstacleAbsence(41, 'downtime', 8_000),
        ];

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNotNull($bundle->healthInsurance);
        $person = $bundle->healthInsurance->people[0];
        self::assertSame(HealthMinimumTopUpResponsibility::EmployerObstacleVerified, $person->topUpResponsibility);
        self::assertSame(
            HealthMinimumTopUpResponsibilitySource::DerivedEmployerObstacle,
            $person->topUpResponsibilitySource,
        );
        self::assertSame('absence:41', $person->topUpResponsibilityEvidenceReference);
    }

    /** § 208 ZP se platí průměrem a základ nesnižuje: výchozí stav zůstává. */
    public function testFullyCompensatedEmployerObstacleKeepsTheStatutoryDefault(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['statutory_evidence']['health']['month_evidence'] = null;
        $snapshot['people'][0]['employments'][0]['absences'] = [
            self::obstacleAbsence(42, 'other_employer_obstacle', 10_000),
        ];

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNotNull($bundle->healthInsurance);
        self::assertSame(
            HealthMinimumTopUpResponsibilitySource::StatutoryDefault,
            $bundle->healthInsurance->people[0]->topUpResponsibilitySource,
        );
    }

    /** Prostoj a neplacené volno v jednom měsíci: rozhodne účetní, ne odhad. */
    public function testEmployerObstacleMixedWithUnpaidLeaveLeavesTheResponsibilityOpen(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['statutory_evidence']['health']['month_evidence'] = null;
        $snapshot['people'][0]['employments'][0]['absences'] = [
            self::obstacleAbsence(43, 'weather_interruption', 6_000),
            [
                'id' => 44,
                'absence_type' => 'unpaid_leave',
                'date_from' => '2026-06-22',
                'date_to' => '2026-06-23',
            ],
        ];

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNotNull($bundle->healthInsurance);
        $person = $bundle->healthInsurance->people[0];
        self::assertSame(HealthMinimumTopUpResponsibility::Unverified, $person->topUpResponsibility);
        self::assertSame(HealthMinimumTopUpResponsibilitySource::DerivedMixedCauses, $person->topUpResponsibilitySource);
        self::assertSame([], $bundle->issues, 'Bez doplatku se na nic neptá.');
    }

    /** Prohlášení v měsíční evidenci má vždy přednost před odvozením. */
    public function testDeclaredResponsibilityWinsOverTheObstacleDerivation(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['employments'][0]['absences'] = [
            self::obstacleAbsence(45, 'downtime', 8_000),
        ];

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNotNull($bundle->healthInsurance);
        self::assertSame(
            HealthMinimumTopUpResponsibilitySource::Declared,
            $bundle->healthInsurance->people[0]->topUpResponsibilitySource,
        );
    }

    /** @return array<string,mixed> */
    private static function obstacleAbsence(int $id, string $kind, int $rate): array
    {
        return [
            'id' => $id,
            'absence_type' => 'employer_obstacle',
            'obstacle_kind' => $kind,
            'compensation_rate_basis_points' => $rate,
            'date_from' => '2026-06-15',
            'date_to' => '2026-06-16',
        ];
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
     * Sloupec `other_withholding_eligibility` („prohlášení plátce o účasti na
     * nemocenském pojištění“, migrace 1403) do výpočtu daně nevstupuje.
     * § 6 odst. 4 písm. b) ZDP se na účast neptá, jen na úhrn příjmů od
     * plátce v měsíci pod rozhodnou částkou. Dřív sestavovač z tohoto sloupce
     * posílal `IneligibleVerified` a jednatel s odměnou 4 400 Kč skončil na
     * záloze, bez vyplnění pak v ručním posouzení.
     *
     * @param string|null $stored uložená hodnota sloupce, `null` = klíč chybí
     */
    #[DataProvider('storedPayerStatements')]
    public function testStoredPayerStatementDoesNotChangeTheWithholding(?string $stored): void
    {
        $snapshot = $this->directorSnapshot($stored ?? 'unverified', 440_000);
        if ($stored === null) {
            unset($snapshot['people'][0]['employments'][0]['term']
                ['other_withholding_eligibility']);
        }

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertSame([], $bundle->issues);
        $result = (new MonthlyEmploymentIncomeTaxCalculator(
            new PayrollRulesetProvider([
                CzechPayrollRulesets2026::provider()
                    ->forDate(PayrollRulesetDomain::IncomeTax, '2026-06-30'),
            ]),
        ))->calculate($bundle->incomeTax[0]);

        self::assertSame(TaxCalculationStatus::Calculated, $result->status, implode(',', $result->issues));
        self::assertSame(TaxRegime::Withholding, $result->relationships[0]->regime);
        self::assertSame(66_000, $result->withholdingTaxMinorUnits);
    }

    /** @return iterable<string,array{?string}> */
    public static function storedPayerStatements(): iterable
    {
        yield 'nezakládá účast' => ['eligible'];
        yield 'zakládá účast' => ['ineligible'];
        yield 'nevyplněno' => ['unverified'];
        yield 'snímek bez klíče' => [null];
    }

    /**
     * Jednatel s odměnou přesně 4 500 Kč bez podepsaného prohlášení: výpočet
     * doběhne bez ručního posouzení a daní se zálohou.
     */
    public function testDirectorAtDecisiveAmountCompletesTheStatutoryCalculation(): void
    {
        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble(
            $this->directorSnapshot('unverified'),
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
        // 4 500 Kč je sama rozhodná částka, test § 6 odst. 4 ZDP je ostrý
        // („nedosahující“) a daní se zálohou.
        self::assertSame(TaxRegime::Advance, $result->relationships[0]->regime);
        self::assertSame(450_000, $result->advanceTax?->taxableIncomeMinorUnits);
    }

    /**
     * Snapshot jednatele, který u plátce nepodepsal prohlášení k dani.
     *
     * @return array<string,mixed>
     */
    private function directorSnapshot(string $eligibility, int $amountMinor = 450_000): array
    {
        $snapshot = $this->completeSnapshot();
        $person = &$snapshot['people'][0];
        $person['statutory_evidence']['income_tax']['declaration']['status'] =
            'not-signed';
        $employment = &$person['employments'][0];
        $employment['employment']['relation_type'] = 'statutory_body';
        $employment['employment']['monthly_gross_minor'] = $amountMinor;
        $employment['term']['tax_declaration_signed'] = false;
        $employment['term']['other_withholding_eligibility'] = $eligibility;
        $employment['inputs'][0]['amount_minor'] = $amountMinor;
        unset($person, $employment);

        // Sleva na poplatníka se bez podepsaného prohlášení uplatnit nedá;
        // ponechaný nárok by shodil výpočet na `tax-credit-requires-signed-declaration`
        // a test by měřil něco jiného, než měřit má.
        $snapshot['people'][0]['statutory_evidence']['income_tax']['credit_claims'] = [];

        return $snapshot;
    }

    /**
     * § 3 odst. 9 písm. b) zák. 592/1992 (nemoc, karanténa, ošetřování) a
     * § 7 odst. 1 písm. d) zák. 48/1997 (PPM, rodičovská — platí stát):
     * schválená nepřítomnost snižuje minimum zdravotního pojištění sama.
     * Dřív šlo snížení jen z ruční evidence, ke které nevedla žádná cesta,
     * takže měsíc s nemocí dorovnával pojistné do plného minima.
     */
    public function testApprovedSicknessAndMaternityReduceTheHealthMinimum(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['employments'][0]['absences'] = [
            ['id' => 501, 'absence_type' => 'dpn', 'date_from' => '2026-05-28', 'date_to' => '2026-06-12'],
            ['id' => 502, 'absence_type' => 'ppm', 'date_from' => '2026-06-20', 'date_to' => '2026-12-31'],
            ['id' => 503, 'absence_type' => 'unpaid_leave', 'date_from' => '2026-06-15', 'date_to' => '2026-06-16'],
        ];

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNotNull($bundle->healthInsurance);
        $reductions = array_map(
            static fn ($reduction): array => [
                $reduction->from,
                $reduction->to,
                $reduction->reason,
                $reduction->evidenceReference,
            ],
            $bundle->healthInsurance->people[0]->minimumReductions,
        );
        // Interval se ořízne na měsíc; neplacené volno minimum nesnižuje.
        self::assertContains(
            ['2026-06-01', '2026-06-12', HealthMinimumReductionReason::SicknessCareOrQuarantine, 'absence:501'],
            $reductions,
        );
        self::assertContains(
            ['2026-06-20', '2026-06-30', HealthMinimumReductionReason::StateInsured, 'absence:502'],
            $reductions,
        );
        self::assertCount(2, $reductions);
    }

    /**
     * Ověřená sleva pracujícího důchodce (§ 7d z. 589/1992) dokládá pobírání
     * starobního důchodu → za osobu platí i stát (§ 7 odst. 1 písm. b)
     * z. 48/1997) → minimum ZP se nepoužije (§ 3 odst. 8 písm. d) z. 592/1992).
     * Ověřená sleva na dani pro ZTP/P dokládá průkaz ZTP/P (§ 3 odst. 8 písm. a)).
     * Bez odvození dorovnávala mzda důchodci pojistné do minima, které nedluží.
     */
    public function testVerifiedPensionerDiscountAndZtpPCreditExemptFromTheHealthMinimum(): void
    {
        $snapshot = $this->completeSnapshot();
        $evidence = &$snapshot['people'][0]['statutory_evidence'];
        $evidence['social']['working_pensioner_discount']['status'] = 'verified';
        $evidence['social']['working_pensioner_discount']['evidence_reference'] = 'pension:award-decision';
        $evidence['income_tax']['credit_claims'][] = [
            'id' => 8,
            'effective_from' => '2026-06-01',
            'effective_to' => null,
            'row_version' => 1,
            'credit_kind' => 'ztp-p',
            'evidence_status' => 'verified',
            'evidence_reference' => 'credit:ztp-p-card',
        ];
        unset($evidence);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNotNull($bundle->healthInsurance);
        $reductions = array_map(
            static fn ($reduction): array => [
                $reduction->from,
                $reduction->to,
                $reduction->reason,
                $reduction->evidenceReference,
            ],
            $bundle->healthInsurance->people[0]->minimumReductions,
        );
        self::assertSame([
            ['2026-06-01', '2026-06-30', HealthMinimumReductionReason::StateInsured, 'social_discount_claim:7'],
            ['2026-06-01', '2026-06-30', HealthMinimumReductionReason::ZtpOrZtpP, 'tax_credit_claim:8'],
        ], $reductions);
    }

    /**
     * Neuplatněná sleva důchodce nic neříká o tom, jestli osoba důchod pobírá,
     * a sleva na invaliditu se přiznává i bez nároku na invalidní důchod
     * (§ 35ba odst. 1 písm. c) a d) ZDP). Ani jedno státního pojištěnce nedokládá.
     */
    public function testUnclaimedPensionerDiscountAndDisabilityCreditDoNotExempt(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['statutory_evidence']['income_tax']['credit_claims'][] = [
            'id' => 9,
            'effective_from' => '2026-06-01',
            'effective_to' => null,
            'row_version' => 1,
            'credit_kind' => 'disability-extended',
            'evidence_status' => 'verified',
            'evidence_reference' => 'credit:disability-pension-decision',
        ];

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertNotNull($bundle->healthInsurance);
        self::assertSame([], $bundle->healthInsurance->people[0]->minimumReductions);
    }

    /**
     * Celý měsíc neplaceného volna nemá co zadat do vstupů. Výpočet ho proto
     * pustí s prázdným seznamem složek místo blokace „chybí mzdová složka“.
     */
    public function testMonthWithoutInputsExplainedByAbsenceIsCalculated(): void
    {
        $snapshot = $this->completeSnapshot();
        $employment = &$snapshot['people'][0]['employments'][0];
        $employment['inputs'] = [];
        $employment['absences'] = [
            ['id' => 510, 'absence_type' => 'unpaid_leave', 'date_from' => '2026-06-01', 'date_to' => '2026-06-30'],
        ];
        unset($employment);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertSame([], array_map(
            static fn ($issue): string => "{$issue->domain}|{$issue->code}",
            $bundle->issues,
        ));
        self::assertNotNull($bundle->socialInsurance);
        self::assertSame([], $bundle->socialInsurance->people[0]->relationships[0]->components);
        self::assertNotNull($bundle->healthInsurance);
        self::assertSame([], $bundle->healthInsurance->people[0]->relationships[0]->components);
    }

    /** Bez nepřítomnosti, která by to vysvětlila, je chybějící vstup dál zapomenutá mzda. */
    public function testMonthWithoutInputsAndWithoutAbsenceStillBlocks(): void
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['employments'][0]['inputs'] = [];
        $snapshot['people'][0]['employments'][0]['absences'] = [
            ['id' => 511, 'absence_type' => 'vacation', 'date_from' => '2026-06-01', 'date_to' => '2026-06-05'],
        ];

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        self::assertContains(
            'social_insurance|payroll_component_missing',
            array_map(
                static fn ($issue): string => "{$issue->domain}|{$issue->code}",
                $bundle->issues,
            ),
        );
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
            'risky_savings_ruleset' => PayrollRiskySavingsRules::fromProvider(
                CzechPayrollRulesets2026::provider(),
                '2026-06-01',
            )->toSnapshot(),
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
    /**
     * Storno náhrady při DPN nesmí shodit běh do ručního posouzení.
     *
     * Náhrada je osvobozená od daně a tím pádem mimo vyměřovací základ
     * sociálního i zdravotního pojistného, takže do žádné ze tří domén
     * nevstupuje. Kontrola nezápornosti přesto běžela před filtrem podle
     * zacházení a shodila všechny tři — období pak šlo odblokovat jen ručním
     * SQL.
     */
    public function testNegativeAmountOfAnExemptComponentDoesNotBlockAnyDomain(): void
    {
        $snapshot = $this->completeSnapshot();
        $person = &$snapshot['people'][0];
        $input = &$person['employments'][0]['inputs'][0];
        $input['amount_minor'] = -50_000;
        $input['component']['tax_treatment'] = 'exempt';
        $input['component']['social_treatment'] = 'excluded';
        $input['component']['health_treatment'] = 'excluded';
        unset($input, $person);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        $codes = array_map(static fn ($issue): string => $issue->code, $bundle->issues);
        self::assertNotContains('negative_component_requires_revision', $codes);
    }

    /**
     * Zúžení guardu se nesmí přelít na složku, která do základu vstupuje:
     * záporný zákonný příplatek je věcný problém, ne falešný poplach.
     */
    public function testNegativeAmountOfAnIncludedComponentStillBlocks(): void
    {
        $snapshot = $this->completeSnapshot();
        $person = &$snapshot['people'][0];
        $input = &$person['employments'][0]['inputs'][0];
        $input['amount_minor'] = -50_000;
        $input['component']['tax_treatment'] = 'included';
        $input['component']['social_treatment'] = 'included';
        $input['component']['health_treatment'] = 'included';
        unset($input, $person);

        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble($snapshot);

        $keys = array_map(
            static fn ($issue): string => $issue->domain . '|' . $issue->code,
            $bundle->issues,
        );
        foreach (['social_insurance', 'health_insurance', 'income_tax'] as $domain) {
            self::assertContains($domain . '|negative_component_requires_revision', $keys);
        }
    }

    /**
     * Vrácená náhrada za dovolenou (§ 147 odst. 1 písm. e) ZP) je záporná
     * částka TOHOTO měsíce: snižuje hrubou mzdu, základ daně i pojistného.
     * Vyrovnání dovolené při skončení ji zakládá na NAHRADA_MZDY_DOVOLENA,
     * převod z PAMICA (J10) na NAHRADA_MZDY_DOVOLENA_VYROVNANI. Dřív shodila
     * všechny tři domény a běh nešel spočítat.
     */
    public function testReturnedVacationCompensationIsACurrentMonthNegative(): void
    {
        foreach (['NAHRADA_MZDY_DOVOLENA', 'NAHRADA_MZDY_DOVOLENA_VYROVNANI'] as $code) {
            $bundle = (new PayrollRunStatutoryInputAssembler())->assemble(
                $this->snapshotWithSecondInput($code, -343_100),
            );

            $codes = array_map(static fn ($issue): string => $issue->code, $bundle->issues);
            self::assertNotContains('negative_component_requires_revision', $codes, $code);
        }
    }

    /** Vrácení větší než všechno ostatní by dalo záporný základ — to je věc opravy. */
    public function testReturnedVacationCompensationBelowZeroTotalStillBlocks(): void
    {
        $bundle = (new PayrollRunStatutoryInputAssembler())->assemble(
            $this->snapshotWithSecondInput('NAHRADA_MZDY_DOVOLENA_VYROVNANI', -4_600_000),
        );

        $keys = array_map(
            static fn ($issue): string => $issue->domain . '|' . $issue->code,
            $bundle->issues,
        );
        foreach (['social_insurance', 'health_insurance', 'income_tax'] as $domain) {
            self::assertContains($domain . '|negative_component_requires_revision', $keys);
        }
    }

    /** @return array<string,mixed> */
    private function snapshotWithSecondInput(string $code, int $amountMinor): array
    {
        $snapshot = $this->completeSnapshot();
        $snapshot['people'][0]['employments'][0]['inputs'][] = [
            'id' => 421,
            'amount_minor' => $amountMinor,
            'source_period_start' => null,
            'component' => [
                'code' => $code,
                'tax_treatment' => 'included',
                'social_participation_treatment' => 'included',
                'social_treatment' => 'included',
                'health_participation_treatment' => 'included',
                'health_treatment' => 'included',
            ],
        ];

        return $snapshot;
    }
}
