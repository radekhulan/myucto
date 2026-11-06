<?php
declare(strict_types=1);
namespace MyInvoice\Service\Property\Funds;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Repository\Property\Funds\FundingRepository;
use MyInvoice\Service\Property\{PropertyInput,PropertyInstant};
use MyInvoice\Service\Property\Opening\{OpeningReadProof,OpeningPaymentAvailability};
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Property\Charges\{ChargeCalculator,ChargeFraction};
use PDO;
final class FundingSourceProof
{
    private array $openingItems=[];
    public function __construct(private readonly Connection $db){}
    private function one(string $sql,array $args):array {$q=$this->db->pdo()->prepare($sql);$q->execute($args);return $q->fetch(PDO::FETCH_ASSOC)?:throw new FundingException('property_funding_source_missing');}
    public static function cents(mixed $value,bool $zero=false):string
    {if(!is_string($value)||!preg_match('/^(0|[1-9][0-9]{0,14})$/D',$value)||(!$zero&&$value==='0'))PropertyInput::invalid('amount_cents');return $value;}
    public static function instant(string $date):string {return(new \DateTimeImmutable($date.' 23:59:59',new \DateTimeZone('Europe/Prague')))->format(DATE_ATOM);}
    public function registry(int $supplier,int $house,string $date,array $key,string $amount):array
    {
        PropertyInput::fields($key,['mode','at','method','legal_basis','evidence_ref']);
        $at=PropertyInstant::normalize($key['at']??'');$mode=$key['mode']??null;$method=$key['method']??null;$basis=$key['legal_basis']??null;
        PropertyInput::text($key['evidence_ref']??null,'key.evidence_ref',4000);
        if(!in_array($mode,['at_accounting_date','resolution_snapshot'],true)||!in_array($method,['share','equal'],true))FundingException::reject('key_required');
        $day=(new \DateTimeImmutable($at))->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d');
        if(($mode==='at_accounting_date'&&$day!==$date)||$day>$date)FundingException::reject('key_date');
        if(!in_array($basis,['management_share','own_administration_equal','declaration_override'],true)||($basis==='management_share'&&$method!=='share')||($basis==='own_administration_equal'&&$method!=='equal'))FundingException::reject('key_basis');
        $read=new PropertyReadRepository($this->db);$building=$read->find($supplier,$house,$at,null,true);
        if($building['kind']!=='house'||!($building['revision']['state']['active']??false)||!($building['revision']['state']['registry_complete']??false)||$building['pending_revision_ids']!==[])FundingException::reject('registry_unproven');
        $units=[];$weights=[];
        foreach($read->list($supplier,'unit',$at,null,true) as $unit){if($unit['parent_id']!==$house||!($unit['revision']['state']['active']??false))continue;if($unit['ownership_review_required'])FundingException::reject('registry_unproven');$state=$unit['revision']['state'];$units[$unit['id']]=$unit;$weights[]=['id'=>$unit['id'],'attributes'=>$state['attributes']];}
        if($weights===[])FundingException::reject('registry_empty');
        if($basis==='management_share'){ $sum=['num'=>'0','den'=>'1'];foreach($weights as $weight)$sum=ChargeFraction::add($sum,$weight['attributes']['share']);if($sum!==['num'=>'1','den'=>'1'])FundingException::reject('share_incomplete'); }
        $calculation=ChargeCalculator::calculate(['method'=>$method,'mode'=>'total_budget','amount'=>bcdiv($amount,'100',2),'rounding'=>ChargeCalculator::ROUNDING],$weights);
        return ['key'=>$key,'house'=>$building,'units'=>$units,'calculation'=>$calculation];
    }
    public static function registryHash(array $registry):string
    {
        $facts=['key'=>$registry['key'],'calculation'=>$registry['calculation'],'house'=>[],'units'=>[]];foreach(['house'=>[$registry['house']],'units'=>$registry['units']] as $kind=>$objects)foreach($objects as $object){$fact=['id'=>$object['id'],'parent_id'=>$object['parent_id'],'dimension_value_id'=>$object['dimension_value_id'],'revision_id'=>$object['revision']['id'],'state_hash'=>$object['revision']['state_hash'],'state'=>$object['revision']['state']];if($kind==='house')$facts['house']=$fact;else$facts['units'][$object['id']]=$fact;}return PropertyInput::hash($facts);
    }
    public static function isIncomeOperation(array $operation):bool {return ($operation['kind']??null)==='income';}
    public static function incomeEffects(array $registry):array
    {
        $effects=[];foreach($registry['calculation']['lines'] as $allocation){$unit=$allocation['unit_id'];$amount=$allocation['amount_cents'];if($amount==='0')continue;$groups=$registry['units'][$unit]['revision']['state']['ownerships'];$weights=[];foreach($groups as $i=>$group)$weights[]=['id'=>$i+1,'attributes'=>['share'=>$group['share']]];$parts=ChargeCalculator::calculate(['method'=>'share','mode'=>'total_budget','amount'=>bcdiv($amount,'100',2),'rounding'=>ChargeCalculator::ROUNDING],$weights);foreach($parts['lines'] as $part)if($part['amount_cents']!=='0')$effects[]=['unit_id'=>$unit,'account_code'=>'379.920','amount_cents'=>$part['amount_cents'],'source_kind'=>null,'source_id'=>null,'parties'=>$groups[$part['unit_id']-1]];}return $effects;
    }
    /** @var \WeakMap<\PDO,array{writes:string,rows:array<string,array<int,int>>}>|null dimenze jednotek čtené se zámkem v probíhající transakci */
    private static ?\WeakMap $unitDimensions=null;
    public function dimensions(int $supplier,int $unit):array
    {
        $pdo=$this->db->pdo();
        if(!$pdo instanceof \MyInvoice\Infrastructure\Database\LoggingPdo||!$pdo->statementsTracked()||!$pdo->inTransaction())return $this->readDimensions($supplier,$unit);
        $writes=$pdo->tableWriteCount('property_objects').'|'.$pdo->tableWriteCount('dimension_values');self::$unitDimensions??=new \WeakMap();$memo=self::$unitDimensions[$pdo]??null;
        if($memo===null||$memo['writes']!==$writes)$memo=['writes'=>$writes,'rows'=>[]];
        $memo['rows'][$supplier.'|'.$unit]??=$this->readDimensions($supplier,$unit);self::$unitDimensions[$pdo]=$memo;
        return $memo['rows'][$supplier.'|'.$unit];
    }
    private function readDimensions(int $supplier,int $unit):array
    {$row=$this->one('SELECT v.type_id,v.id FROM property_objects o JOIN dimension_values v ON v.id=o.dimension_value_id AND v.supplier_id=o.supplier_id WHERE o.supplier_id=? AND o.id=? AND o.kind=\'unit\' FOR UPDATE',[$supplier,$unit]);return[(int)$row['type_id']=>(int)$row['id']];}
    public function advance(int $supplier,array $pointer,string $date,?string $effectiveAt=null):array
    {
        $effectiveAt=PropertyInstant::normalize($effectiveAt??self::instant($date));PropertyInput::fields($pointer,['kind','id']);$id=PropertyInput::id($pointer['id']??null,'source.id');$kind=$pointer['kind']??null;
        if($kind==='opening'){
            $row=$this->one('SELECT c.item_id,i.batch_id FROM property_opening_components c JOIN property_opening_items i ON i.supplier_id=c.supplier_id AND i.id=c.item_id WHERE c.supplier_id=? AND c.id=? FOR UPDATE',[$supplier,$id]);$itemId=(int)$row['item_id'];if(!isset($this->openingItems[$supplier][$itemId])){$q=$this->db->pdo()->prepare('SELECT id FROM property_opening_items WHERE supplier_id=? AND batch_id=? ORDER BY id FOR UPDATE');$q->execute([$supplier,$row['batch_id']]);$this->openingItems[$supplier]=($this->openingItems[$supplier]??[])+(new OpeningReadProof($this->db))->items($supplier,array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)),true);}$item=$this->openingItems[$supplier][$itemId];
            if(!$item['active']||$item['snapshot']['kind']!=='advance')FundingException::reject('advance_source');OpeningPaymentAvailability::assertAvailable(['opening_accounting_date'=>$item['batch']['accounting_date'],'opening_effective_at'=>$item['batch']['effective_at']],$date,$effectiveAt);
            $parts=array_column($item['components'],null,'id');$part=$parts[$id]??throw new FundingException('property_funding_advance_source');
            if(!in_array($part['role'],['long_term_advance','admin_advance'],true))FundingException::reject('advance_source');
            return['pointer'=>$pointer,'unit_id'=>$item['snapshot']['unit_id'],'account_code'=>$part['line']['account_code'],'amount_cents'=>$part['amount_cents'],'date'=>$item['batch']['accounting_date'],'purpose_ref'=>$item['snapshot']['advance_state']['purpose_ref'],'proof'=>$item];
        }
        if($kind==='issued'){
            $row=$this->one('SELECT charge_id,line_json FROM unit_charge_lines WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$id]);$charge=(new IssuanceReadRepository($this->db))->find($supplier,(int)$row['charge_id'],true);$line=json_decode($row['line_json'],true,flags:JSON_THROW_ON_ERROR);
            if($charge['status']!=='posted'||$charge['accounting_date']>$date||PropertyInstant::normalize($charge['snapshot']['issued_at'])>$effectiveAt||!in_array($line['component']['kind']??null,['long_term_advance','admin_advance'],true)||bccomp($line['amount_cents'],'0',0)<=0)FundingException::reject('advance_source');
            (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceJournalProof($this->db))->assertEntry($supplier,$charge['journal_entry_id'],$charge);
            return['pointer'=>$pointer,'unit_id'=>$charge['unit_id'],'account_code'=>$line['account']['counter_account'],'amount_cents'=>$line['amount_cents'],'date'=>$charge['accounting_date'],'purpose_ref'=>$charge['snapshot']['title'],'proof'=>['charge'=>$charge,'line'=>$line]];
        }
        if($kind==='transfer'){
            $effect=(new FundingRepository($this->db))->effect($supplier,$id);if(!$effect['operation']['active']||!in_array($effect['account_code'],['955.100','324.200'],true)||bccomp($effect['amount_cents'],'0',0)<=0||$effect['operation']['accounting_date']>$date||PropertyInstant::normalize(str_replace(' ','T',$effect['operation']['effective_at']).'Z')>$effectiveAt)FundingException::reject('advance_source');
            return['pointer'=>$pointer,'unit_id'=>$effect['unit_id'],'account_code'=>$effect['account_code'],'amount_cents'=>$effect['amount_cents'],'date'=>$effect['operation']['accounting_date'],'purpose_ref'=>$effect['operation']['request']['purpose_ref'],'proof'=>$effect];
        }
        FundingException::reject('advance_source');
    }
    public function cost(int $supplier,int $line,string $date,string $classification):array
    {
        $row=$this->one('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? AND id=? FOR UPDATE',[$supplier,$line]);$ledger=(new PaymentJournalProof($this->db))->current($supplier,(int)$row['entry_id']);$lines=array_column($ledger['lines'],null,'id');$source=$lines[$line]??throw new FundingException('property_funding_cost_source');
        $role=match($classification){'repair'=>'svj.common_part.repair','improvement'=>'svj.common_part.improvement','administration'=>'svj.management.cost',default=>throw new FundingException('property_funding_classification')};
        $expected=\MyInvoice\Service\Accounting\AccountRoleResolver::fromConnection($this->db)->resolve($supplier,$role,$date);
        if($ledger['entry']['reversed_by']!==null||$ledger['entry']['entry_date']>$date||!in_array($ledger['entry']['source_type'],['purchase_invoice','manual'],true)||$source['side']!=='debit'||$source['account_code']!==$expected||$source['is_red_storno']||$source['currency_code']!==null||$source['amount_foreign']!==null||$source['fx_rate']!==null||bccomp($source['amount'],'0',2)<=0)FundingException::reject('cost_source');
        $q=$this->db->pdo()->prepare('SELECT line_id FROM joint_allocation_source_claims WHERE supplier_id=? AND line_id=? FOR UPDATE');$q->execute([$supplier,$line]);if($q->fetchColumn()!==false)FundingException::reject('cost_already_allocated');
        try{$coverage=(new \MyInvoice\Service\Accounting\Funding\NativeCostCoverage($this->db))->financingCost($supplier,$line);}catch(\MyInvoice\Service\Accounting\PostingException){FundingException::reject('cost_coverage_scope');}
        if(!$coverage['eligible'])FundingException::reject('cost_source');$covered=(string)$coverage['covered_cents'];
        return ['line'=>$source,'ledger'=>$ledger,'amount_cents'=>bcmul($source['amount'],'100',0),'direct_coverage_cents'=>$covered];
    }
    public function coverage(int $supplier,int $line,string $date):array
    {
        try{$proof=(new \MyInvoice\Service\Accounting\Funding\NativeCostCoverage($this->db))->income($supplier,$line);}catch(\MyInvoice\Service\Accounting\PostingException){FundingException::reject('coverage_source');}
        $source=$proof['line'];$ledger=$proof['ledger'];
        if($ledger['entry']['entry_date']>$date||$source['is_red_storno']||$source['currency_code']!==null||$source['amount_foreign']!==null||$source['fx_rate']!==null)FundingException::reject('coverage_source');
        return ['line'=>$source,'ledger'=>$ledger,'amount_cents'=>(string)$proof['amount_cents']];
    }
    public function assertPostedBank(int $supplier,array $facts,array $request):void
    {
        $raw=(new \MyInvoice\Service\Bank\BankTransactionContext($this->db))->lock($supplier,$request['bank_id']);if($raw===null||PropertyInput::hash($raw)!==PropertyInput::hash($facts['raw']))FundingException::reject('source_changed');$amount=\MyInvoice\Service\Accounting\JournalLineAmount::decimal($raw['amount']);$direction=bccomp($amount,'0',2)>0?'in':'out';$cents=bcmul(ltrim($amount,'-'),'100',0);if($facts['id']!==$request['bank_id']||$facts['kind']!=='bank'||$facts['date']!==$request['accounting_date']||$facts['date']!==$raw['posted_at']||$facts['amount_cents']!==$cents||$request['amount_cents']!==$cents||$facts['direction']!==$direction||$direction!==($request['kind']==='income'?'in':'out')||($raw['currency']?:$raw['statement_currency'])!=='CZK')FundingException::reject('bank_source');
        $container=\MyInvoice\Bootstrap::buildContainer();$container->set(Connection::class,$this->db);$account=$container->get(\MyInvoice\Service\Accounting\Bank\BankAnalyticResolver::class)->existingAnalyticCodeFor($supplier,$raw,true)??'221';if($facts['money_account']!==$account||!str_starts_with($account,'221'))FundingException::reject('bank_source');$q=$this->db->pdo()->prepare('SELECT id,account_code,account_type FROM chart_of_accounts WHERE supplier_id=? AND account_code=? FOR UPDATE');$q->execute([$supplier,$account]);$current=$q->fetch(PDO::FETCH_ASSOC);$saved=$facts['money_account_receipt'];unset($saved['is_active']);if(!$current||PropertyInput::hash($current)!==PropertyInput::hash($saved))FundingException::reject('bank_source');
    }
    public function bank(int $supplier,int $id,string $date,string $direction):array
    {
        $container=\MyInvoice\Bootstrap::buildContainer();$container->set(Connection::class,$this->db);$facts=$container->get(\MyInvoice\Service\Property\Payments\Workflow\PaymentSourceEligibility::class)->current($supplier,['kind'=>'bank','id'=>$id]);
        if($facts['existing']!==null||$facts['date']!==$date||$facts['direction']!==$direction)FundingException::reject('bank_source');
        FundingUseGuard::assertSourceUnclaimed($this->db,$supplier,'bank',$id);return $facts;
    }
}
