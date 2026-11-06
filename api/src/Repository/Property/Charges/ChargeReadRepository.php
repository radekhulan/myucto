<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property\Charges;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\Charges\ChargeException;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyInstant;
use PDO;

final class ChargeReadRepository
{
    public function __construct(private readonly Connection $db) {}
    public function components(int $supplier,bool $current=false): array
    {
        $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charge_components WHERE supplier_id=? ORDER BY id'.$this->lock($current));
        $stmt->execute([$supplier]);
        return array_map(static function(array $row): array {
            foreach(['id','supplier_id','service_id','created_by'] as $field) if($row[$field]!==null) $row[$field]=(int)$row[$field];
            $request=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);
            if(!hash_equals($row['request_hash'],PropertyInput::hash($request+['actor'=>$row['created_by']]))) throw new ChargeException('property_charge_history_corrupt','Otisk složky nesouhlasí.',409);
            $row['effective_at']=$request['effective_at'];
            unset($row['idempotency_key'],$row['request_hash'],$row['request_json']);
            return $row;
        },$stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    public function versions(int $supplier,int $house,bool $current=false): array
    {
        $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charge_versions WHERE supplier_id=? AND house_id=? ORDER BY revision_no DESC'.$this->lock($current));
        $stmt->execute([$supplier,$house]);
        return array_map($this->decode(...),$stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    public function find(int $supplier,int $id,bool $current=false): array
    {
        $stmt=$this->db->pdo()->prepare('SELECT * FROM unit_charge_versions WHERE supplier_id=? AND id=?'.$this->lock($current)); $stmt->execute([$supplier,$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return $row?$this->decode($row):throw new ChargeException('property_charge_not_found','Verze předpisu nebyla nalezena.',404);
    }
    public function at(int $supplier,int $house,string $at,?string $knownAt=null,bool $current=false): ?array
    {
        $at=PropertyInstant::normalize($at); $knownAt=$knownAt===null?null:PropertyInstant::normalize($knownAt);
        foreach($this->versions($supplier,$house,$current) as $version) {
            if($knownAt!==null&&$version['recorded_at']>$knownAt) continue;
            if(PropertyInstant::contains($version['effective_from'],$version['effective_to'],$at)) return $version;
        }
        return null;
    }
    private function decode(array $row): array
    {
        $row['request']=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);
        $row['preview']=json_decode($row['preview_json'],true,flags:JSON_THROW_ON_ERROR);
        if(!hash_equals($row['preview_hash'],PropertyInput::hash($row['preview']))) throw new ChargeException('property_charge_history_corrupt','Otisk historického výpočtu nesouhlasí.',409);
        $envelope=['request'=>$row['request'],'preview_hash'=>$row['preview_hash'],'idempotency_key'=>$row['idempotency_key'],'actor'=>(int)$row['created_by']];
        if(!hash_equals($row['request_hash'],PropertyInput::hash($envelope))
            || PropertyInput::hash($row['request'])!==PropertyInput::hash($row['preview']['request'])) throw new ChargeException('property_charge_history_corrupt','Historické rozhodnutí neodpovídá uloženému výpočtu.',409);
        foreach(['id','supplier_id','house_id','revision_no','created_by'] as $field) $row[$field]=(int)$row[$field];
        foreach(['effective_from','effective_to','recorded_at'] as $field) if($row[$field]!==null) $row[$field]=PropertyInstant::normalize(str_replace(' ','T',$row[$field]).'Z');
        unset($row['request_json'],$row['preview_json'],$row['request_hash'],$row['idempotency_key']);
        return $row;
    }
    private function lock(bool $current): string
    {
        if($current&&!$this->db->pdo()->inTransaction()) throw new \LogicException('Charge current reads require period/catalog/registry locks.');
        return $current?' FOR UPDATE':'';
    }
}
