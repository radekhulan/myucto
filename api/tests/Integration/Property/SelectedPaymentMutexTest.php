<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Tests\Support\CloneSandbox;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Payments\{PaymentLocks,PaymentSourceProof,PaymentException};
use MyInvoice\Repository\Property\Payments\PaymentRepository;
use PDO;
use PHPUnit\Framework\TestCase;
final class SelectedPaymentMutexTest extends TestCase
{
    use \MyInvoice\Tests\Support\ChargeIssuanceFixture;
    use \MyInvoice\Tests\Support\PaymentCorrectionFixture;
    use \MyInvoice\Tests\Support\ReceivableFixture;
    private Connection $db;
    private array $f;
    private string $ownedSchema;
    private PDO $server;
    private array $connections=[];
    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $config = $container->get(Config::class);
        $source = (string) $container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $source);
        self::assertTrue(\MyInvoice\Tests\Support\TestDatabaseHost::isAllowed((string) ($config->get('db.host'))), 'Testovací DB musí být lokální nebo v db.test_allowed_hosts.');
        $this->server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->ownedSchema = CloneSandbox::acquire($this->server, $source, true);
        $data = $config->all();
        $data['db']['name'] = $this->ownedSchema;
        for ($i = 0; $i < 2; ++$i) {
            $db = Connection::withoutSharedTestConnection(fn () => new Connection(new Config($data, $config->dataDir())));
            $db->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $db->pdo()->exec('SET SESSION innodb_snapshot_isolation = 0');
            $db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connections[] = $db;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
            $db->close();
        }
        self::assertMatchesRegularExpression('/^myucto_clonesandbox_[a-f0-9]{10}_test$/D', $this->ownedSchema);
        self::assertTrue(CloneSandbox::isLeased($this->ownedSchema));
        CloneSandbox::dropOrRelease($this->server, $this->ownedSchema, [$this->ownedSchema]);
    }


    public function testSupplierMutexAloneBlocksPaymentAndOrdinarySourceMutation():void
    {
        [$a,$b]=$this->connections;$this->db=$a;$a->pdo()->beginTransaction();$this->f=$this->issuanceFixture($a);$f=$this->f;$bank=$this->correctionBank('100.00');$a->pdo()->commit();
        $request=$this->creditRequest($f,$bank);$writer=$this->writer($b);
        $a->pdo()->beginTransaction();$q=$a->pdo()->prepare('SELECT id FROM supplier WHERE id=? FOR UPDATE');$q->execute([$f['supplier']]);self::assertSame($f['supplier'],(int)$q->fetchColumn());
        $b->pdo()->exec('SET SESSION innodb_lock_wait_timeout=1');
        try{$writer->preview($f['supplier'],$request);self::fail('Payment bypassed supplier mutex.');}
        catch(\PDOException $e){self::assertSame(1205,(int)$e->errorInfo[1]);}
        self::assertFalse($b->pdo()->inTransaction());
        $b->pdo()->beginTransaction();
        try{(new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($b))->assertSourceMutable($f['supplier'],'bank',$bank);self::fail('Ordinary source mutation bypassed supplier mutex.');}
        catch(\PDOException $e){self::assertSame(1205,(int)$e->errorInfo[1]);}
        finally{if($b->pdo()->inTransaction())$b->pdo()->rollBack();}
        $a->pdo()->rollBack();
        self::assertSame('10000',$writer->preview($f['supplier'],$request)['snapshot']['credit']['amount_cents']);
    }
    public function testPrefixDoesNotScanEveryDebtButSelectedProofStillLocksCurrentDebt():void
    {
        [$a,$b]=$this->connections;$this->db=$a;$a->pdo()->beginTransaction();$this->f=$this->issuanceFixture($a);$base=$this->settledPaymentFixture('1','0');$f=$this->f;$a->pdo()->commit();
        $a->pdo()->beginTransaction();$q=$a->pdo()->prepare('SELECT id FROM unit_payment_debts WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$f['supplier'],$base['debt']['id']]);self::assertSame($base['debt']['id'],(int)$q->fetchColumn());
        $b->pdo()->exec('SET SESSION innodb_lock_wait_timeout=1');$b->pdo()->beginTransaction();
        (new PaymentLocks($b))->acquire($f['supplier']);
        try{(new \MyInvoice\Repository\Property\Payments\DebtRepository($b))->currentMany($f['supplier'],[$base['debt']['id']]);self::fail('Selected current read lost its row lock.');}
        catch(\PDOException $e){self::assertSame(1205,(int)$e->errorInfo[1]);}
        finally{if($b->pdo()->inTransaction())$b->pdo()->rollBack();$a->pdo()->rollBack();}
        $b->pdo()->beginTransaction();
        try{self::assertCount(1,(new \MyInvoice\Repository\Property\Payments\DebtRepository($b))->currentMany($f['supplier'],[$base['debt']['id']]));}
        finally{$b->pdo()->rollBack();}
    }
    public function testOldRrSnapshotCannotHideNewSourceClaimAfterMutexAcquisition():void
    {
        [$a,$b]=$this->connections;$this->db=$a;$a->pdo()->beginTransaction();$this->f=$this->issuanceFixture($a);$f=$this->f;$bank=$this->correctionBank('100.00');$a->pdo()->commit();
        $a->pdo()->beginTransaction();$a->pdo()->query('SELECT COUNT(*) FROM unit_payment_sources')->fetchColumn();
        $request=$this->creditRequest($f,$bank);$request['amount_cents']='6000';$writer=$this->writer($b);
        $writer->apply($f['supplier'],$request,$writer->preview($f['supplier'],$request)['preview_hash'],'supplier-mutex-winner',$f['actor']);
        (new PaymentLocks($a))->acquire($f['supplier']);$source=(new PaymentSourceProof($a))->current($f['supplier'],'bank',$bank);
        self::assertSame('6000',$source['consumed_cents']);self::assertSame('4000',$source['available_cents']);
        try{(new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($a))->assertSourceMutable($f['supplier'],'bank',$bank);self::fail('Stale RR snapshot hid current source ownership.');}
        catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('property_payment_source_immutable',$e->errorCode);}
        finally{$a->pdo()->rollBack();}
    }
    public function testOptimizedMembershipMatchesBigintDiscoveryForSyntheticJsonTypes():void
    {
        $sql=<<<'SQL'
SELECT EXISTS (SELECT 1 FROM JSON_TABLE(r.payload,'$[*]' COLUMNS(value BIGINT PATH '$')) refs WHERE refs.value IN (1,2)) AS original_result,
CASE WHEN JSON_EXTRACT(r.payload,'$[*]') REGEXP '[".eE]'
THEN EXISTS (SELECT 1 FROM JSON_TABLE(r.payload,'$[*]' COLUMNS(value BIGINT PATH '$')) refs WHERE refs.value IN (1,2))
ELSE JSON_OVERLAPS(JSON_EXTRACT(r.payload,'$[*]'),'[1,2]') END AS optimized_result
FROM (SELECT ? AS payload) r
SQL;
        $q=$this->connections[0]->pdo()->prepare($sql);
        foreach(['[]','[1]','[3]','[1,3]','["1"]','["01"]','[1.6]','[1e0]','[true]','[false]','[null]','[[1]]','[{"x":1}]'] as $document){
            $q->execute([$document]);$row=$q->fetch(PDO::FETCH_ASSOC);
            self::assertSame((bool)$row['original_result'],(bool)$row['optimized_result'],$document);
        }
    }
    public function testSnapshotDiscoveryRejectsMissingMaterializedPaymentAllocation():void
    {
        $this->db=$this->connections[0];$this->db->pdo()->beginTransaction();
        $this->f=$this->issuanceFixture($this->db);$base=$this->settledPaymentFixture('1000','0');$supplier=$this->f['supplier'];
        $reader=new \MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository($this->db);
        self::assertArrayHasKey($base['debt']['id'],$reader->readForDebts($supplier,[$base['debt']['id']])['debts']);
        $this->db->pdo()->commit();
        $this->dropOwnedTrigger('unit_payment_allocations_immutable_delete');
        $q=$this->db->pdo()->prepare('DELETE FROM unit_payment_allocations WHERE supplier_id=? AND operation_id=?');
        $q->execute([$supplier,$base['operation']['id']]);self::assertGreaterThan(0,$q->rowCount());
        $this->db->pdo()->beginTransaction();
        $this->assertCorrupt(fn()=> $reader->read($supplier,true));
        $this->assertCorrupt(fn()=> $reader->readForDebts($supplier,[$base['debt']['id']]));
    }
    public function testSnapshotDiscoveryRejectsMissingMaterializedPartyLink():void
    {
        $this->db=$this->connections[0];$this->db->pdo()->beginTransaction();
        $this->f=$this->issuanceFixture($this->db);$base=$this->settledPaymentFixture('1000','0');$supplier=$this->f['supplier'];
        $reader=new \MyInvoice\Repository\Property\Receivables\PartyEventRepository($this->db);
        $old=$base['debt']['snapshot']['liability_groups'][0]['members'][0];
        $request=$this->request($base,$this->f['clients'][2],1,null,'2026-02-02T10:00:00+01:00',[$old]);
        $event=$reader->confirm($supplier,$request,$reader->preview($supplier,$request)['preview_hash'],'missing-party-link',$this->f['actor']);
        self::assertSame([$event['id']],array_column($reader->historyForDebts($supplier,[$base['debt']['id']])['events'],'id'));
        $this->db->pdo()->commit();
        $this->dropOwnedTrigger('trg_property_party_debt_no_delete');
        $this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        try{
            $q=$this->db->pdo()->prepare('DELETE FROM property_claim_party_event_debts WHERE supplier_id=? AND event_id=?');
            $q->execute([$supplier,$event['id']]);self::assertSame(1,$q->rowCount());
        }finally{$this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');}
        $this->db->pdo()->beginTransaction();
        $this->assertCorrupt(fn()=> $reader->history($supplier,true));
        $this->assertCorrupt(fn()=> $reader->historyForDebts($supplier,[$base['debt']['id']]));
    }
    private function dropOwnedTrigger(string $trigger):void
    {
        self::assertMatchesRegularExpression('/^myucto_clonesandbox_[a-f0-9]{10}_test$/D',$this->ownedSchema);
        self::assertTrue(CloneSandbox::isLeased($this->ownedSchema));
        self::assertSame($this->ownedSchema,$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        self::assertContains($trigger,['unit_payment_allocations_immutable_delete','trg_property_party_debt_no_delete']);
        $this->server->exec('DROP TRIGGER '.DatabaseCloner::quote($this->ownedSchema).'.'.DatabaseCloner::quote($trigger));
    }
    private function assertCorrupt(callable $read):void
    {
        try{$read();self::fail('Missing materialized history passed validation.');}
        catch(\MyInvoice\Service\Property\PropertyException $e){self::assertSame('property_receivable_history_corrupt',$e->errorCode);}
    }
    private function creditRequest(array $f,int $bank):array
    {
        return ['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'10000','accounting_date'=>'2026-02-01','reason'=>'Synthetic independently entitled credit','debts'=>[],
            'credit_entitlement'=>['third_party' => ['basis' => 'other_documented', 'evidence' => 'Synthetic documented creditor title'], 'unit_id'=>array_values($f['units'])[0],'party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic creditor title','due_date'=>'2026-03-01']];
    }
    private function writer(Connection $db):PaymentRepository
    {
        $container=Bootstrap::buildContainer();$container->set(Connection::class,$db);return new PaymentRepository($db,$container->get(PostingService::class));
    }
}
