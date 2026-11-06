<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\NonprofitTaxPagedAssessmentRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentReadService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

final class J6AssessmentReadHeaderTest extends TestCase
{
    use IsolatedSupplierTrait;

    private PDO $pdo;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->pdo = $container->get(Connection::class)->pdo();
        self::assertSame((string) getenv('MYINVOICE_DB_NAME'), (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testLegacyHeadMetadataAndCurrentSourceRemainReadableWithoutAnnualGraphConversion(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->prepare("UPDATE supplier SET accounting_mode='double_entry' WHERE id=?")->execute([$supplier]);
        $period = (new AccountingPeriodRepository($db))->create($supplier, 2026, '2026-01-01', '2026-12-31');
        $this->pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework)
            VALUES(?,'2026-01-01','cz_504')")->execute([$supplier]);
        (new ChartOfAccountsSeeder($db))->seedForSupplier($supplier, '2026-01-01');
        $inputHash = hash('sha256', 'legacy-source-' . $supplier);
        $stmt = $this->pdo->prepare('INSERT INTO nonprofit_tax_assessments
            (supplier_id,period_id,revision_no,status,reason,ruleset,storage_version,input_hash,request_hash,idempotency_key,
             snapshot_json,assessment_json,result_json,created_by)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$supplier, $period, 1, 'confirmed', 'Syntetický legacy záznam', 'legacy-rules', 'legacy_json_v1',
            $inputHash, hash('sha256', 'legacy-request-' . $supplier), 'legacy-header-' . $supplier,
            '{"sources":"annual payload is intentionally not a typed graph"}', '{"funding":"legacy"}', '{"rows":"legacy"}', $actor]);
        $assessmentId = (int) $this->pdo->lastInsertId();

        $header = (new NonprofitTaxPagedAssessmentRepository($db))->historyHeader($supplier, $period);
        self::assertSame(['assessment_id' => $assessmentId, 'revision_no' => 1,
            'storage_version' => 'legacy_json_v1', 'source_input_hash' => $inputHash],
            array_intersect_key($header, array_flip(['assessment_id', 'revision_no', 'storage_version', 'source_input_hash'])));

        $summary = $container->get(J6AssessmentReadService::class)->periodSummary($supplier, $period);
        self::assertSame($inputHash, $summary['latest']['source_input_hash']);
        self::assertTrue($summary['latest']['conversion_required']);
        self::assertNull($summary['latest']['summary']);
        self::assertSame($period, $summary['source']['header']['period']['id']);
    }
}
