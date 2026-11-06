<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Property;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Receivables\PropertySaldoReadClosure;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyInput;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PropertySaldoReadClosureFrontierTest extends TestCase
{
    public function testDiscoveryReturnsEachFactOnceAndRetainsAllEdges():void
    {
        $pdo=new SaldoFrontierPdo();$selection=(new PropertySaldoReadClosure(self::connection($pdo)))->units(7,[1]);
        foreach(['unit_payment_debts'=>[1,2,3,4],'unit_payment_operations'=>[10,20,30],'unit_charge_lines'=>[101,111,201,301,501],'unit_payment_corrections'=>[90],'unit_payment_allocations'=>[1001],'unit_credit_lots'=>[70],'unit_credit_events'=>[71],'unit_credit_reservations'=>[80],'unit_payment_sources'=>[900]] as $table=>$ids){
            self::assertSame($ids,array_column($selection->rows[$table],'id'),$table);
        }
        self::assertSame([10,11,20,30,50],self::sorted($selection->chargeIds));
        self::assertSame([1,2,3,4],self::sorted($selection->debtIds));
        self::assertCount(1,$selection->rows['unit_payment_correction_anchors']);
        self::assertCount(1,$selection->rows['unit_payment_reliefs']);
        self::assertSame(1,$pdo->openingSeeds);
        self::assertLessThanOrEqual(33,$pdo->selects-$pdo->serviceSelects);self::assertLessThanOrEqual(6,$pdo->serviceSelects);
        self::assertLessThanOrEqual(28,array_sum(array_map('count',$pdo->returned)));
        foreach(['unit_payment_debts','unit_payment_operations','unit_payment_corrections'] as $table){
            self::assertSame(count(array_unique($pdo->returned[$table])),count($pdo->returned[$table]),$table.' must not refetch already discovered immutable facts');
        }
    }
    public function testMissingCrossUnitDebtFailsClosed():void
    {
        $pdo=new SaldoFrontierPdo();$pdo->tables['unit_payment_debts']=array_values(array_filter($pdo->tables['unit_payment_debts'],fn($r)=>$r['id']!==3));
        $this->expectException(PropertyException::class);
        (new PropertySaldoReadClosure(self::connection($pdo)))->units(7,[1]);
    }
    public function testCorruptLateSnapshotFailsClosed():void
    {
        $pdo=new SaldoFrontierPdo();$pdo->tables['unit_payment_operations'][2]['snapshot_hash']=str_repeat('0',64);
        $this->expectException(PropertyException::class);
        (new PropertySaldoReadClosure(self::connection($pdo)))->units(7,[1]);
    }
    public function testSecondInvocationDoesNotReuseRowsOrProof():void
    {
        $pdo=new SaldoFrontierPdo();$reader=new PropertySaldoReadClosure(self::connection($pdo));$reader->units(7,[1]);
        $pdo->tables['unit_payment_operations'][2]['snapshot_hash']=str_repeat('0',64);
        $this->expectException(PropertyException::class);$reader->units(7,[1]);
    }
    public function testOwnerTransactionIsRequired():void
    {
        $pdo=new SaldoFrontierPdo();$pdo->transaction=false;
        $this->expectException(\LogicException::class);(new PropertySaldoReadClosure(self::connection($pdo)))->units(7,[1]);
    }
    public static function connection(SaldoFrontierPdo $pdo):Connection
    {
        $reflection=new ReflectionClass(Connection::class);$db=$reflection->newInstanceWithoutConstructor();$reflection->getProperty('pdo')->setValue($db,$pdo);return $db;
    }
    private static function sorted(array $ids):array{sort($ids,SORT_NUMERIC);return $ids;}
}

final class SaldoFrontierPdo extends PDO
{
    public array $tables=[];
    public int $openingSeeds=0;
    public array $returned=[];
    public int $selects=0;
    public int $serviceSelects=0;
    public bool $transaction=true;
    public function __construct()
    {
        foreach([[10,1,'a',1],[11,1,'a',1],[20,2,'b',2],[30,3,'c',3],[50,9,'d',1],[999,1,'a',1]] as [$id,$unit,$source,$read])$this->tables['unit_charges'][]=['id'=>$id,'supplier_id'=>$id===999?8:7,'unit_id'=>$unit,'read_unit_id'=>$read,'source_key'=>$source];
        foreach([[1,10,101],[2,20,201],[3,30,301],[4,50,501]] as [$id,$charge,$line]){
            $this->tables['unit_payment_debts'][]=self::fact(['id'=>$id,'supplier_id'=>7,'charge_id'=>$charge,'read_charge_id'=>$charge],['lines'=>[['id'=>$line]]]);
            $this->tables['unit_charge_lines'][]=['id'=>$line,'supplier_id'=>7,'charge_id'=>$charge];
            $this->tables['unit_payment_debt_lines'][]=['supplier_id'=>7,'debt_id'=>$id,'charge_line_id'=>$line];
        }
        $this->tables['unit_charge_lines'][]=['id'=>111,'supplier_id'=>7,'charge_id'=>11];
        foreach([[10,null,900,1,10],[20,null,900,2,20],[30,20,null,3,30]] as [$id,$original,$source,$debt,$charge]){
            $fact=['allocations'=>[['debt_id'=>$debt,'charge_id'=>$charge]]];
            if($id===10)$fact+=['credit'=>['id'=>70,'unit_id'=>1],'reservation'=>['id'=>80],'source'=>['id'=>900,'receipt'=>['ledger'=>['entry'=>['id'=>901]]]]];
            if($id===30)$fact+=['original_operation_id'=>20,'correction_id'=>90];
            $this->tables['unit_payment_operations'][]=self::fact(['id'=>$id,'supplier_id'=>7,'source_id'=>$source,'original_operation_id'=>$original,'read_source_entry_id'=>$id===20?901:null,'status'=>$id===30?'reversed':'posted','kind'=>$id===30?'reversal':'allocation'], $fact)+['read_links_json'=>json_encode(['debt_ids'=>[$debt],'original_operation_id'=>$original,'credit_id'=>$id===10?70:null,'reservation_id'=>$id===10?80:null,'correction_id'=>$id===30?90:null,'source_id'=>$source,'credit_unit_id'=>$id===10?1:null])];
        }
        $this->tables['unit_payment_corrections']=[self::fact(['id'=>90,'supplier_id'=>7,'adjustment_charge_id'=>11,'operation_id'=>30],['anchors'=>[['original_line_id'=>201,'adjustment_line_id'=>111]],'reliefs'=>[['allocation_id'=>1001]]])];
        $this->tables['unit_payment_correction_anchors']=[['supplier_id'=>7,'correction_id'=>90,'adjustment_line_id'=>111,'original_line_id'=>201]];
        $this->tables['unit_payment_reliefs']=[['supplier_id'=>7,'correction_id'=>90,'allocation_id'=>1001]];
        $this->tables['unit_payment_allocations']=[['id'=>1001,'supplier_id'=>7,'debt_id'=>2,'charge_id'=>20,'operation_id'=>20,'allocation_json'=>json_encode(['debt_id'=>2,'charge_line_id'=>201])]];
        $this->tables['unit_payment_sources']=[['id'=>900,'supplier_id'=>7,'journal_entry_id'=>901]];
        $this->tables['unit_credit_lots']=[['id'=>70,'supplier_id'=>7,'unit_id'=>2,'read_unit_id'=>2,'operation_id'=>10]];
        $this->tables['unit_credit_events']=[['id'=>71,'supplier_id'=>7,'credit_id'=>70,'operation_id'=>30,'reservation_id'=>80]];
        $this->tables['unit_credit_reservations']=[['id'=>80,'supplier_id'=>7,'credit_id'=>70]];
    }
    public function inTransaction():bool{return $this->transaction;}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new SaldoFrontierStatement($this,$query);}
    private static function fact(array $row,array $fact):array{return $row+['snapshot_json'=>json_encode($fact,JSON_THROW_ON_ERROR),'snapshot_hash'=>PropertyInput::hash($fact)];}
    public function run(string $sql,array $params):array
    {
        ++$this->selects;if(str_contains($sql,'property_service_settlement'))++$this->serviceSelects;
        if(str_starts_with($sql,'SELECT d.id FROM unit_payment_debts d JOIN property_opening_items i ')){
            ++$this->openingSeeds;
            if(!preg_match('/i.unit_id IN \(([0-9,]+)\)/',$sql,$m))throw new \LogicException('Unexpected opening seed SQL');$units=array_map('intval',explode(',',$m[1]));$result=[];
            foreach($this->tables['unit_payment_debts']??[] as $debt)foreach($this->tables['property_opening_items']??[] as $item)if($debt['supplier_id']===$params[0]&&$item['supplier_id']===$debt['supplier_id']&&$item['id']===($debt['opening_item_id']??null)&&in_array($item['unit_id'],$units,true))$result[]=['id'=>$debt['id']];
            usort($result,fn($a,$b)=>$a['id']<=>$b['id']);return $result;
        }
        if(str_starts_with($sql,'SELECT DISTINCT a.document_id id FROM property_service_settlement_applications a JOIN property_service_settlements d ')){
            preg_match('/d.unit_id IN \(([0-9,]+)\)/',$sql,$m);$units=isset($m[1])?array_map('intval',explode(',',$m[1])):[];$ids=[];
            foreach($this->tables['property_service_settlement_applications']??[] as $app)foreach($this->tables['property_service_settlements']??[] as $doc)if($app['supplier_id']===$params[0]&&$doc['supplier_id']===$app['supplier_id']&&$doc['id']===$app['document_id']&&in_array($doc['unit_id'],$units,true))$ids[$doc['id']]=true;
            ksort($ids,SORT_NUMERIC);return array_map(static fn($id)=>['id'=>$id],array_keys($ids));
        }
        if(str_starts_with($sql,'SELECT sibling.id')){
            preg_match('/selected.id IN \(([0-9,]+)\)/',$sql,$m);$ids=isset($m[1])?array_map('intval',explode(',',$m[1])):[];$result=[];
            foreach($this->tables['unit_charges'] as $selected)if($selected['supplier_id']===$params[0]&&in_array($selected['id'],$ids,true))foreach($this->tables['unit_charges'] as $sibling)if($sibling['supplier_id']===$selected['supplier_id']&&$sibling['unit_id']===$selected['unit_id']&&$sibling['source_key']===$selected['source_key'])$result[]=['id'=>$sibling['id']];
            usort($result,fn($a,$b)=>$a['id']<=>$b['id']);return $result;
        }
        if(!preg_match('/^SELECT r\.\* FROM ([a-z_]+) r WHERE r\.supplier_id=\? AND /',$sql,$m))throw new \LogicException('Unexpected SQL');$table=$m[1];
        $exclude=[];if(preg_match('/ AND NOT \(r.id IN \(([0-9,]+)\)\)/',$sql,$m)){$exclude=array_map('intval',explode(',',$m[1]));$sql=str_replace($m[0],'',$sql);}
        preg_match_all('/r\.([a-z_]+) IN \(([0-9,]+)\)/',$sql,$columns,PREG_SET_ORDER);
        preg_match_all('/JSON_TABLE\(r\.([a-z_]+),.*?\) refs WHERE (.*?)\)\)/',$sql,$json,PREG_SET_ORDER);
        $result=[];
        foreach($this->tables[$table]??[] as $row){
            if($row['supplier_id']!==$params[0]||in_array($row['id']??null,$exclude,true))continue;$matches=false;
            foreach($columns as $column)if(in_array($row[$column[1]]??null,array_map('intval',explode(',',$column[2])),true))$matches=true;
            foreach($json as $part){
                preg_match_all("/NESTED PATH '([^']+)' COLUMNS\((value[0-9]+) BIGINT/",$part[0],$paths,PREG_SET_ORDER);
                $value=json_decode($row[$part[1]]??'{}',true,flags:JSON_THROW_ON_ERROR);
                foreach($paths as $path){if(!preg_match('/refs\.'.$path[2].' IN \(([0-9,]+)\)/',$part[2],$ids))continue;
                    $values=[$value];foreach(explode('.',substr($path[1],2)) as $key){$next=[];$array=str_ends_with($key,'[*]');$key=str_replace('[*]','',$key);foreach($values as $v)if(isset($v[$key])){if($array)array_push($next,...$v[$key]);else $next[]=$v[$key];}$values=$next;}
                    if(array_intersect($values,array_map('intval',explode(',',$ids[1])))!==[])$matches=true;
                }
            }
            if($matches)$result[]=$row;
        }
        preg_match('/ORDER BY (?:r\.)?([a-z_,]+)$/',$sql,$m);$order=explode(',',$m[1]);usort($result,static function($a,$b)use($order){foreach($order as $key)if(($n=($a[$key]??null)<=>($b[$key]??null))!==0)return $n;return 0;});
        foreach($result as $row)$this->returned[$table][]=$row['id']??json_encode($row);return $result;
    }
}
final class SaldoFrontierStatement extends PDOStatement
{
    private array $rows=[];
    public function __construct(private readonly SaldoFrontierPdo $pdo,private readonly string $sql){}
    public function execute(?array $params=null):bool{$this->rows=$this->pdo->run($this->sql,$params??[]);return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
}
