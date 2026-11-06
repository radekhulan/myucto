<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\Issuance\{IssuanceException,IssuancePreviewService};
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
final class IssuanceBatchLedgerProofTest extends TestCase
{
    use ChargeIssuanceFixture;
    private Connection $db;
    private array $f;
    private array $batch;
    private IssuanceRepository $owner;
    protected function setUp():void
    {
        $container=Bootstrap::buildContainer();$this->db=Connection::withoutSharedTestConnection(static fn()=>$container->get(Connection::class));self::assertStringEndsWith('_test',$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());$this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);$this->owner=$container->get(IssuanceRepository::class);$preview=(new IssuancePreviewService($this->db))->preview($this->f['supplier'],$this->f['issue_request']);$this->batch=$this->owner->issue($this->f['supplier'],['request'=>$this->f['issue_request'],'preview_hash'=>$preview['preview_hash'],'idempotency_key'=>'batch-ledger-proof'],$this->f['actor']);
    }
    protected function tearDown():void
    {
        if(isset($this->db)){$this->db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PDOStatement::class]);if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();$this->db->close();}
    }
    private function post():array{return $this->owner->post($this->f['supplier'],$this->batch['id'],array_map(static fn($charge)=>['id'=>$charge['id'],'row_version'=>$charge['row_version']],$this->batch['charges']),$this->f['actor']);}
    public function testLateNativeWriteCannotInvalidateAnEarlierLedgerAfterItsIndividualCheck():void
    {
        $p=$this->db->pdo();$first=$this->batch['charges'][0]['id'];$last=$this->batch['charges'][array_key_last($this->batch['charges'])]['id'];$fired=false;$supplier=$this->f['supplier'];
        $hook=function(string $sql,?array $params)use($p,$first,$last,$supplier,&$fired):void{
            if($fired||!str_contains($sql,'INSERT INTO unit_charge_issue_audit')||($params[2]??null)!==$last||($params[3]??null)!=='posted')return;
            $fired=true;$q=$p->prepare("UPDATE journal_entries SET entry_date='2026-01-02' WHERE supplier_id=? AND source_type='unit_charge' AND source_id=?");$q->execute([$supplier,$first]);self::assertSame(1,$q->rowCount());
        };
        $p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[IssuanceLateWriteStatement::class,[$hook]]);
        try{$this->post();self::fail('The complete final native ledger graph must be proved before committing the batch');}catch(IssuanceException $e){self::assertSame('property_charge_issue_ledger_mismatch',$e->errorCode);}finally{$p->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PDOStatement::class]);}
        self::assertTrue($fired);$q=$p->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type='unit_charge'");$q->execute([$supplier]);self::assertSame(0,(int)$q->fetchColumn());$q=$p->prepare('SELECT DISTINCT status FROM unit_charges WHERE supplier_id=? AND batch_id=?');$q->execute([$supplier,$this->batch['id']]);self::assertSame(['draft'],$q->fetchAll(PDO::FETCH_COLUMN));
    }
    public function testNativeReceiptRetryIsIdenticalAndRevalidatesEveryPhysicalEntry():void
    {
        $posted=$this->post();self::assertSame($posted,$this->post());self::assertCount(12,$posted['charges']);$p=$this->db->pdo();$q=$p->prepare("UPDATE journal_entries SET entry_date='2026-01-02' WHERE supplier_id=? AND id=?");$q->execute([$this->f['supplier'],$posted['charges'][0]['journal_entry_id']]);
        try{$this->post();self::fail('Idempotent receipt must not authorize a changed physical ledger');}catch(IssuanceException $e){self::assertSame('property_charge_issue_ledger_mismatch',$e->errorCode);}
    }
}
final class IssuanceLateWriteStatement extends PDOStatement
{
    protected function __construct(private readonly \Closure $hook){}
    public function execute(?array $params=null):bool{$result=parent::execute($params);($this->hook)($this->queryString,$params);return $result;}
}
