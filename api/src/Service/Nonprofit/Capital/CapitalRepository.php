<?php
declare(strict_types=1);
namespace MyInvoice\Service\Nonprofit\Capital;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use PDO;
final class CapitalRepository
{
    public const TABLES=['nonprofit_capital_events','nonprofit_capital_sources','nonprofit_capital_commands'];
    public function __construct(private readonly Connection $db) {}
    public function sources(int $supplier,bool $lock=true):array
    {
        $q=$this->db->pdo()->prepare('SELECT * FROM nonprofit_capital_sources WHERE supplier_id=? ORDER BY id'.($lock?' FOR UPDATE':''));$q->execute([$supplier]);$out=[];
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$commands=$this->commands($supplier,array_column($rows,'command_id'),$lock);
        foreach($rows as$row){
            foreach(['id','supplier_id','command_id','source_line_id','asset_id','provider_id','amount_cents']as$key)$row[$key]=$row[$key]===null?null:(int)$row[$key];
            foreach(['policy','basis']as$key){$row[$key]=json_decode($row[$key.'_json'],true,flags:JSON_THROW_ON_ERROR);unset($row[$key.'_json']);}
            if(!hash_equals($row['record_hash'],self::sourceHash($row)))CapitalPolicy::changed();
            $command=$commands[$row['command_id']]??CapitalPolicy::changed();
            if(($command['request']['action']??null)!=='register'||($command['request']['policy']??null)!==$row['policy']||($command['request']['input_hash']??null)!==CapitalPolicy::hash(['policy'=>$row['policy'],'basis'=>$row['basis']])||$row['kind']!==$row['policy']['kind']||$row['source_line_id']!==$row['policy']['source_line_id']||$row['asset_id']!==$row['policy']['asset_id']||$row['provider_id']!==$row['policy']['provider_id']||$row['amount_cents']!==$row['basis']['source']['amount_cents'])CapitalPolicy::changed();
            $out[$row['id']]=$row;
        }
        return $out;
    }
    public function events(int $supplier,bool $lock=true):array
    {
        $q=$this->db->pdo()->prepare('SELECT * FROM nonprofit_capital_events WHERE supplier_id=? ORDER BY id'.($lock?' FOR UPDATE':''));$q->execute([$supplier]);$out=[];$last=[];
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);$commands=$this->commands($supplier,array_column($rows,'command_id'),$lock);
        foreach($rows as$row){
            foreach(['id','supplier_id','source_id','command_id','previous_id','original_id','amount_cents','entry_id','depreciation_id','cost_line_id']as$key)$row[$key]=$row[$key]===null?null:(int)$row[$key];
            foreach(['snapshot','receipt']as$key){$row[$key]=json_decode($row[$key.'_json'],true,flags:JSON_THROW_ON_ERROR);unset($row[$key.'_json']);}
            $prior=$last[$row['source_id']]??null;
            if($row['previous_id']!==($prior['id']??null)||$row['parent_hash']!==($prior['record_hash']??str_repeat('0',64))||!hash_equals($row['record_hash'],self::eventHash($row)))CapitalPolicy::changed();
            $command=$commands[$row['command_id']]??CapitalPolicy::changed();
            if(($command['request']['source_id']??null)!==$row['source_id']||($command['request']['action']??null)!==$row['operation']||CapitalPolicy::hash($command['request']['plan']??[])!==CapitalPolicy::hash($row['snapshot'])||($command['request']['input_hash']??null)!==CapitalPolicy::hash($row['snapshot']))CapitalPolicy::changed();
            foreach(['original_id','amount_cents','depreciation_id','cost_line_id']as$key)if(($command['request']['intent'][$key]??null)!==$row[$key])CapitalPolicy::changed();
            $last[$row['source_id']]=$row;$out[$row['id']]=$row;
        }
        return $out;
    }
    public function byToken(int $supplier,string $token,bool $lock=true):?array
    {
        $q=$this->db->pdo()->prepare('SELECT id FROM nonprofit_capital_commands WHERE supplier_id=? AND idempotency_key=?'.($lock?' FOR UPDATE':''));$q->execute([$supplier,$token]);$id=$q->fetchColumn();return $id===false?null:$this->command($supplier,(int)$id,$lock);
    }
    public function command(int $supplier,int $id,bool $lock=true):array
    {
        return $this->commands($supplier,[$id],$lock)[$id]??CapitalPolicy::changed();
    }
    /** Příkazy zdrojů a čerpání jedním dotazem po (supplier_id, id) místo dotazu na každý řádek. */
    private function commands(int $supplier,array $ids,bool $lock):array
    {
        $ids=array_values(array_unique(array_map('intval',$ids)));sort($ids,SORT_NUMERIC);$out=[];
        foreach(array_chunk($ids,500)as$chunk){
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $q=$this->db->pdo()->prepare('SELECT * FROM nonprofit_capital_commands WHERE supplier_id=? AND id IN ('.$marks.') ORDER BY id'.($lock?' FOR UPDATE':''));$q->execute([$supplier,...$chunk]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row)$out[(int)$row['id']]=self::commandRow($row);
        }
        return $out;
    }
    private static function commandRow(array $row):array
    {
        $row['id']=(int)$row['id'];$row['supplier_id']=(int)$row['supplier_id'];$row['created_by']=$row['created_by']===null?null:(int)$row['created_by'];$row['request']=json_decode($row['request_json'],true,flags:JSON_THROW_ON_ERROR);unset($row['request_json']);
        if(!hash_equals($row['request_hash'],CapitalPolicy::hash($row['request']))||($row['request']['actor']??null)!==$row['created_by'])CapitalPolicy::changed();return $row;
    }
    public function appendCommand(int $supplier,string $token,array $request,?int $actor):int
    {
        if(!$this->db->pdo()->inTransaction())throw new \LogicException('Capital commands require transaction.');
        $q=$this->db->pdo()->prepare('INSERT INTO nonprofit_capital_commands(supplier_id,idempotency_key,request_json,request_hash,created_by)VALUES(?,?,?,?,?)');$q->execute([$supplier,$token,NonprofitTaxRules::canonicalJson($request),CapitalPolicy::hash($request),$actor]);return (int)$this->db->pdo()->lastInsertId();
    }
    public function appendSource(array $row):array
    {
        $row['record_hash']=self::sourceHash($row);$q=$this->db->pdo()->prepare('INSERT INTO nonprofit_capital_sources(supplier_id,command_id,source_line_id,asset_id,provider_id,kind,amount_cents,policy_json,basis_json,record_hash)VALUES(?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$row['supplier_id'],$row['command_id'],$row['source_line_id'],$row['asset_id'],$row['provider_id'],$row['kind'],$row['amount_cents'],NonprofitTaxRules::canonicalJson($row['policy']),NonprofitTaxRules::canonicalJson($row['basis']),$row['record_hash']]);$row['id']=(int)$this->db->pdo()->lastInsertId();return $row;
    }
    public function appendEvent(array $row):array
    {
        $row['record_hash']=self::eventHash($row);$q=$this->db->pdo()->prepare('INSERT INTO nonprofit_capital_events(supplier_id,source_id,command_id,previous_id,original_id,parent_hash,operation,amount_cents,entry_id,depreciation_id,cost_line_id,snapshot_json,receipt_json,record_hash)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$row['supplier_id'],$row['source_id'],$row['command_id'],$row['previous_id'],$row['original_id'],$row['parent_hash'],$row['operation'],$row['amount_cents'],$row['entry_id'],$row['depreciation_id'],$row['cost_line_id'],NonprofitTaxRules::canonicalJson($row['snapshot']),NonprofitTaxRules::canonicalJson($row['receipt']),$row['record_hash']]);$row['id']=(int)$this->db->pdo()->lastInsertId();return $row;
    }
    public static function sourceHash(array $row):string{return CapitalPolicy::hash(array_intersect_key($row,array_flip(['supplier_id','command_id','source_line_id','asset_id','provider_id','kind','amount_cents','policy','basis'])));}
    public static function eventHash(array $row):string{return CapitalPolicy::hash(array_intersect_key($row,array_flip(['supplier_id','source_id','command_id','previous_id','original_id','parent_hash','operation','amount_cents','entry_id','depreciation_id','cost_line_id','snapshot','receipt'])));}
}
