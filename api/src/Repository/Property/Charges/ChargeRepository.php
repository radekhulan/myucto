<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property\Charges;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\PropertyCatalog;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\Charges\ChargeException;
use MyInvoice\Service\Property\Charges\ChargeRequest;
use MyInvoice\Service\Property\Charges\ChargePreviewService;
use PDO;

final class ChargeRepository
{
    public function __construct(private readonly Connection $db) {}
    public function createComponent(int $supplier,array $input,int $actor): array
    {
        PropertyInput::id($actor,'actor'); $request=ChargeRequest::component($input); $hash=PropertyInput::hash($request+['actor'=>$actor]);
        return (new DimensionPeriodGuard($this->db))->transaction(function() use($supplier,$request,$hash,$actor): array {
            (new PropertyCatalog($this->db))->lock($supplier,$request['effective_at']);
            $objects=(new PropertyReadRepository($this->db))->objects($supplier,null,true);
            $old=$this->retry('unit_charge_components',$supplier,$request['idempotency_key'],$hash);
            if($old!==null) return $this->component($supplier,(int)$old['id']);
            (new PropertyProfileRepository($this->db))->assertEnabledLocked($supplier,$request['effective_at']);
            if($request['service_id']!==null) {
                $service=(new PropertyReadRepository($this->db))->find($supplier,$request['service_id'],$request['effective_at'],currentRead:true);
                if($service['kind']!=='service'||!($service['revision']['state']['active']??false)) throw new ChargeException('property_charge_service_unavailable','Služba nepatří do aktivního číselníku firmy.',409);
            }
            foreach((new ChargeReadRepository($this->db))->components($supplier,true) as $component) if(strcasecmp($component['code'],$request['code'])===0) throw new ChargeException('property_charge_code_conflict','Tento kód složky již existuje.',409);
            if($request['account_code']!=='') {
                $stmt=$this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code=? AND is_active=1 FOR UPDATE'); $stmt->execute([$supplier,$request['account_code']]);
                if(!$stmt->fetchColumn()) throw new ChargeException('property_charge_account_missing','Účet není v aktivní osnově firmy.',409);
            }
            $now=self::now();
            $this->db->pdo()->prepare('INSERT INTO unit_charge_components (supplier_id,code,name,kind,service_id,account_code,idempotency_key,request_hash,request_json,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$supplier,$request['code'],$request['name'],$request['kind'],$request['service_id'],$request['account_code'],$request['idempotency_key'],$hash,self::json($request),$actor,$now]);
            $id=(int)$this->db->pdo()->lastInsertId(); $this->audit($supplier,'component',$id,['request_hash'=>$hash],$actor,$now);
            return $this->component($supplier,$id);
        });
    }
    public function saveVersion(int $supplier,array $input,int $actor): array
    {
        PropertyInput::fields($input,['request','preview_hash','idempotency_key']); PropertyInput::id($actor,'actor');
        if(!is_array($input['request']??null)||!is_string($input['preview_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$input['preview_hash'])) PropertyInput::invalid('preview_hash');
        $request=ChargeRequest::version($input['request']); $token=PropertyInput::code($input['idempotency_key']??null,'idempotency_key');
        $hash=PropertyInput::hash(['request'=>$request,'preview_hash'=>$input['preview_hash'],'idempotency_key'=>$token,'actor'=>$actor]);
        return (new DimensionPeriodGuard($this->db))->transaction(function() use($supplier,$request,$input,$token,$hash,$actor): array {
            (new PropertyCatalog($this->db))->lock($supplier,$request['effective_from']);
            (new PropertyReadRepository($this->db))->objects($supplier,null,true);
            $old=$this->retry('unit_charge_versions',$supplier,$token,$hash);
            if($old!==null) return (new ChargeReadRepository($this->db))->find($supplier,(int)$old['id'],true);
            $preview=(new ChargePreviewService($this->db))->preview($supplier,$request,true);
            if(!hash_equals($input['preview_hash'],$preview['preview_hash'])) throw new ChargeException('property_charge_preview_stale','Výpočetní podklady se změnily. Vytvořte nový náhled.',409);
            $now=self::now();
            $this->db->pdo()->prepare('INSERT INTO unit_charge_versions (supplier_id,house_id,revision_no,effective_from,effective_to,request_json,preview_json,preview_hash,idempotency_key,request_hash,created_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$supplier,$request['house_id'],$request['expected_revision']+1,PropertyInstant::sql($request['effective_from']),$request['effective_to']===null?null:PropertyInstant::sql($request['effective_to']),
                    self::json($request),self::json($preview['preview']),$preview['preview_hash'],$token,$hash,$actor,$now]);
            $id=(int)$this->db->pdo()->lastInsertId();
            foreach($preview['preview']['calculation']['rates'] as $rate) $this->db->pdo()->prepare('INSERT INTO unit_charge_rates (supplier_id,version_id,component_id,rate_json,calculation_json) VALUES (?,?,?,?,?)')
                ->execute([$supplier,$id,$rate['component']['id'],self::json($rate['rate']),self::json($rate['calculation'])]);
            $this->audit($supplier,'version',$id,['preview_hash'=>$preview['preview_hash'],'request_hash'=>$hash],$actor,$now);
            return (new ChargeReadRepository($this->db))->find($supplier,$id,true);
        });
    }
    private function retry(string $table,int $supplier,string $token,string $hash): ?array
    {
        $stmt=$this->db->pdo()->prepare('SELECT id,request_hash FROM '.$table.' WHERE supplier_id=? AND idempotency_key=? FOR UPDATE'); $stmt->execute([$supplier,$token]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if($row&&!hash_equals($row['request_hash'],$hash)) throw new ChargeException('property_charge_idempotency_conflict','Identifikátor požadavku již patří jinému obsahu.',409);
        return $row?:null;
    }
    private function component(int $supplier,int $id): array
    {
        foreach((new ChargeReadRepository($this->db))->components($supplier,true) as $component) if($component['id']===$id) return $component;
        throw new \LogicException('Missing inserted component.');
    }
    private function audit(int $supplier,string $action,int $id,array $payload,int $actor,string $now): void
    {
        $this->db->pdo()->prepare('INSERT INTO unit_charge_audit (supplier_id,action,component_id,version_id,payload_json,created_by,recorded_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$supplier,$action,$action==='component'?$id:null,$action==='version'?$id:null,self::json($payload),$actor,$now]);
    }
    private static function now(): string { return (new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'); }
    private static function json(array $value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION); }
}
