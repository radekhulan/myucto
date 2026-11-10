<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollAttendanceImportRepository;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Service\Payroll\Time\PayrollTimeImportApprovalService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Sloučení vztahu do jiného vztahu téže osoby.
 *
 * Reálný případ: import registrací založil osobě vztah HPP od 20. 2. s registrací,
 * účetní jí ručně založila DPP od 7. 2. a na ni naimportovala únorovou docházku
 * a mzdový vstup. Registrovaný vztah je pro ČSSZ ten správný, data ale leží na ručním.
 */
#[Group('integration')]
final class PayrollEmploymentMergeApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const FEBRUARY = '2026-02-01';

    private Connection $db;
    private PayrollEmploymentAction $action;
    private PayrollTimeRepository $time;
    private PayrollTimeImportApprovalService $approvals;
    private PayrollAttendanceImportRepository $imports;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $officeId;
    private int $userId;
    private int $componentId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(PayrollEmploymentAction::class);
        $this->time = $container->get(PayrollTimeRepository::class);
        $this->approvals = $container->get(PayrollTimeImportApprovalService::class);
        $this->imports = $container->get(PayrollAttendanceImportRepository::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $this->employeeId = $this->employee($this->supplierId, 'Syntetická Brigádnice');
        $pdo->prepare("INSERT INTO payroll_offices (supplier_id, code, name, is_active) VALUES (?, 'MAIN', 'Hlavní účtárna', 1)")
            ->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment, enforcement_treatment,
                 jmhz_treatment, statistics_treatment,
                 accounting_debit_code, accounting_credit_code,
                 valid_from, is_active)
             VALUES (?, "ODMENA", "Odměna", "bonus", "monetary", "one_off", "included",
                     "included", "included", "included", "included",
                     "included", "included", "included", "included",
                     "521", "331", "2026-01-01", 1)'
        )->execute([$this->supplierId]);
        $this->componentId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testManualAgreementMergesIntoRegisteredEmployment(): void
    {
        $registered = $this->employment('REG-1', 'employment', '2026-02-20', true, null);
        $this->importedRegistration((int) $registered['id']);
        $manual = $this->employment('DPP-1', 'dpp', '2026-02-07', false, '2026-06-30');
        $this->hourly((int) $manual['id'], 25000);
        // Registrovaný vztah má za únor prázdný pracovní měsíc (založený bez docházky).
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_time_months (supplier_id, employment_id, period_start) VALUES (?, ?, ?)"
        )->execute([$this->supplierId, $registered['id'], self::FEBRUARY]);
        $import = $this->importedAttendance((int) $manual['id']);
        $inputId = $this->input((int) $manual['id'], 'draft', "attendance:2026-02:{$manual['id']}:ODMENA");

        $response = $this->merge($manual, $registered, true, 'Registrace ČSSZ vede vztah jako HPP.');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        $merged = $body['employment'];
        self::assertSame((int) $registered['id'], (int) $merged['id']);
        self::assertSame('dpp', $merged['relation_type'], 'Cíl převezme druh vztahu ze zdroje.');
        self::assertSame('2026-02-07', $merged['start_date'], 'Nástup je dřívější z obou.');
        self::assertSame('2026-02-07', $merged['actual_start_date']);
        $terms = $merged['terms'][count($merged['terms']) - 1];
        self::assertSame('2026-02-07', $terms['effective_from']);
        self::assertSame('2026-06-30', $terms['fixed_term_end_on']);
        self::assertSame('hourly', $terms['remuneration_type']);
        self::assertSame(25000, (int) $terms['hourly_rate_minor']);
        self::assertSame('T', $terms['activity_code'], 'Druh činnosti se nastaví podle dohody.');
        self::assertSame((int) $manual['id'], $body['merged_employment_id']);
        $codes = array_column($body['warnings'], 'code');
        self::assertContains('registration_start_differs', $codes);
        self::assertContains('imported_registration_differs', $codes);
        self::assertContains('draft_inputs_follow', $codes);

        self::assertSame(0, $this->countRows('payroll_employments', 'id', (int) $manual['id']), 'Ruční vztah zmizel.');
        $input = $this->db->pdo()->query(
            'SELECT employment_id, external_id, status FROM payroll_inputs WHERE id = ' . $inputId
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame((int) $registered['id'], (int) $input['employment_id']);
        self::assertSame("attendance:2026-02:{$registered['id']}:ODMENA", $input['external_id']);
        self::assertSame('draft', $input['status']);
        self::assertSame(
            [$inputId],
            array_column($this->time->activeImportInputs($this->supplierId, (int) $registered['id'], self::FEBRUARY), 'id'),
            'Kontrola importu docházky najde přesunutý vstup pod novým vztahem.',
        );

        $months = $this->db->pdo()->prepare(
            'SELECT month.id, month.work_source, summary.values_json, summary.employment_id, summary.attendance_import_id
               FROM payroll_time_months month
               JOIN payroll_time_month_import_summaries summary
                 ON summary.supplier_id = month.supplier_id AND summary.time_month_id = month.id
              WHERE month.supplier_id = ? AND month.employment_id = ? AND month.period_start = ?'
        );
        $months->execute([$this->supplierId, $registered['id'], self::FEBRUARY]);
        $month = $months->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $month, 'Prázdný měsíc cíle nahradil měsíc se souhrnem importu.');
        self::assertSame('import_summary', $month[0]['work_source']);
        self::assertSame((int) $registered['id'], (int) $month[0]['employment_id']);
        self::assertSame($import, (int) $month[0]['attendance_import_id']);
        self::assertSame(['worked_millihours' => 52000], json_decode((string) $month[0]['values_json'], true));
        self::assertSame(1, $this->countRows('payroll_attendance_import_rows', 'employment_id', (int) $registered['id']));

        $event = $this->db->pdo()->query(
            "SELECT note, diff_json FROM payroll_employment_events WHERE event_type = 'merged' AND employment_id = " . (int) $registered['id']
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($event, 'Sloučení má vlastní událost na časové ose cíle.');
        self::assertSame('Registrace ČSSZ vede vztah jako HPP.', $event['note']);
        $diff = json_decode((string) $event['diff_json'], true);
        self::assertSame('DPP-1', $diff['merged_from']['code']);
        self::assertSame(['from' => 'employment', 'to' => 'dpp'], $diff['relation_type']);
        self::assertSame(['from' => '2026-02-20', 'to' => '2026-02-07'], $diff['start_date']);
        self::assertSame(1, $diff['moved']['payroll_inputs']);
    }

    public function testMergingSecondAgreementLeavesSingleAgreementAsFirst(): void
    {
        // Registrovaná DPP vznikla vedle ruční jako druhá souběžná (U); po
        // sloučení zbude jediná dohoda, takže je první u zaměstnavatele (T).
        $registered = $this->employment('REG-U', 'dpp', '2026-02-20', true, null);
        $this->importedRegistration((int) $registered['id']);
        $this->db->pdo()->prepare(
            "UPDATE payroll_employment_terms SET activity_code = 'U' WHERE supplier_id = ? AND employment_id = ?"
        )->execute([$this->supplierId, $registered['id']]);
        $manual = $this->employment('DPP-T', 'dpp', '2026-02-07', false, '2026-02-28');
        $this->input((int) $manual['id'], 'draft', null);

        $response = $this->merge($manual, $registered, true);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $merged = $this->json($response)['employment'];
        foreach ($merged['terms'] as $terms) {
            self::assertSame('T', $terms['activity_code'], 'Jediná dohoda osoby je první dohodou u zaměstnavatele.');
        }
    }

    public function testMergeWithoutTermsKeepsTargetTerms(): void
    {
        $registered = $this->employment('REG-2', 'employment', '2026-02-20', true, null);
        $manual = $this->employment('DPP-2', 'dpp', '2026-02-07', false, '2026-06-30');
        $this->input((int) $manual['id'], 'draft', null);

        $response = $this->merge($manual, $registered, false);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $merged = $this->json($response)['employment'];
        self::assertSame('employment', $merged['relation_type']);
        self::assertSame('2026-02-20', $merged['start_date']);
        self::assertSame($registered['row_version'] + 1, $merged['row_version']);
    }

    public function testApprovedInputIsRevertedAndMoved(): void
    {
        $registered = $this->employment('REG-3', 'employment', '2026-02-20', true, null);
        $manual = $this->employment('DPP-3', 'dpp', '2026-02-07', false, null);
        $inputId = $this->input((int) $manual['id'], 'approved', null);

        $response = $this->merge($manual, $registered, true);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertContains('source_approval_reverted', array_column($this->json($response)['warnings'], 'code'));
        $input = $this->db->pdo()->query('SELECT employment_id, status FROM payroll_inputs WHERE id = ' . $inputId)->fetch(\PDO::FETCH_ASSOC);
        self::assertSame((int) $registered['id'], (int) $input['employment_id']);
        self::assertSame('draft', $input['status'], 'U cíle se vstup schvaluje znovu.');
    }

    public function testLockedInputBlocksMerge(): void
    {
        $registered = $this->employment('REG-4', 'employment', '2026-02-20', true, null);
        $manual = $this->employment('DPP-4', 'dpp', '2026-02-07', false, null);
        $inputId = $this->input((int) $manual['id'], 'approved', null);
        $this->db->pdo()->exec("UPDATE payroll_inputs SET status = 'locked' WHERE id = {$inputId}");

        $response = $this->merge($manual, $registered, true);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('payroll_merge_source_has_locked_inputs', $this->json($response)['error']['code']);
        self::assertSame(1, $this->countRows('payroll_employments', 'id', (int) $manual['id']));
    }

    /**
     * Reálný průběh: účetní u ručního vztahu schválí importovanou docházku
     * i vstupy a u registrovaného zadá nulovou měsíční mzdu a schválí prázdný
     * měsíc. Schválení zapsalo neměnný souhrn JMHZ, který drží zdroj cizím
     * klíčem; podání to není. Sloučení schválení vrátí, souhrn zahodí a nulový
     * vstup ani prázdný měsíc cíle mu nevadí.
     */
    public function testApprovedAttendanceAndTargetPlaceholdersDoNotBlockMerge(): void
    {
        $registered = $this->employment('REG-9', 'employment', '2026-02-20', true, null);
        $this->importedRegistration((int) $registered['id']);
        $manual = $this->employment('DPP-9', 'dpp', '2026-02-07', false, null);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, activated_by, activated_at)
             VALUES (?, "setup", "2026-01-01", ?, NOW())',
        )->execute([$this->supplierId, $this->userId]);
        $importId = $this->imports->insertBatch(
            $this->supplierId,
            self::FEBRUARY,
            'attendance',
            random_bytes(32),
            [['name' => 'dochazka.xlsx', 'sha256' => hash('sha256', 'syntetický soubor docházky')]],
            [],
            1,
            [[
                'employment_id' => (int) $manual['id'],
                'meaning' => 'worked_hours',
                'component_code' => '',
                'quantity_millihours' => 52_000,
                'amount_minor' => null,
                'source_ref' => 'dochazka.xlsx!List1!C3',
            ]],
            $this->userId,
        );
        self::assertNotNull($importId);
        $result = $this->approvals->applyBatch($this->supplierId, $importId, true, $this->userId);
        self::assertSame(1, $result['approved'], (string) json_encode($result, JSON_UNESCAPED_UNICODE));
        self::assertSame('approved', $this->time->monthState($this->supplierId, (int) $manual['id'], self::FEBRUARY)['status'] ?? null);
        self::assertSame(1, $this->countRows('payroll_jmhz_work_month_revisions', 'employment_id', (int) $manual['id']));
        $sourceInput = $this->input((int) $manual['id'], 'approved', "attendance:2026-02:{$manual['id']}:ODMENA");
        $placeholder = $this->input((int) $registered['id'], 'approved', 'quick-monthly:MZDA_MESICNI');
        $pdo->prepare(
            'INSERT INTO payroll_time_months
                (supplier_id, employment_id, period_start, status, approved_by, approved_at, last_changed_by)
             VALUES (?, ?, ?, "approved", ?, NOW(), ?)',
        )->execute([$this->supplierId, $registered['id'], self::FEBRUARY, $this->userId, $this->userId]);

        $response = $this->merge($manual, $registered, true);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertContains('source_approval_reverted', array_column($this->json($response)['warnings'], 'code'));
        self::assertSame('draft', $pdo->query('SELECT status FROM payroll_inputs WHERE id = ' . $sourceInput)->fetchColumn());
        self::assertSame('draft', $pdo->query('SELECT status FROM payroll_inputs WHERE id = ' . $placeholder)->fetchColumn(), 'Vstup cíle se počítal podle dosavadního druhu, schvaluje se znovu.');
        self::assertSame(0, $this->countRows('payroll_employments', 'id', (int) $manual['id']));
        self::assertSame(0, $this->countRows('payroll_jmhz_work_month_revisions', 'employment_id', (int) $manual['id']));
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM sample_data_entries WHERE supplier_id = {$this->supplierId} AND entity_type LIKE 'payroll_row_%'"
        )->fetchColumn(), 'Značky pro smazání souhrnů nezůstaly.');
        $target = $this->time->monthState($this->supplierId, (int) $registered['id'], self::FEBRUARY);
        self::assertSame('open', $target['status'] ?? null, 'Únor je u cíle otevřený ke schválení.');
        self::assertSame(1, $this->countRows('payroll_registration_a1_profiles', 'employment_id', (int) $registered['id']));

        $log = $this->db->pdo()->query(
            "SELECT payload FROM activity_log WHERE action = 'payroll.employment.merged' AND entity_id = " . (int) $registered['id']
            . ' ORDER BY id DESC LIMIT 1'
        )->fetchColumn();
        $dropped = json_decode((string) $log, true)['dropped_jmhz_work_month_revisions'] ?? [];
        self::assertCount(1, $dropped, 'Auditní záznam nese otisk zahozeného souhrnu.');
        self::assertSame(self::FEBRUARY, $dropped[0]['period_start']);
    }

    public function testTargetInPayrollRunForMovedPeriodBlocksMerge(): void
    {
        $registered = $this->employment('REG-4', 'employment', '2026-02-20', true, null);
        $manual = $this->employment('DPP-4', 'dpp', '2026-02-07', false, null);
        $this->input((int) $manual['id'], 'draft', null);
        $this->inRun((int) $registered['id'], self::FEBRUARY);

        $response = $this->merge($manual, $registered, false);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('payroll_merge_target_in_run', $this->json($response)['error']['code']);
        self::assertSame(1, $this->countRows('payroll_inputs', 'employment_id', (int) $manual['id']));
    }

    public function testSourceInPayrollRunBlocksMerge(): void
    {
        $registered = $this->employment('REG-5', 'employment', '2026-02-20', true, null);
        $manual = $this->employment('DPP-5', 'dpp', '2026-02-07', false, null);
        $this->inRun((int) $manual['id'], self::FEBRUARY);

        $response = $this->merge($manual, $registered, false);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('payroll_merge_source_in_run', $this->json($response)['error']['code']);
    }

    public function testEmploymentOfAnotherPersonCannotBeMerged(): void
    {
        $registered = $this->employment('REG-6', 'employment', '2026-02-20', true, null);
        $otherPerson = $this->employee($this->supplierId, 'Jiná Osoba');
        $foreign = $this->employment('DPP-6', 'dpp', '2026-02-07', false, null, $otherPerson);

        $response = $this->merge($foreign, $registered, false);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('payroll_merge_other_person', $this->json($response)['error']['code']);
    }

    public function testAttendanceOfTheSameMonthOnBothEmploymentsBlocksMerge(): void
    {
        $registered = $this->employment('REG-7', 'employment', '2026-02-20', true, null);
        $manual = $this->employment('DPP-7', 'dpp', '2026-02-07', false, null);
        $this->importedAttendance((int) $manual['id']);
        $this->importedAttendance((int) $registered['id']);

        $response = $this->merge($manual, $registered, true);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('payroll_merge_time_month_conflict', $this->json($response)['error']['code']);
        self::assertSame(1, $this->countRows('payroll_time_months', 'employment_id', (int) $manual['id']));
    }

    public function testMergeCannotReachAnotherTenant(): void
    {
        $registered = $this->employment('REG-8', 'employment', '2026-02-20', true, null);
        $foreignEmployee = $this->employee($this->otherSupplierId, 'Cizí Pracovník');
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_employments (supplier_id, employee_id, code, relation_type, status, is_primary, row_version)
             VALUES (?, ?, 'CIZI', 'dpp', 'planned', 0, 1)"
        )->execute([$this->otherSupplierId, $foreignEmployee]);
        $foreign = ['id' => (int) $this->db->pdo()->lastInsertId(), 'row_version' => 1];

        self::assertSame(404, $this->merge($foreign, $registered, false)->getStatusCode());
        self::assertSame(404, $this->merge($registered, $foreign, false)->getStatusCode());
        self::assertSame(1, $this->countRows('payroll_employments', 'id', (int) $registered['id']));
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $target
     */
    private function merge(array $source, array $target, bool $adoptTerms, ?string $note = null): Response
    {
        return $this->action->mergeInto(
            (new ServerRequestFactory())
                ->createServerRequest('POST', "/api/payroll/employments/{$source['id']}/merge-into")
                ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
                ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
                ->withParsedBody([
                    'target_employment_id' => (int) $target['id'],
                    'row_version' => (int) $source['row_version'],
                    'target_row_version' => (int) $target['row_version'],
                    'adopt_terms' => $adoptTerms,
                    'note' => $note,
                ]),
            new Response(),
            ['id' => (string) $source['id']],
        );
    }

    /** @return array<string,mixed> */
    private function employment(string $code, string $relationType, string $start, bool $primary, ?string $fixedEnd, ?int $employeeId = null): array
    {
        $employeeId ??= $this->employeeId;
        [$activityCode, $detail] = $relationType === 'dpp' ? ['T', null] : ['1', '1'];
        $created = $this->action->create(
            $this->request('POST', "/api/payroll/people/{$employeeId}/employments", [
                'code' => $code,
                'relation_type' => $relationType,
                'monthly_gross_minor' => $relationType === 'dpp' ? null : 4000000,
                'terms' => [
                    'office_id' => $this->officeId,
                    'effective_from' => $start,
                    'contract_signed_on' => $start,
                    'planned_start_on' => $start,
                    'actual_start_on' => null,
                    'fixed_term_end_on' => $fixedEnd,
                    'weekly_hours' => '40',
                    'workload_basis_points' => 10000,
                    'work_place' => 'Praha',
                    'regular_workplace' => 'Praha',
                    'jmhz_workplace_municipality_code' => null,
                    'jmhz_workplace_country_code' => null,
                    'jmhz_apz_contribution_status' => 'unverified',
                    'jmhz_apz_instrument_code' => null,
                    'jmhz_functional_benefits_status' => 'unverified',
                    'jmhz_temporary_assignment_status' => 'unverified',
                    'cz_isco_code' => '43111',
                    'activity_code' => $activityCode,
                    'jmhz_relationship_detail_code' => $detail,
                    'social_insurance_participation' => 'automatic',
                    'health_insurance_participation' => 'automatic',
                    'tax_regime' => 'advance',
                    'foreign_legislation_country_code' => null,
                    'a1_certificate_until' => null,
                    'risky_work' => false,
                    'tax_declaration_signed' => false,
                    'is_primary' => $primary,
                    'change_reason' => 'Syntetické podmínky',
                ],
            ]),
            new Response(),
            ['id' => (string) $employeeId],
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $employment = $this->json($created)['employment'];

        $active = $this->action->transition(
            $this->request('POST', "/api/payroll/employments/{$employment['id']}/transitions/active", [
                'row_version' => $employment['row_version'],
                'effective_on' => $start,
            ]),
            new Response(),
            ['id' => (string) $employment['id'], 'target' => 'active'],
        );
        self::assertSame(200, $active->getStatusCode(), (string) $active->getBody());

        return $this->json($active)['employment'];
    }

    /** Převzatá registrace ČSSZ, jakou zakládá import registrací. */
    private function importedRegistration(int $employmentId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_registration_a1_profiles
                (supplier_id, employee_id, employment_id, effective_on,
                 profile_ciphertext, profile_hash, reference_hash, row_version)
             VALUES (?, ?, ?, ?, ?, UNHEX(SHA2(?, 256)), ?, 1)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $employmentId,
            '2026-02-20',
            'enc:v2:synthetic-fixture',
            "synthetic-a1-profile-{$employmentId}",
            str_repeat('a', 64),
        ]);
    }

    private function hourly(int $employmentId, int $rateMinor): void
    {
        $this->db->pdo()->prepare(
            "UPDATE payroll_employment_terms
                SET remuneration_type = 'hourly', hourly_rate_minor = ?, monthly_gross_minor = NULL,
                    remuneration_basis_kind = 'agreement', remuneration_basis = 'Dohoda o provedení práce'
              WHERE supplier_id = ? AND employment_id = ?"
        )->execute([$rateMinor, $this->supplierId, $employmentId]);
    }

    /** Únorová docházka ze souhrnu importu: měsíc, souhrn a řádek importu. */
    private function importedAttendance(int $employmentId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO payroll_attendance_imports (supplier_id, period_start, source_system, content_sha256, files_json, rules_json)
             VALUES (?, ?, 'myucto', UNHEX(SHA2(?, 256)), '[]', '{}')"
        )->execute([$this->supplierId, self::FEBRUARY, "attendance-{$employmentId}"]);
        $importId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_time_months (supplier_id, employment_id, period_start, work_source)
             VALUES (?, ?, ?, 'import_summary')"
        )->execute([$this->supplierId, $employmentId, self::FEBRUARY]);
        $monthId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_time_month_import_summaries
                (supplier_id, time_month_id, time_month_revision_no, employment_id, period_start,
                 attendance_import_id, values_json, worked_days, sources_json, content_sha256)
             VALUES (?, ?, 1, ?, ?, ?, '{\"worked_millihours\":52000}', 7, '{}', SHA2(?, 256))"
        )->execute([$this->supplierId, $monthId, $employmentId, self::FEBRUARY, $importId, "summary-{$employmentId}"]);
        $pdo->prepare(
            "INSERT INTO payroll_attendance_import_rows
                (supplier_id, import_id, employment_id, meaning, quantity_millihours, source_ref)
             VALUES (?, ?, ?, 'worked_hours', 52000, 'radek-1')"
        )->execute([$this->supplierId, $importId, $employmentId]);

        return $importId;
    }

    private function input(int $employmentId, string $status, ?string $externalId): int
    {
        $approved = $status === 'approved';
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_inputs
                (supplier_id, employee_id, employment_id, component_id, period_start, amount_minor,
                 source_kind, external_id, status, component_snapshot_json, component_snapshot_hash,
                 created_by, approved_by, approved_at)
             VALUES (?, (SELECT employee_id FROM payroll_employments WHERE id = ?), ?, ?, ?, 130000,
                     "time", ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $employmentId,
            $employmentId,
            $this->componentId,
            self::FEBRUARY,
            $externalId,
            $status,
            $approved ? '{}' : null,
            $approved ? hash('sha256', 'snapshot', true) : null,
            $this->userId,
            $approved ? $this->userId : null,
            $approved ? date('Y-m-d H:i:s') : null,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function inRun(int $employmentId, string $period): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_runs (supplier_id, period_start, payment_date) VALUES (?, ?, ?)')
            ->execute([$this->supplierId, $period, '2026-03-10']);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, 'reviewed', 'v1', ?, '{}', ?, UNHEX(SHA2(?, 256)))"
        )->execute([$this->supplierId, $runId, str_repeat('a', 64), str_repeat('b', 64), "merge-revision-{$employmentId}"]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_employments (supplier_id, revision_id, employee_id, employment_id, input_json, input_hash)
             VALUES (?, ?, (SELECT employee_id FROM payroll_employments WHERE id = ?), ?, ?, ?)'
        )->execute([$this->supplierId, $revisionId, $employmentId, $employmentId, '{}', str_repeat('c', 64)]);
    }

    private function countRows(string $table, string $column, int $value): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$column} = ?");
        $stmt->execute([$this->supplierId, $value]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');

        return $body === null ? $request : $request->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function employee(int $supplierId, string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 0, 0, 0, NULL, 0, 1)'
        )->execute([$supplierId, $name, 'employee', 'hpp']);

        return (int) $this->db->pdo()->lastInsertId();
    }
}
