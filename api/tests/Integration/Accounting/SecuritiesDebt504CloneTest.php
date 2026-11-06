<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Accounting;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\SecuritiesDebt504Repository as R;
use MyInvoice\Service\Anonymization\{AnonymizationService,AnonymizationOptions,DatabaseCloner};
use MyInvoice\Tests\Support\CloneSandbox;
use PDO;
use PHPUnit\Framework\TestCase;
final class SecuritiesDebt504CloneTest extends TestCase
{
    private array $owned=[];
    private PDO $server;
    private Config $config;
    private Connection $source;
    private ?Connection $target=null;
    private SecuritiesDebt504WorkflowTest $fixture;
    private string|false $priorDatabase;
    protected function setUp(): void
    {
        $this->priorDatabase=getenv('MYINVOICE_DB_NAME');$this->config=Config::load(Bootstrap::rootDir());$base=(string)$this->config->get('db.name');
        self::assertStringEndsWith('_test',$base);self::assertContains($this->config->get('db.host'),['localhost','127.0.0.1','::1']);
        $token=bin2hex(random_bytes(5));$this->owned=['myucto_debt_clone_'.$token.'_source_test','myucto_debt_clone_'.$token.'_target_test'];
        $this->server=new PDO('mysql:host='.$this->config->get('db.host').';port='.$this->config->get('db.port',3306).';charset=utf8mb4',$this->config->get('db.user'),$this->config->get('db.pass',''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $q=$this->server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?,?)');$q->execute($this->owned);self::assertSame(0,(int)$q->fetchColumn());
        $this->owned[0]=CloneSandbox::acquire($this->server,$base,true);
        putenv('MYINVOICE_DB_NAME='.$this->owned[0]);$this->fixture=new SecuritiesDebt504WorkflowTest('testNativeCouponAcquisitionAccrualAcrossYearPartialReceiptsAndPrincipalAreNotIncome');
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
    private function prepare(): array
    {
        $terms=$this->call('coupon');$terms['contract_reference']='Synthetic private-contract@synthetic.invalid';$terms['lot_reference']='Synthetic private-position@synthetic.invalid';$lot=$this->call('register',$terms);
        $event=$this->call('applyCommand',['operation'=>'accrual','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic private-accrual@synthetic.invalid']);
        $this->call('applyCommand',['operation'=>'reverse','lot_id'=>$lot,'accounting_date'=>'2025-12-31','event_id'=>$event['event_id'],'evidence'=>'Synthetic private-reversal@synthetic.invalid']);
        $this->call('applyCommand',['operation'=>'accrual','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic private-final-accrual@synthetic.invalid']);
        $this->call('applyCommand',['operation'=>'valuation','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic private-unchanged-quote@synthetic.invalid','profile'=>$this->call('profile'),'quote'=>$this->call('quote','1000.00')]);
        $bank=$this->call('bank','20.00','2026-01-15','253');$this->source->pdo()->exec("UPDATE bank_transactions SET counterparty_name='Synthetic private-issuer@synthetic.invalid' WHERE id=(SELECT source_id FROM journal_entries WHERE id=".$bank['entry_id'].")");
        $this->call('applyCommand',['operation'=>'coupon_receipt','lot_id'=>$lot,'accounting_date'=>'2026-01-15','evidence'=>'Synthetic private-receipt@synthetic.invalid']+$bank);
        $repo=new R($this->source);$state=$repo->current($this->supplier(),$lot,'2026-01-15');$this->source->pdo()->commit();return[$lot,$state];
    }
    public function testActualFullAnonymizedCloneKeepsNativeGraphValidAndRemovesPrivateTextsAndCommandTokens(): void
    {
        [$lot,$before]=$this->prepare();$fingerprint=$this->fingerprint($this->source);
        $this->owned[1]=CloneSandbox::anonymized($this->server,$this->config,$this->owned[0],'synthetic-debt-clone');
        self::assertSame($fingerprint,$this->fingerprint($this->source));$cfg=$this->config->all();$cfg['db']['name']=$this->owned[1];
        $this->target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($cfg,$this->config->dataDir())));$this->target->pdo()->beginTransaction();$after=(new R($this->target))->current($this->supplier(),$lot,'2026-01-15');
        foreach(['book_czk','earned','unpaid_interest','principal_balance'] as $field)self::assertSame($before[$field],$after[$field]);
        self::assertNotSame($before['hash'],$after['hash']);self::assertSame($before['facts']['asset']['id'],$after['facts']['asset']['id']);
        $quotes=array_values(array_filter($after['events'],static fn($event)=>$event['request']['operation']==='valuation'));self::assertCount(1,$quotes);self::assertSame('0.00',$quotes[0]['receipt']['amount']);self::assertArrayNotHasKey('ledger',$quotes[0]['receipt']);
        self::assertSame($this->source->pdo()->query('SELECT * FROM accounting_securities_debt_claims ORDER BY supplier_id,line_id')->fetchAll(PDO::FETCH_ASSOC),$this->target->pdo()->query('SELECT * FROM accounting_securities_debt_claims ORDER BY supplier_id,line_id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame(0,(int)$this->target->pdo()->query('SELECT COUNT(*) FROM accounting_securities_debt_commands')->fetchColumn());
        foreach(['accounting_securities_debt_lots','accounting_securities_debt_events','bank_transactions'] as $table){$text=json_encode($this->target->pdo()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR);foreach(['private-contract@synthetic.invalid','private-position@synthetic.invalid','private-accrual@synthetic.invalid','private-reversal@synthetic.invalid','private-final-accrual@synthetic.invalid','private-unchanged-quote@synthetic.invalid','private-receipt@synthetic.invalid','private-issuer@synthetic.invalid'] as $private)self::assertStringNotContainsString($private,$text);}
    }
    public function testActualFullClonePreservesDurableForeignFxCarryAfterGenericFxPayloadIsRemoved(): void
    {
        $lot=$this->call('foreignFx',true);$before=(new R($this->source))->current($this->supplier(),$lot,'2026-01-01');self::assertSame('23760.25',$before['book_czk']);$this->source->pdo()->commit();$source=$this->fingerprint($this->source);
        $this->owned[1]=CloneSandbox::anonymized($this->server,$this->config,$this->owned[0],'synthetic-eur-debt-clone');self::assertSame($source,$this->fingerprint($this->source));$cfg=$this->config->all();$cfg['db']['name']=$this->owned[1];$this->target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($cfg,$this->config->dataDir())));$p=$this->target->pdo();$p->beginTransaction();$after=(new R($this->target))->current($this->supplier(),$lot,'2026-01-01');self::assertSame($before['book_czk'],$after['book_czk']);self::assertSame($before['principal_book_czk'],$after['principal_book_czk']);self::assertCount(1,$after['fx_carries']);self::assertNotSame($before['hash'],$after['hash']);
        $q=$p->prepare("SELECT payload FROM accounting_closing_steps WHERE supplier_id=? AND step_key='fx_revaluation'");$q->execute([$this->supplier()]);$payload=R::decode($q->fetchColumn());self::assertTrue($payload['fx504_anonymized']);self::assertArrayNotHasKey('fx504',$payload);foreach(['accounting_securities_debt_lots','accounting_securities_debt_fx_carries'] as $table)self::assertStringNotContainsString('private-eur-contract@synthetic.invalid',json_encode($p->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));
    }

    public function testUnboundCouponReceiptIsNotRevalidatedAgainstLaterEditedBankDocument(): void
    {
        $terms=$this->call('coupon');$lot=$this->call('register',$terms);
        $this->call('applyCommand',['operation'=>'accrual','lot_id'=>$lot,'accounting_date'=>'2025-12-31','evidence'=>'Synthetic accrual']);
        $bank=$this->call('bank','20.00','2026-01-15','253');
        $receipt=$this->call('applyCommand',['operation'=>'coupon_receipt','lot_id'=>$lot,'accounting_date'=>'2026-01-15','evidence'=>'Synthetic receipt']+$bank);
        $this->call('applyCommand',['operation'=>'reverse','lot_id'=>$lot,'accounting_date'=>'2026-01-15','event_id'=>$receipt['event_id'],'evidence'=>'Synthetic unbind private-unbind@synthetic.invalid']);
        $posting=(new \ReflectionProperty($this->fixture,'posting'))->getValue($this->fixture);
        $posting->reverse($this->supplier(),$bank['entry_id'],['entry_date'=>'2026-01-15']);
        $before=(new R($this->source))->current($this->supplier(),$lot,'2026-01-15');$this->source->pdo()->commit();$fingerprint=$this->fingerprint($this->source);
        $this->owned[1]=CloneSandbox::anonymized($this->server,$this->config,$this->owned[0],'synthetic-unbound-debt-clone');
        self::assertSame($fingerprint,$this->fingerprint($this->source));$cfg=$this->config->all();$cfg['db']['name']=$this->owned[1];
        $this->target=Connection::withoutSharedTestConnection(fn()=>new Connection(new Config($cfg,$this->config->dataDir())));$this->target->pdo()->beginTransaction();
        $after=(new R($this->target))->current($this->supplier(),$lot,'2026-01-15');
        foreach(['book_czk','earned','unpaid_interest','principal_balance'] as $field)self::assertSame($before[$field],$after[$field]);
        $q=$this->target->pdo()->prepare("SELECT receipt_json,receipt_hash FROM accounting_securities_debt_events WHERE supplier_id=? AND id=? AND operation='coupon_receipt'");$q->execute([$this->supplier(),$receipt['event_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
        $frozen=R::decode($row['receipt_json']);self::assertSame(R::hash($frozen),$row['receipt_hash']);
        self::assertSame($bank['entry_id'],$frozen['payment']['ledger']['entry']['id']);self::assertNull($frozen['payment']['ledger']['entry']['reversed_by']);
        self::assertStringNotContainsString('private-unbind@synthetic.invalid',json_encode($this->target->pdo()->query('SELECT * FROM accounting_securities_debt_events')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));
    }

    public function testRehashedFakeNativeReceiptIsRejectedBeforeCloneTransformation(): void
    {
        $this->prepare();$p=$this->source->pdo();$row=$p->query("SELECT id,receipt_json FROM accounting_securities_debt_events WHERE operation='accrual' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);$receipt=R::decode($row['receipt_json']);$receipt['amount']='18.00';$p->prepare('UPDATE accounting_securities_debt_events SET receipt_json=?,receipt_hash=? WHERE id=?')->execute([R::json($receipt),R::hash($receipt),$row['id']]);$before=$this->fingerprint($this->source);
        try{$this->owned[1]=CloneSandbox::anonymized($this->server,$this->config,$this->owned[0],'synthetic-debt-corrupt-clone');self::fail('Clone laundered rehashed native corruption.');}catch(\MyInvoice\Service\Accounting\Closing\ClosingException $e){self::assertSame('debt_event_corrupt',$e->errorCode);}
        self::assertSame($before,$this->fingerprint($this->source));
    }
    private function fingerprint(Connection $db): string
    {
        $out=[];foreach(['accounting_securities_debt_lots','accounting_securities_debt_events','accounting_securities_debt_claims','accounting_securities_debt_commands','accounting_securities_debt_fx_carries','journal_entries','journal_entry_lines','bank_transactions'] as $table)$out[$table]=$db->pdo()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);return R::hash($out);
    }
}
