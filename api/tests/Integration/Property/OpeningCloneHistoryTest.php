<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\{PropertyRegistryRepository,PropertyReadRepository};
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Service\Anonymization\{DatabaseCloner,OpeningCloneScrubber,PropertyCloneScrubber,Pseudonymizer,TextScrubber};
use MyInvoice\Service\Property\Opening\OpeningReadProof;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,CloneSandbox};
use PDO;
use PHPUnit\Framework\TestCase;
final class OpeningCloneHistoryTest extends TestCase
{
    use ChargeIssuanceFixture;
    private static array $owned=[];
    private static array $connections=[];
    private static ?PDO $server=null;
    private static ?Config $config=null;
    /** Fixtura i přepsaný registr se staví jednou; oba testy mění cíl jen v transakci, kterou vrátí. */
    private static ?array $prepared=null;
    private function connect(string $schema):Connection{$data=self::$config->all();$data['db']['name']=$schema;$db=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($data,self::$config->dataDir())));self::$connections[]=$db;return $db;}
    public static function tearDownAfterClass():void{try{foreach(self::$connections as $db){if($db->pdo()->inTransaction())$db->pdo()->rollBack();$db->close();}if(self::$server!==null)foreach(array_reverse(self::$owned) as $schema)CloneSandbox::dropOrRelease(self::$server,$schema,self::$owned);}finally{self::$connections=[];self::$owned=[];self::$prepared=null;}}
    private function prepare():array
    {
        if(self::$prepared!==null)return self::$prepared;
        self::$config=Config::load(Bootstrap::rootDir());$base=self::$config->get('db.name');self::assertStringEndsWith('_test',$base);self::assertContains(self::$config->get('db.host'),['localhost','127.0.0.1','::1']);$token=bin2hex(random_bytes(5));self::$owned=['myucto_opening_history_'.$token.'_source_test','myucto_opening_history_'.$token.'_target_test'];self::$server=new PDO('mysql:host='.self::$config->get('db.host').';port='.self::$config->get('db.port',3306).';charset=utf8mb4',self::$config->get('db.user'),self::$config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$q=self::$server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?,?)');$q->execute(self::$owned);self::assertSame(0,(int)$q->fetchColumn());self::$owned[0]=CloneSandbox::acquire(self::$server,$base,true);$source=$this->connect(self::$owned[0]);$source->pdo()->beginTransaction();$f=$this->issuanceFixture($source);$s=$f['supplier'];$unit=$f['units']['U01'];$state=$f['states']['U01'];$state['identifiers'][]=['kind'=>'text','value'=>'private-history-identifier@synthetic.invalid','payment_scope'=>'supplier','source'=>'manual','title_ref'=>'Synthetic historical identifier'];$registry=new PropertyRegistryRepository($source);
        $change=['expected_revision'=>1,'effective_from'=>'2026-01-02T00:00:00+01:00','status'=>'confirmed','state'=>$state,'reason'=>'Synthetic historical identity','title_ref'=>'Synthetic deed','idempotency_key'=>'old-identity'];$registry->revise($s,$unit,'unit',$change,$f['actor']);$change['expected_revision']=2;$change['effective_from']='2026-01-03T00:00:00+01:00';$change['state']=$f['states']['U01'];$change['idempotency_key']='new-identity';$registry->revise($s,$unit,'unit',$change,$f['actor']);
        $accounts=new \MyInvoice\Repository\ChartOfAccountsRepository($source);if(!$accounts->findByCode($s,'955.100')){$parent=$accounts->findByCode($s,'955');$accounts->insert($s,['account_code'=>'955.100','name'=>'Synthetic long advance','account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
        $item=['unit_id'=>$unit,'service_id'=>null,'origin_at'=>'2026-01-05T00:00:00+01:00','period_from'=>'2026-01-05','period_to'=>'2026-01-05','due_date'=>'2030-12-31','occurrence_ref'=>'2026','case_ref'=>'history-opening','kind'=>'advance','title_ref'=>'Synthetic takeover','evidence_ref'=>'Synthetic matched payment','components'=>[['key'=>'long','role'=>'long_term_advance','account_code'=>'955.100','amount_cents'=>'1000000']],'advance_state'=>['purpose_ref'=>'Synthetic future repair','settlement_due_on'=>'2030-12-31','prescribed_cents'=>'1000000','paid_cents'=>'1000000','used_cents'=>'0','unpaid_cases'=>[]]];$request=['mode'=>'post_new','accounting_date'=>'2026-01-05','effective_at'=>'2026-01-05T00:00:00+01:00','expected_revision'=>0,'evidence_ref'=>'Synthetic signed takeover','reason'=>'Synthetic historical takeover','items'=>[$item],'other_balances'=>[['account_code'=>'221','side'=>'debit','amount_cents'=>'1000000','dimensions'=>[]]]];$container=Bootstrap::buildContainer();$container->set(Connection::class,$source);$owner=$container->get(OpeningRepository::class);$batch=$owner->apply($s,$request,$owner->preview($s,$request)['preview_hash'],'opening-history',$f['actor']);$source->pdo()->commit();self::$owned[1]=CloneSandbox::acquire(self::$server,self::$owned[0]);$target=$this->connect(self::$owned[1]);$scrubber=new TextScrubber(new Pseudonymizer('synthetic-opening-history'));$map=(new PropertyCloneScrubber($target->pdo(),$scrubber))->run();return self::$prepared=compact('source','target','scrubber','map','s','unit','batch');
    }
    public function testOpeningCloneRehashesAndScrubsEveryHistoricalRegistryState():void
    {
        $data=$this->prepare();$pdo=$data['target']->pdo();$before=$data['source']->pdo()->query('SELECT snapshot_json,snapshot_hash FROM property_opening_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$pdo->beginTransaction();(new OpeningCloneScrubber($data['target'],$data['scrubber']))->run($data['map'],static fn(array $value):array=>$value,static fn(array $value):array=>$value,static fn(string $hash,array $value):string=>PropertyInput::hash($value));
        $reader=new OpeningReadProof($data['target']);$item=$reader->item($data['s'],$data['batch']['items'][0]['id'],true);self::assertSame('1000000',$item['snapshot']['amount_cents']);self::assertCount(3,$item['snapshot']['registry']['unit']['history']);$native=(new PropertyReadRepository($data['target']))->find($data['s'],$data['unit'],'2026-01-05T00:00:00+01:00',null,true);$native=array_column($native['history'],null,'id');foreach($item['snapshot']['registry']['unit']['history'] as $revision){self::assertSame(PropertyInput::hash($revision['state']),$revision['state_hash']);self::assertSame($native[$revision['id']]['state_hash'],$revision['state_hash']);self::assertSame($native[$revision['id']]['state'],$revision['state']);}
        foreach(['property_opening_batches','property_opening_items'] as $table)self::assertStringNotContainsString('private-history-identifier@synthetic.invalid',json_encode($pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));self::assertSame($before,$data['source']->pdo()->query('SELECT snapshot_json,snapshot_hash FROM property_opening_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));$pdo->rollBack();
    }
    public function testHistoricalProofCorruptionAndMissingMappingsFailClosedAtomically():void
    {
        $data=$this->prepare();$pdo=$data['target']->pdo();$rows=$pdo->query('SELECT id,snapshot_json,snapshot_hash FROM property_opening_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$original=PropertyInput::hash($rows);
        foreach(['source','missing','target'] as $mutation){$pdo->beginTransaction();$map=$data['map'];$snapshot=json_decode($rows[0]['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);$old=$snapshot['registry']['unit']['history'][1];
            if($mutation==='source'){$snapshot['registry']['unit']['history'][1]['state']['identifiers'][0]['value']='Synthetic tampered history';$pdo->prepare('UPDATE property_opening_items SET snapshot_json=?,snapshot_hash=? WHERE id=?')->execute([json_encode($snapshot),PropertyInput::hash($snapshot),$rows[0]['id']]);foreach($pdo->query('SELECT id,snapshot_json FROM property_opening_batches ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row){$batch=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);$batch['items'][0]['registry']['unit']['history'][1]['state']['identifiers'][0]['value']='Synthetic tampered history';$pdo->prepare('UPDATE property_opening_batches SET snapshot_json=?,snapshot_hash=? WHERE id=?')->execute([json_encode($batch),PropertyInput::hash($batch),$row['id']]);}}
            elseif($mutation==='missing')unset($map[$data['s']][$old['id']]);else $map[$data['s']][$old['id']]['after_state_hash']=str_repeat('a',64);
            try{(new OpeningCloneScrubber($data['target'],$data['scrubber']))->run($map,static fn(array $value):array=>$value,static fn(array $value):array=>$value,static fn(string $hash,array $value):string=>PropertyInput::hash($value));self::fail('Historical registry proof accepted: '.$mutation);}catch(\LogicException $error){self::assertSame(match($mutation){'source'=>'Opening registry clone source mismatch.','missing'=>'Opening registry clone proof missing.','target'=>'Opening registry clone target mismatch.'},$error->getMessage());}finally{$pdo->rollBack();}
            self::assertSame($original,PropertyInput::hash($pdo->query('SELECT id,snapshot_json,snapshot_hash FROM property_opening_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));
        }
    }

}
