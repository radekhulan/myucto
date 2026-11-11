<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollSurchargePolicyHistoryLockedException;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollEmploymentSurchargePolicyService;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollE2eFix8Test extends TestCase
{
    use PayrollFullFlowTrait;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testFrozenPolicyCannotBeRewrittenButFutureVersionCanBeCreated(): void
    {
        $officeId = $this->createOffice();
        $person = $this->createEmployment($officeId, 'Syntetická Zásada', 1, 'hpp', 'employment', 40, 10000);
        $componentId = $this->createComponent('FIX8_MZDA', 'base_wage', 'one_off');
        $this->createApprovedInput($person, $componentId, 4_000_000, 'fix8-wage');
        $service = $this->container->get(PayrollEmploymentSurchargePolicyService::class);
        $data = ['valid_from' => '2026-06-01', 'overtime_mode' => 'surcharge', 'holiday_mode' => 'surcharge'];
        $policy = $service->save($this->supplierId, $person['employment_id'], $data, $this->actors[0]);
        $run = $this->runs->createRun($this->supplierId, '2026-06-01', '2026-07-14', $officeId, $this->actors[0]);
        $this->runs->lockInputs($this->supplierId, (int) $run['id'], (int) $run['row_version'], 'fix8-lock', $this->actors[0]);

        foreach (['2026-05-01', '2026-06-01', '2026-07-01'] as $from) {
            try {
                $service->update($this->supplierId, $person['employment_id'], (int) $policy['id'],
                    [...$data, 'valid_from' => $from, 'row_version' => 1, 'night_rate_bp' => 2000]);
                self::fail('Zmrazená zásada se nesmí přepsat.');
            } catch (PayrollSurchargePolicyHistoryLockedException) {
                self::assertTrue(true);
            }
        }
        $next = $service->save($this->supplierId, $person['employment_id'], [...$data, 'valid_from' => '2026-07-01'], $this->actors[0]);
        self::assertSame('2026-07-01', $next['valid_from']);
    }

    public function testReadinessReportsManualPremiumsBeforeHourlyCalculation(): void
    {
        $officeId = $this->createOffice();
        $person = $this->createEmployment($officeId, 'Syntetický Hodinář', 1, 'hpp', 'employment', 40, 10000);
        foreach (['PRIPLATEK_NOCNI', 'PRIPLATEK_VIKEND'] as $code) {
            $componentId = $this->createComponent($code, 'premium', 'one_off');
            $this->createApprovedInput($person, $componentId, 10_000, 'fix8-' . $code);
        }
        $this->db->pdo()->prepare('UPDATE payroll_employment_terms
            SET remuneration_type = "hourly", hourly_rate_minor = 25000, monthly_gross_minor = NULL,
                remuneration_basis_kind = "contract", remuneration_basis = "Syntetická smlouva"
            WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplierId, $person['employment_id']]);
        $readiness = $this->container->get(PayrollRunReadinessService::class);
        $result = $readiness->inspect($this->supplierId, '2026-06-01', '2026-07-14');
        $findings = array_column($result['findings'], null, 'code');
        self::assertArrayHasKey('hourly_claim_component_conflict', $findings);
        $finding = $findings['hourly_claim_component_conflict'];
        self::assertSame('blocker', $finding['severity']);
        self::assertSame(1, $finding['entity_total']);
        self::assertStringContainsString($person['name'], $finding['entities'][0]['label']);
        self::assertStringContainsString('PRIPLATEK_NOCNI', $finding['entities'][0]['message']);
        self::assertStringContainsString('PRIPLATEK_VIKEND', $finding['entities'][0]['message']);
        self::assertSame('/payroll/quick-inputs?period=2026-06&employment=' . $person['employment_id'], $finding['entities'][0]['remediation_path']);
    }
}
