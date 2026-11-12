<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculationPipeline;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola před během: nález o osobách, které zákonný výpočet zastaví, nese
 * jedno číslo (počet osob) v nadpisu, ve větě i na tlačítkách hromadné opravy.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollRunReadinessStatutoryCountTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PAYDAY = '2026-08-14';

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testMissingPayoutRuleWarnsBeforeRunOnlyForPeopleWhoNeedIt(): void
    {
        $officeId = $this->createOffice('PAY', 'Syntetická účtárna', '1100001237');
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureHealthInsuranceOutput();
        $componentId = $this->createComponent('MZDA_MESICNI_PAY', 'base_wage', 'regular');
        $missing = $this->createEmployment($officeId, 'Syntetická Bez pravidla', 1, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        $zero = $this->createEmployment($officeId, 'Syntetická Nulová', 2, 'dpp', 'dpp', 10, 2_500, false, self::PERIOD_START);
        $cash = $this->createEmployment($officeId, 'Syntetická Hotovost', 3, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        $overdrawn = $this->createEmployment($officeId, 'Syntetická Záporná', 4, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START, healthTopUpResponsibility: 'employee');
        $workdays = self::workdays(self::PERIOD);
        foreach ([$missing, $zero, $cash, $overdrawn] as $person) {
            $this->publishShifts($person['employment_id'], $workdays);
            self::assertSame(200, $this->approveTimeMonth($person['employment_id'], self::PERIOD, $workdays)->getStatusCode());
            $this->createApprovedInput($person, $componentId, in_array($person, [$zero, $overdrawn], true) ? 0 : 4_500_000, 'pay-' . $person['employee_id'], self::PERIOD_START);
        }
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_payout_rules
                (supplier_id, employee_id, allocation_reference, destination_kind, allocation_kind)
             VALUES (?, ?, "synthetic-cash", "cash", "remainder")'
        )->execute([$this->supplierId, $cash['employee_id']]);

        $readiness = $this->container->get(PayrollRunReadinessService::class);
        $result = $readiness->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY);
        $finding = array_column($result['findings'], null, 'code')['payout_rule_missing'] ?? null;
        self::assertNotNull($finding);
        self::assertSame('warning', $finding['severity']);
        self::assertSame('revision', $finding['impact']);
        self::assertSame('setup', $finding['scope']);
        self::assertSame(1, $finding['count']);
        self::assertSame([$missing['employee_id']], array_column($finding['entities'], 'entity_id'));
        self::assertSame('Syntetická Bez pravidla', $finding['entities'][0]['label']);
        self::assertSame(
            "/payroll/people?person={$missing['employee_id']}&panel=accounts",
            $finding['entities'][0]['remediation_path'],
        );
        self::assertStringContainsString('přípravu plateb', $finding['message']);
        self::assertStringContainsString('opravnou revizi', $finding['message']);
        self::assertTrue($result['ready']);
        self::assertSame(
            [$missing['employee_id']],
            $readiness->findingEmployeeIds($this->supplierId, self::PERIOD_START, self::PAYDAY, 'payout_rule_missing'),
        );
        $snapshot = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build($this->supplierId, self::PERIOD_START, self::PAYDAY);
        $preview = $this->container->get(PayrollRunCalculationPipeline::class)
            ->calculate($snapshot->data, $this->supplierId, persist: false);
        self::assertSame('calculated', $preview['statutory']['status']);
        $people = array_column($preview['people'], null, 'employee_id');
        self::assertGreaterThan(0, $people[$missing['employee_id']]['payable_after_enforcement_minor']);
        self::assertSame(0, $people[$zero['employee_id']]['payable_after_enforcement_minor']);
        self::assertLessThan(0, $people[$overdrawn['employee_id']]['payable_after_enforcement_minor']);
        foreach (['payroll_runs', 'payroll_run_revisions', 'payroll_statutory_results', 'payroll_payment_liabilities'] as $table) {
            $statement = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
            $statement->execute([$this->supplierId]);
            self::assertSame(0, (int) $statement->fetchColumn(), $table);
        }
    }

    /**
     * Nález „zákonný výpočet zastaví“: nadpis, věta i tlačítka hromadné opravy
     * nesou JEDNO číslo, počet osob. Osoba se dvěma chybějícími údaji se dřív
     * počítala v nadpisu dvakrát („2×“), kdežto věta mluvila o jedné osobě.
     */
    public function testStatutoryManualReviewCountsPeopleNotRows(): void
    {
        $officeId = $this->createOffice('CNT', 'Syntetická účtárna', '1100001237');
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureHealthInsuranceOutput();
        $componentId = $this->createComponent('MZDA_MESICNI_CNT', 'base_wage', 'regular');
        $person = $this->createEmployment($officeId, 'Syntetická Dvojí', 1, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        $workdays = self::workdays(self::PERIOD);
        $this->publishShifts($person['employment_id'], $workdays);
        self::assertSame(200, $this->approveTimeMonth($person['employment_id'], self::PERIOD, $workdays)->getStatusCode());
        $this->createApprovedInput($person, $componentId, 4_500_000, 'cnt-base', self::PERIOD_START);
        foreach (['payroll_person_health_coverage_history', 'payroll_person_tax_residences'] as $table) {
            $this->db->pdo()->prepare("DELETE FROM {$table} WHERE supplier_id = ? AND employee_id = ?")
                ->execute([$this->supplierId, $person['employee_id']]);
        }

        $readiness = $this->container->get(PayrollRunReadinessService::class);
        self::assertInstanceOf(PayrollRunReadinessService::class, $readiness);
        $finding = array_column(
            $readiness->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY)['findings'],
            null,
            'code',
        )['statutory_calculation_manual_review'] ?? null;
        self::assertNotNull($finding);
        // Dva chybějící údaje téže osoby: jeden záznam s oběma větami
        // a odkazem na společné místo opravy.
        self::assertSame([$person['employee_id']], array_column($finding['entities'], 'entity_id'), CanonicalJson::encode($finding['entities']));
        self::assertStringContainsString('rezidenc', $finding['entities'][0]['message']);
        self::assertStringContainsString('zdravotní pojištění', $finding['entities'][0]['message']);
        self::assertSame(
            "/payroll/people?person={$person['employee_id']}&panel=statutory_evidence",
            $finding['entities'][0]['remediation_path'],
        );
        self::assertSame(1, $finding['count']);
        self::assertSame(1, $finding['entity_total']);
        self::assertStringContainsString('zastaví u 1 osoby chybějící', $finding['message']);
        foreach ($finding['bulk_actions'] as $action) {
            self::assertSame(1, $action['count'], $action['kind']);
        }
    }

    /**
     * Vztah bez schválené složky hlásí samostatná skupina (vztah bez vstupů,
     * neschválené vstupy). Skupina zákonné evidence ho nesmí počítat podruhé:
     * kontrola u agentury hlásila „53×", ale výchozí údaje nabízela 31 osobám.
     */
    public function testStatutoryManualReviewSkipsPeopleMissingOnlyComponent(): void
    {
        $officeId = $this->createOffice('CMP', 'Syntetická účtárna', '1100001237');
        $this->configureSocialInsuranceOutput($officeId);
        $this->configureHealthInsuranceOutput();
        $componentId = $this->createComponent('MZDA_MESICNI_CMP', 'base_wage', 'regular');
        $statutory = $this->createEmployment($officeId, 'Syntetická Bez rezidence', 1, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        $withoutInput = $this->createEmployment($officeId, 'Syntetická Bez složky', 2, 'dpp', 'dpp', 10, 2_500, false, self::PERIOD_START);
        $workdays = self::workdays(self::PERIOD);
        foreach ([$statutory, $withoutInput] as $person) {
            $this->publishShifts($person['employment_id'], $workdays);
            self::assertSame(200, $this->approveTimeMonth($person['employment_id'], self::PERIOD, $workdays)->getStatusCode());
        }
        $this->createApprovedInput($statutory, $componentId, 4_500_000, 'cmp-base', self::PERIOD_START);
        $this->db->pdo()->prepare('DELETE FROM payroll_person_tax_residences WHERE supplier_id = ? AND employee_id = ?')
            ->execute([$this->supplierId, $statutory['employee_id']]);

        $findings = array_column(
            $this->container->get(PayrollRunReadinessService::class)
                ->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY)['findings'],
            null,
            'code',
        );
        $finding = $findings['statutory_calculation_manual_review'] ?? null;
        self::assertNotNull($finding);
        self::assertSame([$statutory['employee_id']], array_column($finding['entities'], 'entity_id'), CanonicalJson::encode($finding['entities']));
        self::assertSame(1, $finding['count']);
        self::assertStringNotContainsString('Syntetická Bez složky', $finding['message']);
        self::assertArrayHasKey('employment_without_inputs', $findings);
        self::assertSame([$withoutInput['employment_id']], array_column($findings['employment_without_inputs']['entities'], 'entity_id'));
    }
}
