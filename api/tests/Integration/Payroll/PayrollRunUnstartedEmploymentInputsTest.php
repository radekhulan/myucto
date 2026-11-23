<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunValidation;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola mzdového běhu nad vstupy, které do běhu nevstoupí.
 *
 * Brigádnice zadaná ručně před potvrzením nástupu měla vztah se založením
 * až z října a únorové vstupy. Vztah do únorového běhu nepatřil, běh o něm
 * mlčel a vstupy se tiše nevyplatily. Kontrola teď hlásí blokátor se jménem.
 *
 * Zároveň hlídá, že neschválený vstup nehlásí kromě `draft_inputs_present`
 * ještě varování „bez mzdových vstupů".
 */
#[Group('integration')]
final class PayrollRunUnstartedEmploymentInputsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRunSnapshotBuilder $builder;
    private PayrollRunReadinessService $readiness;
    private int $supplierId;
    private int $actorId;
    private int $officeId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->builder = $container->get(PayrollRunSnapshotBuilder::class);
        $this->readiness = $container->get(PayrollRunReadinessService::class);
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
             VALUES (?, ?, "Synthetic unstarted", "readonly", "cs", 1)'
        )->execute([
            'unstarted-' . bin2hex(random_bytes(4)) . '@invalid.example',
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
            'source_reference' => 'synthetic:unstarted-policy',
        ], $this->actorId);
        $pdo->prepare('INSERT INTO payroll_offices (supplier_id, code, name, is_active) VALUES (?, "UNST", "Syntetická účtárna", 1)')
            ->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-01-01", "0012345678", "synthetic:unstarted")'
        )->execute([$this->supplierId, $this->officeId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testInputsOfEmploymentWithUnconfirmedStartBlockTheRun(): void
    {
        // Běžný vztah, aby běh nebyl prázdný.
        [, $activeEmploymentId] = $this->employment('Syntetická Aktivní', 'active', '2026-01-01', '2026-01-01');
        $this->input($activeEmploymentId, 2_000_000, 'approved');
        // Ručně založená DPP: nástup 7. 2., založení i podmínky až z 28. 10.
        [, $plannedEmploymentId] = $this->employment('Syntetická Brigádnice', 'planned', '2026-02-07', '2026-10-28');
        $this->input($plannedEmploymentId, 5_700_000, 'approved');

        $snapshot = $this->builder->build($this->supplierId, '2026-02-01', '2026-03-10');

        $found = self::byCode($snapshot->validations, 'inputs_on_unstarted_employment');
        self::assertCount(1, $found, json_encode(array_map(static fn ($v): string => $v->code, $snapshot->validations)));
        self::assertSame('blocker', $found[0]->severity);
        self::assertSame($plannedEmploymentId, $found[0]->entityId);
        self::assertStringContainsString('Syntetická Brigádnice', $found[0]->message);
        self::assertStringContainsString('57 000,00 Kč', $found[0]->message);
        self::assertStringContainsString('nástup není potvrzený', $found[0]->message);
        self::assertStringContainsString('Potvrdit nástup', $found[0]->message);
        self::assertStringContainsString("employment={$plannedEmploymentId}&panel=employment_terms", (string) $found[0]->remediationPath);

        $findings = $this->readiness->inspect($this->supplierId, '2026-02-01', '2026-03-10');
        $codes = array_column($findings['findings'], 'code');
        self::assertContains('inputs_on_unstarted_employment', $codes);
    }

    /**
     * Vstup za únor zadaný na březnovou dohodu osoby, která má i únorovou
     * dohodu: hláška nabídne přesun na platný vztah nebo zrušení a vede na
     * vstupy vztahu, ne jen na potvrzení nástupu.
     */
    public function testInputOnWrongAgreementOffersMoveToValidEmploymentOfThePerson(): void
    {
        [$employeeId, $februaryId] = $this->employment('Syntetická Dvojí', 'active', '2026-02-07', '2026-02-07', null, '1', '2026-02-28');
        $this->input($februaryId, 100_000, 'approved');
        [, $marchId] = $this->employment('Syntetická Dvojí', 'active', '2026-03-18', '2026-03-18', $employeeId, '2');
        $this->input($marchId, 29_250, 'approved');

        $snapshot = $this->builder->build($this->supplierId, '2026-02-01', '2026-03-10');

        $found = self::byCode($snapshot->validations, 'inputs_on_unstarted_employment');
        self::assertCount(1, $found, json_encode(array_map(static fn ($v): string => $v->code, $snapshot->validations)));
        self::assertSame($marchId, $found[0]->entityId);
        self::assertStringContainsString('nástup je evidovaný až od 2026-03-18', $found[0]->message);
        self::assertStringContainsString('platný vztah DPP 1 od 7. 2. 2026 do 28. 2. 2026', $found[0]->message);
        self::assertStringContainsString('přesuňte', $found[0]->message);
        self::assertStringContainsString('zrušte', $found[0]->message);
        self::assertSame("/payroll/components?tab=inputs&period=2026-02&employment={$marchId}", $found[0]->remediationPath);
    }

    public function testCancelledInputsOfUnstartedEmploymentAreNotReported(): void
    {
        [, $activeEmploymentId] = $this->employment('Syntetická Aktivní', 'active', '2026-01-01', '2026-01-01');
        $this->input($activeEmploymentId, 2_000_000, 'approved');
        [, $plannedEmploymentId] = $this->employment('Syntetická Zrušená', 'planned', '2026-02-07', '2026-10-28');
        $this->input($plannedEmploymentId, 100_000, 'cancelled');

        $snapshot = $this->builder->build($this->supplierId, '2026-02-01', '2026-03-10');

        self::assertSame([], self::byCode($snapshot->validations, 'inputs_on_unstarted_employment'));
    }

    public function testDraftInputIsReportedOnceNotAsEmploymentWithoutInputs(): void
    {
        [, $employmentId] = $this->employment('Syntetický Koncept', 'active', '2026-01-01', '2026-01-01');
        $this->input($employmentId, 50_000, 'draft');

        $snapshot = $this->builder->build($this->supplierId, '2026-02-01', '2026-03-10');

        self::assertCount(1, self::byCode($snapshot->validations, 'draft_inputs_present'));
        self::assertSame([], self::byCode($snapshot->validations, 'employment_without_inputs'));
    }

    public function testEmploymentWithoutAnyInputStillWarns(): void
    {
        [, $employmentId] = $this->employment('Syntetický Bez vstupu', 'active', '2026-01-01', '2026-01-01');

        $snapshot = $this->builder->build($this->supplierId, '2026-02-01', '2026-03-10');

        $found = self::byCode($snapshot->validations, 'employment_without_inputs');
        self::assertCount(1, $found);
        self::assertSame($employmentId, $found[0]->entityId);
    }

    /**
     * @param list<PayrollRunValidation> $validations
     * @return list<PayrollRunValidation>
     */
    private static function byCode(array $validations, string $code): array
    {
        return array_values(array_filter($validations, static fn (PayrollRunValidation $v): bool => $v->code === $code));
    }

    /** @return array{int,int} */
    private function employment(string $name, string $status, string $startDate, string $createdOn, ?int $employeeId = null, string $code = '1', ?string $endDate = null): array
    {
        $pdo = $this->db->pdo();
        if ($employeeId === null) {
            $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, ?, "employee", 1)')
                ->execute([$this->supplierId, $name]);
            $employeeId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO payroll_employee_profiles (supplier_id, employee_id, profile_status) VALUES (?, ?, "ready")')
                ->execute([$this->supplierId, $employeeId]);
        }
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, office_id, code, relation_type, status, start_date, actual_start_date, end_date, is_primary)
             VALUES (?, ?, ?, ?, "dpp", ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $employeeId, $this->officeId, $code, $status, $startDate, $status === 'active' ? $startDate : null, $endDate, $code === '1' ? 1 : 0]);
        $employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employment_events
                (supplier_id, employment_id, event_type, from_status, to_status, effective_on, note, diff_json, created_by)
             VALUES (?, ?, "created", NULL, ?, ?, NULL, "{}", ?)'
        )->execute([$this->supplierId, $employmentId, $status, $createdOn, $this->actorId]);
        $pdo->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from, planned_start_on, actual_start_on,
                 weekly_hours, workload_basis_points, social_insurance_participation,
                 health_insurance_participation, tax_regime, tax_declaration_signed, is_primary)
             VALUES (?, ?, ?, ?, ?, ?, 40, 10000, "automatic", "automatic", "advance", 1, 1)'
        )->execute([$this->supplierId, $employmentId, $this->officeId, $createdOn, $startDate, $status === 'active' ? $startDate : null]);

        return [$employeeId, $employmentId];
    }

    private function input(int $employmentId, int $amountMinor, string $status): void
    {
        $pdo = $this->db->pdo();
        $code = 'UNST' . $employmentId;
        $pdo->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind, frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment, health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment, jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code, valid_from)
             VALUES (?, ?, "Syntetická odměna", "base_wage", "monetary", "regular", "included",
                     "included", "included", "included", "included", "included", "included", "included",
                     "included", "521", "331", "2026-01-01")'
        )->execute([$this->supplierId, $code]);
        $componentId = (int) $pdo->lastInsertId();
        $json = CanonicalJson::encode([
            'code' => $code,
            'name' => 'Syntetická odměna',
            'component_kind' => 'base_wage',
            'value_kind' => 'monetary',
            'frequency_kind' => 'regular',
            'tax_treatment' => 'included',
            'social_participation_treatment' => 'included',
            'social_treatment' => 'included',
            'health_participation_treatment' => 'included',
            'health_treatment' => 'included',
            'average_earning_treatment' => 'included',
            'enforcement_treatment' => 'included',
            'jmhz_treatment' => 'included',
            'statistics_treatment' => 'included',
            'accounting_debit_code' => '521',
            'accounting_credit_code' => '331',
            'annual_limit_minor' => null,
            'component_id' => $componentId,
            'component_row_version' => 1,
            'valid_from' => '2026-01-01',
            'valid_to' => null,
        ]);
        $employeeId = (int) $pdo->query('SELECT employee_id FROM payroll_employments WHERE id = ' . $employmentId)->fetchColumn();
        $pdo->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id, period_start, amount_minor, source_kind, status,
                 component_snapshot_json, component_snapshot_hash, approved_by, approved_at)
             VALUES (?, ?, ?, ?, "2026-02-01", ?, "manual", ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId, $employeeId, $employmentId, $componentId, $amountMinor, $status,
            $json, hash('sha256', $json, true),
            $status === 'draft' ? null : $this->actorId,
            $status === 'draft' ? null : '2026-02-28 12:00:00',
        ]);
    }
}
