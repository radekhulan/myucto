<?php
declare(strict_types=1);
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Tests\Support\PropertyChargeFixture;
use PHPUnit\Framework\TestCase;
final class OpeningEpochNativeTest extends TestCase {
 use PropertyChargeFixture;
 use MyInvoice\Tests\Support\PaymentCorrectionFixture;
 private Connection $db;
 private array $f;
 public function testBalancedPersonalOpening():void {
  $container=Bootstrap::buildContainer();$db=$container->get(Connection::class);$pdo=$db->pdo();self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));self::assertSame(getenv('MYINVOICE_DB_NAME'),$pdo->query('SELECT DATABASE()')->fetchColumn());$pdo->beginTransaction();
  try {
   $f=$this->chargeFixture($db);$this->f=$f;$this->db=$db;$s=$f['supplier'];(new AccountingPeriodRepository($db))->create($s,2026,'2026-01-01','2026-12-31');(new ChartOfAccountsSeeder($db))->seedForSupplier($s,'2026-01-01');
   $accounts=new MyInvoice\Repository\ChartOfAccountsRepository($db);foreach(['315.100','379.910','379.900'] as $code)if($accounts->findByCode($s,$code)===null){$parent=$accounts->findByCode($s,substr($code,0,3));$accounts->insert($s,['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
   $common=['unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'origin_at'=>'2026-01-01T00:00:00+01:00','period_from'=>'2026-01-01','period_to'=>'2026-01-01','due_date'=>'2026-01-02','occurrence_ref'=>'2026','title_ref'=>'Synthetic title','evidence_ref'=>'Synthetic reconciliation'];
   $debt=$common+['case_ref'=>'debt-A','kind'=>'debt','components'=>[['key'=>'principal','role'=>'principal','account_code'=>'315.100','amount_cents'=>'1000000']],'liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic personal claim','valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding']];
   $credit=$common+['case_ref'=>'credit-B','kind'=>'credit','components'=>[['key'=>'credit','role'=>'credit','account_code'=>'379.910','amount_cents'=>'1000000']],'creditor_ids'=>[$f['clients'][18]]];
   $request=['mode'=>'post_new','accounting_date'=>'2026-01-01','effective_at'=>'2026-01-01T00:00:00+01:00','expected_revision'=>0,'evidence_ref'=>'Synthetic signed takeover','reason'=>'Synthetic opening','items'=>[$debt,$credit]];

   $repo=$container->get(OpeningRepository::class);$preview=$repo->preview($s,$request);$snapshot=$preview['snapshot'];$normalized=$snapshot['request'];$token='epoch-native';$actor=$f['actor'];$hash=MyInvoice\Service\Property\PropertyInput::hash(['request'=>$normalized,'token'=>$token,'actor'=>$actor]);
   $pdo->exec('SAVEPOINT epoch_before');
   $q=$pdo->prepare('INSERT INTO property_opening_batches (supplier_id,period_id,previous_id,revision_no,mode,accounting_date,effective_at,request_json,request_hash,snapshot_json,snapshot_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,NULL,1,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
   $q->execute([$s,$snapshot['period_id'],$normalized['mode'],$normalized['accounting_date'],MyInvoice\Service\Property\PropertyInstant::sql($normalized['effective_at']),json_encode($normalized,JSON_THROW_ON_ERROR),$hash,json_encode($snapshot,JSON_THROW_ON_ERROR),$preview['preview_hash'],$token,$actor]);$id=(int)$pdo->lastInsertId();
   $proof=MyInvoice\Service\Property\Opening\OpeningOwnerProof::fromPending($db,$repo,$s,$id);$journal=$proof->journal($db,'journal');$wrong=$journal['lines'];$wrong[0]['amount']='1.00';try{$proof->indices($db,$journal['header'],$wrong,'journal');self::fail('Changed writer lines accepted.');}catch(MyInvoice\Service\Property\Opening\OpeningException $e){self::assertSame('property_opening_intent_target',$e->errorCode);}
   try{serialize($proof);self::fail('Proof serialized.');}catch(LogicException $e){self::assertStringContainsString('serialized',$e->getMessage());}
   $pdo->exec('ROLLBACK TO SAVEPOINT epoch_before');self::assertTrue($pdo->inTransaction());
   try{$container->get(MyInvoice\Service\Accounting\Activation\OpeningBalanceService::class)->postPropertyOpening($proof);self::fail('Stale savepoint proof wrote.');}catch(LogicException $e){self::assertStringContainsString('epoch',$e->getMessage());}
   self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id='.$s)->fetchColumn());
   $fresh=$repo->preview($s,$request);$written=$repo->apply($s,$request,$fresh['preview_hash'],'fresh-native',$actor);self::assertSame('posted',$written['status']);
  }finally{if($pdo->inTransaction())$pdo->rollBack();}
 }
}
