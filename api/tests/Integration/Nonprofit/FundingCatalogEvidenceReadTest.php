<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Nonprofit;

use MyInvoice\Repository\NonprofitTaxCalculationManifestRepository;
use MyInvoice\Repository\NonprofitTaxPagedAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Repository\NonprofitTaxSourceEvidenceManifestRepository;
use MyInvoice\Service\Anonymization\Streaming\CanonicalJsonSpool;
use MyInvoice\Service\Nonprofit\Catalog\FundingCatalogEvidenceRead;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentGraphReceipt;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationHeader;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6CalculationPageHasher;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6ImmutableManifestSink;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6SourceEvidencePageHasher;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;
use MyInvoice\Tests\Support\NonprofitResourceCaseFixture;
use MyInvoice\Tests\Support\ResourceReadBearerFixture;
use PHPUnit\Framework\TestCase;

final class FundingCatalogEvidenceReadTest extends TestCase
{
    use ResourceReadBearerFixture;
    use NonprofitResourceCaseFixture;

    public function testPurposeUsesBoundedConfirmedJ6PagesAndRedactsPrivateProof(): void
    {
        $source = $this->fundingSource(false);
        $assessment = $this->confirmedJ6(501);
        $reader = $this->container->get(FundingCatalogEvidenceRead::class);
        $before = $this->domainDmlCounters();
        $beforeRead = $this->sessionStatus();

        $first = $reader->read($this->supplier, $source['id'], $this->period, $assessment['id'], 'purpose', null, 25);
        $afterFirst = $this->sessionStatus();
        self::assertSame([], $first['items']);
        self::assertSame(['after_ordinal' => 0, 'offset' => 0], $first['next_cursor']);
        self::assertSame('page_verified_more_available', $first['coverage']);
        self::assertLessThanOrEqual(20, $afterFirst['Com_select'] - $beforeRead['Com_select']);
        self::assertLessThanOrEqual(30, $afterFirst['Rows_sent'] - $beforeRead['Rows_sent']);

        $second = $reader->read($this->supplier, $source['id'], $this->period, $assessment['id'], 'purpose', $first['next_cursor'], 25);
        self::assertNull($second['next_cursor']);
        self::assertSame('proven', $second['coverage']);
        self::assertCount(1, $second['items']);
        self::assertSame([
            'funding_id' => $source['id'],
            'period_id' => $this->period,
            'assessment_id' => $assessment['id'],
            'source_line_id' => $this->income,
            'date' => '2026-01-01',
            'amount_cents' => '100000',
            'activity_kind_id' => $this->kind,
            'decision' => [
                'title' => 'gift',
                'qualifying_purpose' => true,
                'claim_exemption' => true,
                'election_evidence_present' => true,
                'public_budget' => null,
            ],
            'classification' => [
                'regime' => 'exempt',
                'method' => 'none',
                'legal_basis_status' => 'standard_code',
                'legal_basis_code' => 'zdp_19b_2b_1_3',
            ],
            'assessment_method' => 'ruleset',
            'proof_status' => 'confirmed_immutable_j6',
        ], $second['items'][0]);
        self::assertSame($before, $this->domainDmlCounters());
        $json = json_encode($second, JSON_THROW_ON_ERROR);
        foreach ([self::PRIVATE_SENTINEL, 'source_anchor', 'source_key', '"evidence":', '"request":', 'hash', 'created_by', 'reason'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testUsageIsAssessmentBoundAndUnknownMetadataRemainsSeparate(): void
    {
        $source = $this->fundingSource(false);
        $assessment = $this->confirmedJ6();

        $result = $this->container->get(FundingCatalogEvidenceRead::class)->read(
            $this->supplier, $source['id'], $this->period, $assessment['id'], 'usage', null, 100,
        );

        self::assertNull($result['next_cursor']);
        self::assertSame([[
            'funding_id' => $source['id'],
            'period_id' => $this->period,
            'assessment_id' => $assessment['id'],
            'net_source_cents' => '100000',
            'prior_drawdown_cents' => '0',
            'drawdown_cents' => '60000',
            'remaining_cents' => '40000',
            'proof_status' => 'confirmed_immutable_j6',
        ]], $result['items']);
        self::assertSame('confirmed_assessment', $result['temporal_basis']);
        self::assertSame('immutable_j6_graph', $result['consistency']);
        self::assertFalse($result['financial_authorization']);
    }

    public function testForeignIdentityAndSemanticallyInvalidVerifiedPageFailClosed(): void
    {
        $source = $this->fundingSource(false);
        $assessment = $this->confirmedJ6(malformed: true);
        $reader = $this->container->get(FundingCatalogEvidenceRead::class);

        try {
            $reader->read($this->otherSupplier, $source['id'], $this->period, $assessment['id'], 'purpose');
            self::fail('Foreign funding source was exposed.');
        } catch (NonprofitTaxAssessmentException $failure) {
            self::assertSame('nonprofit_catalog_not_found', $failure->errorCode);
        }
        try {
            $reader->read($this->supplier, $source['id'], $this->period, $assessment['id'], 'purpose');
            self::fail('Unsupported legal classification was projected as verified.');
        } catch (NonprofitTaxAssessmentException $failure) {
            self::assertSame('nonprofit_funding_catalog_proof_invalid', $failure->errorCode);
        }
    }

    public function testGiftCertificateEvidenceFromRealPagedAssessmentOverBearerApi(): void
    {
        $source = $this->fundingSource(false);
        $assessment = $this->pagedFundingAssessment(60000);
        $token = $this->token();
        $path = '/api/v1/accounting/catalog/nonprofit-funding/' . $source['id'] . '/evidence';
        $query = ['period_id' => (string) $this->period, 'assessment_id' => (string) $assessment];

        [$status, $purpose] = $this->http('GET', $path, $token, $query + ['kind' => 'purpose']);
        self::assertSame(200, $status, json_encode($purpose));
        self::assertSame('proven', $purpose['coverage']);
        $gift = $purpose['items'][0];
        self::assertSame($this->income, $gift['source_line_id']);
        self::assertSame('2026-01-01', $gift['date']);
        self::assertSame('100000', $gift['amount_cents']);
        self::assertSame(['title' => 'gift', 'qualifying_purpose' => true, 'claim_exemption' => true,
            'election_evidence_present' => true, 'public_budget' => null], $gift['decision']);

        [$status, $usage] = $this->http('GET', $path, $token, $query + ['kind' => 'usage']);
        self::assertSame(200, $status, json_encode($usage));
        self::assertSame(['60000', '40000'], [$usage['items'][0]['drawdown_cents'], $usage['items'][0]['remaining_cents']]);

        $items = [];
        $cursor = [];
        $pages = 0;
        do {
            [$status, $uses] = $this->http('GET', $path, $token, $query + ['kind' => 'uses', 'limit' => '1'] + $cursor);
            self::assertSame(200, $status, json_encode($uses));
            array_push($items, ...$uses['items']);
            $cursor = $uses['next_cursor'] === null ? [] : array_map('strval', $uses['next_cursor']);
            self::assertLessThan(10, ++$pages);
        } while ($cursor !== []);
        self::assertSame([[
            'funding_id' => $source['id'], 'period_id' => $this->period, 'assessment_id' => $assessment,
            'cost_line_id' => $this->expense, 'amount_cents' => '60000', 'proof_status' => 'confirmed_immutable_j6',
        ]], $items);
        foreach ([$purpose, $usage, $uses] as $body) {
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            foreach ([self::PRIVATE_SENTINEL, 'PRIVATE-SOURCE-KEY', '"evidence":', 'hash'] as $private) {
                self::assertStringNotContainsString($private, $json);
            }
        }

        [$status, $legacy] = $this->http('GET', $path, $token,
            ['period_id' => (string) $this->period, 'assessment_id' => (string) $this->taxReceipt['id'], 'kind' => 'purpose']);
        self::assertSame(409, $status);
        self::assertSame('nonprofit_tax_legacy_history_requires_migration', $legacy['error']['code']);
        [$status] = $this->http('GET', $path, $token, $query + ['kind' => 'purpose', 'offset' => '1']);
        self::assertSame(422, $status);
        [$status] = $this->http('GET', $path, $token, $query + ['kind' => 'purpose', 'known_at' => '2026-01-01']);
        self::assertSame(409, $status);
    }

    public function testUsesPageResumesInsideTheSameImmutableChunk(): void
    {
        $source = $this->fundingSource(false);
        $assessment = $this->confirmedJ6(uses: 3);
        $reader = $this->container->get(FundingCatalogEvidenceRead::class);

        $items = [];
        $cursors = [];
        $cursor = null;
        do {
            $page = $reader->read($this->supplier, $source['id'], $this->period, $assessment['id'], 'uses', $cursor, 2);
            array_push($items, ...$page['items']);
            $cursor = $page['next_cursor'];
            $cursors[] = $cursor;
            self::assertLessThan(10, count($cursors));
        } while ($cursor !== null);

        self::assertContains(2, array_column(array_filter($cursors), 'offset'), 'Plná stránka pokračuje uvnitř téhož bloku.');
        self::assertSame([900000001, 900000002, 900000003], array_column($items, 'cost_line_id'));
        self::assertSame(['10000', '10000', '10000'], array_column($items, 'amount_cents'));
    }

    private function confirmedJ6(int $rowCount = 1, bool $malformed = false, int $uses = 0): array
    {
        $snapshotRepository = new NonprofitTaxSnapshotManifestRepository($this->db, new NonprofitTaxSnapshotHasher());
        $inputHash = hash('sha256', "funding-b2-snapshot:{$this->supplier}:{$this->period}:{$rowCount}:{$uses}:" . (int) $malformed);
        $snapshot = $snapshotRepository->appendManifest([
            'supplier_id' => $this->supplier,
            'period_id' => $this->period,
            'ruleset' => 'nonprofit-j6-rules-2026.1',
            'header' => ['period' => ['id' => $this->period]],
            'header_hash' => hash('sha256', 'funding-b2-header:' . $inputHash),
            'root_hash' => hash('sha256', 'funding-b2-root:' . $inputHash),
            'input_hash' => $inputHash,
            'row_count' => $rowCount,
            'finding_count' => 0,
            'chunk_count' => 0,
            'membership_fact_count' => 0,
        ], []);
        $header = new J6CalculationHeader(
            $this->supplier,
            $this->period,
            'nonprofit-j6-rules-2026.1',
            $inputHash,
            hash('sha256', 'funding-b2-decision:' . $inputHash),
            hash('sha256', 'funding-b2-calculation:' . $inputHash),
            $snapshot->id,
            null,
            ['profile' => ['kind' => 'narrow_vpp', 'evidence' => self::PRIVATE_SENTINEL]],
            $inputHash,
        );
        $sink = new J6ImmutableManifestSink(
            new NonprofitTaxCalculationManifestRepository($this->db),
            new J6CalculationPageHasher(new CanonicalJsonSpool()),
            new NonprofitTaxSourceEvidenceManifestRepository($this->db),
            new J6SourceEvidencePageHasher(new CanonicalJsonSpool()),
        );
        $sink->begin($header);
        $anchor = $this->fundingPolicy['source_anchor'];
        for ($offset = 0; $offset < $rowCount; $offset += 500) {
            $size = min(500, $rowCount - $offset);
            $decisions = $results = $coverage = [];
            for ($index = 1; $index <= $size; ++$index) {
                $position = $offset + $index;
                $id = $position === $rowCount ? $anchor : 'line:' . (900000000 + $position);
                $decision = [
                    'title' => $malformed && $id === $anchor ? 'private_free_text' : 'gift',
                    'evidence' => self::PRIVATE_SENTINEL,
                    'facts' => [
                        'qualifying_purpose' => true,
                        'claim_exemption' => true,
                        'election_evidence' => self::PRIVATE_SENTINEL,
                    ],
                ];
                $decisions[] = ['record_kind' => 'source', 'source_id' => $id, 'payload' => $decision,
                    'decision_record_hash' => hash('sha256', 'funding-b2-decision-row:' . $id)];
                $results[] = ['id' => $id, 'line_id' => (int) substr($id, 5), 'date' => '2026-01-01', 'kind' => 'revenue', 'amount_cents' => 100000,
                    'activity_kind_id' => $this->kind, 'decision' => $decision,
                    'classification' => ['regime' => 'exempt', 'method' => 'none', 'legal_basis' => 'zdp_19b_2b_1_3'],
                    'deductible_cents' => 0, 'adjustment_cents' => -100000, 'funded_excluded_cents' => 0,
                    'result_row_hash' => hash('sha256', 'funding-b2-result:' . $id)];
                $coverage[] = ['source_id' => $id, 'result_row_hash' => hash('sha256', 'funding-b2-result:' . $id)];
            }
            $sink->appendDecisionPage($decisions);
            $sink->appendResultPage($results);
            $sink->appendCoveragePage($coverage);
        }
        if ($uses > 0) {
            $links = [];
            for ($index = 1; $index <= $uses; ++$index) {
                $links[] = ['record_kind' => 'funding_link', 'source_id' => $anchor, 'cost_id' => 'line:' . (900000000 + $index),
                    'payload' => ['amount_cents' => 10000, 'evidence' => self::PRIVATE_SENTINEL],
                    'decision_record_hash' => hash('sha256', 'funding-b2-link:' . $index)];
            }
            $sink->appendDecisionPage($links);
        }
        $sink->appendFundingUsagePage([[
            'anchor' => $anchor,
            'member_id' => $anchor,
            'net_source_cents' => 100000,
            'prior_drawdown_cents' => 0,
            'drawdown_cents' => 60000,
            'remaining_cents' => 40000,
            'usage_record_hash' => hash('sha256', 'funding-b2-usage:' . $anchor),
        ]]);
        $calculation = $sink->complete([
            'schema' => 'nonprofit-j6-summary.v2',
            'ruleset' => 'nonprofit-j6-rules-2026.1',
            'profile' => 'narrow_vpp',
            'assessment_method' => 'ruleset',
            'scope' => 'classification_bridge_before_general_adjustments',
            'source_row_count' => $rowCount,
            'decision_record_count' => $rowCount + $uses,
            'activity_group_count' => 1,
            'funding_usage_count' => 1,
            'accounting_result_cents' => 100000,
            'excluded_revenue_cents' => 100000,
            'cost_addbacks_cents' => 0,
            'assessed_result_cents' => 0,
            'external_adjustment_cents' => 0,
            'dppo_supported' => false,
        ]);
        $receipt = J6AssessmentGraphReceipt::fromCalculation($snapshot, 0, $calculation);
        $repository = new NonprofitTaxPagedAssessmentRepository($this->db);
        return $repository->appendConfirmed(
            $this->supplier,
            $this->period,
            $repository->latest($this->supplier, $this->period),
            'Synthetic confirmed B2 assessment',
            'funding-b2-' . bin2hex(random_bytes(8)),
            hash('sha256', 'funding-b2-request:' . $inputHash),
            $header->decisionHeader,
            $receipt,
            $this->actor,
        );
    }

    private function sessionStatus(): array
    {
        $rows = $this->db->pdo()->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_select','Rows_sent')")
            ->fetchAll(\PDO::FETCH_KEY_PAIR);
        return array_map('intval', $rows);
    }
}
