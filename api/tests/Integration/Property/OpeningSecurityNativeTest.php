<?php
declare(strict_types=1);
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Repository\Property\Opening\OpeningRepository;
use MyInvoice\Tests\Support\PropertyChargeFixture;
use PHPUnit\Framework\TestCase;
final class OpeningSecurityNativeTest extends TestCase {
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

   $action=$container->get(MyInvoice\Action\Property\Opening\OpeningAction::class);
   $http=(new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST','/property/openings/confirm')->withAttribute(MyInvoice\Middleware\AuthMiddleware::ATTR_USER,['id'=>$f['actor'],'role'=>'admin'])->withAttribute(MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID,$s)->withParsedBody(['request'=>$request,'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'opening-native']);
   self::assertSame(403,$action->confirm($http->withAttribute(MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD,'bearer'),new Slim\Psr7\Response())->getStatusCode());
   $session=$http->withAttribute(MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD,'session');
   self::assertSame(200,$action->confirm($session,new Slim\Psr7\Response())->getStatusCode());
   $restricted=$session->withAttribute('auth.effective_role',new MyInvoice\Security\EffectiveRole(1,'Synthetic readonly','staff',true,['property'=>1]));
   self::assertSame(403,$action->confirm($restricted,new Slim\Psr7\Response())->getStatusCode());
   $catalog=$action->catalog($restricted->withQueryParams([]),new Slim\Psr7\Response());self::assertSame(200,$catalog->getStatusCode());$public=json_decode((string)$catalog->getBody(),true,flags:JSON_THROW_ON_ERROR);self::assertCount(1,$public['items']);self::assertArrayNotHasKey('snapshot',$public['items'][0]);self::assertArrayNotHasKey('request',$public['items'][0]);self::assertArrayNotHasKey('case_key',$public['items'][0]['items'][0]);
   $foreign=$action->catalog($restricted->withQueryParams([])->withAttribute(MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID,$s+1000000),new Slim\Psr7\Response());self::assertSame(404,$foreign->getStatusCode(),(string)$foreign->getBody());
   $before=(int)$pdo->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id='.$s)->fetchColumn();
   try{$repo->apply($s,$request,$preview['preview_hash'],'different-token',$f['actor']);self::fail('Concurrent opening accepted.');}catch(MyInvoice\Service\Property\Opening\OpeningException $e){self::assertStringStartsWith('property_opening_',$e->errorCode);}
   $changed=$request;$changed['reason']='Changed request';try{$repo->apply($s,$changed,$preview['preview_hash'],'opening-native',$f['actor']);self::fail('Idempotency mismatch accepted.');}catch(MyInvoice\Service\Property\Opening\OpeningException $e){self::assertSame('property_opening_idempotency_conflict',$e->errorCode);}
   try{$container->get(MyInvoice\Repository\JournalEntryRepository::class)->updateDescription($result['journal_entry_id'],$s,'Tampered',$f['actor']);self::fail('Generic description mutation accepted.');}catch(MyInvoice\Service\Property\Opening\OpeningException|MyInvoice\Service\Accounting\PostingException|MyInvoice\Service\Property\Payments\PaymentException $e){self::assertNotEmpty($e->getMessage());}
   try{$container->get(MyInvoice\Service\Accounting\PostingService::class)->postDocument($s,'opening',null,[['account_code'=>'315.100','side'=>'debit','amount'=>'1.00'],['account_code'=>'379.910','side'=>'credit','amount'=>'1.00']],['entry_date'=>'2026-01-01','posted_by'=>$f['actor']]);self::fail('Generic second opening accepted.');}catch(MyInvoice\Service\Accounting\PostingException $e){self::assertSame('property_opening_owner_required',$e->errorCode);}
   try{$pdo->prepare('UPDATE property_opening_items SET amount_cents=? WHERE supplier_id=?')->execute(['1',$s]);self::fail('Immutable origin changed.');}catch(PDOException $e){self::assertSame('45000',$e->getCode());}
   self::assertSame($before,(int)$pdo->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id='.$s)->fetchColumn());
   $storno=$request;$storno['mode']='reversal';$storno['expected_revision']=1;$storno['accounting_date']='2026-01-02';$storno['effective_at']='2026-01-02T00:00:00+01:00';$storno['items']=[];
   $sp=$repo->preview($s,$storno);$reversed=$repo->apply($s,$storno,$sp['preview_hash'],'reverse-native',$f['actor']);self::assertSame('posted',$reversed['status']);
   $recon=(new MyInvoice\Service\Property\Receivables\PropertyReconciliation($db))->at($s,'2026-01-31',$f['units']['U02']);self::assertSame(['receivable'=>'0','credit'=>'0'],$recon['ledger_cents']);self::assertSame($recon['ledger_cents'],$recon['subledger_cents']);self::assertSame('complete',$recon['opening_import_coverage']);
   $projector=new MyInvoice\Service\Property\Receivables\PropertyReceivableProjection($db);$past=$projector->at($s,'2026-01-31T23:59:59+01:00',$result['recorded_at'],['unit_id'=>$f['units']['U02']],true);self::assertSame('1000000',$past['totals']['gross_receivable_cents']);$now=$projector->at($s,'2026-01-31T23:59:59+01:00',MyInvoice\Repository\Property\Receivables\PartyEventRepository::now(),['unit_id'=>$f['units']['U02']],true);self::assertSame('0',$now['totals']['gross_receivable_cents']);
  }finally{if($pdo->inTransaction())$pdo->rollBack();}
 }
}
