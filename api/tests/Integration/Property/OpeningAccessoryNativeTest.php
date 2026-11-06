<?php
declare(strict_types=1);
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Tests\Support\PropertyChargeFixture;
use PHPUnit\Framework\TestCase;
final class OpeningAccessoryNativeTest extends TestCase {
 use PropertyChargeFixture;
 use MyInvoice\Tests\Support\PaymentCorrectionFixture;
 private Connection $db;
 private array $f;
 public function testBalancedPersonalOpening():void {
  $container=Bootstrap::buildContainer();$db=$container->get(Connection::class);$pdo=$db->pdo();self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));self::assertSame(getenv('MYINVOICE_DB_NAME'),$pdo->query('SELECT DATABASE()')->fetchColumn());$pdo->beginTransaction();
  try {
   $f=$this->chargeFixture($db);$this->f=$f;$this->db=$db;$s=$f['supplier'];(new AccountingPeriodRepository($db))->create($s,2026,'2026-01-01','2026-12-31');(new ChartOfAccountsSeeder($db))->seedForSupplier($s,'2026-01-01');
   $accounts=new MyInvoice\Repository\ChartOfAccountsRepository($db);foreach(['315.100','315.410','379.910','379.900'] as $code)if($accounts->findByCode($s,$code)===null){$parent=$accounts->findByCode($s,substr($code,0,3));$accounts->insert($s,['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
   $common=['unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'origin_at'=>'2026-01-01T00:00:00+01:00','period_from'=>'2026-01-01','period_to'=>'2026-01-01','due_date'=>'2026-01-02','occurrence_ref'=>'2026','title_ref'=>'Synthetic title','evidence_ref'=>'Synthetic reconciliation'];
   $debt=$common+['case_ref'=>'debt-A','kind'=>'debt','components'=>[['key'=>'principal','role'=>'principal','account_code'=>'315.100','amount_cents'=>'990000']],'liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic personal claim','valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding']];
   $credit=$common+['case_ref'=>'credit-B','kind'=>'credit','components'=>[['key'=>'credit','role'=>'credit','account_code'=>'379.910','amount_cents'=>'1000000']],'creditor_ids'=>[$f['clients'][18]]];
   $debt['components'][]=['key'=>'interest','role'=>'interest','account_code'=>'315.410','amount_cents'=>'10000'];
   $request=['mode'=>'post_new','accounting_date'=>'2026-01-01','effective_at'=>'2026-01-01T00:00:00+01:00','expected_revision'=>0,'evidence_ref'=>'Synthetic signed takeover','reason'=>'Synthetic opening','items'=>[$debt,$credit]];
   $repo=$container->get(OpeningRepository::class);$preview=$repo->preview($s,$request);$result=$repo->apply($s,$request,$preview['preview_hash'],'opening-native',$f['actor']);self::assertSame('posted',$result['status']);self::assertCount(2,$result['items']);
   $debtId=(int)$pdo->query('SELECT id FROM unit_payment_debts WHERE supplier_id='.$s)->fetchColumn();$creditId=(int)$pdo->query('SELECT id FROM unit_credit_lots WHERE supplier_id='.$s)->fetchColumn();
   $current=(new MyInvoice\Repository\Property\Payments\DebtRepository($db))->current($s,$debtId);self::assertSame('990000',$current['snapshot']['amount_cents']);
   $currentCredit=$container->get(MyInvoice\Repository\Property\Payments\PaymentRepository::class)->credit($s,$creditId);self::assertSame('1000000',$currentCredit['balance_cents']);
   $recon=(new MyInvoice\Service\Property\Receivables\PropertyReconciliation($db))->at($s,'2026-01-31',$f['units']['U02']);self::assertSame(['receivable'=>'1000000','credit'=>'1000000'],$recon['ledger_cents']);self::assertSame($recon['ledger_cents'],$recon['subledger_cents']);self::assertTrue($recon['matches']);
   $history=(new MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository($db))->readForDebts($s,[$debtId]);self::assertCount(1,$history['debts']);
   $projection=(new MyInvoice\Service\Property\Receivables\PropertyReceivableProjection($db))->at($s,'2026-01-31T23:59:59+01:00',MyInvoice\Repository\Property\Receivables\PartyEventRepository::now(),['unit_id'=>$f['units']['U02']],true);self::assertSame('1000000',$projection['totals']['gross_receivable_cents']);self::assertSame('1000000',$projection['totals']['gross_credit_cents']);
   $again=$repo->apply($s,$request,$preview['preview_hash'],'opening-native',$f['actor']);self::assertSame($result['id'],$again['id']);
   $revision=$request;$revision['mode']='revision';$revision['expected_revision']=1;$revision['accounting_date']='2026-01-02';$revision['effective_at']='2026-01-02T00:00:00+01:00';$revision['reason']='Synthetic correction';foreach($revision['items'] as &$item)$item['components'][0]['amount_cents']=$item['kind']==='debt'?'890000':'900000';unset($item);
   $revPreview=$repo->preview($s,$revision);$rev=$repo->apply($s,$revision,$revPreview['preview_hash'],'revision-native',$f['actor']);self::assertSame('posted',$rev['status']);
   $recon=(new MyInvoice\Service\Property\Receivables\PropertyReconciliation($db))->at($s,'2026-01-31',$f['units']['U02']);self::assertSame(['receivable'=>'900000','credit'=>'900000'],$recon['ledger_cents']);self::assertSame($recon['ledger_cents'],$recon['subledger_cents']);self::assertTrue($recon['matches']);
   self::assertFalse((new MyInvoice\Repository\Property\Payments\DebtRepository($db))->current($s,$debtId)['active']);
   $newDebt=(int)$pdo->query('SELECT MAX(id) FROM unit_payment_debts WHERE supplier_id='.$s)->fetchColumn();$newCredit=(int)$pdo->query('SELECT MAX(id) FROM unit_credit_lots WHERE supplier_id='.$s)->fetchColumn();
   $payments=$container->get(MyInvoice\Repository\Property\Payments\PaymentRepository::class);$bank=$this->correctionBank('200.00');
   $payment=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'20000','accounting_date'=>'2026-02-01','reason'=>'Synthetic opening debt payment','debts'=>[$newDebt],'payment'=>['payer_id'=>$f['clients'][1],'debtor_id'=>$f['clients'][1],'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$newDebt],'evidence'=>'Synthetic disposition']]];
   $paid=$payments->apply($s,$payment,$payments->preview($s,$payment)['preview_hash'],'opening-payment',$f['actor']);self::assertSame('posted',$paid['status']);
   \MyInvoice\Tests\Support\RefundAccountFixture::document($pdo,$s,$f['clients'][18]);$reservation=$payments->reserve($s,$newCredit,1,['amount_cents'=>'30000','party_ids'=>[$f['clients'][18]],'disposition'=>'Synthetic former-owner refund instruction','recipient_account'=>'1000000005/0100'],'former-owner-reserve',$f['actor']);
   $out=$this->correctionBank('-300.00');$refund=['kind'=>'refund','source'=>['kind'=>'bank','id'=>$out,'expected_version'=>0],'amount_cents'=>'30000','accounting_date'=>'2026-02-01','reservation_id'=>$reservation['id'],'expected_reservation_version'=>1,'reason'=>'Synthetic former-owner refund'];
   $returned=$payments->apply($s,$refund,$payments->preview($s,$refund)['preview_hash'],'opening-refund',$f['actor']);self::assertSame('posted',$returned['status']);self::assertSame('870000',$payments->credit($s,$newCredit)['balance_cents']);
   $recon=(new MyInvoice\Service\Property\Receivables\PropertyReconciliation($db))->at($s,'2026-02-28',$f['units']['U02']);self::assertSame(['receivable'=>'880000','credit'=>'870000'],$recon['ledger_cents']);self::assertSame($recon['ledger_cents'],$recon['subledger_cents']);
   $read=new MyInvoice\Repository\Property\Payments\PaymentOperationsReadRepository($db);$visible=$read->page($s,'debts',0,25,$f['units']['U02']);self::assertCount(2,$visible['items']);self::assertSame('880000',$visible['items'][1]['remaining_cents']);$credits=$read->page($s,'credits',0,25,$f['units']['U02']);self::assertCount(2,$credits['items']);self::assertSame('870000',$credits['items'][1]['balance_cents']);$operations=$read->page($s,'operations',0,25,$f['units']['U02']);self::assertCount(2,$operations['items']);
   $closure=(new MyInvoice\Repository\Property\Receivables\PropertySaldoReadClosure($db))->units($s,[$f['units']['U02']]);MyInvoice\Repository\Property\Receivables\PropertySaldoReadProof::materializations($closure->rows);self::assertCount(2,$closure->debtIds);
   $blocked=$revision;$blocked['expected_revision']=2;try{$repo->preview($s,$blocked);self::fail('Used opening revised.');}catch(MyInvoice\Service\Property\Opening\OpeningException $e){self::assertSame('property_opening_already_used',$e->errorCode);}



  }finally{if($pdo->inTransaction())$pdo->rollBack();}
 }
}
