<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Property\Charges\Issuance\{IssuanceException,IssuancePreviewService,IssuanceUseGuard};
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,OwnedTestDatabaseCleanup};
use PDO;
use PHPUnit\Framework\TestCase;
final class IssuanceClaimScopeTest extends TestCase
{
    use ChargeIssuanceFixture;
    private static string $schema;
    private static PDO $server;
    private static Config $config;
    private array $connections=[];
    private array $f;
    private array $batch;
    public static function setUpBeforeClass():void
    {
        $container=Bootstrap::buildContainer();self::$config=$container->get(Config::class);$source=$container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();self::assertStringEndsWith('_test',$source);self::assertContains(self::$config->get('db.host'),['localhost','127.0.0.1','::1']);self::$schema='myucto_issue_scope_'.bin2hex(random_bytes(5)).'_test';
        self::$server=new PDO('mysql:host='.self::$config->get('db.host').';port='.self::$config->get('db.port',3306).';charset=utf8mb4',self::$config->get('db.user'),self::$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$cloner=new DatabaseCloner(self::$server);$plan=$cloner->plan($source);$cloner->copyTables($plan,self::$schema,static function():void{});$cloner->finalize($plan,self::$schema);
    }
    public static function tearDownAfterClass():void
    {if(isset(self::$server,self::$schema)){self::assertMatchesRegularExpression('/^myucto_issue_scope_[a-f0-9]{10}_test$/D',self::$schema);OwnedTestDatabaseCleanup::drop(self::$server,self::$schema,[self::$schema]);}}
    protected function setUp():void
    {
        $data=self::$config->all();$data['db']['name']=self::$schema;
        for($i=0;$i<2;$i++){$db=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($data,self::$config->dataDir())));$db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");$db->pdo()->exec('SET SESSION innodb_snapshot_isolation=0');$db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->pdo()->exec('SET SESSION innodb_lock_wait_timeout=1');$this->connections[]=$db;}
        $a=$this->connections[0];$a->pdo()->beginTransaction();$this->f=$this->issuanceFixture($a);$preview=(new IssuancePreviewService($a))->preview($this->f['supplier'],$this->f['issue_request']);$this->batch=$this->owner($a)->issue($this->f['supplier'],['request'=>$this->f['issue_request'],'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'claim-scope-batch'],$this->f['actor']);$a->pdo()->commit();
    }
    protected function tearDown():void
    {foreach($this->connections as $db){if($db->pdo()->inTransaction())$db->pdo()->rollBack();$db->close();}}
    private function owner(Connection $db):IssuanceRepository{$c=Bootstrap::buildContainer();$c->set(Connection::class,$db);return $c->get(IssuanceRepository::class);}
    private function post(Connection $db):array{return $this->owner($db)->post($this->f['supplier'],$this->batch['id'],array_map(static fn($charge)=>['id'=>$charge['id'],'row_version'=>1],$this->batch['charges']),$this->f['actor']);}
    public function testUnrelatedClaimRowLockDoesNotBlockExactEntryGuard():void
    {
        [$a,$b]=$this->connections;$posted=$this->post($a);$c=Bootstrap::buildContainer();$c->set(Connection::class,$a);$manual=$c->get(PostingService::class)->postDocument($this->f['supplier'],'manual',null,[['account_code'=>'221','side'=>'debit','amount'=>'1.00'],['account_code'=>'211','side'=>'credit','amount'=>'1.00']],['entry_date'=>'2026-01-01','user_id'=>$this->f['actor'],'posted_by'=>$this->f['actor']]);
        $a->pdo()->beginTransaction();$q=$a->pdo()->prepare('SELECT id FROM unit_charges WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$this->f['supplier'],$posted['charges'][0]['id']]);self::assertNotFalse($q->fetchColumn());$b->pdo()->beginTransaction();
        (new IssuanceUseGuard($b))->assertEntriesMutable($this->f['supplier'],[$manual]);self::assertTrue($b->pdo()->inTransaction());
    }
    public function testStaleRepeatableReadSnapshotCannotHideNewNativeClaim():void
    {
        [$a,$b]=$this->connections;$a->pdo()->beginTransaction();$q=$a->pdo()->prepare('SELECT status FROM unit_charges WHERE supplier_id=? AND id=?');$q->execute([$this->f['supplier'],$this->batch['charges'][0]['id']]);self::assertSame('draft',$q->fetchColumn());$posted=$this->post($b);$q->execute([$this->f['supplier'],$this->batch['charges'][0]['id']]);self::assertSame('draft',$q->fetchColumn());
        try{(new IssuanceUseGuard($a))->assertEntriesMutable($this->f['supplier'],[$posted['charges'][0]['journal_entry_id']]);self::fail('Current source claim must remain visible after acquiring the supplier mutex');}catch(IssuanceException $e){self::assertSame('property_charge_issue_source_immutable',$e->errorCode);}
    }
    public function testSelectedClaimRetainsCurrentRowLockAndImmutableSourceCheck():void
    {
        [$a,$b]=$this->connections;$posted=$this->post($a);$a->pdo()->beginTransaction();$q=$a->pdo()->prepare('SELECT id FROM unit_charges WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$this->f['supplier'],$posted['charges'][0]['id']]);self::assertNotFalse($q->fetchColumn());$b->pdo()->beginTransaction();
        try{(new IssuanceUseGuard($b))->assertEntriesMutable($this->f['supplier'],[$posted['charges'][0]['journal_entry_id']]);self::fail('Selected current claim row lock must still serialize the mutation');}catch(\PDOException $e){self::assertSame(1205,(int)$e->errorInfo[1]);}finally{$b->pdo()->rollBack();$a->pdo()->rollBack();}
        $b->pdo()->beginTransaction();try{(new IssuanceUseGuard($b))->assertEntriesMutable($this->f['supplier'],[$posted['charges'][0]['journal_entry_id']]);self::fail('Committed selected claim must remain immutable');}catch(IssuanceException $e){self::assertSame('property_charge_issue_source_immutable',$e->errorCode);}
    }
    public function testOriginalReversalLineAndSourceGuardsKeepExactOwnershipAndFullLockApi():void
    {
        [$a,$b]=$this->connections;$posted=$this->post($a);$charge=$posted['charges'][0];$s=$this->f['supplier'];$reversed=$this->owner($a)->reverse($s,$charge['id'],['row_version'=>$charge['row_version'],'accounting_date'=>'2026-02-01','effective_at'=>'2026-02-01T12:00:00+01:00','reason'=>'Synthetic owned reversal','evidence_ref'=>'Synthetic reversal title','idempotency_key'=>'scope-native-reversal'],$this->f['actor']);
        $q=$a->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? ORDER BY id LIMIT 1');$q->execute([$s,$reversed['reversal_entry_id']]);$line=(int)$q->fetchColumn();self::assertGreaterThan(0,$line);
        $a->pdo()->beginTransaction();$all=(new IssuanceUseGuard($a))->lockClaims($s);self::assertCount(12,$all);$a->pdo()->rollBack();
        foreach([static fn($g)=>$g->assertEntriesMutable($s,[$charge['journal_entry_id']]),static fn($g)=>$g->assertEntriesMutable($s,[$reversed['reversal_entry_id']]),static fn($g)=>$g->assertLinesMutable($s,[$line]),static fn($g)=>$g->assertSourcesMutable($s,[['unit_charge',$charge['id']]]),static fn($g)=>$g->assertAssignmentInsertsAfterPeriodPreflight($s,[$line])] as $check){
            $b->pdo()->beginTransaction();try{$check(new IssuanceUseGuard($b));self::fail('Exact native original/reversal/line/source ownership must remain immutable');}catch(IssuanceException|\MyInvoice\Service\Accounting\Dimension\DimensionException|\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('property_charge_issue_source_immutable',$e->errorCode);}finally{$b->pdo()->rollBack();}
        }
    }

    public function testIdOnlyCatalogLockKeepsHouseUnitAndServiceRowMutexes():void
    {
        [$a,$b]=$this->connections;$a->pdo()->beginTransaction();(new \MyInvoice\Repository\Property\PropertyReadRepository($a))->lockObjects($this->f['supplier']);
        foreach([$this->f['house']['id'],$this->f['service']['id'],array_values($this->f['units'])[11]] as $id){$b->pdo()->beginTransaction();try{$q=$b->pdo()->prepare('SELECT id FROM property_objects WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$this->f['supplier'],$id]);self::fail('Identity-only projection must keep the exact house/unit/service row mutex');}catch(\PDOException $e){self::assertSame(1205,(int)$e->errorInfo[1]);}finally{$b->pdo()->rollBack();}}
        $a->pdo()->rollBack();$b->pdo()->beginTransaction();$q=$b->pdo()->prepare('SELECT id FROM property_objects WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$this->f['supplier'],array_values($this->f['units'])[11]]);self::assertNotFalse($q->fetchColumn());
    }

}
