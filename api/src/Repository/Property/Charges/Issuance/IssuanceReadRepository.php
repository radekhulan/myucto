<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property\Charges\Issuance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use PDO;

final class IssuanceReadRepository
{
    /** Pod limitem zástupných parametrů MariaDB i u firmy s tisíci předpisů. */
    private const CHUNK = 500;

    public function __construct(private readonly Connection $db) {}

    /** Předpisy vybraných jednotek po indexu (supplier_id, unit_id, source_key, revision_no). */
    public function forUnits(int $supplier,array $unitIds,bool $current=false): array
    {
        foreach($unitIds as $id) PropertyInput::id($id,'unit_id');
        $unitIds=array_values(array_unique($unitIds)); sort($unitIds,SORT_NUMERIC); $out=[];
        foreach(array_chunk($unitIds,self::CHUNK) as $chunk) {
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charges WHERE supplier_id=? AND unit_id IN ('.$marks.') ORDER BY id'.$this->lock($current));
            $stmt->execute([$supplier,...$chunk]);
            $rows=array_map($this->decodeCharge(...),$stmt->fetchAll(PDO::FETCH_ASSOC));
            $this->assertMaterialized($supplier,$rows,$current);
            array_push($out,...$rows);
        }
        usort($out,fn(array $a,array $b):int=>$a['id']<=>$b['id']);
        return $out;
    }

    /** Navazující aktivní revize téhož případu jednotky; storno zamyká jen tuto rodinu předpisů. */
    public function activeSuccessor(int $supplier,array $charge): ?int
    {
        $stmt=$this->db->pdo()->prepare("SELECT id FROM unit_charges WHERE supplier_id=? AND unit_id=? AND source_key=? AND revision_no>? ORDER BY revision_no".$this->lock(true));
        $stmt->execute([$supplier,$charge['unit_id'],$charge['source_key'],$charge['revision_no']]);
        $active=null;
        foreach($this->findMany($supplier,array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)),true) as $next)
            if($active===null&&in_array($next['status'],['draft','posted'],true)) $active=$next['id'];
        return $active;
    }

    public function charges(int $supplier, bool $current = false): array
    {
        $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charges WHERE supplier_id=? ORDER BY id'.$this->lock($current));
        $stmt->execute([$supplier]);
        $rows=array_map($this->decodeCharge(...),$stmt->fetchAll(PDO::FETCH_ASSOC));
        $this->assertMaterialized($supplier,$rows,$current);
        return $rows;
    }

    public function stream(int $supplier,bool $current=false): \Generator
    {
        if(!$this->db->pdo()->inTransaction()) throw new \LogicException('Issuance stream requires a consistent transaction.');
        $after=0;
        do {
            $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charges WHERE supplier_id=? AND id>? ORDER BY id LIMIT 500'.$this->lock($current));
            $stmt->execute([$supplier,$after]); $charges=[];
            while($row=$stmt->fetch(PDO::FETCH_ASSOC)) $charges[]=$this->decodeCharge($row);
            $stmt->closeCursor();
            $this->assertMaterialized($supplier,$charges,$current);
            foreach($charges as $charge) { $after=$charge['id']; yield $charge; }
            $count=count($charges); unset($charges,$charge,$row);
        } while($count===500);
    }

    public function previewHistory(int $supplier,array $request,bool $current=false):array
    {
        $cteLock=$this->lock($current);$unitIds=array_values(array_unique(array_map('intval',array_column($request['units'],'unit_id'))));sort($unitIds,SORT_NUMERIC);
        if($unitIds===[])return ['source'=>[],'targets'=>[],'families'=>[]];
        $source=[];$targetIds=[];$families=[];
        foreach(array_chunk($unitIds,500)as$chunk){
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charges FORCE INDEX (uq_issue_charge_revision) WHERE supplier_id=? AND unit_id IN ('.$marks.') AND source_key=? ORDER BY unit_id,revision_no,id'.$this->lock($current));
            $stmt->execute([$supplier,...$chunk,$request['source_key']]);$rows=array_map($this->decodeCharge(...),$stmt->fetchAll(PDO::FETCH_ASSOC));$this->assertMaterialized($supplier,$rows,$current);array_push($source,...$rows);
            $stmt=$this->db->pdo()->prepare("WITH ranked AS (
                SELECT c.id,ROW_NUMBER() OVER (PARTITION BY c.unit_id,l.component_id ORDER BY c.period_from DESC,c.id DESC) position
                FROM unit_charges c FORCE INDEX (idx_unit_charge_preview_target)
                JOIN unit_charge_lines l ON l.supplier_id=c.supplier_id AND l.charge_id=c.id
                WHERE c.supplier_id=? AND c.unit_id IN ({$marks}) AND c.status IN ('draft','posted','reversed') AND c.period_from<=?{$cteLock}
            ) SELECT id FROM ranked WHERE position=1 ORDER BY id");
            $stmt->execute([$supplier,...$chunk,$request['period_from']]);foreach($stmt->fetchAll(PDO::FETCH_COLUMN)as$id)$targetIds[(int)$id]=true;
            $stmt=$this->db->pdo()->prepare("WITH ordered AS (
                SELECT c.id,c.unit_id,c.source_key,c.revision_no,c.previous_charge_id,c.period_from,c.period_to,c.status,c.row_version,c.snapshot_json,
                    ROW_NUMBER() OVER (PARTITION BY c.unit_id,c.source_key ORDER BY c.revision_no,c.id) position,
                    COUNT(*) OVER (PARTITION BY c.unit_id,c.source_key) family_count,
                    LAG(c.id) OVER (PARTITION BY c.unit_id,c.source_key ORDER BY c.revision_no,c.id) prior_id
                FROM unit_charges c FORCE INDEX (uq_issue_charge_revision)
                WHERE c.supplier_id=? AND c.unit_id IN ({$marks}){$cteLock}
            ) SELECT unit_id,source_key,
                MAX(CASE WHEN position=1 THEN id END) root_charge_id,
                MAX(CASE WHEN position=family_count THEN id END) latest_charge_id,
                MAX(CASE WHEN position=family_count THEN revision_no END) latest_revision,
                MAX(CASE WHEN position=family_count THEN status END) latest_status,
                MAX(CASE WHEN position=family_count THEN row_version END) latest_row_version,
                MAX(CASE WHEN position=1 THEN CAST(JSON_EXTRACT(snapshot_json,'$.case_identity') AS CHAR) END) case_identity_json,
                MAX(CASE WHEN position=1 THEN CAST(JSON_EXTRACT(snapshot_json,'$.case_distinction') AS CHAR) END) case_distinction_json,
                COUNT(DISTINCT COALESCE(CAST(JSON_EXTRACT(snapshot_json,'$.case_identity') AS CHAR),'__missing__')) identity_variants,
                COUNT(DISTINCT COALESCE(CAST(JSON_EXTRACT(snapshot_json,'$.case_distinction') AS CHAR),'__missing__')) distinction_variants,
                MIN(CASE WHEN revision_no=position AND ((position=1 AND previous_charge_id IS NULL) OR (position>1 AND previous_charge_id=prior_id)) THEN 1 ELSE 0 END) chain_valid,
                MAX(CASE WHEN period_from<=? AND period_to>=? THEN 1 ELSE 0 END) period_overlap
            FROM ordered GROUP BY unit_id,source_key ORDER BY unit_id,source_key");
            $stmt->execute([$supplier,...$chunk,$request['period_to'],$request['period_from']]);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC)as$row){
                if(!(bool)$row['chain_valid']||(int)$row['identity_variants']!==1||(int)$row['distinction_variants']!==1)self::corrupt();
                $identity=$row['case_identity_json']===null?null:json_decode($row['case_identity_json'],true,flags:JSON_THROW_ON_ERROR);
                $distinction=$row['case_distinction_json']===null?null:json_decode($row['case_distinction_json'],true,flags:JSON_THROW_ON_ERROR);
                if($identity!==null&&\MyInvoice\Service\Property\Charges\Issuance\IssuanceCaseIdentity::normalize($identity)!==$identity)self::corrupt();
                if($distinction!==null&&\MyInvoice\Service\Property\Charges\Issuance\IssuanceCaseIdentity::distinction($distinction)!==$distinction)self::corrupt();
                $families[]=['supplier_id'=>$supplier,'unit_id'=>(int)$row['unit_id'],'source_key'=>$row['source_key'],
                    'root_charge_id'=>(int)$row['root_charge_id'],'case_identity'=>$identity,'case_distinction'=>$distinction,
                    'identity_status'=>$identity===null?'legacy_unclassified':'explicit','latest_charge_id'=>(int)$row['latest_charge_id'],
                    'latest_revision'=>(int)$row['latest_revision'],'status'=>$row['latest_status'],'row_version'=>(int)$row['latest_row_version'],
                    'periods'=>(bool)$row['period_overlap']?[[$request['period_from'],$request['period_to']]]:[]];
            }
        }
        $targets=$targetIds==[]?[]:array_values($this->findMany($supplier,array_keys($targetIds),$current));
        return ['source'=>$source,'targets'=>$targets,'families'=>$families];
    }

    public function find(int $supplier,int $id,bool $current = false): array
    {
        return $this->findMany($supplier,[$id],$current)[$id];
    }

    public function findMany(int $supplier,array $ids,bool $current = false): array
    {
        foreach($ids as $id) PropertyInput::id($id,'charge_id');
        // Aktuální čtení se v jedné transakci opakuje jen po zápisu ({@see \MyInvoice\Infrastructure\Database\CurrentReadMemo}).
        if($current) return \MyInvoice\Infrastructure\Database\CurrentReadMemo::remember($this->db,'charges|'.$supplier.'|'.implode(',',$ids),fn(): array => $this->readCharges($supplier,$ids,true));
        return $this->readCharges($supplier,$ids,false);
    }

    private function readCharges(int $supplier,array $ids,bool $current): array
    {
        $ids=array_values(array_unique($ids)); sort($ids,SORT_NUMERIC); $out=[];
        foreach(array_chunk($ids,500) as $chunk) {
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charges WHERE supplier_id=? AND id IN ('.$marks.') ORDER BY id'.$this->lock($current));
            $stmt->execute([$supplier,...$chunk]);
            $charges=array_map($this->decodeCharge(...),$stmt->fetchAll(PDO::FETCH_ASSOC));
            if(count($charges)!==count($chunk)) throw new IssuanceException('property_charge_issue_not_found','Předpis nebyl nalezen.',404);
            $this->assertMaterialized($supplier,$charges,$current);
            foreach($charges as $charge) $out[$charge['id']]=$charge;
        }
        return $out;
    }

    public function batch(int $supplier,int $id,bool $current = false): array
    {
        $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charge_batches WHERE supplier_id=? AND id=?'.$this->lock($current));
        $stmt->execute([$supplier,$id]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new IssuanceException('property_charge_issue_not_found','Dávka předpisů nebyla nalezena.',404);
        $request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);
        $snapshot=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
        self::hash($row['preview_hash'],$snapshot);
        self::hash($row['request_hash'],['request'=>$request,'preview_hash'=>$row['preview_hash'],'idempotency_key'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]);
        if(PropertyInput::hash($request)!==PropertyInput::hash($snapshot['request'])) self::corrupt();
        $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charges WHERE supplier_id=? AND batch_id=? ORDER BY unit_id'.$this->lock($current));
        $stmt->execute([$supplier,$id]); $charges=array_map($this->decodeCharge(...),$stmt->fetchAll(PDO::FETCH_ASSOC));
        if(count($charges)!==count($snapshot['charges'])) self::corrupt();
        $expected=array_column($snapshot['charges'],null,'unit_id');
        foreach($charges as $charge) if(!isset($expected[$charge['unit_id']])||PropertyInput::hash($expected[$charge['unit_id']])!==$charge['snapshot_hash']) self::corrupt();
        $this->assertMaterialized($supplier,$charges,$current);
        return ['id'=>(int)$row['id'],'supplier_id'=>$supplier,'version_id'=>(int)$row['version_id'],'request'=>$request,'snapshot'=>$snapshot,
            'preview_hash'=>$row['preview_hash'],'created_by'=>(int)$row['created_by'],'recorded_at'=>$row['recorded_at'],'charges'=>$charges];
    }

    public function evidence(int $supplier,int $chargeId,bool $current=false): array
    {
        $this->find($supplier,$chargeId,$current);
        $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charge_evidence_events WHERE supplier_id=? AND charge_id=? ORDER BY id'.$this->lock($current));
        $stmt->execute([$supplier,$chargeId]); $out=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);
            self::hash($row['request_hash'],['charge_id'=>$chargeId,'request'=>$request,'idempotency_key'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']]);
            if($row['kind']!==$request['kind']||$row['effective_at']!==\MyInvoice\Service\Property\PropertyInstant::sql($request['effective_at'])) self::corrupt();
            $out[]=['id'=>(int)$row['id'],'charge_id'=>$chargeId,'request'=>$request,'created_by'=>(int)$row['created_by'],'recorded_at'=>$row['recorded_at']];
        }
        return $out;
    }

    private function decodeCharge(array $row): array
    {
        $snapshot=json_decode($row['snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
        self::hash($row['snapshot_hash'],$snapshot);
        foreach(['id','supplier_id','batch_id','unit_id','revision_no','previous_charge_id','row_version','journal_entry_id','reversal_entry_id'] as $field) if($row[$field]!==null) $row[$field]=(int)$row[$field];
        foreach(['unit_id','revision_no','previous_charge_id','title','issue_mode','period_from','period_to','accounting_date','due_date','source_key','amount_cents'] as $field) if($row[$field]!==$snapshot[$field]) self::corrupt();
        foreach(['claim_origin_at','issued_at'] as $field) if($row[$field]!==\MyInvoice\Service\Property\PropertyInstant::sql($snapshot[$field])) self::corrupt();
        unset($row['snapshot_json']);
        return $row+['snapshot'=>$snapshot];
    }

    private function assertMaterialized(int $supplier,array $charges,bool $current): void
    {
        if($charges===[]) return;
        if(count($charges)>self::CHUNK) {
            foreach(array_chunk($charges,self::CHUNK) as $chunk) $this->assertMaterialized($supplier,$chunk,$current);
            return;
        }
        $ids=array_column($charges,'id'); sort($ids,SORT_NUMERIC);
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $actual=['lines'=>[],'parties'=>[],'notices'=>[]];
        foreach(['lines','parties','notices'] as $kind) {
            $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charge_'.$kind.' WHERE supplier_id=? AND charge_id IN ('.$marks.') ORDER BY charge_id,id'.$this->lock($current));
            $stmt->execute([$supplier,...$ids]);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id=(int)$row['charge_id'];
                if($kind==='lines') $actual[$kind][$id][(int)$row['component_id']]=['component_id'=>(int)$row['component_id'],
                    'service_id'=>$row['service_id']===null?null:(int)$row['service_id'],'amount_cents'=>$row['amount_cents'],
                    'line'=>json_decode($row['line_json'],true,flags:JSON_THROW_ON_ERROR)];
                elseif($kind==='parties') $actual[$kind][$id][$row['role'].':'.$row['client_id']]=['client_id'=>(int)$row['client_id'],
                    'group_key'=>$row['group_key'],'role'=>$row['role'],'group'=>json_decode($row['party_json'],true,flags:JSON_THROW_ON_ERROR)];
                else $actual[$kind][$id][(int)$row['component_id']]=json_decode($row['notice_json'],true,flags:JSON_THROW_ON_ERROR);
            }
        }
        foreach($charges as $charge) {
            $expected=['lines'=>[],'parties'=>[],'notices'=>[]];
            foreach($charge['snapshot']['lines'] as $line) $expected['lines'][$line['component_id']]=['component_id'=>$line['component_id'],
                'service_id'=>$line['service_id'],'amount_cents'=>$line['amount_cents'],'line'=>$line];
            foreach($charge['snapshot']['liability_groups'] as $group) foreach($group['members'] as $client) $expected['parties'][$group['role'].':'.$client]=[
                'client_id'=>$client,'group_key'=>$group['group_key'],'role'=>$group['role'],'group'=>$group];
            foreach($charge['snapshot']['notices'] as $notice) $expected['notices'][$notice['component_id']]=$notice['notice'];
            foreach($expected as $kind=>$rows) if(!PropertyInput::sameJsonGraph($rows,$actual[$kind][$charge['id']]??[])) self::corrupt();
        }
    }

    private function lock(bool $current): string
    {
        if($current&&!$this->db->pdo()->inTransaction()) throw new \LogicException('Issuance current reads require period/catalog locks.');
        return $current?' FOR UPDATE':'';
    }

    public static function hash(string $hash,array $payload): void
    {
        if(!hash_equals($hash,PropertyInput::hash($payload))) self::corrupt();
    }

    public static function corrupt(): never
    {
        throw new IssuanceException('property_charge_issue_history_corrupt','Historický předpis neodpovídá uloženému dokladu a otisku.',409);
    }
}
