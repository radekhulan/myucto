<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Catalog;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountDefinitionRepository,AccountingFrameworkRepository,AccountingModeRepository};
use MyInvoice\Service\Accounting\{AccountDefinitionException,AccountRoleResolver,AccountingFrameworkService};
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use PDO;

final class AccountingContextProjection
{
    public function __construct(private readonly Connection $db) {}

    public function forDate(int $supplier,string $date): AccountingContext
    {
        AccountDefinitionRepository::date($date);
        $pdo=$this->db->pdo();$own=!$pdo->inTransaction();
        if($own){$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');}
        elseif($pdo->query('SELECT @@tx_isolation')->fetchColumn()!=='REPEATABLE-READ')throw new AccountDefinitionException('accounting_context_snapshot_required');
        try {
            $result=$this->read($supplier,$date);
            if($own)$pdo->commit();
            return $result;
        } catch(\Throwable $e) {
            if($own&&$pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }

    private function read(int $supplier,string $date): AccountingContext
    {
        $pdo=$this->db->pdo();$q=$pdo->prepare('SELECT id FROM supplier WHERE id=?');$q->execute([$supplier]);
        if($supplier<=0||$q->fetchColumn()===false)throw new AccountDefinitionException('accounting_context_tenant_not_found',404);
        $findings=[];$profile=AccountRoleResolver::fromConnection($this->db)->forDate($supplier,$date);
        $frameworks=new AccountingFrameworkService($this->db,new AccountingFrameworkRepository($this->db));
        $framework=$this->effective($frameworks->history($supplier),$date,$profile->framework,'default');
        if($framework['temporal_source']!=='dated_history')$findings[]=$this->finding('framework_default_not_historized','framework');
        $q=$pdo->prepare('SELECT id,effective_from FROM supplier_accounting_modes WHERE supplier_id=? ORDER BY effective_from');$q->execute([$supplier]);
        $method=$this->effective($q->fetchAll(PDO::FETCH_ASSOC),$date,(new AccountingModeRepository($this->db))->forDate($supplier,$date),'current_supplier');
        if($method['temporal_source']!=='dated_history')$findings[]=$this->finding('accounting_method_not_historized','method');
        $q=$pdo->prepare('SELECT id,account_id,effective_from,row_version,definition_hash FROM accounting_account_definition_history WHERE supplier_id=? ORDER BY account_id,effective_from,id');$q->execute([$supplier]);$history=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row)$history[(int)$row['account_id']][]=$row;
        $accounts=[];$byCode=[];
        foreach((new AccountDefinitionRepository($this->db))->listForDate($supplier,$date)as$row){
            $id=(int)$row['id'];$revision=$this->effective($history[$id]??[],$date,null,'current_base');
            $selected=$revision['selected'];
            $accounts[]=[
                'id'=>$id,'account_code'=>(string)$row['account_code'],'account_type'=>(string)$row['account_type'],
                'parent_id'=>$row['parent_id']===null?null:(int)$row['parent_id'],'is_synthetic'=>(bool)$row['is_synthetic'],
                'normal_side'=>$row['normal_side'],'tax_deductibility'=>(string)$row['tax_deductibility'],'is_active'=>(bool)$row['is_active'],
                'temporal_source'=>$revision['temporal_source'],'effective_from'=>$revision['effective_from'],'effective_until'=>$revision['effective_until'],
                'metadata_revision'=>$selected===null?null:['id'=>(int)$selected['id'],'row_version'=>(int)$selected['row_version'],'definition_hash'=>(string)$selected['definition_hash']],
            ];
            $byCode[(string)$row['account_code']]=$id;
            if($selected===null)$findings[]=$this->finding('account_definition_not_historized','accounts',$id);
        }
        $roles=[];
        foreach($profile->accounts()as$role=>$code){$id=$byCode[$code]??null;$roles[]=['role'=>$role,'account_code'=>$code,'account_id'=>$id];if($id===null)$findings[]=['code'=>'role_account_missing','scope'=>'account_roles','role'=>$role];}
        $dimensions=$this->dimensions($supplier,$profile->framework===AccountingFrameworkService::CZ_504,$findings);
        unset($framework['selected'],$method['selected']);
        return new AccountingContext($date,$framework,$method,$accounts,$roles,$dimensions,$findings);
    }

    private function effective(array $history,string $date,?string $value,string $fallback): array
    {
        $selected=null;$until=null;
        foreach($history as$row){if($row['effective_from']<=$date)$selected=$row;else{$until=$row['effective_from'];break;}}
        return ['value'=>$value,'id'=>isset($selected['id'])?(int)$selected['id']:null,'effective_from'=>$selected['effective_from']??null,'effective_until'=>$until,'temporal_source'=>$selected===null?$fallback:'dated_history','selected'=>$selected];
    }

    private function dimensions(int $supplier,bool $required,array &$findings): array
    {
        if(!$required)return [];
        $q=$this->db->pdo()->prepare('SELECT id,system_key FROM dimension_types WHERE supplier_id=? AND system_key IS NOT NULL ORDER BY id');$q->execute([$supplier]);$types=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row)$types[$row['system_key']][]=(int)$row['id'];
        $q=$this->db->pdo()->prepare('SELECT v.id,v.type_id,v.semantic_key FROM dimension_values v JOIN dimension_types t ON t.id=v.type_id AND t.supplier_id=v.supplier_id WHERE v.supplier_id=? AND t.system_key IS NOT NULL AND v.semantic_key IS NOT NULL ORDER BY v.id');$q->execute([$supplier]);$values=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row)$values[(int)$row['type_id']][$row['semantic_key']][]=(int)$row['id'];
        $out=[];
        foreach(SystemDimensionCatalog::DEFINITIONS as$role=>$definition){
            $ids=$types[$role]??[];$type=count($ids)===1?$ids[0]:null;
            if($type===null)$findings[]=['code'=>$ids===[]?'system_dimension_missing':'system_dimension_ambiguous','scope'=>'system_dimensions','role'=>$role];
            $roles=[];
            foreach(array_keys($definition['values'])as$semantic){$ids=$type===null?[]:($values[$type][$semantic]??[]);$id=count($ids)===1?$ids[0]:null;$roles[]=['role'=>$semantic,'value_id'=>$id];if($id===null)$findings[]=['code'=>$ids===[]?'system_dimension_value_missing':'system_dimension_value_ambiguous','scope'=>'system_dimensions','role'=>$role.'.'.$semantic];}
            $out[]=['role'=>$role,'type_id'=>$type,'value_roles'=>$roles,'temporal_source'=>'current_identity','historical_state_available'=>false];
        }
        $findings[]=$this->finding('system_dimension_state_not_historized','system_dimensions');
        return $out;
    }

    private function finding(string $code,string $scope,?int $id=null): array
    {
        return $id===null?['code'=>$code,'scope'=>$scope]:['code'=>$code,'scope'=>$scope,'id'=>$id];
    }
}
