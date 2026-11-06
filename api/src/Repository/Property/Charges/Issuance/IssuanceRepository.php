<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property\Charges\Issuance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Repository\Property\Charges\ChargeReadRepository;
use MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\PropertyDimensionPolicy;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceLocks;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceJournalProof;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceEventPolicy;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceReversalIntent;
use PDO;

final class IssuanceRepository
{
    public function __construct(private readonly Connection $db,private readonly PostingService $posting, private readonly \MyInvoice\Repository\JournalEntryRepository $journal) {}

    public function issue(int $supplier,array $input,int $actor): array
    {
        PropertyInput::id($actor,'actor'); PropertyInput::fields($input,['request','preview_hash','idempotency_key']);
        if(!is_array($input['request']??null)||!is_string($input['preview_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$input['preview_hash'])) PropertyInput::invalid('preview_hash');
        $request=IssuanceRequest::normalize($input['request']); $token=PropertyInput::code($input['idempotency_key']??null,'idempotency_key');
        $envelope=['request'=>$request,'preview_hash'=>$input['preview_hash'],'idempotency_key'=>$token,'actor'=>$actor]; $hash=PropertyInput::hash($envelope);
        return (new DimensionPeriodGuard($this->db))->transaction(function() use($supplier,$request,$input,$token,$hash,$actor): array {
            $locks=new IssuanceLocks($this->db); $locks->acquire($supplier);
            $reader=new IssuanceReadRepository($this->db);
            $old=$this->retry('unit_charge_batches',$supplier,$token,$hash);
            if($old!==null) return $reader->batch($supplier,(int)$old['id'],true);
            $locks->assertFramework($supplier,$request['accounting_date']);
            (new PropertyProfileRepository($this->db))->assertEnabledLocked($supplier,self::instant());
            $preview=(new IssuancePreviewService($this->db))->preview($supplier,$request,true);
            if(!hash_equals($input['preview_hash'],$preview['preview_hash'])) throw new IssuanceException('property_charge_issue_preview_stale','Podklady nebo titul předpisu se změnily. Vytvořte nový náhled.',409);
            $now=self::now(); $pdo=$this->db->pdo();
            $pdo->prepare('INSERT INTO unit_charge_batches (supplier_id,version_id,request_json,snapshot_json,preview_hash,request_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$supplier,$request['version_id'],IssuanceRequest::json($request),IssuanceRequest::json($preview['snapshot']),$preview['preview_hash'],$hash,$token,$actor,$now]);
            $batch=(int)$pdo->lastInsertId();
            foreach($preview['snapshot']['charges'] as $charge) {
                $pdo->prepare('INSERT INTO unit_charges (supplier_id,batch_id,unit_id,source_key,revision_no,previous_charge_id,title,issue_mode,period_from,period_to,claim_origin_at,issued_at,accounting_date,due_date,amount_cents,snapshot_json,snapshot_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$supplier,$batch,$charge['unit_id'],$charge['source_key'],$charge['revision_no'],$charge['previous_charge_id'],$charge['title'],$charge['issue_mode'],$charge['period_from'],$charge['period_to'],
                        PropertyInstant::sql($charge['claim_origin_at']),PropertyInstant::sql($charge['issued_at']),$charge['accounting_date'],$charge['due_date'],$charge['amount_cents'],IssuanceRequest::json($charge),PropertyInput::hash($charge)]);
                $id=(int)$pdo->lastInsertId();
                foreach($charge['lines'] as $line) $pdo->prepare('INSERT INTO unit_charge_lines (supplier_id,charge_id,component_id,service_id,amount_cents,line_json) VALUES (?,?,?,?,?,?)')
                    ->execute([$supplier,$id,$line['component_id'],$line['service_id'],$line['amount_cents'],IssuanceRequest::json($line)]);
                foreach($charge['liability_groups'] as $group) foreach($group['members'] as $client) $pdo->prepare('INSERT INTO unit_charge_parties (supplier_id,charge_id,client_id,group_key,role,party_json) VALUES (?,?,?,?,?,?)')
                    ->execute([$supplier,$id,$client,$group['group_key'],$group['role'],IssuanceRequest::json($group)]);
                foreach($charge['notices'] as $notice) $pdo->prepare('INSERT INTO unit_charge_notices (supplier_id,charge_id,component_id,notice_json) VALUES (?,?,?,?)')
                    ->execute([$supplier,$id,$notice['component_id'],IssuanceRequest::json($notice['notice'])]);
                $this->audit($supplier,$batch,$id,'issued',['snapshot_hash'=>PropertyInput::hash($charge),'reason'=>$request['reason']],$actor,$now);
            }
            return $reader->batch($supplier,$batch,true);
        });
    }

    public function post(int $supplier,int $batchId,array $expected,int $actor,string $debtRegistration=ChargeDebtRegistrar::MODE_AUTOMATIC): array
    {
        PropertyInput::id($actor,'actor'); $expected=self::versions($expected); $debtRegistration=ChargeDebtRegistrar::mode($debtRegistration);
        return (new DimensionPeriodGuard($this->db))->transaction(fn():array=>$this->postLocked($supplier,$batchId,$expected,$actor,null,$debtRegistration));
    }

    public function postPaymentCorrection(\MyInvoice\Service\Property\Payments\PaymentCorrectionIntent $intent):array
    {
        $intent->claim($this->db);
        return (new DimensionPeriodGuard($this->db))->transaction(function()use($intent):array{
            $intent->prepareWrite($this->db);
            $this->postLocked($intent->supplierId,$intent->batchId,[$intent->chargeId=>$intent->expectedVersion],$intent->actor,$intent);
            return $intent->complete($this->db);
        });
    }

    public function reversePaymentCorrection(\MyInvoice\Service\Property\Payments\PaymentCorrectionReversalIntent $intent):array
    {
        return $this->journal->insertPropertyCorrectionChargeReversal($intent);
    }

    private function postLocked(int $supplier,int $batchId,array $expected,int $actor,?\MyInvoice\Service\Property\Payments\PaymentCorrectionIntent $correction=null,string $debtRegistration=ChargeDebtRegistrar::MODE_MANUAL):array
    {
        $locks=new IssuanceLocks($this->db); $locks->acquire($supplier);
        $reader=new IssuanceReadRepository($this->db); $batch=$reader->batch($supplier,$batchId,true);
        $ids=array_column($batch['charges'],'id'); sort($ids,SORT_NUMERIC);
        if($ids!==array_keys($expected)) self::conflict();
        $done=true;$proofs=[];
        $proof=new IssuanceJournalProof($this->db);
        foreach($batch['charges'] as $charge) {
            if($charge['status']==='posted'&&in_array($expected[$charge['id']],[$charge['row_version'],$charge['row_version']-1],true)) {
                $proofs[]=['entry_id'=>$charge['journal_entry_id'],'charge'=>$charge,'reversal'=>false];
                continue;
            }
            if(in_array($charge['status'],['cancelled','reversed'],true)&&$charge['row_version']===$expected[$charge['id']]) {
                if($charge['status']==='reversed') $proofs[]=['entry_id'=>$charge['reversal_entry_id'],'charge'=>$charge,'reversal'=>true];
                continue;
            }
            if($charge['status']!=='draft'||$charge['row_version']!==$expected[$charge['id']]) self::conflict();
            $done=false;
        }
        if($proofs!==[]) $proof->assertEntries($supplier,$proofs);
        if($done) return $batch;
        (new PropertyProfileRepository($this->db))->assertEnabledLocked($supplier,self::instant());
        $version=(new ChargeReadRepository($this->db))->find($supplier,$batch['version_id'],true);
        if($version['preview_hash']!==$batch['snapshot']['version_preview_hash']) IssuanceReadRepository::corrupt();
        $proof=new IssuanceJournalProof($this->db);$batchContext=null;$postingDocuments=[];$positive=$correction===null;$drafts=[];
        foreach($batch['charges']as$charge)if($charge['status']==='draft'){
            $document=$this->postingDocument($charge,$actor);$postingDocuments[$charge['id']]=$document;$drafts[]=$charge;
            foreach($charge['snapshot']['lines']as$line)if(bccomp($line['amount_cents'],'0',0)<0)$positive=false;
        }
        if($positive&&$postingDocuments!==[]){
            (new \MyInvoice\Service\Property\Payments\PaymentUseGuard($this->db))->assertPositiveChargePostingBatchAllowed($supplier,$drafts);
            $locks->assertFrameworks($supplier,array_column($drafts,'accounting_date'));
            (new PropertyDimensionPolicy($this->db))->assertAccountingDateBatch($supplier,array_map(static fn(array $charge,array $document):array=>[
                'accounting_date'=>$charge['accounting_date'],'lines'=>$document['lines']],$drafts,array_values($postingDocuments)));
            $batchContext=$this->posting->preflightPropertyIssuanceBatch($supplier,array_values($postingDocuments));
        }
        $posted=[];
        try{foreach($batch['charges'] as $charge) {
            if($charge['status']!=='draft') continue;
            $document=$postingDocuments[$charge['id']];$lines=$document['lines'];
            if($batchContext===null){
                if($correction===null){
                    try{(new \MyInvoice\Service\Property\Payments\PaymentUseGuard($this->db))->assertChargePostingAllowed($supplier,$charge['id']);}
                    catch(\MyInvoice\Service\Property\Payments\PaymentException $e){throw new IssuanceException($e->errorCode,$e->getMessage(),$e->httpStatus,$e->details);}
                }else $correction->assertCharge($this->db,$charge);
                $locks->assertFramework($supplier,$charge['accounting_date']);
                (new PropertyDimensionPolicy($this->db))->assertAccountingDate($supplier,$charge['accounting_date'],$lines);
            }
            $entry=$batchContext===null
                ?$this->posting->postDocument($supplier,'unit_charge',$charge['id'],$lines,$document['meta'])
                :$this->posting->postPropertyIssuanceBatchDocument($batchContext,$supplier,$charge['id'],$lines,$document['meta']);
            $proofs[]=['entry_id'=>$entry,'charge'=>$charge,'reversal'=>false];
            $stmt=$this->db->pdo()->prepare("UPDATE unit_charges SET status='posted',journal_entry_id=?,row_version=row_version+1 WHERE supplier_id=? AND id=? AND status='draft' AND row_version=?");
            $stmt->execute([$entry,$supplier,$charge['id'],$charge['row_version']]); if($stmt->rowCount()!==1) self::conflict();
            $posted[]=['status'=>'posted','journal_entry_id'=>$entry,'row_version'=>$charge['row_version']+1]+$charge;
            if($charge['previous_charge_id']!==null) $this->adjustment($supplier,$charge['previous_charge_id'],$charge['id'],$charge['issue_mode'],$entry,
                $charge['snapshot']['issued_at'],['new_charge_id'=>$charge['id'],'snapshot_hash'=>$charge['snapshot_hash']], 'issue-'.$charge['id'],$actor);
            $this->audit($supplier,$batchId,$charge['id'],'posted',['journal_entry_id'=>$entry,'snapshot_hash'=>$charge['snapshot_hash']],$actor,self::now());
        }}finally{$batchContext?->close();}
        $proof->assertEntries($supplier,$proofs);
        if($correction===null&&$debtRegistration===ChargeDebtRegistrar::MODE_AUTOMATIC) (new ChargeDebtRegistrar($this->db))->registerPosted($supplier,$posted,$actor);
        return $reader->batch($supplier,$batchId,true);
    }

    private function postingDocument(array $charge,int $actor):array
    {
        $documentDate=(new \DateTimeImmutable($charge['snapshot']['issued_at']))->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d');
        return ['source_id'=>$charge['id'],'lines'=>IssuanceJournalProof::lines($charge),'meta'=>[
            'entry_date'=>$charge['accounting_date'],'document_date'=>$documentDate,'document_no'=>'P-'.$charge['id'],
            'description'=>'Předpis '.$charge['snapshot']['registry']['revision']['state']['unit_no'].' / '.$charge['period_from'],
            'posted_by'=>$actor,'user_id'=>$actor]];
    }

    public function reverse(int $supplier,int $chargeId,array $input,int $actor): array
    {
        PropertyInput::id($actor,'actor');
        PropertyInput::fields($input,['row_version','accounting_date','effective_at','reason','evidence_ref','idempotency_key']);
        $request=['row_version'=>PropertyInput::id($input['row_version']??null,'row_version'),
            'accounting_date'=>IssuanceRequest::date($input['accounting_date']??null),
            'effective_at'=>PropertyInstant::normalize($input['effective_at']??null),
            'reason'=>PropertyInput::text($input['reason']??null,'reason',2000),
            'evidence_ref'=>PropertyInput::text($input['evidence_ref']??null,'evidence_ref',2000)];
        $token=PropertyInput::code($input['idempotency_key']??null,'idempotency_key');
        $hash=PropertyInput::hash(['charge_id'=>$chargeId,'request'=>$request,'idempotency_key'=>$token,'actor'=>$actor]);
        return (new DimensionPeriodGuard($this->db))->transaction(function() use($supplier,$chargeId,$request,$token,$hash,$actor): array {
            $locks=new IssuanceLocks($this->db); $locks->acquire($supplier);
            $reader=new IssuanceReadRepository($this->db); $charge=$reader->find($supplier,$chargeId,true);
            $proof=new IssuanceJournalProof($this->db);
            if($this->retry('unit_charge_adjustments',$supplier,$token,$hash)!==null) {
                if(!in_array($charge['status'],['reversed','cancelled'],true)) IssuanceReadRepository::corrupt();
                if($charge['status']==='reversed') $proof->assertEntry($supplier,$charge['reversal_entry_id'],$charge,true);
                return $charge;
            }
            if($charge['row_version']!==$request['row_version']||!in_array($charge['status'],['draft','posted'],true)) self::conflict();
            try{(new \MyInvoice\Service\Property\Payments\PaymentUseGuard($this->db))->assertChargeCorrectable($supplier,$chargeId);}
            catch(\MyInvoice\Service\Property\Payments\PaymentException $e){throw new IssuanceException($e->errorCode,$e->getMessage(),$e->httpStatus,$e->details);}
            if($reader->activeSuccessor($supplier,$charge)!==null)
                throw new IssuanceException('property_charge_issue_successor_active','Nejprve zrušte navazující předpis nebo rozdílovou opravu.',409);
            if($request['effective_at']<$charge['snapshot']['issued_at']||$request['accounting_date']<$charge['accounting_date'])
                throw new IssuanceException('property_charge_issue_reversal_date','Storno nesmí předcházet původnímu předpisu.');
            try{(new \MyInvoice\Service\Property\Receivables\PartyHistoryUseGuard($this->db))->assertChargeMutable($supplier,$chargeId);}
            catch(\MyInvoice\Service\Property\Payments\PaymentException $e){throw new IssuanceException($e->errorCode,$e->getMessage(),$e->httpStatus,$e->details);}
            $reversal=null; $kind=$charge['status']==='posted'?'reversal':'cancel_draft'; $status='cancelled';
            $adjustment=$this->adjustment($supplier,$chargeId,null,$kind,null,$request['effective_at'],$request,$token,$actor);
            if($charge['status']==='posted') {
                $this->journal->insertPropertyChargeReversal($supplier,$adjustment);
                return $reader->find($supplier,$chargeId,true);
            }
            $stmt=$this->db->pdo()->prepare('UPDATE unit_charges SET status=?,reversal_entry_id=?,row_version=row_version+1 WHERE supplier_id=? AND id=? AND row_version=? AND status=?');
            $stmt->execute([$status,$reversal,$supplier,$chargeId,$request['row_version'],$charge['status']]); if($stmt->rowCount()!==1) self::conflict();
            $this->audit($supplier,$charge['batch_id'],$chargeId,$status,['adjustment_id'=>$adjustment,'reason'=>$request['reason'],
                'snapshot_hash'=>$charge['snapshot_hash'],'reversal_entry_id'=>$reversal],$actor,self::now());
            return $reader->find($supplier,$chargeId,true);
        });
    }

    public function recordEvidence(int $supplier,int $chargeId,array $input,int $actor): array
    {
        PropertyInput::id($actor,'actor'); PropertyInput::fields($input,['request','idempotency_key']);
        if(!is_array($input['request']??null)) PropertyInput::invalid('request');
        $token=PropertyInput::code($input['idempotency_key']??null,'idempotency_key');
        return (new DimensionPeriodGuard($this->db))->transaction(function() use($supplier,$chargeId,$input,$token,$actor): array {
            (new IssuanceLocks($this->db))->acquire($supplier);
            $reader=new IssuanceReadRepository($this->db); $charge=$reader->find($supplier,$chargeId,true);
            $request=IssuanceEventPolicy::normalize($input['request'],$charge);
            $hash=PropertyInput::hash(['charge_id'=>$chargeId,'request'=>$request,'idempotency_key'=>$token,'actor'=>$actor]);
            $events=$reader->evidence($supplier,$chargeId,true);
            $old=$this->retry('unit_charge_evidence_events',$supplier,$token,$hash);
            if($old!==null) {
                foreach($events as $event) if($event['id']===(int)$old['id']) return $event;
                IssuanceReadRepository::corrupt();
            }
            $last=0; foreach($events as $event) if($event['request']['kind']===$request['kind']) $last=$event['id'];
            if($last!==$request['expected_event_id']) self::conflict();
            if($request['kind']==='reminder') {
                $stmt=$this->db->pdo()->prepare('SELECT id FROM clients WHERE supplier_id=? AND id=? FOR UPDATE');
                $stmt->execute([$supplier,$request['facts']['recipient_id']]);
                if(!$stmt->fetchColumn()) throw new IssuanceException('property_charge_issue_party_unavailable','Příjemce nepatří do této firmy.');
            }
            $this->db->pdo()->prepare('INSERT INTO unit_charge_evidence_events (supplier_id,charge_id,kind,effective_at,request_json,request_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$supplier,$chargeId,$request['kind'],PropertyInstant::sql($request['effective_at']),IssuanceRequest::json($request),$hash,$token,$actor,self::now()]);
            $id=(int)$this->db->pdo()->lastInsertId();
            $this->audit($supplier,$charge['batch_id'],$chargeId,'evidence',['event_id'=>$id,'request_hash'=>$hash],$actor,self::now());
            $events=$reader->evidence($supplier,$chargeId,true);
            return $events[array_key_last($events)];
        });
    }

    private function retry(string $table,int $supplier,string $token,string $hash): ?array
    {
        $stmt=$this->db->pdo()->prepare('SELECT id,request_hash FROM '.$table.' WHERE supplier_id=? AND idempotency_key=? FOR UPDATE'); $stmt->execute([$supplier,$token]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if($row&&!hash_equals($row['request_hash'],$hash)) throw new IssuanceException('property_charge_issue_idempotency_conflict','Identifikátor požadavku již patří jinému obsahu.',409);
        return $row?:null;
    }

    private function audit(int $supplier,int $batch,?int $charge,string $action,array $payload,int $actor,string $now): void
    {
        $this->db->pdo()->prepare('INSERT INTO unit_charge_issue_audit (supplier_id,batch_id,charge_id,action,payload_json,payload_hash,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$supplier,$batch,$charge,$action,IssuanceRequest::json($payload),PropertyInput::hash($payload),$actor,$now]);
    }

    private function adjustment(int $supplier,int $charge,?int $new,string $kind,?int $entry,string $at,array $request,string $token,int $actor): int
    {
        $hash=PropertyInput::hash(['charge_id'=>$charge,'request'=>$request,'idempotency_key'=>$token,'actor'=>$actor]);
        $this->db->pdo()->prepare('INSERT INTO unit_charge_adjustments (supplier_id,charge_id,adjustment_charge_id,kind,journal_entry_id,effective_at,request_json,request_hash,idempotency_key,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$supplier,$charge,$new,$kind,$entry,PropertyInstant::sql($at),IssuanceRequest::json($request),$hash,$token,$actor,self::now()]);
        return (int)$this->db->pdo()->lastInsertId();
    }

    private static function versions(array $rows): array
    {
        if(!array_is_list($rows)) PropertyInput::invalid('expected'); $out=[];
        foreach($rows as $row) {
            if(!is_array($row)) PropertyInput::invalid('expected');
            PropertyInput::fields($row,['id','row_version']); $id=PropertyInput::id($row['id']??null,'id');
            if(isset($out[$id])) PropertyInput::invalid('id'); $out[$id]=PropertyInput::id($row['row_version']??null,'row_version');
        }
        ksort($out,SORT_NUMERIC); return $out;
    }
    private static function conflict(): never { throw new IssuanceException('property_charge_issue_revision_conflict','Stav předpisu se změnil. Načtěte aktuální stav.',409); }
    private static function instant(): string { return (new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'); }
    private static function now(): string { return PropertyInstant::sql(self::instant()); }
}
