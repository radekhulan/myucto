<?php

declare(strict_types=1);
namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\TaxReturnRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Funding\CostCoverage;
use MyInvoice\Service\Accounting\Funding\NativeCostCoverage;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Tax\Return\TaxReturnException;
use PDO;

final class NonprofitTaxSavingsService
{
    public function __construct(private readonly Connection $db) {}

    public function context(int $supplier):array
    {
        return $this->transaction($supplier,function()use($supplier):array{
            $ledger=new NonprofitTaxSavingsLedger($this->db);$out=[];
            foreach($ledger->sources($supplier)as$source){$events=$ledger->events($supplier,$source['id']);$out[]=['source'=>$source,'events'=>$events,'remaining_cents'=>bcsub($source['saving_cents'],$this->used($events),0),'void'=>$ledger->isVoid($events),'head_hash'=>$ledger->head($source,$events)]+$this->selectors($supplier,$source,$ledger->isVoid($events));}
            return['sources'=>$out];
        });
    }

    public function previewUse(int $supplier,int $source,array $input):array
    {return$this->transaction($supplier,fn()=>$this->usePlan($supplier,$source,$input));}

    public function confirmUse(int $supplier,int $source,array $input,string $previewHash,string $expectedHeadHash,string $key,int $actor):array
    {
        return$this->transaction($supplier,function()use($supplier,$source,$input,$previewHash,$expectedHeadHash,$key,$actor):array{
            $request=['operation'=>'use','source_id'=>$source,'input'=>$input,'preview_hash'=>$previewHash,'expected_head_hash'=>$expectedHeadHash];
            if($retry=$this->retry($supplier,$request,$key,$actor))return$retry;
            $plan=$this->usePlan($supplier,$source,$input);$this->assertPreview($plan,$previewHash,$expectedHeadHash);
            return$this->append($supplier,$request,$key,$actor,$plan['period_id'],$plan['cost_line_id'],$plan['amount_cents'],null,$plan['proof']);
        });
    }

    public function previewReverse(int $supplier,int $source,int $event,string $reason):array
    {return$this->transaction($supplier,fn()=>$this->reversePlan($supplier,$source,$event,$reason));}

    public function confirmReverse(int $supplier,int $source,int $event,string $reason,string $previewHash,string $expectedHeadHash,string $key,int $actor):array
    {
        return$this->transaction($supplier,function()use($supplier,$source,$event,$reason,$previewHash,$expectedHeadHash,$key,$actor):array{
            $request=['operation'=>'reverse_use','source_id'=>$source,'event_id'=>$event,'reason'=>$reason,'preview_hash'=>$previewHash,'expected_head_hash'=>$expectedHeadHash];
            if($retry=$this->retry($supplier,$request,$key,$actor))return$retry;
            $plan=$this->reversePlan($supplier,$source,$event,$reason);$this->assertPreview($plan,$previewHash,$expectedHeadHash);
            return$this->append($supplier,$request,$key,$actor,$plan['period_id'],$plan['cost_line_id'],$plan['amount_cents'],$event,$plan['proof']);
        });
    }

    public function preparePeriodReplacement(int $supplier,int $period,int $actor,string $token):void
    {
        $this->transaction($supplier,function()use($supplier,$period,$actor,$token):void{
            $ledger=new NonprofitTaxSavingsLedger($this->db);
            foreach($ledger->sources($supplier)as$source)if($source['period_id']===$period){$events=$ledger->events($supplier,$source['id']);if($ledger->isVoid($events))continue;
                if($this->used($events)!=='0')throw new TaxReturnException('nonprofit_tax_saving_already_used','Před změnou přiznání nejdříve odvolejte použití jeho daňové úspory.',409);
                $request=['operation'=>'void','source_id'=>$source['id'],'reason'=>'Replacement of effective native tax return','expected_head_hash'=>$ledger->head($source,$events),'native_filing_token'=>$token];
                $this->append($supplier,$request,'filing-'.$token.'-'.$source['id'],$actor,null,null,'0',null,['source_hash'=>$source['record_hash'],'snapshot_id'=>$source['snapshot_id']]);
            }
        });
    }

    public function restoreEffective(int $supplier,int $fiscalYear,int $actor,string $token):void
    {
        $this->transaction($supplier,function()use($supplier,$fiscalYear,$actor,$token):void{
            $effective=(new TaxReturnRepository($this->db))->findLastFinalized($supplier,$fiscalYear,'po');if($effective===null)return;$ledger=new NonprofitTaxSavingsLedger($this->db);
            foreach($ledger->sources($supplier)as$source)if($source['snapshot_id']===(int)$effective['final_snapshot_id']){$events=$ledger->events($supplier,$source['id']);if(!$ledger->isVoid($events))return;
                $request=['operation'=>'restore','source_id'=>$source['id'],'reason'=>'Restore previous effective native tax return','expected_head_hash'=>$ledger->head($source,$events),'native_filing_token'=>$token];
                $this->append($supplier,$request,'restore-'.$token.'-'.$source['id'],$actor,null,null,'0',null,['source_hash'=>$source['record_hash'],'snapshot_id'=>$source['snapshot_id']]);
            }
        });
    }

    private function usePlan(int $supplier,int $id,array $input):array
    {
        if(array_diff(array_keys($input),['cost_line_id','amount_cents','purpose','evidence'])!==[]||!is_int($input['cost_line_id']??null)||$input['cost_line_id']<=0||!is_string($input['amount_cents']??null)||preg_match('/^[1-9][0-9]{0,15}$/D',$input['amount_cents'])!==1)$this->invalid();
        $evidence=NonprofitTaxRules::evidence($input['evidence']??null);$ledger=new NonprofitTaxSavingsLedger($this->db);$source=$ledger->source($supplier,$id);$events=$ledger->events($supplier,$id);if($ledger->isVoid($events))$this->invalid();$this->assertSourceCurrent($supplier,$source);
        $period=$ledger->nextPeriod($supplier,$source);$this->openPeriod($supplier,$period['id']);$native=(new NativeCostCoverage($this->db))->financingCost($supplier,$input['cost_line_id']);
        if(!$native['eligible']||(int)$native['ledger']['entry']['period_id']!==$period['id'])$this->invalid();
        $assessment=$this->assessmentSource()->currentView($supplier,(int)$period['id']);
        [$row,$legal,$purposes]=$this->qualification($source,$assessment,$input['cost_line_id']);$purpose=$input['purpose']??null;
        if($row===null||!in_array($purpose,$purposes,true))$this->invalid();
        $capacity=(new CostCoverage($this->db))->capacity($supplier,$input['cost_line_id']);$remaining=bcsub($source['saving_cents'],$this->used($events),0);
        if(bccomp($input['amount_cents'],$remaining,0)>0||bccomp($input['amount_cents'],(string)$capacity['available_cents'],0)>0)throw new TaxReturnException('nonprofit_tax_saving_capacity_exceeded','Použití překračuje skutečnou daňovou úsporu nebo volnou část způsobilého nákladu.',422);
        $plan=['operation'=>'use','source_id'=>$id,'source_hash'=>$source['record_hash'],'head_hash'=>$ledger->head($source,$events),'period_id'=>$period['id'],'cost_line_id'=>$input['cost_line_id'],'amount_cents'=>$input['amount_cents'],
            'proof'=>['cost_native'=>$native,'assessment'=>['id'=>$assessment['id'],'input_hash'=>$assessment['input_hash'],'request_hash'=>$assessment['request_hash'],'row'=>$row],'legal_profile'=>$legal,'purpose'=>$purpose,'evidence'=>$evidence,'cost_capacity_hash'=>NonprofitTaxRules::hash($capacity),'cost_capacity'=>$capacity]];
        return$plan+['preview_hash'=>NonprofitTaxRules::hash($plan)];
    }

    /** Stejná čtecí cesta k posouzení J6 jako DPPO 504 (legacy i stránkované revize). */
    private function assessmentSource():NonprofitDppoAssessmentSource
    {$c=Bootstrap::buildContainer();$c->set(Connection::class,$this->db);return$c->get(NonprofitDppoAssessmentSource::class);}

    private function qualification(array $source,array $assessment,int $line):array
    {
        $row=array_column($assessment['result']['rows'],null,'line_id')[$line]??null;$legal=$assessment['snapshot']['legal_profile']??null;$purposes=[];
        if($row===null||$row['kind']!=='expense'||$row['amount_cents']<=0||$row['activity']!=='main'||($row['decision']['title']??null)!=='nonbusiness_cost'||$legal===null||$legal['profile']['entity_type']!==$source['basis']['legal_profile']['profile']['entity_type']||$legal['profile']['kind']!==$source['basis']['legal_profile']['profile']['kind'])return[null,$legal,[]];
        if($source['basis']['legal_profile']['profile']['reduction_band']==='university_research'){
            $purpose=$row['decision']['facts']['saving_qualified_purpose']??null;if(in_array($purpose,['education','science','research','development','art'],true))$purposes=[$purpose];
        }else $purposes=['nonbusiness'];
        return[$row,$legal,$purposes];
    }

    private function selectors(int $supplier,array $source,bool $void):array
    {
        $out=['next_period'=>null,'eligibility'=>['ready'=>false,'code'=>null,'message'=>null],'eligible_costs'=>[],'cost_findings'=>[]];
        if($void){$out['eligibility']['code']='nonprofit_tax_saving_source_void';return$out;}
        try{
            $this->assertSourceCurrent($supplier,$source);
            $period=(new NonprofitTaxSavingsLedger($this->db))->nextPeriod($supplier,$source);$out['next_period']=$period;$this->openPeriod($supplier,$period['id']);
            $assessment=$this->assessmentSource()->currentView($supplier,(int)$period['id']);
            foreach($assessment['result']['rows']as$cost){
                if($cost['kind']!=='expense'||$cost['amount_cents']<=0)continue;[$row,$legal,$purposes]=$this->qualification($source,$assessment,(int)$cost['line_id']);if($row===null||$purposes===[])continue;
                try{$capacity=(new CostCoverage($this->db))->capacity($supplier,(int)$row['line_id']);
                    if(bccomp((string)$capacity['available_cents'],'0',0)>0)$out['eligible_costs'][]=['cost_line_id'=>(int)$row['line_id'],'period_id'=>$period['id'],'date'=>$row['date'],'account_code'=>$row['account_code'],'amount_cents'=>(string)$row['amount_cents'],'available_cents'=>(string)$capacity['available_cents'],'allowed_purposes'=>$purposes,'assessment_id'=>$assessment['id'],'description'=>$row['decision']['evidence'],'document_ref'=>is_int($row['origin']['id']??null)?$row['origin']['type'].':'.$row['origin']['id']:null];
                }catch(\MyInvoice\Service\Accounting\PostingException $e){$out['cost_findings'][]=['cost_line_id'=>(int)$row['line_id'],'code'=>$e->errorCode,'message'=>$e->getMessage()];}
            }
            $out['eligibility']['ready']=true;return$out;
        }catch(TaxReturnException|NonprofitTaxAssessmentException $e){$out['eligibility']=['ready'=>false,'code'=>$e->errorCode,'message'=>$e->getMessage()];return$out;}
    }

    private function assertSourceCurrent(int $supplier,array $source):void
    {
        $snapshot=(new TaxReturnRepository($this->db))->snapshot($supplier,$source['snapshot_id']);
        if($snapshot===null)$this->invalid();
        (new NonprofitDppoFilingGate($this->db))->submission($supplier,['form_code'=>'dppdp9','xml_content'=>$snapshot['xml_content']],true);
    }

    private function reversePlan(int $supplier,int $id,int $event,string $reason):array
    {
        NonprofitTaxRules::evidence($reason);$ledger=new NonprofitTaxSavingsLedger($this->db);$source=$ledger->source($supplier,$id);$events=$ledger->events($supplier,$id);$byId=array_column($events,null,'id');$original=$byId[$event]??null;
        if($ledger->isVoid($events)||$original===null||$original['operation']!=='use')$this->invalid();foreach($events as$row)if($row['original_id']===$event)$this->invalid();$this->openPeriod($supplier,$original['period_id']);
        $plan=['operation'=>'reverse_use','source_id'=>$id,'source_hash'=>$source['record_hash'],'head_hash'=>$ledger->head($source,$events),'period_id'=>$original['period_id'],'cost_line_id'=>$original['cost_line_id'],'amount_cents'=>$original['amount_cents'],'proof'=>['original_id'=>$event,'original_hash'=>$original['record_hash'],'reason'=>$reason]];
        return$plan+['preview_hash'=>NonprofitTaxRules::hash($plan)];
    }

    private function append(int $supplier,array $request,string $key,int $actor,?int $period,?int $line,string $amount,?int $original,array $proof):array
    {
        $this->identity($key,$actor);$ledger=new NonprofitTaxSavingsLedger($this->db);$source=$ledger->source($supplier,$request['source_id']);$events=$ledger->events($supplier,$source['id']);if(($request['expected_head_hash']??null)!==$ledger->head($source,$events))throw new TaxReturnException('nonprofit_tax_saving_changed','Evidence použití úspory se změnila. Načtěte aktuální stav.',409);
        $hash=NonprofitTaxRules::hash(['request'=>$request,'idempotency_key'=>$key,'actor'=>$actor]);$q=$this->db->pdo()->prepare('INSERT INTO nonprofit_tax_saving_commands(supplier_id,idempotency_key,request_json,request_hash,created_by) VALUES(?,?,?,?,?)');$q->execute([$supplier,$key,NonprofitTaxRules::canonicalJson($request),$hash,$actor]);$command=(int)$this->db->pdo()->lastInsertId();
        $row=['supplier_id'=>$supplier,'source_id'=>$source['id'],'command_id'=>$command,'previous_id'=>$events===[]?null:$events[array_key_last($events)]['id'],'original_id'=>$original,'operation'=>$request['operation'],'period_id'=>$period,'cost_line_id'=>$line,'amount_cents'=>$amount,'parent_hash'=>$ledger->head($source,$events),'proof'=>$proof,'receipt'=>['command_hash'=>$hash,'source_hash'=>$source['record_hash'],'operation'=>$request['operation'],'amount_cents'=>$amount,'cost_line_id'=>$line,'period_id'=>$period]];$recordHash=NonprofitTaxRules::hash($ledger->eventRecord($row));
        $q=$this->db->pdo()->prepare('INSERT INTO nonprofit_tax_saving_events(supplier_id,source_id,command_id,previous_id,original_id,operation,period_id,cost_line_id,amount_cents,parent_hash,proof_json,receipt_json,record_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$supplier,$source['id'],$command,$row['previous_id'],$original,$row['operation'],$period,$line,$amount,$row['parent_hash'],NonprofitTaxRules::canonicalJson($proof),NonprofitTaxRules::canonicalJson($row['receipt']),$recordHash]);
        $eventId=(int)$this->db->pdo()->lastInsertId();
        if($row['operation']==='use')$this->db->pdo()->prepare('INSERT INTO nonprofit_tax_saving_cost_bindings(supplier_id,source_id,event_id,cost_line_id)VALUES(?,?,?,?)')->execute([$supplier,$source['id'],$eventId,$line]);
        elseif($row['operation']==='reverse_use'){$release=$this->db->pdo()->prepare('DELETE FROM nonprofit_tax_saving_cost_bindings WHERE supplier_id=? AND source_id=? AND event_id=? AND cost_line_id=?');$release->execute([$supplier,$source['id'],$original,$line]);if($release->rowCount()!==1)throw new TaxReturnException('nonprofit_tax_saving_proof_changed','Evidence úspory nebo jejího použití není průkazná. Nejdříve vypořádejte původní potvrzení.',409);}
        $events=$ledger->events($supplier,$source['id']);return$events[array_key_last($events)];
    }

    private function retry(int $supplier,array $request,string $key,int $actor):?array
    {
        $this->identity($key,$actor);$q=$this->db->pdo()->prepare('SELECT id FROM nonprofit_tax_saving_commands WHERE supplier_id=? AND idempotency_key=? FOR UPDATE');$q->execute([$supplier,$key]);$id=$q->fetchColumn();if($id===false)return null;$ledger=new NonprofitTaxSavingsLedger($this->db);$command=$ledger->command($supplier,(int)$id);$hash=NonprofitTaxRules::hash(['request'=>$request,'idempotency_key'=>$key,'actor'=>$actor]);
        if(!hash_equals($hash,$command['request_hash']))throw new TaxReturnException('nonprofit_tax_saving_idempotency_conflict','Klíč požadavku již patří jinému použití úspory.',409);foreach($ledger->events($supplier,(int)$request['source_id'])as$event)if($event['command_id']===(int)$id)return$event;throw new TaxReturnException('nonprofit_tax_saving_pending_command','Původní požadavek nemá úplné potvrzení.',409);
    }
    private function used(array $events):string
    {$used='0';foreach($events as$event)if($event['operation']==='use')$used=bcadd($used,$event['amount_cents'],0);elseif($event['operation']==='reverse_use')$used=bcsub($used,$event['amount_cents'],0);return$used;}
    private function assertPreview(array $plan,string $preview,string $head):void
    {if(!preg_match('/^[a-f0-9]{64}$/D',$preview)||!preg_match('/^[a-f0-9]{64}$/D',$head)||!hash_equals($plan['preview_hash'],$preview)||!hash_equals($plan['head_hash'],$head))throw new TaxReturnException('nonprofit_tax_saving_preview_changed','Zkontrolované podklady nebo volná kapacita nákladu se změnily.',409);}
    private function openPeriod(int $supplier,int $id):void
    {$p=(new AccountingPeriodRepository($this->db))->findById($supplier,$id);if($p===null||!in_array($p['status'],['open','closing'],true))throw new TaxReturnException('nonprofit_tax_saving_period_locked','Použití úspory vyžaduje otevřené nebo uzavírané období.',409);
        if((new TaxReturnRepository($this->db))->findLastFinalized($supplier,(int)$p['fiscal_year'],'po')!==null)throw new TaxReturnException('nonprofit_tax_saving_return_final','Před změnou použití úspory vraťte přiznání příslušného období do rozpracovaného stavu.',409);}
    private function identity(string $key,int $actor):void
    {if($actor<=0||preg_match('/^[a-zA-Z0-9_.:-]{1,80}$/D',$key)!==1)$this->invalid();}
    private function transaction(int $supplier,callable $work):mixed
    {return(new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$work):mixed{(new PaymentLocks($this->db))->acquire($supplier);return$work();});}
    private function invalid():never
    {throw new TaxReturnException('nonprofit_tax_saving_use_invalid','Použití vyžaduje doložený skutečný náklad příslušné nepodnikatelské činnosti v následujícím období.',422);}
}
