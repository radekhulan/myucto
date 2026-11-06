<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Support;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository,ChartOfAccountsRepository};
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Repository\Property\Payments\DebtRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Anonymization\{AnonymizationOptions,AnonymizationService,ChargesCloneScrubber,DatabaseCloner,IssuanceCloneScrubber,PaymentsCloneScrubber,PropertyCloneScrubber,Pseudonymizer,SettlementCloneScrubber,TextScrubber};
use MyInvoice\Service\Property\Opening\{OpeningException,OpeningReadProof};
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Tests\Support\{CloneSandbox,PropertyChargeFixture};
use PDO;
use PHPUnit\Framework\TestCase;
abstract class PropertyCloneBatchFixtureTestCase extends TestCase
{
    use PropertyChargeFixture;
    protected static Config $config;
    protected static PDO $server;
    protected static Connection $source;
    protected static array $owned=[];
    protected static array $fixtures=[];
    protected static array $plan=[];
    protected array $targets=[];
    /** Kopie zapůjčené testem, vrací se v tearDown(). */
    protected array $leased=[];
    public static function setUpBeforeClass():void
    {
        self::$fixtures=[];self::$owned=[];
        $c=Bootstrap::buildContainer();$config=$c->get(Config::class);$source=$c->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test',$source);self::assertContains($config->get('db.host'),['localhost','127.0.0.1','::1']);
        self::$server=new PDO('mysql:host='.$config->get('db.host').';port='.$config->get('db.port',3306).';charset=utf8mb4',$config->get('db.user'),$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $schema=CloneSandbox::acquire(self::$server,$source);self::$owned[]=$schema;self::$plan=(new DatabaseCloner(self::$server))->plan($source);
        $data=$config->all();$data['db']['name']=$schema;self::$config=new Config($data,$config->dataDir());self::$source=self::connect($schema);
        self::$source->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }
    public static function tearDownAfterClass():void
    {
        if(isset(self::$source)){if(self::$source->pdo()->inTransaction())self::$source->pdo()->rollBack();self::$source->close();}
        if(isset(self::$server))foreach(array_reverse(self::$owned) as $schema)CloneSandbox::dropOrRelease(self::$server,$schema,self::$owned);self::$owned=[];
    }
        protected static function connect(string $schema):Connection{$data=self::$config->all();$data['db']['name']=$schema;return Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($data,self::$config->dataDir())));}
    protected function setUp():void
    {
        if(self::$fixtures!==[])return;
        $db=self::$source;$db->pdo()->beginTransaction();$c=Bootstrap::buildContainer();$c->set(Connection::class,$db);
        try{foreach([12,3] as $count){
            $f=$this->chargeFixture($db);$s=$f['supplier'];(new AccountingPeriodRepository($db))->create($s,2026,'2026-01-01','2026-12-31');(new ChartOfAccountsSeeder($db))->seedForSupplier($s,'2026-01-01');
            $accounts=new ChartOfAccountsRepository($db);foreach(['315.100','379.910'] as $code)if($accounts->findByCode($s,$code)===null){$parent=$accounts->findByCode($s,substr($code,0,3));$accounts->insert($s,['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
            $common=['unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'origin_at'=>'2026-01-01T00:00:00+01:00','period_from'=>'2026-01-01','period_to'=>'2026-01-01','due_date'=>'2026-01-02','occurrence_ref'=>'2026','title_ref'=>'Synthetic title person@example.test','evidence_ref'=>'Synthetic reconciliation person@example.test'];
            $debt=$common+['kind'=>'debt','components'=>[['key'=>'principal','role'=>'principal','account_code'=>'315.100','amount_cents'=>'1000000']],'liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic personal claim','valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding']];
            $items=[];for($n=0;$n<$count;$n++)$items[]=$debt+['case_ref'=>'synthetic-debt-'.$n];
            $items[]=$common+['case_ref'=>'synthetic-credit','kind'=>'credit','components'=>[['key'=>'credit','role'=>'credit','account_code'=>'379.910','amount_cents'=>(string)($count*1000000)]],'creditor_ids'=>[$f['clients'][18]]];
            $request=['mode'=>'post_new','accounting_date'=>'2026-01-01','effective_at'=>'2026-01-01T00:00:00+01:00','expected_revision'=>0,'evidence_ref'=>'Synthetic signed takeover person@example.test','reason'=>'Synthetic opening','items'=>$items];
            $repo=$c->get(OpeningRepository::class);$preview=$repo->preview($s,$request);$batch=$repo->apply($s,$request,$preview['preview_hash'],'clone-batch-fixture',$f['actor']);self::assertSame('posted',$batch['status']);self::$fixtures[]=$f+['count'=>$count,'batch'=>$batch];
        }$db->pdo()->commit();(new DatabaseCloner(self::$server))->finalize(self::$plan,(string)self::$config->get('db.name'));}catch(\Throwable $e){if($db->pdo()->inTransaction())$db->pdo()->rollBack();throw $e;}
    }
    protected function tearDown():void{foreach($this->targets as $db){if($db->pdo()->inTransaction())$db->pdo()->rollBack();$db->close();}$this->targets=[];foreach($this->leased as $schema)CloneSandbox::release(self::$server,$schema);$this->leased=[];}
    protected function copy():Connection
    {
        $schema=CloneSandbox::acquire(self::$server,(string)self::$config->get('db.name'));$this->leased[]=$schema;$db=self::connect($schema);$this->targets[]=$db;return $db;
    }
    /** Kopie zdroje přepsaná celou produkční transformací anonymizace (bez triggerů). */
    protected function anonymized(string $seed):Connection
    {
        $schema=CloneSandbox::anonymized(self::$server,self::$config,(string)self::$config->get('db.name'),$seed);$this->leased[]=$schema;$db=self::connect($schema);$this->targets[]=$db;return $db;
    }
    protected function prefix(Connection $db):array
    {
        $scrubber=new TextScrubber(new Pseudonymizer(str_repeat('synthetic-payments-clone-',3)));$inputs=SettlementCloneScrubber::captureInputs($db);
        $registry=(new PropertyCloneScrubber($db->pdo(),$scrubber))->run();$versions=(new ChargesCloneScrubber($db->pdo(),$scrubber))->run($registry);$issuance=(new IssuanceCloneScrubber($db,$scrubber))->run($registry,$versions);
        return [$scrubber,$issuance,$registry,$inputs];
    }
    protected function financial(Connection $db):array
    {
        $p=$db->pdo();$p->beginTransaction();try{$out=[];foreach(self::$fixtures as $f){$s=$f['supplier'];$proof=(new OpeningReadProof($db))->items($s,array_column($f['batch']['items'],'id'),true);self::assertCount($f['count']+1,$proof);$debts=(new DebtRepository($db))->allCurrent($s);self::assertCount($f['count'],$debts);
            foreach($debts as $d)$out['debts'][]=[$s,$d['id'],$d['active'],$d['snapshot']['amount_cents'],$d['snapshot']['due_date'],$d['paid'],$d['group_paid']];
            foreach(['journal_entries'=>'id,entry_date,source_type,source_id,reversed_by','journal_entry_lines'=>'id,entry_id,account_id,side,amount,amount_foreign,currency_code,fx_rate','unit_credit_lots'=>'id,unit_id,amount_cents,due_date,opening_item_id'] as $table=>$columns){$q=$p->prepare('SELECT '.$columns.' FROM '.$table.' WHERE supplier_id=? ORDER BY id');$q->execute([$s]);$out[$table][$s]=$q->fetchAll(PDO::FETCH_ASSOC);}
        }return $out;}finally{$p->rollBack();}
    }
    protected function fingerprint(Connection $db):string
    {
        $out=[];foreach(['property_opening_batches','property_opening_items','property_opening_components','unit_payment_debts','unit_credit_lots','journal_entries','journal_entry_lines'] as $table)$out[$table]=$db->pdo()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);return PropertyInput::hash($out);
    }
}
