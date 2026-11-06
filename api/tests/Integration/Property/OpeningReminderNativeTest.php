<?php
declare(strict_types=1);
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Tests\Support\PropertyChargeFixture;
use PHPUnit\Framework\TestCase;
final class OpeningReminderNativeTest extends TestCase {
 use PropertyChargeFixture;
 use MyInvoice\Tests\Support\PaymentCorrectionFixture;
 private Connection $db;
 private array $f;
 public function testImportedRemindersFollowLegalDebtorAndRetainDeliveryRanking():void {
  $container=Bootstrap::buildContainer();$db=$container->get(Connection::class);$pdo=$db->pdo();self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));self::assertSame(getenv('MYINVOICE_DB_NAME'),$pdo->query('SELECT DATABASE()')->fetchColumn());$pdo->beginTransaction();
  try {
   $f=$this->chargeFixture($db);$this->f=$f;$this->db=$db;$s=$f['supplier'];(new AccountingPeriodRepository($db))->create($s,2026,'2026-01-01','2026-12-31');(new ChartOfAccountsSeeder($db))->seedForSupplier($s,'2026-01-01');
   $accounts=new MyInvoice\Repository\ChartOfAccountsRepository($db);foreach(['315.100','379.910','379.900'] as $code)if($accounts->findByCode($s,$code)===null){$parent=$accounts->findByCode($s,substr($code,0,3));$accounts->insert($s,['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
   $common=['unit_id'=>$f['units']['U01'],'service_id'=>$f['service']['id'],'origin_at'=>'2026-01-01T00:00:00+01:00','period_from'=>'2026-01-01','period_to'=>'2026-01-01','due_date'=>'2026-01-02','occurrence_ref'=>'2026','title_ref'=>'Synthetic title','evidence_ref'=>'Synthetic reconciliation'];
   $debt=$common+['case_ref'=>'debt-A','kind'=>'debt','components'=>[['key'=>'principal','role'=>'principal','account_code'=>'315.100','amount_cents'=>'100000']],'liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'solidary','basis'=>'sjm_713','members'=>[$f['clients'][1],$f['clients'][2]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic personal claim','valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding']];
   $credit=$common+['case_ref'=>'credit-B','kind'=>'credit','components'=>[['key'=>'credit','role'=>'credit','account_code'=>'379.910','amount_cents'=>'200000']],'creditor_ids'=>[$f['clients'][18]]];
   $later=$debt;$later['case_ref']='debt-later';$later['occurrence_ref']='2026-later';$later['due_date']='2026-01-03';
   $later['reminders']=[['delivered_at'=>'2026-01-15T12:00:00+01:00','recipient_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic delivery to joint debtor A']];
   $request=['mode'=>'post_new','accounting_date'=>'2026-01-20','effective_at'=>'2026-01-20T00:00:00+01:00','expected_revision'=>0,'evidence_ref'=>'Synthetic signed takeover','reason'=>'Synthetic opening','items'=>[$debt,$later,$credit]];
   $repo=$container->get(OpeningRepository::class);$opening=$repo->preview($s,$request);$repo->apply($s,$request,$opening['preview_hash'],'opening-reminders',$f['actor']);
   $debtRepo=new MyInvoice\Repository\Property\Payments\DebtRepository($db);
   $ids=array_map('intval',$pdo->query('SELECT id FROM unit_payment_debts WHERE supplier_id='.$s.' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));self::assertCount(2,$ids);$byDue=[];foreach($ids as $id)$byDue[$debtRepo->current($s,$id)['snapshot']['due_date']]=$id;[$earlyId,$laterId]=[$byDue['2026-01-02'],$byDue['2026-01-03']];
   self::assertSame('2026-01-02',$debtRepo->current($s,$earlyId)['snapshot']['due_date']);
   $stored=$debtRepo->current($s,$laterId)['snapshot']['reminders'];self::assertCount(1,$stored);
   $payments=$container->get(MyInvoice\Repository\Property\Payments\PaymentRepository::class);$bank=$this->correctionBank('200.00');
   $payment=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'20000','accounting_date'=>'2026-02-01','reason'=>'Synthetic joint debt payment','debts'=>$ids,'payment'=>['payer_id'=>$f['clients'][2],'debtor_id'=>$f['clients'][2],'received_at'=>'2026-02-01T10:00:00+01:00']];
   $assertSelected=static function(array $preview,int $expected):void {
    self::assertCount(1,$preview['snapshot']['allocations']);$allocation=$preview['snapshot']['allocations'][0];
    self::assertSame($expected,$allocation['debt_id']);self::assertSame('20000',$allocation['amount_cents']);self::assertSame('principal',$allocation['component']);self::assertNull($allocation['charge_line_id']);self::assertGreaterThan(0,$allocation['opening_component_id']);
    self::assertSame($expected,$preview['snapshot']['resolution']['allocations'][0]['claim_id']);
   };
   $preview=$payments->preview($s,$payment);$assertSelected($preview,$earlyId);
   $forA=$payment;$forA['payment']['payer_id']=$f['clients'][1];$forA['payment']['debtor_id']=$f['clients'][1];
   $assertSelected($payments->preview($s,$forA),$laterId);
   $thirdParty=$payment;$thirdParty['payment']['payer_id']=$f['clients'][1];$thirdParty['payment']['third_party']=['basis'=>'debtor_consent','evidence'=>'Synthetic debtor B consent'];
   $assertSelected($payments->preview($s,$thirdParty),$earlyId);
   $explicit=$payment;$explicit['payment']['reminders']=[['debt_id'=>$laterId,'delivered_at'=>'2026-01-16T12:00:00+01:00','recipient_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic explicit delivery to A']];
   try{$payments->preview($s,$explicit);self::fail('Explicit foreign reminder accepted.');}catch(MyInvoice\Service\Property\Payments\PaymentException $e){self::assertSame('property_payment_reminder_recipient_unproven',$e->errorCode);}
   $explicit['payment']['reminders'][0]['recipient_ids']=[$f['clients'][2]];
   foreach(['2026-02-01T09:59:59+01:00'=>$laterId,'2026-02-01T10:00:00+01:00'=>$earlyId,'2026-02-01T10:00:01+01:00'=>$earlyId] as $instant=>$selected){
    $explicit['payment']['reminders'][0]['delivered_at']=$instant;$assertSelected($payments->preview($s,$explicit),$selected);
   }
   $invalid=$explicit;$invalid['payment']['reminders'][0]['evidence']='';
   try{$payments->preview($s,$invalid);self::fail('Unproven delivery accepted.');}catch(MyInvoice\Service\Property\PropertyException $e){self::assertSame('property_invalid_input',$e->errorCode);self::assertSame('reminder.evidence',$e->details['field']);}
   $invalid=$explicit;$invalid['payment']['reminders'][0]['delivered_at']='not-an-instant';
   try{$payments->preview($s,$invalid);self::fail('Invalid delivery instant accepted.');}catch(MyInvoice\Service\Property\PropertyException $e){self::assertSame('property_invalid_instant',$e->errorCode);}
   $paid=$payments->apply($s,$payment,$payments->preview($s,$payment)['preview_hash'],'opening-reminder-payment',$f['actor']);self::assertSame('posted',$paid['status']);
   $early=$debtRepo->current($s,$earlyId);$late=$debtRepo->current($s,$laterId);
   self::assertSame('20000',array_reduce($early['paid'],fn($sum,$part)=>bcadd($sum,$part,0),'0'));self::assertSame('0',array_reduce($late['paid'],fn($sum,$part)=>bcadd($sum,$part,0),'0'));self::assertSame($stored,$late['snapshot']['reminders']);
   $recon=(new MyInvoice\Service\Property\Receivables\PropertyReconciliation($db))->at($s,'2026-02-28',$f['units']['U01']);
   self::assertSame(['receivable'=>'180000','credit'=>'200000'],$recon['ledger_cents']);self::assertSame($recon['ledger_cents'],$recon['subledger_cents']);self::assertTrue($recon['matches']);
  }finally{if($pdo->inTransaction())$pdo->rollBack();}
 }
}
