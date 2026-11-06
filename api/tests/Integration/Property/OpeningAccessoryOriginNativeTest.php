<?php
declare(strict_types=1);
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Repository\Property\Payments\{DebtRepository,PaymentOperationsReadRepository,PaymentRepository};
use MyInvoice\Repository\Property\Receivables\{FinancialHistoryRepository,PropertySaldoReadClosure,PropertySaldoReadProof};
use MyInvoice\Service\Property\Opening\{OpeningOriginProof,OpeningReadProof,OpeningException};
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Tests\Support\PropertyChargeFixture;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
final class OpeningAccessoryOriginNativeTest extends TestCase
{
    use PropertyChargeFixture;
    use MyInvoice\Tests\Support\PaymentCorrectionFixture;
    use MyInvoice\Tests\Support\SharedFixtureSavepointTrait { tearDownAfterClass as private sharedFixtureTearDownAfterClass; }
    private Connection $db;
    private array $f;
    private array $request;
    private array $batch;
    private int $debtId;
    private \DI\Container $container;
    private static ?Connection $ownedConnection=null;
    private static ?PDO $server=null;
    private static ?string $ownedSchema=null;
    private static ?string $ownerToken=null;
    public static function setUpBeforeClass():void
    {
        $container=Bootstrap::buildContainer();$config=$container->get(MyInvoice\Infrastructure\Config\Config::class);$source=(string)$container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test',$source);self::assertContains($config->get('db.host'),['localhost','127.0.0.1','::1']);
        self::$server=new PDO('mysql:host='.$config->get('db.host').';port='.$config->get('db.port',3306).';charset=utf8mb4',$config->get('db.user'),$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        self::$server->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $schema='myucto_accessory_origin_'.bin2hex(random_bytes(6)).'_test';$exists=self::$server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$exists->execute([$schema]);self::assertSame(0,(int)$exists->fetchColumn());
        $cloner=new MyInvoice\Service\Anonymization\DatabaseCloner(self::$server);$plan=$cloner->plan($source);$empty=$plan;$empty['tables']=[];$cloner->copyTables($empty,$schema,static function():void{});self::$ownedSchema=$schema;
        try{
            self::$ownerToken=bin2hex(random_bytes(16));self::$server->exec('CREATE TABLE `'.$schema.'`.`_accessory_origin_test_owner` (token CHAR(32) PRIMARY KEY) ENGINE=InnoDB');self::$server->prepare('INSERT INTO `'.$schema.'`.`_accessory_origin_test_owner` VALUES (?)')->execute([self::$ownerToken]);
            foreach($plan['tables'] as $table)self::$server->exec($table['ddl']);
            foreach($plan['tables'] as $name=>$table){$quoted=MyInvoice\Service\Anonymization\DatabaseCloner::quote($name);self::$server->exec('INSERT INTO `'.$schema.'`.'.$quoted.' ('.$table['columns'].') SELECT '.$table['columns'].' FROM '.MyInvoice\Service\Anonymization\DatabaseCloner::quote($source).'.'.$quoted);}
            self::$server->exec('SET SESSION FOREIGN_KEY_CHECKS=1');
            $data=$config->all();$data['db']['name']=$schema;self::$ownedConnection=Connection::withoutSharedTestConnection(fn()=>new Connection(new MyInvoice\Infrastructure\Config\Config($data,$config->dataDir())));
            // Kopie schématu je bez triggerů a testy schéma nemění; dotaz do information_schema stojí přes sekundu, proto jednou za třídu.
            self::assertSame(0,(int)self::$ownedConnection->pdo()->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn());
        }catch(\Throwable $e){self::tearDownAfterClass();throw $e;}
    }
    public static function tearDownAfterClass():void
    {
        self::sharedFixtureTearDownAfterClass();
        if(self::$ownedConnection!==null){if(self::$ownedConnection->pdo()->inTransaction())self::$ownedConnection->pdo()->rollBack();self::$ownedConnection->close();self::$ownedConnection=null;}
        if(self::$ownedSchema!==null){
            $schema=self::$ownedSchema;self::assertMatchesRegularExpression('/^myucto_accessory_origin_[a-f0-9]{12}_test$/D',$schema);self::assertSame(self::$ownerToken,self::$server->query('SELECT token FROM `'.$schema.'`.`_accessory_origin_test_owner`')->fetchColumn());
            self::$server->exec('DROP DATABASE `'.$schema.'`');$exists=self::$server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$exists->execute([$schema]);self::assertSame(0,(int)$exists->fetchColumn());self::$ownedSchema=null;
        }
    }
    protected function setUp():void
    {
        $this->container=Bootstrap::buildContainer();$this->db=self::$ownedConnection;$this->container->set(Connection::class,$this->db);$pdo=$this->db->pdo();
        self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));self::assertSame(self::$ownedSchema,$pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");$this->beginSharedFixtureTest($pdo);
    }
    protected function tearDown():void {if(isset($this->db))$this->endSharedFixtureTest($this->db->pdo());}
    /** Počáteční stav s dluhem a kreditem je pro všechny testy stejný; staví se jednou za třídu. */
    private function fixture(int $count=1):void
    {
        [$this->f,$this->request,$this->batch,$this->debtId]=$this->sharedFixture('opening-'.$count,function()use($count):array{$this->buildFixture($count);return [$this->f,$this->request,$this->batch,$this->debtId];});
    }
    private function buildFixture(int $count):void
    {
        $db=$this->db;$pdo=$db->pdo();$container=$this->container;
   $f=$this->chargeFixture($db);$this->f=$f;$s=$f['supplier'];(new AccountingPeriodRepository($db))->create($s,2026,'2026-01-01','2026-12-31');(new ChartOfAccountsSeeder($db))->seedForSupplier($s,'2026-01-01');
   $accounts=new MyInvoice\Repository\ChartOfAccountsRepository($db);foreach(['315.100','315.410','379.910','379.900'] as $code)if($accounts->findByCode($s,$code)===null){$parent=$accounts->findByCode($s,substr($code,0,3));$accounts->insert($s,['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
   $common=['unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'origin_at'=>'2026-01-01T00:00:00+01:00','period_from'=>'2026-01-01','period_to'=>'2026-01-01','due_date'=>'2026-01-02','occurrence_ref'=>'2026','title_ref'=>'Synthetic title','evidence_ref'=>'Synthetic reconciliation person@example.test'];
   $debt=$common+['case_ref'=>'debt-A','kind'=>'debt','components'=>[['key'=>'principal','role'=>'principal','account_code'=>'315.100','amount_cents'=>'990000']],'liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic personal claim','valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding']];
   $credit=$common+['case_ref'=>'credit-B','kind'=>'credit','components'=>[['key'=>'credit','role'=>'credit','account_code'=>'379.910','amount_cents'=>'1000000']],'creditor_ids'=>[$f['clients'][18]]];
   $debt['components'][]=['key'=>'interest','role'=>'interest','account_code'=>'315.410','amount_cents'=>'10000'];
   $request=['mode'=>'post_new','accounting_date'=>'2026-01-01','effective_at'=>'2026-01-01T00:00:00+01:00','expected_revision'=>0,'evidence_ref'=>'Synthetic signed takeover','reason'=>'Synthetic opening','items'=>[$debt,$credit]];
   if($count>1){$request['items']=[];for($i=0;$i<$count;$i++){$copy=$debt;$copy['case_ref']='debt-'.$i;$request['items'][]=$copy;}$credit['components'][0]['amount_cents']=(string)($count*1000000);$request['items'][]=$credit;}
   $repo=$container->get(OpeningRepository::class);$preview=$repo->preview($s,$request);$result=$repo->apply($s,$request,$preview['preview_hash'],'opening-native',$f['actor']);self::assertSame('posted',$result['status']);self::assertCount($count+1,$result['items']);
   $debtId=(int)$pdo->query('SELECT id FROM unit_payment_debts WHERE supplier_id='.$s)->fetchColumn();$creditId=(int)$pdo->query('SELECT id FROM unit_credit_lots WHERE supplier_id='.$s)->fetchColumn();

        $this->request=$request;$this->batch=$result;$this->debtId=$debtId;
    }
    private function rows():array
    {
        $pdo=$this->db->pdo();$q=$pdo->prepare('SELECT * FROM unit_payment_debts WHERE supplier_id=? AND id=?');$q->execute([$this->f['supplier'],$this->debtId]);$debt=$q->fetch(PDO::FETCH_ASSOC);
        $q=$pdo->prepare('SELECT * FROM unit_claim_accessory_receipts WHERE supplier_id=? AND debt_id=? ORDER BY id');$q->execute([$this->f['supplier'],$this->debtId]);return [$debt,$q->fetch(PDO::FETCH_ASSOC)];
    }
    private function legacy(array $row):array
    {
        $receipt=json_decode($row['receipt_json'],true,flags:JSON_THROW_ON_ERROR);unset($receipt['opening_origin']);$receipt['ledger']=(new PaymentJournalProof($this->db))->current($this->f['supplier'],(int)$row['journal_entry_id']);$receipt['ledger']['entry']['reversed_by']=null;return $receipt;
    }
    private function save(array $row,array $receipt,?int $entry=null,?string $role=null):void
    {
        $q=$this->db->pdo()->prepare('UPDATE unit_claim_accessory_receipts SET receipt_json=?,receipt_hash=?,journal_entry_id=?,component=? WHERE supplier_id=? AND id=?');$q->execute([json_encode($receipt,JSON_THROW_ON_ERROR),PropertyInput::hash($receipt),$entry??$row['journal_entry_id'],$role??$row['component'],$row['supplier_id'],$row['id']]);
    }
    private function readPath(string $path):void
    {
        $s=$this->f['supplier'];
        match($path){
            'current'=>(new DebtRepository($this->db))->current($s,$this->debtId),
            'history'=>(new FinancialHistoryRepository($this->db))->read($s),
            'selected'=>(new FinancialHistoryRepository($this->db))->readForDebts($s,[$this->debtId]),
            'page'=>(new PaymentOperationsReadRepository($this->db))->page($s,'debts',0,25,$this->f['units']['U02']),
            'projection'=>(new FinancialHistoryRepository($this->db))->readProjection($s),
            'seam'=>(new OpeningOriginProof($this->db))->accessory($s,...[...$this->rows(),false]),
        };
    }
    public static function legacyMutations():iterable
    {
        foreach(['date','entry','row','entry_and_row'] as $mutation)foreach(['current','history','selected','page','projection'] as $path)yield $mutation.'-'.$path=>[$mutation,$path];
    }
    #[DataProvider('legacyMutations')]
    public function testLegacyTamperingIsRejected(string $mutation,string $path):void
    {
        $this->fixture();[, $row]=$this->rows();$receipt=$this->legacy($row);$entry=null;
        if($mutation==='date')$receipt['ledger']['entry']['entry_date']='2026-01-03';
        else{$bank=$this->correctionBank('1.00');$q=$this->db->pdo()->prepare("SELECT id FROM journal_entries WHERE supplier_id=? AND source_type='bank' AND source_id=?");$q->execute([$this->f['supplier'],$bank]);$other=(int)$q->fetchColumn();self::assertGreaterThan(0,$other);if($mutation!=='row')$receipt['ledger']['entry']['id']=$other;if($mutation!=='entry')$entry=$other;}
        $this->save($row,$receipt,$entry);$this->expectException(OpeningException::class);$this->readPath($path);
    }
    public static function compactMutations():iterable
    {
        foreach(['batch_id','item_id','component_id','journal_entry_id','journal_line_id','batch_snapshot_hash','item_snapshot_hash','version','role','effective','dimensions','groups','evidence','amount','actor','mixed_format'] as $mutation)yield $mutation=>[$mutation];
    }
    #[DataProvider('compactMutations')]
    public function testCompactTamperingIsRejected(string $mutation):void
    {
        $this->fixture();[, $row]=$this->rows();$receipt=json_decode($row['receipt_json'],true,flags:JSON_THROW_ON_ERROR);self::assertArrayNotHasKey('ledger',$receipt);self::assertSame(1,$receipt['opening_origin']['version']);$role=null;
        if(in_array($mutation,['batch_snapshot_hash','item_snapshot_hash'],true))$receipt['opening_origin'][$mutation]=str_repeat('0',64);
        elseif(in_array($mutation,['batch_id','item_id','component_id','journal_entry_id','journal_line_id','version'],true))$receipt['opening_origin'][$mutation]++;
        elseif($mutation==='role'){$receipt['request']['component']='default_interest';$role='default_interest';}
        elseif($mutation==='effective')$receipt['opening_effective_at']='2026-01-03T00:00:00.000000Z';
        elseif($mutation==='dimensions')$receipt['line']['dimensions']=[];
        elseif($mutation==='groups')$receipt['liability_groups'][0]['evidence_ref']='Changed synthetic evidence';
        elseif($mutation==='evidence')$receipt['request']['evidence']='Changed synthetic evidence';
        elseif($mutation==='amount')$receipt['line']['amount']='99.99';
        elseif($mutation==='actor')$receipt['actor']++;
        elseif($mutation==='mixed_format')$receipt['ledger']=null;
        $this->save($row,$receipt,null,$role);$this->expectException(OpeningException::class);$this->readPath('history');
    }
    public function testPublicSeamRejectsDetachedRows():void
    {
        $this->fixture();[$debt,$row]=$this->rows();$proof=new OpeningOriginProof($this->db);$fresh=$proof->accessory($this->f['supplier'],$debt,$row,true);self::assertSame((int)$debt['opening_item_id'],$fresh['id']);
        $row['created_by']=(string)((int)$row['created_by']+1);$this->expectException(OpeningException::class);$proof->accessory($this->f['supplier'],$debt,$row,true);
    }
    public function testPublicSeamRejectsForeignTenant():void
    {
        $this->fixture();[$debt,$row]=$this->rows();$this->expectException(OpeningException::class);(new OpeningOriginProof($this->db))->accessory($this->f['supplier']+1,$debt,$row,true);
    }
    public function testLegacyPostedAndReversedParity():void
    {
        $this->fixture();[, $row]=$this->rows();$receipt=$this->legacy($row);$this->save($row,$receipt);$raw=$this->rows()[1]['receipt_json'];
        foreach(['current','history','selected','page','projection','seam'] as $path)$this->readPath($path);
        $repo=$this->container->get(OpeningRepository::class);$revision=$this->request;$revision['mode']='revision';$revision['expected_revision']=1;$revision['accounting_date']='2026-01-02';$revision['effective_at']='2026-01-02T00:00:00+01:00';$preview=$repo->preview($this->f['supplier'],$revision);$repo->apply($this->f['supplier'],$revision,$preview['preview_hash'],'legacy-revision',$this->f['actor']);
        foreach(['current','history','selected','page','projection','seam'] as $path)$this->readPath($path);
        self::assertFalse((new DebtRepository($this->db))->current($this->f['supplier'],$this->debtId)['active']);self::assertSame($raw,$this->rows()[1]['receipt_json']);
        $closure=(new PropertySaldoReadClosure($this->db))->units($this->f['supplier'],[$this->f['units']['U02']]);PropertySaldoReadProof::materializations($closure->rows);self::assertCount(2,$closure->debtIds);
    }
    public function testCompactReceiptBytesGrowLinearly():void
    {
        $this->fixture(4);$pdo=$this->db->pdo();$s=$this->f['supplier'];$q=$pdo->prepare('SELECT r.receipt_json FROM unit_claim_accessory_receipts r JOIN property_opening_components c ON c.id=r.opening_component_id JOIN property_opening_items i ON i.id=c.item_id WHERE r.supplier_id=? AND i.batch_id=? ORDER BY r.id');$q->execute([$s,$this->batch['id']]);$first=$q->fetchAll(PDO::FETCH_COLUMN);
        $revision=$this->request;$revision['mode']='revision';$revision['expected_revision']=1;$revision['accounting_date']='2026-01-02';$revision['effective_at']='2026-01-02T00:00:00+01:00';$credit=array_pop($revision['items']);$extra=[];foreach($revision['items'] as $item){$item['case_ref'].='-extra';$extra[]=$item;}$revision['items']=[...$revision['items'],...$extra];$credit['components'][0]['amount_cents']='8000000';$revision['items'][]=$credit;
        $repo=$this->container->get(OpeningRepository::class);$preview=$repo->preview($s,$revision);$batch=$repo->apply($s,$revision,$preview['preview_hash'],'double-items',$this->f['actor']);$q->execute([$s,$batch['id']]);$second=$q->fetchAll(PDO::FETCH_COLUMN);self::assertCount(4,$first);self::assertCount(8,$second);
        foreach([...$first,...$second] as $raw){$receipt=json_decode($raw,true,flags:JSON_THROW_ON_ERROR);self::assertArrayNotHasKey('ledger',$receipt);self::assertSame(1,$receipt['opening_origin']['version']);}
        $bytes=fn(array $values)=>array_sum(array_map('strlen',$values));self::assertLessThan(2.1,$bytes($second)/$bytes($first));self::assertGreaterThan(1.9,$bytes($second)/$bytes($first));
    }
    public function testLegacyOperationSnapshotReplayKeepsOriginalReceipt():void
    {
        $this->fixture();[, $row]=$this->rows();$receipt=$this->legacy($row);$this->save($row,$receipt);$s=$this->f['supplier'];$bank=$this->correctionBank('200.00');$id=$this->debtId;
        $request=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'20000','accounting_date'=>'2026-02-01','reason'=>'Synthetic opening payment','debts'=>[$id],'payment'=>['payer_id'=>$this->f['clients'][1],'debtor_id'=>$this->f['clients'][1],'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$id],'evidence'=>'Synthetic disposition']]];
        $repo=$this->container->get(PaymentRepository::class);$preview=$repo->preview($s,$request);$operation=$repo->apply($s,$request,$preview['preview_hash'],'legacy-operation',$this->f['actor']);$before=$repo->operation($s,$operation['id']);
        self::assertSame('posted',$before['status']);self::assertSame($receipt,$before['snapshot']['debt_receipts'][0]['accessories'][0]['receipt']);$replay=$repo->apply($s,$request,$preview['preview_hash'],'legacy-operation',$this->f['actor']);self::assertSame($operation['id'],$replay['id']);self::assertSame($before['snapshot_hash'],$replay['snapshot_hash']);self::assertSame($receipt,json_decode($this->rows()[1]['receipt_json'],true,flags:JSON_THROW_ON_ERROR));
    }

    public function testCompactCloneRemapsOriginAndOperationSnapshotHashes():void
    {
        $this->fixture();[, $row]=$this->rows();$receipt=json_decode($row['receipt_json'],true,flags:JSON_THROW_ON_ERROR);self::assertArrayHasKey('opening_origin',$receipt);$s=$this->f['supplier'];$bank=$this->correctionBank('200.00');$id=$this->debtId;
        $request=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'20000','accounting_date'=>'2026-02-01','reason'=>'Synthetic opening payment','debts'=>[$id],'payment'=>['payer_id'=>$this->f['clients'][1],'debtor_id'=>$this->f['clients'][1],'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$id],'evidence'=>'Synthetic disposition']]];
        $repo=$this->container->get(PaymentRepository::class);$preview=$repo->preview($s,$request);$operation=$repo->apply($s,$request,$preview['preview_hash'],'compact-clone-operation',$this->f['actor']);$before=$repo->operation($s,$operation['id']);$pdo=$this->db->pdo();$pdo->commit();
        $settlementInputs=\MyInvoice\Service\Anonymization\SettlementCloneScrubber::captureInputs($this->db);
        $scrubber=new MyInvoice\Service\Anonymization\TextScrubber(new MyInvoice\Service\Anonymization\Pseudonymizer(str_repeat('synthetic-accessory-',3)));
        $registry=(new MyInvoice\Service\Anonymization\PropertyCloneScrubber($pdo,$scrubber))->run();
        $versions=(new MyInvoice\Service\Anonymization\ChargesCloneScrubber($pdo,$scrubber))->run($registry);
        $issuance=(new MyInvoice\Service\Anonymization\IssuanceCloneScrubber($this->db,$scrubber))->run($registry,$versions);
        foreach($pdo->query('SELECT id,counterparty_account FROM bank_transactions WHERE counterparty_account IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $r){$fact=json_decode($scrubber->scrubJson(json_encode(['counterparty_account'=>$r['counterparty_account']],JSON_THROW_ON_ERROR)),true,flags:JSON_THROW_ON_ERROR);$pdo->prepare('UPDATE bank_transactions SET counterparty_account=? WHERE id=?')->execute([$fact['counterparty_account'],$r['id']]);}
        foreach($pdo->query('SELECT id,partner_name FROM cash_documents')->fetchAll(PDO::FETCH_ASSOC) as $row){$fact=json_decode($scrubber->scrubJson(json_encode(['partner_name'=>$row['partner_name']],JSON_THROW_ON_ERROR)),true,flags:JSON_THROW_ON_ERROR);$pdo->prepare('UPDATE cash_documents SET partner_name=? WHERE id=?')->execute([$fact['partner_name'],$row['id']]);}
        foreach($pdo->query('SELECT line_id,dimension_type_id,assignment_origin_ref FROM journal_entry_line_dimensions WHERE assignment_origin_ref IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $row)$pdo->prepare('UPDATE journal_entry_line_dimensions SET assignment_origin_ref=? WHERE line_id=? AND dimension_type_id=?')->execute([$scrubber->scrub($row['assignment_origin_ref']),$row['line_id'],$row['dimension_type_id']]);
        $hashes=(new MyInvoice\Service\Anonymization\PaymentsCloneScrubber($this->db,$scrubber))->run($issuance,$registry,$settlementInputs);
        $pdo->beginTransaction();[$debt,$clonedRow]=$this->rows();$cloned=json_decode($clonedRow['receipt_json'],true,flags:JSON_THROW_ON_ERROR);$origin=$cloned['opening_origin'];
        self::assertNotSame($receipt['opening_origin']['batch_snapshot_hash'],$origin['batch_snapshot_hash']);self::assertNotSame($receipt['opening_origin']['item_snapshot_hash'],$origin['item_snapshot_hash']);
        self::assertSame($hashes[$receipt['opening_origin']['batch_snapshot_hash']],$origin['batch_snapshot_hash']);self::assertSame($hashes[$receipt['opening_origin']['item_snapshot_hash']],$origin['item_snapshot_hash']);
        $proven=(new OpeningOriginProof($this->db))->accessory($s,$debt,$clonedRow,true);self::assertSame($origin['item_snapshot_hash'],$proven['snapshot_hash']);
        $after=$repo->operation($s,$operation['id']);self::assertSame($cloned,$after['snapshot']['debt_receipts'][0]['accessories'][0]['receipt']);self::assertSame($cloned,$after['snapshot']['debt_receipts'][0]['debt']['accessories'][0]['receipt']);
        self::assertNotSame($before['snapshot_hash'],$after['snapshot_hash']);$replay=$repo->apply($s,$after['request'],$after['snapshot_hash'],'compact-clone-operation',$this->f['actor']);self::assertSame($operation['id'],$replay['id']);self::assertSame($after['snapshot_hash'],$replay['snapshot_hash']);
        foreach(['current','history','selected','page','projection','seam'] as $path)$this->readPath($path);
    }
}
