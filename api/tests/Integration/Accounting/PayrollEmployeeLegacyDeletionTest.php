<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\PayrollEmployeeAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmployeeDeletionRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * DELETE /api/accounting/payroll/employees/{id} — starší agenda Mzdová rekapitulace.
 *
 * Routa hlídala JEDINOU navázanou tabulku (`payroll_monthly_records`), zatímco
 * `payroll_employees` sdílí s novějším mzdovým modulem, který na ni váže desítky
 * dalších. Osobu, která žila jen v novém modulu, proto pustila a kaskáda pod ní
 * tiše smetla profil, pracovní vztahy, docházku i identitu.
 *
 * Testy drží tři větve: modul vlastní osobu → rozhoduje nová kontrola; modul ještě
 * neběží, ale data v něm už jsou → odmítnout s vysvětlením; modul se nepoužívá →
 * stará agenda beze změny.
 */
#[Group('integration')]
final class PayrollEmployeeLegacyDeletionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmployeeAction $action;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_module_state') || !$this->db->hasTable('payroll_employments')) {
            $this->markTestSkipped('Mzdové migrace neproběhly.');
        }
        $this->action = $container->get(PayrollEmployeeAction::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        // Routa je gatovaná režimem účetnictví, ne mzdovým modulem.
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id IN (?, ?)")
            ->execute([$this->supplierId, $this->otherSupplierId]);
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

    // ── Větev 3: modul se nepoužívá → stará agenda beze změny ────────────────

    public function testEmployeeWithoutAnyHistoryIsDeletedWhenModuleIsDisabled(): void
    {
        $employeeId = $this->employee('Omylem Založený');

        $response = $this->delete($employeeId);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertTrue($this->json($response)['deleted']);
        self::assertSame(0, $this->rowCount('payroll_employees', 'id', $employeeId));
    }

    public function testLegacyMonthlyRecordsStillBlockDeletion(): void
    {
        $employeeId = $this->employee('Zaúčtovaný');
        $this->insertLegacyMonthlyRecord($employeeId);

        $response = $this->delete($employeeId);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('employee_has_records', $this->json($response)['error']['code']);
        self::assertSame(1, $this->rowCount('payroll_employees', 'id', $employeeId));
        self::assertSame(1, $this->rowCount('payroll_monthly_records', 'employee_id', $employeeId));
    }

    // ── Větev 1: modul vlastní osobu → rozhoduje nová kontrola ───────────────

    public function testActiveModuleRoutesDecisionToPayrollCheckAndNamesTheReason(): void
    {
        $employeeId = $this->employee('Mzdový Modul');
        $this->insertGeneratedDocument($employeeId);
        $this->moduleState('active');

        $response = $this->delete($employeeId);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $error = $this->json($response)['error'];
        // Kód i věta musí přijít z NOVÉ kontroly, ne z jediného starého `hasMonthlyRecords`.
        self::assertSame('payroll_employee_has_documents', $error['code']);
        self::assertStringContainsString('výplatní páska', $error['message']);
        self::assertSame(1, $this->rowCount('payroll_employees', 'id', $employeeId));
        self::assertSame(1, $this->rowCount('payroll_generated_documents', 'employee_id', $employeeId));
    }

    /**
     * Bez opravy tenhle případ projde a kaskáda pod ním smaže celý profil.
     */
    public function testActiveModuleProfileIsNotSilentlyCascadedAwayByLegacyRoute(): void
    {
        $employeeId = $this->employee('Rozepsaná Karta');
        $this->insertProfile($employeeId);
        $this->insertRegistration($employeeId);
        $this->moduleState('active');

        $response = $this->delete($employeeId);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('payroll_employee_registered', $this->json($response)['error']['code']);
        self::assertSame(1, $this->rowCount('payroll_employee_profiles', 'employee_id', $employeeId));
    }

    public function testActiveModuleStillDeletesPersonWithoutAnyMovement(): void
    {
        $employeeId = $this->employee('Čistý List');
        $this->insertProfile($employeeId);
        $this->moduleState('active');

        $response = $this->delete($employeeId);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->json($response);
        self::assertTrue($body['deleted']);
        self::assertSame(1, $body['cascade']['profile'], 'Kaskáda musí říct, co zmizelo.');
        self::assertSame(0, $this->rowCount('payroll_employees', 'id', $employeeId));
        self::assertSame(0, $this->rowCount('payroll_employee_profiles', 'employee_id', $employeeId));
    }

    // ── Větev 2: modul ještě neběží, ale data v něm už jsou ──────────────────

    public function testSetupModuleRefusesDeletionAndExplainsWhereTheDataIs(): void
    {
        $employeeId = $this->employee('Rozpracovaný');
        $this->insertProfile($employeeId);
        $this->moduleState('setup');

        $response = $this->delete($employeeId);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $error = $this->json($response)['error'];
        self::assertSame('employee_has_payroll_module_data', $error['code']);
        self::assertStringContainsString('modulu Mzdy', $error['message']);
        self::assertStringContainsString('mzdový profil', $error['message']);
        self::assertSame('setup', $error['payroll_module_status']);
        self::assertArrayHasKey('payroll_employee_profiles', $error['tables']);
        self::assertSame(1, $this->rowCount('payroll_employees', 'id', $employeeId));
        self::assertSame(1, $this->rowCount('payroll_employee_profiles', 'employee_id', $employeeId));
    }

    public function testDisabledModuleWithLeftoverDataRefusesDeletionToo(): void
    {
        $employeeId = $this->employee('Zapomenutá Data');
        $this->insertProfile($employeeId);

        $response = $this->delete($employeeId);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('employee_has_payroll_module_data', $this->json($response)['error']['code']);
        self::assertSame(1, $this->rowCount('payroll_employees', 'id', $employeeId));
    }

    /**
     * Zaúčtované mzdy STARŠÍ agendy nesmí spustit hlášku o novém modulu — data tam
     * neleží a uživatel by hledal v modulu, kde nic není.
     */
    public function testLegacyMonthlyRecordsAreNotReportedAsPayrollModuleData(): void
    {
        $employeeId = $this->employee('Jen Rekapitulace');
        $this->insertLegacyMonthlyRecord($employeeId);

        $response = $this->delete($employeeId);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('employee_has_records', $this->json($response)['error']['code']);
    }

    // ── Cizí klíč RESTRICT: věta, ne 500 ─────────────────────────────────────

    public function testRestrictForeignKeyChildYieldsSentenceNotServerError(): void
    {
        $employeeId = $this->employee('Vydaná Páska');
        // `payroll_generated_documents` má FK RESTRICT — holý DELETE by skončil
        // 500 se syrovou databázovou hláškou.
        $this->insertGeneratedDocument($employeeId);

        $response = $this->delete($employeeId);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $message = $this->json($response)['error']['message'];
        self::assertNotSame('', $message);
        self::assertStringNotContainsStringIgnoringCase('SQLSTATE', $message);
        self::assertStringNotContainsStringIgnoringCase('constraint', $message);
        self::assertStringNotContainsStringIgnoringCase('foreign key', $message);
        self::assertSame(1, $this->rowCount('payroll_employees', 'id', $employeeId));
    }

    /**
     * Strukturální pojistka za `try/catch`: dokud registr pokrývá KAŽDOU tabulku
     * s cizím klíčem na `payroll_employees`, nemá se stará agenda o co rozbít.
     * Nová tabulka bez zápisu do registru tenhle test shodí dřív, než se dostane
     * k uživateli jako 500.
     */
    public function testEveryForeignKeyChildOfEmployeesIsKnownToTheRegistry(): void
    {
        $stmt = $this->db->pdo()->query(
            "SELECT DISTINCT TABLE_NAME
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND REFERENCED_TABLE_NAME = 'payroll_employees'"
        );
        $children = array_map('strval', (array) $stmt->fetchAll(PDO::FETCH_COLUMN));
        self::assertNotEmpty($children);

        $known = array_merge(
            PayrollEmployeeDeletionRepository::moduleTables(),
            PayrollEmployeeDeletionRepository::legacyAgendaTables(),
            array_keys(PayrollEmployeeDeletionRepository::detachedReferences()),
        );

        self::assertSame(
            [],
            array_values(array_diff($children, $known)),
            'Tabulka s cizím klíčem na payroll_employees chybí v registru — '
            . 'stará agenda by ji smazala naslepo nebo spadla na FK.',
        );
    }

    // ── Vozidlo a karta osobu jen jmenují ────────────────────────────────────

    /**
     * Bez úklidu zůstala karta s id smazaného zaměstnance a každé její uložení
     * vracelo 422 „Zaměstnanec nepatří této firmě". Vozidlo i karta zůstávají,
     * vynuluje se jen odkaz na osobu.
     */
    public function testLegacyDeletionDetachesVehicleDriverAndCardHolder(): void
    {
        $employeeId = $this->employee('Řidič S Kartou');
        [$carId, $cardId] = $this->vehicleAndCardOf($employeeId);

        self::assertSame(200, $this->delete($employeeId)->getStatusCode());

        $this->assertDetached($carId, $cardId);
    }

    public function testModuleDeletionDetachesVehicleDriverAndCardHolder(): void
    {
        $employeeId = $this->employee('Řidič V Modulu');
        $this->insertProfile($employeeId);
        $this->moduleState('active');
        [$carId, $cardId] = $this->vehicleAndCardOf($employeeId);

        self::assertSame(200, $this->delete($employeeId)->getStatusCode());

        $this->assertDetached($carId, $cardId);
    }

    public function testBlockedDeletionKeepsVehicleDriverAndCardHolder(): void
    {
        $employeeId = $this->employee('Řidič Se Mzdou');
        $this->insertLegacyMonthlyRecord($employeeId);
        [$carId, $cardId] = $this->vehicleAndCardOf($employeeId);

        self::assertSame(409, $this->delete($employeeId)->getStatusCode());

        $row = $this->db->pdo()->query(
            "SELECT c.driver_employee_id AS driver, pc.employee_id AS holder
               FROM cars c, payment_cards pc WHERE c.id = {$carId} AND pc.id = {$cardId}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame($employeeId, (int) $row['driver']);
        self::assertSame($employeeId, (int) $row['holder']);
    }

    // ── Auditní stopa ────────────────────────────────────────────────────────

    public function testLegacyDeletionLeavesNonEmptyAuditTrail(): void
    {
        $employeeId = $this->employee('Auditovaný Legacy');

        self::assertSame(200, $this->delete($employeeId)->getStatusCode());

        $payload = $this->auditPayload($employeeId);
        self::assertNotSame([], $payload, 'Auditní záznam nesmí být prázdný.');
        self::assertSame('Auditovaný Legacy', $payload['full_name']);
        self::assertSame('legacy', $payload['path']);
        self::assertSame('disabled', $payload['payroll_module_status']);
        self::assertSame(0, $payload['cascade_total']);
    }

    public function testModuleDeletionAuditTrailNamesThePathAndCascade(): void
    {
        $employeeId = $this->employee('Auditovaný Modul');
        $this->insertProfile($employeeId);
        $this->moduleState('active');

        self::assertSame(200, $this->delete($employeeId)->getStatusCode());

        $payload = $this->auditPayload($employeeId);
        self::assertSame('Auditovaný Modul', $payload['full_name']);
        self::assertSame('payroll_module', $payload['path']);
        self::assertSame('active', $payload['payroll_module_status']);
        self::assertGreaterThan(0, $payload['cascade_total']);
    }

    // ── Oprávnění a tenant ───────────────────────────────────────────────────

    /**
     * Účetnictví samo o sobě nestačí na smazání celé mzdové karty — týž úkon na
     * `/api/payroll/people/{id}` si žádá `payroll.person.write` a tahle routa
     * nesmí být obchvat kolem něj.
     */
    public function testModulePathRequiresPayrollPersonWrite(): void
    {
        $employeeId = $this->employee('Bez Mzdového Práva');
        $this->insertProfile($employeeId);
        $this->moduleState('active');

        $request = $this->request('DELETE', "/api/accounting/payroll/employees/{$employeeId}")
            ->withAttribute('auth.effective_role', new EffectiveRole(
                0,
                'Účetní bez mezd',
                'staff',
                true,
                ['accounting' => AccessLevel::WRITE->value],
                'custom',
            ));
        $response = $this->action->delete($request, new Response(), ['id' => (string) $employeeId]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('payroll_employees', 'id', $employeeId));
    }

    /** Účetní s vestavěnou rolí mzdové právo má — oprava ji omezit nesmí. */
    public function testBuiltInAccountantRoleCanStillDeleteThroughModulePath(): void
    {
        $employeeId = $this->employee('Účetní To Zvládne');
        $this->insertProfile($employeeId);
        $this->moduleState('active');

        self::assertSame(200, $this->delete($employeeId)->getStatusCode());
    }

    public function testForeignTenantDeletesNothing(): void
    {
        $employeeId = $this->employee('Cizí Tenant');
        $this->insertProfile($employeeId);
        $this->moduleState('active');

        $response = $this->action->delete(
            $this->request(
                'DELETE',
                "/api/accounting/payroll/employees/{$employeeId}",
                supplierId: $this->otherSupplierId,
            ),
            new Response(),
            ['id' => (string) $employeeId],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $this->rowCount('payroll_employees', 'id', $employeeId));
        self::assertSame(1, $this->rowCount('payroll_employee_profiles', 'employee_id', $employeeId));
    }

    // ── Pomocné ──────────────────────────────────────────────────────────────

    private function delete(int $employeeId): ResponseInterface
    {
        return $this->action->delete(
            $this->request('DELETE', "/api/accounting/payroll/employees/{$employeeId}"),
            new Response(),
            ['id' => (string) $employeeId],
        );
    }

    private function request(
        string $method,
        string $path,
        ?int $supplierId = null,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<string,mixed> */
    private function auditPayload(int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT payload FROM activity_log
              WHERE supplier_id = ? AND action = 'payroll_employee.deleted'
                AND entity_type = 'payroll_employee' AND entity_id = ?
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$this->supplierId, $employeeId]);
        $payload = $stmt->fetchColumn();
        self::assertIsString($payload, 'Auditní záznam o smazání zaměstnance nevznikl.');
        $decoded = json_decode($payload, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function moduleState(string $status): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_module_state (supplier_id, status, start_period, row_version)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE status = VALUES(status), start_period = VALUES(start_period)'
        )->execute([$this->supplierId, $status, '2026-01-01']);
    }

    private function employee(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, ?, ?, 0, 0, 0, NULL, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, 'employee', 'hpp']);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertProfile(int $employeeId): void
    {
        $this->db->pdo()
            ->prepare('INSERT INTO payroll_employee_profiles (supplier_id, employee_id) VALUES (?, ?)')
            ->execute([$this->supplierId, $employeeId]);
    }

    /** Přidělené ID z registrace u ČSSZ/MPSV — důkaz vnějšího úkonu. */
    private function insertRegistration(int $employeeId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO payroll_person_external_ids
                (supplier_id, employee_id, environment, identifier_type,
                 value_ciphertext, value_hash, value_masked, valid_from,
                 source_kind, source_reference_hash)
             VALUES (?, ?, 'test', 'ik_mpsv', 'enc:v2:test', UNHEX(SHA2(?, 256)),
                     '***123', '2026-01-01', 'verified_manual_import', ?)"
        );
        $stmt->execute([
            $this->supplierId,
            $employeeId,
            "external-{$employeeId}",
            str_repeat('a', 64),
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

    private function insertGeneratedDocument(int $employeeId): void
    {
        $pdo = $this->db->pdo();
        $resultHash = str_repeat('1', 64);
        $pdo->prepare(
            'INSERT INTO payroll_runs (supplier_id, period_start, payment_date) VALUES (?, ?, ?)'
        )->execute([$this->supplierId, '2026-03-01', '2026-04-10']);
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
            "legacy-document-revision-{$employeeId}",
        ]);
        $revisionId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO payroll_generated_documents
                (supplier_id, employee_id, run_id, revision_id, document_kind,
                 revision_snapshot_hash, source_snapshot_hash, template_version,
                 renderer_version, file_sha256, size_bytes, mime_type, storage_key,
                 suggested_filename, idempotency_key_hash)
             VALUES (?, ?, ?, ?, 'payslip', ?, ?, 'v1', 'v1', ?, 1024,
                     'application/pdf', ?, 'vyplatni-paska.pdf', UNHEX(SHA2(?, 256)))"
        )->execute([
            $this->supplierId,
            $employeeId,
            $runId,
            $revisionId,
            $resultHash,
            str_repeat('2', 64),
            str_repeat('3', 64),
            str_repeat('3', 64),
            "legacy-document-{$employeeId}",
        ]);
    }

    /** @return array{0:int, 1:int} id vozidla a karty, jejichž držitelem je osoba */
    private function vehicleAndCardOf(int $employeeId): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO cars (supplier_id, registration, driver_employee_id) VALUES (?, ?, ?)')
            ->execute([$this->supplierId, '9ZZ ' . $employeeId, $employeeId]);
        $carId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payment_cards (supplier_id, label, holder_name, last4, employee_id)
             VALUES (?, 'Testovací karta', 'Testovací držitel', '4242', ?)"
        )->execute([$this->supplierId, $employeeId]);

        return [$carId, (int) $pdo->lastInsertId()];
    }

    private function assertDetached(int $carId, int $cardId): void
    {
        $car = $this->db->pdo()->query("SELECT driver_employee_id FROM cars WHERE id = {$carId}")->fetch(PDO::FETCH_ASSOC);
        $card = $this->db->pdo()->query("SELECT employee_id, holder_name FROM payment_cards WHERE id = {$cardId}")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($car, 'Vozidlo smazáním řidiče nezmizí.');
        self::assertIsArray($card, 'Karta smazáním držitele nezmizí.');
        self::assertNull($car['driver_employee_id']);
        self::assertNull($card['employee_id']);
        self::assertSame('Testovací držitel', $card['holder_name']);
    }

    private function rowCount(string $table, string $column, mixed $value): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND {$column} = ?"
        );
        $stmt->execute([$this->supplierId, (int) $value]);

        return (int) $stmt->fetchColumn();
    }
}
