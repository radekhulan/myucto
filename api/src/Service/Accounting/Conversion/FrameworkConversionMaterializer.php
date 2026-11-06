<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Conversion;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountDefinitionRepository,FrameworkConversionRepository as R};
use MyInvoice\Service\Accounting\ChartOfAccounts504Policy;
use MyInvoice\Service\Accounting\Conversion\FrameworkConversionException as E;
final class FrameworkConversionMaterializer
{
    public function __construct(private readonly Connection $db){}
    public function lines(int $supplier,array $plan):array
    {
        $accounts=array_column((new AccountDefinitionRepository($this->db))->listForDate($supplier,$plan['effective_from'],true),null,'account_code');$byId=array_column($accounts,null,'id');
        $q=$this->db->pdo()->prepare('SELECT t.id AS type_id,t.system_key,v.id,v.code,v.name,v.semantic_key FROM dimension_types t JOIN dimension_values v ON v.type_id=t.id AND v.supplier_id=t.supplier_id WHERE t.supplier_id=? AND t.is_active=1 AND v.is_active=1 ORDER BY t.id,v.id FOR UPDATE');$q->execute([$supplier]);$catalog=[];foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $row)$catalog[$row['system_key']][$row['system_key']==='activity_kind'?$row['code']:$row['semantic_key']]=$row;
        $out=[];
        foreach($plan['lines'] as $index=>$semantic){$account=$accounts[$semantic['account_code']]??null;E::need($account!==null&&(bool)$account['is_active'],'conversion_target_account_changed');E::need(ChartOfAccounts504Policy::validationError($account['account_code'],$account['account_type'],$account['normal_side'],$byId[$account['parent_id']??0]['account_code']??null)===null,'conversion_target_definition_invalid');
            $line=array_diff_key($semantic,array_flip(['source_dimensions','classification']));$line['account_id']=(int)$account['id'];$line['line_no']=$index;$line['dimensions']=$semantic['source_dimensions'];$classification=$semantic['classification'];
            foreach(['activity','activity_kind','tax_regime'] as $key){$choice=$key==='activity_kind'?$classification[$key]['code']:$classification[$key];$value=$catalog[$key][$choice]??null;E::need($value!==null,'conversion_dimension_changed');if($key==='activity_kind')E::need($value['name']===$classification[$key]['name'],'conversion_activity_kind_conflict');$line['dimensions'][(int)$value['type_id']]=(int)$value['id'];}
            ksort($line['dimensions']);$line['dimension_splits']=[];
            foreach($line['dimensions'] as $type=>$value)$line['dimension_origins'][$type]=['origin'=>'explicit','ref'=>null];$out[]=$line;
        }
        return $out;
    }
}
