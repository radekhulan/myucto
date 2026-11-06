<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Tests\Support\J6CalculatorFactory;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6LegacyAssessmentConversionService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6LegacyInputTransformer;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6LegacyMappedIdentityTransformer;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6LegacyPageTransformer;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxCalculator;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

final class J6LegacyAssessmentConversionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private J6LegacyAssessmentConversionService $conversion;
    private int $supplier;
    private int $period;
    private int $actor;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $configured = getenv('MYINVOICE_DB_NAME');
        self::assertStringEndsWith('_test', $database);
        if (is_string($configured) && $configured !== '') self::assertSame($configured, $database);
        self::assertContains((string) $container->get(Config::class)->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $this->pdo->beginTransaction();
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $this->period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        $this->conversion = $container->get(J6LegacyAssessmentConversionService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testOwnerConversionKeepsRawLegacyProofAndPublishesVerifiedPagedGraph(): void
    {
        [$id, $inputHash, $raw] = $this->legacyAssessment();
        $plan = $this->pdo->prepare('EXPLAIN SELECT id FROM nonprofit_tax_assessments WHERE supplier_id=? AND period_id=? AND id=? FOR UPDATE');
        $plan->execute([$this->supplier, $this->period, $id]);
        $explain = $plan->fetch(PDO::FETCH_ASSOC);
        self::assertContains($explain['type'], ['const', 'eq_ref', 'ref']);
        self::assertLessThanOrEqual(1, (int) $explain['rows']);
        $completion = $this->conversion->convert($this->supplier, $this->period, $id,
            'legacy_json_v1', $inputHash, 'Syntetický vlastnický převod historického grafu', $this->actor);

        self::assertSame($id, $completion->assessmentId);
        $row = $this->row($id);
        self::assertSame('paged_v2', $row['storage_version']);
        self::assertSame('legacy_json_v1', $row['legacy_storage_version']);
        self::assertSame($inputHash, $row['legacy_input_hash']);
        self::assertSame($raw, [$row['snapshot_sha256'], $row['assessment_sha256'], $row['result_sha256']]);
        self::assertNotNull($row['j6_receipt_json']);
        $legacyView = (new \MyInvoice\Repository\NonprofitTaxAssessmentRepository($this->db))->find($this->supplier, $id);
        $legacyInput = $legacyView['snapshot'];
        unset($legacyInput['input_hash']);
        self::assertSame($inputHash, $legacyView['input_hash']);
        self::assertSame($legacyView['input_hash'], NonprofitTaxRules::hash($legacyInput));
        self::assertSame($id, $this->conversion->convert($this->supplier, $this->period, $id,
            'legacy_json_v1', $inputHash, 'Syntetický opakovaný převod', $this->actor)->assessmentId);
        try {
            $this->pdo->prepare('UPDATE nonprofit_tax_assessments SET reason=? WHERE supplier_id=? AND id=?')
                ->execute(['Zakázaná změna', $this->supplier, $id]);
            self::fail('Converted assessment must remain immutable.');
        } catch (\PDOException $failure) {
            self::assertStringContainsString('nonprofit_tax_assessment_immutable', $failure->getMessage());
        }
    }

    public function testRecomputedLegacyResultMutationFailsParityAndPublishesNothing(): void
    {
        [$id, $inputHash] = $this->legacyAssessment(static function (array $result): array {
            $result['rows'][0]['amount_cents']++;
            return $result;
        });
        try {
            $this->conversion->convert($this->supplier, $this->period, $id,
                'legacy_json_v1', $inputHash, 'Syntetický odmítnutý převod', $this->actor);
            self::fail('Mutated legacy result must not be converted.');
        } catch (\UnexpectedValueException $failure) {
            self::assertSame('Legacy J6 result changed during conversion.', $failure->getMessage());
        }
        $row = $this->row($id);
        self::assertSame('legacy_json_v1', $row['storage_version']);
        self::assertNull($row['j6_receipt_json']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM nonprofit_tax_snapshot_manifests WHERE supplier_id=' . $this->supplier)->fetchColumn());
    }

    public function testTypedRebaseMayScrubEvidenceButCannotChangeFinancialFields(): void
    {
        [$id, $inputHash] = $this->legacyAssessment();
        $scrub = new class implements J6LegacyInputTransformer {
            public function transform(string $kind, array $record): array { return $this->walk($record); }
            private function walk(array $value): array
            {
                foreach ($value as $key => $item) {
                    if ($key === 'evidence' && is_string($item)) $value[$key] = 'Anonymizovaný syntetický důkaz';
                    elseif (is_array($item)) $value[$key] = $this->walk($item);
                }
                return $value;
            }
        };
        self::assertSame($id, $this->conversion->convert($this->supplier, $this->period, $id,
            'legacy_json_v1', $inputHash, 'Syntetický povolený rebase', $this->actor, $scrub)->assessmentId);
    }

    public function testTypedRebaseCannotChangeCents(): void
    {
        [$id, $inputHash] = $this->legacyAssessment();
        $mutation = new class implements J6LegacyInputTransformer {
            public function transform(string $kind, array $record): array
            {
                if ($kind === 'source') $record['amount_cents']++;
                return $record;
            }
        };
        try {
            $this->conversion->convert($this->supplier, $this->period, $id,
                'legacy_json_v1', $inputHash, 'Syntetický zakázaný rebase', $this->actor, $mutation);
            self::fail('Protected financial field must not be transformed.');
        } catch (\UnexpectedValueException $failure) {
            self::assertSame('Legacy J6 transform changed a protected field.', $failure->getMessage());
        }
        self::assertSame('legacy_json_v1', $this->row($id)['storage_version']);
    }

    public function testFundingPoolsMembersLinksAndUsageRoundTripThroughHistoricProvider(): void
    {
        [$id, $inputHash] = $this->legacyAssessment(null, true);
        $completion = $this->conversion->convert($this->supplier, $this->period, $id,
            'legacy_json_v1', $inputHash, 'Syntetický převod fondu financování', $this->actor);
        self::assertSame(1, $completion->receipt->counts['usage']);
        self::assertSame(1, $completion->receipt->summary['funding_usage_count']);
        self::assertSame('paged_v2', $this->row($id)['storage_version']);
    }

    public function testVerifiedTypedIdentityMapMayRebaseIdsButNotFinancialValues(): void
    {
        [$id, $inputHash] = $this->legacyAssessment();
        $mapping = new class implements J6LegacyMappedIdentityTransformer {
            public function transform(string $kind, array $record): array { return $this->walk($record); }
            public function permitsIdentityChange(string $kind, array $path, mixed $source, mixed $target): bool
            {
                return ($source === 'line:1' && $target === 'line:101')
                    || ($source === 'line:2' && $target === 'line:102')
                    || ($source === 1 && $target === 101) || ($source === 2 && $target === 102);
            }
            private function walk(array $record): array
            {
                foreach ($record as $key => $value) {
                    if (is_array($value)) $record[$key] = $this->walk($value);
                    elseif (in_array($key, ['id', 'source_id', 'cost_id'], true) && $value === 'line:1') $record[$key] = 'line:101';
                    elseif (in_array($key, ['id', 'source_id', 'cost_id'], true) && $value === 'line:2') $record[$key] = 'line:102';
                    elseif ($key === 'line_id' && $value === 1) $record[$key] = 101;
                    elseif ($key === 'line_id' && $value === 2) $record[$key] = 102;
                }
                return $record;
            }
        };
        self::assertSame($id, $this->conversion->convert($this->supplier, $this->period, $id,
            'legacy_json_v1', $inputHash, 'Syntetický mapovaný rebase identit', $this->actor, $mapping)->assessmentId);
    }

    public function testPageTransformerIsCalledOncePerBoundedCollectionPage(): void
    {
        [$id, $inputHash] = $this->legacyAssessment(null, true);
        $transformer = new class implements J6LegacyPageTransformer {
            public array $singleCalls = [];
            public array $pageCalls = [];
            public function transform(string $kind, array $record): array
            {
                $this->singleCalls[] = $kind;
                return $record;
            }
            public function transformPage(string $kind, array $records): array
            {
                if (count($records) > 500) throw new \RuntimeException('Unbounded legacy transform page.');
                $this->pageCalls[] = [$kind, count($records)];
                return $records;
            }
        };
        $this->conversion->convert($this->supplier, $this->period, $id,
            'legacy_json_v1', $inputHash, 'Syntetický dávkový rebase', $this->actor, $transformer);
        self::assertSame(['source_header', 'source_header', 'decision_header'], $transformer->singleCalls);
        self::assertSame([
            ['source', 2], ['result', 2], ['result_usage', 1], ['activity', 1],
            ['funding_pool', 1], ['funding_member', 1], ['funding_link', 1], ['decision', 2],
        ], $transformer->pageCalls);
    }

    private function legacyAssessment(?callable $resultMutation = null, bool $funding = false): array
    {
        $sources = [
            ['id' => 'line:1', 'line_id' => 1, 'kind' => 'revenue', 'amount_cents' => 10000,
                'activity' => 'main', 'activity_kind_id' => 1, 'tax_regime' => 'subject',
                'mandatory_non_deductible' => false, 'module_facts' => []],
            ['id' => 'line:2', 'line_id' => 2, 'kind' => 'expense', 'amount_cents' => 6000,
                'activity' => 'main', 'activity_kind_id' => 1, 'tax_regime' => 'subject',
                'mandatory_non_deductible' => false, 'module_facts' => []],
        ];
        $period = ['id' => $this->period, 'fiscal_year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31'];
        $snapshot = ['supplier_id' => $this->supplier, 'period' => $period, 'frameworks' => ['cz_504'],
            'ruleset' => NonprofitTaxRules::VERSION,
            'assessment_support' => ['automatic' => true, 'documented_manual' => false,
                'automatic_from' => NonprofitTaxRules::AUTOMATIC_FROM, 'automatic_to' => NonprofitTaxRules::AUTOMATIC_TO],
            'sources' => $sources, 'findings' => [], 'accounting_result_cents' => 4000, 'ledger' => [],
            'dimensions' => [], 'joint_projections' => [], 'source_references' => [], 'neutral_line_ids' => [],
            'funding_history' => [], 'funding_reference_sources' => []];
        $snapshot['input_hash'] = NonprofitTaxRules::hash($snapshot);
        $assessment = ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Syntetické stanovy'],
            'activities' => ['1' => ['evidence' => 'Syntetická činnost']],
            'decisions' => [
                'line:1' => ['title' => 'rent', 'evidence' => 'Syntetický příjem', 'deductible_cents' => 10000, 'legal_basis' => 'zdp_24_1'],
                'line:2' => ['title' => 'cost', 'evidence' => 'Syntetický náklad', 'deductible_cents' => 6000, 'legal_basis' => 'zdp_24_1'],
            ], 'funding' => []];
        if ($funding) {
            $assessment['decisions']['line:1'] = ['title' => 'gift', 'evidence' => 'Syntetický dar',
                'deductible_cents' => 10000, 'legal_basis' => 'zdp_19b_2_b', 'facts' => [
                    'claim_exemption' => true, 'qualifying_purpose' => true,
                    'election_evidence' => 'Syntetická volba osvobození']];
            $assessment['funding_sources'] = [['anchor' => 'line:1', 'members' => ['line:1'],
                'evidence' => 'Syntetický fond']];
            $assessment['funding_costs'] = [];
            $assessment['funding'] = [['source_id' => 'line:1', 'cost_id' => 'line:2', 'amount_cents' => 6000,
                'evidence' => 'Syntetické čerpání']];
        }
        $result = J6CalculatorFactory::legacy()->calculate($snapshot, $assessment);
        if ($resultMutation !== null) $result = $resultMutation($result);
        $requestHash = hash('sha256', 'legacy-request-' . $this->supplier);
        $stmt = $this->pdo->prepare('INSERT INTO nonprofit_tax_assessments
            (supplier_id,period_id,revision_no,status,reason,ruleset,storage_version,input_hash,request_hash,idempotency_key,
             snapshot_json,assessment_json,result_json,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$this->supplier, $this->period, 1, 'confirmed', 'Syntetické legacy posouzení',
            NonprofitTaxRules::VERSION, 'legacy_json_v1', $snapshot['input_hash'], $requestHash,
            'legacy-sidecar-' . $this->supplier, NonprofitTaxRules::canonicalJson($snapshot),
            NonprofitTaxRules::canonicalJson($assessment), NonprofitTaxRules::canonicalJson($result), $this->actor]);
        $id = (int) $this->pdo->lastInsertId();
        $row = $this->row($id);
        return [$id, $snapshot['input_hash'], [$row['snapshot_sha256'], $row['assessment_sha256'], $row['result_sha256']]];
    }

    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT storage_version,legacy_storage_version,legacy_input_hash,j6_receipt_json,
            SHA2(snapshot_json,256) AS snapshot_sha256,SHA2(assessment_json,256) AS assessment_sha256,SHA2(result_json,256) AS result_sha256
            FROM nonprofit_tax_assessments WHERE supplier_id=? AND id=?');
        $stmt->execute([$this->supplier, $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
