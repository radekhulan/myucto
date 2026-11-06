<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleService;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleAudit;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\Reports\DimensionProfitService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class SystemDimensionHistoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private DimensionRepository $dimensions;
    private DimensionService $service;
    private JournalEntryRepository $journal;
    private DimensionRuleService $rules;
    private DimensionProfitService $profit;
    private int $supplier;
    private int $period;
    private int $type;
    private array $accounts;
    private bool $committed = false;
    private ?Connection $otherDb = null;
    private ?int $snapshotIsolation = null;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->dimensions = $container->get(DimensionRepository::class);
        $this->service = $container->get(DimensionService::class);
        $this->journal = $container->get(JournalEntryRepository::class);
        $this->rules = $container->get(DimensionRuleService::class);
        $this->profit = $container->get(DimensionProfitService::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplier = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', dimensions_enabled = 1 WHERE id = ?")->execute([$this->supplier]);
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplier);
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '1900-01-01', 'cz_504')")->execute([$this->supplier]);
        $this->period = $container->get(AccountingPeriodRepository::class)->create($this->supplier, 2096, '2096-01-01', '2096-12-31');
        $query = $pdo->prepare('SELECT account_code, id FROM chart_of_accounts WHERE supplier_id = ?');
        $query->execute([$this->supplier]);
        $this->accounts = $query->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->type = $this->dimensions->createType($this->supplier, false, ['code' => 'activity_kind', 'name' => 'Druh činnosti', 'kind' => 'custom']);
        $pdo->prepare("UPDATE dimension_types SET system_key = 'activity_kind' WHERE id = ?")->execute([$this->type]);
    }

    protected function tearDown(): void
    {
        if ($this->otherDb !== null) {
            if ($this->otherDb->pdo()->inTransaction()) $this->otherDb->pdo()->rollBack();
            $this->otherDb->close();
        }
        if (isset($this->db)) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($this->snapshotIsolation !== null) $pdo->exec('SET SESSION innodb_snapshot_isolation = ' . $this->snapshotIsolation);
            if ($this->committed) {
                $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplier]);
                $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplier]);
                $pdo->prepare('UPDATE dimension_values SET parent_id = NULL WHERE supplier_id = ?')->execute([$this->supplier]);
                $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplier]);
            }
        }
    }

    public function testUsedSystemNameCannotRewriteHistoricalReport(): void
    {
        $value = $this->value('SPORT');
        $this->post($value);
        $before = $this->report();
        self::assertSame(100.0, (float) $before[$value]['total']['result']);
        $this->refused(fn () => $this->dimensions->updateValue($this->supplier, $value, ['name' => 'Jiný význam']));
        self::assertSame($before, $this->report());
    }

    public function testOpenSystemBranchCannotRetagEarlierAmounts(): void
    {
        $a = $this->value('A');
        $b = $this->value('B');
        $leaf = $this->value('LEAF', $a);
        $this->post($leaf);
        $before = $this->report();
        self::assertSame(100.0, (float) $before[$a]['total']['result']);
        self::assertSame(0.0, (float) $before[$b]['total']['result']);
        $this->refused(fn () => $this->dimensions->updateValue($this->supplier, $leaf, ['parent_id' => $b]));
        self::assertSame($before, $this->report());
    }

    public function testUsedTypeNameCannotRewriteHistoricalHeader(): void
    {
        $this->post($this->value('SPORT'));
        $this->refused(fn () => $this->dimensions->updateType($this->supplier, $this->type, ['name' => 'Jiný typ']));
        self::assertSame('Druh činnosti', $this->dimensions->findType($this->supplier, $this->type)['name']);
    }

    public function testSystemTypeCannotBeDisabledDeletedOrBypassedByCompanySwitch(): void
    {
        $this->refused(fn () => $this->dimensions->updateType($this->supplier, $this->type, ['is_active' => false]));
        $this->refused(fn () => $this->dimensions->deleteType($this->supplier, $this->type));
        $this->refused(fn () => $this->dimensions->setEnabled($this->supplier, false));
    }

    public function testBuiltinValueCannotBeDisabledEvenBeforeUse(): void
    {
        $value = $this->value('MAIN');
        $this->db->pdo()->prepare("UPDATE dimension_values SET semantic_key = 'main' WHERE id = ?")->execute([$value]);
        $this->refused(fn () => $this->dimensions->updateValue($this->supplier, $value, ['is_active' => false]));
        $this->refused(fn () => $this->dimensions->deleteValue($this->supplier, $value));
    }

    public function testNoopOwnerAndRetirementKeepHistoricalAmountsAndNames(): void
    {
        $value = $this->value('SPORT');
        $this->post($value);
        $before = $this->report();
        $this->dimensions->updateValue($this->supplier, $value, ['name' => 'SPORT', 'parent_id' => null]);
        $this->dimensions->updateValue($this->supplier, $value, ['responsible_note' => 'Nový správce', 'note' => 'Provozní poznámka', 'is_active' => false]);
        $after = $this->report();
        self::assertSame($before[$value]['name'], $after[$value]['name']);
        self::assertSame($before[$value]['total'], $after[$value]['total']);
    }

    #[DataProvider('systemSources')]
    public function testSystemSourceCannotBypassRequiredSystemDimension(string $source): void
    {
        $this->rule();
        $caught = null;
        try {
            $this->rules->check($this->supplier, $source, $this->lines(null), '2096-05-10');
        } catch (PostingException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, $source);
        self::assertSame('dimension_required', $caught->errorCode);
    }

    public static function systemSources(): array
    {
        return array_map(static fn (string $source): array => [$source], ['depreciation', 'offset', 'unit_charge', 'unit_settlement', 'unit_service_accrual', 'unit_credit', 'unit_credit_refund', 'fund_draw']);
    }

    public function testRepositoryInsertRefusesBeforeWritingHeader(): void
    {
        $this->rule();
        $count = $this->countEntries();
        $caught = null;
        try {
            $this->journal->insert($this->header('depreciation'), $this->lines(null));
        } catch (PostingException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught);
        self::assertSame('dimension_required', $caught->errorCode);
        self::assertSame($count, $this->countEntries());
    }

    public function testLegacy500CustomRuleStillExemptsSystemSource(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework = 'cz_500' WHERE supplier_id = ?")->execute([$this->supplier]);
        $this->db->pdo()->prepare('UPDATE dimension_types SET system_key = NULL WHERE id = ?')->execute([$this->type]);
        $this->rule();
        self::assertSame([], $this->rules->check($this->supplier, 'depreciation', $this->lines(null), '2096-05-10'));
        $this->journal->insert($this->header('depreciation'), $this->lines(null));
        self::assertSame(1, $this->countEntries());
    }

    public function testOldSnapshotCannotRenameNewlyUsedSystemValue(): void
    {
        $value = $this->value('ORIGINAL');
        $other = $this->commitAndConnect();
        $pdo = $this->db->pdo();
        $this->snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $pdo->exec('SET SESSION innodb_snapshot_isolation = 0');
        $pdo->beginTransaction();
        self::assertSame('ORIGINAL', $this->dimensions->findValue($this->supplier, $value)['name']);
        $other->get(DimensionRepository::class)->updateValue($this->supplier, $value, ['name' => 'CURRENT']);
        $other->get(JournalEntryRepository::class)->insert($this->header(), $this->lines($value));
        $this->refused(fn () => $this->dimensions->updateValue($this->supplier, $value, ['name' => 'ORIGINAL']));
        $this->dimensions->updateValue($this->supplier, $value, ['name' => 'CURRENT']);
        $pdo->rollBack();
        self::assertSame('CURRENT', $this->report()[$value]['name']);
        self::assertSame(100.0, (float) $this->report()[$value]['total']['result']);
    }

    public function testPeriodLockSerializesSemanticEditAndFirstUse(): void
    {
        $value = $this->value('ORIGINAL');
        $other = $this->commitAndConnect();
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->journal->insert($this->header(), $this->lines($value));
        $this->otherDb->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $blocked = false;
        try {
            $other->get(DimensionRepository::class)->updateValue($this->supplier, $value, ['name' => 'OTHER']);
        } catch (\PDOException $e) {
            self::assertSame(1205, (int) $e->errorInfo[1]);
            $blocked = true;
        }
        self::assertTrue($blocked);
        $pdo->commit();
        $this->refused(fn () => $other->get(DimensionRepository::class)->updateValue($this->supplier, $value, ['name' => 'OTHER']));
        self::assertSame('ORIGINAL', $this->report()[$value]['name']);
    }

    public function testCatalogIsExplicitIdempotentAndDoesNotAdoptCustomIdentity(): void
    {
        $catalog = new SystemDimensionCatalog($this->db);
        $first = $catalog->ensureForFramework($this->supplier, '2096-05-10');
        self::assertSame(['activity', 'activity_kind', 'tax_regime'], array_keys($first));
        self::assertSame(['main', 'economic'], array_keys($first['activity']['values']));
        self::assertSame([], $first['activity_kind']['values']);
        self::assertSame(['subject', 'out_of_scope', 'exempt'], array_keys($first['tax_regime']['values']));
        self::assertSame($first, $catalog->ensureForFramework($this->supplier, '2096-05-10'));
        $this->refused(fn () => $this->dimensions->createValue($this->dimensions->findType($this->supplier, $first['activity']['id']), ['code' => 'AUTO', 'name' => 'Auto']));
        $this->db->pdo()->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework = 'cz_500' WHERE supplier_id = ?")->execute([$this->supplier]);
        self::assertSame([], $catalog->ensureForFramework($this->supplier, '2096-05-10'));
    }

    public function testCatalogConflictRollsBackNewTypesAndAudit(): void
    {
        $this->dimensions->createType($this->supplier, false, ['code' => 'tax_regime', 'name' => 'Vlastní', 'kind' => 'custom']);
        $before = $this->dimensions->listTypes($this->supplier);
        $this->refused(fn () => (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2096-05-10'));
        self::assertSame($before, $this->dimensions->listTypes($this->supplier));
    }

    public function testAmbiguousSystemSplitsAreRefusedBeforeJournalWrite(): void
    {
        $catalog = (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2096-05-10');
        $a = $this->value('A');
        $b = $this->value('B');
        $lines = $this->lines(null);
        $lines[1]['dimension_splits'] = [
            $this->type => [$a => 50, $b => 50],
            $catalog['activity']['id'] => array_fill_keys(array_values($catalog['activity']['values']), 50),
        ];
        try {
            (new SystemDimensionWritePolicy($this->db))->assertJournal($this->header(), $lines);
            self::fail('Nezávislé podíly dvou systémových os nesmějí vytvářet odhadnutou kombinaci.');
        } catch (PostingException $e) {
            self::assertSame('system_dimension_allocation_ambiguous', $e->errorCode);
        }
        self::assertSame(0, $this->countEntries());
    }

    public function testAuditAndRuntimeAgreeOnClosingBalanceOnly(): void
    {
        $this->journal->insert($this->header('closing'), $this->lines(null));
        $this->rules->create($this->supplier, ['dimension_type_id' => $this->type, 'account_mask' => '3,6', 'enforcement' => 'error']);
        $audit = Bootstrap::buildContainer()->get(DimensionRuleAudit::class)->violations($this->supplier, '2096-01-01', '2096-12-31');
        self::assertSame(1, $audit['total']);
        self::assertSame('311', $audit['rows'][0]['account_code']);
        self::assertSame(100.0, abs($audit['rows'][0]['amount']));
        $value = $this->value('BALANCE');
        $lines = $this->lines(null);
        $lines[0]['dimensions'] = [$this->type => $value];
        self::assertSame([], $this->rules->check($this->supplier, 'closing', $lines, '2096-05-10'));
    }

    public function testSemanticAuditUsesExplicitActorAndNoopDoesNotAppend(): void
    {
        $value = $this->value('A');
        $actor = (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->dimensions->updateValue($this->supplier, $value, ['name' => 'B'], $actor, 'test_explicit');
        $stmt = $this->db->pdo()->prepare("SELECT user_id, payload FROM activity_log WHERE supplier_id = ? AND action = 'dimension.system.value' ORDER BY id DESC");
        $stmt->execute([$this->supplier]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame($actor, (int) $rows[0]['user_id']);
        $payload = json_decode($rows[0]['payload'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('test_explicit', $payload['source']);
        self::assertSame('A', $payload['before']['name']);
        self::assertSame('B', $payload['after']['name']);
        $this->dimensions->updateValue($this->supplier, $value, ['name' => 'B']);
        $stmt->execute([$this->supplier]);
        self::assertSame($rows, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function testProfileCannotRenameUsedSystemValueAndReactivationKeepsIdentity(): void
    {
        $value = $this->value('SPORT');
        $this->post($value);
        $before = $this->report();
        $importer = Bootstrap::buildContainer()->get(\MyInvoice\Service\Settings\CompanyProfile\CompanyProfileImporter::class);
        $caught = null;
        try {
            $importer->import($this->supplier, ['format' => 'myucto.company-profile', 'version' => 1,
                'sections' => ['dimensions' => ['types' => [[
                    'code' => 'activity_kind', 'name' => 'Druh činnosti', 'kind' => 'custom',
                    'values' => [['code' => 'SPORT', 'name' => 'Jiný význam']],
                ]]]]], false);
        } catch (\MyInvoice\Service\Settings\CompanyProfile\CompanyProfileException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught);
        self::assertSame('system_dimension_immutable', $caught->errorCode);
        self::assertSame($before, $this->report());
        $this->dimensions->updateValue($this->supplier, $value, ['is_active' => false]);
        $this->dimensions->updateValue($this->supplier, $value, ['is_active' => true]);
        self::assertSame($before[$value]['total'], $this->report()[$value]['total']);
        self::assertSame('SPORT', $this->dimensions->findValue($this->supplier, $value)['name']);
    }

    public function testMandatoryActivityCannotBeBypassedByMissingConfigRule(): void
    {
        (new SystemDimensionCatalog($this->db))->ensureForFramework($this->supplier, '2096-05-10');
        $this->expectException(PostingException::class);
        $this->rules->check($this->supplier, 'depreciation', $this->lines(null), '2096-05-10');
    }

    public function test500PolicyHasConstantCurrentReadsRegardlessOfLineCount(): void
    {
        $this->db->pdo()->prepare('UPDATE dimension_types SET system_key = NULL WHERE id = ?')->execute([$this->type]);
        $this->db->pdo()->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework = 'cz_500' WHERE supplier_id = ?")->execute([$this->supplier]);
        $policy = new SystemDimensionWritePolicy($this->db);
        foreach ([1, 500] as $repeat) {
            $lines = array_merge(...array_fill(0, $repeat, $this->lines(null)));
            $before = (int) $this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(\PDO::FETCH_NUM)[1];
            $policy->assertJournal($this->header(), $lines);
            $after = (int) $this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(\PDO::FETCH_NUM)[1];
            self::assertSame(5, $after - $before, 'Conversion adds three constant current reads for the target period, supplier and applied conversion.');
            self::assertSame(0, $this->countEntries());
        }
    }

    public function testOldSnapshotCannotMissNewMandatorySystemTypeBeforeInsert(): void
    {
        $value = $this->value('SPORT');
        $other = $this->commitAndConnect();
        $pdo = $this->db->pdo();
        $this->snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $pdo->exec('SET SESSION innodb_snapshot_isolation = 0');
        $pdo->beginTransaction();
        self::assertCount(1, $this->dimensions->listTypes($this->supplier));
        (new SystemDimensionCatalog($this->otherDb))->ensureForFramework($this->supplier, '2096-05-10');
        $caught = null;
        try { $this->journal->insert($this->header(), $this->lines($value)); } catch (PostingException $e) { $caught = $e; }
        self::assertNotNull($caught);
        self::assertSame('dimension_required', $caught->errorCode);
        self::assertSame(0, $this->countEntries());
    }

    public function testCurrentImportGuardSeesFrameworkChangedAfterCallerSnapshot(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework = 'cz_500' WHERE supplier_id = ?")->execute([$this->supplier]);
        $this->commitAndConnect();
        $this->snapshotIsolation = (int) $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
        $pdo->exec('SET SESSION innodb_snapshot_isolation = 0');
        $pdo->beginTransaction();
        $guard = new \MyInvoice\Service\Accounting\AccountingFrameworkImportGuard($this->db);
        $periods = [['starts_on' => '2096-01-01', 'ends_on' => '2096-12-31']];
        $guard->assertCz500Periods($this->supplier, $periods);
        $this->otherDb->pdo()->prepare("UPDATE supplier_accounting_frameworks SET accounting_framework = 'cz_504' WHERE supplier_id = ?")->execute([$this->supplier]);
        $caught = null;
        try { $guard->assertCz500Periods($this->supplier, $periods, true); } catch (\MyInvoice\Service\Accounting\AccountingFrameworkException $e) { $caught = $e; }
        self::assertNotNull($caught);
        self::assertSame('accounting_framework_import_unsupported', $caught->errorCode);
        self::assertSame(0, $this->countEntries());
    }

    private function commitAndConnect(): \Psr\Container\ContainerInterface
    {
        $this->db->pdo()->commit();
        $this->committed = true;
        $container = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer());
        $this->otherDb = Connection::withoutSharedTestConnection(fn () => $container->get(Connection::class));
        self::assertNotSame($this->db->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn(), $this->otherDb->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
        return $container;
    }

    private function value(string $name, ?int $parent = null): int
    {
        return $this->dimensions->createValue($this->dimensions->findType($this->supplier, $this->type), ['code' => $name, 'name' => $name, 'parent_id' => $parent]);
    }

    private function post(int $value): void
    {
        $this->journal->insert($this->header(), $this->lines($value));
    }

    private function header(string $source = 'manual'): array
    {
        return ['supplier_id' => $this->supplier, 'period_id' => $this->period, 'entry_date' => '2096-05-10', 'source_type' => $source, 'posted_at' => '2096-05-10 12:00:00'];
    }

    private function lines(?int $value): array
    {
        $extra = $value === null ? [] : ['dimensions' => [$this->type => $value]];
        return [['account_id' => $this->accounts['311'], 'side' => 'debit', 'amount' => 100] + $extra,
            ['account_id' => $this->accounts['602'], 'side' => 'credit', 'amount' => 100] + $extra];
    }

    private function rule(): void
    {
        $this->rules->create($this->supplier, ['dimension_type_id' => $this->type, 'account_mask' => '6', 'enforcement' => 'error']);
    }

    private function report(): array
    {
        return array_column($this->profit->build($this->supplier, $this->type, '2096-01-01', '2096-12-31', [$this->supplier])['rows'], null, 'value_id');
    }

    private function countEntries(): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?');
        $stmt->execute([$this->supplier]);
        return (int) $stmt->fetchColumn();
    }

    private function refused(callable $write): void
    {
        $caught = null;
        try { $write(); } catch (DimensionException $e) { $caught = $e; }
        self::assertNotNull($caught, 'Změna systémové identity musí být odmítnuta.');
        self::assertSame(409, $caught->httpStatus);
    }
}
