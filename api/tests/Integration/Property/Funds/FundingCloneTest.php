<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Funds;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Funds\FundingRepository;
use MyInvoice\Service\Anonymization\{AnonymizationOptions,AnonymizationService,FundingCloneScrubber,TextScrubber,Pseudonymizer};
use MyInvoice\Service\Property\Funds\FundingRead;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Tests\Support\CloneSandbox;
use PDO;
use PHPUnit\Framework\TestCase;
/**
 * Fixtura fondů (otevření, vydání, tři čerpání, příjem, převod, výplata a její storno)
 * se staví jednou za třídu a testy si z ní berou vlastní kopii bez triggerů.
 */
final class FundingCloneTest extends TestCase
{
    private static ?Config $config=null;
    private static ?PDO $server=null;
    private static ?string $sourceName=null;
    private static ?FundingNativeTest $fixture=null;
    private static ?Connection $source=null;
    private static array $data=[];
    private array $owned=[];
    private ?Connection $target=null;
    protected function setUp():void
    {
        if(self::$source!==null)return;
        $prior=getenv('MYINVOICE_DB_NAME');self::$config=Config::load(Bootstrap::rootDir());$base=(string)self::$config->get('db.name');self::assertStringEndsWith('_test',$base);self::assertContains(self::$config->get('db.host'),['localhost','127.0.0.1','::1']);
        self::$server=new PDO('mysql:host='.self::$config->get('db.host').';port='.self::$config->get('db.port',3306).';charset=utf8mb4',self::$config->get('db.user'),self::$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        self::$sourceName=CloneSandbox::acquire(self::$server,$base,true);
        try{putenv('MYINVOICE_DB_NAME='.self::$sourceName);self::$fixture=new FundingNativeTest('testReferenceLongAdvancesAdministrationAndOwnerIncome');Connection::withoutSharedTestConnection(fn()=>(new \ReflectionMethod(self::$fixture,'setUp'))->invoke(self::$fixture));self::$source=(new \ReflectionProperty(self::$fixture,'db'))->getValue(self::$fixture);self::$data=$this->prepare();}
        finally{putenv($prior===false?'MYINVOICE_DB_NAME':'MYINVOICE_DB_NAME='.$prior);}
    }
    protected function tearDown():void
    {
        if($this->target!==null){if($this->target->pdo()->inTransaction())$this->target->pdo()->rollBack();$this->target->close();$this->target=null;}
        foreach($this->owned as $schema)CloneSandbox::release(self::$server,$schema);$this->owned=[];
    }
    public static function tearDownAfterClass():void
    {
        try{if(self::$fixture!==null)(new \ReflectionMethod(self::$fixture,'tearDown'))->invoke(self::$fixture);self::$source?->close();if(self::$sourceName!==null)CloneSandbox::release(self::$server,self::$sourceName);}
        finally{self::$fixture=null;self::$source=null;self::$sourceName=null;self::$data=[];}
    }
    private function copy(bool $anonymized=false,string $seed=''):Connection
    {
        $schema=$anonymized?CloneSandbox::anonymized(self::$server,self::$config,self::$sourceName,$seed):CloneSandbox::acquire(self::$server,self::$sourceName);$this->owned[]=$schema;
        $config=self::$config->all();$config['db']['name']=$schema;return $this->target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($config,self::$config->dataDir())));
    }
    private function native(string $method,mixed ...$args):mixed{return(new \ReflectionMethod(self::$fixture,$method))->invoke(self::$fixture,...$args);}
    private function prepare():array
    {
        $this->native('fundingOpening');$this->native('fundingIssue');$f=(new \ReflectionProperty(self::$fixture,'f'))->getValue(self::$fixture);
        foreach([['2026-03-01','repair','9000000','549.900','opening'],['2026-06-01','improvement','15000000','549.910','opening'],['2026-09-01','administration','1000000','518.200','issued']] as [$date,$kind,$amount,$code,$source]){$cost=$this->native('fundingCost',$date,$code,bcdiv($amount,'100',2));$request=$this->native('fundingDraw',$date,$kind,$amount,$cost,$source);$request['evidence_ref']='Synthetic draw-private@synthetic.invalid';$request['key']['evidence_ref']='Synthetic key-private@synthetic.invalid';foreach($request['sources'] as &$selected)$selected['purpose_evidence']='Synthetic purpose-private@synthetic.invalid';unset($selected);$this->native('fundingApply',$request,'clone-'.$kind);}
        $request=$this->native('fundingRequest','income','2026-12-01','6000000')+['bank_id'=>$this->native('fundingBank','2026-12-01','60000.00'),'key'=>$this->native('fundingKey','2026-12-01')];$income=$this->native('fundingApply',$request,'clone-income');$dispositions=[];foreach($income['receipt']['effects'] as $effect)$dispositions[]=['source_id'=>$effect['id'],'amount_cents'=>bcdiv(bcmul($effect['amount_cents'],'3',0),'4',0),'party_ids'=>$effect['parties']['members'],'evidence_ref'=>'Synthetic contribution-private@synthetic.invalid'];
        $this->native('fundingApply',$this->native('fundingRequest','transfer','2026-12-02','4500000')+['dispositions'=>$dispositions,'target'=>'long_term','purpose_ref'=>'Synthetic roof-private@synthetic.invalid','settlement_due_on'=>'2029-12-31'],'clone-transfer');$dispositions=[];foreach($income['receipt']['effects'] as $effect)$dispositions[]=['source_id'=>$effect['id'],'amount_cents'=>bcdiv($effect['amount_cents'],'6',0),'party_ids'=>$effect['parties']['members'],'evidence_ref'=>'Synthetic payout-private@synthetic.invalid','recipient_authority_ref'=>'Synthetic mandate-private@synthetic.invalid'];
        $payout=$this->native('fundingApply',$this->native('fundingRequest','payout','2026-12-03','1000000')+['dispositions'=>$dispositions,'bank_id'=>$this->native('fundingBank','2026-12-03','-10000.00'),'recipient_id'=>$f['clients'][1],'recipient_account'=>'1000000005/0100'],'clone-payout');$reversal=$this->native('fundingRequest','reverse','2026-12-04');unset($reversal['amount_cents']);$reverse=$this->native('fundingApply',$reversal+['operation_id'=>$payout['id']],'clone-payout-reverse');
        self::$source->pdo()->commit();return compact('f','income','payout','reverse');
    }
    private function fingerprint(Connection $db):string{$rows=[];foreach(FundingCloneScrubber::TABLES as $table)$rows[$table]=$db->pdo()->query('SELECT * FROM '.$table.' ORDER BY '.($table==='property_funding_heads'?'supplier_id,house_id':($table==='property_funding_claims'?'supplier_id,operation_id,source_kind,source_id,claim_role':'supplier_id,id')))->fetchAll(PDO::FETCH_ASSOC);return PropertyInput::hash($rows);}
    public function testActualClonePreservesNativeGraphFinancialTotalsRecipientAndReverse():void
    {
        $data=self::$data;$before=$this->fingerprint(self::$source);
        $this->copy(true,'synthetic-funding-clone');
        self::assertSame($before,$this->fingerprint(self::$source));$pdo=$this->target->pdo();$pdo->beginTransaction();$s=$data['f']['supplier'];$owner=new FundingRepository($this->target);
        $all=$owner->list($s,$data['f']['house']['id']);self::assertCount(7,$all['items']);foreach($all['items'] as $operation)$owner->operation($s,$operation['id']);$read=(new FundingRead($this->target))->at($s,null,'2026-12-31');self::assertTrue($read['matches'],json_encode($read['differences']));self::assertSame('22500000',$read['totals']['955.100']);self::assertSame('200000',$read['totals']['324.200']);self::assertSame('1500000',$read['totals']['379.920']);
        $sourceProof=self::$source->pdo()->prepare('SELECT snapshot_json FROM property_funding_operations WHERE supplier_id=? AND id=?');foreach($all['items']as$operation)if($operation['kind']==='draw'){$sourceProof->execute([$s,$operation['id']]);$original=json_decode((string)$sourceProof->fetchColumn(),true,flags:JSON_THROW_ON_ERROR);self::assertSame($original['proofs']['cost_capacity_hash'],$operation['snapshot']['proofs']['cost_capacity_hash']);}
        $payout=$owner->operation($s,$data['payout']['id']);$reverse=$owner->operation($s,$data['reverse']['id']);self::assertFalse($payout['active']);self::assertSame($data['payout']['request']['recipient_id'],$payout['request']['recipient_id']);self::assertSame($data['payout']['request']['dispositions'][0]['party_ids'],$payout['request']['dispositions'][0]['party_ids']);self::assertSame($payout['id'],$reverse['snapshot']['proofs']['original']['id']);self::assertSame($data['reverse']['receipt']['claims'],$reverse['receipt']['claims']);self::assertNotSame($data['payout']['request_hash'],$payout['request_hash']);
        foreach(['property_funding_operations','property_funding_effects'] as $table){$json=json_encode($pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);foreach(['draw-private@synthetic.invalid','key-private@synthetic.invalid','purpose-private@synthetic.invalid','contribution-private@synthetic.invalid','roof-private@synthetic.invalid','payout-private@synthetic.invalid','mandate-private@synthetic.invalid'] as $secret)self::assertStringNotContainsString($secret,$json);}
        $numeric='SELECT supplier_id,operation_id,unit_id,account_code,amount_cents,source_kind,source_id FROM property_funding_effects ORDER BY supplier_id,id';self::assertSame(self::$source->pdo()->query($numeric)->fetchAll(PDO::FETCH_ASSOC),$pdo->query($numeric)->fetchAll(PDO::FETCH_ASSOC));
        $transfer=array_values(array_filter($all['items'],static fn(array $operation):bool=>$operation['kind']==='transfer'))[0];$request=['kind'=>'reverse','house_id'=>$data['f']['house']['id'],'accounting_date'=>'2026-12-05','expected_revision'=>$all['revision'],'operation_id'=>$transfer['id'],'evidence_ref'=>'Synthetic clone native reverse approval','reason'=>'Synthetic native reverse after clone'];$preview=$owner->preview($s,$request,$data['f']['actor']);$owner->apply($s,$request,$preview['preview_hash'],'clone-native-transfer-reverse',$data['f']['actor']);$read=(new FundingRead($this->target))->at($s,null,'2026-12-31');self::assertTrue($read['matches'],json_encode($read['differences']));self::assertSame('18000000',$read['totals']['955.100']);self::assertSame('6000000',$read['totals']['379.920']);self::assertSame($before,$this->fingerprint(self::$source));$pdo->rollBack();
    }
    public function testCoherentlyRehashedNativeCounterpartCannotBeLaundered():void
    {
        $data=self::$data;$copy=$this->copy();$pdo=$copy->pdo();$pdo->beginTransaction();$s=$data['f']['supplier'];$owner=new FundingRepository($copy);$draw=$owner->list($s,$data['f']['house']['id'])['items'];$draw=array_values(array_filter($draw,static fn(array $operation):bool=>$operation['kind']==='draw'))[0];$snapshot=$draw['snapshot'];$snapshot['journal']['lines'][1]['amount']='2.00';$line=$draw['receipt']['ledger']['lines'][1]['id'];$pdo->prepare('UPDATE journal_entry_lines SET amount=2 WHERE supplier_id=? AND id=?')->execute([$s,$line]);$receipt=$draw['receipt'];$receipt['ledger']=(new \MyInvoice\Service\Property\Payments\PaymentJournalProof($copy))->current($s,$draw['journal_entry_id']);$snapshotHash=PropertyInput::hash($snapshot);$requestHash=PropertyInput::hash(['request'=>$draw['request'],'preview_hash'=>$snapshotHash,'token'=>$draw['idempotency_key'],'actor'=>$data['f']['actor']]);$pdo->prepare('UPDATE property_funding_operations SET snapshot_json=?,snapshot_hash=?,request_hash=?,receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=?')->execute([json_encode($snapshot),$snapshotHash,$requestHash,json_encode($receipt),PropertyInput::hash($receipt),$s,$draw['id']]);$pdo->commit();$before=$this->fingerprint($copy);
        try{(new AnonymizationService(self::$config))->anonymizeCopy(new AnonymizationOptions(self::$sourceName,$this->owned[0],seed:'synthetic-corrupt-funding'),static function():void{});self::fail('Coherently rehashed native counterpart was laundered.');}catch(\MyInvoice\Service\Property\Funds\FundingException $error){self::assertSame('property_funding_financial_journal',$error->errorCode);}
        self::assertSame($before,$this->fingerprint($copy));
    }
    public function testMissingCaptureFailsClosedWithoutRewritingFinancialGraph():void
    {
        $this->copy();$before=$this->fingerprint($this->target);$pdo=$this->target->pdo();$pdo->beginTransaction();
        try{FundingCloneScrubber::run($this->target,new TextScrubber(new Pseudonymizer('synthetic-missing-funding')),[],[],static fn(array $data):array=>$data,static fn(string $hash,array $data):string=>PropertyInput::hash($data),[]);self::fail('Funding graph accepted without native pre-transform verification.');}catch(\LogicException $e){self::assertSame('Funding clone requires its original native proof capture.',$e->getMessage());}finally{$pdo->rollBack();}self::assertSame($before,$this->fingerprint($this->target));
    }
}
