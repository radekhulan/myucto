<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunValidation;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Provize a víkendový příplatek s haléři z importu docházky: měsíční hlášení
 * je odmítlo až po schválení mzdy. Kontrola běhu je teď hlásí před schválením
 * a hromadná oprava je zaokrouhlí nahoru týmiž cestami jako ruční úprava.
 */
#[Group('integration')]
final class PayrollInputWholeCrownsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-05-01';

    private Connection $db;
    private PayrollRunSnapshotBuilder $builder;
    private PayrollInputRepository $inputs;
    private int $supplierId;
    private int $actorId;
    private int $officeId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->builder = $container->get(PayrollRunSnapshotBuilder::class);
        $this->inputs = $container->get(PayrollInputRepository::class);
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role, locale, is_active)
             VALUES (?, ?, "Synthetic whole crowns", "readonly", "cs", 1)'
        )->execute([
            'whole-crowns-' . bin2hex(random_bytes(4)) . '@invalid.example',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);
        $this->actorId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())'
        )->execute([$this->supplierId, $this->actorId]);
        $container->get(PayrollEmployerPolicyRepository::class)->create($this->supplierId, [
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 10,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used',
            'travel_expense_policy' => 'not_used',
            'automatic_posting_enabled' => true,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:whole-crowns-policy',
        ], $this->actorId);
        $pdo->prepare('INSERT INTO payroll_offices (supplier_id, code, name, is_active) VALUES (?, "WHCR", "Syntetická účtárna", 1)')
            ->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-01-01", "0012345678", "synthetic:whole-crowns")'
        )->execute([$this->supplierId, $this->officeId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testHalereInputsBlockRunAndBulkRoundingFixesThemUp(): void
    {
        $agency = $this->employment('Syntetická Brigádnice');
        $provision = $this->component('PROVIZE', 'Provize', 'included');
        $weekend = $this->component('VIKEND', 'Víkendový příplatek', 'included');
        $travel = $this->component('CESTA', 'Cestovní náhrada', 'excluded');
        $approvedId = $this->input($agency, $provision, 123_450, 'approved');
        $draftId = $this->input($agency, $weekend, 10_001, 'draft');
        $this->input($agency, $travel, 33_333, 'approved');
        $locked = $this->employment('Syntetický Uzamčený');
        $lockedId = $this->input($locked, $provision, 50_050, 'locked');

        $before = self::byCode($this->builder->build($this->supplierId, self::PERIOD, '2026-06-10')->validations);
        self::assertCount(2, $before);
        self::assertSame('blocker', $before[0]->severity);
        self::assertStringContainsString('Provize 1 234,50 Kč', $before[0]->message);
        self::assertStringNotContainsString('Cestovní náhrada', $before[0]->message);
        self::assertSame("/payroll/components?tab=inputs&period=2026-05&employment={$agency}", $before[0]->remediationPath);

        $result = $this->inputs->roundUpToWholeCrowns($this->supplierId, self::PERIOD, null, true, $this->actorId);

        self::assertSame(
            [[$approvedId, 123_450, 123_500], [$draftId, 10_001, 10_100]],
            array_map(static fn (array $row): array => [$row['id'], $row['from_minor'], $row['to_minor']], $result['rounded']),
        );
        self::assertSame([$lockedId], array_column($result['skipped'], 'id'));
        self::assertSame(['approved', 'draft', 'locked'], [
            $this->inputStatus($approvedId),
            $this->inputStatus($draftId),
            $this->inputStatus($lockedId),
        ]);

        $after = self::byCode($this->builder->build($this->supplierId, self::PERIOD, '2026-06-10')->validations);
        self::assertSame([$locked], array_map(static fn (PayrollRunValidation $v): ?int => $v->entityId, $after));
    }

    public function testApprovedInputsNeedApprovalRight(): void
    {
        $agency = $this->employment('Syntetická Brigádnice');
        $provision = $this->component('PROVIZE', 'Provize', 'included');
        $approvedId = $this->input($agency, $provision, 123_450, 'approved');

        $result = $this->inputs->roundUpToWholeCrowns($this->supplierId, self::PERIOD, $agency, false, $this->actorId);

        self::assertSame([], $result['rounded']);
        self::assertSame([$approvedId], array_column($result['skipped'], 'id'));
        self::assertSame(123_450, (int) $this->db->pdo()->query("SELECT amount_minor FROM payroll_inputs WHERE id = {$approvedId}")->fetchColumn());
    }

    /**
     * @param list<PayrollRunValidation> $validations
     * @return list<PayrollRunValidation>
     */
    private static function byCode(array $validations): array
    {
        return array_values(array_filter(
            $validations,
            static fn (PayrollRunValidation $v): bool => $v->code === 'inputs_not_whole_crowns',
        ));
    }

    private function inputStatus(int $inputId): string
    {
        return (string) $this->db->pdo()->query("SELECT status FROM payroll_inputs WHERE id = {$inputId}")->fetchColumn();
    }

    private function employment(string $name): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, ?, "employee", 1)')
            ->execute([$this->supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_employee_profiles (supplier_id, employee_id, profile_status) VALUES (?, ?, "ready")')
            ->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type, status, start_date, actual_start_date, is_primary)
             VALUES (?, ?, ?, "1", "dpp", "active", "2026-01-01", "2026-01-01", 1)'
        )->execute([$this->supplierId, $employeeId, $this->officeId]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_events
                (supplier_id, employment_id, event_type, from_status, to_status, effective_on, note, diff_json, created_by)
             VALUES (?, ?, "created", NULL, "active", "2026-01-01", NULL, "{}", ?)'
        )->execute([$this->supplierId, $employmentId, $this->actorId]);
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from, planned_start_on, actual_start_on,
                 weekly_hours, workload_basis_points, social_insurance_participation,
                 health_insurance_participation, tax_regime, tax_declaration_signed, is_primary)
             VALUES (?, ?, ?, "2026-01-01", "2026-01-01", "2026-01-01", 40, 10000, "automatic", "automatic", "advance", 1, 1)'
        )->execute([$this->supplierId, $employmentId, $this->officeId]);

        return $employmentId;
    }

    /** @return array{id:int,json:string} */
    private function component(string $code, string $name, string $jmhzTreatment): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind, frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment, health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment, jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, ?, ?, "bonus", "monetary", "one_off", "included",
                     "included", "included", "included", "included", "included", "included", ?,
                     "included", "521", "331", "2026-01-01")'
        )->execute([$this->supplierId, $code, $name, $jmhzTreatment]);
        $componentId = (int) $pdo->lastInsertId();

        return ['id' => $componentId, 'json' => CanonicalJson::encode([
            'code' => $code,
            'name' => $name,
            'component_kind' => 'bonus',
            'value_kind' => 'monetary',
            'frequency_kind' => 'one_off',
            'tax_treatment' => 'included',
            'social_participation_treatment' => 'included',
            'social_treatment' => 'included',
            'health_participation_treatment' => 'included',
            'health_treatment' => 'included',
            'average_earning_treatment' => 'included',
            'enforcement_treatment' => 'included',
            'jmhz_treatment' => $jmhzTreatment,
            'statistics_treatment' => 'included',
            'accounting_debit_code' => '521',
            'accounting_credit_code' => '331',
            'annual_limit_minor' => null,
            'component_id' => $componentId,
            'component_row_version' => 1,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ])];
    }

    /** @param array{id:int,json:string} $component */
    private function input(int $employmentId, array $component, int $amountMinor, string $status): int
    {
        $pdo = $this->db->pdo();
        $employeeId = (int) $pdo->query('SELECT employee_id FROM payroll_employments WHERE id = ' . $employmentId)->fetchColumn();
        $draft = $status === 'draft';
        $pdo->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id, period_start, amount_minor, source_kind, status,
                 component_snapshot_json, component_snapshot_hash, approved_by, approved_at)
             VALUES (?, ?, ?, ?, ?, ?, "manual", ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId, $employeeId, $employmentId, $component['id'], self::PERIOD, $amountMinor, $status,
            $draft ? null : $component['json'],
            $draft ? null : hash('sha256', $component['json'], true),
            $draft ? null : $this->actorId,
            $draft ? null : '2026-05-28 12:00:00',
        ]);

        return (int) $pdo->lastInsertId();
    }
}
