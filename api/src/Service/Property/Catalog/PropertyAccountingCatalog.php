<?php

declare(strict_types=1);
namespace MyInvoice\Service\Property\Catalog;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Services\Settlement\ServiceLifecycleRepository;
use MyInvoice\Repository\Property\Funds\FundingRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Services\Reconciliation\ServiceReconciliation;
use MyInvoice\Service\Property\Funds\FundingRead;
use PDO;

final class PropertyAccountingCatalog
{
    public function __construct(private readonly Connection $db){}
    private function transaction(int $supplier,callable $read):array
    {
        PropertyInput::id($supplier,'supplier_id');return(new DimensionPeriodGuard($this->db))->transaction(function()use($supplier,$read){(new PaymentLocks($this->db))->acquire($supplier);return $read();});
    }
    private function q(string $sql,array $params):\PDOStatement{$q=$this->db->pdo()->prepare($sql);$q->execute($params);return $q;}
    private static function current(?string $known):void{if($known!==null)throw new PropertyException('property_catalog_known_at_unsupported','property_catalog_known_at_unsupported',409);}
    private function scope(int $supplier,string $kind,?int $id):void
    {
        if($id===null)return;PropertyInput::id($id,$kind.'_id');if(!$this->q('SELECT id FROM property_objects WHERE supplier_id=? AND kind=? AND id=? FOR UPDATE',[$supplier,$kind,$id])->fetchColumn())throw new PropertyException('property_catalog_scope_not_found','property_catalog_scope_not_found',404);
    }
    private function pageIds(string $table,string $where,array $params,int $after,int $limit):array
    {
        if($after<0||$limit<1||$limit>100)PropertyInput::invalid('pagination');
        if($after>0&&!$this->q('SELECT id FROM '.$table.' WHERE '.$where.' AND id=? FOR UPDATE',[...$params,$after])->fetchColumn())throw new PropertyException('property_catalog_cursor_invalid','property_catalog_cursor_invalid',409);
        $rows=$this->q('SELECT id FROM '.$table.' WHERE '.$where.' AND id>? ORDER BY id LIMIT '.($limit+1).' FOR UPDATE',[...$params,$after])->fetchAll(PDO::FETCH_COLUMN);$more=count($rows)>$limit;$ids=array_map('intval',array_slice($rows,0,$limit));return [$ids,$more?$ids[array_key_last($ids)]:null];
    }
    public function lifecycles(int $supplier,int $after=0,int $limit=25,?int $unit=null,?int $service=null,?string $known=null):array
    {
        self::current($known);return $this->transaction($supplier,function()use($supplier,$after,$limit,$unit,$service){$this->scope($supplier,'unit',$unit);$this->scope($supplier,'service',$service);$where='supplier_id=?';$params=[$supplier];foreach(['unit_id'=>$unit,'service_id'=>$service] as $key=>$id)if($id!==null){$where.=' AND '.$key.'=?';$params[]=$id;}
            [$ids,$next]=$this->pageIds('property_service_lifecycle_cases',$where,$params,$after,$limit);$owner=new ServiceLifecycleRepository($this->db);if($after>0)$owner->reconciliationSources($supplier,$after);$items=array_map(fn(int $id):array=>PropertyAccountingProjection::lifecycle($owner->reconciliationSources($supplier,$id)),$ids);return ['items'=>$items,'next_after_id'=>$next,'incomplete'=>array_any($items,static fn(array $item):bool=>$item['incomplete']),'knowledge_basis'=>'current_ledger','known_at'=>null];});
    }
    public function lifecycle(int $supplier,int $id,?string $known=null):array
    {
        self::current($known);PropertyInput::id($id,'case_id');return $this->transaction($supplier,fn():array=>PropertyAccountingProjection::lifecycle((new ServiceLifecycleRepository($this->db))->reconciliationSources($supplier,$id),true));
    }
    public function reconciliation(int $supplier,string $date,?int $unit=null,?int $service=null,?string $known=null):array
    {
        return PropertyAccountingProjection::reconciliation((new ServiceReconciliation($this->db))->at($supplier,$date,$unit,$service,$known));
    }
    public function operations(int $supplier,?int $house=null,int $after=0,int $limit=25):array
    {
        return $this->transaction($supplier,function()use($supplier,$house,$after,$limit){$this->scope($supplier,'house',$house);$where='supplier_id=?';$params=[$supplier];if($house!==null){$where.=' AND house_id=?';$params[]=$house;}[$ids,$next]=$this->pageIds('property_funding_operations',$where,$params,$after,$limit);$owner=new FundingRepository($this->db);if($after>0)$owner->operation($supplier,$after);return ['items'=>array_map(fn(int $id):array=>PropertyAccountingProjection::operation($owner->operation($supplier,$id)),$ids),'next_after_id'=>$next,'incomplete'=>false,'knowledge_basis'=>'current_ledger'];});
    }
    public function balances(int $supplier,string $date,?int $house=null,?int $unit=null,?string $known=null):array
    {
        return $this->transaction($supplier,function()use($supplier,$date,$house,$unit,$known){$this->scope($supplier,'house',$house);$this->scope($supplier,'unit',$unit);return PropertyAccountingProjection::balances((new FundingRead($this->db))->at($supplier,$house,$date,$known,$unit));});
    }
}
