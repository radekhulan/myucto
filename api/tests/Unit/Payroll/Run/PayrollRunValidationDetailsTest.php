<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Service\Payroll\Run\PayrollRunIssueGuidance;
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
        self::assertSame('/payroll/people?person=7&employment=9&panel=employment_terms&field=other_withholding_eligibility', $rows[1]['remediation_path']);
        self::assertStringContainsString('účast na nemocenském pojištění', $rows[1]['message']);
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

    private function rows(): array
    {
        return $this->pdo->query('SELECT * FROM payroll_run_validations')->fetchAll(PDO::FETCH_ASSOC);
    }
}
