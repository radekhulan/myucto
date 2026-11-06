<?php
declare(strict_types=1);
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Tests\Support\PropertyChargeFixture;
use PHPUnit\Framework\TestCase;
final class OpeningCarryNativeTest extends TestCase {
 use PropertyChargeFixture;
 use MyInvoice\Tests\Support\PaymentCorrectionFixture;
 private Connection $db;
 private array $f;
 public function testBalancedPersonalOpening():void {
  $container=Bootstrap::buildContainer();$db=$container->get(Connection::class);$pdo=$db->pdo();self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));self::assertSame(getenv('MYINVOICE_DB_NAME'),$pdo->query('SELECT DATABASE()')->fetchColumn());$pdo->beginTransaction();
  try {
   $f=$this->chargeFixture($db);$this->f=$f;$this->db=$db;$s=$f['supplier'];(new AccountingPeriodRepository($db))->create($s,2026,'2026-01-01','2026-12-31');(new ChartOfAccountsSeeder($db))->seedForSupplier($s,'2026-01-01');
   $accounts=new MyInvoice\Repository\ChartOfAccountsRepository($db);foreach(['315.100','379.910','379.900','315.210','324.110','388.210','389.110','955.100'] as $code)if($accounts->findByCode($s,$code)===null){$parent=$accounts->findByCode($s,substr($code,0,3));$accounts->insert($s,['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
   $common=['unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'origin_at'=>'2026-01-01T00:00:00+01:00','period_from'=>'2026-01-01','period_to'=>'2026-01-01','due_date'=>'2026-01-02','occurrence_ref'=>'2026','title_ref'=>'Synthetic title','evidence_ref'=>'Synthetic reconciliation'];
   $debt=$common+['case_ref'=>'debt-A','kind'=>'debt','components'=>[['key'=>'principal','role'=>'principal','account_code'=>'315.100','amount_cents'=>'1000000']],'liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic personal claim','valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding']];
   $credit=$common+['case_ref'=>'credit-B','kind'=>'credit','components'=>[['key'=>'credit','role'=>'credit','account_code'=>'379.910','amount_cents'=>'1000000']],'creditor_ids'=>[$f['clients'][18]]];
   $request=['mode'=>'post_new','accounting_date'=>'2026-01-01','effective_at'=>'2026-01-01T00:00:00+01:00','expected_revision'=>0,'evidence_ref'=>'Synthetic signed takeover','reason'=>'Synthetic opening','items'=>[$debt,$credit]];
   $request['items'][]=$common+['case_ref'=>'service-advance','kind'=>'advance','components'=>[['key'=>'advance','role'=>'service_advance','account_code'=>'324.110','amount_cents'=>'8000000']],'advance_state'=>['purpose_ref'=>'Synthetic service advances','settlement_due_on'=>'2027-04-30','prescribed_cents'=>'8000000','paid_cents'=>'8000000','used_cents'=>'0','unpaid_cases'=>[]]];
   $request['items'][]=$common+['case_ref'=>'service-carry','kind'=>'service_carry','components'=>[['key'=>'cost','role'=>'service_cost','account_code'=>'315.210','amount_cents'=>'8000000'],['key'=>'accrued-cost','role'=>'accrual_receivable','account_code'=>'388.210','amount_cents'=>'5000000'],['key'=>'accrued-cover','role'=>'accrual_payable','account_code'=>'389.110','amount_cents'=>'5000000']],'service_state'=>['profile'=>'service_balance_sheet','allocation_ref'=>'Synthetic service key','counterpart_ref'=>'Synthetic supplier evidence','prescribed_cents'=>'8000000','paid_cents'=>'8000000','settled_cents'=>'0','cancelled_unpaid_cents'=>'0','unpaid_cases'=>[],'recipient_ids'=>[$f['clients'][1]]]];
   $long=$common;$long['service_id']=null;$request['items'][]=$long+['case_ref'=>'long-advance','kind'=>'advance','components'=>[['key'=>'long','role'=>'long_term_advance','account_code'=>'955.100','amount_cents'=>'5000000']],'advance_state'=>['purpose_ref'=>'Synthetic roof repair','settlement_due_on'=>'2030-12-31','prescribed_cents'=>'5000000','paid_cents'=>'5000000','used_cents'=>'0','unpaid_cases'=>[]]];$request['other_balances']=[['account_code'=>'221','side'=>'debit','amount_cents'=>'5000000','dimensions'=>[]]];
   $repo=$container->get(OpeningRepository::class);$preview=$repo->preview($s,$request);$result=$repo->apply($s,$request,$preview['preview_hash'],'opening-native',$f['actor']);self::assertSame('posted',$result['status']);self::assertCount(5,$result['items']);
   $debtId=(int)$pdo->query('SELECT id FROM unit_payment_debts WHERE supplier_id='.$s)->fetchColumn();$creditId=(int)$pdo->query('SELECT id FROM unit_credit_lots WHERE supplier_id='.$s)->fetchColumn();
   $current=(new MyInvoice\Repository\Property\Payments\DebtRepository($db))->current($s,$debtId);self::assertSame('1000000',$current['snapshot']['amount_cents']);
   $currentCredit=$container->get(MyInvoice\Repository\Property\Payments\PaymentRepository::class)->credit($s,$creditId);self::assertSame('1000000',$currentCredit['balance_cents']);
   $recon=(new MyInvoice\Service\Property\Receivables\PropertyReconciliation($db))->at($s,'2026-01-31',$f['units']['U02']);self::assertSame(['receivable'=>'1000000','credit'=>'1000000'],$recon['ledger_cents']);self::assertSame($recon['ledger_cents'],$recon['subledger_cents']);self::assertTrue($recon['matches']);
   $history=(new MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository($db))->readForDebts($s,[$debtId]);self::assertCount(1,$history['debts']);
   $projection=(new MyInvoice\Service\Property\Receivables\PropertyReceivableProjection($db))->at($s,'2026-01-31T23:59:59+01:00',MyInvoice\Repository\Property\Receivables\PartyEventRepository::now(),['unit_id'=>$f['units']['U02']],true);self::assertSame('1000000',$projection['totals']['gross_receivable_cents']);self::assertSame('1000000',$projection['totals']['gross_credit_cents']);
   self::assertSame('complete',$recon['opening_import_coverage']);self::assertSame('8000000',$recon['opening']['role_cents']['service_cost']);self::assertSame('5000000',$recon['opening']['role_cents']['accrual_receivable']);self::assertSame('5000000',$recon['opening']['role_cents']['accrual_payable']);self::assertSame('5000000',$recon['opening']['role_cents']['long_term_advance']);self::assertCount(3,$recon['opening']['states']);
  }finally{if($pdo->inTransaction())$pdo->rollBack();}
 }
}
