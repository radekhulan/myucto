<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollComponentsAction;
use MyInvoice\Action\Payroll\PayrollInputsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Zrušení vlastního konceptu mzdového vstupu.
 *
 * Mazací cesta dřív neexistovala vůbec: omylem založený (typicky nulový) koncept
 * vyrobil blokátor `draft_inputs_present` a jediným východiskem bylo ho schválit,
 * čímž skončil na výplatní pásce a zmrazil se do neměnného snapshotu. Zrušení jde
 * cestou `status = 'cancelled'`, kterou schéma od migrace 1210 předvídalo.
 *
 * Platí princip majitele: **blokovat smí jen důkaz pohybu.**
 */
#[Group('integration')]
final class PayrollInputCancellationApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const PERIOD = '2026-06';
    private const PERIOD_START = '2026-06-01';

    private Connection $db;
    private PayrollComponentsAction $components;
    private PayrollInputsAction $inputs;
    private int $supplierId;
    private int $employeeId;
    private int $employmentId;
    private int $userId;
    private int $componentId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $components = $container->get(PayrollComponentsAction::class);
        $inputs = $container->get(PayrollInputsAction::class);
        if (!$db instanceof Connection
            || !$components instanceof PayrollComponentsAction
            || !$inputs instanceof PayrollInputsAction
        ) {
            throw new \RuntimeException('Payroll služby nejsou dostupné.');
        }
        $this->db = $db;
        $this->components = $components;
        $this->inputs = $inputs;
        foreach ([
            'payroll_inputs',
            'payroll_benefit_accumulators',
            'payroll_travel_compensation_links',
            'payroll_input_import_rows',
            'payroll_run_revisions',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Mzdové migrace neproběhly.');
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = $this->firstId('supplier');
        $this->userId = $this->firstId('users');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        [$this->employeeId, $this->employmentId] = $this->employment('SYN-CANCEL-1', 'active');
        $this->componentId = $this->createComponent();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testDraftIsCancelledIdempotentlyDisappearsFromListAndStopsBlockingTheRun(): void
    {
        $input = $this->createInput('cancel-me', 0);
        $id = PayrollTimeValue::int($input['id'] ?? null, 'id');
        $version = PayrollTimeValue::int($input['row_version'] ?? null, 'row_version');
        self::assertSame(1, $this->draftBlockerCount());

        $response = $this->cancel($id, $version);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $cancelled = PayrollTimeValue::row($this->json($response)['input'] ?? null, 'input');
        self::assertSame('cancelled', $cancelled['status']);

        self::assertSame(
            [],
            $this->listedInputIds(),
            'Zrušený vstup se ve výpisu nesmí objevit.',
        );
        self::assertSame(
            0,
            $this->draftBlockerCount(),
            'Zrušený vstup už nesmí blokovat mzdový běh.',
        );

        // Idempotence: druhé zrušení nic nerozbije ani nezmění.
        $again = $this->cancel(
            $id,
            PayrollTimeValue::int($cancelled['row_version'] ?? null, 'row_version'),
        );
        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
        $repeated = PayrollTimeValue::row($this->json($again)['input'] ?? null, 'input');
        self::assertSame('cancelled', $repeated['status']);
        self::assertSame($cancelled['row_version'], $repeated['row_version']);
    }

    public function testCancellationIsAudited(): void
    {
        $input = $this->createInput('audit-me', 12_300);
        $id = PayrollTimeValue::int($input['id'] ?? null, 'id');
        $before = $this->auditCount($id);

        $response = $this->cancel(
            $id,
            PayrollTimeValue::int($input['row_version'] ?? null, 'row_version'),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            $before + 1,
            $this->auditCount($id),
            'Zrušení musí nechat auditní stopu.',
        );
    }

    public function testApprovedInputCannotBeCancelled(): void
    {
        $input = $this->createInput('approved-one', 45_000);
        $id = PayrollTimeValue::int($input['id'] ?? null, 'id');
        $version = PayrollTimeValue::int($input['row_version'] ?? null, 'row_version');

        $approved = $this->inputs->approve(
            $this->request('POST', "/api/payroll/inputs/{$id}/approve")
                ->withParsedBody(['row_version' => $version]),
            new Response(),
            ['id' => (string) $id],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        $approvedInput = PayrollTimeValue::row($this->json($approved)['input'] ?? null, 'input');

        $response = $this->cancel(
            $id,
            PayrollTimeValue::int($approvedInput['row_version'] ?? null, 'row_version'),
        );
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('input_state_conflict', $this->errorCode($response));
    }

    /**
     * Roční akumulátor benefitu zakládá teprve schválení, a schválený vstup
     * zrušit nejde. Kontrola akumulátoru ve `assertNoMovement()` proto neměla
     * co chytit — zrušení stojí o stav dřív, na `input_state_conflict`.
     */
    public function testBenefitAccumulatorExistsOnlyForApprovedInputsThatCancelRefusesAnyway(): void
    {
        $input = $this->createInput('benefit-one', 1_000);
        $id = PayrollTimeValue::int($input['id'] ?? null, 'id');
        self::assertSame(0, $this->accumulatorCount($id));

        // Koncept se stále zruší — nic ho nedrží.
        $cancelled = $this->cancel(
            $id,
            PayrollTimeValue::int($input['row_version'] ?? null, 'row_version'),
        );
        self::assertSame(200, $cancelled->getStatusCode(), (string) $cancelled->getBody());

        $second = $this->createInput('benefit-two', 1_000);
        $secondId = PayrollTimeValue::int($second['id'] ?? null, 'id');
        $approved = $this->inputs->approve(
            $this->request('POST', "/api/payroll/inputs/{$secondId}/approve")
                ->withParsedBody([
                    'row_version' => PayrollTimeValue::int(
                        $second['row_version'] ?? null,
                        'row_version',
                    ),
                ]),
            new Response(),
            ['id' => (string) $secondId],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        $approvedInput = PayrollTimeValue::row($this->json($approved)['input'] ?? null, 'input');

        $blocked = $this->cancel(
            $secondId,
            PayrollTimeValue::int($approvedInput['row_version'] ?? null, 'row_version'),
        );
        self::assertSame(409, $blocked->getStatusCode());
        self::assertSame('input_state_conflict', $this->errorCode($blocked));
    }

    private function accumulatorCount(int $inputId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_benefit_accumulators
              WHERE supplier_id = ? AND input_id = ?'
        );
        $stmt->execute([$this->supplierId, $inputId]);

        return (int) $stmt->fetchColumn();
    }

    public function testEvidenceOfMovementBlocksCancellation(): void
    {
        $travel = $this->createInput('with-travel', 2_000);
        $travelId = PayrollTimeValue::int($travel['id'] ?? null, 'id');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_travel_compensation_links
                (supplier_id, input_id, source_system, source_reference)
             VALUES (?, ?, "syntetika", "trip-1")'
        )->execute([$this->supplierId, $travelId]);
        $blockedTravel = $this->cancel(
            $travelId,
            PayrollTimeValue::int($travel['row_version'] ?? null, 'row_version'),
        );
        self::assertSame(409, $blockedTravel->getStatusCode());
        self::assertSame('input_has_movement', $this->errorCode($blockedTravel));

    }

    /**
     * Řádek importu NENÍ pohyb peněz.
     *
     * Import zakládá vstupy rovnou jako koncepty, takže dokud vazba na řádek
     * importu blokovala zrušení, neměl špatně naimportovaný řádek východisko:
     * nešel zrušit, držel mzdový běh na blokátoru `draft_inputs_present`
     * a jedinou cestou dál bylo schválit chybnou částku.
     */
    public function testImportedDraftCanBeCancelled(): void
    {
        $imported = $this->createInput('with-import', 3_000);
        $importedId = PayrollTimeValue::int($imported['id'] ?? null, 'id');
        $this->linkImportRow($importedId);
        $cancelled = $this->cancel(
            $importedId,
            PayrollTimeValue::int($imported['row_version'] ?? null, 'row_version'),
        );
        self::assertSame(200, $cancelled->getStatusCode(), (string) $cancelled->getBody());
        self::assertSame(
            'cancelled',
            PayrollTimeValue::row($this->json($cancelled)['input'] ?? null, 'input')['status'],
        );

        // Stopa po souboru zůstává; zrušením konceptu nemizí.
        $rows = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_input_import_rows
              WHERE supplier_id = ? AND input_id = ?'
        );
        $rows->execute([$this->supplierId, $importedId]);
        self::assertSame(1, (int) $rows->fetchColumn());
    }

    public function testInputFrozenInARunRevisionCannotBeCancelled(): void
    {
        $input = $this->createInput('frozen-one', 7_500);
        $id = PayrollTimeValue::int($input['id'] ?? null, 'id');
        $this->freezeInRunRevision($id);

        $response = $this->cancel(
            $id,
            PayrollTimeValue::int($input['row_version'] ?? null, 'row_version'),
        );
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('input_has_movement', $this->errorCode($response));
    }

    public function testStaleRowVersionConflicts(): void
    {
        $input = $this->createInput('stale-one', 500);
        $id = PayrollTimeValue::int($input['id'] ?? null, 'id');

        $response = $this->cancel($id, 99);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('row_version_conflict', $this->errorCode($response));
    }

    /** Vada 5 — server je zdroj pravdy, ne odfiltrovaná nabídka ve formuláři. */
    public function testInputCannotBeCreatedOnANoShowRelation(): void
    {
        [$employeeId, $employmentId] = $this->employment('SYN-CANCEL-NOSHOW', 'no_show');
        $response = $this->inputs->create(
            $this->request('POST', '/api/payroll/inputs')->withParsedBody([
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'component_id' => $this->componentId,
                'period' => self::PERIOD,
                'source_period' => null,
                'amount_minor' => 10_000,
                'quantity_milliunits' => null,
                'source_kind' => 'manual',
                'external_id' => 'no-show-attempt',
            ]),
            new Response(),
        );
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($response));
    }

    public function testInputCannotBeCreatedOnAnArchivedRelation(): void
    {
        [$employeeId, $employmentId] = $this->employment('SYN-CANCEL-ARCH', 'archived');
        $response = $this->inputs->create(
            $this->request('POST', '/api/payroll/inputs')->withParsedBody([
                'employee_id' => $employeeId,
                'employment_id' => $employmentId,
                'component_id' => $this->componentId,
                'period' => self::PERIOD,
                'source_period' => null,
                'amount_minor' => 10_000,
                'quantity_milliunits' => null,
                'source_kind' => 'manual',
                'external_id' => 'archived-attempt',
            ]),
            new Response(),
        );
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($response));
    }

    private function cancel(int $id, int $rowVersion): ResponseInterface
    {
        return $this->inputs->cancel(
            $this->request('POST', "/api/payroll/inputs/{$id}/cancel")
                ->withParsedBody(['row_version' => $rowVersion]),
            new Response(),
            ['id' => (string) $id],
        );
    }

    /** @return list<int> */
    private function listedInputIds(): array
    {
        $response = $this->inputs->list(
            $this->request('GET', '/api/payroll/inputs')
                ->withQueryParams(['period' => self::PERIOD]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $rows = $this->json($response)['inputs'] ?? null;
        self::assertIsArray($rows);

        $ids = [];
        foreach (PayrollTimeValue::rows($rows, 'inputs') as $row) {
            $ids[] = PayrollTimeValue::int($row['id'] ?? null, 'id');
        }

        return $ids;
    }

    /** Přesně to, co počítá blokátor `draft_inputs_present` mzdového běhu. */
    private function draftBlockerCount(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND status = "draft"'
        );
        $stmt->execute([$this->supplierId, $this->employmentId, self::PERIOD_START]);

        return (int) $stmt->fetchColumn();
    }

    private function auditCount(int $inputId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM activity_log
              WHERE action = "payroll.input.cancelled"
                AND entity_type = "payroll_input"
                AND entity_id = ?'
        );
        $stmt->execute([$inputId]);

        return (int) $stmt->fetchColumn();
    }

    private function linkImportRow(int $inputId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_input_imports
                (supplier_id, period_start, source_kind, source_name, content_hash,
                 status, row_count, accepted_count)
             VALUES (?, ?, "csv", "syntetika.csv", UNHEX(SHA2(?, 256)), "accepted", 1, 1)'
        )->execute([$this->supplierId, self::PERIOD_START, 'syntetika-' . $inputId]);
        $importId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_input_import_rows
                (supplier_id, import_id, input_id, source_row_number, external_id,
                 status, normalized_payload, errors_json)
             VALUES (?, ?, ?, 1, "import-1", "accepted", "{}", "[]")'
        )->execute([$this->supplierId, $importId, $inputId]);
    }

    private function freezeInRunRevision(int $inputId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, "2026-07-10", "calculated", 1)'
        )->execute([$this->supplierId, self::PERIOD_START]);
        $runId = (int) $pdo->lastInsertId();
        $snapshot = CanonicalJson::encode([
            'schema_version' => 'payroll-run-input.v2',
            'people' => [[
                'employments' => [[
                    'inputs' => [['id' => $inputId]],
                ]],
            ]],
        ]);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, 1, "regular", "calculated", "payroll-run-input.v2",
                     SHA2(?, 256), ?, SHA2(?, 256), UNHEX(SHA2(?, 256)))'
        )->execute([
            $this->supplierId,
            $runId,
            'manifest-' . $inputId,
            $snapshot,
            $snapshot,
            'idempotency-' . $inputId,
        ]);
    }

    /** @return array<string,mixed> */
    private function createInput(string $externalId, int $amountMinor): array
    {
        $response = $this->inputs->create(
            $this->request('POST', '/api/payroll/inputs')->withParsedBody([
                'employee_id' => $this->employeeId,
                'employment_id' => $this->employmentId,
                'component_id' => $this->componentId,
                'period' => self::PERIOD,
                'source_period' => null,
                'amount_minor' => $amountMinor,
                'quantity_milliunits' => null,
                'source_kind' => 'manual',
                'external_id' => $externalId,
            ]),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return PayrollTimeValue::row($this->json($response)['input'] ?? null, 'input');
    }

    private function createComponent(): int
    {
        $response = $this->components->create(
            $this->request('POST', '/api/payroll/components')->withParsedBody([
                'code' => 'SYN_CANCEL',
                'name' => 'Syntetická odměna',
                'component_kind' => 'bonus',
                'value_kind' => 'monetary',
                'frequency_kind' => 'one_off',
                'tax_treatment' => 'included',
                'social_participation_treatment' => 'included',
                'social_treatment' => 'included',
                'health_participation_treatment' => 'included',
                'health_treatment' => 'included',
                'average_earning_treatment' => 'excluded',
                'enforcement_treatment' => 'included',
                'jmhz_treatment' => 'included',
                'statistics_treatment' => 'included',
                'accounting_debit_code' => null,
                'accounting_credit_code' => null,
                'annual_limit_minor' => null,
                'valid_from' => '2026-01-01',
                'valid_to' => null,
                'is_active' => true,
            ]),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $component = PayrollTimeValue::row($this->json($response)['component'] ?? null, 'component');

        return PayrollTimeValue::int($component['id'] ?? null, 'component.id');
    }

    /** @return array{int,int} */
    private function employment(string $code, string $status): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$this->supplierId, 'Syntetická osoba ' . $code]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employee_profiles
                (supplier_id, employee_id, profile_status)
             VALUES (?, ?, "legacy")'
        )->execute([$this->supplierId, $employeeId]);
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, ?, "employment", ?, "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$this->supplierId, $employeeId, $code, $status]);

        return [$employeeId, (int) $pdo->lastInsertId()];
    }

    private function firstId(string $table): int
    {
        if (!in_array($table, ['supplier', 'users'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná testovací tabulka.');
        }
        $stmt = $this->db->pdo()->query("SELECT id FROM {$table} ORDER BY id LIMIT 1");
        if ($stmt === false) {
            throw new \RuntimeException("Tabulku {$table} nelze načíst.");
        }
        $value = $stmt->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    private function request(
        string $method,
        string $uri,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'admin'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    private function errorCode(ResponseInterface $response): string
    {
        $error = PayrollTimeValue::row($this->json($response)['error'] ?? null, 'error');

        return PayrollTimeValue::string($error['code'] ?? null, 'error.code');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return PayrollTimeValue::row(
            json_decode((string) $response->getBody(), true),
            'response',
        );
    }
}
