<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\NonprofitTaxCalculationManifestRepository;
use MyInvoice\Repository\NonprofitTaxSourceEvidenceManifestRepository;
use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonSpool;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationHeader;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationPageHasher;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6DecisionContext;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadMode;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6SourceEvidencePageHasher;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ImmutableManifestSink;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\HistoricJ6DecisionProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\HistoricJ6CalculationProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\HistoricJ6FundingEvidenceProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6HistoricAssessmentBinding;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ReadContext;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

final class J6ImmutableManifestSinkTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testCompletePublishesFourImmutablePagedManifestsInsideOwnerTransaction(): void
    {
        [$supplier, $period] = $this->owner();
        $sink = $this->sink();
        $header = $this->header($supplier, $period);
        $sink->begin($header);
        foreach (array_chunk($this->rows(501), 500) as $page) {
            $sink->appendDecisionPage(array_map(static fn (array $row): array => ['record_kind' => 'source',
                'source_id' => $row['id'], 'payload' => ['title' => 'rent'], 'decision_record_hash' => hash('sha256', $row['id'])], $page));
            $sink->appendResultPage($page);
            $sink->appendCoveragePage(array_map(static fn (array $row): array => ['source_id' => $row['id'],
                'result_row_hash' => hash('sha256', $row['id'])], $page));
        }
        $sink->appendFundingUsagePage([['anchor' => 'line:1', 'drawdown_cents' => 0]]);
        $sink->appendFundingReferencePage([['id' => 'line:prior', 'reference_current' => true]]);
        $sink->appendFundingHistoryPage([['assessment_id' => 7, 'anchor' => 'line:prior', 'current' => true]]);
        $receipt = $sink->complete($this->summary(501, 1));
        self::assertSame(['decision', 'result', 'coverage', 'usage'], array_keys($receipt->manifestIds));
        self::assertSame(501, $receipt->counts['result']);

        $repository = new NonprofitTaxCalculationManifestRepository($this->db);
        foreach ($receipt->manifestIds as $kind => $id) {
            $manifest = $repository->manifest($supplier, $id);
            self::assertSame($kind, $manifest['chunk_kind']);
            self::assertSame($header->calculationInputHash, $manifest['calculation_input_hash']);
            self::assertSame($receipt->rootHashes[$kind], $manifest['root_hash']);
            $pages = $repository->chunkPage($supplier, $id, -1, 4);
            self::assertCount($kind === 'usage' ? 1 : 2, $pages);
            self::assertLessThanOrEqual(500, max(array_map(static fn (array $page): int => (int) $page['row_count'], $pages)));
        }
        $evidenceRepository = new NonprofitTaxSourceEvidenceManifestRepository($this->db);
        foreach ($receipt->evidenceManifestIds as $kind => $id) {
            $manifest = $evidenceRepository->manifest($supplier, $id);
            self::assertSame($kind, $manifest['evidence_kind']);
            self::assertSame($receipt->evidenceRootHashes[$kind], $manifest['root_hash']);
            self::assertCount(1, $evidenceRepository->chunkPage($supplier, $id));
        }
        $historic = new HistoricJ6DecisionProvider($repository, new J6CalculationPageHasher(new CanonicalJsonSpool()),
            new J6DecisionContext($supplier, $period, J6ReadMode::HISTORIC, $header->sourceInputHash,
                assessmentId: 23, decisionManifestId: $receipt->manifestIds['decision']));
        self::assertSame('narrow_vpp', $historic->header()['profile']['kind']);
        self::assertSame(501, array_sum(array_map('count', iterator_to_array($historic->decisionPages(200)))));
        $binding = new J6HistoricAssessmentBinding($supplier, $period, 23, 1,
                $receipt->evidenceManifestIds['funding_reference'], $receipt->evidenceManifestIds['funding_history'], $receipt->manifestIds['decision'],
                $receipt->manifestIds['result'], $receipt->manifestIds['coverage'], $receipt->manifestIds['usage'],
                $header->snapshotInputHash ?? $header->sourceInputHash, $header->sourceInputHash,
                $header->calculationInputHash, $receipt->rootHashes, $receipt->evidenceRootHashes);
        $calculation = new HistoricJ6CalculationProvider($repository, new J6CalculationPageHasher(new CanonicalJsonSpool()), $binding);
        self::assertSame(501, array_sum(array_map('count', iterator_to_array($calculation->pages('result', 200)))));
        self::assertSame(501, $calculation->summary()['source_row_count']);
        $historicEvidence = new HistoricJ6FundingEvidenceProvider($evidenceRepository,
            new J6SourceEvidencePageHasher(new CanonicalJsonSpool()), $binding);
        $context = new J6ReadContext($supplier, $period, J6ReadMode::HISTORIC, 23, 1);
        self::assertSame('line:prior', iterator_to_array($historicEvidence->fundingReferencePages($context))[0][0]['id']);
        self::assertSame(7, iterator_to_array($historicEvidence->fundingHistoryPages($context))[0][0]['assessment_id']);
    }

    public function testFailedCompletionNeedsRollbackAndLeavesNoPublishedRows(): void
    {
        $beforeChunks = (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_tax_calculation_chunks')->fetchColumn();
        $beforeManifests = (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_tax_calculation_manifests')->fetchColumn();
        [$supplier, $period] = $this->owner();
        $sink = $this->sink();
        $sink->begin($this->header($supplier, $period));
        $sink->appendDecisionPage([['record_kind' => 'source', 'source_id' => 'line:1', 'payload' => ['title' => 'rent'],
            'decision_record_hash' => hash('sha256', 'line:1')]]);
        try {
            $sink->complete($this->summary(1, 0));
            self::fail('Incomplete calculation must not publish a manifest.');
        } catch (\UnexpectedValueException) {}
        self::assertGreaterThan($beforeChunks, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_tax_calculation_chunks')->fetchColumn());
        $this->pdo->rollBack();
        self::assertSame($beforeChunks, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_tax_calculation_chunks')->fetchColumn());
        self::assertSame($beforeManifests, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_tax_calculation_manifests')->fetchColumn());
    }

    private function owner(): array
    {
        $this->pdo->beginTransaction();
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $period = (new AccountingPeriodRepository($this->db))->create($supplier, 2026, '2026-01-01', '2026-12-31');
        return [$supplier, $period];
    }

    private function sink(): J6ImmutableManifestSink
    {
        return new J6ImmutableManifestSink(new NonprofitTaxCalculationManifestRepository($this->db),
            new J6CalculationPageHasher(new CanonicalJsonSpool()),
            new NonprofitTaxSourceEvidenceManifestRepository($this->db),
            new J6SourceEvidencePageHasher(new CanonicalJsonSpool()));
    }

    private function header(int $supplier, int $period): J6CalculationHeader
    {
        return new J6CalculationHeader($supplier, $period, 'nonprofit-j6-rules-2026.1', hash('sha256', 'source'),
            hash('sha256', 'decision'), hash('sha256', "calculation:{$supplier}:{$period}"), 1, null,
            ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy']]);
    }

    private function rows(int $count): array
    {
        $rows = [];
        for ($index = 1; $index <= $count; ++$index) $rows[] = ['id' => 'line:' . $index, 'amount_cents' => $index];
        return $rows;
    }

    private function summary(int $count, int $usage): array
    {
        return ['source_row_count' => $count, 'decision_record_count' => $count, 'funding_usage_count' => $usage, 'accounting_result_cents' => 1,
            'excluded_revenue_cents' => 0, 'cost_addbacks_cents' => 0, 'assessed_result_cents' => 1];
    }
}
