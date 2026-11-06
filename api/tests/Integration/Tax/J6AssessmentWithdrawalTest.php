<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\NonprofitTaxCalculationManifestRepository;
use MyInvoice\Repository\NonprofitTaxPagedAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Repository\NonprofitTaxSourceEvidenceManifestRepository;
use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonSpool;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentGraphReceipt;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentWithdrawalService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationHeader;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationPageHasher;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ImmutableManifestSink;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6SourceEvidencePageHasher;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

final class J6AssessmentWithdrawalTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertSame((string) getenv('MYINVOICE_DB_NAME'), (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testConfirmedLaterFundingUsageBlocksWithdrawalWithoutAnnualArrayMaterialization(): void
    {
        $container = Bootstrap::buildContainer();
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $periods = new AccountingPeriodRepository($this->db);
        $sourcePeriod = $periods->create($supplier, 2025, '2025-01-01', '2025-12-31');
        $laterPeriod = $periods->create($supplier, 2026, '2026-01-01', '2026-12-31');
        $source = $this->confirmed($supplier, $sourcePeriod, $actor, 'source:gift', []);
        $this->confirmed($supplier, $laterPeriod, $actor, 'later:cost', [[
            'anchor' => 'source:gift', 'member_id' => 'source:gift', 'net_source_cents' => 10000,
            'prior_drawdown_cents' => 0, 'drawdown_cents' => 5000, 'remaining_cents' => 5000,
            'usage_record_hash' => hash('sha256', 'later-usage-' . $supplier),
        ]]);

        try {
            $container->get(J6AssessmentWithdrawalService::class)->withdraw($supplier, $sourcePeriod, $source['id'],
                'Syntetické odvolání', 'withdraw-overlap-' . $supplier, $actor);
            self::fail('Later confirmed funding usage must block withdrawal.');
        } catch (NonprofitTaxAssessmentException $failure) {
            self::assertSame('nonprofit_tax_review_required', $failure->errorCode);
        }
    }

    private function confirmed(int $supplier, int $period, int $actor, string $sourceId, array $usage): array
    {
        $snapshotRepository = new NonprofitTaxSnapshotManifestRepository($this->db, new NonprofitTaxSnapshotHasher());
        $inputHash = hash('sha256', "snapshot:{$supplier}:{$period}");
        $snapshot = $snapshotRepository->appendManifest([
            'supplier_id' => $supplier, 'period_id' => $period, 'ruleset' => 'nonprofit-j6-rules-2026.1',
            'header' => ['period' => ['id' => $period]], 'header_hash' => hash('sha256', "header:{$period}"),
            'root_hash' => hash('sha256', "root:{$period}"), 'input_hash' => $inputHash,
            'row_count' => 1, 'finding_count' => 0, 'chunk_count' => 0, 'membership_fact_count' => 0,
        ], []);
        $header = new J6CalculationHeader($supplier, $period, 'nonprofit-j6-rules-2026.1', $inputHash,
            hash('sha256', "decision:{$period}"), hash('sha256', "calculation:{$period}"), $snapshot->id, null,
            ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy']], $inputHash);
        $sink = new J6ImmutableManifestSink(new NonprofitTaxCalculationManifestRepository($this->db),
            new J6CalculationPageHasher(new CanonicalJsonSpool()),
            new NonprofitTaxSourceEvidenceManifestRepository($this->db),
            new J6SourceEvidencePageHasher(new CanonicalJsonSpool()));
        $sink->begin($header);
        $sink->appendDecisionPage([['record_kind' => 'source', 'source_id' => $sourceId,
            'payload' => ['title' => 'gift'], 'decision_record_hash' => hash('sha256', "decision-row:{$sourceId}")]]);
        $sink->appendResultPage([['id' => $sourceId, 'kind' => 'revenue', 'amount_cents' => 10000,
            'decision' => ['title' => 'gift'], 'classification' => ['regime' => 'exempt'],
            'result_row_hash' => hash('sha256', "result-row:{$sourceId}")]]);
        $sink->appendCoveragePage([['source_id' => $sourceId,
            'result_row_hash' => hash('sha256', "coverage-row:{$sourceId}")]]);
        if ($usage !== []) $sink->appendFundingUsagePage($usage);
        $summary = ['source_row_count' => 1, 'decision_record_count' => 1, 'funding_usage_count' => count($usage),
            'accounting_result_cents' => 10000, 'excluded_revenue_cents' => 10000,
            'cost_addbacks_cents' => 0, 'assessed_result_cents' => 0];
        $calculation = $sink->complete($summary);
        $receipt = J6AssessmentGraphReceipt::fromCalculation($snapshot, 0, $calculation);
        return (new NonprofitTaxPagedAssessmentRepository($this->db))->appendConfirmed($supplier, $period, null,
            'Syntetické potvrzení', "confirmed-{$period}-{$supplier}", hash('sha256', "request:{$period}:{$supplier}"),
            $header->decisionHeader, $receipt, $actor);
    }
}
