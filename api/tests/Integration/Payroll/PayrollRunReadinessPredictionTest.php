<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunStatutoryInputAssembler;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola před během předpovídá blokace, které se dřív ukázaly až později.
 *
 * Simulace února: kontrola hlásila dvě osoby bez prohlášení, zamknutí
 * s výpočtem jich pak zastavilo šestnáct. Kontrola teď posuzuje stav
 * prohlášení nad týmž snímkem a týmž pravidlem jako výpočet.
 */
#[Group('integration')]
final class PayrollRunReadinessPredictionTest extends TestCase
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
             VALUES (?, ?, "Synthetic prediction", "readonly", "cs", 1)'
        )->execute([
            'prediction-' . bin2hex(random_bytes(4)) . '@invalid.example',
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
            'source_reference' => 'synthetic:prediction-policy',
        ], $this->actorId);
        $pdo->prepare('INSERT INTO payroll_offices (supplier_id, code, name, is_active) VALUES (?, "PRED", "Syntetická účtárna", 1)')
            ->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_office_registration_versions
                (supplier_id, office_id, effective_from, social_security_variable_symbol, source_reference)
             VALUES (?, ?, "2026-01-01", "0012345678", "synthetic:prediction")'
        )->execute([$this->supplierId, $this->officeId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testReadinessPredictsEveryPersonTheCalculationStopsOnTaxDeclaration(): void
    {
        // Prohlášení zapsané až od poloviny února: katalog mezer (záznam
        // kdykoli v období) ho bral za doplněné, zmrazená evidence k začátku
        // měsíce ho nevidí a výpočet osobu zastaví.
        [$endedId] = $this->employment('Syntetická Pozdní');
        $this->declaration($endedId, 'signed', '2026-02-15', null);
        [$missingId] = $this->employment('Syntetický Bez prohlášení');
        [$signedId] = $this->employment('Syntetická Podepsaná');
        $this->declaration($signedId, 'signed', '2026-01-01', null);
        [$unverifiedId] = $this->employment('Syntetický Neověřený');
        $this->declaration($unverifiedId, 'unverified', '2026-01-01', null);
        foreach ([$endedId, $missingId, $signedId, $unverifiedId] as $employeeId) {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_person_tax_residences
                    (supplier_id, employee_id, residence, country_code, effective_from, evidence_reference)
                 VALUES (?, ?, "czech-resident", "CZ", "2026-01-01", "synthetic:prediction")'
            )->execute([$this->supplierId, $employeeId]);
        }

        $snapshot = $this->builder->build($this->supplierId, '2026-02-01', '2026-03-10');
        $blocked = [];
        foreach ((new PayrollRunStatutoryInputAssembler())->assemble($snapshot->data)->issues as $issue) {
            if (in_array($issue->code, ['tax_declaration_evidence_missing', 'tax_declaration_evidence_unverified'], true)) {
                $blocked[] = (int) substr((string) $issue->personReference, strlen('employee:'));
            }
        }
        sort($blocked);
        $expected = [$endedId, $missingId, $unverifiedId];
        sort($expected);
        self::assertSame($expected, $blocked, 'Výpočet zastaví právě tyhle osoby.');

        $result = $this->readiness->inspect($this->supplierId, '2026-02-01', '2026-03-10');
        $finding = self::finding($result['findings'], 'tax_declaration_evidence_missing');
        self::assertNotNull($finding, json_encode(array_column($result['findings'], 'code')));
        self::assertSame(3, $finding['entity_total']);
        $predicted = array_column($finding['entities'], 'entity_id');
        sort($predicted);
        self::assertSame($expected, $predicted);
        self::assertSame('blocker', $finding['severity']);
        self::assertSame([['kind' => 'declaration_signed', 'count' => 3]], $finding['bulk_actions']);
        self::assertStringContainsString('Syntetická Pozdní', $finding['message']);

        // Tatáž věc nevisí podruhé v katalogu mezer s jiným počtem.
        $gaps = self::finding($result['findings'], 'person_data_gap');
        foreach ($gaps['entities'] ?? [] as $entity) {
            self::assertNotContains('tax_declaration', $entity['gaps'] ?? []);
        }
    }

    public function testReadinessPredictsUndecidedAnnualSettlementRequestBeforeJmhzFreeze(): void
    {
        // Únor: hlášení se ptá, zda zaměstnanec požádal o roční zúčtování za
        // rok 2025. Zmrazení hlášení ho bez odpovědi zastaví, kontrola před
        // během o tom dřív mlčela.
        [$withoutRowId] = $this->employment('Syntetický Bez žádosti');
        [$unknownId] = $this->employment('Syntetická Nevím');
        [$decidedId] = $this->employment('Syntetický Nepožádal');
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_annual_settlement_requests
                (supplier_id, employee_id, tax_year, request_status, created_by, updated_by)
             VALUES (?, ?, 2025, ?, ?, ?)'
        );
        $insert->execute([$this->supplierId, $unknownId, 'unknown', $this->actorId, $this->actorId]);
        $insert->execute([$this->supplierId, $decidedId, 'not_requested', $this->actorId, $this->actorId]);

        $result = $this->readiness->inspect($this->supplierId, '2026-02-01', '2026-03-10');

        $finding = self::finding($result['findings'], 'jmhz_annual_request_status_unresolved');
        self::assertNotNull($finding, json_encode(array_column($result['findings'], 'code')));
        self::assertNull(self::finding($result['findings'], 'jmhz_annual_request_source_missing'));
        self::assertSame(2, $finding['entity_total']);
        $ids = array_column($finding['entities'], 'entity_id');
        sort($ids);
        $expected = [$withoutRowId, $unknownId];
        sort($expected);
        self::assertSame($expected, $ids);
        self::assertSame([['kind' => 'annual_settlement_request', 'count' => 2]], $finding['bulk_actions']);
        self::assertStringContainsString('Syntetická Nevím', $finding['message']);
        self::assertStringStartsWith('/payroll/annual-settlement', (string) $finding['entities'][0]['remediation_path']);

        // Tlačítko vede na Roční zúčtování zúžené na tytéž osoby.
        $listed = \MyInvoice\Tests\Support\PayrollTestContainer::build()
            ->get(\MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository::class)
            ->listForYear($this->supplierId, 2025, 50, 0, '', 'request_undecided');
        $listedIds = array_map(static fn (array $item): int => (int) $item['employee_id'], $listed['items']);
        sort($listedIds);
        self::assertSame($expected, $listedIds);
        self::assertSame(2, $listed['total']);

        // Mimo leden a únor se stav žádosti nehlásí.
        $march = $this->readiness->inspect($this->supplierId, '2026-03-01', '2026-04-10');
        self::assertNull(self::finding($march['findings'], 'jmhz_annual_request_status_unresolved'));
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>|null
     */
    private static function finding(array $findings, string $code): ?array
    {
        foreach ($findings as $finding) {
            if ($finding['code'] === $code) {
                return $finding;
            }
        }

        return null;
    }

    /** @return array{int,int} */
    private function employment(string $name, string $relation = 'employment'): array
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
             VALUES (?, ?, ?, "1", ?, "active", "2026-01-01", "2026-01-01", 1)'
        )->execute([$this->supplierId, $employeeId, $this->officeId, $relation]);
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

        return [$employeeId, $employmentId];
    }

    private function declaration(int $employeeId, string $status, string $from, ?string $to): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_tax_declarations
                (supplier_id, employee_id, status, signed_on, effective_from, effective_to, evidence_reference)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $employeeId,
            $status,
            $status === 'signed' ? $from : null,
            $from,
            $to,
            $status === 'unverified' ? null : 'synthetic:prediction',
        ]);
    }
}
