<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
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
}
