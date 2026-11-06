<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ChargeIssuanceIsolationTest extends TestCase
{
    use ChargeIssuanceFixture;
    private static ?PDO $server=null;
    private static ?string $schema=null;
    private array $connections=[];

    /**
     * Souběh potřebuje dvě spojení nad potvrzenými daty, proto vlastní kopii testovací
     * databáze. Stačí jedna kopie na třídu: každý test si zakládá vlastní firmu a počty
     * ověřuje jen v ní.
     */
    public static function setUpBeforeClass(): void
    {
        $container=Bootstrap::buildContainer(); $config=$container->get(Config::class);
        $source=(string)$container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test',$source); self::assertContains($config->get('db.host'),['localhost','127.0.0.1','::1']);
        self::$schema='myucto_issue_race_'.bin2hex(random_bytes(5)).'_test';
        self::$server=new PDO('mysql:host='.$config->get('db.host').';port='.$config->get('db.port',3306).';charset=utf8mb4',
            $config->get('db.user'),$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $cloner=new DatabaseCloner(self::$server); $plan=$cloner->plan($source);
        try { $cloner->copyTables($plan,self::$schema,static function(): void {}); $cloner->finalize($plan,self::$schema); }
        catch (\Throwable $e) { self::tearDownAfterClass(); throw $e; }
    }

    public static function tearDownAfterClass(): void
    {
        if(self::$schema!==null&&self::$server!==null) {
            self::assertMatchesRegularExpression('/^myucto_issue_race_[a-f0-9]{10}_test$/D',self::$schema);
            self::$server->exec('DROP DATABASE IF EXISTS '.DatabaseCloner::quote(self::$schema));
        }
        self::$schema=null; self::$server=null;
    }

    protected function setUp(): void
    {
        $config=Bootstrap::buildContainer()->get(Config::class);
        $data=$config->all(); $data['db']['name']=self::$schema;
        for($i=0;$i<2;$i++) {
            $db=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($data,$config->dataDir())));
            $db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $db->pdo()->exec('SET SESSION innodb_snapshot_isolation=0');
            $db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connections[]=$db;
        }
    }

    protected function tearDown(): void
    {
        foreach($this->connections as $db) { if($db->pdo()->inTransaction()) $db->pdo()->rollBack(); $db->close(); }
    }

    public function testStaleCallerSnapshotCannotIssueSecondRevisionOrMissNewLedgerOwnership(): void
    {
        [$a,$b]=$this->connections;
        $b->pdo()->beginTransaction(); $f=$this->issuanceFixture($b); $b->pdo()->commit();
        $preview=(new IssuancePreviewService($a))->preview($f['supplier'],$f['issue_request']);
        $input=['request'=>$f['issue_request'],'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'first'];
        $a->pdo()->beginTransaction(); self::assertSame(0,(int)$a->pdo()->query('SELECT COUNT(*) FROM unit_charges WHERE supplier_id='.(int)$f['supplier'])->fetchColumn());
        $writer=$this->writer($b); $batch=$writer->issue($f['supplier'],$input,$f['actor']);
        $input['idempotency_key']='second';
        try { $this->writer($a)->issue($f['supplier'],$input,$f['actor']); self::fail('Old snapshot issued duplicate business case.'); }
        catch(IssuanceException $e) { self::assertSame('property_charge_issue_revision_conflict',$e->errorCode); }
        self::assertTrue($a->pdo()->inTransaction()); $a->pdo()->rollBack();
        $a->pdo()->beginTransaction(); self::assertSame(0,(int)$a->pdo()->query('SELECT COUNT(*) FROM unit_charges WHERE journal_entry_id IS NOT NULL AND supplier_id='.(int)$f['supplier'])->fetchColumn());
        $posted=$writer->post($f['supplier'],$batch['id'],array_map(fn($c)=>['id'=>$c['id'],'row_version'=>1],$batch['charges']),$f['actor']);
        $entry=$posted['charges'][0]['journal_entry_id'];
        try { (new IssuanceUseGuard($a))->assertEntriesMutable($f['supplier'],[$entry]); self::fail('Old snapshot missed current ledger ownership.'); }
        catch(IssuanceException $e) { self::assertSame('property_charge_issue_source_immutable',$e->errorCode); }
        try {
            (new \MyInvoice\Repository\JournalEntryRepository($a, new \MyInvoice\Service\ActivityLogger($a)))->detachSource($entry, $f['supplier']);
            self::fail('Current writer accepted ownership hidden by the caller snapshot.');
        } catch (\MyInvoice\Service\Accounting\PostingException $e) {
            self::assertSame('property_charge_issue_source_immutable', $e->errorCode);
        }
        $find = $b->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? ORDER BY id');
        $find->execute([$f['supplier'], $entry]);
        $lineId = (int) $find->fetchColumn();
        try {
            (new \MyInvoice\Repository\DimensionAssignmentRepository($a))->insertLineDimensions($f['supplier'], $lineId, [2147483647 => 2147483647]);
            self::fail('Current assignment accepted an issued source hidden by the caller snapshot.');
        } catch (\MyInvoice\Service\Accounting\Dimension\DimensionException $e) {
            self::assertSame('property_charge_issue_source_immutable', $e->errorCode);
        }
        try {
            (new \MyInvoice\Service\Accounting\Allocation\JointAllocationPreviewService($a))->preview($f['supplier'], 2147483647, '2026-03-01', [$lineId]);
            self::fail('J5 preview ignored current issued ownership.');
        } catch (\MyInvoice\Service\Accounting\Allocation\JointAllocationException $e) {
            self::assertSame('property_charge_issue_source_immutable', $e->errorCode);
        }
        $a->pdo()->rollBack();
        self::assertSame(1,(int)$b->pdo()->query('SELECT COUNT(*) FROM unit_charge_batches WHERE supplier_id='.(int)$f['supplier'])->fetchColumn());
        self::assertSame(12,(int)$b->pdo()->query("SELECT COUNT(*) FROM unit_charges WHERE status='posted' AND supplier_id=".(int)$f['supplier'])->fetchColumn());
    }

    private function writer(Connection $db): IssuanceRepository
    {
        $container=Bootstrap::buildContainer(); self::assertInstanceOf(\DI\Container::class,$container);
        $container->set(Connection::class,$db);
        return new IssuanceRepository($db,$container->get(PostingService::class),$container->get(\MyInvoice\Repository\JournalEntryRepository::class));
    }

    public static function lockPaths(): array { return [['restamp'], ['replace'], ['assignment'], ['j5_preview'], ['j5_cancel']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('lockPaths')]
    public function testClaimContextIsLockedBeforeAnyExistingSourceRow(string $path): void
    {
        [$a, $b] = $this->connections;
        $b->pdo()->beginTransaction();
        $f = $this->issuanceFixture($b);
        $writer = $this->writer($b);
        $preview = (new IssuancePreviewService($b))->preview($f['supplier'], $f['issue_request']);
        $batch = $writer->issue($f['supplier'], ['request' => $f['issue_request'], 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'lock-prefix'], $f['actor']);
        $journal = new \MyInvoice\Repository\JournalEntryRepository($b, new \MyInvoice\Service\ActivityLogger($b));
        $chargeId = null;
        if (in_array($path, ['replace', 'assignment', 'j5_preview'], true)) {
            $posted = $writer->post($f['supplier'], $batch['id'], array_map(fn ($charge) => ['id' => $charge['id'], 'row_version' => 1], $batch['charges']), $f['actor']);
            $chargeId = (int) $posted['charges'][0]['id'];
            $entry = (int) $posted['charges'][0]['journal_entry_id'];
            self::assertGreaterThan(0, $chargeId);
            self::assertGreaterThan(0, $entry);
        } else {
            $q = $b->pdo()->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code='315'");
            $q->execute([$f['supplier']]); $account = (int) $q->fetchColumn();
            $q = $b->pdo()->prepare('SELECT id FROM accounting_periods WHERE supplier_id=?');
            $q->execute([$f['supplier']]); $period = (int) $q->fetchColumn();
            $entry = $journal->insert(['supplier_id' => $f['supplier'], 'period_id' => $period, 'entry_date' => '2026-03-01', 'source_type' => 'cash', 'source_id' => 987654], [
                ['account_id' => $account, 'side' => 'debit', 'amount' => '0.01'],
                ['account_id' => $account, 'side' => 'credit', 'amount' => '0.01'],
            ]);
        }
        $before = $journal->findForUpdate($entry, $f['supplier']);
        self::assertNotEmpty($before['lines']);
        $b->pdo()->commit();
        $b->pdo()->beginTransaction();
        if ($chargeId !== null) {
            $claim = $b->pdo()->prepare('SELECT id FROM unit_charges WHERE supplier_id=? AND id=? AND journal_entry_id=? FOR UPDATE');
            $claim->execute([$f['supplier'], $chargeId, $entry]);
            self::assertSame($chargeId, (int) $claim->fetchColumn());
        } else {
            $b->pdo()->prepare('SELECT id FROM unit_charges WHERE supplier_id=? ORDER BY id FOR UPDATE')->execute([$f['supplier']]);
        }
        $a->pdo()->exec('SET SESSION innodb_lock_wait_timeout=1');
        $a->pdo()->beginTransaction();
        try {
            if ($path === 'restamp') (new \MyInvoice\Service\Accounting\Dimension\DimensionStamper($a))->restamp($f['supplier'], 'cash', 987654);
            elseif ($path === 'replace') (new \MyInvoice\Repository\JournalEntryRepository($a, new \MyInvoice\Service\ActivityLogger($a)))->replace($entry, $before, $before['lines']);
            elseif ($path === 'j5_preview') (new \MyInvoice\Service\Accounting\Allocation\JointAllocationPreviewService($a))->preview($f['supplier'], 2147483647, '2026-03-01', [(int) $before['lines'][0]['id']]);
            elseif ($path === 'j5_cancel') (new \MyInvoice\Service\Accounting\Allocation\JointAllocationJournalProof($a))->cancellation($f['supplier'], 2147483647);
            else (new \MyInvoice\Repository\DimensionAssignmentRepository($a))->insertLineDimensions($f['supplier'], (int) $before['lines'][0]['id'], [2147483647 => 2147483647]);
            self::fail('Source operation bypassed the earlier current claim lock.');
        } catch (\PDOException $e) {
            self::assertSame(1205, (int) ($e->errorInfo[1] ?? 0));
        }
        $q = $b->pdo()->prepare('SELECT id FROM journal_entries WHERE supplier_id=? AND id=? FOR UPDATE NOWAIT');
        $q->execute([$f['supplier'], $entry]);
        self::assertSame($entry, (int) $q->fetchColumn(), 'Writer must not retain a later source lock while waiting for claims.');
        $q = $b->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? ORDER BY id FOR UPDATE NOWAIT');
        $q->execute([$f['supplier'], $entry]);
        self::assertCount(count($before['lines']), $q->fetchAll());
    }

    public static function frameworks(): array { return [['cz_500'], ['cz_504']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('frameworks')]
    public function testNewCustomAssignmentsKeepTargetOnlyPeriodLocks(string $framework): void
    {
        [$a, $b] = $this->connections;
        $b->pdo()->beginTransaction();
        $f = $this->issuanceFixture($b);
        $periods = new \MyInvoice\Repository\AccountingPeriodRepository($b);
        $older = $periods->create($f['supplier'], 2025, '2025-01-01', '2025-12-31');
        $b->pdo()->prepare('UPDATE supplier_accounting_frameworks SET accounting_framework=? WHERE supplier_id=?')->execute([$framework, $f['supplier']]);
        $dims = new \MyInvoice\Repository\DimensionRepository($b);
        $type = $dims->createType($f['supplier'], false, ['code' => 'LOCK', 'name' => 'Synthetic custom', 'kind' => 'custom']);
        $value = $dims->createValue(['id' => $type, 'supplier_id' => $f['supplier'], 'supplier_group_id' => null], ['code' => 'V', 'name' => 'Synthetic value']);
        $q = $b->pdo()->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code='315'");
        $q->execute([$f['supplier']]); $account = (int) $q->fetchColumn();
        $q = $b->pdo()->prepare("SELECT id FROM accounting_periods WHERE supplier_id=? AND fiscal_year=2026");
        $q->execute([$f['supplier']]); $target = (int) $q->fetchColumn();
        $b->pdo()->commit();
        $b->pdo()->beginTransaction();
        $b->pdo()->prepare('SELECT id FROM accounting_periods WHERE supplier_id=? AND fiscal_year=2025 FOR UPDATE')->execute([$f['supplier']]);
        $a->pdo()->exec('SET SESSION innodb_lock_wait_timeout=1');
        $a->pdo()->beginTransaction();
        $journal = new \MyInvoice\Repository\JournalEntryRepository($a, new \MyInvoice\Service\ActivityLogger($a));
        $entry = $journal->insert(['supplier_id' => $f['supplier'], 'period_id' => $target, 'entry_date' => '2026-03-01', 'source_type' => 'manual', 'posted_at' => '2026-03-01 12:00:00'], [
            ['account_id' => $account, 'side' => 'debit', 'amount' => '0.01', 'dimensions' => [$type => $value]],
            ['account_id' => $account, 'side' => 'credit', 'amount' => '0.01', 'dimensions' => [$type => $value]],
        ]);
        self::assertGreaterThan(0, $entry);
        self::assertCount(2, (new \MyInvoice\Repository\DimensionAssignmentRepository($a))->entryLineDimensions($f['supplier'], $entry, true));
    }
}
