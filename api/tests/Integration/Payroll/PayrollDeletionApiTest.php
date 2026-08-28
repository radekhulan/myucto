<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Action\Payroll\PayrollPeopleAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmployeeDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentDeletionRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Mazání pracovního vztahu a zaměstnance.
 *
 * Ověřuje vodicí princip: blokovat smí VÝHRADNĚ důkaz pohybu — vnější úkon,
 * schválený výpočet, nebo peníze. Odškrtnutý checklist je poznámka člověka,
 * ne důkaz, a mazání blokovat nesmí.
 */
#[Group('integration')]
final class PayrollDeletionApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmploymentAction $action;
    private PayrollPeopleAction $people;
    private PayrollEmploymentDeletionRepository $employmentDeletion;
    private PayrollEmployeeDeletionRepository $employeeDeletion;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $otherEmployeeId;
    private int $officeId;
    private int $userId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_employment_terms')) {
            $this->markTestSkipped('Migrace 1195 neproběhla.');
        }
        $this->action = $container->get(PayrollEmploymentAction::class);
        $this->people = $container->get(PayrollPeopleAction::class);
        $this->employmentDeletion = $container->get(PayrollEmploymentDeletionRepository::class);
        $this->employeeDeletion = $container->get(PayrollEmployeeDeletionRepository::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query(
            'SELECT id FROM supplier ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query(
            'SELECT id FROM users ORDER BY id LIMIT 1'
        )->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)'
        )->execute([$this->supplierId, $this->otherSupplierId]);

        $this->employeeId = $this->employee($this->supplierId, 'Testovací Pracovník');
        $this->otherEmployeeId = $this->employee($this->otherSupplierId, 'Cizí Pracovník');
        $office = $pdo->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name, is_active)
             VALUES (?, 'MAIN', 'Hlavní účtárna', 1)"
        );
        $office->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
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

    // ── Pracovní vztah ───────────────────────────────────────────────────────

    public function testFreshEmploymentIsDeletableAndClearsItsScaffold(): void
    {
        $employment = $this->create('HPP-1', 'employment', true);

        self::assertTrue($employment['can_delete']);
        self::assertNull($employment['delete_blocker']);
        self::assertGreaterThan(0, $employment['delete_cascade']['checklist']);
        self::assertSame(1, $employment['delete_cascade']['terms']);

        $response = $this->deleteEmployment($employment);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        self::assertSame(0, $this->rowCount('payroll_employments', 'id', $employment['id']));
        foreach ([
            'payroll_employment_terms',
            'payroll_employment_checklist_items',
            'payroll_employment_events',
        ] as $table) {
            self::assertSame(
                0,
                $this->rowCount($table, 'employment_id', $employment['id']),
                "Lešení {$table} se neuklidilo.",
            );
        }
    }

    public function testCompletedChecklistDoesNotBlockDeletion(): void
    {
        $employment = $this->create('HPP-2', 'employment', true);
        // Odškrtnutá „Registrace ČSSZ / JMHZ — Splněno" je poznámka člověka.
        // Navenek se nestalo nic, takže mazání blokovat nesmí.
        $employment = $this->checklist(
            $employment,
            'social_jmhz_registration',
            (int) $employment['row_version'],
        );
        $employment = $this->checklist(
            $employment,
            'health_insurance_registration',
            (int) $employment['row_version'],
        );

        self::assertTrue(
            $employment['can_delete'],
            'Odškrtnutý checklist nesmí blokovat mazání.',
        );

        $response = $this->deleteEmployment($employment);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->rowCount('payroll_employments', 'id', $employment['id']));
    }

    public function testEmploymentInRunRevisionIsBlockedWithExplanation(): void
    {
        $employment = $this->create('HPP-3', 'employment', true);
        $this->linkToRunRevision((int) $employment['id']);

        $decision = $this->employmentDeletion->canDelete(
            $this->supplierId,
            (int) $employment['id'],
        );
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_employment_in_run', $decision->blockerCode);
        self::assertStringContainsString('mzdového běhu', (string) $decision->blockerMessage);

        $response = $this->deleteEmployment($employment);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('payroll_employment_in_run', $this->json($response)['error']['code']);
        self::assertSame(1, $this->rowCount('payroll_employments', 'id', $employment['id']));
    }

    public function testEmploymentWithRegistrationRecordIsBlocked(): void
    {
        $employment = $this->create('HPP-4', 'employment', true);
        $this->insertRegistrationIdentifier((int) $employment['id']);

        $decision = $this->employmentDeletion->canDelete(
            $this->supplierId,
            (int) $employment['id'],
        );
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_employment_registered', $decision->blockerCode);

        $response = $this->deleteEmployment($employment);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('payroll_employments', 'id', $employment['id']));
    }

    public function testAuthoritativeA1ProfileBlocksEmploymentAndEmployeeDeletion(): void
    {
        $employment = $this->create('HPP-A1', 'employment', true);
        $employmentId = (int) $employment['id'];
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_registration_a1_profiles
                (supplier_id, employee_id, employment_id, effective_on,
                 profile_ciphertext, profile_hash, reference_hash, row_version)
             VALUES (?, ?, ?, ?, ?, UNHEX(SHA2(?, 256)), ?, 1)'
        )->execute([
            $this->supplierId,
            $this->employeeId,
            $employmentId,
            '2026-01-01',
            'enc:v2:synthetic-fixture',
            'synthetic-a1-profile',
            str_repeat('a', 64),
        ]);

        $employmentDecision = $this->employmentDeletion->canDelete(
            $this->supplierId,
            $employmentId,
        );
        self::assertNotNull($employmentDecision);
        self::assertFalse($employmentDecision->canDelete);
        self::assertSame('payroll_employment_registered', $employmentDecision->blockerCode);

        $employeeDecision = $this->employeeDeletion->canDelete(
            $this->supplierId,
            $this->employeeId,
        );
        self::assertNotNull($employeeDecision);
        self::assertFalse($employeeDecision->canDelete);
        self::assertSame($employmentId, $employeeDecision->blockedEmploymentId);

        foreach ([
            $this->deleteEmployment($employment),
            $this->deleteEmployee($this->employeeId),
        ] as $response) {
            self::assertSame(409, $response->getStatusCode());
            $error = $this->json($response)['error'];
            self::assertStringNotContainsStringIgnoringCase('constraint', $error['message']);
            self::assertStringNotContainsStringIgnoringCase('SQLSTATE', $error['message']);
        }
        self::assertSame(1, $this->rowCount('payroll_employments', 'id', $employmentId));
        self::assertSame(1, $this->rowCount('payroll_employees', 'id', $this->employeeId));
    }

    public function testForeignTenantSeesNeitherCanDeleteNorDeletes(): void
    {
        $employment = $this->create('HPP-5', 'employment', true);

        // Cizí tenant nesmí `can_delete` ani vidět.
        self::assertNull(
            $this->employmentDeletion->canDelete(
                $this->otherSupplierId,
                (int) $employment['id'],
            ),
        );

        $response = $this->action->delete(
            $this->request(
                'DELETE',
                "/api/payroll/employments/{$employment['id']}",
                ['row_version' => $employment['row_version']],
                supplierId: $this->otherSupplierId,
            ),
            new Response(),
            ['id' => (string) $employment['id']],
        );
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('payroll_employments', 'id', $employment['id']));
    }

    public function testConcurrentLinkFailsOnRecheckNotOnForeignKeyError(): void
    {
        $employment = $this->create('HPP-6', 'employment', true);
        self::assertTrue($employment['can_delete']);

        // Mezi vykreslením a kliknutím vznikla vazba.
        $this->linkToRunRevision((int) $employment['id']);

        $response = $this->deleteEmployment($employment);
        self::assertSame(409, $response->getStatusCode());
        $payload = $this->json($response)['error'];
        self::assertSame('payroll_employment_in_run', $payload['code']);
        // Ne syrová FK hláška z databáze.
        self::assertStringNotContainsStringIgnoringCase('constraint', $payload['message']);
        self::assertStringNotContainsStringIgnoringCase('SQLSTATE', $payload['message']);
        self::assertSame(1, $this->rowCount('payroll_employments', 'id', $employment['id']));
    }

    public function testGuardedDeleteStatementItselfRefusesWhenBlockerAppears(): void
    {
        // Poslední pojistka: i kdyby rozhodnutí prošlo, samotný DELETE je znovu
        // podmíněný všemi blokátory a smaže 0 řádků.
        $employment = $this->create('HPP-7', 'employment', true);
        $this->linkToRunRevision((int) $employment['id']);

        $reflection = new \ReflectionMethod(
            PayrollEmploymentDeletionRepository::class,
            'guardedDeleteSql',
        );
        $sql = $reflection->invoke(null);
        self::assertIsString($sql);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            $this->supplierId,
            (int) $employment['id'],
            (int) $employment['row_version'],
        ]);

        self::assertSame(0, $stmt->rowCount());
        self::assertSame(1, $this->rowCount('payroll_employments', 'id', $employment['id']));
    }

    public function testDeletionLeavesAnAuditTrail(): void
    {
        $employment = $this->create('HPP-8', 'employment', true);
        $employmentId = (int) $employment['id'];

        $response = $this->deleteEmployment($employment);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log
              WHERE supplier_id = ? AND action = 'payroll.employment.deleted'
                AND entity_type = 'payroll_employment' AND entity_id = ?"
        );
        $stmt->execute([$this->supplierId, $employmentId]);
        $payload = $stmt->fetchColumn();

        self::assertIsString($payload, 'Auditní záznam o smazání vztahu nevznikl.');
        $decoded = json_decode($payload, true);
        self::assertIsArray($decoded);
        self::assertSame('HPP-8', $decoded['code']);
        self::assertSame($this->employeeId, $decoded['employee_id']);
        self::assertSame('employment', $decoded['relation_type']);
        self::assertArrayHasKey('cascade', $decoded);
        self::assertGreaterThan(0, $decoded['cascade']['checklist']);
    }

    /**
     * Převzatý vztah nemá datum nástupu (starší agenda ho neevidovala), takže mu
     * migrace 1382 přidá do checklistu položku „Doplnit datum nástupu". Odškrtnout
     * ji jde až tehdy, když datum skutečně je — jinak by checklist hlásil hotovo
     * a na kartě by dál svítily pomlčky.
     */
    public function testLegacyStartDateChecklistCannotBeCompletedBeforeTheDateExists(): void
    {
        $employment = $this->create('HPP-12', 'employment', true);
        $employmentId = (int) $employment['id'];
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = NULL, actual_start_date = NULL, is_legacy_projection = 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $employmentId]);
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_employment_checklist_items
                (supplier_id, employment_id, phase, item_key, status)
             VALUES (?, ?, 'onboarding', 'legacy_start_date', 'pending')"
        )->execute([$this->supplierId, $employmentId]);

        $response = $this->action->checklist(
            $this->request(
                'PUT',
                "/api/payroll/employments/{$employmentId}/checklist/legacy_start_date",
                ['row_version' => 1, 'status' => 'completed'],
            ),
            new Response(),
            ['id' => (string) $employmentId, 'item_key' => 'legacy_start_date'],
        );

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString(
            'datum nástupu',
            $this->json($response)['error']['message'],
        );
    }

    // ── Zaměstnanec ──────────────────────────────────────────────────────────

    public function testEmptyEmployeeIsDeletable(): void
    {
        $employeeId = $this->employee($this->supplierId, 'test');

        $decision = $this->employeeDeletion->canDelete($this->supplierId, $employeeId);
        self::assertNotNull($decision);
        self::assertTrue($decision->canDelete, 'U prázdného zaměstnance není co chránit.');

        $response = $this->deleteEmployee($employeeId);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->rowCount('payroll_employees', 'id', $employeeId));
    }

    public function testEmployeeWithCompletedChecklistIsDeletable(): void
    {
        $employment = $this->create('HPP-9', 'employment', true);
        $this->checklist($employment, 'social_jmhz_registration', (int) $employment['row_version']);

        $decision = $this->employeeDeletion->canDelete($this->supplierId, $this->employeeId);
        self::assertNotNull($decision);
        self::assertTrue($decision->canDelete);
        self::assertSame(1, $decision->cascade['employments']);

        $response = $this->deleteEmployee($this->employeeId);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, $this->rowCount('payroll_employees', 'id', $this->employeeId));
        self::assertSame(0, $this->rowCount('payroll_employments', 'id', $employment['id']));
    }

    public function testEmployeeBlockedByEmploymentNamesThatEmployment(): void
    {
        $employment = $this->create('HPP-10', 'employment', true);
        $this->linkToRunRevision((int) $employment['id']);

        $decision = $this->employeeDeletion->canDelete($this->supplierId, $this->employeeId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame((int) $employment['id'], $decision->blockedEmploymentId);
        self::assertSame('HPP-10', $decision->blockedEmploymentCode);
        // Hláška musí říct KTERÝ vztah to blokuje, ne obecné „nelze smazat".
        self::assertStringContainsString('HPP-10', (string) $decision->blockerMessage);
        self::assertStringContainsString('mzdového běhu', (string) $decision->blockerMessage);

        $response = $this->deleteEmployee($this->employeeId);
        self::assertSame(409, $response->getStatusCode());
        $payload = $this->json($response)['error'];
        self::assertSame('HPP-10', $payload['employment_code']);
        self::assertSame(1, $this->rowCount('payroll_employees', 'id', $this->employeeId));
    }

    public function testEmployeeWithIssuedDocumentIsBlocked(): void
    {
        $this->insertGeneratedDocument($this->employeeId);

        $decision = $this->employeeDeletion->canDelete($this->supplierId, $this->employeeId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_employee_has_documents', $decision->blockerCode);

        $response = $this->deleteEmployee($this->employeeId);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('payroll_employees', 'id', $this->employeeId));
    }

    /**
     * `payroll_employees` sdílí novější modul se starší agendou Mzdová rekapitulace
     * a `payroll_monthly_records` má ON DELETE CASCADE — databáze by zaúčtované mzdy
     * tiše smetla. Blokujeme je proto výslovně.
     */
    public function testLegacyPostedPayrollBlocksEmployeeDeletion(): void
    {
        $this->insertLegacyMonthlyRecord($this->employeeId);

        $decision = $this->employeeDeletion->canDelete($this->supplierId, $this->employeeId);
        self::assertNotNull($decision);
        self::assertFalse($decision->canDelete);
        self::assertSame('payroll_employee_has_legacy_payroll', $decision->blockerCode);

        $response = $this->deleteEmployee($this->employeeId);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('payroll_employees', 'id', $this->employeeId));
        self::assertSame(1, $this->rowCount('payroll_monthly_records', 'employee_id', $this->employeeId));
    }

    public function testForeignTenantSeesNeitherCanDeleteNorDeletesEmployee(): void
    {
        self::assertNull(
            $this->employeeDeletion->canDelete($this->otherSupplierId, $this->employeeId),
        );

        $response = $this->people->delete(
            $this->request(
                'DELETE',
                "/api/payroll/people/{$this->employeeId}",
                null,
                supplierId: $this->otherSupplierId,
            ),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('payroll_employees', 'id', $this->employeeId));
    }

    public function testEmployeeConcurrentLinkFailsOnRecheckNotOnForeignKeyError(): void
    {
        $employment = $this->create('HPP-11', 'employment', true);
        $decision = $this->employeeDeletion->canDelete($this->supplierId, $this->employeeId);
        self::assertNotNull($decision);
        self::assertTrue($decision->canDelete);

        // Mezi vykreslením a kliknutím vznikla vazba.
        $this->linkToRunRevision((int) $employment['id']);

        $response = $this->deleteEmployee($this->employeeId);
        self::assertSame(409, $response->getStatusCode());
        $payload = $this->json($response)['error'];
        self::assertStringNotContainsStringIgnoringCase('constraint', $payload['message']);
        self::assertStringNotContainsStringIgnoringCase('SQLSTATE', $payload['message']);
        self::assertSame(1, $this->rowCount('payroll_employees', 'id', $this->employeeId));
        self::assertSame(1, $this->rowCount('payroll_employments', 'id', $employment['id']));
    }

    public function testEmployeeDeletionLeavesAnAuditTrail(): void
    {
        $employeeId = $this->employee($this->supplierId, 'Auditovaný');

        $response = $this->deleteEmployee($employeeId);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log
              WHERE supplier_id = ? AND action = 'payroll.employee.deleted'
                AND entity_type = 'payroll_employee' AND entity_id = ?"
        );
        $stmt->execute([$this->supplierId, $employeeId]);
        $payload = $stmt->fetchColumn();

        self::assertIsString($payload, 'Auditní záznam o smazání zaměstnance nevznikl.');
        $decoded = json_decode($payload, true);
        self::assertIsArray($decoded);
        self::assertSame('Auditovaný', $decoded['full_name']);
        self::assertArrayHasKey('cascade', $decoded);
    }

    // ── Pomocné ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function create(string $code, string $relationType, bool $primary): array
    {
        $response = $this->action->create(
            $this->request(
                'POST',
                "/api/payroll/people/{$this->employeeId}/employments",
                [
                    'code' => $code,
                    'relation_type' => $relationType,
                    'monthly_gross_minor' => 4000000,
                    'terms' => $this->termsPayload($primary),
                ],
            ),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['employment'];
    }

    /** @param array<string,mixed> $employment */
    private function deleteEmployment(array $employment): Response
    {
        return $this->action->delete(
            $this->request(
                'DELETE',
                "/api/payroll/employments/{$employment['id']}",
                ['row_version' => $employment['row_version']],
            ),
            new Response(),
            ['id' => (string) $employment['id']],
        );
    }

    private function deleteEmployee(int $employeeId): Response
    {
        return $this->people->delete(
            $this->request('DELETE', "/api/payroll/people/{$employeeId}"),
            new Response(),
            ['id' => (string) $employeeId],
        );
    }

    /**
     * @param array<string,mixed> $employment
     * @return array<string,mixed>
     */
    private function checklist(array $employment, string $itemKey, int $rowVersion): array
    {
        $response = $this->action->checklist(
            $this->request(
                'PUT',
                "/api/payroll/employments/{$employment['id']}/checklist/{$itemKey}",
                ['row_version' => $rowVersion, 'status' => 'completed'],
            ),
            new Response(),
            ['id' => (string) $employment['id'], 'item_key' => $itemKey],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['employment'];
    }

    private function linkToRunRevision(int $employmentId): void
    {
        $pdo = $this->db->pdo();
        $run = $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date)
             VALUES (?, ?, ?)'
        );
        $run->execute([$this->supplierId, '2026-01-01', '2026-02-10']);
        $runId = (int) $pdo->lastInsertId();

        $revision = $pdo->prepare(
            "INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 idempotency_key_hash)
             VALUES (?, ?, 1, 'reviewed', 'v1', ?, '{}', ?, UNHEX(SHA2(?, 256)))"
        );
        $revision->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            "revision-{$employmentId}",
        ]);
        $revisionId = (int) $pdo->lastInsertId();

        $link = $pdo->prepare(
            'INSERT INTO payroll_run_employments
                (supplier_id, revision_id, employee_id, employment_id, input_json, input_hash)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $link->execute([
            $this->supplierId,
            $revisionId,
            $this->employeeId,
            $employmentId,
            '{}',
            str_repeat('c', 64),
        ]);
    }

    /**
     * Skutečný záznam registrace: ID PPV, které vztahu přidělila ČSSZ. Na rozdíl
     * od odškrtnutého checklistu je tohle důkaz, že úkon proběhl navenek.
     */
    private function insertRegistrationIdentifier(int $employmentId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO payroll_employment_external_ids
                (supplier_id, employee_id, employment_id, environment,
                 identifier_type, value_ciphertext, value_hash, value_masked,
                 valid_from, source_kind, source_reference_hash)
             VALUES (?, ?, ?, 'test', 'id_ppv', 'enc:v2:test', UNHEX(SHA2(?, 256)),
                     '***123', '2026-01-01', 'verified_manual_import', ?)"
        );
        $stmt->execute([
            $this->supplierId,
            $this->employeeId,
            $employmentId,
            "id-ppv-{$employmentId}",
            str_repeat('a', 64),
        ]);
    }

    private function insertGeneratedDocument(int $employeeId): void
    {
        $pdo = $this->db->pdo();
        $resultHash = str_repeat('1', 64);
        $run = $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date)
             VALUES (?, ?, ?)'
        );
        $run->execute([$this->supplierId, '2026-03-01', '2026-04-10']);
        $runId = (int) $pdo->lastInsertId();

        $revision = $pdo->prepare(
            "INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, 'approved', 'v1', ?, '{}', ?, ?, UNHEX(SHA2(?, 256)))"
        );
        $revision->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            str_repeat('b', 64),
            $resultHash,
            "document-revision-{$employeeId}",
        ]);
        $revisionId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            "INSERT INTO payroll_generated_documents
                (supplier_id, employee_id, run_id, revision_id, document_kind,
                 revision_snapshot_hash, source_snapshot_hash, template_version,
                 renderer_version, file_sha256, size_bytes, mime_type, storage_key,
                 suggested_filename, idempotency_key_hash)
             VALUES (?, ?, ?, ?, 'payslip', ?, ?, 'v1', 'v1', ?, 1024,
                     'application/pdf', ?, 'vyplatni-paska.pdf', UNHEX(SHA2(?, 256)))"
        );
        $stmt->execute([
            $this->supplierId,
            $employeeId,
            $runId,
            $revisionId,
            $resultHash,
            str_repeat('2', 64),
            str_repeat('3', 64),
            str_repeat('3', 64),
            "document-{$employeeId}",
        ]);
    }

    private function insertLegacyMonthlyRecord(int $employeeId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_monthly_records
                (supplier_id, employee_id, year, month, gross, breakdown,
                 advance_tax_final, net_final)
             VALUES (?, ?, 2026, 1, 40000, ?, 3000, 32000)'
        );
        $stmt->execute([$this->supplierId, $employeeId, '{}']);
    }

    private function rowCount(string $table, string $column, mixed $value): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$column} = ?"
        );
        $stmt->execute([$this->supplierId, (int) $value]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed>|null $body */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        ?int $supplierId = null,
    ): \Psr\Http\Message\ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $supplierId ?? $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'accountant'],
            )
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
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 0, 0, 0, NULL, 0, 1)'
        );
        $stmt->execute([$supplierId, $name, 'employee', 'hpp']);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function termsPayload(bool $primary): array
    {
        return [
            'office_id' => $this->officeId,
            'effective_from' => '2026-01-01',
            'contract_signed_on' => '2025-12-20',
            'planned_start_on' => '2026-01-01',
            'actual_start_on' => null,
            'fixed_term_end_on' => '2026-12-31',
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
            'activity_code' => '1',
            'jmhz_relationship_detail_code' => '1',
            'social_insurance_participation' => 'automatic',
            'health_insurance_participation' => 'automatic',
            'tax_regime' => 'advance',
            'foreign_legislation_country_code' => null,
            'a1_certificate_until' => null,
            'risky_work' => false,
            'tax_declaration_signed' => true,
            'is_primary' => $primary,
            'change_reason' => 'Testovací podmínky',
        ];
    }
}
