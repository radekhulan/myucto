<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Services\Settlement\SettlementOriginAdapter;
final class SettlementFinancialOwnerTest extends TestCase {
 use ChargeIssuanceFixture;
 use \MyInvoice\Tests\Support\PaymentCorrectionFixture;
 use \MyInvoice\Tests\Support\SharedFixtureSavepointTrait;
 private array $f;
 private Connection $db;
 protected function setUp():void {$this->db=Bootstrap::buildContainer()->get(Connection::class);$configured=(string)getenv('MYINVOICE_DB_NAME');self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+_test$/D',$configured);self::assertLessThanOrEqual(64,strlen($configured));self::assertSame($configured,(string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());self::assertSame($this->db->pdo(),Bootstrap::buildContainer()->get(Connection::class)->pdo());$this->db->pdo()->exec('SET SESSION sql_mode=\'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION\'');$this->beginSharedFixtureTest($this->db->pdo());}
 protected function tearDown():void{$this->endSharedFixtureTest($this->db->pdo());}
 /** Základ testu se staví jednou za třídu; fixtury samotné zůstávají pro ostatní třídy beze změny. */
 private function shared(string $fixture,mixed ...$args):array
 {
  $state=$this->sharedFixture($fixture.'|'.serialize($args),fn()=>['data'=>$this->$fixture(...$args),'f'=>$this->f]);$this->f=$state['f'];return $state['data'];
 }
 private function openingFixture():array {
  $f=$this->chargeFixture($this->db);$this->f=$f;$supplier=$f['supplier'];
  (new \MyInvoice\Repository\AccountingPeriodRepository($this->db))->create($supplier,2026,'2026-01-01','2026-12-31');
  (new \MyInvoice\Service\Accounting\ChartOfAccountsSeeder($this->db))->seedForSupplier($supplier,'2026-01-01');
  $accounts=new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);
  foreach(['315.100','315.210','324.110','379.900','379.910'] as $code){if($accounts->findByCode($supplier,$code)!==null)continue;$parent=$accounts->findByCode($supplier,substr($code,0,3));$accounts->insert($supplier,['account_code'=>$code,'name'=>'Synthetic '.$code,'account_type'=>$parent['account_type'],'normal_side'=>$parent['normal_side'],'parent_id'=>$parent['id']]);}
  $common=['unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'origin_at'=>'2026-01-01T00:00:00+01:00','period_from'=>'2026-01-01','period_to'=>'2026-01-01','due_date'=>'2026-01-15','occurrence_ref'=>'2026','title_ref'=>'Synthetic title','evidence_ref'=>'Synthetic takeover reconciliation'];
  $links=[['case_ref'=>'unpaid-service','occurrence_ref'=>'2026','amount_cents'=>'40000']];
  $debt=$common+['case_ref'=>'unpaid-service','kind'=>'debt','components'=>[['key'=>'principal','role'=>'principal','account_code'=>'315.100','amount_cents'=>'40000']],'liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic personal claim','valid_from'=>'2026-01-01T00:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-15','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic finding']];
  $advance=$common+['case_ref'=>'service-advance','kind'=>'advance','components'=>[['key'=>'advance','role'=>'service_advance','account_code'=>'324.110','amount_cents'=>'100000']],'advance_state'=>['purpose_ref'=>'Synthetic services','settlement_due_on'=>'2027-04-30','prescribed_cents'=>'100000','paid_cents'=>'60000','used_cents'=>'0','unpaid_cases'=>$links]];
  $cost=$common+['case_ref'=>'service-cost','kind'=>'service_carry','components'=>[['key'=>'cost','role'=>'service_cost','account_code'=>'315.210','amount_cents'=>'80000']],'service_state'=>['profile'=>'service_balance_sheet','allocation_ref'=>'Synthetic service key','counterpart_ref'=>'Synthetic supplier evidence','prescribed_cents'=>'100000','paid_cents'=>'60000','settled_cents'=>'0','cancelled_unpaid_cents'=>'0','unpaid_cases'=>$links,'recipient_ids'=>[$f['clients'][1]]]];
  $request=['mode'=>'post_new','accounting_date'=>'2026-01-01','effective_at'=>'2026-01-01T00:00:00+01:00','expected_revision'=>0,'evidence_ref'=>'Synthetic signed takeover','reason'=>'Synthetic opening','items'=>[$debt,$advance,$cost],'other_balances'=>[['account_code'=>'321','side'=>'credit','amount_cents'=>'20000','dimensions'=>[]]]];
  $repo=Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Opening\OpeningRepository::class);$preview=$repo->preview($supplier,$request);$result=$repo->apply($supplier,$request,$preview['preview_hash'],'native-s8-opening',$f['actor']);
  $q=$this->db->pdo()->prepare('SELECT id,kind FROM property_opening_items WHERE supplier_id=? AND batch_id=?');$q->execute([$supplier,$result['id']]);$items=array_column($q->fetchAll(PDO::FETCH_ASSOC),'id','kind');
  return $f+['selector'=>['kind'=>'opening','advance_item_id'=>(int)$items['advance'],'service_item_id'=>(int)$items['service_carry']],'opening'=>$result];
 }
 private function openingDocument(array $f):array {
  $current=Bootstrap::buildContainer()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$f['selector']]);
  $facts=['supplier_id'=>$f['supplier'],'unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'segment_key'=>'SYNTHETIC-2026','period_from'=>'2026-01-01','period_to'=>'2026-12-31','cutoff_at'=>'2026-01-31T23:59:59+01:00','known_at'=>'2026-10-17T12:00:00+02:00','recipient_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic service cost and cutoff dossier','prescribed_cents'=>'100000','paid_cents'=>'60000','actual_cents'=>'80000','origins'=>$current['origins'],'cutoff_allocations'=>[],'cost'=>array_values($current['costs'])[0]];
  return ['document'=>\MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts),'current'=>$current];
 }

 public function testNativeLatePaymentSettlesExactlyOnceAndReverses():void {
  extract($this->shared('appliedFixture'));
  self::assertSame($result,$owner->apply($f['supplier'],$saved['id'],$request,0,$preview['preview_hash'],'s8-apply',$f['actor']));
  self::assertSame($saved,$docRepo->find($f['supplier'],$saved['id'],true));
  $current=(new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->currentMany($f['supplier'],[$debt])[0];
  self::assertSame('20000',array_sum($current['paid']).'');self::assertSame('20000',array_sum($current['reductions']).'');
  $history=(new \MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository($this->db))->readForDebts($f['supplier'],[$debt]);
  $events=$history['debts'][$debt]['events'];$claim='0';$paid='0';foreach($events as $event){if($event['kind']==='claim')$claim=bcadd($claim,$event['amount_cents'],0);if($event['kind']==='paid')$paid=bcadd($paid,$event['amount_cents'],0);}self::assertSame('20000',$claim);self::assertSame('20000',$paid);self::assertCount(1,$history['credits']);
  $credit=$payments->credit($f['supplier'],$result['credit_ids'][0]);self::assertSame('10000',$credit['available_cents']);
  $q=$this->db->pdo()->prepare("SELECT a.account_code,SUM(CASE l.side WHEN 'debit' THEN l.amount ELSE -l.amount END) balance FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.supplier_id=? GROUP BY a.account_code");$q->execute([$f['supplier']]);$balances=array_column($q->fetchAll(PDO::FETCH_ASSOC),'balance','account_code');
  self::assertSame('0.00',$balances['315.100']);self::assertSame('0.00',$balances['315.210']);self::assertSame('0.00',$balances['324.110']);self::assertSame('-100.00',$balances['379.910']);self::assertSame('300.00',$balances['221']);
  $reversal=$owner->reverse($f['supplier'],$result['id'],'2026-12-31',1,'Synthetic cancellation','s8-reverse',$f['actor']);
  self::assertSame($reversal,$owner->reverse($f['supplier'],$result['id'],'2026-12-31',1,'Synthetic cancellation','s8-reverse',$f['actor']));
  self::assertSame('0',$payments->credit($f['supplier'],$result['credit_ids'][0])['available_cents']);
  $restored=(new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->currentMany($f['supplier'],[$debt])[0];self::assertSame('30000',array_sum($restored['paid']).'');self::assertSame([],$restored['reductions']);
 }
 private function appliedFixture(bool $apply=true):array {
  $f=$this->openingFixture();$document=$this->openingDocument($f)['document'];
  $docRepo=Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class);
  $saved=$docRepo->save($f['supplier'],$document,[$f['selector']],'2026-12-31',0,'s8-owner-document',$f['actor']);
  $source=$document->snapshot()['origins'][0];$debt=$source['debt_id'];$bank=$this->correctionBank('300.00');
  $r=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'30000','accounting_date'=>'2026-02-01','reason'=>'Synthetic late payment','debts'=>[$debt],'payment'=>['payer_id'=>$f['clients'][1],'debtor_id'=>$f['clients'][1],'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$debt],'evidence'=>'Synthetic debtor disposition']]];
  $payments=$this->correctionPayments();$op=$payments->apply($f['supplier'],$r,$payments->preview($f['supplier'],$r)['preview_hash'],'s8-owner-late',$f['actor']);
  $allocation=(int)$this->db->pdo()->query('SELECT id FROM unit_payment_allocations WHERE operation_id='.$op['id'])->fetchColumn();
  $owner=Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);
  $request=['accounting_date'=>'2026-12-31','evidence'=>'Synthetic final service disposition','advances'=>[['origin_key'=>$source['kind'].':'.$source['id'],'applied_cents'=>'80000']],'reliefs'=>[['allocation_id'=>$allocation,'amount_cents'=>'10000','entitlement'=>['party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic documented creditor','due_date'=>'2027-04-30','account_code'=>'379.910']]],'opening_credits'=>[],'new_claim'=>null];
  $preview=$owner->preview($f['supplier'],$saved['id'],$request,0);

  $result=$apply?$owner->apply($f['supplier'],$saved['id'],$request,0,$preview['preview_hash'],'s8-apply',$f['actor']):null;

 return compact('f','document','docRepo','saved','source','debt','bank','r','payments','op','allocation','owner','request','preview','result');
 }
 public function testGenericReversalCannotDetachSettlementEffects():void {
  extract($this->shared('appliedFixture'));$this->expectException(\MyInvoice\Service\Accounting\PostingException::class);
  Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\PostingService::class)->reverse($f['supplier'],$result['journal_entry_id'],['entry_date'=>'2026-12-31','posted_by'=>$f['actor']]);
 }
 public function testSettlementPreviewCannotReleasePaymentPinnedByNativeLiabilityTransfer():void {
  extract($this->shared('appliedFixture',false));
  $at='2026-03-01T00:00:00+01:00';$unit=$f['units']['U02'];$successor=$f['clients'][2];$old=$f['clients'][1];
  $state=$f['states']['U02'];$state['ownerships']=[['group_key'=>'successor','kind'=>'single','share'=>['num'=>'1','den'=>'1'],'members'=>[$successor],'title_ref'=>'Synthetic acquired title']];
  (new \MyInvoice\Repository\Property\PropertyRegistryRepository($this->db))->revise($f['supplier'],$unit,'unit',['expected_revision'=>1,'effective_from'=>$at,'status'=>'confirmed','state'=>$state,'reason'=>'Synthetic sale','title_ref'=>'Synthetic title','idempotency_key'=>'settlement-successor'],$f['actor']);
  $current=(new \MyInvoice\Repository\Property\PropertyReadRepository($this->db))->find($f['supplier'],$unit,$at,null,true);$groups=[];
  foreach([$successor,$old] as $i=>$party)$groups[]=['group_key'=>'party-'.$party,'role'=>$i===0?'debtor':'guarantor','mode'=>'individual','basis'=>'documented_title','members'=>[$party],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic separately assessed succession','legal_reason'=>'Synthetic continuing guarantee','valid_from'=>$at,'valid_to'=>null];
  $transfer=['unit_id'=>$unit,'registry_revision_id'=>$current['revision']['id'],'effective_at'=>$at,'decision'=>'transfer_confirmed','reason'=>'Synthetic liability succession',
   'acquisition'=>['kind'=>'contractual_transfer','applicable_law'=>'89/2012 effective 2026','legal_basis'=>'1186(2),(3)','evidence'=>'Synthetic acquisition','had_and_could_discover'=>true,'knowledge_evidence'=>'Synthetic delivered certificate','guarantee_evidence'=>'Synthetic continuing guarantee'],
   'debts'=>[['debt_id'=>$debt,'expected_event_id'=>null,'groups'=>$groups]]];
  $parties=new \MyInvoice\Repository\Property\Receivables\PartyEventRepository($this->db);$parties->confirm($f['supplier'],$transfer,$parties->preview($f['supplier'],$transfer)['preview_hash'],'settlement-transfer',$f['actor']);
  $request['reliefs'][0]['entitlement']['party_ids']=[$successor];
  $before=(int)$this->db->pdo()->query('SELECT COUNT(*) FROM property_service_settlement_applications WHERE supplier_id='.$f['supplier'])->fetchColumn();
  try{$owner->preview($f['supplier'],$saved['id'],$request,0);self::fail('Vyúčtování uvolnilo úhradu připnutou k převodu dluhu.');}
  catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertSame('property_payment_party_history_in_use',$e->errorCode);}
  self::assertSame($before,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM property_service_settlement_applications WHERE supplier_id='.$f['supplier'])->fetchColumn());
 }
 public function testOriginalPaidAllocationCannotBeReversedWhileRelieved():void {
  extract($this->shared('appliedFixture'));try{$payments->reverse($f['supplier'],$op['id'],$op['row_version'],'2026-12-31','Synthetic attempted bypass','bypass-reverse',$f['actor']);self::fail('Used allocation reversed');}catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertSame('property_payment_allocation_has_service_settlement',$e->errorCode);}
 }
 public function testGenericInsertCannotForgeSettlementSource():void {
  extract($this->shared('appliedFixture'));$header=$preview['header'];$header['source_id']=null;$header['posted_at']='2026-12-31 12:00:00';$header['posted_by']=$f['actor'];
  $this->expectException(\MyInvoice\Service\Accounting\PostingException::class);Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class)->insert($header,$preview['plan']['lines']);
 }


 private function assertNoApplication(int $supplier,int $entries):void {
  foreach(['property_service_settlement_heads','property_service_settlement_requests','property_service_settlement_applications','property_service_settlement_effects'] as $table)self::assertSame(0,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM '.$table.' WHERE supplier_id='.$supplier)->fetchColumn());
  self::assertSame($entries,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id='.$supplier)->fetchColumn());
 }
 public function testChangedPhysicalSourceAfterPreviewRejectsWithoutWrites():void {
  extract($this->shared('appliedFixture',false));$entries=(int)$this->db->pdo()->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id='.$f['supplier'])->fetchColumn();
  $this->db->pdo()->prepare('UPDATE bank_transactions SET amount=? WHERE id=?')->execute(['301.00',$bank]);
  try{$owner->apply($f['supplier'],$saved['id'],$request,0,$preview['preview_hash'],'changed-bank',$f['actor']);self::fail('Changed physical source accepted');}catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertContains($e->errorCode,['property_payment_source_clearing_mismatch','property_payment_source_changed']);}
  $this->assertNoApplication($f['supplier'],$entries);self::assertSame($saved,$docRepo->find($f['supplier'],$saved['id'],true));
 }
 public function testLateAdditionalPaymentAfterPreviewRejectsWithoutWrites():void {
  extract($this->shared('appliedFixture',false));$later=$r;$later['source']['id']=$this->correctionBank('100.00');$later['amount_cents']='10000';
  $payments->apply($f['supplier'],$later,$payments->preview($f['supplier'],$later)['preview_hash'],'later-physical',$f['actor']);$entries=(int)$this->db->pdo()->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id='.$f['supplier'])->fetchColumn();
  try{$owner->apply($f['supplier'],$saved['id'],$request,0,$preview['preview_hash'],'stale-payment-preview',$f['actor']);self::fail('Stale allocation preview accepted');}catch(DomainException $e){self::assertContains($e->getMessage(),['settlement_preview_stale','settlement_relief_margin']);}
  $this->assertNoApplication($f['supplier'],$entries);
 }
 public function testOwnerRollbackRemovesAllFinancialEffectsAndReversal():void {
  extract($this->shared('appliedFixture',false));$entries=(int)$this->db->pdo()->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id='.$f['supplier'])->fetchColumn();$this->db->pdo()->exec('SAVEPOINT before_service_apply');
  $result=$owner->apply($f['supplier'],$saved['id'],$request,0,$preview['preview_hash'],'rollback-apply',$f['actor']);$credit=$result['credit_ids'][0];
  $this->db->pdo()->exec('SAVEPOINT before_service_reverse');$owner->reverse($f['supplier'],$result['id'],'2026-12-31',1,'Synthetic rollback reversal','rollback-reverse',$f['actor']);
  $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT before_service_reverse');self::assertSame('10000',$payments->credit($f['supplier'],$credit)['available_cents']);
  self::assertNull((new \MyInvoice\Service\Property\Payments\PaymentJournalProof($this->db))->current($f['supplier'],$result['journal_entry_id'])['entry']['reversed_by']);
  $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT before_service_apply');$this->assertNoApplication($f['supplier'],$entries);
  self::assertSame(0,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM unit_credit_lots WHERE supplier_id='.$f['supplier'].' AND settlement_effect_id IS NOT NULL')->fetchColumn());
  $current=(new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->currentMany($f['supplier'],[$debt])[0];self::assertSame('30000',array_sum($current['paid']).'');self::assertSame([],$current['reductions']);
 }
 public function testSameAllocationCannotCreateAnotherSettlementCredit():void {
  extract($this->shared('appliedFixture'));$count=(int)$this->db->pdo()->query('SELECT COUNT(*) FROM unit_credit_lots WHERE supplier_id='.$f['supplier'])->fetchColumn();
  try{$owner->apply($f['supplier'],$saved['id'],$request,0,$preview['preview_hash'],'second-apply',$f['actor']);self::fail('Second disposition accepted');}catch(DomainException $e){self::assertSame('settlement_version_conflict',$e->getMessage());}
  self::assertSame($count,(int)$this->db->pdo()->query('SELECT COUNT(*) FROM unit_credit_lots WHERE supplier_id='.$f['supplier'])->fetchColumn());
 }
 public function testGenericDetachAndReplaceCannotRewriteSettlement():void {
  extract($this->shared('appliedFixture'));$journal=Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);
  foreach(['detach','replace'] as $action){try{if($action==='detach')$journal->detachSource($result['journal_entry_id'],$f['supplier']);else $journal->replace($result['journal_entry_id'],$preview['header'],$preview['plan']['lines']);self::fail('Generic '.$action.' accepted');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('property_service_settlement_owner_required',$e->errorCode);}}
 }


 private function nativeFixture(string $actual):array {

  $f=$this->issuanceFixture($this->db);$this->f=$f;$repo=Bootstrap::buildContainer()->get(IssuanceRepository::class);
  $preview=(new IssuancePreviewService($this->db))->preview($f['supplier'],$f['issue_request']);
  $batch=$repo->issue($f['supplier'],['request'=>$f['issue_request'],'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'native-s8-issue'],$f['actor']);
  $expected=[];foreach($batch['charges'] as $charge)$expected[]=['id'=>$charge['id'],'row_version'=>$charge['row_version']];
  $repo->post($f['supplier'],$batch['id'],$expected,$f['actor'],\MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL);
  $q=$this->db->pdo()->prepare('SELECT l.id FROM unit_charge_lines l JOIN unit_charges c ON c.supplier_id=l.supplier_id AND c.id=l.charge_id WHERE l.supplier_id=? AND c.unit_id=? AND l.service_id=?');$q->execute([$f['supplier'],$f['units']['U02'],$f['service']['id']]);$id=(int)$q->fetchColumn();
  (new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->register($f['supplier'],['charge_id'=>(int)$this->db->pdo()->query('SELECT charge_id FROM unit_charge_lines WHERE id='.$id)->fetchColumn(),'expected_version'=>2,'principal_line_ids'=>[$id],'title_ref'=>'Synthetic service debt','due_date'=>'2026-01-15','legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-01','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic no security'],'damages_for'=>null],'s8-debt',$f['actor']);
  $result=Bootstrap::buildContainer()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[['kind'=>'s3_line','id'=>$id]]);

  $chart=new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);$parent=$chart->findByCode($f['supplier'],'315');$chart->insert($f['supplier'],['account_code'=>'315.210','name'=>'Synthetic service cost','account_type'=>'asset','normal_side'=>'debit','parent_id'=>$parent['id']]);
  $dims=$result['origins'][0]['advance_line']['dimensions'];$entry=Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($f['supplier'],'manual',null,[['account_code'=>'315.210','side'=>'debit','amount'=>bcdiv($actual,'100',2),'dimensions'=>$dims],['account_code'=>'321','side'=>'credit','amount'=>bcdiv($actual,'100',2),'dimensions'=>$dims]],['entry_date'=>'2026-12-31','posted_by'=>$f['actor']]);
  $costId=(int)$this->db->pdo()->query('SELECT MIN(id) FROM journal_entry_lines WHERE entry_id='.$entry)->fetchColumn();$selector=['kind'=>'s3_line','id'=>$id,'cost_line_id'=>$costId];
  $native=Bootstrap::buildContainer()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$selector]);
  $facts=['supplier_id'=>$f['supplier'],'unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'segment_key'=>'S3-SERVICE-2026','period_from'=>'2026-01-01','period_to'=>'2026-12-31','cutoff_at'=>'2026-12-31T23:59:59+01:00','known_at'=>'2027-01-01T12:00:00+01:00','recipient_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic native service ledger','prescribed_cents'=>'1200000','paid_cents'=>'0','actual_cents'=>$actual,'origins'=>$native['origins'],'cutoff_allocations'=>[],'cost'=>array_values($native['costs'])[0]];
  $doc=\MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts);$saved=Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class)->save($f['supplier'],$doc,[$selector],'2026-12-31',0,'s3-service-doc',$f['actor']);return compact('f','selector','saved','native','doc');

 }

 public function testNativeS3ApplicationReducesOriginalClaimAndReverses():void {
  extract($this->shared('nativeFixture','800000'));$source=$native['origins'][0];$owner=Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);
  $r=['accounting_date'=>'2026-12-31','evidence'=>'Synthetic native service disposition','advances'=>[['origin_key'=>'s3_line:'.$source['id'],'applied_cents'=>'800000']],'reliefs'=>[],'opening_credits'=>[],'new_claim'=>null];
  $preview=$owner->preview($f['supplier'],$saved['id'],$r,0);$result=$owner->apply($f['supplier'],$saved['id'],$r,0,$preview['preview_hash'],'s3-service-apply',$f['actor']);self::assertSame([],$result['credit_ids']);self::assertSame([],$result['debt_ids']);
  $debt=(new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->currentMany($f['supplier'],[$source['debt_id']])[0];self::assertSame('400000',$debt['reductions'][$source['id']]);self::assertSame([],$debt['paid']);
  $owner->reverse($f['supplier'],$result['id'],'2026-12-31',1,'Synthetic cancellation','s3-service-reverse',$f['actor']);$debt=(new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->currentMany($f['supplier'],[$source['debt_id']])[0];self::assertSame([],$debt['reductions']);
 }
 public function testNativeS3NewClaimHasIndependentOriginAndCanReverseUnused():void {
  extract($this->shared('nativeFixture','1400000'));$source=$native['origins'][0];$owner=Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);
  $r=['accounting_date'=>'2026-12-31','evidence'=>'Synthetic native service disposition','advances'=>[['origin_key'=>'s3_line:'.$source['id'],'applied_cents'=>'1200000']],'reliefs'=>[],'opening_credits'=>[],'new_claim'=>['party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic independent settlement claim','due_date'=>'2027-04-30','account_code'=>'315.210'],'claim_title'=>['effective_at'=>'2026-12-31T12:00:00+01:00','title_ref'=>'Synthetic service settlement title','liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic service liability','valid_from'=>'2026-12-31T12:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2026-12-31','evidence'=>'Synthetic service legal act','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic unsecured finding']]];
  $preview=$owner->preview($f['supplier'],$saved['id'],$r,0);$result=$owner->apply($f['supplier'],$saved['id'],$r,0,$preview['preview_hash'],'s3-new-claim-apply',$f['actor']);self::assertCount(1,$result['debt_ids']);
  $debt=(new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->currentMany($f['supplier'],$result['debt_ids'])[0];self::assertTrue($debt['active']);self::assertNull($debt['snapshot']['charge_id']);self::assertSame('200000',$debt['snapshot']['amount_cents']);self::assertSame([],$debt['paid']);
  $history=(new \MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository($this->db))->readForDebts($f['supplier'],$result['debt_ids']);self::assertSame('200000',$history['debts'][$debt['id']]['events'][0]['amount_cents']);
  $owner->reverse($f['supplier'],$result['id'],'2026-12-31',1,'Synthetic unused claim reversal','s3-new-claim-reverse',$f['actor']);self::assertFalse((new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->currentMany($f['supplier'],$result['debt_ids'])[0]['active']);
 }


 private function newClaimFixture():array {

  extract($this->nativeFixture('1400000'));$source=$native['origins'][0];$owner=Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);
  $r=['accounting_date'=>'2026-12-31','evidence'=>'Synthetic native service disposition','advances'=>[['origin_key'=>'s3_line:'.$source['id'],'applied_cents'=>'1200000']],'reliefs'=>[],'opening_credits'=>[],'new_claim'=>['party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic independent settlement claim','due_date'=>'2027-04-30','account_code'=>'315.210'],'claim_title'=>['effective_at'=>'2026-12-31T12:00:00+01:00','title_ref'=>'Synthetic service settlement title','liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic service liability','valid_from'=>'2026-12-31T12:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2026-12-31','evidence'=>'Synthetic service legal act','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic unsecured finding']]];
  $preview=$owner->preview($f['supplier'],$saved['id'],$r,0);$result=$owner->apply($f['supplier'],$saved['id'],$r,0,$preview['preview_hash'],'s3-new-claim-apply',$f['actor']);self::assertCount(1,$result['debt_ids']);
  return compact('f','source','owner','r','preview','result');
 }

 private function bankOn(string $amount,string $date):int {
  $pdo=$this->db->pdo();$pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic',?,'1000000005','0100','CZK',?)")->execute([$this->f['supplier'],hash('sha256',random_bytes(20)),$date]);$statement=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank) VALUES (?,?,?,'CZK','1000000005','0100')")->execute([$statement,$date,$amount]);$id=(int)$pdo->lastInsertId();
  $this->paymentFixtureContainer()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->f['supplier'],'bank',$id,[['account_code'=>'221','side'=>str_starts_with($amount,'-')?'credit':'debit','amount'=>ltrim($amount,'-')],['account_code'=>'379.900','side'=>str_starts_with($amount,'-')?'debit':'credit','amount'=>ltrim($amount,'-')]],['entry_date'=>$date,'posted_by'=>$this->f['actor']]);return $id;
 }
 public function testPaidNewClaimBlocksSettlementReverseAndItsPaymentReversesNormally():void {
  extract($this->shared('newClaimFixture'));$payments=$this->correctionPayments();$bank=$this->bankOn('1000.00','2026-12-31');
  $payment=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'100000','accounting_date'=>'2026-12-31','reason'=>'Synthetic settlement claim payment','debts'=>$result['debt_ids'],'payment'=>['payer_id'=>$f['clients'][1],'debtor_id'=>$f['clients'][1],'received_at'=>'2026-12-31T13:00:00+01:00','choice'=>['claim_ids'=>$result['debt_ids'],'evidence'=>'Synthetic debtor disposition']]];
  $op=$payments->apply($f['supplier'],$payment,$payments->preview($f['supplier'],$payment)['preview_hash'],'newclaim-payment',$f['actor']);$current=(new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->currentMany($f['supplier'],$result['debt_ids'])[0];self::assertSame('100000',array_sum($current['paid']).'');
  try{$owner->reverse($f['supplier'],$result['id'],'2026-12-31',1,'Synthetic forbidden paid claim reversal','newclaim-blocked-reverse',$f['actor']);self::fail('Paid claim reversed');}catch(DomainException $e){self::assertSame('settlement_claim_in_use',$e->getMessage());}
  $payments->reverse($f['supplier'],$op['id'],$op['row_version'],'2026-12-31','Synthetic payment cancellation','newclaim-payment-reverse',$f['actor']);$owner->reverse($f['supplier'],$result['id'],'2026-12-31',1,'Synthetic no downstream claim use','newclaim-unused-reverse',$f['actor']);
 }
 public function testReservedServiceCreditBlocksReverseUntilCancelled():void {
  extract($this->shared('appliedFixture'));$credit=$payments->credit($f['supplier'],$result['credit_ids'][0]);
  \MyInvoice\Tests\Support\RefundAccountFixture::document($this->db->pdo(),$f['supplier'],$f['clients'][1]);$reservation=$payments->reserve($f['supplier'],$credit['id'],$credit['row_version'],['amount_cents'=>'10000','party_ids'=>[$f['clients'][1]],'disposition'=>'Synthetic bank refund instruction','recipient_account'=>'1000000005/0100'],'service-credit-reserve',$f['actor']);
  try{$owner->reverse($f['supplier'],$result['id'],'2026-12-31',1,'Synthetic forbidden reserved refund reversal','reserved-service-reverse',$f['actor']);self::fail('Reserved credit origin reversed');}catch(DomainException $e){self::assertSame('settlement_credit_in_use',$e->getMessage());}
  $payments->cancelReservation($f['supplier'],$reservation['id'],$reservation['row_version'],'Synthetic cancelled transfer','service-credit-cancel',$f['actor']);$owner->reverse($f['supplier'],$result['id'],'2026-12-31',1,'Synthetic available credit cancellation','available-service-reverse',$f['actor']);
  self::assertSame('0',$payments->credit($f['supplier'],$credit['id'])['available_cents']);
 }


 public function testChangedManualCostAfterDocumentCannotBecomeCurrentAuthority():void {
  extract($this->shared('nativeFixture','800000'));$source=$native['origins'][0];$owner=Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);$cost=$native['costs']['s3_line:'.$source['id']]['source_id'];$entry=(int)$this->db->pdo()->query('SELECT entry_id FROM journal_entry_lines WHERE id='.$cost)->fetchColumn();
  $this->db->pdo()->prepare('UPDATE journal_entry_lines SET amount=? WHERE supplier_id=? AND entry_id=?')->execute(['9000.00',$f['supplier'],$entry]);
  $r=['accounting_date'=>'2026-12-31','evidence'=>'Synthetic changed cost attempt','advances'=>[['origin_key'=>'s3_line:'.$source['id'],'applied_cents'=>'800000']],'reliefs'=>[],'opening_credits'=>[],'new_claim'=>null];
  $entries=(int)$this->db->pdo()->query('SELECT COUNT(*) FROM journal_entries WHERE supplier_id='.$f['supplier'])->fetchColumn();try{$owner->preview($f['supplier'],$saved['id'],$r,0);self::fail('Changed cost became current authority');}catch(DomainException $e){self::assertSame('settlement_document_cost_changed',$e->getMessage());}$this->assertNoApplication($f['supplier'],$entries);
 }
 public function testUsedManualCostDescriptionCannotChange():void {
  extract($this->shared('newClaimFixture'));$cost=$preview['plan']['document']['cost']['source_id'];$entry=(int)$this->db->pdo()->query('SELECT entry_id FROM journal_entry_lines WHERE id='.$cost)->fetchColumn();$journal=Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);
  try{$journal->updateDescription($entry,$f['supplier'],'Synthetic replacement description',$f['actor']);self::fail('Used manual cost description changed');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('property_service_settlement_owner_required',$e->errorCode);}
 }
 public function testUsedS3IssuanceCannotReverse():void {
  extract($this->shared('newClaimFixture'));$charge=(int)$this->db->pdo()->query('SELECT charge_id FROM unit_charge_lines WHERE id='.$source['id'])->fetchColumn();
  try{Bootstrap::buildContainer()->get(\MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository::class)->reverse($f['supplier'],$charge,['row_version'=>2,'accounting_date'=>'2026-12-31','effective_at'=>'2026-12-31T20:00:00+01:00','reason'=>'Synthetic bypass attempt','evidence_ref'=>'Synthetic reversal evidence','idempotency_key'=>'used-s3-reverse'],$f['actor']);self::fail('Used S3 charge reversed');}catch(\MyInvoice\Service\Property\Charges\Issuance\IssuanceException $e){self::assertSame('property_payment_claim_has_service_settlement',$e->errorCode);}
 }


 public function testServiceCreditCannotFundRefundBeforeApplication():void {
  extract($this->shared('appliedFixture'));$credit=$payments->credit($f['supplier'],$result['credit_ids'][0]);\MyInvoice\Tests\Support\RefundAccountFixture::document($this->db->pdo(),$f['supplier'],$f['clients'][1]);$reservation=$payments->reserve($f['supplier'],$credit['id'],$credit['row_version'],['amount_cents'=>'10000','party_ids'=>[$f['clients'][1]],'disposition'=>'Synthetic bank refund instruction','recipient_account'=>'1000000005/0100'],'service-credit-old-reserve',$f['actor']);$bank=$this->bankOn('-100.00','2026-02-01');
  $refund=['kind'=>'refund','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'10000','accounting_date'=>'2026-02-01','reason'=>'Synthetic backdated refund','reservation_id'=>$reservation['id'],'expected_reservation_version'=>$reservation['row_version']];
  try{$payments->preview($f['supplier'],$refund);self::fail('Service credit funded a backdated refund');}catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertSame('property_payment_backdated_payment',$e->errorCode);}
 }
 public function testPostedServiceCreditRefundBlocksReverseAndCannotBePaidTwice():void {
  extract($this->shared('appliedFixture'));$credit=$payments->credit($f['supplier'],$result['credit_ids'][0]);\MyInvoice\Tests\Support\RefundAccountFixture::document($this->db->pdo(),$f['supplier'],$f['clients'][1]);$reservation=$payments->reserve($f['supplier'],$credit['id'],$credit['row_version'],['amount_cents'=>'10000','party_ids'=>[$f['clients'][1]],'disposition'=>'Synthetic bank refund instruction','recipient_account'=>'1000000005/0100'],'service-credit-refund-reserve',$f['actor']);$bank=$this->bankOn('-100.00','2026-12-31');
  $refund=['kind'=>'refund','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'10000','accounting_date'=>'2026-12-31','reason'=>'Synthetic service credit refund','reservation_id'=>$reservation['id'],'expected_reservation_version'=>$reservation['row_version']];$preview=$payments->preview($f['supplier'],$refund);$op=$payments->apply($f['supplier'],$refund,$preview['preview_hash'],'service-credit-refund',$f['actor']);self::assertSame('0',$payments->credit($f['supplier'],$credit['id'])['balance_cents']);
  try{$owner->reverse($f['supplier'],$result['id'],'2026-12-31',1,'Synthetic attempted paid credit reversal','paid-credit-reverse',$f['actor']);self::fail('Paid credit reversed');}catch(DomainException $e){self::assertSame('settlement_credit_in_use',$e->getMessage());}
  try{$payments->apply($f['supplier'],$refund,$preview['preview_hash'],'service-credit-double-refund',$f['actor']);self::fail('Credit refunded twice');}catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertContains($e->errorCode,['property_payment_source_version_conflict','property_payment_reservation_version_conflict','property_payment_reservation_capacity']);}
  $payments->reverse($f['supplier'],$op['id'],$op['row_version'],'2026-12-31','Synthetic refund cancellation','service-credit-refund-reverse',$f['actor']);self::assertSame('10000',$payments->credit($f['supplier'],$credit['id'])['balance_cents']);
 }


 public function testCostStorageAnchorCannotCrossTenant():void {
  extract($this->shared('appliedFixture',false));$other=$this->chargeFixture($this->db);$q=$this->db->pdo()->prepare('SELECT * FROM property_service_settlements WHERE supplier_id=? AND id=?');$q->execute([$f['supplier'],$saved['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);unset($row['id']);$row['supplier_id']=$other['supplier'];$row['unit_id']=$other['units']['U02'];$row['service_id']=$other['service']['id'];$row['created_by']=$other['actor'];$row['segment_key']='FOREIGN-COST';$row['idempotency_key']='foreign-cost-storage';
  try{$this->db->pdo()->prepare('INSERT INTO property_service_settlements ('.implode(',',array_keys($row)).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));self::fail('Foreign tenant cost storage anchor accepted');}catch(PDOException $e){self::assertSame('23000',$e->getCode());}
 }

}
