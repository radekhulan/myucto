<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Services\Settlement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Services\Settlement\ServiceLifecycleRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Payments\{PaymentLocks,PaymentJournalProof};
use MyInvoice\Service\Property\{PropertyInput,PropertyException};
use PDO;

final class ServiceLifecycleWorkflow
{
    public function __construct(private readonly Connection $db, private readonly ServiceLifecycleRepository $owner) {}

    public static function permission(string $operation): string
    {
        return match ($operation) {
            'start','start_opening' => 'property.settlements.manage',
            'unwind' => 'property.settlements.unwind',
            'finalize' => 'property.settlements.post',
            default => throw new PropertyException('property_invalid_field','Neplatná operace.',422),
        };
    }

    public function preview(int $supplier, array $command, int $actor): array
    {
        return $this->transaction($supplier, function () use ($supplier,$command,$actor): array {
            $command = $this->normalize($command);
            $version = $this->version($supplier,$command);
            $plan = $this->simulate($supplier,$command,$actor);
            return ['expected_state'=>$version,'preview_hash'=>PropertyInput::hash([$supplier,$actor,$command,$version,$plan]),'plan'=>$plan];
        });
    }

    public function execute(int $supplier,array $command,int $actor,string $expected,string $hash,string $key): array
    {
        SettlementEvidence::hash($expected); SettlementEvidence::hash($hash); PropertyInput::code($key,'idempotency_key');
        return $this->transaction($supplier,function () use ($supplier,$command,$actor,$expected,$hash,$key): array {
            $command = $this->normalize($command);
            $identity = PropertyInput::hash([$actor,$command,$expected,$hash]);
            $q=$this->db->pdo()->prepare('SELECT request_hash,response_json FROM property_service_lifecycle_commands WHERE supplier_id=? AND idempotency_key=? FOR UPDATE');
            $q->execute([$supplier,$key]); $old=$q->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                if (!hash_equals($old['request_hash'],$identity)) SettlementEvidence::reject('idempotency_conflict');
                return json_decode($old['response_json'],true,flags:JSON_THROW_ON_ERROR);
            }
            $version = $this->version($supplier,$command);
            if (!hash_equals($expected,$version)) SettlementEvidence::reject('preview_stale');
            $plan = $this->simulate($supplier,$command,$actor);
            if (!hash_equals($hash,PropertyInput::hash([$supplier,$actor,$command,$version,$plan]))) SettlementEvidence::reject('preview_stale');
            $result=$this->perform($supplier,$command,$actor);
            $response=['case_id'=>$result['case_id'],'operation'=>$command['operation'],'plan'=>$plan];
            $q=$this->db->pdo()->prepare('INSERT INTO property_service_lifecycle_commands(supplier_id,idempotency_key,request_hash,case_id,response_json,created_by,recorded_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP(6))');
            $q->execute([$supplier,$key,$identity,$result['case_id'],json_encode($response,JSON_THROW_ON_ERROR),$actor]);
            return $response;
        });
    }

    private function transaction(int $supplier,callable $work): mixed
    {
        PropertyInput::id($supplier,'supplier');
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplier,$work) {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplier);
            (new PaymentLocks($this->db))->acquire($supplier);
            return $work();
        });
    }

    private function normalize(array $command): array
    {
        $op=$command['operation']??''; self::permission(is_string($op)?$op:'');
        $fields=match($op) {
            'start'=>['operation','unit_id','service_id','period_from','period_to','policy','evidence','service_cost_account','entry_ids','coverage_title'],
            'start_opening'=>['operation','unit_id','service_id','period_from','period_to','policy','evidence','service_cost_account','opening_item_id'],
            'unwind'=>['operation','case_id','accounting_date'],
            'finalize'=>['operation','case_id','accounting_date','invoice_id','line_id','title'],
        };
        SettlementEvidence::fields($command,$fields);
        foreach(['unit_id','service_id','case_id','opening_item_id','invoice_id','line_id'] as $key) if(array_key_exists($key,$command)) PropertyInput::id($command[$key],$key);
        foreach(['period_from','period_to','accounting_date'] as $key) if(array_key_exists($key,$command)) SettlementEvidence::date($command[$key]);
        foreach(['evidence','coverage_title','title'] as $key) if(array_key_exists($key,$command)) PropertyInput::text($command[$key],$key,4000);
        if(array_key_exists('policy',$command)&&!in_array($command['policy'],['service_balance_sheet','service_profit_and_loss'],true)) PropertyInput::invalid('policy');
        if(array_key_exists('service_cost_account',$command)) PropertyInput::code($command['service_cost_account'],'service_cost_account');
        if(array_key_exists('entry_ids',$command)) {
            if(!is_array($command['entry_ids'])||!array_is_list($command['entry_ids'])||count($command['entry_ids'])<1||count($command['entry_ids'])>2) PropertyInput::invalid('entry_ids');
            foreach($command['entry_ids'] as $id) PropertyInput::id($id,'entry_id');
        }
        return SettlementEvidence::detached($command);
    }

    private function version(int $supplier,array $command): string
    {
        if(isset($command['case_id'])) {
            $q=$this->db->pdo()->prepare('SELECT facts_hash FROM property_service_lifecycle_cases WHERE supplier_id=? AND id=? FOR UPDATE');$q->execute([$supplier,$command['case_id']]);
            $case=$q->fetchColumn();if($case===false) SettlementEvidence::reject('lifecycle_not_found');
            $q=$this->db->pdo()->prepare('SELECT id,event_key,request_hash,receipt_hash,journal_entry_id FROM property_service_lifecycle_events WHERE supplier_id=? AND case_id=? ORDER BY id FOR UPDATE');$q->execute([$supplier,$command['case_id']]);
            return PropertyInput::hash([$case,$q->fetchAll(PDO::FETCH_ASSOC)]);
        }
        $q=$this->db->pdo()->prepare('SELECT id,facts_hash FROM property_service_lifecycle_cases WHERE supplier_id=? AND unit_id=? AND service_id=? AND period_from<=? AND period_to>=? ORDER BY id FOR UPDATE');
        $q->execute([$supplier,$command['unit_id'],$command['service_id'],$command['period_to'],$command['period_from']]);
        return PropertyInput::hash($q->fetchAll(PDO::FETCH_ASSOC));
    }

    private function simulate(int $supplier,array $command,int $actor): array
    {
        $pdo=$this->db->pdo(); $savepoint='service_lifecycle_preview_'.bin2hex(random_bytes(8));$pdo->exec('SAVEPOINT '.$savepoint);
        try {
            $result=$this->perform($supplier,$command,$actor);
            $state=$this->owner->current($supplier,$result['case_id']);
            $plan=['operation'=>$command['operation'],'policy'=>$state['case']['policy'],'period_from'=>$state['case']['period_from'],'period_to'=>$state['case']['period_to'],'estimate_cents'=>$state['estimate']['amount_cents'],'final_cents'=>$state['final']['amount_cents']??null,'source_fingerprint'=>PropertyInput::hash([$state['estimate'],$state['final']['invoice']??null]),'journals'=>[]];
            foreach($result['journal_ids'] as $id) {
                $journal=(new PaymentJournalProof($this->db))->current($supplier,$id);
                $plan['journals'][]=['accounting_date'=>$journal['entry']['entry_date'],'lines'=>PaymentJournalProof::canonicalLines($journal['lines'])];
            }
            return $plan;
        } finally {
            $pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);
            $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
        }
    }

    private function perform(int $supplier,array $c,int $actor): array
    {
        PropertyInput::id($actor,'actor');$op=$c['operation'];$journals=[];
        if($op==='start'||$op==='start_opening') {
            $case=$this->owner->openCase($supplier,$c['unit_id'],$c['service_id'],$c['period_from'],$c['period_to'],$c['policy'],$c['evidence'],$actor,$c['service_cost_account']);
            if($op==='start') $this->owner->registerEstimate($supplier,$case,$c['entry_ids'],$c['coverage_title']);
            else $this->owner->bindOpeningEstimate($supplier,$case,$c['opening_item_id']);
        } else {
            $case=$c['case_id'];
            if($op==='unwind') $journals=$this->owner->unwind($supplier,$case,$c['accounting_date'],$actor);
            else {
                // Konečné zúčtování rozpustí dohad ke stejnému datu, pokud se tak ještě nestalo.
                if(!$this->owner->isUnwound($supplier,$case)) $journals=$this->owner->unwindWithFinal($supplier,$case,$c['accounting_date'],$actor);
                $final=$this->owner->finalize($supplier,$case,$c['invoice_id'],$c['line_id'],$c['accounting_date'],$c['title'],$actor);
                if(isset($final['ledger'])) $journals[]=$final['ledger']['entry']['id'];
            }
        }
        return ['case_id'=>$case,'journal_ids'=>$journals];
    }
}
