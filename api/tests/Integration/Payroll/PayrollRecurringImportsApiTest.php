<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollInputImportsAction;
use MyInvoice\Action\Payroll\PayrollInputsAction;
use MyInvoice\Action\Payroll\PayrollRecurringComponentsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollInputImportRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollRecurringImportsApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollRecurringComponentsAction $recurring;
    private PayrollInputImportsAction $imports;
    private PayrollInputsAction $inputs;
    private PayrollInputImportRepository $importRepository;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $employmentId;
    private int $otherEmploymentId;
    private int $regularComponentId;
    private int $oneOffComponentId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        if ($container === null) {
            throw new \RuntimeException('DI kontejner není dostupný.');
        }
        $db = $container->get(Connection::class);
        $recurring = $container->get(PayrollRecurringComponentsAction::class);
        $imports = $container->get(PayrollInputImportsAction::class);
        $inputs = $container->get(PayrollInputsAction::class);
        $importRepository = $container->get(PayrollInputImportRepository::class);
        if (!$db instanceof Connection
            || !$recurring instanceof PayrollRecurringComponentsAction
            || !$imports instanceof PayrollInputImportsAction
            || !$inputs instanceof PayrollInputsAction
            || !$importRepository instanceof PayrollInputImportRepository) {
            throw new \RuntimeException('MZ-08 služby nejsou dostupné.');
        }
        $this->db = $db;
        $this->recurring = $recurring;
        $this->imports = $imports;
        $this->inputs = $inputs;
        $this->importRepository = $importRepository;
        if (!$db->hasColumn('payroll_recurring_components', 'calculation_kind')
            || !$db->hasColumn('payroll_input_imports', 'duplicate_count')) {
            $this->markTestSkipped('Migrace 1211–1212 neproběhly.');
        }

        $pdo = $db->pdo();
        $sourceSupplierId = $this->firstId($pdo, 'supplier');
        $this->userId = $this->firstId($pdo, 'users');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
        [$this->employeeId, $this->employmentId] = $this->employment(
            $this->supplierId,
            'Syntetická pracovnice',
            'SYN-HPP-1',
        );
        [, $this->otherEmploymentId] = $this->employment(
            $this->otherSupplierId,
            'Cizí syntetický pracovník',
            'OTHER-HPP-1',
        );
        $this->regularComponentId = $this->component(
            $this->supplierId,
            'SYN_REGULAR',
            'regular',
        );
        $this->oneOffComponentId = $this->component(
            $this->supplierId,
            'SYN_IMPORT',
            'one_off',
        );
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

    public function testRecurringMaterializationIsTenantSafeVersionedAndIdempotent(): void
    {
        $created = $this->recurring->create(
            $this->request('POST', '/api/payroll/recurring-components')
                ->withParsedBody($this->recurringPayload()),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $recurring = PayrollTimeValue::row(
            $this->json($created)['recurring_component'] ?? null,
            'recurring_component',
        );
        $recurringId = PayrollTimeValue::int($recurring['id'] ?? null, 'recurring.id');

        $materialized = $this->recurring->materialize(
            $this->request('POST', '/api/payroll/recurring-components/materialize')
                ->withParsedBody(['period' => '2026-06']),
            new Response(),
        );
        self::assertSame(200, $materialized->getStatusCode());
        $first = PayrollTimeValue::row(
            $this->json($materialized)['materialization'] ?? null,
            'materialization',
        );
        self::assertSame(1, $first['created_count']);
        self::assertSame(0, $first['replayed_count']);
        $createdRows = PayrollTimeValue::rows($first['created'] ?? null, 'created');
        self::assertSame(150001, $createdRows[0]['amount_minor']);

        $replay = $this->recurring->materialize(
            $this->request('POST', '/api/payroll/recurring-components/materialize')
                ->withParsedBody(['period' => '2026-06']),
            new Response(),
        );
        $replayBody = PayrollTimeValue::row(
            $this->json($replay)['materialization'] ?? null,
            'materialization',
        );
        self::assertSame(0, $replayBody['created_count']);
        self::assertSame(1, $replayBody['replayed_count']);

        $input = $this->db->pdo()->prepare(
            'SELECT source_snapshot_json, source_snapshot_hash
               FROM payroll_inputs
              WHERE supplier_id = ? AND recurring_component_id = ?'
        );
        $input->execute([$this->supplierId, $recurringId]);
        $snapshot = $input->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($snapshot);
        self::assertNotEmpty($snapshot['source_snapshot_json']);
        self::assertIsString($snapshot['source_snapshot_hash']);
        self::assertSame(32, strlen($snapshot['source_snapshot_hash']));

        $stale = $this->recurring->update(
            $this->request('PUT', '/api/payroll/recurring-components/' . $recurringId)
                ->withParsedBody([
                    ...$this->recurringPayload(),
                    'row_version' => 999,
                ]),
            new Response(),
            ['id' => (string) $recurringId],
        );
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('row_version_conflict', $this->errorCode($stale));

        $foreignPayload = [
            ...$this->recurringPayload(),
            'employment_id' => $this->otherEmploymentId,
        ];
        $foreign = $this->recurring->create(
            $this->request('POST', '/api/payroll/recurring-components')
                ->withParsedBody($foreignPayload),
            new Response(),
        );
        self::assertSame(422, $foreign->getStatusCode());

        $bearer = $this->recurring->materialize(
            $this->request(
                'POST',
                '/api/payroll/recurring-components/materialize',
                'bearer',
            )->withParsedBody(['period' => '2026-06']),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->errorCode($bearer));

        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET status = "ended", end_date = "2026-06-30"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);
        $afterEnd = $this->recurring->materialize(
            $this->request('POST', '/api/payroll/recurring-components/materialize')
                ->withParsedBody(['period' => '2026-07']),
            new Response(),
        );
        $afterEndBody = PayrollTimeValue::row(
            $this->json($afterEnd)['materialization'] ?? null,
            'materialization',
        );
        self::assertSame(0, $afterEndBody['created_count']);
        self::assertSame(0, $afterEndBody['replayed_count']);
    }

    public function testRecurringComponentCanBeCreatedForLegacyEmploymentWithoutStartDate(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = NULL, actual_start_date = NULL,
                    is_legacy_projection = 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $created = $this->recurring->create(
            $this->request('POST', '/api/payroll/recurring-components')
                ->withParsedBody($this->recurringPayload()),
            new Response(),
        );

        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());

        $materialized = $this->recurring->materialize(
            $this->request('POST', '/api/payroll/recurring-components/materialize')
                ->withParsedBody(['period' => '2026-06']),
            new Response(),
        );
        self::assertSame(
            200,
            $materialized->getStatusCode(),
            (string) $materialized->getBody(),
        );
        $result = PayrollTimeValue::row(
            $this->json($materialized)['materialization'] ?? null,
            'materialization',
        );
        self::assertSame(1, $result['created_count']);
    }

    public function testFullMonthRecurringAmountRequiresReviewForPartialEmploymentMonth(): void
    {
        $created = $this->recurring->create(
            $this->request('POST', '/api/payroll/recurring-components')
                ->withParsedBody([
                    ...$this->recurringPayload(),
                    'amount_minor' => 300_000,
                    'valid_from' => '2026-01-01',
                    'allocation_rule' => 'full_month',
                ]),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = "2026-06-15", actual_start_date = "2026-06-15"
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $materialized = $this->recurring->materialize(
            $this->request('POST', '/api/payroll/recurring-components/materialize')
                ->withParsedBody(['period' => '2026-06']),
            new Response(),
        );
        self::assertSame(
            200,
            $materialized->getStatusCode(),
            (string) $materialized->getBody(),
        );
        $result = PayrollTimeValue::row(
            $this->json($materialized)['materialization'] ?? null,
            'materialization',
        );

        self::assertSame(0, $result['created_count']);
        self::assertSame(1, $result['manual_review_count']);
        self::assertStringContainsString(
            'část měsíce',
            PayrollTimeValue::string(
                PayrollTimeValue::rows(
                    $result['manual_review'] ?? null,
                    'manual_review',
                )[0]['reason'] ?? null,
                'reason',
            ),
        );
    }

    public function testRecurringComponentRejectsOrdinaryEmploymentWithoutStartDate(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments
                SET start_date = NULL, actual_start_date = NULL,
                    is_legacy_projection = 0
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $this->employmentId]);

        $created = $this->recurring->create(
            $this->request('POST', '/api/payroll/recurring-components')
                ->withParsedBody($this->recurringPayload()),
            new Response(),
        );

        self::assertSame(422, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame('validation_failed', $this->errorCode($created));
    }

    public function testCsvDryRunPartialApplyDedupeAndTenantIsolation(): void
    {
        $csv = implode("\n", [
            'employment_id;employment_code;component_code;amount_minor;external_id;source_period',
            "{$this->employmentId};SYN-HPP-1;SYN_IMPORT;25000;csv-1;",
            "{$this->otherEmploymentId};OTHER-HPP-1;SYN_IMPORT;10000;foreign-1;",
            "{$this->employmentId};SYN-HPP-1;SYN_IMPORT;necislo;bad-1;",
            "{$this->employmentId};SYN-HPP-1;SYN_IMPORT;30000;csv-1;",
        ]);
        $before = $this->countRows('payroll_input_imports', $this->supplierId);
        $preview = $this->imports->preview(
            $this->importRequest('preview.csv', 'csv', $csv),
            new Response(),
        );
        self::assertSame(200, $preview->getStatusCode(), (string) $preview->getBody());
        $previewBody = PayrollTimeValue::row(
            $this->json($preview)['preview'] ?? null,
            'preview',
        );
        self::assertSame(4, $previewBody['row_count']);
        self::assertSame(1, $previewBody['accepted_count']);
        self::assertSame(2, $previewBody['rejected_count']);
        self::assertSame(1, $previewBody['duplicate_count']);
        self::assertSame(
            $before,
            $this->countRows('payroll_input_imports', $this->supplierId),
        );

        $applied = $this->imports->apply(
            $this->importRequest('preview.csv', 'csv', $csv),
            new Response(),
        );
        self::assertSame(201, $applied->getStatusCode(), (string) $applied->getBody());
        $import = PayrollTimeValue::row(
            $this->json($applied)['import'] ?? null,
            'import',
        );
        self::assertSame('partial', $import['status']);
        self::assertSame(1, $import['accepted_count']);
        self::assertSame(2, $import['rejected_count']);
        self::assertSame(1, $import['duplicate_count']);
        self::assertFalse($import['replayed']);
        $importRows = PayrollTimeValue::rows($import['rows'] ?? null, 'rows');
        self::assertCount(4, $importRows);
        $acceptedRow = array_values(array_filter(
            $importRows,
            static fn (array $row): bool => ($row['status'] ?? null) === 'accepted',
        ))[0];
        $acceptedInputId = PayrollTimeValue::int(
            $acceptedRow['input_id'] ?? null,
            'input_id',
        );
        $approved = $this->inputs->approve(
            $this->request(
                'POST',
                '/api/payroll/inputs/' . $acceptedInputId . '/approve',
            )->withParsedBody(['row_version' => 1]),
            new Response(),
            ['id' => (string) $acceptedInputId],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        $approvedInput = PayrollTimeValue::row(
            $this->json($approved)['input'] ?? null,
            'approved_input',
        );
        self::assertSame(
            'approved',
            $approvedInput['status'] ?? null,
        );
        self::assertNull($this->importRepository->detail(
            $this->otherSupplierId,
            PayrollTimeValue::int($import['id'] ?? null, 'import_id'),
        ));

        $replayed = $this->imports->apply(
            $this->importRequest('renamed.csv', 'csv', $csv),
            new Response(),
        );
        $replayedImport = PayrollTimeValue::row(
            $this->json($replayed)['import'] ?? null,
            'import',
        );
        self::assertTrue($replayedImport['replayed']);
        self::assertSame($import['id'], $replayedImport['id']);
        self::assertSame(
            1,
            $this->countRows('payroll_inputs', $this->supplierId, 'import'),
        );
    }

    public function testXlsxDryRunAndApplyUseSameValidatedContract(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            [
                'employment_id',
                'employment_code',
                'component_code',
                'amount_minor',
                'external_id',
            ],
            [$this->employmentId, 'SYN-HPP-1', 'SYN_IMPORT', 45000, 'xlsx-1'],
        ]);
        $xlsx = $this->xlsx($spreadsheet);

        $preview = $this->imports->preview(
            $this->importRequest('synthetic.xlsx', 'xlsx', $xlsx),
            new Response(),
        );
        self::assertSame(200, $preview->getStatusCode(), (string) $preview->getBody());
        $body = PayrollTimeValue::row(
            $this->json($preview)['preview'] ?? null,
            'preview',
        );
        self::assertSame(1, $body['accepted_count']);
        self::assertSame(0, $body['rejected_count']);

        $applied = $this->imports->apply(
            $this->importRequest('synthetic.xlsx', 'xlsx', $xlsx),
            new Response(),
        );
        $import = PayrollTimeValue::row(
            $this->json($applied)['import'] ?? null,
            'import',
        );
        self::assertSame('xlsx', $import['source_kind']);
        self::assertSame('accepted', $import['status']);
        self::assertSame(1, $import['accepted_count']);
    }

    public function testDuplicateOnlyImportIsPartialAndFormulaLikeExternalIdIsRejected(): void
    {
        $duplicateCsv = implode("\n", [
            'employment_id;employment_code;component_code;amount_minor;external_id',
            "{$this->employmentId};SYN-HPP-1;SYN_IMPORT;15000;duplicate-only",
            "{$this->employmentId};SYN-HPP-1;SYN_IMPORT;16000;duplicate-only",
        ]);
        $applied = $this->imports->apply(
            $this->importRequest('duplicates.csv', 'csv', $duplicateCsv),
            new Response(),
        );
        $import = PayrollTimeValue::row(
            $this->json($applied)['import'] ?? null,
            'import',
        );
        self::assertSame('partial', $import['status']);
        self::assertSame(1, $import['accepted_count']);
        self::assertSame(0, $import['rejected_count']);
        self::assertSame(1, $import['duplicate_count']);

        $unsafeCsv = implode("\n", [
            'employment_id;employment_code;component_code;amount_minor;external_id',
            "{$this->employmentId};SYN-HPP-1;SYN_IMPORT;17000;-unsafe-formula",
        ]);
        $preview = $this->imports->preview(
            $this->importRequest('unsafe.csv', 'csv', $unsafeCsv),
            new Response(),
        );
        $previewBody = PayrollTimeValue::row(
            $this->json($preview)['preview'] ?? null,
            'preview',
        );
        self::assertSame(0, $previewBody['accepted_count']);
        self::assertSame(1, $previewBody['rejected_count']);
    }

    public function testSystemSourceInputsRequireTheirSourceProtocol(): void
    {
        $sql = 'INSERT INTO payroll_inputs
                    (supplier_id, employee_id, employment_id, component_id,
                     period_start, amount_minor, source_kind, external_id)
                VALUES (?, ?, ?, ?, "2026-08-01", 1000, ?, ?)';
        $stmt = $this->db->pdo()->prepare($sql);

        foreach ([
            ['recurring', 'missing-recurring-snapshot'],
            ['import', 'missing-import-protocol'],
        ] as [$sourceKind, $externalId]) {
            try {
                $stmt->execute([
                    $this->supplierId,
                    $this->employeeId,
                    $this->employmentId,
                    $this->oneOffComponentId,
                    $sourceKind,
                    $externalId,
                ]);
                self::fail("Zdroj {$sourceKind} prošel bez povinného protokolu.");
            } catch (\PDOException $e) {
                self::assertSame('23000', (string) $e->getCode());
            }
        }
    }

    public function testForeignKeyFailureIsNotReportedAsDuplicateImportRow(): void
    {
        try {
            $this->importRepository->store(
                $this->supplierId,
                '2026-09-01',
                'csv',
                'synthetic-fk-race.csv',
                hash('sha256', 'synthetic-fk-race', true),
                [[
                    'row_number' => 2,
                    'payload' => [
                        'employee_id' => $this->employeeId,
                        'employment_id' => $this->otherEmploymentId,
                        'component_id' => $this->oneOffComponentId,
                        'component_code' => 'SYN_IMPORT',
                        'period_start' => '2026-09-01',
                        'source_period_start' => null,
                        'amount_minor' => 10_000,
                        'quantity_milliunits' => null,
                        'source_kind' => 'import',
                        'external_id' => 'synthetic-fk-race',
                    ],
                    'impact' => [],
                ]],
                [],
                [],
                $this->userId,
            );
            self::fail('Porušení cizího klíče se nesmí vykázat jako duplicita.');
        } catch (\PDOException $e) {
            self::assertSame('23000', (string) $e->getCode());
        }
    }

    public function testImportRowsShareProvisionalAnnualBenefitLimit(): void
    {
        $this->component(
            $this->supplierId,
            'SYN_BENEFIT',
            'one_off',
            'benefit_health',
            100_000,
        );
        $csv = implode("\n", [
            'employment_id;employment_code;component_code;amount_minor;external_id',
            "{$this->employmentId};SYN-HPP-1;SYN_BENEFIT;60000;benefit-1",
            "{$this->employmentId};SYN-HPP-1;SYN_BENEFIT;60000;benefit-2",
        ]);

        $preview = $this->imports->preview(
            $this->importRequest('benefit-limit.csv', 'csv', $csv),
            new Response(),
        );
        $body = PayrollTimeValue::row(
            $this->json($preview)['preview'] ?? null,
            'preview',
        );

        self::assertSame(1, $body['accepted_count']);
        self::assertSame(1, $body['rejected_count']);
    }

    /** @return array<string,mixed> */
    private function recurringPayload(): array
    {
        return [
            'employment_id' => $this->employmentId,
            'component_id' => $this->regularComponentId,
            'calculation_kind' => 'fixed_amount',
            'amount_minor' => 300001,
            'rate_basis_points' => null,
            'valid_from' => '2026-06-16',
            'valid_to' => null,
            'allocation_rule' => 'calendar_days',
            'maximum_amount_minor' => null,
            'note' => 'Syntetický předpis',
            'is_active' => true,
        ];
    }

    private function importRequest(
        string $sourceName,
        string $format,
        string $content,
    ): \Psr\Http\Message\ServerRequestInterface {
        return $this->request('POST', '/api/payroll/input-imports')->withParsedBody([
            'period' => '2026-06',
            'format' => $format,
            'source_name' => $sourceName,
            'content_base64' => base64_encode($content),
        ]);
    }

    /** @return array{0:int,1:int} */
    private function employment(int $supplierId, string $name, string $code): array
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 40000, 0, 1)'
        )->execute([$supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active",
                     "2026-01-01", "2026-01-01", 4000000, 0)'
        )->execute([$supplierId, $employeeId, $code]);
        return [$employeeId, (int) $pdo->lastInsertId()];
    }

    private function component(
        int $supplierId,
        string $code,
        string $frequency,
        string $kind = 'bonus',
        ?int $annualLimitMinor = null,
    ): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment,
                 enforcement_treatment, jmhz_treatment,
                 statistics_treatment, annual_limit_minor, valid_from)
             VALUES (?, ?, "Syntetická složka", ?, "monetary",
                     ?, "included", "included", "included", "included",
                     "included", "included", "included", "included",
                     "included", ?, "2026-01-01")'
        );
        $stmt->execute([
            $supplierId,
            $code,
            $kind,
            $frequency,
            $annualLimitMinor,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function request(
        string $method,
        string $uri,
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'admin'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
    }

    private function firstId(PDO $pdo, string $table): int
    {
        if (!in_array($table, ['supplier', 'users'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná testovací tabulka.');
        }
        $stmt = $pdo->query("SELECT id FROM {$table} ORDER BY id LIMIT 1");
        if ($stmt === false) {
            throw new \RuntimeException("Nelze načíst testovací ID z {$table}.");
        }
        $value = $stmt->fetchColumn();
        return $value === false || $value === null
            ? 0
            : PayrollTimeValue::int($value, "{$table}.id");
    }

    private function countRows(
        string $table,
        int $supplierId,
        ?string $sourceKind = null,
    ): int {
        if (!in_array($table, ['payroll_input_imports', 'payroll_inputs'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná testovací tabulka.');
        }
        $sql = "SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?";
        $params = [$supplierId];
        if ($sourceKind !== null) {
            $sql .= ' AND source_kind = ?';
            $params[] = $sourceKind;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return PayrollTimeValue::int($stmt->fetchColumn(), 'count');
    }

    private function xlsx(Spreadsheet $spreadsheet): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'payroll-input-integration-');
        if ($tmp === false) {
            throw new \RuntimeException('Nelze vytvořit syntetický XLSX.');
        }
        try {
            (new Xlsx($spreadsheet))->save($tmp);
            $content = file_get_contents($tmp);
            if ($content === false) {
                throw new \RuntimeException('Syntetický XLSX nelze načíst.');
            }
            return $content;
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($tmp);
        }
    }

    private function errorCode(ResponseInterface $response): string
    {
        $error = PayrollTimeValue::row(
            $this->json($response)['error'] ?? null,
            'error',
        );
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
