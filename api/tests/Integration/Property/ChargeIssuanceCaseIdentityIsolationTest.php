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
final class ChargeIssuanceCaseIdentityIsolationTest extends TestCase
{
    use ChargeIssuanceFixture;
    private PDO $server;
    private string $schema;
    private array $connections=[];

    protected function setUp(): void
    {
        $container=Bootstrap::buildContainer(); $config=$container->get(Config::class);
        $source=(string)$container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test',$source); self::assertContains($config->get('db.host'),['localhost','127.0.0.1','::1']);
        $this->schema='myucto_identity_race_'.bin2hex(random_bytes(5)).'_test';
        $this->server=new PDO('mysql:host='.$config->get('db.host').';port='.$config->get('db.port',3306).';charset=utf8mb4',
            $config->get('db.user'),$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $cloner=new DatabaseCloner($this->server); $plan=$cloner->plan($source);
        $cloner->copyTables($plan,$this->schema,static function(): void {}); $cloner->finalize($plan,$this->schema);
        $data=$config->all(); $data['db']['name']=$this->schema;
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
        if(isset($this->schema,$this->server)) {
            self::assertMatchesRegularExpression('/^myucto_identity_race_[a-f0-9]{10}_test$/D',$this->schema);
            $this->server->exec('DROP DATABASE IF EXISTS '.DatabaseCloner::quote($this->schema));
        }
    }

    public function testOldRepeatableReadSnapshotCannotMissAnotherOpaqueFamily(): void
    {
        [$a,$b]=$this->connections;
        $b->pdo()->beginTransaction();$f=$this->issuanceFixture($b);$b->pdo()->commit();
        $request=$f['issue_request'];$request['units']=[$request['units'][0]];
        $reload=$request;$reload['source_key']='RELOADED';
        $a->pdo()->exec('SET TRANSACTION READ ONLY');$a->pdo()->beginTransaction();
        $preview=(new IssuancePreviewService($a))->preview($f['supplier'],$reload);$a->pdo()->commit();
        $a->pdo()->beginTransaction();self::assertSame(0,(int)$a->pdo()->query('SELECT COUNT(*) FROM unit_charges WHERE supplier_id='.(int)$f['supplier'])->fetchColumn());
        $p=(new IssuancePreviewService($b))->preview($f['supplier'],$request);$writer=$this->writer($b);
        $first=$writer->issue($f['supplier'],['request'=>$request,'preview_hash'=>$p['preview_hash'],'idempotency_key'=>'first'],$f['actor']);
        $writer->post($f['supplier'],$first['id'],[['id'=>$first['charges'][0]['id'],'row_version'=>1]],$f['actor']);
        self::assertSame(0,(int)$a->pdo()->query('SELECT COUNT(*) FROM unit_charges WHERE supplier_id='.(int)$f['supplier'])->fetchColumn());
        try{$this->writer($a)->issue($f['supplier'],['request'=>$reload,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'reload'],$f['actor']);self::fail('Current identity was hidden by old RR snapshot.');}
        catch(IssuanceException $e){self::assertSame('property_charge_case_exists',$e->errorCode);}
        self::assertTrue($a->pdo()->inTransaction());$a->pdo()->rollBack();
        self::assertSame(1,(int)$b->pdo()->query('SELECT COUNT(*) FROM unit_charge_batches WHERE supplier_id='.(int)$f['supplier'])->fetchColumn());
        self::assertSame(1,(int)$b->pdo()->query("SELECT COUNT(*) FROM journal_entries WHERE source_type='unit_charge' AND supplier_id=".(int)$f['supplier'])->fetchColumn());
        $b->pdo()->beginTransaction();$other=$this->issuanceFixture($b);$b->pdo()->commit();
        $otherRequest=$other['issue_request'];$otherRequest['units']=[$otherRequest['units'][0]];
        self::assertSame($request['case_identity'],$otherRequest['case_identity']);
        $p=(new IssuancePreviewService($b))->preview($other['supplier'],$otherRequest);
        $second=$writer->issue($other['supplier'],['request'=>$otherRequest,'preview_hash'=>$p['preview_hash'],'idempotency_key'=>'tenant-independent'],$other['actor']);
        self::assertSame($other['supplier'],$second['supplier_id']);self::assertCount(1,$second['charges']);
    }

    public function testConcurrentInitialIsSerializedThenCurrentIdentityRefusesReload(): void
    {
        [$a,$b]=$this->connections;
        $b->pdo()->beginTransaction();$f=$this->issuanceFixture($b);$b->pdo()->commit();
        $request=$f['issue_request'];$request['units']=[$request['units'][0]];
        $reload=$request;$reload['source_key']='CONCURRENT-RELOAD';
        $p=(new IssuancePreviewService($a))->preview($f['supplier'],$reload);
        $input=['request'=>$reload,'preview_hash'=>$p['preview_hash'],'idempotency_key'=>'concurrent'];
        $b->pdo()->beginTransaction();$p=(new IssuancePreviewService($b))->preview($f['supplier'],$request);
        $first=$this->writer($b)->issue($f['supplier'],['request'=>$request,'preview_hash'=>$p['preview_hash'],'idempotency_key'=>'first'],$f['actor']);
        $a->pdo()->exec('SET SESSION innodb_lock_wait_timeout=1');
        try{$this->writer($a)->issue($f['supplier'],$input,$f['actor']);self::fail('Concurrent current catalogue was not locked.');}
        catch(\PDOException $e){self::assertSame(1205,(int)($e->errorInfo[1]??0));}
        self::assertFalse($a->pdo()->inTransaction());$b->pdo()->commit();
        try{$this->writer($a)->issue($f['supplier'],$input,$f['actor']);self::fail('Waiter created a second identity after commit.');}
        catch(IssuanceException $e){self::assertSame('property_charge_case_exists',$e->errorCode);}
        self::assertFalse($a->pdo()->inTransaction());
        self::assertSame(1,(int)$b->pdo()->query('SELECT COUNT(*) FROM unit_charge_batches WHERE supplier_id='.(int)$f['supplier'])->fetchColumn());
        self::assertSame($first['id'],(int)$b->pdo()->query('SELECT id FROM unit_charge_batches WHERE supplier_id='.(int)$f['supplier'])->fetchColumn());
    }

    private function writer(Connection $db): IssuanceRepository
    {
        $container=Bootstrap::buildContainer(); self::assertInstanceOf(\DI\Container::class,$container);
        $container->set(Connection::class,$db);
        return new IssuanceRepository($db,$container->get(PostingService::class),$container->get(\MyInvoice\Repository\JournalEntryRepository::class));
    }

}
