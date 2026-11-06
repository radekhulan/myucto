<?php
declare(strict_types=1);
namespace MyInvoice\Repository\Property\Services\Settlement;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\{JournalLineAmount,PostingService};
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\{PropertyInput,PropertyCatalog};
use MyInvoice\Service\Property\Payments\{PaymentJournalProof,PaymentLocks,PaymentPostingDate};
use MyInvoice\Service\Property\Opening\OpeningReadProof;
use MyInvoice\Service\Property\Services\Settlement\{SettlementEvidence as E,ServiceLifecycleProof};
use PDO;
final class ServiceLifecycleRepository
{
 public function __construct(private readonly Connection $db){}
 private function tx(callable $work):mixed {return(new DimensionPeriodGuard($this->db))->transaction($work);}
 private function q(string $sql,array $args=[]):\PDOStatement {$q=$this->db->pdo()->prepare($sql);$q->execute($args);return $q;}
 private function one(string $sql,array $args=[]):array {$r=$this->q($sql,$args)->fetch(PDO::FETCH_ASSOC);if(!$r)E::reject('lifecycle_not_found');return $r;}
 private static function json(array $a):string {return json_encode($a,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
 private static function decode(string $a):array {return json_decode($a,true,flags:JSON_THROW_ON_ERROR);}
 private function context(int $supplier):void {if(!$this->db->pdo()->inTransaction())throw new \LogicException('Lifecycle requires owner transaction.');(new PaymentLocks($this->db))->acquire($supplier);}
 public function openCase(int $supplier,int $unit,int $service,string $from,string $to,string $policy,string $evidence,int $actor,string $serviceCostAccount):int
 {return $this->tx(function()use($supplier,$unit,$service,$from,$to,$policy,$evidence,$actor,$serviceCostAccount):int{
  $this->context($supplier);foreach([$supplier,$unit,$service,$actor] as $id)PropertyInput::id($id,'scope');E::date($from);E::date($to);if($from>$to||!in_array($policy,['service_balance_sheet','service_profit_and_loss'],true))E::reject('lifecycle_policy');PropertyInput::text($evidence,'policy_evidence',4000);
  foreach([[$unit,'unit'],[$service,'service']] as [$id,$kind])$this->one('SELECT id FROM property_objects WHERE supplier_id=? AND id=? AND kind=? FOR UPDATE',[$supplier,$id,$kind]);
  $existing=$this->q('SELECT * FROM property_service_lifecycle_cases WHERE supplier_id=? AND unit_id=? AND service_id=? AND period_from<=? AND period_to>=? FOR UPDATE',[$supplier,$unit,$service,$to,$from])->fetchAll(PDO::FETCH_ASSOC);if($existing!==[])E::reject('lifecycle_case_overlap');
  (new ServiceLifecyclePolicyRepository($this->db))->assertForCase($supplier,$service,$from,$to,$policy,$evidence,$actor);
  $account=$this->one('SELECT account_type,is_active FROM chart_of_accounts WHERE supplier_id=? AND account_code=? FOR UPDATE',[$supplier,$serviceCostAccount]);if(!preg_match(\MyInvoice\Service\Property\Opening\OpeningRolePolicy::ROLES['service_cost'][2],$serviceCostAccount)||$account['account_type']!=='asset'||!$account['is_active'])E::reject('lifecycle_cost_account');$facts=['service_cost_account'=>$serviceCostAccount,'supplier_id'=>$supplier,'unit_id'=>$unit,'service_id'=>$service,'period_from'=>$from,'period_to'=>$to,'policy'=>$policy,'evidence'=>$evidence,'actor'=>$actor];
  $this->q('INSERT INTO property_service_lifecycle_cases(supplier_id,unit_id,service_id,period_from,period_to,policy,facts_json,facts_hash,created_by,recorded_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$supplier,$unit,$service,$from,$to,$policy,self::json($facts),PropertyInput::hash($facts),$actor]);return(int)$this->db->pdo()->lastInsertId();
 });}
 private function case(int $supplier,int $id):array
 {$r=$this->one('SELECT * FROM property_service_lifecycle_cases WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);$f=self::decode($r['facts_json']);if(PropertyInput::hash($f)!==$r['facts_hash']||$f['supplier_id']!==$supplier||$f['unit_id']!==(int)$r['unit_id']||$f['service_id']!==(int)$r['service_id']||$f['period_from']!==$r['period_from']||$f['period_to']!==$r['period_to']||$f['policy']!==$r['policy']||$f['actor']!==(int)$r['created_by'])E::reject('lifecycle_corrupt');return $f+['id'=>$id];}
 private function event(int $supplier,int $id):array
 {$r=$this->one('SELECT * FROM property_service_lifecycle_events WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);$request=self::decode($r['request_json']);if(PropertyInput::hash($request)!==$r['request_hash']||($request['case_id']??null)!==(int)$r['case_id']||($request['event_key']??null)!==$r['event_key'])E::reject('lifecycle_corrupt');$receipt=$r['receipt_json']===null?null:self::decode($r['receipt_json']);if($receipt!==null&&PropertyInput::hash($receipt)!==$r['receipt_hash'])E::reject('lifecycle_corrupt');return ['recorded_at'=>$r['recorded_at'],'id'=>$id,'case_id'=>(int)$r['case_id'],'request'=>$request,'receipt'=>$receipt,'entry'=>$r['journal_entry_id']===null?null:(int)$r['journal_entry_id']];}
 private function newEvent(int $supplier,int $case,string $key,array $request):int
 {if($this->q('SELECT id FROM property_service_lifecycle_events WHERE supplier_id=? AND case_id=? AND event_key=? FOR UPDATE',[$supplier,$case,$key])->fetchColumn()!==false)E::reject('lifecycle_event_exists');$request=['case_id'=>$case,'event_key'=>$key]+$request;$this->q('INSERT INTO property_service_lifecycle_events(supplier_id,case_id,event_key,request_json,request_hash,recorded_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(6))',[$supplier,$case,$key,self::json($request),PropertyInput::hash($request)]);return(int)$this->db->pdo()->lastInsertId();}
 private function receipt(int $supplier,int $event,array $receipt,?int $entry=null):void
 {$q=$this->q('UPDATE property_service_lifecycle_events SET receipt_json=?,receipt_hash=?,journal_entry_id=? WHERE supplier_id=? AND id=? AND receipt_json IS NULL',[self::json($receipt),PropertyInput::hash($receipt),$entry,$supplier,$event]);if($q->rowCount()!==1)E::reject('lifecycle_event_changed');}
 private function claim(int $supplier,int $event,array $lines,string $role):void {foreach($lines as $line){if($this->q('SELECT event_id FROM property_service_lifecycle_claims WHERE supplier_id=? AND line_id=? AND claim_role=? FOR UPDATE',[$supplier,$line['id'],$role])->fetchColumn()!==false)E::reject('lifecycle_source_capacity');$this->q('INSERT INTO property_service_lifecycle_claims(supplier_id,event_id,line_id,claim_role) VALUES(?,?,?,?)',[$supplier,$event,$line['id'],$role]);}}
 private function scoped(array $case,array $line):void
 {foreach(['unit','service'] as $kind){$r=$this->one('SELECT v.id,v.type_id FROM property_objects o JOIN dimension_values v ON v.id=o.dimension_value_id AND v.supplier_id=o.supplier_id WHERE o.supplier_id=? AND o.id=? AND o.kind=? FOR UPDATE',[$case['supplier_id'],$case[$kind.'_id'],$kind]);if(($line['dimensions'][(int)$r['type_id']]??null)!==(int)$r['id'])E::reject('lifecycle_source_tuple');}foreach(['currency_code','amount_foreign','fx_rate'] as $key)if(($line[$key]??null)!==null)E::reject('fx_unsupported');if($line['is_red_storno']||bccomp($line['amount'],'0',2)<=0)E::reject('lifecycle_source_role');}
 private function source(int $supplier,int $entry):array {$source=(new PaymentJournalProof($this->db))->current($supplier,$entry);if($source['entry']['reversed_by']!==null)E::reject('lifecycle_source_reversed');return $source;}
 private function assertPair(array $case,array $source,string $debit,string $credit):string
 {if(count($source['lines'])!==2)E::reject('lifecycle_source_partition');[$a,$b]=$source['lines'];foreach([$a,$b] as $line)$this->scoped($case,$line);if($a['side']!=='debit'||$b['side']!=='credit'||!preg_match($debit,$a['account_code'])||!preg_match($credit,$b['account_code'])||$a['amount']!==$b['amount'])E::reject('lifecycle_source_role');$aTuple=$a;$bTuple=$b;foreach(['id','account_code','side','amount'] as $key){unset($aTuple[$key],$bTuple[$key]);}if(PropertyInput::hash($aTuple)!==PropertyInput::hash($bTuple))E::reject('lifecycle_source_tuple');return bcmul($a['amount'],'100',0);}
 private function estimateAmount(array $case,array $sources,bool $opening):string
 {
  $expected=$opening||$case['policy']==='service_balance_sheet'?1:2;
  if(count($sources)!==$expected)E::reject('lifecycle_estimate_sources');
  $amount=($opening||$case['policy']==='service_balance_sheet')?$this->assertPair($case,$sources[0],'/^388\\.2[0-9]{2}$/D','/^389\\.1[0-9]{2}$/D'):$this->assertPair($case,$sources[0],'/^5[0-9.]+$/D','/^389\\.1[0-9]{2}$/D');if($expected===2&&$this->assertPair($case,$sources[1],'/^388\\.2[0-9]{2}$/D','/^649(?:\\.[0-9]+)?$/D')!==$amount)E::reject('lifecycle_estimate_coverage');
  return $amount;
 }
 public function registerEstimate(int $supplier,int $caseId,array $entries,string $coverageTitle):array
 {return $this->tx(function()use($supplier,$caseId,$entries,$coverageTitle):array{
  $this->context($supplier);$case=$this->case($supplier,$caseId);PropertyInput::text($coverageTitle,'coverage_title',4000);$expected=$case['policy']==='service_balance_sheet'?1:2;if(count($entries)!==$expected||count(array_unique($entries))!==$expected)E::reject('lifecycle_estimate_sources');$sources=[];foreach($entries as $id){$source=$this->source($supplier,PropertyInput::id($id,'estimate_entry'));if($source['entry']['entry_date']<$case['period_from']||$source['entry']['entry_date']>$case['period_to']||$source['entry']['source_type']!=='manual')E::reject('lifecycle_estimate_period');$sources[]=$source;}
  $amount=$this->estimateAmount($case,$sources,false);
  $event=$this->newEvent($supplier,$caseId,'estimate',['kind'=>'estimate','entries'=>$entries,'coverage_title'=>$coverageTitle]);$receipt=['sources'=>$sources,'amount_cents'=>$amount,'opening_item_id'=>null];$this->receipt($supplier,$event,$receipt);foreach($sources as $source)$this->claim($supplier,$event,$source['lines'],'estimate');return $receipt+['event_id'=>$event];
 });}
 public function bindOpeningEstimate(int $supplier,int $caseId,int $itemId):array
 {return $this->tx(function()use($supplier,$caseId,$itemId):array{
  $this->context($supplier);$case=$this->case($supplier,$caseId);$item=(new OpeningReadProof($this->db))->item($supplier,$itemId,true);$f=$item['snapshot'];if(!$item['active']||$f['kind']!=='service_carry'||$f['unit_id']!==$case['unit_id']||$f['service_id']!==$case['service_id']||$f['period_from']!==$case['period_from']||$f['period_to']!==$case['period_to']||$f['service_state']['profile']!==$case['policy'])E::reject('lifecycle_opening_scope');$lines=[];foreach($item['components'] as $part)if(in_array($part['role'],['accrual_receivable','accrual_payable'],true))$lines[]=$part['line'];usort($lines,fn($a,$b)=>$a['side']==='debit'?-1:1);$source=['entry'=>(new PaymentJournalProof($this->db))->current($supplier,$item['batch']['journal_entry_id'])['entry'],'lines'=>$lines];$amount=$this->estimateAmount($case,[$source],true);
  $unwind=null;if($case['policy']==='service_profit_and_loss'){$unwind=$f['service_state']['accrual_unwind']??null;if(!is_array($unwind))E::reject('lifecycle_opening_policy_required');foreach(['expense_account'=>'expense','coverage_account'=>'revenue'] as $key=>$type){$account=$this->one('SELECT account_type,is_active FROM chart_of_accounts WHERE supplier_id=? AND account_code=? FOR UPDATE',[$supplier,$unwind[$key]]);if($account['account_type']!==$type||!$account['is_active'])E::reject('lifecycle_opening_policy_required');}$kinds=[];foreach(['expense_dimensions','coverage_dimensions'] as $field){$dimensions=$unwind[$field]??null;if(!is_array($dimensions))E::reject('lifecycle_opening_policy_required');$axes=[];foreach($dimensions as $type=>$value){$axis=$this->one('SELECT t.system_key,v.supplier_id FROM dimension_types t JOIN dimension_values v ON v.type_id=t.id AND v.supplier_id=t.supplier_id WHERE t.supplier_id=? AND t.id=? AND v.id=? FOR UPDATE',[$supplier,$type,$value]);if(!in_array($axis['system_key'],['activity','activity_kind','tax_regime'],true))E::reject('lifecycle_opening_policy_required');$axes[$axis['system_key']]=true;}if(count($axes)!==3)E::reject('lifecycle_opening_policy_required');$origins=array_fill_keys(array_keys($dimensions),['origin'=>'explicit','ref'=>'opening-accrual-policy']);(new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->assertAssignments($supplier,[['dimensions'=>$dimensions,'dimension_origins'=>$origins]],$item['batch']['accounting_date']);}}
  $event=$this->newEvent($supplier,$caseId,'estimate',['kind'=>'opening_estimate','opening_item_id'=>$itemId]);$receipt=['sources'=>[$source],'amount_cents'=>$amount,'opening_item_id'=>$itemId,'opening_hash'=>$item['snapshot_hash'],'accrual_unwind'=>$unwind];$this->receipt($supplier,$event,$receipt);$this->claim($supplier,$event,$lines,'estimate');return $receipt+['event_id'=>$event];
 });}
 private function events(int $supplier,int $caseId):array {$out=[];foreach($this->q('SELECT id FROM property_service_lifecycle_events WHERE supplier_id=? AND case_id=? ORDER BY id FOR UPDATE',[$supplier,$caseId])->fetchAll(PDO::FETCH_COLUMN) as $id){$e=$this->event($supplier,(int)$id);$out[$e['request']['event_key']]=$e;}return $out;}
 private function estimate(int $supplier,int $caseId):array
 {
  $events=$this->events($supplier,$caseId);$event=$events['estimate']??null;
  if(!$event||!$event['receipt'])E::reject('lifecycle_estimate_required');
  $receipt=$event['receipt'];$case=$this->case($supplier,$caseId);$expected=[];
  foreach($receipt['sources'] as $source)foreach($source['lines'] as $line)$expected[]=$line['id'];
  sort($expected,SORT_NUMERIC);
  $actual=array_map('intval',$this->q('SELECT line_id FROM property_service_lifecycle_claims WHERE supplier_id=? AND event_id=? AND claim_role=\'estimate\' ORDER BY line_id FOR UPDATE',[$supplier,$event['id']])->fetchAll(PDO::FETCH_COLUMN));
  if($expected!==$actual)E::reject('lifecycle_source_binding');
  $sources=[];foreach($receipt['sources'] as $index=>$source)$sources[]=$this->original($supplier,$event,$index);
  $opening=$receipt['opening_item_id']!==null;
  if($this->estimateAmount($case,$sources,$opening)!==$receipt['amount_cents'])E::reject('lifecycle_estimate_coverage');
  if($opening){if($event['request']['kind']!=='opening_estimate'||$event['request']['opening_item_id']!==$receipt['opening_item_id'])E::reject('lifecycle_source_binding');}
  else{
   if($event['request']['kind']!=='estimate'||array_map('intval',$event['request']['entries'])!==array_column(array_column($sources,'entry'),'id'))E::reject('lifecycle_source_binding');
   foreach($sources as $source)if($source['entry']['source_type']!=='manual'||$source['entry']['entry_date']<$case['period_from']||$source['entry']['entry_date']>$case['period_to'])E::reject('lifecycle_estimate_period');
  }
  return $event;
 }
 private function original(int $supplier,array $estimate,int $index):array
 {$saved=$estimate['receipt']['sources'][$index]??null;if(!$saved)E::reject('lifecycle_source_missing');if($estimate['receipt']['opening_item_id']!==null){$item=(new OpeningReadProof($this->db))->item($supplier,$estimate['receipt']['opening_item_id'],true);if(!$item['active']||$item['snapshot_hash']!==$estimate['receipt']['opening_hash']||PropertyInput::hash([$item['snapshot']['service_state']['accrual_unwind']??null])!==PropertyInput::hash([$estimate['receipt']['accrual_unwind']??null]))E::reject('lifecycle_opening_changed');$lines=[];foreach($item['components'] as $p)if(in_array($p['role'],['accrual_receivable','accrual_payable'],true))$lines[]=$p['line'];usort($lines,fn($a,$b)=>$a['side']==='debit'?-1:1);if(PropertyInput::hash($lines)!==PropertyInput::hash($saved['lines']))E::reject('lifecycle_source_changed');return $saved;}$actual=(new PaymentJournalProof($this->db))->current($supplier,$saved['entry']['id']);$expected=$saved;$expected['entry']['reversed_by']=$actual['entry']['reversed_by'];if(PropertyInput::hash($actual)!==PropertyInput::hash($expected))E::reject('lifecycle_source_changed');return $actual;}
 private function unwindLines(array $estimate,int $index):array
 {$source=$estimate['receipt']['sources'][$index];$unwind=$estimate['receipt']['accrual_unwind']??null;if($unwind===null)return array_map(JournalLineAmount::reversal(...),$source['lines']);$receivable=$source['lines'][0];$payable=$source['lines'][1];$a=JournalLineAmount::reversal($payable);$b=$payable;$b['account_code']=$unwind['expense_account'];$b['dimensions']+=$unwind['expense_dimensions'];$b['dimension_origins']+=array_fill_keys(array_keys($unwind['expense_dimensions']),['origin'=>'explicit','ref'=>'opening-accrual-policy']);$c=$receivable;$c['account_code']=$unwind['coverage_account'];$c['dimensions']+=$unwind['coverage_dimensions'];$c['dimension_origins']+=array_fill_keys(array_keys($unwind['coverage_dimensions']),['origin'=>'explicit','ref'=>'opening-accrual-policy']);$d=JournalLineAmount::reversal($receivable);return[$a,$b,$c,$d];}
 /**
  * Samostatné rozpuštění dohadu je přípustné jen v účetním období, které začíná až po
  * skončení zúčtovacího období služby. V období spotřeby smí dohad zmizet jen spolu
  * s konečným zúčtováním (unwindWithFinal), jinak by rok zůstal bez nákladu i krytí.
  */
 public function unwind(int $supplier,int $caseId,string $date,int $actor):array
 {return $this->tx(function()use($supplier,$caseId,$date,$actor):array{
  $this->context($supplier);
  $case=$this->case($supplier,$caseId);
  if($this->accountingPeriod($supplier,E::date($date))['starts_on']<=$case['period_to'])E::reject('lifecycle_unwind_with_final_required');
  return $this->unwindEntries($supplier,$caseId,$date,$actor);
 });}
 /** Rozpuštění jako součást konečného zúčtování ve stejné transakci a ke stejnému datu. */
 public function unwindWithFinal(int $supplier,int $caseId,string $date,int $actor):array
 {return $this->tx(function()use($supplier,$caseId,$date,$actor):array{$this->context($supplier);return $this->unwindEntries($supplier,$caseId,E::date($date),$actor);});}
 public function isUnwound(int $supplier,int $caseId):bool
 {
  $this->context($supplier);
  $estimate=$this->estimate($supplier,$caseId);
  $completed=$this->completedUnwinds($supplier,$estimate,$this->events($supplier,$caseId));
  return count($completed)===count($estimate['receipt']['sources']);
 }
 private function accountingPeriod(int $supplier,string $date):array
 {
  $period=$this->q('SELECT id,starts_on,ends_on FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? ORDER BY id FOR UPDATE',[$supplier,$date,$date])->fetchAll(PDO::FETCH_ASSOC);
  if(count($period)!==1)E::reject('lifecycle_period_missing');
  return $period[0];
 }
 /** Konečné zúčtování musí ležet ve stejném účetním období jako všechna rozpuštění dohadu. */
 private function assertUnwindPeriod(int $supplier,int $caseId,string $date):void
 {
  $period=$this->accountingPeriod($supplier,$date);
  foreach($this->unwound($supplier,$caseId)['verified_unwind_dates'] as $unwindDate){
   if($unwindDate<$period['starts_on']||$unwindDate>$period['ends_on'])E::reject('lifecycle_unwind_period');
  }
 }
 private function unwindEntries(int $supplier,int $caseId,string $date,int $actor):array
 {$case=$this->case($supplier,$caseId);$estimate=$this->estimate($supplier,$caseId);$entries=[];foreach(array_keys($estimate['receipt']['sources']) as $index){$event=$this->newEvent($supplier,$caseId,'unwind-'.$index,['kind'=>'unwind','estimate'=>$estimate['id'],'index'=>$index,'accounting_date'=>$date,'actor'=>$actor]);$entries[]=(new JournalEntryRepository($this->db,new \MyInvoice\Service\ActivityLogger($this->db)))->insertPropertyServiceLifecycle(ServiceLifecycleProof::fromPending($this->db,$this,$supplier,$event));}return $entries;}
 private function completedUnwinds(int $supplier,array $estimate,array $events):array
 {
  $completed=[];
  foreach($events as $key=>$event){
   if(!str_starts_with((string)$key,'unwind-'))continue;$r=$event['request'];$index=$r['index']??null;
   if(($r['kind']??null)!=='unwind'||!is_int($index)||$key!=='unwind-'.$index||!isset($estimate['receipt']['sources'][$index])||($r['estimate']??null)!==$estimate['id'])E::reject('lifecycle_unwind_proof');
   if($event['receipt']===null){if($event['entry']!==null)E::reject('lifecycle_unwind_proof');continue;}
   if($event['entry']===null||!isset($event['receipt']['ledger']))E::reject('lifecycle_unwind_proof');$ledger=(new PaymentJournalProof($this->db))->current($supplier,$event['entry']);$source=$this->original($supplier,$estimate,$index);
   if(PropertyInput::hash($ledger)!==PropertyInput::hash($event['receipt']['ledger'])||$ledger['entry']['reversed_by']!==null||$ledger['entry']['entry_date']!==($r['accounting_date']??null)||$ledger['entry']['entry_date']<$source['entry']['entry_date']||$ledger['entry']['source_type']!==($estimate['receipt']['opening_item_id']===null?$source['entry']['source_type']:'property_service_lifecycle')||$ledger['entry']['source_id']!==null||PropertyInput::hash(PaymentJournalProof::canonicalLines($ledger['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($this->unwindLines($estimate,$index))))E::reject('lifecycle_unwind_proof');
   if($estimate['receipt']['opening_item_id']===null&&$source['entry']['reversed_by']!==$event['entry'])E::reject('lifecycle_unwind_proof');
   $completed[$index]=['event_id'=>$event['id'],'index'=>$index,'recorded_at'=>$event['recorded_at'],'accounting_date'=>$ledger['entry']['entry_date'],'request'=>$r,'receipt'=>$event['receipt'],'ledger'=>$ledger];
  }
  if($estimate['receipt']['opening_item_id']===null)foreach($estimate['receipt']['sources'] as $index=>$saved){$source=$this->original($supplier,$estimate,$index);if($source['entry']['reversed_by']!==($completed[$index]['ledger']['entry']['id']??null))E::reject('lifecycle_unwind_proof');}
  ksort($completed);return $completed;
 }
 private function unwound(int $supplier,int $caseId):array
 {$estimate=$this->estimate($supplier,$caseId);$completed=$this->completedUnwinds($supplier,$estimate,$this->events($supplier,$caseId));$dates=[];foreach($estimate['receipt']['sources'] as $index=>$saved){if(!isset($completed[$index]))E::reject('lifecycle_estimate_not_unwound');$dates[]=$completed[$index]['accounting_date'];}return $estimate+['verified_unwind_dates'=>$dates];}
 public function assertFinalDate(int $supplier,int $caseId,string $date):void
 {$this->context($supplier);self::assertUnwindDates($date,$this->unwound($supplier,$caseId));}
 private static function assertUnwindDates(string $date,array $proof):void
 {E::date($date);$dates=$proof['verified_unwind_dates']??[];if($dates===[])E::reject('lifecycle_unwind_proof');foreach($dates as $unwindDate)if($date<$unwindDate)E::reject('lifecycle_final_before_unwind');}
 public function invoiceHashInput(int $supplier,int $invoice):array
 {$document=$this->one('SELECT supplier_id,vendor_id,document_kind,issue_date,tax_date,delivery_date,total_without_vat,total_vat,total_with_vat,currency_id,exchange_rate,prices_include_vat,vat_deduction,status FROM purchase_invoices WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$invoice]);if(in_array($document['status'],['draft','cancelled'],true))E::reject('lifecycle_invoice_changed');unset($document['status']);$items=$this->q('SELECT id,description,quantity,unit_price_without_vat,vat_rate_id,vat_rate_snapshot,total_without_vat,total_vat,total_with_vat,expense_kind,expense_account_code,order_index FROM purchase_invoice_items WHERE purchase_invoice_id=? ORDER BY order_index,id FOR UPDATE',[$invoice])->fetchAll(PDO::FETCH_ASSOC);$dimensions=new \MyInvoice\Repository\DimensionAssignmentRepository($this->db);return ['header'=>$document,'items'=>$items,'dimensions'=>$dimensions->documentDimensions($supplier,'purchase_invoice',$invoice,true),'origins'=>$dimensions->documentOrigins($supplier,'purchase_invoice',$invoice,true),'splits'=>$dimensions->documentSplits($supplier,'purchase_invoice',$invoice,true)];}
 private function invoice(array $case,int $invoice,int $lineId):array
 {$header=$this->one("SELECT id FROM journal_entries WHERE supplier_id=? AND source_type='purchase_invoice' AND source_id=? AND reversed_by IS NULL AND posted_at IS NOT NULL ORDER BY id FOR UPDATE",[$case['supplier_id'],$invoice]);$ledger=$this->source($case['supplier_id'],(int)$header['id']);$lines=array_values(array_filter($ledger['lines'],fn($l)=>$l['id']===$lineId));if(count($lines)!==1)E::reject('lifecycle_invoice_line');$line=$lines[0];$this->scoped($case,$line);$pattern=$case['policy']==='service_balance_sheet'?'/^315\\.2[0-9]{2}$/D':'/^5[0-9.]+$/D';if($line['side']!=='debit'||!preg_match($pattern,$line['account_code'])||($case['policy']==='service_balance_sheet'&&$line['account_code']!==$case['service_cost_account']))E::reject('lifecycle_invoice_role');$supplierLine=array_filter($ledger['lines'],fn($l)=>$l['side']==='credit'&&preg_match('/^321(?:\\.[0-9]+)?$/D',$l['account_code']));if($supplierLine===[])E::reject('lifecycle_invoice_counterpart');$documentHash=PropertyInput::hash($this->invoiceHashInput($case['supplier_id'],$invoice));return ['invoice_id'=>$invoice,'line'=>$line,'ledger'=>$ledger,'document_hash'=>$documentHash];}
 public function finalize(int $supplier,int $caseId,int $invoice,int $lineId,string $date,string $title,int $actor):array
 {return $this->tx(function()use($supplier,$caseId,$invoice,$lineId,$date,$title,$actor):array{$this->context($supplier);$case=$this->case($supplier,$caseId);$this->assertFinalDate($supplier,$caseId,$date);$this->assertUnwindPeriod($supplier,$caseId,$date);PropertyInput::text($title,'final_coverage_title',4000);$source=$this->invoice($case,$invoice,$lineId);(new PaymentPostingDate($this->db))->assertOpen($supplier,$date);if($date<$source['ledger']['entry']['entry_date'])E::reject('lifecycle_final_date');$event=$this->newEvent($supplier,$caseId,'final',['kind'=>'final','invoice_id'=>$invoice,'line_id'=>$lineId,'accounting_date'=>$date,'title'=>$title,'actor'=>$actor]);$this->claim($supplier,$event,[$source['line']],'final');if($case['policy']==='service_profit_and_loss')$entry=(new JournalEntryRepository($this->db,new \MyInvoice\Service\ActivityLogger($this->db)))->insertPropertyServiceLifecycle(ServiceLifecycleProof::fromPending($this->db,$this,$supplier,$event));else{$this->receipt($supplier,$event,['invoice'=>$source,'cost_line_id'=>$lineId,'amount_cents'=>bcmul($source['line']['amount'],'100',0)]);}$result=$this->current($supplier,$caseId);return $result['final'];});}
 private function finalLines(array $case,array $invoice,array $estimate):array
 {$line=$invoice['line'];$a=$line;$a['account_code']=$case['service_cost_account'];$a['side']='debit';$b=$line;$b['account_code']=$estimate['receipt']['accrual_unwind']['coverage_account']??$estimate['receipt']['sources'][1]['lines'][1]['account_code'];$b['side']='credit';return[$a,$b];}
 public function pending(Connection $db,int $supplier,int $eventId):array
 {if($db->pdo()!==$this->db->pdo())throw new \LogicException('Wrong lifecycle connection.');$this->context($supplier);$event=$this->event($supplier,$eventId);if($event['receipt']!==null)E::reject('lifecycle_already_written');$case=$this->case($supplier,$event['case_id']);$r=$event['request'];$date=E::date($r['accounting_date']);(new PaymentPostingDate($this->db))->assertOpen($supplier,$date);$period=$this->one("SELECT id FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? AND status='open' FOR UPDATE",[$supplier,$date,$date]);$sourceEntry=null;
  if($r['kind']==='unwind'){$estimate=$this->estimate($supplier,$case['id']);if($estimate['id']!==$r['estimate'])E::reject('lifecycle_source_missing');$source=$this->original($supplier,$estimate,$r['index']);if($estimate['receipt']['opening_item_id']===null){if($source['entry']['reversed_by']!==null)E::reject('lifecycle_source_reversed');$sourceEntry=$source['entry']['id'];}if($date<($source['entry']['entry_date']??$case['period_to']))E::reject('lifecycle_unwind_date');$lines=$this->unwindLines($estimate,$r['index']);$sourceType=$sourceEntry===null?'property_service_lifecycle':$source['entry']['source_type'];}
  elseif($r['kind']==='final'&&$case['policy']==='service_profit_and_loss'){$this->assertFinalDate($supplier,$case['id'],$date);$this->assertUnwindPeriod($supplier,$case['id'],$date);$invoice=$this->invoice($case,$r['invoice_id'],$r['line_id']);$source=$invoice;$lines=$this->finalLines($case,$invoice,$this->estimate($supplier,$case['id']));$sourceType='property_service_lifecycle';}
  else E::reject('lifecycle_operation');
  foreach($lines as &$line){unset($line['id']);$account=$this->one('SELECT id,account_type FROM chart_of_accounts WHERE supplier_id=? AND account_code=? FOR UPDATE',[$supplier,$line['account_code']]);$line['account_id']=(int)$account['id'];}unset($line);$header=['supplier_id'=>$supplier,'period_id'=>(int)$period['id'],'entry_date'=>$date,'document_date'=>$date,'document_no'=>null,'description'=>'Služby: '.$r['kind'],'source_type'=>$sourceType,'source_id'=>null,'posted_at'=>$event['recorded_at'],'posted_by'=>$r['actor']];return ['case'=>$case,'event'=>$r,'source'=>$source,'journal'=>['header'=>$header,'lines'=>$lines,'source_entry_id'=>$sourceEntry]];
 }
 public function complete(Connection $db,int $supplier,int $eventId,int $entry):void
 {if($db->pdo()!==$this->db->pdo())throw new \LogicException('Wrong lifecycle connection.');$event=$this->event($supplier,$eventId);if($event['receipt']!==null)E::reject('lifecycle_already_written');$case=$this->case($supplier,$event['case_id']);$ledger=(new PaymentJournalProof($this->db))->current($supplier,$entry);$r=$event['request'];if($ledger['entry']['entry_date']!==$r['accounting_date']||$ledger['entry']['reversed_by']!==null)E::reject('lifecycle_journal_mismatch');if($r['kind']==='unwind'){$estimate=$this->estimate($supplier,$case['id']);$saved=$estimate['receipt']['sources'][$r['index']]??null;if(!$saved||$estimate['id']!==$r['estimate'])E::reject('lifecycle_source_missing');$actual=$this->original($supplier,$estimate,$r['index']);if($estimate['receipt']['opening_item_id']===null&&$actual['entry']['reversed_by']!==$entry)E::reject('lifecycle_unwind_proof');if(PropertyInput::hash(PaymentJournalProof::canonicalLines($ledger['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($this->unwindLines($estimate,$r['index']))))E::reject('lifecycle_journal_mismatch');$receipt=['ledger'=>$ledger];}else{ $expected=$this->pending($db,$supplier,$eventId);if(PropertyInput::hash(PaymentJournalProof::canonicalLines($ledger['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($expected['journal']['lines'])))E::reject('lifecycle_journal_mismatch');$source=$this->invoice($case,$r['invoice_id'],$r['line_id']);$receipt=['ledger'=>$ledger,'invoice'=>$source,'cost_line_id'=>$ledger['lines'][0]['id'],'amount_cents'=>bcmul($source['line']['amount'],'100',0)];}$this->receipt($supplier,$eventId,$receipt,$entry);}
 /**
  * Stav případu je důkaz složený z mnoha zamčených tabulek (případ, události, deník,
  * faktura, dimenze, počáteční stavy); v jedné transakci se počítá znovu jen po zápisu
  * nebo hranici transakce ({@see \MyInvoice\Infrastructure\Database\CurrentReadMemo}).
  */
 private function currentState(int $supplier,int $caseId,bool $allowPendingFinal=false):array
 {return \MyInvoice\Infrastructure\Database\CurrentReadMemo::remember($this->db,'lifecycle-state|'.$supplier.'|'.$caseId.'|'.(int)$allowPendingFinal,fn():array=>$this->computeState($supplier,$caseId,$allowPendingFinal));}
 private function computeState(int $supplier,int $caseId,bool $allowPendingFinal=false):array
 {$this->context($supplier);$case=$this->case($supplier,$caseId);$events=$this->events($supplier,$caseId);$estimate=isset($events['estimate'])&&$events['estimate']['receipt']!==null?$this->estimate($supplier,$caseId):null;$completed=$estimate===null?[]:$this->completedUnwinds($supplier,$estimate,$events);if($estimate===null)foreach($events as $key=>$event)if(str_starts_with((string)$key,'unwind-')&&$event['receipt']!==null)E::reject('lifecycle_estimate_required');$result=['case'=>$case,'estimate'=>$estimate['receipt']??null,'final'=>null];if(isset($events['final'])&&(!$allowPendingFinal||$events['final']['receipt']!==null)){$estimate=$this->unwound($supplier,$caseId);$event=$events['final'];self::assertUnwindDates($event['request']['accounting_date'],$estimate);$receipt=$event['receipt'];if(!$receipt)E::reject('lifecycle_pending');$invoice=$this->invoice($case,$event['request']['invoice_id'],$event['request']['line_id']);if(PropertyInput::hash($invoice)!==PropertyInput::hash($receipt['invoice']))E::reject('lifecycle_invoice_changed');if($receipt['amount_cents']!==bcmul($invoice['line']['amount'],'100',0))E::reject('lifecycle_coverage_changed');if($case['policy']==='service_profit_and_loss'){if(!isset($receipt['ledger'])||$event['entry']!==$receipt['ledger']['entry']['id']||$receipt['ledger']['entry']['source_type']!=='property_service_lifecycle'||$receipt['ledger']['entry']['entry_date']!==$event['request']['accounting_date']||$receipt['cost_line_id']!==$receipt['ledger']['lines'][0]['id']||PropertyInput::hash(PaymentJournalProof::canonicalLines($receipt['ledger']['lines']))!==PropertyInput::hash(PaymentJournalProof::canonicalLines($this->finalLines($case,$invoice,$estimate))))E::reject('lifecycle_coverage_changed');}elseif(isset($receipt['ledger'])||$event['entry']!==null||$receipt['cost_line_id']!==$invoice['line']['id'])E::reject('lifecycle_coverage_changed');if(isset($receipt['ledger'])&&PropertyInput::hash((new PaymentJournalProof($this->db))->current($supplier,$event['entry']))!==PropertyInput::hash($receipt['ledger']))E::reject('lifecycle_coverage_changed');$claim=$this->q('SELECT line_id FROM property_service_lifecycle_claims WHERE supplier_id=? AND event_id=? AND claim_role=\'final\' ORDER BY line_id FOR UPDATE',[$supplier,$event['id']])->fetchAll(PDO::FETCH_COLUMN);if(array_map('intval',$claim)!==[$event['request']['line_id']])E::reject('lifecycle_source_binding');$result['final']=$receipt+['event_id'=>$event['id'],'accounting_date'=>$event['request']['accounting_date']];}return ['current'=>$result,'events'=>$events,'estimate_event'=>$estimate,'unwinds'=>$completed];}
 public function current(int $supplier,int $caseId):array
 {return $this->currentState($supplier,$caseId)['current'];}
 public function reconciliationSources(int $supplier,int $caseId):array
 {
  $state=$this->currentState($supplier,$caseId,true);$pending=[];foreach($state['events'] as $key=>$event)if($event['receipt']===null)$pending[]=['code'=>'lifecycle_pending','event_id'=>$event['id'],'event_key'=>$key,'accounting_date'=>$event['request']['accounting_date']??null,'recorded_at'=>$event['recorded_at']];
  $estimate=$state['estimate_event'];$final=$state['current']['final'];if($final!==null)$final+=['recorded_at'=>$state['events']['final']['recorded_at'],'request'=>$state['events']['final']['request']];
  array_push($pending,...$this->unwindGaps($supplier,$state['unwinds'],$final));
  $policy=$this->policyGap($supplier,$state['current']['case']);
  if($policy!==null)$pending[]=$policy;
  return ['case'=>$state['current']['case'],'estimate'=>$estimate===null?null:['event_id'=>$estimate['id'],'recorded_at'=>$estimate['recorded_at'],'request'=>$estimate['request'],'receipt'=>$estimate['receipt']],'unwinds'=>array_values($state['unwinds']),'final'=>$final,'pending'=>$pending];
 }
 /**
  * Rozpuštění bez konečného zúčtování ve stejném účetním období nechává období bez
  * nákladu i krytí. Uzávěrka toho období proto nesmí projít.
  */
 private function unwindGaps(int $supplier,array $unwinds,?array $final):array
 {
  $gaps=[];
  foreach($unwinds as $unwind){
   $period=$this->accountingPeriod($supplier,$unwind['accounting_date']);
   $finalDate=$final['accounting_date']??null;
   if($finalDate!==null&&$finalDate>=$period['starts_on']&&$finalDate<=$period['ends_on'])continue;
   $gaps[]=['code'=>'lifecycle_unwind_without_final','event_id'=>$unwind['event_id'],'event_key'=>'unwind-'.$unwind['index'],'accounting_date'=>$period['ends_on'],'recorded_at'=>$unwind['recorded_at']];
  }
  return $gaps;
 }
 private function policyGap(int $supplier,array $case):?array
 {
  $versions=(new ServiceLifecyclePolicyRepository($this->db))->governing($supplier,$case['service_id'],$case['period_from'],$case['period_to']);
  if(count($versions)===1&&$versions[0]['policy']===$case['policy'])return null;
  return ['code'=>'service_policy_unproven','event_id'=>null,'event_key'=>null,'accounting_date'=>$case['period_to'],'recorded_at'=>null,'policy'=>$case['policy'],'version_ids'=>array_column($versions,'id')];
 }
 public function assertCostReady(int $supplier,int $unit,int $service,int $cost,string $date,?int $openingItem=null,?array $period=null):?array
 {$this->context($supplier);$cases=$this->q('SELECT id FROM property_service_lifecycle_cases WHERE supplier_id=? AND unit_id=? AND service_id=? ORDER BY id FOR UPDATE',[$supplier,$unit,$service])->fetchAll(PDO::FETCH_COLUMN);if($cases===[])return null;$matching=[];$relevant=false;foreach($cases as $id){$scope=$this->case($supplier,(int)$id);if($period!==null&&($scope['period_to']<$period['from']||$scope['period_from']>$period['to']))continue;$relevant=true;$state=$this->current($supplier,(int)$id);if(($state['final']['cost_line_id']??null)===$cost)$matching[]=$state;}if(!$relevant)return null;if(count($matching)!==1)E::reject('lifecycle_final_proof_required');$state=$matching[0];if($state['final']['accounting_date']>E::date($date))E::reject('lifecycle_final_after_settlement');if($openingItem!==null&&($state['estimate']['opening_item_id']??null)!==$openingItem)E::reject('lifecycle_opening_scope');return $state;}
}
