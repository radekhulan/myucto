<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceJournalProof;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceCaseIdentity;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceRequest;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use PDO;

final class IssuanceCloneScrubber
{
    private const TABLES=['unit_charge_batches','unit_charges','unit_charge_lines','unit_charge_parties','unit_charge_notices',
        'unit_charge_adjustments','unit_charge_evidence_events','unit_charge_issue_audit'];
    private const TEXT=['reason','evidence_ref','legal_reason','title_ref','written_notice_ref','authority_ref','delivery_ref',
        'basis_ref','assessment_scale','name','unit_no'];

    private array $caseReferences=[];

    public function __construct(private readonly Connection $db,private readonly TextScrubber $scrubber) {}

    public function run(array $registryMap,array $versionMap): array
    {
        $pdo=$this->db->pdo(); $marks=implode(',',array_fill(0,count(self::TABLES),'?'));
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$marks.')');
        $stmt->execute(self::TABLES); $count=(int)$stmt->fetchColumn();
        if($count===0) return [];
        if($count!==count(self::TABLES)||$pdo->inTransaction()) throw new \LogicException('Issuance clone requires the complete schema and its own transaction.');
        if(TriggerMetadata::any($pdo))
            throw new \LogicException('Issuance clone must run before installing triggers.');
        $pdo->beginTransaction();
        try {
            $reader=new IssuanceReadRepository($this->db); $ledger=new IssuanceJournalProof($this->db);
            $batches=$this->rows('unit_charge_batches'); $charges=[]; $newCharges=[]; $hashes=[];
            foreach($batches as $row) {
                $batch=$reader->batch((int)$row['supplier_id'],(int)$row['id'],true);
                foreach($batch['charges'] as $charge) {
                    $charges[$charge['id']]=$charge;
                    if($charge['status']==='posted') $ledger->assertEntry($charge['supplier_id'],$charge['journal_entry_id'],$charge);
                    if($charge['status']==='reversed') {
                        $ledger->assertEntry($charge['supplier_id'],$charge['journal_entry_id'],$charge);
                        $ledger->assertEntry($charge['supplier_id'],$charge['reversal_entry_id'],$charge,true);
                    }
                }
            }
            IssuanceCaseIdentity::families(array_values($charges));
            $this->prepareCaseReferences($charges);
            foreach($charges as $id=>$charge) {
                $snapshot=$this->charge($charge['snapshot'],$charge['supplier_id'],$registryMap,$versionMap);
                $hashes[$charge['snapshot_hash']]=$hash=PropertyInput::hash($snapshot);
                $newCharges[$id]=$charge; $newCharges[$id]['snapshot']=$snapshot; $newCharges[$id]['snapshot_hash']=$hash;
                $this->update('unit_charges',$charge,['snapshot_json'=>IssuanceRequest::json($snapshot),'snapshot_hash'=>$hash]);
            }
            foreach(['lines'=>'line_json','parties'=>'party_json','notices'=>'notice_json'] as $table=>$column) foreach($this->rows('unit_charge_'.$table) as $row) {
                $charge=$newCharges[(int)$row['charge_id']]??throw new \RuntimeException('Issuance child has no verified charge.');
                self::same((int)$row['supplier_id'],$charge['supplier_id']);
                $this->update('unit_charge_'.$table,$row,[$column=>IssuanceRequest::json($this->text(self::decode($row[$column]),(int)$row['supplier_id']))]);
            }
            foreach($batches as $row) {
                $request=$this->text(self::decode($row['request_json']),(int)$row['supplier_id']); $snapshot=self::decode($row['snapshot_json']);
                $snapshot['request']=$request;
                $snapshot['version_preview_hash']=$this->version((int)$row['supplier_id'],(int)$row['version_id'],$snapshot['version_preview_hash'],$versionMap);
                foreach($snapshot['charges'] as &$charge) $charge=$this->charge($charge,(int)$row['supplier_id'],$registryMap,$versionMap);
                unset($charge);
                $previewHash=PropertyInput::hash($snapshot);
                $requestHash=PropertyInput::hash(['request'=>$request,'preview_hash'=>$previewHash,'idempotency_key'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]);
                $hashes[$row['preview_hash']]=$previewHash; $hashes[$row['request_hash']]=$requestHash;
                $this->update('unit_charge_batches',$row,['request_json'=>IssuanceRequest::json($request),'snapshot_json'=>IssuanceRequest::json($snapshot),
                    'preview_hash'=>$previewHash,'request_hash'=>$requestHash]);
            }
            foreach(['unit_charge_adjustments','unit_charge_evidence_events'] as $table) foreach($this->rows($table) as $row) {
                $charge=$charges[(int)$row['charge_id']]??throw new \RuntimeException('Issuance event has no verified charge.');
                self::same((int)$row['supplier_id'],$charge['supplier_id']);
                $request=self::decode($row['request_json']);
                IssuanceReadRepository::hash($row['request_hash'],self::envelope($row,$request));
                if($table==='unit_charge_evidence_events') {
                    self::same($row['kind'],$request['kind']); self::same($row['effective_at'],PropertyInstant::sql($request['effective_at']));
                } elseif(in_array($row['kind'],['reversal','cancel_draft'],true)) {
                    self::same($row['effective_at'],PropertyInstant::sql($request['effective_at']));
                    self::same($row['adjustment_charge_id'],null); self::same($row['journal_entry_id'],null);
                    self::same($charge['status'],$row['kind']==='reversal'?'reversed':'cancelled');
                    self::same($charge['row_version'],$request['row_version']+1);
                } else {
                    $next=$charges[(int)$row['adjustment_charge_id']]??throw new \RuntimeException('Issuance correction has no verified successor.');
                    self::same((int)$row['supplier_id'],$next['supplier_id']); self::same($request,['new_charge_id'=>$next['id'],'snapshot_hash'=>$next['snapshot_hash']]);
                    self::same($next['previous_charge_id'],$charge['id']); self::same($row['kind'],$next['issue_mode']);
                    self::same((int)$row['journal_entry_id'],$next['journal_entry_id']);
                }
                $request=$this->replaceHashes($this->text($request,(int)$row['supplier_id']),$hashes);
                $hashes[$row['request_hash']]=$hash=PropertyInput::hash(self::envelope($row,$request));
                $this->update($table,$row,['request_json'=>IssuanceRequest::json($request),'request_hash'=>$hash]);
            }
            foreach($this->rows('unit_charge_issue_audit') as $row) {
                $payload=self::decode($row['payload_json']); IssuanceReadRepository::hash($row['payload_hash'],$payload);
                $charge=$charges[(int)$row['charge_id']]??throw new \RuntimeException('Issuance audit has no verified charge.');
                self::same((int)$row['supplier_id'],$charge['supplier_id']); self::same((int)$row['batch_id'],$charge['batch_id']);
                if(isset($payload['snapshot_hash'])) self::same($payload['snapshot_hash'],$charge['snapshot_hash']);
                $payload=$this->replaceHashes($this->text($payload,(int)$row['supplier_id']),$hashes);
                $this->update('unit_charge_issue_audit',$row,['payload_json'=>IssuanceRequest::json($payload),'payload_hash'=>PropertyInput::hash($payload)]);
            }
            foreach($batches as $row) $reader->batch((int)$row['supplier_id'],(int)$row['id'],true);
            foreach($newCharges as $charge) {
                $reader->evidence($charge['supplier_id'],$charge['id'],true);
                if($charge['status']==='posted') $ledger->assertEntry($charge['supplier_id'],$charge['journal_entry_id'],$charge);
                if($charge['status']==='reversed') $ledger->assertEntry($charge['supplier_id'],$charge['reversal_entry_id'],$charge,true);
            }
            IssuanceCaseIdentity::families(array_values($newCharges));
            $map=[];
            foreach($charges as $id=>$charge)$map[$charge['supplier_id']][$id]=['before_snapshot_hash'=>$charge['snapshot_hash'],'after_snapshot_hash'=>$newCharges[$id]['snapshot_hash']];
            $pdo->commit();
            return $map;
        } catch(\Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    private function charge(array $snapshot,int $supplier,array $registryMap,array $versionMap): array
    {
        $revision=&$snapshot['registry']['revision'];
        $proof=$registryMap[$supplier][$revision['id']]??throw new \RuntimeException('Issuance registry revision proof is missing.');
        self::same($revision['state_hash'],$proof['before_state_hash']); IssuanceReadRepository::hash($revision['state_hash'],$revision['state']);
        $revision['state']=(new PropertyCloneScrubber($this->db->pdo(),$this->scrubber))->scrubSnapshotState('unit',$revision['state']);
        IssuanceReadRepository::hash($proof['after_state_hash'],$revision['state']); $revision['state_hash']=$proof['after_state_hash'];
        unset($revision);
        $snapshot['version_preview_hash']=$this->version($supplier,$snapshot['version_id'],$snapshot['version_preview_hash'],$versionMap);
        foreach($snapshot['parties'] as &$party) $party=json_decode($this->scrubber->scrubJson(IssuanceRequest::json($party)),true,flags:JSON_THROW_ON_ERROR);
        unset($party);
        $snapshot=$this->text($snapshot,$supplier);
        IssuanceReadRepository::hash($snapshot['registry']['revision']['state_hash'],$snapshot['registry']['revision']['state']);
        return $snapshot;
    }
    private function version(int $supplier,int $id,string $hash,array $map): string
    {
        $proof=$map[$supplier][$id]??throw new \RuntimeException('Issuance charge version proof is missing.');
        self::same($hash,$proof['old_preview_hash']); return $proof['new_preview_hash'];
    }
    private function text(array $payload,int $supplier): array
    {
        foreach($payload as $key=>&$value) {
            if($key==='cost_center'&&is_string($value)) $value=$this->scrubber->byStrategy('cost_center',$value);
            elseif($key==='case_identity'&&is_array($value)) {
                foreach(['case_ref','occurrence_ref','decision_ref'] as $field)
                    $value[$field]=$this->caseReferences[$supplier][$field][IssuanceCaseIdentity::canonical($value[$field])]??throw new \RuntimeException('Issuance identity reference has no verified source.');
                foreach(['title_ref','evidence_ref'] as $field) $value[$field]=$this->scrubber->scrub($value[$field]);
                $value=IssuanceCaseIdentity::normalize($value);
            } elseif($key==='case_distinction'&&is_array($value)) $value=IssuanceCaseIdentity::distinction($this->text($value,$supplier));
            elseif(is_array($value)) $value=$this->text($value,$supplier);
            elseif(is_string($value)&&(in_array($key,self::TEXT,true)||($key==='legal_basis'&&isset($payload['recipient_ids'],$payload['effective_at'])))) $value=$this->scrubber->scrub($value);
        }
        unset($value); return $payload;
    }
    private function prepareCaseReferences(array $charges): void
    {
        $references=[]; $this->caseReferences=[];
        foreach($charges as $charge) if(isset($charge['snapshot']['case_identity'])) {
            $identity=IssuanceCaseIdentity::normalize($charge['snapshot']['case_identity']);
            foreach(['case_ref','occurrence_ref','decision_ref'] as $field)
                $references[$charge['supplier_id']][$field][IssuanceCaseIdentity::canonical($identity[$field])]??=$identity[$field];
        }
        foreach($references as $supplier=>$fields) foreach($fields as $field=>$values) {
            ksort($values,SORT_STRING); $used=[]; $max=$field==='decision_ref'?500:160;
            foreach($values as $canonical=>$value) {
                $base=trim($this->scrubber->scrub($value));
                if($base==='') $base='Anonymní případ';
                $base=mb_substr($base,0,$max,'UTF-8'); $next=$base; $suffix=1;
                while(isset($used[IssuanceCaseIdentity::canonical($next)])) {
                    $tail=' ['.++$suffix.']'; $next=mb_substr($base,0,$max-mb_strlen($tail),'UTF-8').$tail;
                }
                $used[IssuanceCaseIdentity::canonical($next)]=true;
                $this->caseReferences[$supplier][$field][$canonical]=$next;
            }
        }
    }

    private function replaceHashes(array $payload,array $hashes): array
    {
        foreach($payload as $key=>&$value) if(in_array($key,['snapshot_hash','request_hash','preview_hash'],true))
            $value=$hashes[$value]??throw new \RuntimeException('Issuance audit hash has no verified owner.');
        unset($value); return $payload;
    }
    private static function envelope(array $row,array $request): array
    {
        return ['charge_id'=>(int)$row['charge_id'],'request'=>$request,'idempotency_key'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']];
    }
    private static function same(mixed $a,mixed $b): void
    {
        if(PropertyInput::hash(['value'=>$a])!==PropertyInput::hash(['value'=>$b])) throw new \RuntimeException('Issuance clone materialization mismatch.');
    }
    private function rows(string $table): array { return $this->db->pdo()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC); }
    private function update(string $table,array $row,array $changes): void
    {
        $sets=implode(',',array_map(static fn(string $key): string=>$key.'=?',array_keys($changes)));
        $this->db->pdo()->prepare('UPDATE '.$table.' SET '.$sets.' WHERE supplier_id=? AND id=?')->execute([...array_values($changes),$row['supplier_id'],$row['id']]);
    }
    private static function decode(string $json): array { return json_decode($json,true,flags:JSON_THROW_ON_ERROR); }
}
