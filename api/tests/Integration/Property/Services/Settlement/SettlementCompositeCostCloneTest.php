<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Services\Settlement;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Services\Settlement\{SettlementDocumentRepository,SettlementApplicationRepository};
use MyInvoice\Tests\Support\CloneSandbox;
use PDO;
use PHPUnit\Framework\TestCase;
/**
 * Složený náklad (dva zdroje, 132 tis.) se staví jednou za třídu, testy pracují na
 * vlastních kopiích bez triggerů. Chybějící zachycení se ověřuje nad toutéž fixturou,
 * na podobě grafu nezáleží.
 */
final class SettlementCompositeCostCloneTest extends TestCase
{
    private static ?Config $config=null;
    private static ?PDO $server=null;
    private static ?string $sourceName=null;
    private static ?Connection $source=null;
    private static array $graph=[];
    private array $owned=[];
    private ?Connection $target=null;
    protected function setUp():void
    {
        if(self::$source!==null)return;
        $prior=getenv('MYINVOICE_DB_NAME');self::$config=Config::load(Bootstrap::rootDir());$base=(string)self::$config->get('db.name');self::assertStringEndsWith('_test',$base);self::assertContains(self::$config->get('db.host'),['localhost','127.0.0.1','::1']);
        self::$server=new PDO('mysql:host='.self::$config->get('db.host').';port='.self::$config->get('db.port',3306).';charset=utf8mb4',self::$config->get('db.user'),self::$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        self::$sourceName=CloneSandbox::acquire(self::$server,$base,true);
        try{
            putenv('MYINVOICE_DB_NAME='.self::$sourceName);$config=self::$config->all();$config['db']['name']=self::$sourceName;self::$source=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($config,self::$config->dataDir())));self::$source->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");self::$source->pdo()->beginTransaction();$container=Bootstrap::buildContainer();$container->set(Connection::class,self::$source);
            $fixture=new SettlementCompositeCostTest('testReferenceNativeCompositeClears132kCostAndAdvanceWithoutInventedEntry');foreach(['db'=>self::$source,'container'=>$container] as $key=>$value)(new \ReflectionProperty($fixture,$key))->setValue($fixture,$value);
            $native=static fn(string $method,mixed ...$args):mixed=>(new \ReflectionMethod($fixture,$method))->invoke($fixture,...$args);
            $d=$native('base',false,true);$p=$native('applied',$d);self::$source->pdo()->commit();self::$graph=['d'=>$d,'p'=>$p];
        }finally{putenv($prior===false?'MYINVOICE_DB_NAME':'MYINVOICE_DB_NAME='.$prior);}
    }
    protected function tearDown():void
    {
        if($this->target!==null){if($this->target->pdo()->inTransaction())$this->target->pdo()->rollBack();$this->target->close();$this->target=null;}
        foreach($this->owned as $schema)CloneSandbox::release(self::$server,$schema);$this->owned=[];
    }
    public static function tearDownAfterClass():void
    {
        try{if(self::$source!==null){if(self::$source->pdo()->inTransaction())self::$source->pdo()->rollBack();self::$source->close();}if(self::$sourceName!==null)CloneSandbox::release(self::$server,self::$sourceName);}
        finally{self::$source=null;self::$sourceName=null;self::$graph=[];}
    }
    private function copy(bool $anonymized,string $seed=''):Connection
    {
        $schema=$anonymized?CloneSandbox::anonymized(self::$server,self::$config,self::$sourceName,$seed):CloneSandbox::acquire(self::$server,self::$sourceName);$this->owned[]=$schema;
        $config=self::$config->all();$config['db']['name']=$schema;return $this->target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($config,self::$config->dataDir())));
    }
    private function fingerprint(Connection $db):string
    {
        $all=[];foreach(['property_service_settlements','property_service_lifecycle_cases','property_service_lifecycle_events','property_service_settlement_applications'] as $table)$all[$table]=$db->pdo()->query('SELECT * FROM '.$table.' ORDER BY supplier_id,id')->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',serialize($all));
    }
    public function testActualFullClonePreservesAllCompositeSourcesAndNativeReverse():void
    {
        ['d'=>$d,'p'=>$p]=self::$graph;$before=$this->fingerprint(self::$source);$this->copy(true,'synthetic-composite-cost-clone');self::assertSame($before,$this->fingerprint(self::$source));$this->target->pdo()->beginTransaction();$container=Bootstrap::buildContainer();$container->set(Connection::class,$this->target);$doc=$container->get(SettlementDocumentRepository::class)->find($d['f']['supplier'],$p['saved']['id'],true);self::assertCount(2,$doc['document']['cost']['sources']);self::assertSame('13200000',$doc['document']['cost']['amount_cents']);self::assertSame(array_column($p['cost']['sources'],'source_id'),array_column($doc['document']['cost']['sources'],'source_id'));$json=json_encode($doc['document'],JSON_THROW_ON_ERROR);foreach(['composite.known@synthetic.invalid','composite.policy@synthetic.invalid','composite.final@synthetic.invalid','synthetic.context@example.test'] as $secret)self::assertStringNotContainsString($secret,$json);$closure=(new \MyInvoice\Repository\Property\Receivables\PropertySaldoReadClosure($this->target))->units($d['f']['supplier'],[$d['f']['units']['U02']]);self::assertNotEmpty($closure->rows['property_service_settlement_ledgers']);$owner=$container->get(SettlementApplicationRepository::class);$reverse=$owner->reverse($d['f']['supplier'],$p['done']['id'],'2027-04-02',1,'Synthetic native composite clone reversal','composite-clone-reverse',$d['f']['actor']);self::assertGreaterThan(0,$reverse['id']);self::assertTrue((new \MyInvoice\Service\Property\Receivables\PropertyReconciliation($this->target))->at($d['f']['supplier'],'2027-04-02',$d['f']['units']['U02'])['matches']);self::assertSame($before,$this->fingerprint(self::$source));$this->target->pdo()->rollBack();
    }

    public function testMissingNativeCaptureFailsClosedBeforeCompositeCloneAcceptance():void
    {
        $before=$this->fingerprint(self::$source);$this->copy(false);$this->target->pdo()->beginTransaction();try{\MyInvoice\Service\Anonymization\SettlementCostCloneProof::verify($this->target,[]);self::fail('Composite clone accepted without its native pre-transform proof.');}catch(\LogicException $error){self::assertSame('Composite service clone requires its original native proof capture.',$error->getMessage());}self::assertSame($before,$this->fingerprint($this->target));$this->target->pdo()->rollBack();
    }
}
