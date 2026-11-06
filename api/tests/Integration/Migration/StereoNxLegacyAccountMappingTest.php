<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountMappingPlan;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountMappingService;
use MyInvoice\Service\Migration\StereoNx\StereoNxAccountingWriter;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use MyInvoice\Service\Migration\StereoNx\StereoNxImportMap;
use MyInvoice\Tests\Fixtures\StereoNx\SyntheticStereoNxAccountingTables;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class StereoNxLegacyAccountMappingTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private StereoNxAccountMappingService $service;
    private StereoNxAccountingWriter $writer;
    private PostingRuleRepository $rules;
    private PostingService $posting;
    private int $supplierId;
    private int $userId;
    private string $ico;
    private array $prepared;

    protected function setUp(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertTrue(str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test'));
        $sourceSupplier = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplier);
        self::assertGreaterThan(0, $this->userId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplier);
        $identity = SyntheticStereoNxAccountingTables::identity();
        $this->ico = $identity['ico'];
        $pdo->prepare("UPDATE supplier SET company_name=?, ic=?, dic=?, accounting_mode='double_entry', is_vat_payer=1 WHERE id=?")
            ->execute([$identity['name'], $identity['ico'], $identity['dic'], $this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $accounts = $container->get(ChartOfAccountsRepository::class);
        $parent = $accounts->findByCode($this->supplierId, '343');
        self::assertNotNull($parent);
        self::assertNotNull($accounts->findByCode($this->supplierId, '343.100'));
        $legacyId = $accounts->insert($this->supplierId, ['account_code' => '343100',
            'name' => 'Syntetická historická vstupní DPH', 'account_type' => $parent['account_type'],
            'normal_side' => null, 'is_synthetic' => false, 'parent_id' => $parent['id'], 'is_active' => true]);
        $chart = [self::sourceAccount('343100', 'R'), self::sourceAccount('321', 'P')];
        $container->get(StereoNxImportMap::class)->put($this->supplierId, $this->ico, 0,
            'accounting_account', '343100', $chart[0]['source_hash'], $legacyId);
        $this->prepared = ['ok' => true, 'identity' => $identity, 'company_index' => 0,
            'accounting_plan' => ['chart' => $chart, 'entries' => [self::entry('343100', '1')]]];
        $this->service = $container->get(StereoNxAccountMappingService::class);
        $this->writer = $container->get(StereoNxAccountingWriter::class);
        $this->rules = $container->get(PostingRuleRepository::class);
        $this->posting = $container->get(PostingService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testLegacyImportedVatTargetRemainsConsistentWithNativeRulesWhenCanonicalAccountDoesNotExist(): void
    {
        $this->db->pdo()->prepare("DELETE FROM chart_of_accounts WHERE supplier_id=? AND account_code='343.100'")
            ->execute([$this->supplierId]);
        $planner = new StereoNxAccountMappingPlan();
        $proposal = $planner->propose($this->prepared, []);
        self::assertSame('343.100', $proposal['vat']['input']);
        $mapped = $planner->apply($this->prepared, $proposal, []);
        $resolved = $this->service->resolveExistingTargets($mapped, $this->supplierId, $this->ico, 0);
        self::assertSame('343100', $resolved['account_mapping']['accounts']['343100']);
        self::assertSame('343100', $resolved['accounting_plan']['entries'][0]['target_debit']);
        self::assertSame('343100', $resolved['account_mapping']['vat']['input']);
        $written = $this->writer->write($resolved, $this->supplierId, $this->userId);
        self::assertSame(1, $written['journal_entries_created']);
        $this->service->applySettings($resolved['account_mapping'], $this->supplierId, $this->ico, 0, $resolved);
        $nativeInput = $this->rules->resolve($this->supplierId, 'invoice.vat.input')['debit_account_code'];
        self::assertSame('343100', $nativeInput);
        $query = $this->db->pdo()->prepare("SELECT a.account_code FROM journal_entry_lines l
            JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id
            WHERE l.supplier_id=? AND l.side='debit'");
        $query->execute([$this->supplierId]);
        self::assertSame([$nativeInput], $query->fetchAll(\PDO::FETCH_COLUMN));
        $inputResolver = new \ReflectionMethod(PostingService::class, 'inputVatAccount');
        self::assertSame($nativeInput, $inputResolver->invoke($this->posting, $this->supplierId));

        $repeat = $this->service->resolveExistingTargets($mapped, $this->supplierId, $this->ico, 0);
        self::assertSame($resolved, $repeat);
        self::assertSame(1, $this->writer->write($repeat, $this->supplierId, $this->userId)['journal_entries_existing']);
        $this->service->applySettings($repeat['account_mapping'], $this->supplierId, $this->ico, 0, $repeat);
        self::assertSame($nativeInput, $this->rules->resolve($this->supplierId, 'invoice.vat.input')['debit_account_code']);
    }

    public function testCoexistingLegacyAndCanonicalAccountsRequireExplicitConsolidation(): void
    {
        $planner = new StereoNxAccountMappingPlan();
        $proposal = $planner->propose($this->prepared, []);
        $mapped = $planner->apply($this->prepared, $proposal, []);
        try {
            $this->service->resolveExistingTargets($mapped, $this->supplierId, $this->ico, 0);
            self::fail('Jedna role nemůže přebít dva skutečně odlišné účetní cíle.');
        } catch (StereoNxException $e) {
            self::assertSame('account_mapping_existing_conflict', $e->errorCode);
        }
    }

    private static function sourceAccount(string $code, string $type): array
    {
        return ['code' => $code, 'name' => 'Syntetický účet ' . $code, 'source_type' => $type,
            'tax_deductible' => true, 'source_hash' => hash('sha256', 'synthetic-chart-' . $code)];
    }

    private static function entry(string $debit, string $number): array
    {
        $entry = ['source_key' => json_encode(['PF', 'TEST', $number, '1', '1'], JSON_THROW_ON_ERROR),
            'date' => '2026-03-01', 'source_year' => 2026, 'posting_year' => 2026,
            'debit' => $debit, 'credit' => '321', 'amount_cents' => 2100, 'is_red_storno' => false,
            'document_no' => 'TEST-PF-' . $number, 'description' => 'Syntetická DPH', 'cost_center' => '',
            'performance' => '', 'job' => '', 'is_opening' => false];
        $entry['source_hash'] = StereoNxImportMap::fingerprint($entry);
        return $entry;
    }
}
