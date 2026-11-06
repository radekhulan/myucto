<?php
declare(strict_types=1);
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Tests\Support\PropertyChargeFixture;
use PHPUnit\Framework\TestCase;
final class OpeningPartyNativeTest extends TestCase {
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
   $repo=$container->get(OpeningRepository::class);$preview=$repo->preview($s,$request);$result=$repo->apply($s,$request,$preview['preview_hash'],'opening-native',$f['actor']);self::assertSame('posted',$result['status']);self::assertCount(2,$result['items']);
   $debtId=(int)$pdo->query('SELECT id FROM unit_payment_debts WHERE supplier_id='.$s)->fetchColumn();$creditId=(int)$pdo->query('SELECT id FROM unit_credit_lots WHERE supplier_id='.$s)->fetchColumn();
   $current=(new MyInvoice\Repository\Property\Payments\DebtRepository($db))->current($s,$debtId);self::assertSame('1000000',$current['snapshot']['amount_cents']);
   $currentCredit=$container->get(MyInvoice\Repository\Property\Payments\PaymentRepository::class)->credit($s,$creditId);self::assertSame('1000000',$currentCredit['balance_cents']);
   $recon=(new MyInvoice\Service\Property\Receivables\PropertyReconciliation($db))->at($s,'2026-01-31',$f['units']['U02']);self::assertSame(['receivable'=>'1000000','credit'=>'1000000'],$recon['ledger_cents']);self::assertSame($recon['ledger_cents'],$recon['subledger_cents']);self::assertTrue($recon['matches']);
   $history=(new MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository($db))->readForDebts($s,[$debtId]);self::assertCount(1,$history['debts']);
   $projection=(new MyInvoice\Service\Property\Receivables\PropertyReceivableProjection($db))->at($s,'2026-01-31T23:59:59+01:00',MyInvoice\Repository\Property\Receivables\PartyEventRepository::now(),['unit_id'=>$f['units']['U02']],true);self::assertSame('1000000',$projection['totals']['gross_receivable_cents']);self::assertSame('1000000',$projection['totals']['gross_credit_cents']);
   $again=$repo->apply($s,$request,$preview['preview_hash'],'opening-native',$f['actor']);self::assertSame($result['id'],$again['id']);

   $at='2026-02-02T10:00:00+01:00';$state=$f['states']['U02'];$state['ownerships']=[['group_key'=>'new-owner','kind'=>'single','title_ref'=>'Synthetic title','members'=>[$f['clients'][2]],'share'=>['num'=>'1','den'=>'1']]];
   (new MyInvoice\Repository\Property\PropertyRegistryRepository($db))->revise($s,$f['units']['U02'],'unit',['expected_revision'=>1,'effective_from'=>$at,'status'=>'confirmed','state'=>$state,'reason'=>'Synthetic sale','title_ref'=>'Synthetic sale title','idempotency_key'=>'registry-sale'],$f['actor']);
   $unit=(new MyInvoice\Repository\Property\PropertyReadRepository($db))->find($s,$f['units']['U02'],$at,null,true);$groups=[];foreach([$f['clients'][2],$f['clients'][1]] as $index=>$client)$groups[]=['group_key'=>'person-'.$client,'role'=>$index===0?'debtor':'guarantor','mode'=>'individual','basis'=>'documented_title','members'=>[$client],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic assessed title','legal_reason'=>'Synthetic assessed succession','valid_from'=>$at,'valid_to'=>null];
   $transfer=['unit_id'=>$f['units']['U02'],'registry_revision_id'=>$unit['revision']['id'],'effective_at'=>$at,'decision'=>'transfer_confirmed','reason'=>'Synthetic assessed succession','acquisition'=>['kind'=>'contractual_transfer','applicable_law'=>'89/2012 effective 2026','legal_basis'=>'1186(2),(3)','evidence'=>'Synthetic acquisition','had_and_could_discover'=>true,'knowledge_evidence'=>'Synthetic delivered certificate','guarantee_evidence'=>'Synthetic guarantee assessment'],'debts'=>[['debt_id'=>$debtId,'expected_event_id'=>null,'groups'=>$groups]]];
   $events=new MyInvoice\Repository\Property\Receivables\PartyEventRepository($db);$event=$events->confirm($s,$transfer,$events->preview($s,$transfer)['preview_hash'],'opening-transfer',$f['actor']);self::assertGreaterThan(0,$event['id']);
   $projector=new MyInvoice\Service\Property\Receivables\PropertyReceivableProjection($db);$now=MyInvoice\Repository\Property\Receivables\PartyEventRepository::now();
   $old=$projector->at($s,'2026-02-03T10:00:00+01:00',$now,['client_id'=>$f['clients'][1]],true);self::assertSame('0',$old['totals']['gross_receivable_cents']);self::assertSame('1000000',$old['totals']['guarantee_exposure_cents']);
   $new=$projector->at($s,'2026-02-03T10:00:00+01:00',$now,['client_id'=>$f['clients'][2]],true);self::assertSame('1000000',$new['totals']['gross_receivable_cents']);
   $certificates=new MyInvoice\Repository\Property\Receivables\DebtCertificateRepository($db);$certRequest=['unit_id'=>$f['units']['U02'],'as_of'=>'2026-02-03T10:00:00+01:00','known_at'=>$now,'requester_id'=>$f['clients'][1],'authority_evidence'=>'Synthetic authority','purpose'=>'Synthetic certificate','coverage_acknowledgement'=>'Recorded S3 S4 S6 coverage'];$cert=$certificates->issue($s,$certRequest,$certificates->preview($s,$certRequest)['preview_hash'],'opening-certificate',$f['actor']);self::assertGreaterThan(0,$cert['id']);
   $storno=$request;$storno['mode']='reversal';$storno['expected_revision']=1;$storno['items']=[];try{$repo->preview($s,$storno);self::fail('Opening pinned by legal succession reversed.');}catch(MyInvoice\Service\Property\Opening\OpeningException $e){self::assertSame('property_opening_already_used',$e->errorCode);}
  }finally{if($pdo->inTransaction())$pdo->rollBack();}
 }
}
