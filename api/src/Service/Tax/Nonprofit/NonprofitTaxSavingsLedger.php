<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Funding\NativeCostCoverage;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use MyInvoice\Service\Validation\XmlSchemaValidator;
use PDO;

final class NonprofitTaxSavingsLedger
{
    public function __construct(private readonly Connection $db) {}

    public function sources(int $supplier):array
    {
        $this->locked();$q=$this->db->pdo()->prepare('SELECT id FROM nonprofit_tax_saving_sources WHERE supplier_id=? ORDER BY period_id,id FOR UPDATE');$q->execute([$supplier]);
        return array_map(fn($id)=>$this->source($supplier,(int)$id),$q->fetchAll(PDO::FETCH_COLUMN));
    }

    public function source(int $supplier,int $id):array
    {
        $this->locked();$q=$this->db->pdo()->prepare('SELECT * FROM nonprofit_tax_saving_sources WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new TaxReturnException('nonprofit_tax_saving_not_found','Evidence daňové úspory nebyla nalezena.',404);
        foreach(['id','supplier_id','period_id','snapshot_id','legal_profile_id','reduction_kc','created_by']as$key)$row[$key]=(int)$row[$key];$row['saving_cents']=(string)$row['saving_cents'];
        $row['basis']=json_decode($row['basis_json'],true,flags:JSON_THROW_ON_ERROR);unset($row['basis_json']);
        if(!hash_equals($row['record_hash'],NonprofitTaxRules::hash($this->sourceRecord($row))))$this->changed();
        $final=$this->finalProof($supplier,$row['snapshot_id']);
        if(NonprofitTaxRules::hash($final)!==NonprofitTaxRules::hash($row['basis'])||$row['period_id']!==$final['period']['id']||$row['legal_profile_id']!==$final['legal_profile']['id']||$row['reduction_kc']!==$final['saving']['reduction_kc']||$row['saving_cents']!==bcmul((string)$final['saving']['saving_kc'],'100',0))$this->changed();
        return$row;
    }

    public function events(int $supplier,int $source):array
    {
        $this->locked();$owner=$this->source($supplier,$source);$q=$this->db->pdo()->prepare('SELECT * FROM nonprofit_tax_saving_events WHERE supplier_id=? AND source_id=? ORDER BY id FOR UPDATE');$q->execute([$supplier,$source]);$out=[];$previous=null;$byId=[];$reversed=[];$used='0';$void=false;
        $eventRows=$q->fetchAll(PDO::FETCH_ASSOC);$historicalReversed=array_map('intval',array_column(array_filter($eventRows,static fn($e)=>$e['operation']==='reverse_use'),'original_id'));
        foreach($eventRows as$row){
            foreach(['id','supplier_id','source_id','command_id']as$key)$row[$key]=(int)$row[$key];foreach(['previous_id','original_id','period_id','cost_line_id']as$key)$row[$key]=$row[$key]===null?null:(int)$row[$key];$row['amount_cents']=(string)$row['amount_cents'];
            foreach(['proof','receipt']as$key){$row[$key]=json_decode($row[$key.'_json'],true,flags:JSON_THROW_ON_ERROR);unset($row[$key.'_json']);}
            if(($void&&$row['operation']!=='restore')||$row['previous_id']!==($previous['id']??null)||$row['parent_hash']!==($previous['record_hash']??$owner['record_hash'])||!hash_equals($row['record_hash'],NonprofitTaxRules::hash($this->eventRecord($row))))$this->changed();
            $command=$this->command($supplier,$row['command_id']);
            if(($command['request']['source_id']??null)!==$source||($command['request']['operation']??null)!==$row['operation']||($command['request']['expected_head_hash']??null)!==$row['parent_hash']||($row['receipt']['command_hash']??null)!==$command['request_hash'])$this->changed();
            if($row['operation']==='use'){
                if($row['original_id']!==null||$row['period_id']!==$this->nextPeriod($supplier,$owner)['id']||$row['cost_line_id']===null||bccomp($row['amount_cents'],'0',0)<=0)$this->changed();
                $native=in_array($row['id'],$historicalReversed,true)?($row['proof']['cost_native']??[]):(new NativeCostCoverage($this->db))->financingCost($supplier,$row['cost_line_id']);
                if(!($native['eligible']??false)||NonprofitTaxRules::hash($native)!==NonprofitTaxRules::hash($row['proof']['cost_native']??[]))$this->changed();
                $cost=array_column($native['ledger']['lines'],null,'id')[$row['cost_line_id']]??null;
                if($cost===null||(int)$native['ledger']['entry']['period_id']!==$row['period_id']||bccomp($row['amount_cents'],(string)JournalLineAmount::signedCents($cost),0)>0)$this->changed();
                $input=$command['request']['input']??[];
                if(($input['cost_line_id']??null)!==$row['cost_line_id']||($input['amount_cents']??null)!==$row['amount_cents']||($input['purpose']??null)!==($row['proof']['purpose']??null)||trim((string)($input['evidence']??''))!==($row['proof']['evidence']??null))$this->changed();
                if(!hash_equals($row['proof']['cost_capacity_hash']??'',NonprofitTaxRules::hash($row['proof']['cost_capacity']??[]))||bccomp($row['amount_cents'],(string)($row['proof']['cost_capacity']['available_cents']??'-1'),0)>0)$this->changed();
                $c=\MyInvoice\Bootstrap::buildContainer();$c->set(Connection::class,$this->db);$assessment=$c->get(NonprofitDppoAssessmentSource::class)->historicalView($supplier,(int)($row['proof']['assessment']['id']??0));
                $qualified=array_column($assessment['result']['rows'],null,'line_id')[$row['cost_line_id']]??null;
                if($qualified===null||$qualified['activity']!=='main'||($qualified['decision']['title']??null)!=='nonbusiness_cost'||$assessment['input_hash']!==($row['proof']['assessment']['input_hash']??null)||$assessment['request_hash']!==($row['proof']['assessment']['request_hash']??null)||NonprofitTaxRules::hash($qualified)!==NonprofitTaxRules::hash($row['proof']['assessment']['row']??[]))$this->changed();
                $plan=['operation'=>'use','source_id'=>$source,'source_hash'=>$owner['record_hash'],'head_hash'=>$row['parent_hash'],'period_id'=>$row['period_id'],'cost_line_id'=>$row['cost_line_id'],'amount_cents'=>$row['amount_cents'],'proof'=>$row['proof']];
                if(!hash_equals($command['request']['preview_hash']??'',NonprofitTaxRules::hash($plan)))$this->changed();
                $used=bcadd($used,$row['amount_cents'],0);
            }elseif($row['operation']==='reverse_use'){
                $original=$byId[$row['original_id']]??null;if($original===null||$original['operation']!=='use'||isset($reversed[$original['id']])||$row['amount_cents']!==$original['amount_cents']||$row['cost_line_id']!==$original['cost_line_id']||$row['period_id']!==$original['period_id'])$this->changed();
                $reversed[$original['id']]=true;$used=bcsub($used,$row['amount_cents'],0);
            }elseif($row['operation']==='void'){
                if($used!=='0'||$row['amount_cents']!=='0'||$row['original_id']!==null||$row['cost_line_id']!==null||$row['period_id']!==null)$this->changed();$void=true;
            }elseif($row['operation']==='restore'){
                if(!$void||$used!=='0'||$row['amount_cents']!=='0'||$row['original_id']!==null||$row['cost_line_id']!==null||$row['period_id']!==null)$this->changed();$void=false;
            }else $this->changed();
            if(bccomp($used,$owner['saving_cents'],0)>0||bccomp($used,'0',0)<0)$this->changed();
            $out[]=$row;$byId[$row['id']]=$row;$previous=$row;
        }
        $expected=[];foreach($out as$event)if($event['operation']==='use'&&!isset($reversed[$event['id']]))$expected[]=['source_id'=>$source,'event_id'=>$event['id'],'cost_line_id'=>$event['cost_line_id']];
        $q=$this->db->pdo()->prepare('SELECT source_id,event_id,cost_line_id FROM nonprofit_tax_saving_cost_bindings WHERE supplier_id=? AND source_id=? ORDER BY event_id FOR UPDATE');$q->execute([$supplier,$source]);$bindings=$q->fetchAll(PDO::FETCH_ASSOC);foreach($bindings as&$binding)foreach($binding as&$value)$value=(int)$value;unset($binding,$value);
        if($bindings!==$expected)$this->changed();
        return$out;
    }

    public function costClaims(int $supplier,int $line):array
    {
        return(new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$line):array{
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplier);$q=$this->db->pdo()->prepare("SELECT DISTINCT source_id FROM nonprofit_tax_saving_events WHERE supplier_id=? AND cost_line_id=? ORDER BY source_id FOR UPDATE");$q->execute([$supplier,$line]);$claims=[];
            foreach($q->fetchAll(PDO::FETCH_COLUMN)as$id){$source=$this->source($supplier,(int)$id);$events=$this->events($supplier,(int)$id);$reversed=array_column(array_filter($events,static fn($e)=>$e['operation']==='reverse_use'),'original_id');
                if($this->isVoid($events))continue;$this->assertFinalCurrent($supplier,$source);
                foreach($events as$event)if($event['operation']==='use'&&$event['cost_line_id']===$line&&!in_array($event['id'],$reversed,true))$claims[]=['source_id'=>$source['id'],'event_id'=>$event['id'],'cost_line_id'=>$line,'period_id'=>$event['period_id'],'amount_cents'=>$event['amount_cents'],'source_hash'=>$source['record_hash'],'event_hash'=>$event['record_hash'],'command_hash'=>$event['receipt']['command_hash']];
            }
            return$claims;
        });
    }

    public function due(int $supplier,int $period):array
    {
        $this->locked();$current=(new AccountingPeriodRepository($this->db))->findById($supplier,$period)??throw new TaxReturnException('nonprofit_tax_saving_period_not_found','Účetní období nebylo nalezeno.',404);$items=[];$total=0;
        foreach($this->sources($supplier)as$source){if($source['basis']['period']['ends_on']>=$current['starts_on'])continue;$next=$this->nextPeriod($supplier,$source);if($next['id']!==$period)continue;
            $events=$this->events($supplier,$source['id']);if($this->isVoid($events))continue;$this->assertFinalCurrent($supplier,$source);$used='0';foreach($events as$event)$used=bcadd($used,$event['operation']==='use'?$event['amount_cents']:bcsub('0',$event['amount_cents'],0),0);
            $addback=NonprofitDppoReduction::unusedAddback($source['reduction_kc'],(int)$source['saving_cents'],(int)$used);$total+=$addback;
            $items[]=['source_id'=>$source['id'],'source_hash'=>$source['record_hash'],'period_id'=>$period,'saving_cents'=>$source['saving_cents'],'used_cents'=>$used,'reduction_kc'=>$source['reduction_kc'],'addback_kc'=>$addback,'head_hash'=>$events===[]?$source['record_hash']:$events[array_key_last($events)]['record_hash']];
        }
        return['period_id'=>$period,'addback_kc'=>$total,'sources'=>$items];
    }

    public function registerFinal(int $supplier,int $snapshot,int $actor):?array
    {
        $this->locked();$basis=$this->finalProof($supplier,$snapshot);if($actor<=0||$basis['finalized_by']!==$actor)$this->changed();if($basis['saving']['reduction_kc']===0)return null;
        $q=$this->db->pdo()->prepare('SELECT id FROM nonprofit_tax_saving_sources WHERE supplier_id=? AND snapshot_id=? FOR UPDATE');$q->execute([$supplier,$snapshot]);$existing=$q->fetchColumn();if($existing!==false)return$this->source($supplier,(int)$existing);
        $row=['supplier_id'=>$supplier,'period_id'=>$basis['period']['id'],'snapshot_id'=>$snapshot,'legal_profile_id'=>$basis['legal_profile']['id'],'reduction_kc'=>$basis['saving']['reduction_kc'],'saving_cents'=>bcmul((string)$basis['saving']['saving_kc'],'100',0),'basis'=>$basis,'created_by'=>$actor];$hash=NonprofitTaxRules::hash($this->sourceRecord($row));
        $q=$this->db->pdo()->prepare('INSERT INTO nonprofit_tax_saving_sources(supplier_id,period_id,snapshot_id,legal_profile_id,reduction_kc,saving_cents,basis_json,record_hash,created_by) VALUES(?,?,?,?,?,?,?,?,?)');$q->execute([$supplier,$row['period_id'],$snapshot,$row['legal_profile_id'],$row['reduction_kc'],$row['saving_cents'],NonprofitTaxRules::canonicalJson($basis),$hash,$actor]);
        $source=$this->source($supplier,(int)$this->db->pdo()->lastInsertId());$this->assertFinalCurrent($supplier,$source);return$source;
    }

    /**
     * Ověřený základ úspory z finálního snapshotu 504 (stejný tvar, jaký nese zdroj úspory).
     * Anonymizovaná kopie z něj staví základ zdroje po přepisu archivu.
     */
    public function finalBasis(int $supplier,int $snapshot):array
    {
        $this->locked();
        return$this->finalProof($supplier,$snapshot);
    }

    public function command(int $supplier,int $id):array
    {
        $this->locked();$q=$this->db->pdo()->prepare('SELECT * FROM nonprofit_tax_saving_commands WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)$this->changed();foreach(['id','supplier_id','created_by']as$key)$row[$key]=(int)$row[$key];$row['request']=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);unset($row['request_json']);
        if(!hash_equals($row['request_hash'],NonprofitTaxRules::hash(['request'=>$row['request'],'idempotency_key'=>$row['idempotency_key'],'actor'=>$row['created_by']])))$this->changed();return$row;
    }

    public function nextPeriod(int $supplier,array $source):array
    {
        $next=null;foreach((new AccountingPeriodRepository($this->db))->listForTenant($supplier)as$period)if($period['starts_on']>$source['basis']['period']['ends_on']&&($next===null||$period['starts_on']<$next['starts_on']))$next=$period;
        $expected=(new \DateTimeImmutable($source['basis']['period']['ends_on']))->modify('+1 day')->format('Y-m-d');
        if($next===null||$next['starts_on']!==$expected)throw new TaxReturnException('nonprofit_tax_saving_next_period_required','Použití úspory vyžaduje skutečné bezprostředně následující zdaňovací období.',422);return$next;
    }

    public function head(array $source,array $events):string
    {return$events===[]?$source['record_hash']:$events[array_key_last($events)]['record_hash'];}
    public function isVoid(array $events):bool
    {return$events!==[]&&$events[array_key_last($events)]['operation']==='void';}
    public function eventRecord(array $row):array
    {$record=[];foreach(['supplier_id','source_id','command_id','previous_id','original_id','operation','period_id','cost_line_id','amount_cents','parent_hash','proof','receipt']as$key)$record[$key]=$row[$key];return$record;}
    public function sourceRecord(array $row):array
    {$record=[];foreach(['supplier_id','period_id','snapshot_id','legal_profile_id','reduction_kc','saving_cents','basis','created_by']as$key)$record[$key]=$row[$key];return$record;}

    private function finalProof(int $supplier,int $snapshot):array
    {
        $q=$this->db->pdo()->prepare('SELECT * FROM income_tax_return_snapshots WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$snapshot]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row||$row['business_status']!=='passed'||!hash_equals($row['source_sha256'],hash('sha256',$row['source_manifest']))||!hash_equals($row['xml_sha256'],hash('sha256',$row['xml_content'])))$this->changed();
        $data=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);$manifest=json_decode($row['source_manifest'],true,flags:JSON_THROW_ON_ERROR);$nonprofit=$data['computed']['nonprofit']??null;
        NonprofitDppoStoredProof::verify($data,$manifest,$row['xml_content']);
        if(!is_array($nonprofit)||($data['podklady']['accounting_decree']??null)!=='504'||($manifest['accounting_decree']??null)!=='504'||($nonprofit['proof_hash']??null)!==($manifest['nonprofit_proof_hash']??null))$this->changed();
        $saving=$nonprofit['saving']??[];foreach(['base_before_reduction_kc','reduction_kc','credits_entitlement_kc','rate_numerator','rate_denominator','rounding_kc']as$key)if(!is_int($saving[$key]??null))$this->changed();
        $expected=NonprofitDppoLegalRules::saving($saving['base_before_reduction_kc'],$saving['reduction_kc'],$saving['credits_entitlement_kc'],$saving['rate_numerator'],$saving['rate_denominator'],$saving['rounding_kc']);if(NonprofitTaxRules::hash($saving)!==NonprofitTaxRules::hash($expected)||(float)($data['computed']['tax']??-1)!==(float)$saving['tax_with_reduction_kc'])$this->changed();
        $period=$data['podklady']['period']??[];if(!is_int($period['id']??null))$this->changed();$nativePeriod=(new AccountingPeriodRepository($this->db))->findById($supplier,$period['id']);if($nativePeriod===null||$nativePeriod['starts_on']!==($period['starts_on']??null)||$nativePeriod['ends_on']!==($period['ends_on']??null))$this->changed();
        $legal=$nonprofit['legal_profile']??[];$found=null;foreach((new NonprofitLegalProfileService($this->db))->history($supplier,$period['id'],true)as$profile)if($profile['id']===($legal['id']??null))$found=$profile;
        if($found===null||$found['record_hash']!==($legal['record_hash']??null)||($saving['reduction_kc']>0&&(!in_array($found['profile']['kind'],['narrow_vpp','broad_vpp'],true)||$found['profile']['reduction_band']==='ineligible'))||$saving['reduction_kc']>NonprofitDppoReduction::limit($saving['base_before_reduction_kc'],$found['profile']['reduction_band'],NonprofitDppoStoredProof::constantsForRecord($data['podklady']['nonprofit_calculation'])))$this->changed();
        $dom=new \DOMDocument();if(!$dom->loadXML($row['xml_content'],LIBXML_NONET))$this->changed();$xp=new \DOMXPath($dom);$header=$xp->query('/Pisemnost/DPPDP9/VetaD')?->item(0);$form=$xp->query('/Pisemnost/DPPDP9/VetaO')?->item(0);
        if(!$header instanceof \DOMElement||$header->getAttribute('uv_vyhl')!=='504'||!$form instanceof \DOMElement||(int)$form->getAttribute('kc_ii231_251')!==$saving['reduction_kc']||(new XmlSchemaValidator())->validate($row['xml_content'],'dppdp9')['status']!=='passed')$this->changed();
        return['snapshot_id'=>$snapshot,'tax_return_id'=>(int)$row['tax_return_id'],'snapshot_hash'=>hash('sha256',$row['snapshot_json']),'source_sha256'=>$row['source_sha256'],'xml_sha256'=>$row['xml_sha256'],'finalized_by'=>(int)$row['finalized_by'],'period'=>['id'=>$period['id'],'starts_on'=>$period['starts_on'],'ends_on'=>$period['ends_on']],'legal_profile'=>['id'=>$found['id'],'record_hash'=>$found['record_hash'],'profile'=>$found['profile']],'saving'=>$saving,'nonprofit_proof_hash'=>$nonprofit['proof_hash']];
    }
    private function assertFinalCurrent(int $supplier,array $source):void
    {$q=$this->db->pdo()->prepare("SELECT final_snapshot_id,status FROM income_tax_returns WHERE supplier_id=? AND id=? FOR UPDATE");$q->execute([$supplier,$source['basis']['tax_return_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row||$row['status']!=='final'||(int)$row['final_snapshot_id']!==$source['snapshot_id'])$this->changed();
        $period=(new AccountingPeriodRepository($this->db))->findById($supplier,$source['period_id']);$effective=(new \MyInvoice\Repository\TaxReturnRepository($this->db))->findLastFinalized($supplier,(int)$period['fiscal_year'],'po');if($effective===null||(int)$effective['final_snapshot_id']!==$source['snapshot_id'])$this->changed();}
    private function locked():void
    {if(!$this->db->pdo()->inTransaction())throw new \LogicException('Tax savings require a period-locked transaction.');}
    private function changed():never
    {throw new TaxReturnException('nonprofit_tax_saving_proof_changed','Evidence úspory nebo jejího použití není průkazná. Nejdříve vypořádejte původní potvrzení.',409);}
}
