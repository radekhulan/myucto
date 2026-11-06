<?php

declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

function verify(bool $condition):void {if(!$condition)throw new RuntimeException('synthetic_function_case_assertion_failed');}
$service=new class {
    public array $arguments=[];
    public ?Throwable $failure=null;
    public function incomeStatementByFunction(mixed ...$args):array {
        if($this->failure!==null)throw $this->failure;
        $this->arguments=$args;
        return ['entity'=>['prepared_at'=>'synthetic presentation timestamp'],'original_doc_number'=>'1e999999','amount'=>'1.000000000000000001','due_date'=>'2025-12-31'];
    }
};
$dimensions=new class {public array $arguments=[];public function filter(mixed ...$args):array {$this->arguments=$args;return ['synthetic_dimension_filter'=>true];}};
$container=new class($service,$dimensions) {
    public function __construct(private object $service,private object $dimensions){}
    public function get(string $class):object {return match($class){'MyInvoice\\Service\\Accounting\\Reports\\FinancialStatementService'=>$this->service,'MyInvoice\\Service\\Accounting\\Dimension\\DimensionService'=>$this->dimensions,default=>throw new RuntimeException('unexpected_synthetic_callable')};}
};
$case=['kind'=>'income_statement_function','args'=>[1,2,'2026-06-30','full',null]];
$data=MyUctoRegression\Manifest::invoke($container,$case);
verify($service->arguments===$case['args']);
$normalized=MyUctoRegression\Manifest::normalize($data,'2026-10-17');
verify($normalized['entity']['prepared_at']==='2026-10-17 00:00');
verify($normalized['due_date']===$data['due_date']);
verify($normalized['original_doc_number']==='1e999999');
verify($normalized['amount']==='1.000000000000000001');
$case['dimension']=['value_id'=>9,'descendants'=>false];
MyUctoRegression\Manifest::invoke($container,$case);
verify($dimensions->arguments===[1,9,false]);
verify($service->arguments===[1,2,'2026-06-30','full',['synthetic_dimension_filter'=>true]]);
$failure=new RuntimeException('function_map_incomplete');$service->failure=$failure;$actual=null;
try {MyUctoRegression\Manifest::invoke($container,$case);}catch(Throwable $error){$actual=$error;}
verify($actual===$failure);
echo json_encode(['synthetic_only'=>true,'assertions'=>8,'result'=>'GREEN','database_used'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
