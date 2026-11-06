<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Capital;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AssetRepository;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;
final class CapitalService
{
    private readonly CapitalRepository $owner;
    private readonly CapitalLedger $ledger;
    /** Zamykací čtení; jen přehled {@see read()} ho vypíná. */
    private bool $lock=true;
    /** Paměť ověření historie v rámci jednoho {@see canonicalEvents()}; mimo něj null. */
    private ?array $memo=null;
    public function __construct(private readonly Connection $db,private readonly ?PostingService $posting=null)
    {$this->owner=new CapitalRepository($db);$this->ledger=new CapitalLedger($db);}
    public function hasAssetSources(int $supplier,int $asset):bool
    {
        $q=$this->db->pdo()->prepare("SELECT id FROM nonprofit_capital_sources WHERE supplier_id=? AND asset_id=? AND kind='investment' LIMIT 1".($this->db->pdo()->inTransaction()?' FOR UPDATE':''));$q->execute([$supplier,$asset]);return $q->fetchColumn()!==false;
    }
    public function previewSource(int $supplier,array $policy):array
    {
        $policy=CapitalPolicy::normalize($policy);return $this->transaction($supplier,function()use($supplier,$policy):array{
            $basis=$this->registration($supplier,$policy);return ['policy'=>$policy,'basis'=>$basis,'input_hash'=>CapitalPolicy::hash(['policy'=>$policy,'basis'=>$basis]),'financial_authorization'=>true];
        });
    }
    public function confirmSource(int $supplier,array $policy,string $inputHash,string $reason,string $token,int $actor):array
    {
        $policy=CapitalPolicy::normalize($policy);$this->mutation($reason,$token,$actor);
        $request=['action'=>'register','policy'=>$policy,'input_hash'=>$inputHash,'reason'=>$reason,'actor'=>$actor];
        return $this->transaction($supplier,function()use($supplier,$policy,$inputHash,$token,$actor,$request):array{
            if($old=$this->owner->byToken($supplier,$token))return $this->retry($supplier,$old,$request);
            $basis=$this->registration($supplier,$policy);if(!hash_equals(CapitalPolicy::hash(['policy'=>$policy,'basis'=>$basis]),$inputHash))CapitalPolicy::changed();
            $command=$this->owner->appendCommand($supplier,$token,$request,$actor);
            $stored=$this->owner->appendSource(['supplier_id'=>$supplier,'command_id'=>$command,'source_line_id'=>$policy['source_line_id'],'asset_id'=>$policy['asset_id'],'provider_id'=>$policy['provider_id'],'kind'=>$policy['kind'],'amount_cents'=>$basis['source']['amount_cents'],'policy'=>$policy,'basis'=>$basis]);return $this->owner->sources($supplier)[$stored['id']];
        });
    }
    public function previewFund(int $supplier,int $source,int $cost,int $amount,string $date):array
    {
        $intent=['action'=>'release','source_id'=>$source,'cost_line_id'=>$cost,'depreciation_id'=>null,'amount_cents'=>$amount,'date'=>$date,'original_id'=>null,'closing'=>false];
        return $this->transaction($supplier,fn()=>['intent'=>$intent,'plan'=>$this->plan($supplier,$intent),'input_hash'=>CapitalPolicy::hash($this->plan($supplier,$intent)),'financial_authorization'=>true]);
    }
    public function confirmFund(int $supplier,int $source,int $cost,int $amount,string $date,string $inputHash,string $reason,string $token,int $actor):array
    {
        $this->mutation($reason,$token,$actor);$intent=['action'=>'release','source_id'=>$source,'cost_line_id'=>$cost,'depreciation_id'=>null,'amount_cents'=>$amount,'date'=>$date,'original_id'=>null,'closing'=>false];
        return $this->transaction($supplier,fn()=>$this->execute($supplier,$intent,$inputHash,$reason,$token,$actor));
    }
    public function previewReverse(int $supplier,int $event,string $date):array
    {
        return $this->transaction($supplier,function()use($supplier,$event,$date):array{
            $original=$this->canonicalEvents($supplier)[$event]??null;if(!$original||!$original['active'])CapitalPolicy::changed();
            $intent=['action'=>'reverse','source_id'=>$original['source_id'],'cost_line_id'=>null,'depreciation_id'=>null,'amount_cents'=>$original['amount_cents'],'date'=>$date,'original_id'=>$event,'closing'=>false];$plan=$this->plan($supplier,$intent);
            return ['intent'=>$intent,'plan'=>$plan,'input_hash'=>CapitalPolicy::hash($plan),'financial_authorization'=>true];
        });
    }
    public function confirmReverse(int $supplier,int $event,string $date,string $inputHash,string $reason,string $token,int $actor):array
    {
        $this->mutation($reason,$token,$actor);
        return $this->transaction($supplier,function()use($supplier,$event,$date,$inputHash,$reason,$token,$actor):array{
            $original=$this->owner->events($supplier)[$event]??null;if(!$original)CapitalPolicy::changed();
            $intent=['action'=>'reverse','source_id'=>$original['source_id'],'cost_line_id'=>null,'depreciation_id'=>null,'amount_cents'=>$original['amount_cents'],'date'=>$date,'original_id'=>$event,'closing'=>false];
            return $this->execute($supplier,$intent,$inputHash,$reason,$token,$actor);
        });
    }
    public function onDepreciation(int $supplier,int $asset,int $depreciation,bool $closing,?int $actor):array
    {
        return $this->transaction($supplier,function()use($supplier,$asset,$depreciation,$closing,$actor):array{
            if(!$this->hasAssetSources($supplier,$asset))return [];
            $sources=$this->owner->sources($supplier);$events=$this->canonicalEvents($supplier);$results=[];
            foreach($sources as$source){
                if($source['kind']!=='investment'||$source['asset_id']!==$asset)continue;$existing=null;$last=0;
                foreach($events as$event)if($event['source_id']===$source['id']){$last=$event['id'];if($event['active']&&$event['depreciation_id']===$depreciation)$existing=$event;}
                if($existing!==null){$proof=$this->depreciation($supplier,$source,$depreciation,false);if(CapitalPolicy::hash($existing['snapshot']['depreciation'])!==CapitalPolicy::hash($proof))CapitalPolicy::changed();$results[]=$existing;continue;}
                $native=$this->depreciation($supplier,$source,$depreciation);$used=$this->used($events,$source['id']);$target=CapitalPolicy::proportional($native['cumulative_cents'],$source['amount_cents'],$source['basis']['asset']['valuation_cents']);$amount=$target-$used;
                if($amount<0)CapitalPolicy::changed();if($amount===0)continue;
                $intent=['action'=>'release','source_id'=>$source['id'],'cost_line_id'=>null,'depreciation_id'=>$depreciation,'amount_cents'=>$amount,'date'=>$native['cost']['date'],'original_id'=>null,'closing'=>$closing];$plan=$this->plan($supplier,$intent);
                $results[]=$this->execute($supplier,$intent,CapitalPolicy::hash($plan),'Automatické poměrné rozpuštění doloženého investičního zdroje','depreciation:'.$depreciation.':'.$source['id'].':'.$last,$actor);
            }
            return $results;
        });
    }
    /**
     * Vyřazení vlastního majetku z investičního zdroje: dosud nerozpuštěný zbytek zdroje
     * se ve stejné transakci zúčtuje 901/649 proti zůstatkové ceně v zápisu vyřazení.
     * Volá vyřazení v modulu majetku až po zaúčtování odpisu roku a zápisu vyřazení.
     */
    public function onDisposal(int $supplier,int $asset,int $disposalEntry,?int $actor):array
    {
        return $this->transaction($supplier,function()use($supplier,$asset,$disposalEntry,$actor):array{
            if(!$this->hasAssetSources($supplier,$asset))return [];
            $events=$this->canonicalEvents($supplier);
            $residual=null;
            $results=[];
            foreach($this->owner->sources($supplier)as$source){
                if($source['kind']!=='investment'||$source['asset_id']!==$asset)continue;
                $remaining=$source['amount_cents']-$this->used($events,$source['id']);
                if($remaining<0)CapitalPolicy::changed();
                if($remaining===0)continue;
                $residual??=$this->disposalResidualLine($supplier,$asset,$disposalEntry);
                $intent=['action'=>'release','source_id'=>$source['id'],'cost_line_id'=>$residual['id'],'depreciation_id'=>null,'amount_cents'=>$remaining,'date'=>$residual['date'],'original_id'=>null,'closing'=>false];
                $plan=$this->plan($supplier,$intent);
                $results[]=$this->execute($supplier,$intent,CapitalPolicy::hash($plan),'Zúčtování zbytku investičního zdroje při vyřazení majetku','disposal:'.$disposalEntry.':'.$source['id'],$actor);
            }
            return $results;
        });
    }
    /**
     * Vrácení vyřazení: řízeně stornuje zúčtování zbytku zdroje k tomuto vyřazení
     * i rozpuštění k odpisu roku vyřazení, který vrácení vyřazení také stornuje.
     */
    public function onDisposalRevert(int $supplier,int $asset,?int $disposalEntry,?int $depreciation,string $date,?int $actor):array
    {
        return $this->transaction($supplier,function()use($supplier,$asset,$disposalEntry,$depreciation,$date,$actor):array{
            if(!$this->hasAssetSources($supplier,$asset))return [];
            $sources=$this->owner->sources($supplier);
            $results=[];
            foreach(array_reverse($this->canonicalEvents($supplier),true)as$event){
                $source=$sources[$event['source_id']];
                if(!$event['active']||$source['kind']!=='investment'||$source['asset_id']!==$asset)continue;
                $ofDisposal=$disposalEntry!==null&&$event['depreciation_id']===null&&$event['snapshot']['cost']['entry_id']===$disposalEntry;
                $ofDisposalYear=$depreciation!==null&&$event['depreciation_id']===$depreciation;
                if(!$ofDisposal&&!$ofDisposalYear)continue;
                $intent=['action'=>'reverse','source_id'=>$event['source_id'],'cost_line_id'=>null,'depreciation_id'=>null,'amount_cents'=>$event['amount_cents'],'date'=>$date,'original_id'=>$event['id'],'closing'=>false];
                $plan=$this->plan($supplier,$intent);
                $results[]=$this->execute($supplier,$intent,CapitalPolicy::hash($plan),'Storno čerpání investičního zdroje při vrácení vyřazení majetku','disposal-revert:'.$event['id'],$actor);
            }
            return $results;
        });
    }
    /** Řádek odpisu, na který odkazuje neměnná kapitálová historie, nelze fyzicky smazat. */
    public function retainsDepreciation(int $supplier,int $depreciation):bool
    {
        $q=$this->db->pdo()->prepare('SELECT 1 FROM nonprofit_capital_events WHERE supplier_id=? AND depreciation_id=? LIMIT 1');
        $q->execute([$supplier,$depreciation]);
        return $q->fetchColumn()!==false;
    }
    /** Investiční zdroj s nerozpuštěným zbytkem, který vyřazení musí zúčtovat. */
    public function hasRemainingAssetSource(int $supplier,int $asset):bool
    {
        return $this->transaction($supplier,function()use($supplier,$asset):bool{
            if(!$this->hasAssetSources($supplier,$asset))return false;
            $events=$this->canonicalEvents($supplier);
            foreach($this->owner->sources($supplier)as$source)if($source['kind']==='investment'&&$source['asset_id']===$asset&&$source['amount_cents']>$this->used($events,$source['id']))return true;
            return false;
        });
    }
    private function disposalResidualLine(int $supplier,int $asset,int $entry):array
    {
        $found=null;
        foreach($this->ledger->entry($supplier,$entry)as$line){
            if($line['source_type']!=='asset_disposal'||$line['source_id']!==$asset)CapitalPolicy::changed();
            if($line['side']!=='debit'||$line['account_type']!=='expense'||!str_starts_with($line['account_code'],'5'))continue;
            if($found!==null)CapitalPolicy::changed();
            $found=$line;
        }
        if($found===null)CapitalPolicy::invalid('Vyřazení majetku s nerozpuštěným investičním zdrojem vyžaduje zaúčtovanou zůstatkovou cenu.','nonprofit_capital_disposal_residual_missing');
        return $found;
    }
    private function disposalCost(int $supplier,array $source,int $line):array
    {
        $cost=$this->ledger->cost($supplier,$line,$this->lock);
        if($cost['source_type']!=='asset_disposal'||$cost['source_id']!==$source['asset_id'])CapitalPolicy::invalid('Zbytek investičního zdroje se zúčtuje jen proti zůstatkové ceně ve vyřazení vlastního majetku.');
        return $cost;
    }
    private function execute(int $supplier,array $intent,string $inputHash,string $reason,string $token,?int $actor):array
    {
        $base=['action'=>$intent['action'],'source_id'=>$intent['source_id'],'intent'=>$intent,'input_hash'=>$inputHash,'reason'=>$reason,'actor'=>$actor];
        if($old=$this->owner->byToken($supplier,$token)){
            $comparison=$old['request'];unset($comparison['plan']);if(CapitalPolicy::hash($comparison)!==CapitalPolicy::hash($base))throw new CapitalException('nonprofit_capital_idempotency_conflict','Klíč požadavku patří jiným podkladům.',409);
            return $this->retry($supplier,$old,$old['request']);
        }
        $plan=$this->plan($supplier,$intent);if(!hash_equals(CapitalPolicy::hash($plan),$inputHash))CapitalPolicy::changed();$request=$base+['plan'=>$plan];
        $command=$this->owner->appendCommand($supplier,$token,$request,$actor);
        $journal=new \MyInvoice\Repository\JournalEntryRepository($this->db,new \MyInvoice\Service\ActivityLogger($this->db));
        $journal->insertNonprofitCapital(CapitalJournalProof::fromPending($this->db,$this,$supplier,$command));
        foreach($this->canonicalEvents($supplier)as$event)if($event['command_id']===$command)return $event;
        CapitalPolicy::changed();
    }
    public function pending(Connection $db,int $supplier,int $command):array
    {
        if($db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction())throw new \LogicException('Capital pending proof requires its live owner connection.');
        $saved=$this->owner->command($supplier,$command);$request=$saved['request'];
        foreach($this->owner->events($supplier)as$event)if($event['command_id']===$command)CapitalPolicy::changed();
        if(!in_array($request['action'],['release','reverse'],true)||!isset($request['intent'],$request['plan'])||CapitalPolicy::hash($this->plan($supplier,$request['intent']))!==CapitalPolicy::hash($request['plan'])||CapitalPolicy::hash($request['plan'])!==$request['input_hash'])CapitalPolicy::changed();
        $journal=$request['plan']['journal'];$journal['header']['posted_at']=$saved['created_at'];$journal['header']['posted_by']=$saved['created_by'];$journal['header']['description']=$request['reason'];$journal['header']['source_id']=$journal['source_entry_id']===null?$command:null;
        return ['request'=>$request,'plan'=>$request['plan'],'journal'=>$journal];
    }
    public function complete(Connection $db,int $supplier,int $command,int $entry):void
    {
        if($db->pdo()!==$this->db->pdo()||!$db->pdo()->inTransaction())throw new \LogicException('Capital completion requires its owner connection.');
        $saved=$this->owner->command($supplier,$command);$request=$saved['request'];$plan=$request['plan'];$native=(new PaymentJournalProof($db))->current($supplier,$entry);$intent=$request['intent'];
        if(CapitalPolicy::hash(PaymentJournalProof::canonicalLines($native['lines']))!==CapitalPolicy::hash(PaymentJournalProof::canonicalLines($plan['journal']['lines']))||$native['entry']['period_id']!==$plan['journal']['header']['period_id']||$native['entry']['entry_date']!==$intent['date']||$native['entry']['source_type']!=='nonprofit_capital'||$native['entry']['source_id']!==($plan['journal']['source_entry_id']===null?$command:null)||$native['entry']['reversed_by']!==null)CapitalPolicy::changed();
        $header=$plan['journal']['header'];$header['posted_by']=$saved['created_by'];$header['description']=$request['reason'];$header['source_id']=$plan['journal']['source_entry_id']===null?$command:null;$q=$db->pdo()->prepare("SELECT supplier_id,period_id,entry_date,document_date,document_no,description,source_type,source_id,posted_by,DATE_FORMAT(posted_at,'%Y-%m-%d %H:%i:%s') posted_at FROM journal_entries WHERE supplier_id=? AND id=? FOR UPDATE");$q->execute([$supplier,$entry]);$actual=$q->fetch(PDO::FETCH_ASSOC);if(!$actual||$actual['posted_at']!==substr($saved['created_at'],0,19))CapitalPolicy::changed();foreach($header as$key=>$value)if((string)($actual[$key]??'')!==(string)($value??''))CapitalPolicy::changed();
        if($intent['action']==='reverse'){
            $original=$plan['original'];$current=(new PaymentJournalProof($db))->current($supplier,$original['entry_id']);if($current['entry']['reversed_by']!==$entry)CapitalPolicy::changed();
        }
        $events=$this->owner->events($supplier);$previous=null;foreach($events as$event)if($event['source_id']===$intent['source_id'])$previous=$event;
        $this->owner->appendEvent(['supplier_id'=>$supplier,'source_id'=>$intent['source_id'],'command_id'=>$command,'previous_id'=>$previous['id']??null,'original_id'=>$intent['original_id'],'parent_hash'=>$previous['record_hash']??str_repeat('0',64),'operation'=>$intent['action'],'amount_cents'=>$intent['amount_cents'],'entry_id'=>$entry,'depreciation_id'=>$intent['depreciation_id'],'cost_line_id'=>$intent['cost_line_id'],'snapshot'=>$plan,'receipt'=>$native]);
    }
    private function plan(int $supplier,array $intent):array
    {
        CapitalPolicy::date($intent['date']);if(!is_int($intent['amount_cents'])||$intent['amount_cents']<=0)CapitalPolicy::invalid('Čerpání musí být kladná celočíselná částka v haléřích.');
        $period=$this->open($supplier,$intent['date'],$intent['closing']);$this->framework($supplier,$intent['date']);$sources=$this->owner->sources($supplier);$source=$sources[$intent['source_id']]??null;if(!$source)CapitalPolicy::changed();$this->assertSource($supplier,$source);$events=$this->canonicalEvents($supplier);$original=null;$dep=null;$cost=null;$claimed='0';$capacity=null;
        if($intent['action']==='reverse'){
            $original=$events[$intent['original_id']]??null;if(!$original||!$original['active']||$original['source_id']!==$source['id']||$original['amount_cents']!==$intent['amount_cents']||$intent['date']<$original['receipt']['entry']['entry_date']||$period!==$original['receipt']['entry']['period_id'])CapitalPolicy::changed();
            $lines=array_map(JournalLineAmount::reversal(...),$original['receipt']['lines']);foreach($lines as&$line){unset($line['id']);$q=$this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code=? FOR UPDATE');$q->execute([$supplier,$line['account_code']]);$account=$q->fetchColumn();if($account===false)CapitalPolicy::changed();$line['account_id']=(int)$account;}unset($line);$sourceEntry=$original['entry_id'];
        }else{
            $disposal=$source['kind']==='investment'&&$intent['depreciation_id']===null&&$intent['cost_line_id']!==null;
            if($disposal){
                $cost=$this->disposalCost($supplier,$source,$intent['cost_line_id']);
                $expected=$source['amount_cents']-$this->used($events,$source['id']);
                if($intent['amount_cents']!==$expected||$intent['date']!==$cost['date'])CapitalPolicy::changed();
            }elseif($source['kind']==='investment'){
                if($intent['depreciation_id']===null||$intent['cost_line_id']!==null)CapitalPolicy::invalid('Investiční zdroj se rozpouští výhradně se skutečným odpisem.');
                $dep=$this->depreciation($supplier,$source,$intent['depreciation_id']);$cost=$dep['cost'];$expected=CapitalPolicy::proportional($dep['cumulative_cents'],$source['amount_cents'],$source['basis']['asset']['valuation_cents'])-$this->used($events,$source['id']);
                $previousTarget=CapitalPolicy::proportional($dep['cumulative_cents']-$cost['amount_cents'],$source['amount_cents'],$source['basis']['asset']['valuation_cents']);if($this->used($events,$source['id'])!==$previousTarget)CapitalPolicy::invalid('Dřívější investiční čerpání musí být nejprve obnoveno ke skutečnému původnímu odpisu.','nonprofit_capital_depreciation_sequence');
                if($intent['amount_cents']!==$expected||$intent['date']!==$cost['date'])CapitalPolicy::changed();
            }else{
                if($intent['depreciation_id']!==null||$intent['cost_line_id']===null)CapitalPolicy::invalid('Skutečný fond vyžaduje doložený náklad.');
                $cost=$this->ledger->cost($supplier,$intent['cost_line_id']);if($cost['entry_id']===$source['basis']['source']['entry_id'])CapitalPolicy::invalid('Náklad tvořící vlastní zdroj není jeho použitím.');if($intent['date']<$cost['date'])CapitalPolicy::invalid('Čerpání nemůže předcházet nákladu.');
                if($period!==$cost['period_id'])CapitalPolicy::invalid('Čerpání fondu musí patřit do účetního období skutečného nákladu.');
                $direct=(new \MyInvoice\Service\Accounting\Funding\NativeCostCoverage($this->db))->forCost($supplier,$cost['id']);$cost['direct_coverage']=$direct;if(!$direct['eligible'])CapitalPolicy::invalid('Opravený přijatý náklad vyžaduje samostatný kanonický podklad oprav.');
            }
            $capacity=(new \MyInvoice\Service\Accounting\Funding\CostCoverage($this->db))->capacity($supplier,$cost['id']);$claimed=$capacity['claimed_cents'];
            if(bccomp((string)$intent['amount_cents'],(string)$capacity['available_cents'],0)>0)CapitalPolicy::invalid('Čerpání převyšuje dosud nekrytou část skutečného nákladu.','nonprofit_capital_cost_overcovered');
            if($intent['date']<$source['basis']['source']['date'])CapitalPolicy::invalid('Čerpání nemůže předcházet doloženému vzniku vlastního zdroje.');
            // Vyřazením majetek zaniká, proto se zbytek zdroje zúčtuje bez ohledu na
            // způsobilé účty a období čerpání sjednané pro roční odpisy.
            if(!$disposal)CapitalPolicy::assertCost($source['policy'],$cost);
            if($this->used($events,$source['id'])+$intent['amount_cents']>$source['amount_cents'])CapitalPolicy::invalid('Čerpání převyšuje doložený zůstatek zdroje.','nonprofit_capital_source_overdrawn');
            $dims=$cost['dimensions'];$q=$this->db->pdo()->prepare("SELECT id FROM dimension_types WHERE supplier_id=? AND system_key='tax_regime' FOR UPDATE");$q->execute([$supplier]);$taxType=$q->fetchColumn();if($taxType===false)CapitalPolicy::changed();$dims[(int)$taxType]=$source['policy']['revenue_tax_regime_id'];ksort($dims,SORT_NUMERIC);
            foreach($source['basis']['source']['dimensions']as$type=>$value)if((int)$type!==(int)$taxType&&($dims[$type]??null)!==$value)CapitalPolicy::invalid('Náklad neodpovídá doloženým dimenzím vlastního zdroje.');
            $amount=bcdiv((string)$intent['amount_cents'],'100',2);$metadata=['dimensions'=>$dims,'dimension_origins'=>array_fill_keys(array_keys($dims),['origin'=>'module','ref'=>'nonprofit-capital:'.$source['id']]),'cost_center'=>$cost['cost_center'],'project_id'=>$cost['project_id']];
            // Výnosová strana nemá v rolích účetního rámce vlastní roli: 649 je referenční
            // syntetika položky B.IV.10 pro investiční zdroj (§ 38 odst. 10 písm. c) vyhl. 504),
            // 648 zúčtování fondů. Shodné prefixy drží NativeCostCoverage::REVENUE_PREFIXES.
            $lines=[['account_code'=>$source['basis']['source']['account_code'],'side'=>'debit','amount'=>$amount]+$metadata,['account_code'=>$source['kind']==='investment'?'649':'648','side'=>'credit','amount'=>$amount]+$metadata];
            $lines=($this->posting??throw new \LogicException('Capital posting requires its native posting service.'))->prepareDocumentLines($supplier,'nonprofit_capital',null,$lines,$intent['date']);$q=$this->db->pdo()->prepare('SELECT id,account_code FROM chart_of_accounts WHERE supplier_id=? FOR UPDATE');$q->execute([$supplier]);$codes=array_column($q->fetchAll(\PDO::FETCH_ASSOC),'account_code','id');foreach($lines as&$line){$line['account_code']=$codes[$line['account_id']]??throw new \LogicException('Resolved capital account is missing.');}unset($line);$sourceEntry=null;
        }
        return ['source'=>$source,'source_used_cents'=>$this->used($events,$source['id']),'previous_event_id'=>$this->lastEvent($events,$source['id']),'depreciation'=>$dep,'cost'=>$cost,'claimed_cost_cents'=>$claimed,'cost_capacity_hash'=>$capacity===null?null:CapitalPolicy::hash($capacity),'cost_capacity'=>$capacity===null?null:array_intersect_key($capacity,array_flip(['cost_cents','direct_cents','claimed_cents','available_cents'])),'original'=>$original,'journal'=>['header'=>['supplier_id'=>$supplier,'period_id'=>$period,'entry_date'=>$intent['date'],'document_date'=>$intent['date'],'document_no'=>null,'description'=>null,'source_type'=>'nonprofit_capital','source_id'=>null],'lines'=>$lines,'source_entry_id'=>$sourceEntry]];
    }
    public function depreciationProof(int $supplier,array $source,int $depreciation):array{return $this->depreciation($supplier,$source,$depreciation,false);}
    private function depreciation(int $supplier,array $source,int $depreciation,bool $latest=true):array
    {
        $rows=$this->depreciationRows($supplier,$source['asset_id']);
        $row=$rows[$depreciation]??null;
        if($row===null)CapitalPolicy::changed();
        $history=[];$cumulative=0;$cost=null;
        foreach($rows as$year){
            if($year['fiscal_year']>$row['fiscal_year']){if($latest)CapitalPolicy::invalid('Investiční odpisy se zpracovávají chronologicky.');continue;}
            [$native,$nativeCost]=$this->depreciationYear($supplier,$year);
            $cumulative+=$year['amount_cents'];$history[]=$native;if($year['id']===$depreciation)$cost=$nativeCost;
        }
        if($cost===null||$cumulative>$source['basis']['asset']['valuation_cents'])CapitalPolicy::changed();
        return ['depreciation_id'=>$depreciation,'asset_id'=>$source['asset_id'],'fiscal_year'=>$row['fiscal_year'],'cumulative_cents'=>$cumulative,'history'=>$history,'cost'=>$cost];
    }
    /** Zaúčtované účetní odpisy majetku jedním dotazem; v rámci ověření historie se čtou jednou. */
    private function depreciationRows(int $supplier,int $asset):array
    {
        if($this->memo!==null&&isset($this->memo['rows'][$asset]))return $this->memo['rows'][$asset];
        $q=$this->db->pdo()->prepare("SELECT id,amount,fiscal_year FROM depreciation_entries WHERE supplier_id=? AND asset_id=? AND kind='accounting' AND status='posted' ORDER BY fiscal_year,id".$this->forUpdate());
        $q->execute([$supplier,$asset]);
        $rows=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row)$rows[(int)$row['id']]=['id'=>(int)$row['id'],'fiscal_year'=>(int)$row['fiscal_year'],'amount_cents'=>JournalLineAmount::cents($row['amount'])];
        if($this->memo!==null)$this->memo['rows'][$asset]=$rows;
        return $rows;
    }
    /** Nativní zápis jednoho ročního odpisu a jeho nákladový řádek 55x. */
    private function depreciationYear(int $supplier,array $year):array
    {
        if($this->memo!==null&&isset($this->memo['years'][$year['id']]))return $this->memo['years'][$year['id']];
        $find=$this->db->pdo()->prepare("SELECT id FROM journal_entries WHERE supplier_id=? AND source_type='depreciation' AND source_id=? AND posted_at IS NOT NULL AND reversed_by IS NULL".$this->forUpdate());
        $find->execute([$supplier,$year['id']]);
        $ids=$find->fetchAll(PDO::FETCH_COLUMN);
        if(count($ids)!==1)CapitalPolicy::changed();
        $native=$this->journalProof($supplier,(int)$ids[0]);
        if(count($native['lines'])!==2||$year['amount_cents']<=0)CapitalPolicy::changed();
        $nativeCost=null;
        foreach($native['lines']as$line)if($line['side']==='debit'&&str_starts_with($line['account_code'],'55')&&JournalLineAmount::signedCents($line)===$year['amount_cents'])$nativeCost=$this->ledger->cost($supplier,$line['id'],$this->lock);
        if($nativeCost===null)CapitalPolicy::changed();
        if($this->memo!==null)$this->memo['years'][$year['id']]=[$native,$nativeCost];
        return [$native,$nativeCost];
    }
    private function journalProof(int $supplier,int $entry):array
    {
        $proof=new PaymentJournalProof($this->db);
        return $this->lock?$proof->current($supplier,$entry):$proof->snapshot($supplier,$entry);
    }
    private function forUpdate():string{return $this->lock?' FOR UPDATE':'';}
    private function used(array $events,int $source):int
    {
        $used=0;foreach($events as$event)if($event['source_id']===$source)$used+=($event['operation']==='release'?1:-1)*$event['amount_cents'];return $used;
    }
    private function lastEvent(array $events,int $source):?int
    {
        $last=null;foreach($events as$event)if($event['source_id']===$source)$last=$event['id'];return $last;
    }
    private function registration(int $supplier,array $policy):array
    {
        $source=$this->ledger->source($supplier,$policy);$this->framework($supplier,$source['date']);$this->open($supplier,$policy['valid_from'],false);
        $sources=$this->owner->sources($supplier);foreach($sources as$row)if($row['source_line_id']===$policy['source_line_id'])CapitalPolicy::invalid('Účetní řádek již má vlastní zdroj.');
        $provider=null;
        if($policy['provider_id']!==null){$q=$this->db->pdo()->prepare('SELECT id,company_name,first_name,last_name,ic,dic,street,city,zip,country_id FROM clients WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$policy['provider_id']]);$provider=$q->fetch(PDO::FETCH_ASSOC);if(!$provider)CapitalPolicy::invalid('Poskytovatel není součástí této firmy.');foreach(['id','country_id']as$key)$provider[$key]=$provider[$key]===null?null:(int)$provider[$key];}
        $this->eligibility($supplier,$policy);$asset=null;
        if($policy['kind']==='investment'){
            $asset=$this->assetBasis($supplier,$policy['asset_id']);
            $q=$this->db->pdo()->prepare("SELECT id FROM depreciation_entries WHERE supplier_id=? AND asset_id=? AND kind='accounting' FOR UPDATE");$q->execute([$supplier,$policy['asset_id']]);
            if($q->fetchColumn()!==false||$asset['opening_acc_cents']!==0)CapitalPolicy::invalid('Automatický podíl vyžaduje doložení investičního zdroje před prvním účetním odpisem.');
            $total=$source['amount_cents'];foreach($sources as$row)if($row['asset_id']===$policy['asset_id']){$total+=$row['amount_cents'];if(CapitalPolicy::hash($row['basis']['asset'])!==CapitalPolicy::hash($asset))CapitalPolicy::changed();}
            if($total>$asset['valuation_cents'])CapitalPolicy::invalid('Investiční zdroje převyšují ocenění vlastního majetku.');
        }
        return ['source'=>$source,'provider'=>$provider,'asset'=>$asset];
    }
    public function assetBasis(int $supplier,int $id):array
    {
        $repository=new AssetRepository($this->db);
        $asset=$this->lock?$repository->findForUpdate($supplier,$id):$repository->find($supplier,$id);
        if(!$asset||$asset['depreciator_ground']!=='owner'||$asset['accumulated_account_code']===null||!in_array($asset['status'],['draft','in_use','disposed'],true))CapitalPolicy::invalid('Investiční zdroj vyžaduje vlastní odpisovaný majetek.');
        $q=$this->db->pdo()->prepare('SELECT id,completed_on,amount FROM asset_improvements WHERE supplier_id=? AND asset_id=? ORDER BY completed_on,id'.$this->forUpdate());$q->execute([$supplier,$id]);$improvements=[];$valuation=JournalLineAmount::cents($asset['input_price']);
        foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row){$amount=JournalLineAmount::cents($row['amount']);$valuation+=$amount;$improvements[]=['id'=>(int)$row['id'],'date'=>$row['completed_on'],'amount_cents'=>$amount];}
        if($valuation<=0)CapitalPolicy::invalid('Ocenění majetku musí být kladné.');
        return ['id'=>$id,'valuation_cents'=>$valuation,'input_cents'=>JournalLineAmount::cents($asset['input_price']),'opening_acc_cents'=>JournalLineAmount::cents($asset['opening_acc_amount']),'improvements'=>$improvements];
    }
    private function eligibility(int $supplier,array $policy):void
    {
        $q=$this->db->pdo()->prepare("SELECT v.id,t.system_key FROM dimension_values v JOIN dimension_types t ON t.supplier_id=v.supplier_id AND t.id=v.type_id WHERE v.supplier_id=? AND v.is_active=1 FOR UPDATE");$q->execute([$supplier]);$types=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row)$types[(int)$row['id']]=$row['system_key'];
        if(($types[$policy['revenue_tax_regime_id']]??null)!=='tax_regime')CapitalPolicy::invalid('Daňový režim výnosu není aktivní systémovou hodnotou této firmy.');
        foreach($policy['activity_kind_ids']as$id)if(($types[$id]??null)!=='activity_kind')CapitalPolicy::invalid('Druh činnosti není aktivní systémovou hodnotou této firmy.');
        $q=$this->db->pdo()->prepare('SELECT account_code FROM '.\MyInvoice\Repository\AccountDefinitionRepository::relation($policy['valid_from'],$supplier,true)." a WHERE supplier_id=? AND account_type='expense' AND is_active=1 FOR UPDATE");$q->execute([$supplier]);$codes=$q->fetchAll(PDO::FETCH_COLUMN);
        foreach($policy['account_prefixes']as$prefix){$found=false;foreach($codes as$code)if(str_starts_with($code,$prefix))$found=true;if(!$found)CapitalPolicy::invalid('Způsobilý nákladový účet není aktivní osnovou této firmy.');}
    }
    /**
     * Přehled jen čte: jeden konzistentní snímek bez zámků období a řádků, aby GET
     * neblokoval účtování. Ověření historie čte každý roční odpis a zdroj jednou.
     */
    public function read(int $supplier):array
    {
        $pdo=$this->db->pdo();
        $own=!$pdo->inTransaction();
        if($own)$pdo->beginTransaction();
        $this->lock=false;
        try{
            $this->memo=['rows'=>[],'years'=>[],'sources'=>[]];
            $sources=$this->owner->sources($supplier,false);
            $events=$this->canonicalEvents($supplier);
            $out=[];
            foreach($sources as$source){
                $this->assertSource($supplier,$source);
                $used=0;$history=[];
                foreach($events as$event)if($event['source_id']===$source['id']){$used+=($event['operation']==='release'?1:-1)*$event['amount_cents'];$history[]=$event;}
                if($used<0||$used>$source['amount_cents'])CapitalPolicy::changed();
                $out[]=['source'=>$source,'used_cents'=>$used,'available_cents'=>$source['amount_cents']-$used,'events'=>$history];
            }
            if($own)$pdo->commit();
            return ['sources'=>$out];
        }catch(\Throwable $e){
            if($own&&$pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }finally{
            $this->lock=true;
            $this->memo=null;
        }
    }
    public function canonicalEvents(int $supplier):array
    {
        $outer=$this->memo===null;
        if($outer)$this->memo=['rows'=>[],'years'=>[],'sources'=>[]];
        try{return $this->verifiedEvents($supplier);}
        finally{if($outer)$this->memo=null;}
    }
    private function verifiedEvents(int $supplier):array
    {
        $events=$this->owner->events($supplier,$this->lock);$sources=$this->owner->sources($supplier,$this->lock);$reversals=[];
        foreach($events as$event)if($event['original_id']!==null){$original=$events[$event['original_id']]??null;if(!$original||$original['operation']!=='release'||$original['source_id']!==$event['source_id']||$original['amount_cents']!==$event['amount_cents'])CapitalPolicy::changed();$reversals[$event['original_id']]=$event;}
        foreach($events as$event){
            $source=$sources[$event['source_id']]??null;if(!$source)CapitalPolicy::changed();$this->assertSource($supplier,$source);
            if($event['operation']==='release'&&!isset($reversals[$event['id']])){
                if($source['kind']==='investment'&&$event['depreciation_id']===null){
                    if($event['cost_line_id']===null||CapitalPolicy::hash($this->disposalCost($supplier,$source,$event['cost_line_id']))!==CapitalPolicy::hash($event['snapshot']['cost']))CapitalPolicy::changed();
                }
                elseif($source['kind']==='investment'){if(CapitalPolicy::hash($this->depreciation($supplier,$source,$event['depreciation_id'],false))!==CapitalPolicy::hash($event['snapshot']['depreciation']))CapitalPolicy::changed();}
                else{if($event['cost_line_id']===null)CapitalPolicy::changed();$cost=$this->ledger->cost($supplier,$event['cost_line_id'],$this->lock);$cost['direct_coverage']=(new \MyInvoice\Service\Accounting\Funding\NativeCostCoverage($this->db))->forCost($supplier,$event['cost_line_id'],$this->lock);if(CapitalPolicy::hash($cost)!==CapitalPolicy::hash($event['snapshot']['cost']))CapitalPolicy::changed();}
            }
            $native=$this->journalProof($supplier,$event['entry_id']);$expected=$event['receipt'];$expected['entry']['reversed_by']=$reversals[$event['id']]['entry_id']??null;
            if(CapitalPolicy::hash($native)!==CapitalPolicy::hash($expected))CapitalPolicy::changed();
            if($event['operation']==='reverse'){
                $original=$events[$event['original_id']];$mirror=array_map(JournalLineAmount::reversal(...),$original['receipt']['lines']);
                if(CapitalPolicy::hash(PaymentJournalProof::canonicalLines($mirror))!==CapitalPolicy::hash(PaymentJournalProof::canonicalLines($native['lines'])))CapitalPolicy::changed();
            }
            $event['active']=$event['operation']==='release'&&!isset($reversals[$event['id']]);$events[$event['id']]=$event;
        }
        return $events;
    }
    private function assertSource(int $supplier,array $source):void
    {
        if($this->memo!==null&&isset($this->memo['sources'][$source['id']]))return;
        if(CapitalPolicy::hash($this->ledger->source($supplier,$source['policy'],$this->lock))!==CapitalPolicy::hash($source['basis']['source']))CapitalPolicy::changed();
        if($source['kind']==='investment'&&CapitalPolicy::hash($this->assetBasis($supplier,$source['asset_id']))!==CapitalPolicy::hash($source['basis']['asset']))CapitalPolicy::changed();
        if($this->memo!==null)$this->memo['sources'][$source['id']]=true;
    }
    private function framework(int $supplier,string $date):void
    {
        $framework=new AccountingFrameworkService($this->db,new AccountingFrameworkRepository($this->db));
        if($framework->forDateForUpdate($supplier,$date)!==AccountingFrameworkService::CZ_504)CapitalPolicy::invalid('Investiční zdroje a fondy vyžadují účetní rámec504.');
    }
    private function open(int $supplier,string $date,bool $closing):int
    {
        $q=$this->db->pdo()->prepare('SELECT id,status FROM accounting_periods WHERE supplier_id=? AND starts_on<=? AND ends_on>=? ORDER BY id FOR UPDATE');$q->execute([$supplier,$date,$date]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==1||!in_array($rows[0]['status'],$closing?['open','closing']:['open'],true))throw new CapitalException('period_not_open','Operace vyžaduje otevřené účetní období.',409);return (int)$rows[0]['id'];
    }
    private function transaction(int $supplier,callable $work):mixed
    {
        $guard=new DimensionPeriodGuard($this->db);return $guard->transaction(function()use($guard,$supplier,$work){$guard->lockPeriods($supplier);return $work();});
    }
    private function mutation(string $reason,string $token,int $actor):void
    {
        if($actor<=0||trim($reason)===''||mb_strlen($reason)>2000)CapitalPolicy::invalid('Potvrzení vyžaduje oprávněného uživatele a doložený důvod.');CapitalPolicy::key($token);
    }
    private function retry(int $supplier,array $command,array $request):array
    {
        if(!hash_equals($command['request_hash'],CapitalPolicy::hash($request)))throw new CapitalException('nonprofit_capital_idempotency_conflict','Klíč požadavku patří jiným podkladům.',409);
        foreach($this->owner->sources($supplier)as$source)if($source['command_id']===$command['id'])return $source;
        foreach($this->canonicalEvents($supplier)as$event)if($event['command_id']===$command['id'])return $event;
        CapitalPolicy::changed();
    }
}
