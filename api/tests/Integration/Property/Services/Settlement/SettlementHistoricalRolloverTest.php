<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Services\Settlement;
use PDO;
use DomainException;
use LogicException;
use PHPUnit\Framework\TestCase;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Services\Settlement\SettlementOriginAdapter;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Tests\Support\{ChargeIssuanceFixture,PaymentCorrectionFixture,NonprofitTaxAssessmentFixture};
final class SettlementHistoricalRolloverTest extends TestCase {
 use ChargeIssuanceFixture,PaymentCorrectionFixture;
 private Connection $db;
 private array $f;
 private \DI\Container $container;
 private ClosingService $closing;
 protected function setUp():void {
  $this->container=Bootstrap::buildContainer();$this->db=$this->container->get(Connection::class);self::assertStringEndsWith('_test',(string)$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());$this->db->pdo()->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");$this->db->pdo()->beginTransaction();$this->closing=$this->container->get(ClosingService::class);
 }
 protected function tearDown():void {if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
 private function container():\DI\Container {$container=Bootstrap::buildContainer();$container->set(Connection::class,$this->db);return $container;}
 private function retire(array $f,bool $activity=false):void {
  $q=$this->db->pdo()->prepare('SELECT v.id,t.system_key FROM dimension_values v JOIN dimension_types t ON t.id=v.type_id WHERE v.supplier_id=? AND (v.id IN (SELECT dimension_value_id FROM property_objects WHERE supplier_id=? AND id IN (?,?)) OR (?=1 AND t.system_key=\'activity\'))');$q->execute([$f['supplier'],$f['supplier'],$f['units']['U02'],$f['service']['id'],$activity?1:0]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);self::assertContains('unit',array_column($rows,'system_key'));self::assertContains('service',array_column($rows,'system_key'));if($activity)self::assertContains('activity',array_column($rows,'system_key'));foreach($rows as $r)$this->db->pdo()->prepare('UPDATE dimension_values SET is_active=0 WHERE supplier_id=? AND id=?')->execute([$f['supplier'],$r['id']]);
 }
 private function financialState(int $supplier,int $debt,string $claim,string $paid):void {
  $current=(new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->currentMany($supplier,[$debt])[0];self::assertSame($paid,array_sum($current['paid']).'');$history=(new \MyInvoice\Repository\Property\Receivables\FinancialHistoryRepository($this->db))->readForDebts($supplier,[$debt]);$sums=['claim'=>'0','paid'=>'0'];foreach($history['debts'][$debt]['events'] as $event)if(isset($sums[$event['kind']]))$sums[$event['kind']]=bcadd($sums[$event['kind']],$event['amount_cents'],0);self::assertSame($claim,$sums['claim']);self::assertSame($paid,$sums['paid']);
 }
 private function rv(int $period):int {$q=$this->db->pdo()->prepare('SELECT row_version FROM accounting_periods WHERE supplier_id=? AND id=?');$q->execute([$this->f['supplier'],$period]);return(int)$q->fetchColumn();}
 public function testS6SettlementPreservesActuallyRetiredUnitAndServiceThroughTypedReversal():void {
  extract($this->appliedFixture(false));$this->retire($f);try{(new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->assertJournal($preview['header'],$preview['plan']['lines']);self::fail('Ordinary journal accepted inactive property');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('property_dimension_unavailable',$e->errorCode);}
  $fresh=$owner->preview($f['supplier'],$saved['id'],$request,0);$done=$owner->apply($f['supplier'],$saved['id'],$request,0,$fresh['preview_hash'],'retired-s6',$f['actor']);self::assertSame('10000',$payments->credit($f['supplier'],$done['credit_ids'][0])['balance_cents']);$this->financialState($f['supplier'],$debt,'20000','20000');$reverse=$owner->reverse($f['supplier'],$done['id'],'2026-12-31',1,'Synthetic inactive reverse','retired-s6-reverse',$f['actor']);self::assertFalse((new \MyInvoice\Service\Property\Services\Settlement\SettlementHistory($this->db))->application($f['supplier'],$done['id'],true)['active']);$this->financialState($f['supplier'],$debt,'40000','30000');self::assertSame($reverse,$owner->reverse($f['supplier'],$done['id'],'2026-12-31',1,'Synthetic inactive reverse','retired-s6-reverse',$f['actor']));
 }
 public function testS3CurrentCostRetainsActuallyInactiveActivityAlongsidePropertyDimensions():void {
  extract($this->newClaimFixture(false));$q=$this->db->pdo()->prepare("UPDATE dimension_values v JOIN dimension_types t ON t.id=v.type_id SET v.is_active=0 WHERE v.supplier_id=? AND t.system_key='activity'");$q->execute([$f['supplier']]);self::assertGreaterThan(0,$q->rowCount());try{(new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->assertJournal($preview['header'],$preview['plan']['lines']);self::fail('Ordinary journal accepted inactive activity');}catch(\MyInvoice\Service\Accounting\PostingException $e){self::assertSame('dimension_closed',$e->errorCode);}$fresh=$owner->preview($f['supplier'],$saved['id'],$r,0);$done=$owner->apply($f['supplier'],$saved['id'],$r,0,$fresh['preview_hash'],'retired-activity',$f['actor']);self::assertCount(1,$done['debt_ids']);$this->financialState($f['supplier'],$done['debt_ids'][0],'200000','0');$owner->reverse($f['supplier'],$done['id'],'2026-12-31',1,'Synthetic activity reverse','retired-activity-reverse',$f['actor']);self::assertFalse((new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->currentMany($f['supplier'],$done['debt_ids'])[0]['active']);
 }
 public function testNativeS6CloseOpenS8ApplyCloseOpenAndTypedReverseKeepExactCurrentHistory():void {
  extract($this->appliedFixture(false));$this->f=$f;$proof=new \MyInvoice\Service\Property\Payments\PaymentJournalProof($this->db);$source=$proof->current($f['supplier'],$f['opening']['journal_entry_id']);$this->retire($f);
  $q=$this->db->pdo()->prepare('SELECT id FROM accounting_periods WHERE supplier_id=? AND fiscal_year=?');$q->execute([$f['supplier'],2026]);$year=(int)$q->fetchColumn();$closed=$this->close($year);self::assertNotNull($closed['entry_id']);$opened=$this->closing->openNext($f['supplier'],$year,$this->rv($year));self::assertNotNull($opened['entry_id']);$q->execute([$f['supplier'],2027]);$next=(int)$q->fetchColumn();$request['accounting_date']='2027-02-01';$fresh=$owner->preview($f['supplier'],$saved['id'],$request,0);$done=$owner->apply($f['supplier'],$saved['id'],$request,0,$fresh['preview_hash'],'next-year-s8',$f['actor']);$this->financialState($f['supplier'],$debt,'20000','20000');self::assertSame('10000',$payments->credit($f['supplier'],$done['credit_ids'][0])['balance_cents']);
  $closedNext=$this->close($next);self::assertNotNull($closedNext['entry_id']);$openedNext=$this->closing->openNext($f['supplier'],$next,$this->rv($next));self::assertNotNull($openedNext['entry_id']);$q->execute([$f['supplier'],2028]);$last=(int)$q->fetchColumn();$this->financialState($f['supplier'],$debt,'20000','20000');$reverse=$owner->reverse($f['supplier'],$done['id'],'2028-02-01',1,'Synthetic next year reversal','next-year-s8-reverse',$f['actor']);$this->financialState($f['supplier'],$debt,'40000','30000');self::assertSame('0',$payments->credit($f['supplier'],$done['credit_ids'][0])['balance_cents']);self::assertSame('2028-02-01',$reverse['accounting_date']);self::assertSame($source,$proof->current($f['supplier'],$f['opening']['journal_entry_id']));
  $q=$this->db->pdo()->prepare('SELECT a.account_code,SUM(CASE l.side WHEN \'debit\' THEN l.amount ELSE -l.amount END) balance FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.supplier_id=? AND e.period_id=? GROUP BY a.account_code');$q->execute([$f['supplier'],$last]);$balances=array_column($q->fetchAll(PDO::FETCH_ASSOC),'balance','account_code');foreach(['315.100'=>'100.00','315.210'=>'800.00','324.110'=>'-1000.00','379.910'=>'0.00'] as $account=>$balance)self::assertSame($balance,$balances[$account],$account);
 }
 public function testCarrierBindsEntireTargetSourceAndTransactionEpoch():void {
  extract($this->appliedFixture(false));$carrier=\MyInvoice\Service\Property\Services\Settlement\SettlementHistoricalCarrierProof::fromCurrent($this->db,$owner,$f['supplier'],$saved['id'],$request,0);$journal=$carrier->journal($this->db);$this->retire($f);$policy=new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db);$policy->assertPropertyServiceSettlementJournal($journal['header'],$journal['lines'],$carrier);
  foreach(['amount','dimensions','provenance','legacy','account','tenant','date','description','extra'] as $change){$header=$journal['header'];$lines=$journal['lines'];if($change==='amount')$lines[0]['amount']='0.01';if($change==='dimensions')$lines[0]['dimensions']=[];if($change==='provenance')$lines[0]['dimension_origins']=['synthetic'=>['origin'=>'explicit','ref'=>'Synthetic changed provenance']];if($change==='legacy')$lines[0]['cost_center']='Synthetic arbitrary context';if($change==='account')$lines[0]['account_id']++;if($change==='tenant')$header['supplier_id']++;if($change==='date')$header['entry_date']='2026-12-30';if($change==='description')$header['description']='Synthetic unrelated purpose';if($change==='extra')$lines[0]['document_assignment_owners']=['synthetic'=>1];try{$carrier->indices($this->db,$header,$lines);self::fail('Changed target accepted: '.$change);}catch(DomainException $e){self::assertSame('settlement_carrier_target_mismatch',$e->getMessage(),$change);}}
  $this->db->pdo()->exec('SAVEPOINT before_carrier_epoch');$later=\MyInvoice\Service\Property\Services\Settlement\SettlementHistoricalCarrierProof::fromCurrent($this->db,$owner,$f['supplier'],$saved['id'],$request,0);$this->db->pdo()->exec('ROLLBACK TO SAVEPOINT before_carrier_epoch');try{$later->indices($this->db,$journal['header'],$journal['lines']);self::fail('Rolled back carrier accepted');}catch(LogicException $e){self::assertStringContainsString('epoch expired',$e->getMessage());}
  $this->db->pdo()->prepare('UPDATE bank_transactions SET amount=? WHERE id=?')->execute(['301.00',$bank]);try{$carrier->indices($this->db,$journal['header'],$journal['lines']);self::fail('Changed native source accepted');}catch(\MyInvoice\Service\Property\Payments\PaymentException $e){self::assertContains($e->errorCode,['property_payment_source_changed','property_payment_source_clearing_mismatch']);}
 }

    private function close(int $period):array
    {
        $supplier=$this->f['supplier'];$meta=['user_id'=>$this->f['actor'],'posted_by'=>$this->f['actor']];
        $this->closing->start($supplier,$period,$this->rv($period),$meta);$items=[];
        foreach($this->closing->inventoryPreview($supplier,$period)['rows']as $r)$items[(int)$r['account_id']]=['counted_balance'=>(float)$r['book_balance'],'resolution'=>'resolved','note'=>null];
        $this->closing->saveInventory($supplier,$period,$this->rv($period),['complete'=>true],$items,$meta);
        $this->closing->runPrecheck($supplier,$period,$this->rv($period),$meta);
        $this->closing->confirmStep($supplier,$period,'depreciation','skipped',null,$this->rv($period),$meta);
        $end=$this->db->pdo()->query('SELECT ends_on FROM accounting_periods WHERE id='.$period)->fetchColumn();
        $this->db->pdo()->prepare("INSERT INTO exchange_rates(rate_date,currency_code,rate) VALUES (?,'EUR',25) ON DUPLICATE KEY UPDATE rate=25")->execute([$end]);
        $initial=$this->closing->fxPreview($supplier,$period);
        $accounts=array_filter($initial['positions'],static fn(array $p):bool=>$p['account_code']==='324')?['324'=>['classification'=>'nonmonetary','reason'=>'Synthetic documented advance']]:[];
        $fx=$this->closing->fxPreview($supplier,$period,[],$accounts);
        $this->closing->runFxRevaluation($supplier,$period,[],$this->rv($period),$meta,['snapshot_hash'=>$fx['snapshot_hash'],'account_decisions'=>$accounts]);
        foreach(['estimates','deferrals','provisions','income_tax']as $step)$this->closing->confirmStep($supplier,$period,$step,'skipped',null,$this->rv($period),$meta);
        $this->closing->runStockValuation($supplier,$period,$this->rv($period),$meta);
        NonprofitTaxAssessmentFixture::confirm($this->container,$supplier,$period,$this->f['actor']);
        return $this->closing->closeBooks($supplier,$period,$this->rv($period),$meta);
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
  $repo=$this->container()->get(\MyInvoice\Repository\Property\Opening\OpeningRepository::class);$preview=$repo->preview($supplier,$request);$result=$repo->apply($supplier,$request,$preview['preview_hash'],'native-s8-opening',$f['actor']);
  $q=$this->db->pdo()->prepare('SELECT id,kind FROM property_opening_items WHERE supplier_id=? AND batch_id=?');$q->execute([$supplier,$result['id']]);$items=array_column($q->fetchAll(PDO::FETCH_ASSOC),'id','kind');
  return $f+['selector'=>['kind'=>'opening','advance_item_id'=>(int)$items['advance'],'service_item_id'=>(int)$items['service_carry']],'opening'=>$result];
 }
 private function openingDocument(array $f):array {
  $current=$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$f['selector']]);
  $facts=['supplier_id'=>$f['supplier'],'unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'segment_key'=>'SYNTHETIC-2026','period_from'=>'2026-01-01','period_to'=>'2026-12-31','cutoff_at'=>'2026-01-31T23:59:59+01:00','known_at'=>'2026-10-17T12:00:00+02:00','recipient_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic service cost and cutoff dossier','prescribed_cents'=>'100000','paid_cents'=>'60000','actual_cents'=>'80000','origins'=>$current['origins'],'cutoff_allocations'=>[],'cost'=>array_values($current['costs'])[0]];
  return ['document'=>\MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts),'current'=>$current];
 }

 private function appliedFixture(bool $apply=true):array {
  $f=$this->openingFixture();$document=$this->openingDocument($f)['document'];
  $docRepo=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class);
  $saved=$docRepo->save($f['supplier'],$document,[$f['selector']],'2026-12-31',0,'s8-owner-document',$f['actor']);
  $source=$document->snapshot()['origins'][0];$debt=$source['debt_id'];$bank=$this->correctionBank('300.00');
  $r=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'30000','accounting_date'=>'2026-02-01','reason'=>'Synthetic late payment','debts'=>[$debt],'payment'=>['payer_id'=>$f['clients'][1],'debtor_id'=>$f['clients'][1],'received_at'=>'2026-02-01T10:00:00+01:00','choice'=>['claim_ids'=>[$debt],'evidence'=>'Synthetic debtor disposition']]];
  $payments=$this->correctionPayments();$op=$payments->apply($f['supplier'],$r,$payments->preview($f['supplier'],$r)['preview_hash'],'s8-owner-late',$f['actor']);
  $allocation=(int)$this->db->pdo()->query('SELECT id FROM unit_payment_allocations WHERE operation_id='.$op['id'])->fetchColumn();
  $owner=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);
  $request=['accounting_date'=>'2026-12-31','evidence'=>'Synthetic final service disposition','advances'=>[['origin_key'=>$source['kind'].':'.$source['id'],'applied_cents'=>'80000']],'reliefs'=>[['allocation_id'=>$allocation,'amount_cents'=>'10000','entitlement'=>['party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic documented creditor','due_date'=>'2027-04-30','account_code'=>'379.910']]],'opening_credits'=>[],'new_claim'=>null];
  $preview=$owner->preview($f['supplier'],$saved['id'],$request,0);

  $result=$apply?$owner->apply($f['supplier'],$saved['id'],$request,0,$preview['preview_hash'],'s8-apply',$f['actor']):null;

 return compact('f','document','docRepo','saved','source','debt','bank','r','payments','op','allocation','owner','request','preview','result');
 }
 private function nativeFixture(string $actual):array {

  $f=$this->issuanceFixture($this->db);$this->f=$f;$repo=$this->container()->get(IssuanceRepository::class);
  $preview=(new IssuancePreviewService($this->db))->preview($f['supplier'],$f['issue_request']);
  $batch=$repo->issue($f['supplier'],['request'=>$f['issue_request'],'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'native-s8-issue'],$f['actor']);
  $expected=[];foreach($batch['charges'] as $charge)$expected[]=['id'=>$charge['id'],'row_version'=>$charge['row_version']];
  $repo->post($f['supplier'],$batch['id'],$expected,$f['actor'],\MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL);
  $q=$this->db->pdo()->prepare('SELECT l.id FROM unit_charge_lines l JOIN unit_charges c ON c.supplier_id=l.supplier_id AND c.id=l.charge_id WHERE l.supplier_id=? AND c.unit_id=? AND l.service_id=?');$q->execute([$f['supplier'],$f['units']['U02'],$f['service']['id']]);$id=(int)$q->fetchColumn();
  (new \MyInvoice\Repository\Property\Payments\DebtRepository($this->db))->register($f['supplier'],['charge_id'=>(int)$this->db->pdo()->query('SELECT charge_id FROM unit_charge_lines WHERE id='.$id)->fetchColumn(),'expected_version'=>2,'principal_line_ids'=>[$id],'title_ref'=>'Synthetic service debt','due_date'=>'2026-01-15','legal'=>['basis'=>'legal_act','legal_act_date'=>'2025-12-01','evidence'=>'Synthetic decision','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic no security'],'damages_for'=>null],'s8-debt',$f['actor']);
  $result=$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[['kind'=>'s3_line','id'=>$id]]);

  $chart=new \MyInvoice\Repository\ChartOfAccountsRepository($this->db);$parent=$chart->findByCode($f['supplier'],'315');$chart->insert($f['supplier'],['account_code'=>'315.210','name'=>'Synthetic service cost','account_type'=>'asset','normal_side'=>'debit','parent_id'=>$parent['id']]);
  $dims=$result['origins'][0]['advance_line']['dimensions'];$cat=(new \MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog($this->db))->ensureForFramework($f['supplier'],'2026-12-31');$dims[$cat['activity']['id']]=$cat['activity']['values']['main'];$entry=$this->container()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($f['supplier'],'manual',null,[['account_code'=>'315.210','side'=>'debit','amount'=>bcdiv($actual,'100',2),'dimensions'=>$dims],['account_code'=>'321','side'=>'credit','amount'=>bcdiv($actual,'100',2),'dimensions'=>$dims]],['entry_date'=>'2026-12-31','posted_by'=>$f['actor']]);
  $costId=(int)$this->db->pdo()->query('SELECT MIN(id) FROM journal_entry_lines WHERE entry_id='.$entry)->fetchColumn();$selector=['kind'=>'s3_line','id'=>$id,'cost_line_id'=>$costId];
  $native=$this->container()->get(SettlementOriginAdapter::class)->current($f['supplier'],$f['units']['U02'],$f['service']['id'],'2026-12-31',[$selector]);
  $facts=['supplier_id'=>$f['supplier'],'unit_id'=>$f['units']['U02'],'service_id'=>$f['service']['id'],'segment_key'=>'S3-SERVICE-2026','period_from'=>'2026-01-01','period_to'=>'2026-12-31','cutoff_at'=>'2026-12-31T23:59:59+01:00','known_at'=>'2027-01-01T12:00:00+01:00','recipient_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic native service ledger','prescribed_cents'=>'1200000','paid_cents'=>'0','actual_cents'=>$actual,'origins'=>$native['origins'],'cutoff_allocations'=>[],'cost'=>array_values($native['costs'])[0]];
  $doc=\MyInvoice\Service\Property\Services\Settlement\SettlementDocument::fromEvidence($facts);$saved=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementDocumentRepository::class)->save($f['supplier'],$doc,[$selector],'2026-12-31',0,'s3-service-doc',$f['actor']);return compact('f','selector','saved','native','doc');

 }

 private function newClaimFixture(bool $apply=true):array {

  extract($this->nativeFixture('1400000'));$source=$native['origins'][0];$owner=$this->container()->get(\MyInvoice\Repository\Property\Services\Settlement\SettlementApplicationRepository::class);
  $r=['accounting_date'=>'2026-12-31','evidence'=>'Synthetic native service disposition','advances'=>[['origin_key'=>'s3_line:'.$source['id'],'applied_cents'=>'1200000']],'reliefs'=>[],'opening_credits'=>[],'new_claim'=>['party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic independent settlement claim','due_date'=>'2027-04-30','account_code'=>'315.210'],'claim_title'=>['effective_at'=>'2026-12-31T12:00:00+01:00','title_ref'=>'Synthetic service settlement title','liability_groups'=>[['group_key'=>'A','role'=>'debtor','mode'=>'individual','basis'=>'documented_title','members'=>[$f['clients'][1]],'extent'=>['num'=>'1','den'=>'1'],'evidence_ref'=>'Synthetic title','legal_reason'=>'Synthetic service liability','valid_from'=>'2026-12-31T12:00:00+01:00','valid_to'=>null]],'legal'=>['basis'=>'legal_act','legal_act_date'=>'2026-12-31','evidence'=>'Synthetic service legal act','regime'=>'general'],'security'=>['kind'=>'unsecured','evidence'=>'Synthetic unsecured finding']]];
  $preview=$owner->preview($f['supplier'],$saved['id'],$r,0);$result=$apply?$owner->apply($f['supplier'],$saved['id'],$r,0,$preview['preview_hash'],'s3-new-claim-apply',$f['actor']):null;
  return compact('f','saved','source','owner','r','preview','result');
 }

 private function bankOn(string $amount,string $date):int {
  $pdo=$this->db->pdo();$pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic',?,'1000000005','0100','CZK',?)")->execute([$this->f['supplier'],hash('sha256',random_bytes(20)),$date]);$statement=(int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank) VALUES (?,?,?,'CZK','1000000005','0100')")->execute([$statement,$date,$amount]);$id=(int)$pdo->lastInsertId();
  $this->paymentFixtureContainer()->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($this->f['supplier'],'bank',$id,[['account_code'=>'221','side'=>str_starts_with($amount,'-')?'credit':'debit','amount'=>ltrim($amount,'-')],['account_code'=>'379.900','side'=>str_starts_with($amount,'-')?'debit':'credit','amount'=>ltrim($amount,'-')]],['entry_date'=>$date,'posted_by'=>$this->f['actor']]);return $id;
 }
}
