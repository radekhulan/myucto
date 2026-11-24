<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Service\Payroll\Run\PayrollRunRecurringBaseWage;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pravidelná základní mzda pevnou částkou jiná než sjednaná měsíční mzda.
 *
 * Měsíční mzdu 44 200 Kč z podmínek vztahu přebil předpis 35 500 Kč (částka
 * jiného zaměstnance) a běh ji vyplatil bez jediné zmínky. Kontrola před
 * během i validace běhu teď upozorní; krácení, nástup v průběhu měsíce ani
 * změna podmínek v měsíci upozornění nevyvolají.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollRunRecurringBaseWageCheckTest extends TestCase
{
    use PayrollFullFlowTrait;

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

    public function testRecurringBaseWageDifferentFromAgreedWageWarnsBeforeAndInRun(): void
    {
        $officeId = $this->createOffice('RBW', 'Syntetická účtárna', '1100001237');
        $componentId = $this->createComponent('MZDA_MESICNI_RBW', 'base_wage', 'regular');
        $mistaken = $this->createEmployment($officeId, 'Syntetický Přepsaný', 1, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        $matching = $this->createEmployment($officeId, 'Syntetická Shodná', 2, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        $changed = $this->createEmployment($officeId, 'Syntetický Zvýšený', 3, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        $joined = $this->createEmployment($officeId, 'Syntetická Nastoupivší', 4, 'hpp', 'employment', 40, 10_000, true, self::PERIOD_START);
        foreach ([$mistaken, $matching, $joined] as $person) {
            $this->agreedWage($person['employment_id'], 4_420_000);
        }
        // Zvýšení mzdy od poloviny měsíce: předpis drží ještě starou částku.
        $this->agreedWage($changed['employment_id'], 4_000_000);
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET effective_to = "2026-07-15" WHERE supplier_id = ? AND employment_id = ?'
        )->execute([$this->supplierId, $changed['employment_id']]);
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from, planned_start_on, actual_start_on,
                 weekly_hours, workload_basis_points, social_insurance_participation,
                 health_insurance_participation, tax_regime, tax_declaration_signed, is_primary, monthly_gross_minor)
             VALUES (?, ?, ?, "2026-07-16", "2026-01-01", "2026-01-01", 40, 10000, "automatic", "automatic", "advance", 1, 1, 4800000)'
        )->execute([$this->supplierId, $changed['employment_id'], $officeId]);
        // Nástup v průběhu měsíce krátí vstup, předpis zůstává celou mzdou.
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET start_date = "2026-07-10", actual_start_date = "2026-07-10" WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $joined['employment_id']]);

        $this->recurring($mistaken['employment_id'], $componentId, 3_550_000);
        $this->recurring($matching['employment_id'], $componentId, 4_420_000);
        $this->recurring($changed['employment_id'], $componentId, 4_000_000);
        $this->recurring($joined['employment_id'], $componentId, 4_420_000, 'calendar_days');

        $snapshot = $this->container->get(PayrollRunSnapshotBuilder::class)
            ->build($this->supplierId, self::PERIOD_START, self::PAYDAY);
        $warnings = array_values(array_filter(
            $snapshot->validations,
            static fn ($validation): bool => $validation->code === PayrollRunRecurringBaseWage::CODE,
        ));
        self::assertCount(1, $warnings, CanonicalJson::encode(array_map(static fn ($v): array => [$v->code, $v->entityId, $v->message], $warnings)));
        self::assertSame('warning', $warnings[0]->severity);
        self::assertFalse($warnings[0]->requiresOverride);
        self::assertSame($mistaken['employment_id'], $warnings[0]->entityId);
        self::assertStringContainsString('35 500 Kč', $warnings[0]->message);
        self::assertStringContainsString('44 200 Kč', $warnings[0]->message);
        self::assertSame(
            '/payroll/components?tab=recurring&period=2026-07&employment=' . $mistaken['employment_id'],
            $warnings[0]->remediationPath,
        );

        $finding = array_column(
            $this->container->get(PayrollRunReadinessService::class)
                ->inspect($this->supplierId, self::PERIOD_START, self::PAYDAY)['findings'],
            null,
            'code',
        )[PayrollRunRecurringBaseWage::CODE] ?? null;
        self::assertNotNull($finding);
        self::assertSame('warning', $finding['severity']);
        self::assertSame('revision', $finding['impact']);
        self::assertSame([$mistaken['employment_id']], array_column($finding['entities'], 'entity_id'));
    }

    private function agreedWage(int $employmentId, int $amountMinor): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET monthly_gross_minor = ? WHERE supplier_id = ? AND employment_id = ?'
        )->execute([$amountMinor, $this->supplierId, $employmentId]);
    }

    private function recurring(int $employmentId, int $componentId, int $amountMinor, string $allocation = 'full_month'): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_recurring_components
                (supplier_id, employment_id, component_id, calculation_kind, amount_minor,
                 valid_from, allocation_rule, is_active)
             VALUES (?, ?, ?, "fixed_amount", ?, "2026-01-01", ?, 1)'
        )->execute([$this->supplierId, $employmentId, $componentId, $amountMinor, $allocation]);
    }
}
