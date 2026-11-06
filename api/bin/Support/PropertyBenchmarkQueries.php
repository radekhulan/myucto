<?php

declare(strict_types=1);
namespace MyInvoice\Benchmark;
use PDO;
use PDOStatement;
final class PropertyBenchmarkQueries
{
    private array $queries=[];
    public function attach(PDO $pdo):void{$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PropertyBenchmarkStatement::class,[$this]]);}
    public function detach(PDO $pdo):void{$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PDOStatement::class]);}
    public function record(string $sql,?array $parameters,float $ms):void
    {
        if(preg_match('/^\s*(?:SELECT|WITH)\b/i',$sql)!==1)return;
        $key=hash('sha256',$sql);$this->queries[$key]??=['sql'=>$sql,'parameters'=>$parameters,'calls'=>0,'total_ms'=>0.0,'max_ms'=>0.0];$q=&$this->queries[$key];$q['calls']++;$q['total_ms']+=$ms;
        if($ms>=$q['max_ms']){$q['max_ms']=$ms;$q['parameters']=$parameters;}
    }
    public function summary():array
    {
        $calls=0;$total=0.0;$max=0;
        foreach($this->queries as $query){$calls+=$query['calls'];$total+=$query['total_ms'];$max=max($max,$query['calls']);}
        return ['distinct_selects'=>count($this->queries),'recorded_select_calls'=>$calls,'recorded_total_ms'=>$total,'max_calls_per_sql'=>$max];
    }
    public function plans(PDO $pdo,int $limit=12):array
    {
        $queries=array_values($this->queries);usort($queries,static fn($a,$b)=>$b['total_ms']<=>$a['total_ms']);$out=[];
        foreach(array_slice($queries,0,$limit) as $query){$row=$query;unset($row['parameters']);
            foreach(['EXPLAIN','ANALYZE'] as $mode){$q=$pdo->prepare($mode.' FORMAT=JSON '.$query['sql']);$q->execute($query['parameters']);$row[strtolower($mode)]=json_decode($q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);}
            $out[]=$row;
        }
        return $out;
    }
}
final class PropertyBenchmarkStatement extends PDOStatement
{
    protected function __construct(private readonly PropertyBenchmarkQueries $owner){}
    public function execute(?array $params=null):bool
    {
        $started=hrtime(true);try{return parent::execute($params);}finally{$this->owner->record($this->queryString,$params,(hrtime(true)-$started)/1e6);}
    }
}
