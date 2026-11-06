<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Charges\Issuance;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\Charges\ChargeReadRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Service\Property\PropertyInstant;
use PDO;

final class IssuanceContextService
{
    public function __construct(private readonly Connection $db) {}

    public function context(int $supplier,int $versionId,string $at): array
    {
        $at=PropertyInstant::normalize($at);
        $version=(new ChargeReadRepository($this->db))->find($supplier,$versionId);
        $registry=new PropertyReadRepository($this->db);
        $unitIds=[]; foreach($version['preview']['calculation']['rates'] as $rate) foreach($rate['calculation']['lines'] as $line) $unitIds[$line['unit_id']]=true;
        $units=array_values(array_filter($registry->list($supplier,'unit',$at),fn($unit)=>$unit['parent_id']===$version['house_id']&&isset($unitIds[$unit['id']])));
        $ids=[];
        foreach($units as $unit) {
            foreach($unit['revision']['state']['ownerships']??[] as $group) foreach($group['members'] as $id) $ids[$id]=true;
            foreach($unit['revision']['state']['roles']??[] as $role) $ids[$role['client_id']]=true;
        }
        $stmt=$this->db->pdo()->prepare('SELECT account_code,name,account_type FROM chart_of_accounts WHERE supplier_id=? AND is_active=1 ORDER BY account_code'); $stmt->execute([$supplier]);
        $accounts=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $visibility=new \MyInvoice\Repository\DimensionRepository($this->db);
        [$typeScope,$typeParams]=$visibility->visibleSql($supplier,'t');
        [$valueScope,$valueParams]=$visibility->visibleSql($supplier,'v');
        $stmt=$this->db->pdo()->prepare("SELECT t.id type_id,t.name type_name,t.system_key,v.id value_id,v.name value_name FROM dimension_types t JOIN dimension_values v ON v.type_id=t.id WHERE {$typeScope} AND {$valueScope} AND t.is_active=1 AND v.is_active=1 AND (t.system_key IS NULL OR t.system_key NOT IN ('unit','service')) ORDER BY t.id,v.id");
        $stmt->execute([...$typeParams,...$valueParams]); $dimensions=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id=(int)$row['type_id'];
            $dimensions[$id]??=['id'=>$id,'name'=>$row['type_name'],'system_key'=>$row['system_key'],'values'=>[]];
            $dimensions[$id]['values'][]=['id'=>(int)$row['value_id'],'name'=>$row['value_name']];
        }
        $history=$unitIds===[]?[]:(new IssuanceReadRepository($this->db))->forUnits($supplier,array_keys($unitIds));
        return ['as_of'=>$at,'version'=>$version,'units'=>$units,'parties'=>$registry->parties($supplier,array_keys($ids)),
            'accounts'=>$accounts,'dimensions'=>array_values($dimensions),'history'=>$history,'case_families'=>IssuanceCaseIdentity::context(IssuanceCaseIdentity::families($history))];
    }
}
