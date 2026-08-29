<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\TaxStatementAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollTaxStatementRepository;
use MyInvoice\Service\Payroll\TaxStatement\TaxStatementService;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Ověřuje SQL podkladu obou vyúčtování proti skutečnému schématu — včetně
 * dotazů, které v testu vracejí prázdno (odvedené platby, místa výkonu práce,
 * nerezidenti). Prázdný výsledek je taky odpověď; neplatný JOIN by se jinak
 * projevil až u zákazníka.
 */
#[Group('integration')]
final class PayrollTaxStatementTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private TaxStatementService $service;
    private PayrollTaxStatementRepository $repository;
    private int $supplierId;
    private int $otherSupplierId;
    private int $userId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(TaxStatementService::class);
            $this->repository = $container->get(PayrollTaxStatementRepository::class);
        } catch (\Throwable $exception) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $exception->getMessage());
        }
        foreach ([
            'payroll_runs',
            'payroll_run_revisions',
            'payroll_statutory_results',
            'payroll_annual_settlement_outcomes',
            'payroll_payment_matches',
            'payroll_employment_terms',
            'payroll_person_tax_residences',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Tabulka {$table} není dostupná.");
            }
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')
            ->fetchColumn() ?: 0);
        if ($sourceSupplierId <= 0 || $this->userId <= 0) {
            $this->markTestSkipped('Chybí syntetický zdroj firmy nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare(
            'UPDATE supplier SET payroll_enabled = 1, financial_office_code = "451",
                    taxpayer_type = "po", dic = "CZ12345678"
              WHERE id IN (?, ?)',
        )->execute([$this->supplierId, $this->otherSupplierId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testPreviewAggregatesFrozenResultsAndStaysInsideTheTenant(): void
    {
        $this->seedMonth($this->supplierId, '2025-01-01', 4, 48_200_00, 1_260_00, 900_00);
        $this->seedMonth($this->supplierId, '2025-02-01', 4, 48_200_00, 1_260_00, 900_00);
        // Druhá firma se stejným obdobím — do výsledku se nesmí dostat.
        $this->seedMonth($this->otherSupplierId, '2025-01-01', 9, 99_000_00, 0, 0);

        $preview = $this->service->preview($this->supplierId, 2025);
        $dpz = $preview['dpzvd6'];
        $dps = $preview['dpsvd2'];

        self::assertSame(2025, $dpz['year']);
        self::assertCount(2, $dpz['months']);
        self::assertSame(1, $dpz['months'][0]['month']);
        self::assertSame(4, $dpz['months'][0]['headcount']);
        self::assertSame(48_200, $dpz['months'][0]['advance_due']);
        self::assertSame(1_260, $dpz['months'][0]['bonus_paid']);
        // Sl. 9 = sl. 1 − sl. 3 − sl. 4 − sl. 5; nic zaplaceno není, sl. 11 = 0.
        self::assertSame(46_940, $dpz['months'][0]['settled_amount']);
        self::assertSame(0, $dpz['months'][0]['remitted']);
        self::assertSame(96_400, $dpz['total']['advance_due']);

        self::assertCount(2, $dps['months']);
        self::assertSame(900_00, $dps['months'][0]['tax_due_minor']);
        self::assertSame(1_800_00, $dps['total']['tax_due_minor']);
        self::assertSame('772', $dps['income_kind']);

        // Deset měsíců bez schváleného běhu musí být vidět, ne tiše zmizet.
        self::assertNotEmpty(array_filter(
            $dpz['warnings'],
            static fn (string $warning): bool
                => str_contains($warning, 'nemají schválený mzdový běh'),
        ));

        $other = $this->service->preview($this->otherSupplierId, 2025);
        self::assertSame(99_000, $other['dpzvd6']['total']['advance_due']);
    }

    public function testBuildProducesSchemaValidXmlForBothForms(): void
    {
        $this->seedMonth($this->supplierId, '2025-06-01', 2, 12_000_00, 0, 450_00);

        $validator = new XmlSchemaValidator();
        foreach (TaxStatementService::FORMS as $formCode) {
            if (!$validator->hasSchema($formCode)) {
                self::markTestSkipped('XSD ' . $formCode . '.xsd není k dispozici.');
            }
            $built = $this->service->build($this->supplierId, 2025, $formCode);
            $validation = $validator->validate($built['xml'], $formCode);
            self::assertSame(
                'passed',
                $validation['status'],
                $formCode . ' XSD chyby: ' . implode(' | ', $validation['errors']),
            );
        }
    }

    public function testSupportingQueriesRunAgainstTheRealSchema(): void
    {
        self::assertSame(
            [],
            $this->repository->remittedTaxTotals($this->supplierId, 2025),
        );
        self::assertSame(
            [],
            $this->repository->annualSettlementPayouts($this->supplierId, 2025),
        );
        self::assertSame(
            [],
            $this->repository->workplaceHeadcount($this->supplierId, '2025-12-01'),
        );
        self::assertSame(
            0,
            $this->repository->nonResidentEmployeeCount($this->supplierId, 2025),
        );
    }

    public function testYearWithoutApprovedRunsIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->service->preview($this->supplierId, 2025);
    }

    public function testApiNeedsThePermissionAndTheEnabledPayrollModule(): void
    {
        $this->seedMonth($this->supplierId, '2025-04-01', 1, 5_000_00, 0, 0);
        $action = Bootstrap::buildApp()->getContainer()->get(TaxStatementAction::class);

        $forbidden = $action->preview($this->request('staff'), new Response());
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('forbidden', $this->json($forbidden)['error']['code']);

        $allowed = $action->preview($this->request('admin'), new Response());
        self::assertSame(200, $allowed->getStatusCode());
        self::assertSame(2025, $this->json($allowed)['year']);

        $this->db->pdo()->prepare('UPDATE supplier SET payroll_enabled = 0 WHERE id = ?')
            ->execute([$this->supplierId]);
        $disabled = $action->preview($this->request('admin'), new Response());
        self::assertSame(403, $disabled->getStatusCode());
        self::assertSame('payroll_disabled', $this->json($disabled)['error']['code']);
    }

    private function request(string $role): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/payroll/reports/tax-statement/preview')
            ->withQueryParams(['year' => '2025'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role]);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    private function seedMonth(
        int $supplierId,
        string $periodStart,
        int $headcount,
        int $advanceMinor,
        int $bonusMinor,
        int $withholdingMinor,
    ): void {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no,
                 created_by, updated_by)
             VALUES (?, ?, ?, "approved", 1, ?, ?)',
        )->execute([
            $supplierId,
            $periodStart,
            substr($periodStart, 0, 8) . '15',
            $this->userId,
            $this->userId,
        ]);
        $runId = (int) $pdo->lastInsertId();

        $revisionResult = json_encode([
            'schema_version' => 'payroll-run-result.v2',
            'totals' => ['source_amount_minor' => $advanceMinor * 4],
            'people' => array_fill(0, $headcount, []),
        ], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "regular", "approved", "payroll-run-input.v2",
                     ?, "{}", ?, ?, ?, ?, NOW())',
        )->execute([
            $supplierId,
            $runId,
            str_repeat('a', 64),
            hash('sha256', "tax-statement-input:{$supplierId}:{$runId}"),
            $revisionResult,
            hash('sha256', $revisionResult),
            hash('sha256', "tax-statement-revision:{$supplierId}:{$runId}", true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();

        $taxResult = json_encode([
            'status' => 'calculated',
            'advance_tax_minor_units' => $advanceMinor,
            'tax_bonus_minor_units' => $bonusMinor,
            'withholding_tax_minor_units' => $withholdingMinor,
        ], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO payroll_statutory_results
                (supplier_id, revision_id, calculation_kind, schema_version, result_status,
                 ruleset_id, ruleset_hash, input_snapshot_json, input_snapshot_hash,
                 result_snapshot_json, result_snapshot_hash, result_set_hash, created_by)
             VALUES (?, ?, "income_tax", "payroll-income-tax-result.v1", "calculated",
                     "test", ?, "{}", ?, ?, ?, ?, ?)',
        )->execute([
            $supplierId,
            $revisionId,
            str_repeat('b', 64),
            hash('sha256', "tax-statement-statutory-input:{$revisionId}"),
            $taxResult,
            hash('sha256', $taxResult),
            hash('sha256', "tax-statement-statutory-result:{$revisionId}"),
            $this->userId,
        ]);
    }
}
