<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\NonprofitTaxDraftRepository;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\DraftJ6DecisionProvider;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDraftService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6AssessmentDraftQueryService;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6DraftContext;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotMaterializer;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\TestCase;

final class J6AssessmentDraftServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private NonprofitTaxDraftRepository $repository;
    private J6AssessmentDraftService $service;
    private int $supplier;
    private int $period;
    private int $actor;
    private string $sourceHash;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->pdo = $this->db->pdo();
        self::assertSame((string) getenv('MYINVOICE_DB_NAME'), (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn());
        $this->pdo->beginTransaction();
        $seed = (int) $this->pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->supplier = $this->createIsolatedSupplier($this->pdo, $seed);
        $this->period = (new AccountingPeriodRepository($this->db))->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        $this->actor = (int) $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->sourceHash = hash('sha256', 'synthetic-j6-source');
        $this->repository = new NonprofitTaxDraftRepository($this->db);
        $this->service = new J6AssessmentDraftService($this->repository, new DimensionPeriodGuard($this->db),
            $container->get(NonprofitTaxSnapshotMaterializer::class), new NonprofitTaxSnapshotManifestRepository($this->db, new NonprofitTaxSnapshotHasher()));
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testDecisionAndFundingEditsAreCappedVersionedAndReadonlyProviderPagesDoNoDml(): void
    {
        $draft = $this->service->open($this->supplier, $this->period, $this->sourceHash, $this->header(), $this->actor);
        $decisions = [];
        for ($index = 1; $index <= 500; ++$index) {
            $decisions[] = ['source_id' => 'line:' . $index, 'payload' => ['title' => 'other_taxable', 'evidence' => 'Synthetic decision ' . $index]];
        }
        $draft = $this->service->patchDecisions($this->supplier, $this->period, $draft['id'], $draft['revision_no'], $decisions, [], $this->actor);
        self::assertSame(2, $draft['revision_no']);
        $draft = $this->service->patchFundingPools($this->supplier, $this->period, $draft['id'], $draft['revision_no'], [[
            'pool_kind' => 'revenue', 'anchor' => 'line:1', 'payload' => ['evidence' => 'Synthetic source pool'],
        ], [
            'pool_kind' => 'revenue', 'anchor' => 'line:501', 'payload' => ['evidence' => 'Synthetic second source pool'],
        ]], [], $this->actor);
        $members = array_map(static fn (int $id): string => 'line:' . $id, range(1, 500));
        $draft = $this->service->patchFundingMembers($this->supplier, $this->period, $draft['id'], $draft['revision_no'],
            'revenue', 'line:1', $members, [], $this->actor);
        $draft = $this->service->patchFundingMembers($this->supplier, $this->period, $draft['id'], $draft['revision_no'],
            'revenue', 'line:501', ['line:501'], [], $this->actor);
        self::assertSame(5, $draft['revision_no']);

        $provider = new DraftJ6DecisionProvider($this->repository,
            new J6DraftContext($this->supplier, $this->period, $draft['id'], $draft['revision_no'], $this->actor, $this->sourceHash));
        $before = $this->dmlStatus();
        self::assertSame('narrow_vpp', $provider->header()['profile']['kind']);
        $counts = [];
        foreach ($provider->decisionPages(200) as $page) $counts[] = count($page);
        self::assertSame([200, 200, 100], array_values(array_filter($counts)));
        $memberCounts = [];
        $selectsBefore = $this->selectStatus();
        foreach ($provider->fundingMemberPages(200) as $page) $memberCounts[] = count($page);
        self::assertSame([200, 200, 101], array_values(array_filter($memberCounts)));
        self::assertSame(4, $this->selectStatus() - $selectsBefore,
            'Členové všech fondů se čtou jednou globální keyset sekvencí, ne dotazem pro každý fond.');
        $query = new J6AssessmentDraftQueryService($this->repository, new NonprofitTaxSnapshotManifestRepository($this->db, new NonprofitTaxSnapshotHasher()),
            new NonprofitTaxSnapshotHasher());
        $context = new J6DraftContext($this->supplier, $this->period, $draft['id'],
            $draft['revision_no'], $this->actor, $this->sourceHash);
        $firstPool = $query->fundingMemberPoolPage($context, 'revenue', 'line:1', null, 500);
        self::assertCount(500, $firstPool['rows']);
        self::assertSame('line:99', $firstPool['next_cursor']);
        $secondPool = $query->fundingMemberPoolPage($context, 'revenue', 'line:501', null, 500);
        self::assertSame([['member_id' => 'line:501']], $secondPool['rows']);
        self::assertNull($secondPool['next_cursor']);
        $page = $query->decisionPage($context, null, 200);
        self::assertCount(200, $page['rows']);
        self::assertIsString($page['next_cursor']);
        self::assertSame($draft['revision_no'], $page['draft']['revision_no']);
        self::assertSame($before, $this->dmlStatus());

        $this->expectException(\InvalidArgumentException::class);
        $this->service->patchDecisions($this->supplier, $this->period, $draft['id'], $draft['revision_no'],
            array_merge($decisions, [['source_id' => 'line:501', 'payload' => ['title' => 'rent']]]), [], $this->actor);
    }

    public function testStaleRevisionAndForeignActorFailClosedWithoutPartialBatch(): void
    {
        $draft = $this->service->open($this->supplier, $this->period, $this->sourceHash, $this->header(), $this->actor);
        $draft = $this->service->patchActivities($this->supplier, $this->period, $draft['id'], $draft['revision_no'], [[
            'activity_kind_id' => '1', 'payload' => ['evidence' => 'Synthetic activity'],
        ]], [], $this->actor);
        try {
            $this->service->patchActivities($this->supplier, $this->period, $draft['id'], 1, [[
                'activity_kind_id' => '2', 'payload' => ['evidence' => 'Must roll back'],
            ]], [], $this->actor);
            self::fail('Stale draft revision must fail.');
        } catch (NonprofitTaxAssessmentException $failure) {
            self::assertSame('nonprofit_tax_draft_changed', $failure->errorCode);
        }
        self::assertSame([], $this->repository->page($this->supplier, $draft['id'], 'activity', '1'));
        $this->expectException(NonprofitTaxAssessmentException::class);
        $this->expectExceptionMessage('nebyl nalezen');
        $this->service->patchActivities($this->supplier, $this->period, $draft['id'], $draft['revision_no'], [], [], $this->actor + 1000000);
    }

    private function header(): array
    {
        return ['profile' => ['kind' => 'narrow_vpp', 'evidence' => 'Synthetic statutes']];
    }

    private function dmlStatus(): array
    {
        $out = [];
        foreach (['Com_insert', 'Com_update', 'Com_delete'] as $name) {
            $row = $this->pdo->query("SHOW SESSION STATUS LIKE '{$name}'")->fetch(PDO::FETCH_NUM);
            $out[$name] = (int) $row[1];
        }
        return $out;
    }

    private function selectStatus(): int
    {
        return (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
    }
}



