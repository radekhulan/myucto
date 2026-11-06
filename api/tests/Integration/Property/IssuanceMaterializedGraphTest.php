<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\{IssuanceRepository,IssuanceReadRepository};
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Property\{PropertyInput};
use MyInvoice\Service\Property\Charges\Issuance\{IssuanceException,IssuancePreviewService,IssuanceLocks};
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,OwnedTestDatabaseCleanup};
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class IssuanceMaterializedGraphTest extends TestCase
{
    use ChargeIssuanceFixture;
    private static Connection $connection;
    private static Config $config;
    private static PDO $server;
    private static string $schema;
    private static ?array $fixture=null;
    private Connection $db;
    private array $f;
    public static function setUpBeforeClass():void
    {
        $c=Bootstrap::buildContainer();$config=$c->get(Config::class);$source=$c->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();self::assertStringEndsWith('_test',$source);self::assertContains($config->get('db.host'),['localhost','127.0.0.1','::1']);self::$schema='myucto_issue_graph_'.bin2hex(random_bytes(5)).'_test';self::$server=new PDO('mysql:host='.$config->get('db.host').';port='.$config->get('db.port',3306).';charset=utf8mb4',$config->get('db.user'),$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$cloner=new DatabaseCloner(self::$server);$plan=$cloner->plan($source);$cloner->copyTables($plan,self::$schema,static function():void{});$cloner->finalize($plan,self::$schema);
        foreach(['lines','parties','notices'] as $table)foreach(['update','delete'] as $operation)self::$server->exec('DROP TRIGGER '.DatabaseCloner::quote(self::$schema).'.'.DatabaseCloner::quote('unit_charge_'.$table.'_immutable_'.$operation));self::$server->exec('DROP TRIGGER '.DatabaseCloner::quote(self::$schema).'.unit_charges_guard_update');$data=$config->all();$data['db']['name']=self::$schema;self::$config=new Config($data,$config->dataDir());self::$connection=Connection::withoutSharedTestConnection(fn()=>new Connection(self::$config));self::$connection->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }
    public static function tearDownAfterClass():void
    {
        if(isset(self::$connection)){if(self::$connection->pdo()->inTransaction())self::$connection->pdo()->rollBack();self::$connection->close();}if(isset(self::$server,self::$schema))OwnedTestDatabaseCleanup::drop(self::$server,self::$schema,[self::$schema]);
    }
    protected function setUp():void
    {
        $this->db=self::$connection;$c=Bootstrap::buildContainer();$c->set(Connection::class,$this->db);$p=$this->db->pdo();self::assertSame(self::$schema,$p->query('SELECT DATABASE()')->fetchColumn());if(self::$fixture===null){$p->beginTransaction();$f=$this->issuanceFixture($this->db);$preview=(new IssuancePreviewService($this->db))->preview($f['supplier'],$f['issue_request']);$batch=$c->get(IssuanceRepository::class)->issue($f['supplier'],['request'=>$f['issue_request'],'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'materialized-native'],$f['actor']);$p->commit();self::$fixture=$f+['batch'=>$batch];}$this->f=self::$fixture;$p->exec('SET SESSION innodb_snapshot_isolation=0');$p->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->beginTransaction();
    }
    protected function tearDown():void{if(isset($this->db)&&$this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    private function executeSql(string $sql,array $args):\PDOStatement{$q=$this->db->pdo()->prepare($sql);$q->execute($args);return $q;}
    private function charge():array{return $this->f['batch']['charges'][0];}
    private function jsonChange(string $table,string $column,callable $change):void
    {
        $s=$this->f['supplier'];$q=$this->executeSql('SELECT id,'.$column.' FROM '.$table.' WHERE supplier_id=? AND charge_id=? ORDER BY id LIMIT 1',[$s,$this->charge()['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);self::assertNotFalse($row);$json=$change(json_decode($row[$column],true,flags:JSON_THROW_ON_ERROR));$this->executeSql('UPDATE '.$table.' SET '.$column.'=? WHERE supplier_id=? AND id=?',[json_encode($json,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION),$s,$row['id']]);
    }
    public function testNativeReaderKeepsCanonicalObjectKeyOrderCompatibility():void
    {
        $reader=new IssuanceReadRepository($this->db);$s=$this->f['supplier'];$id=$this->charge()['id'];$before=$reader->find($s,$id,true);$this->jsonChange('unit_charge_lines','line_json',static fn($line)=>array_reverse($line,true));$this->jsonChange('unit_charge_parties','party_json',static fn($party)=>array_reverse($party,true));self::assertSame($before,$reader->find($s,$id,true));
    }
    public static function damage():array{return array_map(static fn($v)=>[$v],['line_scalar','line_graph','missing_line','party_graph','missing_party','notice_graph','missing_notice','rehash_source']);}
    #[DataProvider('damage')]
    public function testEveryCompleteNativeMaterializationRemainsMandatory(string $damage):void
    {
        $s=$this->f['supplier'];$id=$this->charge()['id'];
        if($damage==='line_scalar')$this->executeSql('UPDATE unit_charge_lines SET amount_cents=amount_cents+1 WHERE supplier_id=? AND charge_id=? ORDER BY id LIMIT 1',[$s,$id]);
        elseif($damage==='line_graph')$this->jsonChange('unit_charge_lines','line_json',static function($line){$line['amount_cents']=bcadd($line['amount_cents'],'1',0);return $line;});
        elseif($damage==='party_graph')$this->jsonChange('unit_charge_parties','party_json',static function($party){$party['evidence_ref']='Changed synthetic title';return $party;});
        elseif($damage==='notice_graph')$this->jsonChange('unit_charge_notices','notice_json',static function($notice){$notice['title_ref']='Changed synthetic notice';return $notice;});
        elseif(str_starts_with($damage,'missing_')){$table=match($damage){'missing_line'=>'lines','missing_party'=>'parties',default=>'notices'};$this->executeSql('DELETE FROM unit_charge_'.$table.' WHERE supplier_id=? AND charge_id=? ORDER BY id LIMIT 1',[$s,$id]);}
        else{$q=$this->executeSql('SELECT snapshot_json FROM unit_charges WHERE supplier_id=? AND id=?',[$s,$id]);$snapshot=json_decode($q->fetchColumn(),true,flags:JSON_THROW_ON_ERROR);$snapshot['lines'][0]['amount_cents']=bcadd($snapshot['lines'][0]['amount_cents'],'1',0);$this->executeSql('UPDATE unit_charges SET snapshot_json=?,snapshot_hash=? WHERE supplier_id=? AND id=?',[json_encode($snapshot,JSON_THROW_ON_ERROR),PropertyInput::hash($snapshot),$s,$id]);}
        $this->expectException(IssuanceException::class);(new IssuanceReadRepository($this->db))->find($s,$id,true);
    }
    public function testCurrentMaterializationCannotUseStaleRepeatableReadFacts():void
    {
        $s=$this->f['supplier'];$id=$this->charge()['id'];$p=$this->db->pdo();$q=$this->executeSql('SELECT id,amount_cents FROM unit_charge_lines WHERE supplier_id=? AND charge_id=? ORDER BY id LIMIT 1',[$s,$id]);$old=$q->fetch(PDO::FETCH_ASSOC);$other=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config(self::$config->all(),self::$config->dataDir())));$other->pdo()->exec('SET SESSION innodb_lock_wait_timeout=1');$u=$other->pdo()->prepare('UPDATE unit_charge_lines SET amount_cents=? WHERE supplier_id=? AND id=?');
        try{$u->execute([bcadd($old['amount_cents'],'1',0),$s,$old['id']]);self::assertSame($old['amount_cents'],$this->executeSql('SELECT amount_cents FROM unit_charge_lines WHERE supplier_id=? AND id=?',[$s,$old['id']])->fetchColumn());(new IssuanceLocks($this->db))->acquire($s);try{(new IssuanceReadRepository($this->db))->find($s,$id,true);self::fail('Committed native line change cannot hide behind staleRR');}catch(IssuanceException $e){self::assertSame('property_charge_issue_history_corrupt',$e->errorCode);}}
        finally{$p->rollBack();$u->execute([$old['amount_cents'],$s,$old['id']]);$other->close();}
    }
}