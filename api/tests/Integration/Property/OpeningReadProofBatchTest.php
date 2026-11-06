<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingPeriodRepository,ChartOfAccountsRepository};
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Property\Opening\{OpeningReadProof,OpeningException};
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Tests\Support\{PropertyChargeFixture,OwnedTestDatabaseCleanup};
use MyInvoice\Service\Anonymization\DatabaseCloner;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class OpeningReadProofBatchTest extends TestCase
{
    use PropertyChargeFixture;
    private static ?array $fixture=null;
    private static Connection $connection;
    private static Config $config;
    private Connection $db;
    private array $f;
    private static PDO $server;
    private static string $schema;
    public static function setUpBeforeClass():void
    {
        $c=Bootstrap::buildContainer();$config=$c->get(Config::class);$source=$c->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();self::assertStringEndsWith('_test',$source);self::assertContains($config->get('db.host'),['localhost','127.0.0.1','::1']);self::$schema='myucto_opening_batch_'.bin2hex(random_bytes(5)).'_test';self::$server=new PDO('mysql:host='.$config->get('db.host').';port='.$config->get('db.port',3306).';charset=utf8mb4',$config->get('db.user'),$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$cloner=new DatabaseCloner(self::$server);$plan=$cloner->plan($source);$cloner->copyTables($plan,self::$schema,static function():void{});$cloner->finalize($plan,self::$schema);
        foreach(['states','links','recipients','items','components','parties','batches'] as $table)foreach(['update','delete'] as $operation)self::$server->exec('DROP TRIGGER '.DatabaseCloner::quote(self::$schema).'.'.DatabaseCloner::quote('property_opening_'.$table.'_immutable_'.$operation));
        $data=$config->all();$data['db']['name']=self::$schema;self::$config=new Config($data,$config->dataDir());self::$connection=Connection::withoutSharedTestConnection(fn()=>new Connection(self::$config));self::$connection->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }
    public static function tearDownAfterClass():void
    {
        if(isset(self::$connection)){if(self::$connection->pdo()->inTransaction())self::$connection->pdo()->rollBack();self::$connection->close();}if(isset(self::$server,self::$schema))OwnedTestDatabaseCleanup::drop(self::$server,self::$schema,[self::$schema]);
    }
    protected function setUp():void
    {
        $container=Bootstrap::buildContainer();$db=self::$connection;$container->set(Connection::class,$db);self::assertSame(self::$schema,$db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        if(self::$fixture===null){$this->db=$db;$db->pdo()->beginTransaction();
   $f=$this->chargeFixture($db);$s=$f['supplier'];(new AccountingPeriodRepository($db))->create($s,2026,'2026-01-01','2026-12-31');(new ChartOfAccountsSeeder($db))->seedForSupplier($s,'2026-01-01');
   $accounts=new ChartOfAccountsRepository($db);foreach(['315.100','379.910','379.900','315.210','324.110','388.210','389.110','955.100'] as $code)if($accounts->findByCode($s,$code)===null){$parent=$accounts->findByCode($s,substr($code,0,3));$accounts->insert($s,['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
   $common=['unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'origin_at'=>'2026-01-01T00:00:00+01:00','period_from'=>'2026-01-01','period_to'=>'2026-01-01','due_date'=>'2026-01-02','occurrence_ref'=>'2026','title_ref'=>'Synthetic title','evidence_ref'=>'Synthetic reconciliation'];
   $debt=$common+['case_ref'=>'debt-A','kind'=>'debt','components'=>[['key'=>'principal','role'=>'principal','account_code'=>'315.100','amount_cents'=>'1000000']],'liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic personal claim','valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding']];
   $credit=$common+['case_ref'=>'credit-B','kind'=>'credit','components'=>[['key'=>'credit','role'=>'credit','account_code'=>'379.910','amount_cents'=>'1000000']],'creditor_ids'=>[$f['clients'][18]]];
   $request=['mode'=>'post_new','accounting_date'=>'2026-01-01','effective_at'=>'2026-01-01T00:00:00+01:00','expected_revision'=>0,'evidence_ref'=>'Synthetic signed takeover','reason'=>'Synthetic opening','items'=>[$debt,$credit]];
   $request['items'][]=$common+['case_ref'=>'service-advance','kind'=>'advance','components'=>[['key'=>'advance','role'=>'service_advance','account_code'=>'324.110','amount_cents'=>'8000000']],'advance_state'=>['purpose_ref'=>'Synthetic service advances','settlement_due_on'=>'2027-04-30','prescribed_cents'=>'8000000','paid_cents'=>'8000000','used_cents'=>'0','unpaid_cases'=>[]]];
   $request['items'][]=$common+['case_ref'=>'service-carry','kind'=>'service_carry','components'=>[['key'=>'cost','role'=>'service_cost','account_code'=>'315.210','amount_cents'=>'8000000'],['key'=>'accrued-cost','role'=>'accrual_receivable','account_code'=>'388.210','amount_cents'=>'5000000'],['key'=>'accrued-cover','role'=>'accrual_payable','account_code'=>'389.110','amount_cents'=>'5000000']],'service_state'=>['profile'=>'service_balance_sheet','allocation_ref'=>'Synthetic service key','counterpart_ref'=>'Synthetic supplier evidence','prescribed_cents'=>'8000000','paid_cents'=>'8000000','settled_cents'=>'0','cancelled_unpaid_cents'=>'0','unpaid_cases'=>[],'recipient_ids'=>[$f['clients'][1]]]];
   $long=$common;$long['service_id']=null;$request['items'][]=$long+['case_ref'=>'long-advance','kind'=>'advance','components'=>[['key'=>'long','role'=>'long_term_advance','account_code'=>'955.100','amount_cents'=>'5000000']],'advance_state'=>['purpose_ref'=>'Synthetic roof repair','settlement_due_on'=>'2030-12-31','prescribed_cents'=>'5000000','paid_cents'=>'5000000','used_cents'=>'0','unpaid_cases'=>[]]];$request['other_balances']=[['account_code'=>'221','side'=>'debit','amount_cents'=>'5000000','dimensions'=>[]]];

   for($n=1;$n<=12;$n++){ $d=$debt;$d['case_ref']='debt-'.$n;$c=$credit;$c['case_ref']='credit-'.$n;$request['items'][]=$d;$request['items'][]=$c;}
   $request['items'][2]['advance_state']['paid_cents']='7000000';$request['items'][2]['advance_state']['unpaid_cases']=[['case_ref'=>'debt-A','occurrence_ref'=>'2026','amount_cents'=>'1000000']];

        $repo=$container->get(OpeningRepository::class);$preview=$repo->preview($s,$request);$batch=$repo->apply($s,$request,$preview['preview_hash'],'batch-fixture',$f['actor']);$foreign=$this->chargeFixture($db);(new AccountingPeriodRepository($db))->create($foreign['supplier'],2026,'2026-01-01','2026-12-31');(new ChartOfAccountsSeeder($db))->seedForSupplier($foreign['supplier'],'2026-01-01');foreach(['315.100','379.910'] as $code)if($accounts->findByCode($foreign['supplier'],$code)===null){$parent=$accounts->findByCode($foreign['supplier'],substr($code,0,3));$accounts->insert($foreign['supplier'],['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}$foreignRequest=$request;unset($foreignRequest['other_balances']);$foreignRequest['items']=[$debt,$credit];foreach($foreignRequest['items'] as &$item){$item['unit_id']=$foreign['units']['U02'];$item['service_id']=$foreign['service']['id'];if($item['kind']==='debt')$item['liability_groups'][0]['members']=[$foreign['clients'][1]];else $item['creditor_ids']=[$foreign['clients'][18]];}unset($item);$foreignPreview=$repo->preview($foreign['supplier'],$foreignRequest);$repo->apply($foreign['supplier'],$foreignRequest,$foreignPreview['preview_hash'],'foreign-proof',$foreign['actor']);$db->pdo()->commit();self::$fixture=$f+['request'=>$request,'batch'=>$batch];self::$connection=$db;}
        $this->db=self::$connection;$this->f=self::$fixture;$this->db->pdo()->exec('SET SESSION innodb_snapshot_isolation=0');$this->db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->pdo()->beginTransaction();
    }
    protected function tearDown():void{if(isset($this->db)&&$this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    private function ids():array{return array_column($this->f['batch']['items'],'id');}
    private function openingItemId(string $kind):int{foreach($this->f['batch']['items'] as $item)if($item['kind']===$kind)return $item['id'];throw new \RuntimeException('Missing fixture item');}
    private function execute(string $sql,array $args=[]):void{$q=$this->db->pdo()->prepare($sql);$q->execute($args);}
    public static function reads():array{return [[false],[true]];}
    #[DataProvider('reads')]
    public function testExactByteParityOrderingDuplicatesAndBoundedQueries(bool $current):void
    {
        $proof=new OpeningReadProof($this->db);$s=$this->f['supplier'];$ids=array_reverse($this->ids());$ids[]=$ids[0];$expected=[];foreach($ids as $id)$expected[$id]=$proof->item($s,$id,$current);
        $p=$this->db->pdo();$original=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);OpeningProofStatement::$selects=0;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OpeningProofStatement::class]);try{$actual=$proof->items($s,$ids,$current);$count=OpeningProofStatement::$selects;}finally{$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$original);}
        self::assertSame($expected,$actual);self::assertSame(array_keys($expected),array_keys($actual));self::assertLessThanOrEqual(70,$count,'Selected S6 closure must use bounded queries instead of seven SELECTs per item');
        self::assertSame($expected,$proof->items($s,array_merge(array_fill(0,500,$ids[0]),$ids),$current));self::assertSame($expected,$proof->currentBatchItems($s,$this->f['batch']['id'],$ids));$proof->verifyCurrentBatchItems($s,$this->f['batch']['id'],$ids);self::assertSame([],$proof->items($s,[],$current));
    }
    public static function missing():array{return [['missing'],['tenant'],['batch']];}
    #[DataProvider('missing')]
    public function testMissingRequestedProofCannotDisappear(string $kind):void
    {
        $proof=new OpeningReadProof($this->db);$s=$this->f['supplier'];$ids=$this->ids();$id=$ids[0];$this->expectException(OpeningException::class);
        if($kind==='missing'){$ids[]=PHP_INT_MAX;$proof->items($s,$ids,false);}elseif($kind==='tenant')$proof->items($s+100000,$ids,false);else $proof->currentBatchItems($s,$this->f['batch']['id']+100000,[$id]);
    }
    public static function mutations():array{return array_map(static fn($x)=>[$x],['component','party','state','recipient','link','invalid_link','tenant_link','physical','rehashed_fact','batch_hash','item_hash']);}
    #[DataProvider('mutations')]
    public function testEveryNativeFactAndPhysicalProofRemainsRequired(string $mutation):void
    {
        $s=$this->f['supplier'];$debt=$this->openingItemId('debt');$advance=$this->openingItemId('advance');$service=$this->openingItemId('service_carry');$credit=$this->openingItemId('credit');
        match($mutation){
            'component'=>$this->execute('DELETE FROM property_opening_components WHERE supplier_id=? AND item_id=?',[$s,$debt]),
            'party'=>$this->execute('DELETE FROM property_opening_parties WHERE supplier_id=? AND item_id=?',[$s,$debt]),
            'state'=>$this->execute('DELETE FROM property_opening_states WHERE supplier_id=? AND item_id=?',[$s,$advance]),
            'recipient'=>$this->execute('DELETE FROM property_opening_recipients WHERE supplier_id=? AND item_id=?',[$s,$service]),
            'link'=>$this->execute('DELETE FROM property_opening_case_links WHERE supplier_id=? AND item_id=?',[$s,$advance]),
            'invalid_link'=>$this->execute('UPDATE property_opening_case_links SET debt_item_id=? WHERE supplier_id=? AND item_id=?',[$credit,$s,$advance]),
            'tenant_link'=>$this->foreignLink($s,$advance),
            'rehashed_fact'=>$this->rehashFact($s,$debt),
            'physical'=>$this->execute('UPDATE journal_entry_lines SET amount=amount+1 WHERE supplier_id=? AND id=(SELECT journal_line_id FROM property_opening_components WHERE supplier_id=? AND item_id=? LIMIT 1)',[$s,$s,$debt]),
            'batch_hash'=>$this->execute('UPDATE property_opening_batches SET snapshot_hash=REPEAT(\'0\',64) WHERE supplier_id=? AND id=?',[$s,$this->f['batch']['id']]),
            'item_hash'=>$this->execute('UPDATE property_opening_items SET snapshot_hash=REPEAT(\'0\',64) WHERE supplier_id=? AND id=?',[$s,$debt])
        };
        $this->expectException(OpeningException::class);(new OpeningReadProof($this->db))->items($s,$this->ids(),true);
    }
    private function rehashFact(int $supplier,int $debt):void
    {
        $q=$this->db->pdo()->prepare('SELECT id,fact_json FROM property_opening_components WHERE supplier_id=? AND item_id=? ORDER BY id LIMIT 1');$q->execute([$supplier,$debt]);$row=$q->fetch(PDO::FETCH_ASSOC);$fact=json_decode($row['fact_json'],true,flags:JSON_THROW_ON_ERROR);$fact['ledger_line']['amount']=bcadd($fact['ledger_line']['amount'],'1',2);$this->execute('UPDATE property_opening_components SET fact_json=?,fact_hash=? WHERE supplier_id=? AND id=?',[json_encode($fact,JSON_THROW_ON_ERROR),\MyInvoice\Service\Property\PropertyInput::hash($fact),$supplier,$row['id']]);
    }
    private function foreignLink(int $supplier,int $advance):void
    {
        $q=$this->db->pdo()->prepare("SELECT id FROM property_opening_items WHERE supplier_id<>? AND kind='debt' ORDER BY id LIMIT 1");$q->execute([$supplier]);$foreign=(int)$q->fetchColumn();self::assertGreaterThan(0,$foreign);$this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS=0');try{$this->execute('UPDATE property_opening_case_links SET debt_item_id=? WHERE supplier_id=? AND item_id=?',[$foreign,$supplier,$advance]);}finally{$this->db->pdo()->exec('SET FOREIGN_KEY_CHECKS=1');}
    }
    private function other():Connection
    {
        $config=self::$config;$db=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($config->all(),$config->dataDir())));$db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");$db->pdo()->exec('SET SESSION innodb_lock_wait_timeout=1');return $db;
    }
    public function testCurrentProofSeesCommittedChangeBeyondStaleRepeatableRead():void
    {
        $s=$this->f['supplier'];$id=$this->openingItemId('debt');$a=$this->db->pdo();$q=$a->prepare('SELECT snapshot_hash FROM property_opening_items WHERE supplier_id=? AND id=?');$q->execute([$s,$id]);$old=$q->fetchColumn();$b=$this->other();
        try{$u=$b->pdo()->prepare('UPDATE property_opening_items SET snapshot_hash=? WHERE supplier_id=? AND id=?');$u->execute([str_repeat('0',64),$s,$id]);$q->execute([$s,$id]);self::assertSame($old,$q->fetchColumn());(new PaymentLocks($this->db))->acquire($s);try{(new OpeningReadProof($this->db))->items($s,[$id],true);self::fail('Current proof cannot hide committed corruption');}catch(OpeningException $e){self::assertSame('property_opening_history_corrupt',$e->errorCode);}}
        finally{$a->rollBack();$u->execute([$old,$s,$id]);$b->close();}
    }
    public function testLateNativeMaterializationFailureRollsBackWholeRevision():void
    {
        $s=$this->f['supplier'];$p=$this->db->pdo();$tables=['property_opening_batches','property_opening_items','property_opening_components','property_opening_parties','property_opening_states','property_opening_recipients','property_opening_case_links','unit_payment_debts','unit_credit_lots','journal_entries','journal_entry_lines'];$counts=function()use($s,$p,$tables):array{$out=[];foreach($tables as $table){$q=$p->prepare('SELECT COUNT(*) FROM '.$table.' WHERE supplier_id=?');$q->execute([$s]);$out[$table]=(int)$q->fetchColumn();}return $out;};$before=$counts();$original=$p->getAttribute(PDO::ATTR_STATEMENT_CLASS);$injected=false;OpeningProofStatement::$callback=function(string $sql,?array $params)use($s,&$injected):void{if(!str_starts_with($sql,'UPDATE property_opening_heads SET batch_id='))return;OpeningProofStatement::$callback=null;$injected=true;$this->execute('UPDATE property_opening_components SET amount_cents=amount_cents+1 WHERE supplier_id=? AND item_id IN (SELECT id FROM property_opening_items WHERE supplier_id=? AND batch_id=?)',[$s,$s,$params[0]]);};$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OpeningProofStatement::class]);
        $request=$this->f['request'];$request['mode']='revision';$request['expected_revision']=1;$request['accounting_date']='2026-01-02';$request['effective_at']='2026-01-02T00:00:00+01:00';$c=Bootstrap::buildContainer();$c->set(Connection::class,$this->db);$repo=$c->get(OpeningRepository::class);
        try{$preview=$repo->preview($s,$request);try{$repo->apply($s,$request,$preview['preview_hash'],'late-batch-failure',$this->f['actor']);self::fail('Final native proof must reject a late corrupted component');}catch(OpeningException $e){self::assertSame('property_opening_component_corrupt',$e->errorCode);}}
        finally{OpeningProofStatement::$callback=null;$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,$original);}
        self::assertTrue($injected);self::assertSame($before,$counts());$proof=new OpeningReadProof($this->db);self::assertSame('posted',$proof->batch($s,$this->f['batch']['id'],true)['status']);$proof->verifyCurrentBatchItems($s,$this->f['batch']['id'],$this->ids());
    }
    public static function closures():array{return array_map(static fn($x)=>[$x],['property_opening_items','property_opening_components','property_opening_parties','property_opening_states','property_opening_recipients','property_opening_case_links']);}
    #[DataProvider('closures')]
    public function testCurrentProofRetainsEverySelectedClosureRowMutex(string $table):void
    {
        $s=$this->f['supplier'];(new PaymentLocks($this->db))->acquire($s);(new OpeningReadProof($this->db))->items($s,$this->ids(),true);$b=$this->other();try{$b->pdo()->beginTransaction();$q=$b->pdo()->prepare('SELECT * FROM '.$table.' WHERE supplier_id=? ORDER BY '.(in_array($table,['property_opening_items','property_opening_components'],true)?'id':'item_id').' LIMIT 1 FOR UPDATE');try{$q->execute([$s]);self::fail('Selected proof closure row must remain locked');}catch(\PDOException $e){self::assertSame(1205,(int)$e->errorInfo[1]);}}finally{if($b->pdo()->inTransaction())$b->pdo()->rollBack();$b->close();}
    }
}
final class OpeningProofStatement extends PDOStatement
{
    public static int $selects=0;
    public static ?\Closure $callback=null;
    protected function __construct(){}
    public function execute(?array $params=null):bool{if(preg_match('/^\s*SELECT\b/i',$this->queryString))self::$selects++;$result=parent::execute($params);if(self::$callback!==null)(self::$callback)($this->queryString,$params);return $result;}
}
