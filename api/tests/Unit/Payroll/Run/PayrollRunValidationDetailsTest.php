<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Service\Payroll\Run\PayrollRunIssueGuidance;
use MyInvoice\Service\Payroll\Run\PayrollRunValidationMessageFormatter;
use PDO;
use PHPUnit\Framework\TestCase;

final class PayrollRunValidationDetailsTest extends TestCase
{
    private PDO $pdo;
    private PayrollRunRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE payroll_run_validations (
            supplier_id INTEGER, revision_id INTEGER, severity TEXT, code TEXT,
            entity_type TEXT, entity_id INTEGER, message TEXT, remediation_path TEXT,
            requires_override INTEGER
        )');
        $connection = $this->createStub(Connection::class);
        $connection->method('pdo')->willReturn($this->pdo);
        $this->repository = new PayrollRunRepository($connection);
    }

    public function testStatutoryKeepsEachProblemAndItsPersonInsteadOfOneRunSummary(): void
    {
        $this->repository->replaceStatutoryValidations(1, 2, ['statutory' => [
            'status' => 'manual_review',
            'issues' => [
                'health_insurance:health_insurer_evidence_unverified:employee:7:employment:9',
                'income_tax:tax_child_evidence_unverified:employee:8',
            ],
        ]]);
        $rows = $this->rows();
        self::assertCount(2, $rows);
        self::assertSame([7, 8], array_column($rows, 'entity_id'));
        self::assertSame(['employee', 'employee'], array_column($rows, 'entity_type'));
        self::assertStringContainsString('zdravotní pojišťovna', $rows[0]['message']);
        self::assertSame('/payroll/people?person=7&employment=9&panel=statutory_evidence', $rows[0]['remediation_path']);
        self::assertStringContainsString('pořadí dítěte', $rows[1]['message']);
        self::assertSame('/payroll/people?person=8&panel=dependants', $rows[1]['remediation_path']);
    }

    public function testNestedInsuranceAndTaxReasonsAreNotLostWhenTopLevelIssuesAreEmpty(): void
    {
        $this->repository->replaceStatutoryValidations(1, 2, ['statutory' => [
            'status' => 'manual_review', 'issues' => [],
            'people' => [[
                'person_reference' => 'employee:7',
                'social_insurance' => ['issues' => ['working_pensioner_discount_unverified']],
                'income_tax' => ['issues' => [], 'relationships' => [[
                    'relationship_reference' => 'employment:9',
                    'issues' => ['other-withholding-eligibility-unverified'],
                ]]],
            ]],
        ]]);
        $rows = $this->rows();
        self::assertCount(2, $rows);
        self::assertStringContainsString('Sleva pracujícího důchodce', $rows[0]['message']);
        // Pole prohlášení plátce karta vztahu už nenabízí (srážku určuje výpočet
        // sám), proklik na něj by vedl do prázdna — kontrola ze staré revize
        // proto posílá jen na přepočet běhu.
        self::assertSame('/payroll/runs', $rows[1]['remediation_path']);
        self::assertStringNotContainsString('other_withholding_eligibility', $rows[1]['remediation_path']);
        self::assertStringContainsString('starší revize', $rows[1]['message']);
    }

    public function testEnforcementKeepsTheClaimAndDoesNotReplaceInsolvencyWithGenericList(): void
    {
        $this->repository->replaceEnforcementValidations(1, 2, ['people' => [[
            'employee_id' => 7,
            'enforcement' => ['result' => ['status' => 'manual_review', 'issues' => [
                'claim:12:delivery_date_missing', 'insolvency_recipient_not_verified',
                'dependants_evidence_incomplete',
            ]]],
        ]]]);
        $rows = $this->rows();
        self::assertCount(3, $rows);
        self::assertStringContainsString('Pohledávka č. 12', $rows[0]['message']);
        self::assertStringContainsString('datum doručení', $rows[0]['message']);
        self::assertSame('/payroll/insolvency?person=7', $rows[1]['remediation_path']);
        self::assertSame('/payroll/people?person=7&panel=dependants', $rows[2]['remediation_path']);
        self::assertSame([7, 7, 7], array_column($rows, 'entity_id'));
    }

    public function testUnknownCodeIsPreservedForDiagnosticsAndAsksForSupportWithoutGuessing(): void
    {
        $detail = PayrollRunIssueGuidance::describe('future_issue:employee:7');
        self::assertSame('future_issue:employee:7', $detail['issue_code']);
        self::assertSame(7, $detail['entity_id']);
        self::assertStringContainsString('Předejte správci', $detail['message']);
        self::assertStringNotContainsString('future_issue', $detail['message']);
    }

    public function testEveryInputAssemblerIssueHasAnExplicitActionableExplanation(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../../src/Service/Payroll/Run/PayrollRunStatutoryInputAssembler.php');
        preg_match_all('/\$this->issue\(\s*[^,]+,\s*\x27([^\x27]+)\x27/', $source, $matches);
        self::assertGreaterThan(40, count($matches[1]));
        foreach (array_unique($matches[1]) as $code) {
            $detail = PayrollRunIssueGuidance::describe($code);
            self::assertStringNotContainsString('kontrolu, kterou nelze automaticky', $detail['message'], $code);
            self::assertLessThanOrEqual(500, mb_strlen($detail['message']), $code);
        }
    }

    public function testCalculatorSpecificAndParameterizedReasonsHaveGuidance(): void
    {
        foreach ([
            'relationship:employment:9:part_time_discount_worked_hours_missing',
            'relationship:employment:9:employer_rate_category_unverified',
            'participation_component_manual_review:SYNTHETIC_COMPONENT',
            'minimum_reduction_requires_whole_month:state_insured',
            'other_employer_base_outside_calculation_month:2026-07',
            'dpp_group_contains_unresolved_relationship',
            'dpc_group_negative_income_requires_period_revision',
            'income:income:12:severance_period_split_required',
            'claim:12:duplicate_id',
            'concurrent_priority_enforcement_with_insolvency_requires_manual_review',
            'enforcement_ruleset_incomplete',
            'risky_savings_pension_company_missing',
            'risky_savings_product_reference_missing',
            'tax-child-concurrent-claim-unresolved',
            'tax-child-order-gap',
            'tax-child-requires-signed-declaration',
            'nonresident-monthly-credit-not-supported',
        ] as $issue) {
            $detail = PayrollRunIssueGuidance::describe($issue, 7);
            self::assertSame($issue, $detail['issue_code']);
            self::assertStringNotContainsString('kontrolu, kterou nelze automaticky', $detail['message'], $issue);
            self::assertNotNull($detail['remediation_path'], $issue);
            self::assertLessThanOrEqual(500, mb_strlen($detail['message']), $issue);
        }
    }

    public function testPostTerminationIncomeLeadsToDeferredIncomeOnEmploymentCard(): void
    {
        $detail = PayrollRunIssueGuidance::describe('post_termination_income_attribution_unverified', 7, 9);

        self::assertSame('/payroll/people?person=7&employment=9&panel=deferred_income', $detail['remediation_path']);
        self::assertStringContainsString('Odložený příjem', $detail['message']);
    }

    /**
     * Lednový běh nového roku bez sady exekučních srážek (nařízení vlády
     * o nezabavitelných částkách ještě nevyšlo) hlásil u každé osoby dva
     * blokující řádky. Jeden tvrdil obecně „není sada pravidel", přestože
     * Pravidla mezd ukazují rok jako pokrytý, druhý radil ověřit datum výplaty.
     */
    public function testMissingEnforcementRulesetIsOneClearRowPerPerson(): void
    {
        $this->repository->replaceEnforcementValidations(1, 2, ['people' => [[
            'employee_id' => 7,
            'enforcement' => ['result' => ['status' => 'manual_review', 'issues' => [
                'enforcement_ruleset_not_effective_for_whole_year',
                'payment_date_outside_ruleset_2026',
            ]]],
        ]]]);

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertStringContainsString('exekučních srážek', $rows[0]['message']);
        self::assertStringContainsString('Exekuční srážky', $rows[0]['message']);
        self::assertStringNotContainsString('Ověřte období a datum výplaty', $rows[0]['message']);
        self::assertSame('/payroll/rulesets', $rows[0]['remediation_path']);
    }

    /**
     * Počáteční roční úhrny se zadávají na kartě hlavního pracovního vztahu
     * (Počáteční stavy). Proklik do zákonné evidence vedl na sekci, kde nic
     * takového není a která navíc hlásila „Evidence je úplná".
     */
    public function testMissingAnnualAccumulatorLeadsToOpeningBalancesOnEmploymentCard(): void
    {
        foreach (['income_tax:annual_accumulator_missing:employee:7', 'social_insurance:annual_accumulator_invalid:employee:7'] as $issue) {
            $detail = PayrollRunIssueGuidance::describe($issue);

            self::assertSame('/payroll/people?person=7&panel=opening_balances', $detail['remediation_path'], $issue);
            self::assertStringContainsString('Počáteční stavy', $detail['message'], $issue);
            self::assertStringNotContainsString('V zákonné evidenci', $detail['message'], $issue);
        }
    }

    /**
     * Karta běhu nabízí hromadné „Doplnit výchozí údaje" jen u nálezů, které
     * doplnění opravdu vyřeší. Pozná je podle sekce v prokliku; nález, který
     * výchozí údaje nedoplní (zdravotní pojištění), sekci pro doplnění nenese.
     */
    public function testStatutoryEvidenceLinkNamesTheSectionWhichDefaultsCanFill(): void
    {
        $expected = [
            'social_insurance:social_jurisdiction_evidence_missing:employee:7' => 'statutory.social_jurisdictions',
            'income_tax:tax_residence_evidence_missing:employee:7' => 'statutory.tax_residences',
            'income_tax:tax_declaration_evidence_missing:employee:7' => 'statutory.tax_declarations',
            'social_insurance:working_pensioner_discount_evidence_unverified:employee:7' => 'statutory.social_discount_claims',
        ];
        foreach ($expected as $issue => $field) {
            self::assertSame(
                '/payroll/people?' . http_build_query(['person' => 7, 'panel' => 'statutory_evidence', 'field' => $field]),
                PayrollRunIssueGuidance::describe($issue)['remediation_path'],
                $issue,
            );
        }

        self::assertSame(
            '/payroll/people?person=7&panel=statutory_evidence',
            PayrollRunIssueGuidance::describe('health_insurance:health_coverage_evidence_missing:employee:7')['remediation_path'],
        );
    }

    /**
     * Assembler hlásí tentýž problém vztahu v každé ze tří domén. Uložené
     * řádky se lišily jen neuloženým prefixem domény — účetní viděla tři
     * stejné blokátory.
     */
    public function testSameProblemReportedByEveryDomainIsOneRowNotThree(): void
    {
        $this->repository->replaceStatutoryValidations(1, 2, ['statutory' => [
            'status' => 'manual_review',
            'issues' => [
                'health_insurance:employment_term_missing:employee:7:employment:9',
                'income_tax:employment_term_missing:employee:7:employment:9',
                'social_insurance:employment_term_missing:employee:7:employment:9',
            ],
        ]]);

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(7, (int) $rows[0]['entity_id']);
    }

    /**
     * „Chybí čistá mzda" u osoby bez jakékoli srážky je jen ozvěna zákonné
     * validace. Osoba s pohledávkou, insolvencí nebo dohodou řádek dostane
     * dál, stejně jako osoba se spočítaným zákonným výsledkem.
     */
    public function testNetPayEchoIsSkippedOnlyForPeopleWithoutAnyDeduction(): void
    {
        $person = static fn (int $id, string $statutoryStatus, array $input): array => [
            'employee_id' => $id,
            'statutory' => ['status' => $statutoryStatus],
            'enforcement' => [
                'input' => $input,
                'result' => [
                    'status' => 'manual_review',
                    'issues' => [PayrollRunValidationMessageFormatter::NET_PAY_ISSUE],
                ],
            ],
        ];
        $nothing = ['claims' => [], 'insolvency' => ['mode' => 'none']];

        $this->repository->replaceEnforcementValidations(1, 2, ['people' => [
            $person(7, 'manual_review', $nothing),
            $person(8, 'manual_review', ['claims' => [['id' => 'claim:12']]] + $nothing),
            $person(9, 'manual_review', ['claims' => [], 'insolvency' => ['mode' => 'approved_standard']]),
            $person(10, 'manual_review', $nothing + ['voluntary_agreements' => [['id' => 'agreement:3']]]),
            $person(11, 'calculated', $nothing),
        ]]);

        self::assertSame(
            [8, 9, 10, 11],
            array_map('intval', array_column($this->rows(), 'entity_id')),
        );
    }

    private function rows(): array
    {
        return $this->pdo->query('SELECT * FROM payroll_run_validations')->fetchAll(PDO::FETCH_ASSOC);
    }
}
