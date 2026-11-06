<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountDefinitionRepository as R;
use MyInvoice\Service\Anonymization\{AnonymizationService,AnonymizationOptions,DatabaseCloner};
use MyInvoice\Tests\Support\CloneSandbox;
use PDO;
use PHPUnit\Framework\TestCase;
final class FrameworkConversionCloneTest extends TestCase
{
    private array $owned=[];
    private PDO $server;
    private Config $config;
    private Connection $source;
    private ?Connection $target=null;
    private FrameworkConversionWorkflowTest $fixture;
    private string|false $priorDatabase;
    protected function setUp(): void
    {
        $this->priorDatabase=getenv('MYINVOICE_DB_NAME');$this->config=Config::load(Bootstrap::rootDir());$base=(string)$this->config->get('db.name');
        self::assertStringEndsWith('_test',$base);self::assertContains($this->config->get('db.host'),['localhost','127.0.0.1','::1']);
        $token=bin2hex(random_bytes(5));$this->owned=['myucto_nno_conversion_clone_'.$token.'_source_test','myucto_nno_conversion_clone_'.$token.'_target_test'];
        $this->server=new PDO('mysql:host='.$this->config->get('db.host').';port='.$this->config->get('db.port',3306).';charset=utf8mb4',$this->config->get('db.user'),$this->config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $q=$this->server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?,?)');$q->execute($this->owned);self::assertSame(0,(int)$q->fetchColumn());
        $this->owned[0]=CloneSandbox::acquire($this->server,$base,true);
        putenv('MYINVOICE_DB_NAME='.$this->owned[0]);$this->fixture=new FrameworkConversionWorkflowTest('testNext504ForeignNativeCaseUsesProvedConversionOpening');
        Connection::withoutSharedTestConnection(fn()=>(new \ReflectionMethod($this->fixture,'setUp'))->invoke($this->fixture));$this->source=(new \ReflectionProperty($this->fixture,'db'))->getValue($this->fixture);
    }
    protected function tearDown(): void
    {
        try{
            if(isset($this->fixture))(new \ReflectionMethod($this->fixture,'tearDown'))->invoke($this->fixture);
            if(isset($this->source))$this->source->close();
            if($this->target!==null){if($this->target->pdo()->inTransaction())$this->target->pdo()->rollBack();$this->target->close();}
            if(isset($this->server))foreach(array_reverse($this->owned) as $name)CloneSandbox::dropOrRelease($this->server,$name,$this->owned);
        }finally{if(isset($this->priorDatabase))putenv($this->priorDatabase===false?'MYINVOICE_DB_NAME':'MYINVOICE_DB_NAME='.$this->priorDatabase);}
    }
    private function call(string $method,mixed ...$args): mixed{return(new \ReflectionMethod($this->fixture,$method))->invoke($this->fixture,...$args);}
    private function supplier(): int{return(new \ReflectionProperty($this->fixture,'supplier'))->getValue($this->fixture);}
    private function prepare(bool $decision=false,bool $laterConfig=false):array
    {
        $supplier=$this->supplier();$actor=(new \ReflectionProperty($this->fixture,'actor'))->getValue($this->fixture);$container=(new \ReflectionProperty($this->fixture,'container'))->getValue($this->fixture);$periods=$container->get(\MyInvoice\Repository\AccountingPeriodRepository::class);$source=(new \ReflectionProperty($this->fixture,'source'))->getValue($this->fixture);$target=(new \ReflectionProperty($this->fixture,'target'))->getValue($this->fixture);
        $this->source->pdo()->prepare("UPDATE chart_of_accounts SET name='Synthetic conversion-account@synthetic.invalid' WHERE supplier_id=? AND account_code='221'")->execute([$supplier]);$command=$this->call('command');$command['evidence']='Synthetic conversion-evidence@synthetic.invalid';foreach($command['mapping'] as &$mapping)$mapping['activity_kind']['name']='Synthetic conversion-kind@synthetic.invalid';unset($mapping);
        $workflow=$container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionWorkflow::class);$preview=$workflow->preview($supplier,$command,$actor);$result=$workflow->confirm($supplier,$command,$actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-clone-conversion');
        if($decision){self::assertTrue($periods->setStatusCas($source,$supplier,'approved',(int)$periods->findById($supplier,$source)['row_version'],$actor,['body'=>'Synthetic conversion-authority@synthetic.invalid','decision_ref'=>'Synthetic conversion-approval@synthetic.invalid','document_hash'=>hash('sha256','synthetic-conversion-approved')]));$decisions=$container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionResultDecision::class);$choices=$decisions->choices($supplier,$result['conversion_id'],'2026-03-01');$body=['decision_date'=>'2026-03-01','decision_authority'=>'Synthetic conversion-decision-authority@synthetic.invalid','decision_reference'=>'Synthetic conversion-decision-reference@synthetic.invalid','allocations'=>array_map(fn($cell)=>['cell_key'=>$cell['key'],'role'=>'result.retained_profit','amount'=>\MyInvoice\Service\Accounting\Closing\Fx504Builder::decimal(abs($cell['available_cents']))],$choices['cells'])];$preview=$decisions->preview($supplier,$result['conversion_id'],$body,$actor);$result['decision']=$decisions->confirm($supplier,$result['conversion_id'],$body,$actor,$preview['state_hash'],$preview['preview_hash'],'synthetic-clone-decision');}
        if($laterConfig){$definitions=new \MyInvoice\Repository\AccountDefinitionRepository($this->source);$account=$container->get(\MyInvoice\Repository\ChartOfAccountsRepository::class)->findByCode($supplier,'221','2026-01-01');$definitions->change($supplier,$account['id'],'2027-01-01',['name'=>'Synthetic later independent name'],$definitions->stateHash($supplier,$account['id']),$actor,'Synthetic later config owner');}
        $this->source->pdo()->commit();return $result+['actor'=>$actor,'source'=>$source,'target'=>$target];
    }
    private function cloneTarget():\Psr\Container\ContainerInterface
    {
        $before=$this->fingerprint($this->source);$this->owned[1]=CloneSandbox::anonymized($this->server,$this->config,$this->owned[0],'synthetic-conversion-clone');self::assertSame($before,$this->fingerprint($this->source));
        putenv('MYINVOICE_DB_NAME='.$this->owned[1]);$container=Connection::withoutSharedTestConnection(function(){$container=Bootstrap::buildContainer();$this->target=$container->get(Connection::class);return $container;});$this->target->pdo()->beginTransaction();self::assertSame($this->owned[1],$this->target->pdo()->query('SELECT DATABASE()')->fetchColumn());return $container;
    }
    public function testActualFullClonePreservesFinancialOriginProofAndOwnedCancellation():void
    {
        $result=$this->prepare();$container=$this->cloneTarget();$read=new \MyInvoice\Repository\FrameworkConversionRepository($this->target);$row=$read->receipt($this->supplier(),$result['conversion_id'],true);$source=$read->source($this->supplier(),$result['source'],$result['target'],true);self::assertSame($source['source_hash'],$row['source_hash']);
        $fx=(new \MyInvoice\Repository\Fx504ReadRepository($this->target))->snapshot($this->supplier(),$result['target'],true);self::assertSame([],$fx['case_snapshot']['findings']);self::assertNotEmpty($fx['case_snapshot']['origins']);
        $all='';foreach(['accounting_framework_conversions','accounting_framework_conversion_cells','accounting_account_definition_history'] as $table)$all.=json_encode($this->target->pdo()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);foreach(['conversion-account@synthetic.invalid','conversion-evidence@synthetic.invalid','conversion-kind@synthetic.invalid'] as $private)self::assertStringNotContainsString($private,$all);self::assertSame(0,(int)$this->target->pdo()->query('SELECT COUNT(*) FROM accounting_framework_conversion_commands')->fetchColumn());
        $workflow=$container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionWorkflow::class);$preview=$workflow->cancellationPreview($this->supplier(),$result['conversion_id'],$result['actor']);$cancel=$workflow->cancel($this->supplier(),$result['conversion_id'],$result['actor'],$preview['receipt_hash'],$preview['preview_hash'],'synthetic-target-cancel');self::assertSame('cancelled',$cancel['status']);self::assertSame('cz_500',$container->get(\MyInvoice\Service\Accounting\AccountingFrameworkService::class)->forDate($this->supplier(),'2026-01-01'));
    }
    public function testActualFullClonePreserves931DecisionAndCannotCancelConsumedOpening():void
    {
        $result=$this->prepare(true);$container=$this->cloneTarget();$q=$this->target->pdo()->prepare('SELECT * FROM accounting_framework_conversion_decisions WHERE supplier_id=?');$q->execute([$this->supplier()]);$decision=$q->fetch(PDO::FETCH_ASSOC);$receipt=json_decode($decision['receipt_json'],true,512,JSON_THROW_ON_ERROR);self::assertSame(\MyInvoice\Repository\FrameworkConversionRepository::hash($receipt),$decision['receipt_hash']);self::assertSame($receipt['ledger'],(new \MyInvoice\Service\Property\Payments\PaymentJournalProof($this->target))->current($this->supplier(),(int)$decision['journal_entry_id']));self::assertStringNotContainsString('conversion-decision-authority@synthetic.invalid',$decision['request_json']);self::assertStringNotContainsString('conversion-decision-reference@synthetic.invalid',$decision['request_json']);
        try{$container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionWorkflow::class)->cancellationPreview($this->supplier(),$result['conversion_id'],$result['actor']);self::fail('Native result decision consumed the converted opening');}catch(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionException $e){self::assertSame('conversion_already_consumed',$e->errorCode);}
    }
    public function testActualFullCloneDoesNotEraseLaterMetadataConsumption():void
    {
        $result=$this->prepare(false,true);$container=$this->cloneTarget();try{$container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionWorkflow::class)->cancellationPreview($this->supplier(),$result['conversion_id'],$result['actor']);self::fail('Independent later metadata must remain protected after cloning');}catch(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionException $e){self::assertSame('conversion_configuration_consumed',$e->errorCode);}
    }
    public function testCancelledHistoricalConversionCloneAfterCorrectedNativeReclosePreservesArchive():void
    {
        $result=$this->prepare();$container=(new \ReflectionProperty($this->fixture,'container'))->getValue($this->fixture);$supplier=$this->supplier();$actor=$result['actor'];$workflow=$container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionWorkflow::class);$preview=$workflow->cancellationPreview($supplier,$result['conversion_id'],$actor);$workflow->cancel($supplier,$result['conversion_id'],$actor,$preview['receipt_hash'],$preview['preview_hash'],'synthetic-clone-cancel-release');$periods=$container->get(\MyInvoice\Repository\AccountingPeriodRepository::class);$version=fn()=>(int)$periods->findById($supplier,$result['source'])['row_version'];$closing=$container->get(\MyInvoice\Service\Accounting\Closing\ClosingService::class);$meta=['user_id'=>$actor,'posted_by'=>$actor];
        $closing->revertStep($supplier,$result['source'],'close_books',$version(),$meta);$closing->abort($supplier,$result['source'],$version(),$meta);$container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($supplier,'manual',null,[['account_code'=>'221','side'=>'debit','amount'=>'25.00'],['account_code'=>'602','side'=>'credit','amount'=>'25.00']],['entry_date'=>'2025-07-01','description'=>'Synthetic corrected-source@synthetic.invalid','posted'=>true]+$meta);
        $closing->start($supplier,$result['source'],$version(),$meta);$items=[];foreach($closing->inventoryPreview($supplier,$result['source'])['rows']as$row)$items[(int)$row['account_id']]=['counted_balance'=>(float)$row['book_balance'],'resolution'=>'resolved','note'=>null];$closing->saveInventory($supplier,$result['source'],$version(),['complete'=>true],$items,$meta);$closing->runPrecheck($supplier,$result['source'],$version(),$meta);$closing->confirmStep($supplier,$result['source'],'depreciation','skipped',null,$version(),$meta);$fx=$closing->fxPreview($supplier,$result['source']);$closing->runFxRevaluation($supplier,$result['source'],[],$version(),$meta,isset($fx['snapshot_hash'])?['snapshot_hash'=>$fx['snapshot_hash']]:[]);foreach(['estimates','deferrals','provisions','income_tax']as$step)$closing->confirmStep($supplier,$result['source'],$step,'skipped',null,$version(),$meta);self::assertSame('closed',$closing->closeBooks($supplier,$result['source'],$version(),$meta)['status']);
        $read=new \MyInvoice\Repository\FrameworkConversionRepository($this->source);$this->source->pdo()->beginTransaction();$before=$read->receipt($supplier,$result['conversion_id'],true);$current=$read->source($supplier,$result['source'],$result['target'],true);self::assertNotSame($before['source_hash'],$current['source_hash']);$this->source->pdo()->commit();$this->cloneTarget();$after=(new \MyInvoice\Repository\FrameworkConversionRepository($this->target))->receipt($supplier,$result['conversion_id'],true);
        self::assertSame('cancelled',$after['status']);self::assertNull($after['source_entry_id']);self::assertNull($after['opening_entry_id']);self::assertSame($before['source_hash'],$after['source_hash']);self::assertSame($before['receipt']['plan']['source_entry_id'],$after['receipt']['plan']['source_entry_id']);self::assertSame($before['receipt']['opening_entry_id'],$after['receipt']['opening_entry_id']);
        foreach(['lines','ledger']as$key){$financial=static function(array$value)use(&$financial):array{foreach($value as$k=>&$v){if(in_array((string)$k,['cost_center','ref'],true))$v=null;elseif(is_array($v))$v=$financial($v);}unset($v);return$value;};self::assertSame($financial($before['receipt'][$key]),$financial($after['receipt'][$key]));}
        self::assertSame($after['receipt_hash'],\MyInvoice\Repository\FrameworkConversionRepository::hash($after['receipt']));self::assertSame($after['receipt']['request_hash'],\MyInvoice\Repository\FrameworkConversionRepository::hash($after['request']));$q=$this->target->pdo()->prepare('SELECT source_json,target_json FROM accounting_framework_conversion_cells WHERE supplier_id=? AND conversion_id=? ORDER BY cell_key');$q->execute([$supplier,$result['conversion_id']]);$json=$after['receipt_json'].$after['request_json'].json_encode($q->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);foreach(['conversion-account@synthetic.invalid','conversion-evidence@synthetic.invalid','conversion-kind@synthetic.invalid']as$sentinel)self::assertStringNotContainsString($sentinel,$json);
    }
    public function testCancelledArchiveRejectsRehashedMaterializedMoney():void
    {
        $result=$this->prepare();$container=(new \ReflectionProperty($this->fixture,'container'))->getValue($this->fixture);$workflow=$container->get(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionWorkflow::class);$supplier=$this->supplier();$preview=$workflow->cancellationPreview($supplier,$result['conversion_id'],$result['actor']);$workflow->cancel($supplier,$result['conversion_id'],$result['actor'],$preview['receipt_hash'],$preview['preview_hash'],'synthetic-tampered-archive');$read=new \MyInvoice\Repository\FrameworkConversionRepository($this->source);$row=$read->receipt($supplier,$result['conversion_id']);$receipt=$row['receipt'];
        foreach([0,1]as$index){$receipt['lines'][$index]['amount']=bcadd($receipt['lines'][$index]['amount'],'0.01',2);$receipt['ledger']['lines'][$index]['amount']=bcadd($receipt['ledger']['lines'][$index]['amount'],'0.01',2);}
        $q=$this->source->pdo()->prepare('UPDATE accounting_framework_conversions SET receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=?');$q->execute([\MyInvoice\Repository\FrameworkConversionRepository::json($receipt),\MyInvoice\Repository\FrameworkConversionRepository::hash($receipt),$supplier,$result['conversion_id']]);
        foreach($this->source->pdo()->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC)as$trigger)$this->source->pdo()->exec('DROP TRIGGER `'.str_replace('`','``',$trigger['Trigger']).'`');
        try{\MyInvoice\Service\Anonymization\FrameworkConversionCloneScrubber::captureInputs($this->source);self::fail('A freshly hashed archived ledger cannot replace the original semantic amount');}catch(\MyInvoice\Service\Accounting\Conversion\FrameworkConversionException$e){self::assertSame('conversion_cancelled_archive_changed',$e->errorCode);}
    }
    private function fingerprint(Connection $db):string
    {
        $out=[];foreach(['accounting_framework_conversions','accounting_framework_conversion_cells','accounting_framework_conversion_commands','accounting_framework_conversion_decisions','accounting_account_definition_history','chart_of_accounts','journal_entries','journal_entry_lines'] as $table)$out[$table]=$db->pdo()->query('SELECT * FROM '.$table.' ORDER BY supplier_id'.($table==='accounting_framework_conversion_cells'?',conversion_id,cell_key':($table==='accounting_framework_conversion_commands'?',command_token':',id')))->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',json_encode($out,JSON_THROW_ON_ERROR));
    }
}
