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
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollComponentsInputsApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollComponentsAction $components;
    private PayrollInputsAction $inputs;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employeeId;
    private int $employmentId;
    private int $otherEmployeeId;
    private int $otherEmploymentId;
    private int $userId;

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
        foreach ([
            'payroll_component_definitions',
            'payroll_inputs',
            'payroll_benefit_accumulators',
            'payroll_runs',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped('Migrace 1210 neproběhla.');
            }
        }
        $this->components = $components;
        $this->inputs = $inputs;

        $pdo = $this->db->pdo();
        $sourceSupplierId = $this->firstId($pdo, 'supplier');
        $this->userId = $this->firstId($pdo, 'users');
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)'
        )->execute([$this->supplierId, $this->otherSupplierId]);

        [$this->employeeId, $this->employmentId] = $this->employment(
            $this->supplierId,
            'Syntetická zaměstnankyně',
            'SYN-COMP-1',
        );
        [$this->otherEmployeeId, $this->otherEmploymentId] = $this->employment(
            $this->otherSupplierId,
            'Cizí syntetický pracovník',
            'SYN-COMP-2',
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

    public function testVersionedCatalogPreviewSnapshotAndAnnualLimit(): void
    {
        $catalogResponse = $this->components->list(
            $this->request('GET', '/api/payroll/components')
                ->withQueryParams(['effective_on' => '2026-06-01']),
            new Response(),
        );
        self::assertSame(200, $catalogResponse->getStatusCode());
        $catalog = $this->json($catalogResponse);
        $catalogComponents = $catalog['components'] ?? null;
        self::assertIsArray($catalogComponents);
        self::assertGreaterThanOrEqual(20, count($catalogComponents));

        $component = $this->createComponent($this->componentPayload(
            code: 'SYN_HEALTH',
            validFrom: '2026-01-01',
            annualLimitMinor: 100_000,
        ));
        $componentId = PayrollTimeValue::int($component['id'] ?? null, 'component_id');
        $firstPayload = $this->inputPayload($componentId, 60_000, 'benefit-1');

        $previewResponse = $this->inputs->preview(
            $this->request('POST', '/api/payroll/inputs/preview')
                ->withParsedBody($firstPayload),
            new Response(),
        );
        self::assertSame(200, $previewResponse->getStatusCode());
        $preview = PayrollTimeValue::row(
            $this->json($previewResponse)['preview'] ?? null,
            'preview',
        );
        $impact = PayrollTimeValue::row($preview['impact'] ?? null, 'impact');
        $cash = PayrollTimeValue::row($impact['cash_payable'] ?? null, 'cash_payable');
        $tax = PayrollTimeValue::row($impact['tax_base'] ?? null, 'tax_base');
        self::assertSame('supported', $preview['support_status']);
        self::assertSame(0, $cash['minor_units']);
        self::assertSame(60_000, $tax['minor_units']);
        self::assertFalse($preview['annual_limit_exceeded']);

        $first = $this->createInput($firstPayload);
        $firstId = PayrollTimeValue::int($first['id'] ?? null, 'input_id');
        $firstVersion = PayrollTimeValue::int(
            $first['row_version'] ?? null,
            'row_version',
        );
        $approvedResponse = $this->inputs->approve(
            $this->request(
                'POST',
                "/api/payroll/inputs/{$firstId}/approve",
            )->withParsedBody(['row_version' => $firstVersion]),
            new Response(),
            ['id' => (string) $firstId],
        );
        self::assertSame(200, $approvedResponse->getStatusCode());
        $approved = PayrollTimeValue::row(
            $this->json($approvedResponse)['input'] ?? null,
            'approved_input',
        );
        self::assertSame('approved', $approved['status']);
        self::assertNotNull($approved['component_snapshot_json']);

        $secondPayload = $this->inputPayload($componentId, 50_000, 'benefit-2');
        $secondPreview = $this->inputs->preview(
            $this->request('POST', '/api/payroll/inputs/preview')
                ->withParsedBody($secondPayload),
            new Response(),
        );
        $secondPreviewBody = PayrollTimeValue::row(
            $this->json($secondPreview)['preview'] ?? null,
            'preview',
        );
        self::assertTrue($secondPreviewBody['annual_limit_exceeded']);
        $second = $this->createInput($secondPayload);
        $secondId = PayrollTimeValue::int($second['id'] ?? null, 'input_id');
        $secondVersion = PayrollTimeValue::int(
            $second['row_version'] ?? null,
            'row_version',
        );
        $limitResponse = $this->inputs->approve(
            $this->request(
                'POST',
                "/api/payroll/inputs/{$secondId}/approve",
            )->withParsedBody(['row_version' => $secondVersion]),
            new Response(),
            ['id' => (string) $secondId],
        );
        self::assertSame(409, $limitResponse->getStatusCode());
        self::assertSame(
            'benefit_limit_exceeded',
            $this->errorCode($limitResponse),
        );

        $componentVersion = PayrollTimeValue::int(
            $component['row_version'] ?? null,
            'row_version',
        );
        $inUseResponse = $this->components->update(
            $this->request(
                'PUT',
                "/api/payroll/components/{$componentId}",
            )->withParsedBody([
                ...$this->componentPayload(
                    code: 'SYN_HEALTH',
                    validFrom: '2026-01-01',
                    annualLimitMinor: 120_000,
                ),
                'row_version' => $componentVersion,
            ]),
            new Response(),
            ['id' => (string) $componentId],
        );
        self::assertSame(409, $inUseResponse->getStatusCode());
        self::assertSame('component_in_use', $this->errorCode($inUseResponse));

        $next = $this->createComponent($this->componentPayload(
            code: 'SYN_HEALTH',
            validFrom: '2027-01-01',
            annualLimitMinor: 120_000,
        ));
        self::assertNotSame(
            $componentId,
            PayrollTimeValue::int($next['id'] ?? null, 'component_id'),
        );
        $stmt = $this->db->pdo()->prepare(
            'SELECT valid_to
               FROM payroll_component_definitions
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$this->supplierId, $componentId]);
        self::assertSame('2026-12-31', $stmt->fetchColumn());

        $snapshot = json_decode(PayrollTimeValue::string(
            $approved['component_snapshot_json'],
            'component_snapshot_json',
        ), true);
        self::assertIsArray($snapshot);
        self::assertSame(100_000, $snapshot['annual_limit_minor']);
    }

    public function testDefaultComponentCodesAreCzech(): void
    {
        $response = $this->components->list(
            $this->request('GET', '/api/payroll/components')
                ->withQueryParams(['effective_on' => '2026-06-01']),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        $components = $this->json($response)['components'] ?? null;
        self::assertIsArray($components);
        $codes = array_column($components, 'code');
        sort($codes);

        self::assertSame([
            'CESTOVNI_NAHRADA',
            'CESTOVNI_NAHRADA_LIMIT',
            'CESTOVNI_NAHRADA_NADLIMIT',
            'DOPLATEK_MZDY',
            'MZDA_HODINOVA',
            'MZDA_MESICNI',
            'MZDA_UKOLOVA',
            'NAHRADA_KONKURENCNI_DOLOZKA',
            'NAHRADA_MZDY',
            'NAHRADA_MZDY_DPN',
            'NEPENEZNI_PRIJEM',
            'ODMENA',
            'ODSTUPNE',
            'PRECHODNE_UBYTOVANI',
            'PREMIE_PRIPLATKY',
            'PRISPEVEK_DLOUHODOBA_PECE',
            'PRISPEVEK_PENZE_ZIVOTNI',
            'PRISPEVEK_RIZIKOVE_SPORENI',
            'PRISPEVEK_STRAVOVANI',
            'PROVIZE',
            'REKREACE_VOLNY_CAS',
            'SOUKROME_VOZIDLO',
            'VZDELAVANI',
            'ZDRAVOTNI_BENEFIT',
        ], $codes);
    }

    public function testApprovalFreezesExemptionBasisInComponentSnapshot(): void
    {
        $component = $this->createComponent([
            ...$this->componentPayload(
                code: 'SYN_EXEMPT',
                validFrom: '2026-01-01',
                annualLimitMinor: null,
                kind: 'benefit_health',
                valueKind: 'non_monetary',
            ),
            'tax_treatment' => 'exempt',
            'social_participation_treatment' => 'excluded',
            'social_treatment' => 'excluded',
            'health_participation_treatment' => 'excluded',
            'health_treatment' => 'excluded',
            'exemption_basis' => 'not_subject_to_tax',
        ]);
        $input = $this->createInput($this->inputPayload(
            PayrollTimeValue::int($component['id'] ?? null, 'component_id'),
            12_345,
            'exemption-basis-1',
        ));
        $inputId = PayrollTimeValue::int($input['id'] ?? null, 'input_id');

        $response = $this->inputs->approve(
            $this->request('POST', "/api/payroll/inputs/{$inputId}/approve")
                ->withParsedBody([
                    'row_version' => PayrollTimeValue::int(
                        $input['row_version'] ?? null,
                        'row_version',
                    ),
                ]),
            new Response(),
            ['id' => (string) $inputId],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $approved = PayrollTimeValue::row(
            $this->json($response)['input'] ?? null,
            'approved_input',
        );
        $snapshot = json_decode(PayrollTimeValue::string(
            $approved['component_snapshot_json'] ?? null,
            'component_snapshot_json',
        ), true);
        self::assertIsArray($snapshot);
        self::assertSame('not_subject_to_tax', $snapshot['exemption_basis'] ?? null);
    }

    public function testDedupeTenantIsolationOptimisticLockAndSessionOnly(): void
    {
        $component = $this->createComponent($this->componentPayload(
            code: 'SYN_BONUS',
            validFrom: '2026-01-01',
            annualLimitMinor: null,
            kind: 'bonus',
            valueKind: 'monetary',
        ));
        $componentId = PayrollTimeValue::int($component['id'] ?? null, 'component_id');
        $payload = $this->inputPayload($componentId, 25_000, 'bonus-1');
        $input = $this->createInput($payload);
        $inputId = PayrollTimeValue::int($input['id'] ?? null, 'input_id');

        $duplicate = $this->inputs->create(
            $this->request('POST', '/api/payroll/inputs')->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(422, $duplicate->getStatusCode());

        $stale = $this->inputs->update(
            $this->request(
                'PUT',
                "/api/payroll/inputs/{$inputId}",
            )->withParsedBody([...$payload, 'row_version' => 999]),
            new Response(),
            ['id' => (string) $inputId],
        );
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('row_version_conflict', $this->errorCode($stale));

        $foreign = [
            ...$payload,
            'employee_id' => $this->otherEmployeeId,
            'employment_id' => $this->otherEmploymentId,
            'external_id' => 'foreign-1',
        ];
        $foreignPreview = $this->inputs->preview(
            $this->request('POST', '/api/payroll/inputs/preview')
                ->withParsedBody($foreign),
            new Response(),
        );
        self::assertSame(422, $foreignPreview->getStatusCode());

        $bearer = $this->components->list(
            $this->request(
                'GET',
                '/api/payroll/components',
                authMethod: 'bearer',
            ),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->errorCode($bearer));
    }

    public function testApprovalOnlyRoleCanApproveDraftInput(): void
    {
        $component = $this->createComponent($this->componentPayload(
            code: 'SYN_APPROVAL',
            validFrom: '2026-01-01',
            annualLimitMinor: null,
            kind: 'bonus',
            valueKind: 'monetary',
        ));
        $input = $this->createInput($this->inputPayload(
            PayrollTimeValue::int($component['id'] ?? null, 'component_id'),
            25_000,
            'approval-only-1',
        ));
        $inputId = PayrollTimeValue::int($input['id'] ?? null, 'input_id');
        $request = $this->request('POST', "/api/payroll/inputs/{$inputId}/approve")
            ->withAttribute('auth.effective_role', new EffectiveRole(
                42,
                'Schvalovatel mezd',
                'staff',
                true,
                ['payroll.approve' => AccessLevel::WRITE->value],
            ))
            ->withParsedBody([
                'row_version' => PayrollTimeValue::int(
                    $input['row_version'] ?? null,
                    'row_version',
                ),
            ]);

        $response = $this->inputs->approve(
            $request,
            new Response(),
            ['id' => (string) $inputId],
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            'approved',
            PayrollTimeValue::row(
                $this->json($response)['input'] ?? null,
                'approved_input',
            )['status'],
        );
    }

    /**
     * Zúžení mzdových vstupů na jeden vztah musí platit i za koncem stránky.
     *
     * Dokud zužoval prohlížeč nad načtenou stránkou, vypadalo zúžení na vztah
     * z jiné strany jako „ten člověk v měsíci nic nemá". Druhý vztah patří
     * osobě, která se v řazení podle jména propadne na konec, a ptáme se na
     * první stránku o jednom řádku.
     */
    public function testInputNarrowingReachesAnEmploymentBeyondTheFirstPage(): void
    {
        $component = $this->createComponent($this->componentPayload(
            code: 'SYN_FOCUS',
            validFrom: '2026-01-01',
            annualLimitMinor: null,
            kind: 'bonus',
            valueKind: 'monetary',
        ));
        $componentId = PayrollTimeValue::int($component['id'] ?? null, 'component_id');
        $this->createInput($this->inputPayload($componentId, 11_000, 'focus-first'));

        [$secondEmployeeId, $secondEmploymentId] = $this->employment(
            $this->supplierId,
            'Zúžený syntetik',
            'SYN-COMP-FOCUS',
        );
        $offPage = $this->createInput([
            ...$this->inputPayload($componentId, 22_000, 'focus-second'),
            'employee_id' => $secondEmployeeId,
            'employment_id' => $secondEmploymentId,
        ]);
        $offPageId = PayrollTimeValue::int($offPage['id'] ?? null, 'input_id');

        $firstPage = $this->listInputs(['limit' => '1']);
        self::assertNotContains(
            $offPageId,
            $this->inputIds($firstPage),
            'Předpoklad testu: hledaný vstup na první stránce být nesmí.',
        );

        $narrowed = $this->listInputs([
            'limit' => '1',
            'employment_id' => (string) $secondEmploymentId,
        ]);

        self::assertSame([$offPageId], $this->inputIds($narrowed));
        self::assertSame(1, $narrowed['total'], 'Total musí být zúžený stejně jako stránka.');
        self::assertSame($secondEmploymentId, $narrowed['employment_id']);

        $blind = $this->listInputs([
            'employment_id' => (string) ($secondEmploymentId + 10_000),
        ]);
        self::assertSame([], $this->inputIds($blind), 'Slepé zúžení nesmí vrátit celý měsíc.');
        self::assertSame(0, $blind['total']);
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function listInputs(array $query): array
    {
        $response = $this->inputs->list(
            $this->request('GET', '/api/payroll/inputs')
                ->withQueryParams(['period' => '2026-06', ...$query]),
            new Response(),
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<int>
     */
    private function inputIds(array $payload): array
    {
        $ids = [];
        foreach (PayrollTimeValue::rows((array) $payload['inputs'], 'inputs') as $input) {
            $ids[] = PayrollTimeValue::int($input['id'] ?? null, 'input.id');
        }

        return $ids;
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
             VALUES (?, ?, "employee", "hpp", 1, 1, 0, 42000, 0, 1)'
        )->execute([$supplierId, $name]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 start_date, actual_start_date, monthly_gross_minor,
                 is_legacy_projection)
             VALUES (?, ?, ?, "employment", "active",
                     "2026-01-01", "2026-01-01", 4200000, 0)'
        )->execute([$supplierId, $employeeId, $code]);
        return [$employeeId, (int) $pdo->lastInsertId()];
    }

    private function firstId(PDO $pdo, string $table): int
    {
        if (!in_array($table, ['supplier', 'users'], true)) {
            throw new \InvalidArgumentException('Nepodporovaná testovací tabulka.');
        }
        $stmt = $pdo->query("SELECT id FROM {$table} ORDER BY id LIMIT 1");
        if ($stmt === false) {
            throw new \RuntimeException("Tabulku {$table} nelze načíst.");
        }
        $value = $stmt->fetchColumn();
        return $value === false ? 0 : PayrollTimeValue::int($value, "{$table}.id");
    }

    /**
     * @return array<string,mixed>
     */
    private function componentPayload(
        string $code,
        string $validFrom,
        ?int $annualLimitMinor,
        string $kind = 'benefit_health',
        string $valueKind = 'non_monetary',
    ): array {
        return [
            'code' => $code,
            'name' => 'Syntetická složka',
            'component_kind' => $kind,
            'value_kind' => $valueKind,
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
            'annual_limit_minor' => $annualLimitMinor,
            'valid_from' => $validFrom,
            'valid_to' => null,
            'is_active' => true,
        ];
    }

    /** @return array<string,mixed> */
    private function inputPayload(int $componentId, int $amountMinor, string $externalId): array
    {
        return [
            'employee_id' => $this->employeeId,
            'employment_id' => $this->employmentId,
            'component_id' => $componentId,
            'period' => '2026-06',
            'source_period' => null,
            'amount_minor' => $amountMinor,
            'quantity_milliunits' => null,
            'source_kind' => 'manual',
            'external_id' => $externalId,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function createComponent(array $payload): array
    {
        $response = $this->components->create(
            $this->request('POST', '/api/payroll/components')
                ->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        return PayrollTimeValue::row(
            $this->json($response)['component'] ?? null,
            'component',
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function createInput(array $payload): array
    {
        $response = $this->inputs->create(
            $this->request('POST', '/api/payroll/inputs')
                ->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        return PayrollTimeValue::row(
            $this->json($response)['input'] ?? null,
            'input',
        );
    }

    private function request(
        string $method,
        string $uri,
        string $authMethod = 'session',
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withAttribute(
                SupplierScopeMiddleware::ATTR_CURRENT_ID,
                $this->supplierId,
            )
            ->withAttribute(
                AuthMiddleware::ATTR_USER,
                ['id' => $this->userId, 'role' => 'admin'],
            )
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
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
        $decoded = json_decode((string) $response->getBody(), true);
        return PayrollTimeValue::row($decoded, 'response');
    }
}
