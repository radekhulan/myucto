<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Service\Accounting\JournalLineComparison;
use MyInvoice\Service\ActivityLogHashChain;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Payroll\{PayrollPostingBatchRepository, PayrollStatutoryResultRepository};
use MyInvoice\Service\Payroll\Posting\PayrollPostingLineBuilder;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use PDO;

final class PayrollCostCenterCloneScrubber
{
    private const PAIRS = [
        'payroll_run_revisions' => ['input_snapshot_json'=>'input_snapshot_hash','result_snapshot_json'=>'result_snapshot_hash'],
        'payroll_run_employments' => ['input_json'=>'input_hash','result_json'=>'result_hash'],
        'payroll_run_persons' => ['result_json'=>'result_hash'],
        'payroll_statutory_results' => ['input_snapshot_json'=>'input_snapshot_hash','result_snapshot_json'=>'result_snapshot_hash'],
        'payroll_statutory_person_results' => ['input_snapshot_json'=>'input_snapshot_hash','result_snapshot_json'=>'result_snapshot_hash'],
        'payroll_statutory_relationship_results' => ['input_snapshot_json'=>'input_snapshot_hash','result_snapshot_json'=>'result_snapshot_hash'],
        'payroll_net_results' => ['result_json'=>'result_hash'],
    ];

    public static function capture(Connection $db): array
    {
        return self::captureGraph($db);
    }

    private static function captureGraph(Connection $db,?array $ledgerProofs=null): array
    {
        $pdo=$db->pdo();
        $captured=[];
        foreach(self::PAIRS as $table=>$pairs) {
            $captured[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY supplier_id,id')->fetchAll(PDO::FETCH_ASSOC);
            foreach($captured[$table] as $row) foreach($pairs as $json=>$hash) {
                if($row[$json]===null && $row[$hash]===null)continue;
                self::verified($row[$json],$row[$hash],$table.'.'.$row['id'].'.'.$json);
            }
        }
        foreach($captured['payroll_run_revisions'] as $revision) {
            if($revision['result_snapshot_json']===null)continue;
            $result=json_decode($revision['result_snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
            if(($result['source_snapshot_hash']??null)!==$revision['input_snapshot_hash'])throw new \DomainException('Payroll clone source result has stale input lineage.');
        }
        self::assertRunGraphs($captured);
        $repository=new PayrollStatutoryResultRepository($db);
        foreach($captured['payroll_statutory_results'] as $row) {
            $set=$repository->find((int)$row['supplier_id'],(int)$row['revision_id'],$row['calculation_kind']);
            if($set===null || !hash_equals($row['result_set_hash'],self::setHash($repository,$set)))throw new \DomainException('Payroll clone statutory set proof is invalid.');
        }
        $captured['batches']=$pdo->query('SELECT * FROM payroll_posting_batches ORDER BY supplier_id,run_id,id')->fetchAll(PDO::FETCH_ASSOC);
        $captured['ledger_proofs']=$ledgerProofs??self::ledgerProofs($db);
        foreach($captured['batches'] as $batch) {
            $preview=self::preview($db,$batch);
            if(!hash_equals($batch['target_hash'],$preview->targetHash)||!hash_equals($batch['delta_hash'],$preview->deltaHash))throw new \DomainException('Payroll clone posting source hash is invalid.');
            self::assertAllocations($pdo,$batch,$preview->targetAllocations);
            self::assertLedger($db,$batch,$preview->lines,$captured['ledger_proofs'][(int)$batch['id']]??null);
        }
        return $captured;
    }

    public static function run(Connection $db, TextScrubber $scrubber, array $captured): void
    {
        $pdo=$db->pdo();
        if($pdo->inTransaction() || TriggerMetadata::any($pdo))throw new \LogicException('Payroll clone requires its own transaction before trigger installation.');
        $pdo->beginTransaction();
        try {
            foreach(self::PAIRS as $table=>$pairs) {
                $ids=$pdo->query('SELECT id FROM '.$table.' ORDER BY supplier_id,id')->fetchAll(PDO::FETCH_COLUMN);
                if(array_map('intval',$ids)!==array_map(static fn(array $row):int=>(int)$row['id'],$captured[$table]??[]))throw new \DomainException('Payroll clone captured graph is incomplete.');
                foreach($captured[$table] as $row) {
                    $values=[];
                    foreach($pairs as $json=>$hash) {
                        if($row[$json]===null)continue;
                        $data=self::transformed($scrubber,$row[$json]);
                        if($table==='payroll_run_revisions' && $json==='result_snapshot_json') {
                            $input=self::transformed($scrubber,$row['input_snapshot_json']);
                            $data['source_snapshot_hash']=hash('sha256',CanonicalJson::encode($input));
                        }
                        $values[$json]=CanonicalJson::encode($data);
                        $values[$hash]=hash('sha256',$values[$json]);
                        if($table==='payroll_statutory_person_results' && $json==='result_snapshot_json' && $values[$hash]!==$row[$hash])throw new \DomainException('Payroll clone cannot rebase a changed statutory financial person result.');
                    }
                    self::update($pdo,$table,$row,$values);
                }
            }
            $repository=new PayrollStatutoryResultRepository($db);
            foreach($captured['payroll_statutory_results'] as $row) {
                $set=$repository->find((int)$row['supplier_id'],(int)$row['revision_id'],$row['calculation_kind']);
                if($set===null)throw new \DomainException('Payroll statutory set disappeared during clone.');
                self::update($pdo,'payroll_statutory_results',$row,['result_set_hash'=>self::setHash($repository,$set)]);
            }
            $ledgerProofs=[];
            foreach($captured['ledger_proofs'] as $id=>$proof)$ledgerProofs[$id]=['audit_id'=>$proof['audit_id'],'payload'=>self::transformed($scrubber,CanonicalJson::encode($proof['payload']))];
            foreach($captured['batches'] as $batch) {
                $preview=self::preview($db,$batch);
                self::assertAllocations($pdo,$batch,$preview->targetAllocations);
                self::assertLedger($db,$batch,$preview->lines,$ledgerProofs[(int)$batch['id']]??null);
                self::update($pdo,'payroll_posting_batches',$batch,['target_hash'=>$preview->targetHash,'delta_hash'=>$preview->deltaHash]);
            }
            self::captureGraph($db,$ledgerProofs);
            $pdo->commit();
        } catch(\Throwable $error) {
            if($pdo->inTransaction())$pdo->rollBack();
            throw $error;
        }
    }

    private static function assertRunGraphs(array $captured): void
    {
        $employments=[];
        $persons=[];
        $inputs=[];
        foreach($captured['payroll_run_revisions'] as $revision) {
            $key=$revision['supplier_id'].':'.$revision['id'];
            $input=json_decode($revision['input_snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
            $inputs[$key]=$revision['input_snapshot_hash'];
            foreach($input['people'] as $person) {
                $personKey=$key.':'.$person['employee']['id'];
                $persons[$personKey]=null;
                foreach($person['employments'] as $employment)$employments[$personKey.':'.$employment['employment']['id']]=[CanonicalJson::encode($employment),null];
            }
            if($revision['result_snapshot_json']===null)continue;
            $result=json_decode($revision['result_snapshot_json'],true,flags:JSON_THROW_ON_ERROR);
            foreach($result['people'] as $person) {
                $personKey=$key.':'.$person['employee_id'];
                if(!array_key_exists($personKey,$persons))throw new \DomainException('Payroll clone person source lineage is invalid.');
                $persons[$personKey]=CanonicalJson::encode($person);
                foreach($person['employments'] as $employment) {
                    $employmentKey=$personKey.':'.$employment['employment_id'];
                    if(!isset($employments[$employmentKey]))throw new \DomainException('Payroll clone employment source lineage is invalid.');
                    $employments[$employmentKey][1]=CanonicalJson::encode($employment);
                }
            }
        }
        $actualEmployments=[];
        foreach($captured['payroll_run_employments'] as $row)$actualEmployments[$row['supplier_id'].':'.$row['revision_id'].':'.$row['employee_id'].':'.$row['employment_id']]=[$row['input_json'],$row['result_json']];
        $actualPersons=[];
        foreach($captured['payroll_run_persons'] as $row)$actualPersons[$row['supplier_id'].':'.$row['revision_id'].':'.$row['employee_id']]=$row['result_json'];
        ksort($persons);ksort($actualPersons);ksort($employments);ksort($actualEmployments);
        if($persons!==$actualPersons||$employments!==$actualEmployments)throw new \DomainException('Payroll clone native revision graph is incomplete or stale.');
        foreach($captured['payroll_statutory_results'] as $row)if(($inputs[$row['supplier_id'].':'.$row['revision_id']]??null)!==$row['input_snapshot_hash'])throw new \DomainException('Payroll clone statutory input lineage is invalid.');
    }
    private static function verified(mixed $json,mixed $hash,string $label): array
    {
        if(!is_string($json)||!is_string($hash)||preg_match('/^[0-9a-f]{64}$/D',$hash)!==1||!hash_equals($hash,hash('sha256',$json)))throw new \DomainException('Payroll clone source snapshot proof is invalid: '.$label);
        $data=json_decode($json,true,flags:JSON_THROW_ON_ERROR);
        if(!is_array($data)||CanonicalJson::encode($data)!==$json)throw new \DomainException('Payroll clone source snapshot is not canonical: '.$label);
        self::officeProofs($data,false);
        return $data;
    }

    private static function transformed(TextScrubber $scrubber,string $json): array
    {
        $data=json_decode($scrubber->scrubJson($json),true,flags:JSON_THROW_ON_ERROR);
        self::officeProofs($data,true);
        return $data;
    }

    private static function officeProofs(array &$data,bool $rewrite): void
    {
        foreach($data as $key=>&$value) {
            if(!is_array($value))continue;
            if($key==='office_registration' && isset($value['sha256'])) {
                $hash=PayrollRunSnapshotBuilder::officeRegistrationHash($value);
                if(!$rewrite && !hash_equals($value['sha256'],$hash))throw new \DomainException('Payroll clone office registration proof is invalid.');
                if($rewrite)$value['sha256']=$hash;
            } else self::officeProofs($value,$rewrite);
        }
    }
    private static function setHash(PayrollStatutoryResultRepository $repository,array $set): string
    {
        return $repository->resultSetHash($set['calculation_kind'],$set['schema_version'],$set['result_status'],$set['ruleset_id'],$set['ruleset_hash'],$set['input_snapshot'],$set['result_snapshot'],$set['people']);
    }

    private static function preview(Connection $db,array $batch): \MyInvoice\Service\Payroll\Posting\PayrollPostingPreview
    {
        $query=$db->pdo()->prepare('SELECT * FROM payroll_run_revisions WHERE supplier_id=? AND id=?');
        $query->execute([$batch['supplier_id'],$batch['revision_id']]);
        $revision=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($revision))throw new \DomainException('Payroll clone posting revision is missing.');
        $input=self::verified($revision['input_snapshot_json'],$revision['input_snapshot_hash'],'posting.input');
        $result=self::verified($revision['result_snapshot_json'],$revision['result_snapshot_hash'],'posting.result');
        $repository=new PayrollStatutoryResultRepository($db);
        $sets=[];
        foreach(['social_insurance','health_insurance','income_tax','net_pay'] as $kind) {
            $set=$repository->find((int)$batch['supplier_id'],(int)$batch['revision_id'],$kind);
            if($set===null)throw new \DomainException('Payroll clone posting statutory set is missing.');
            $sets[$kind]=$set;
        }
        $batches=new PayrollPostingBatchRepository($db,new AccountingPeriodRepository($db));
        $previous=$batches->latestEffectiveBefore((int)$batch['supplier_id'],(int)$batch['run_id'],(int)$revision['revision_no']);
        if(($previous['id']??null)!==($batch['previous_batch_id']===null?null:(int)$batch['previous_batch_id']))throw new \DomainException('Payroll clone posting correction lineage is invalid.');
        return (new PayrollPostingLineBuilder())->build($input,$result,$sets,$input['employer']['accounting_accounts'],$previous['allocations']??[]);
    }

    private static function assertAllocations(PDO $pdo,array $batch,array $expected): void
    {
        $query=$pdo->prepare('SELECT allocation_key,account_code,signed_minor,description,cost_center,dimensions FROM payroll_posting_allocations WHERE supplier_id=? AND batch_id=? ORDER BY allocation_key');
        $query->execute([$batch['supplier_id'],$batch['id']]);
        $actual=[];
        foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $value=['allocation_key'=>$row['allocation_key'],'account_code'=>$row['account_code'],'signed_minor'=>(int)$row['signed_minor'],'description'=>$row['description']];
            if($row['cost_center']!==null)$value['cost_center']=$row['cost_center'];
            if($row['dimensions']!==null)$value['dimensions']=array_map('intval',json_decode($row['dimensions'],true,flags:JSON_THROW_ON_ERROR));
            $actual[]=$value;
        }
        usort($expected,static fn(array $a,array $b):int=>strcmp($a['allocation_key'],$b['allocation_key']));
        if(CanonicalJson::encode($actual)!==CanonicalJson::encode($expected))throw new \DomainException('Payroll clone native allocations differ from canonical owner.');
    }

    private static function ledgerProofs(Connection $db): array
    {
        $rows=$db->pdo()->query("SELECT b.id AS batch_id,a.id AS audit_id,a.hash,a.payload FROM payroll_posting_batches b JOIN activity_log a ON a.supplier_id=b.supplier_id AND a.entity_type='journal_entry' AND a.entity_id=b.journal_entry_id AND a.action='accounting.posted' WHERE b.journal_entry_id IS NOT NULL ORDER BY a.id")->fetchAll(PDO::FETCH_ASSOC);
        $proofs=[];
        foreach($rows as $row) {
            $id=(int)$row['batch_id'];
            if(isset($proofs[$id])||!is_string($row['hash'])||preg_match('/^[0-9a-f]{64}$/D',$row['hash'])!==1)throw new \DomainException('Payroll clone posting audit proof is missing or ambiguous.');
            $proofs[$id]=['audit_id'=>(int)$row['audit_id'],'payload'=>json_decode($row['payload'],true,flags:JSON_THROW_ON_ERROR)];
        }
        if($rows!==[]&&!(new ActivityLogHashChain($db))->verify((int)$rows[0]['audit_id'])['ok'])throw new \DomainException('Payroll clone posting audit proof is invalid.');
        return $proofs;
    }

    private static function assertLedger(Connection $db,array $batch,array $expected,?array $proof): void
    {
        $pdo=$db->pdo();
        if($batch['journal_entry_id']===null) {
            if($expected!==[])throw new \DomainException('Payroll clone native journal is missing.');
            return;
        }
        $headerQuery=$pdo->prepare('SELECT source_type,source_id,entry_date,posted_at,reversed_by FROM journal_entries WHERE supplier_id=? AND id=?');
        $headerQuery->execute([$batch['supplier_id'],$batch['journal_entry_id']]);
        $header=$headerQuery->fetch(PDO::FETCH_ASSOC);
        if(!is_array($header)||$header['source_type']!=='payroll'||(int)$header['source_id']!==(int)$batch['revision_id']||$header['entry_date']!==$batch['entry_date']||$header['posted_at']===null||$header['reversed_by']!==null)throw new \DomainException('Payroll clone native journal header differs from canonical owner.');
        $query=$pdo->prepare('SELECT * FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? ORDER BY id');
        $query->execute([$batch['supplier_id'],$batch['journal_entry_id']]);
        $actual=$query->fetchAll(PDO::FETCH_ASSOC);
        $assignments=new DimensionAssignmentRepository($db);
        $dimensions=$assignments->entryLineDimensions((int)$batch['supplier_id'],(int)$batch['journal_entry_id']);
        $splits=$assignments->entryLineSplits((int)$batch['supplier_id'],(int)$batch['journal_entry_id']);
        foreach($actual as &$row) {
            if((int)$row['is_red_storno']!==0||$row['currency_code']!==null||$row['fx_rate']!==null||$row['amount_foreign']!==null)throw new \DomainException('Payroll clone native journal currency or storno differs from canonical owner.');
            $row['dimensions']=$dimensions[(int)$row['id']]??[];
            $row['dimension_splits']=$splits[(int)$row['id']]??[];
        }
        unset($row);
        if($proof===null)throw new \DomainException('Payroll clone posting audit proof is missing.');
        $audit=$pdo->prepare("SELECT payload FROM activity_log WHERE id=? AND supplier_id=? AND entity_type='journal_entry' AND entity_id=? AND action='accounting.posted'");
        $audit->execute([$proof['audit_id'],$batch['supplier_id'],$batch['journal_entry_id']]);
        $payload=$audit->fetchColumn();
        if(!is_string($payload)||CanonicalJson::encode(json_decode($payload,true,flags:JSON_THROW_ON_ERROR))!==CanonicalJson::encode($proof['payload']))throw new \DomainException('Payroll clone posting audit proof differs from captured source.');
        $payload=$proof['payload'];
        if(($payload['source_type']??null)!=='payroll'||($payload['source_id']??null)!==(int)$batch['revision_id']||($payload['reposted']??null)!==false||!is_array($payload['lines']??null))throw new \DomainException('Payroll clone posting audit proof has invalid owner lineage.');
        if(!JournalLineComparison::sameLines($actual,$payload['lines']))throw new \DomainException('Payroll clone native journal differs from canonical owner.');
    }

    private static function update(PDO $pdo,string $table,array $row,array $values): void
    {
        if($values===[])return;
        $assignments=implode(',',array_map(static fn(string $key):string=>$key.'=?',array_keys($values)));
        if(array_key_exists('updated_at',$row))$assignments.=',updated_at=updated_at';
        $query=$pdo->prepare('UPDATE '.$table.' SET '.$assignments.' WHERE supplier_id=? AND id=?');
        $query->execute([...array_values($values),$row['supplier_id'],$row['id']]);
    }
}