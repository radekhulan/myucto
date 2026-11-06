<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\PaymentWorkflowReadRepository;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\TestCase;

final class PaymentDiscoveryTest extends TestCase
{
    use ChargeIssuanceFixture;
    private Connection $db;
    private array $f;
    protected function setUp(): void
    {
        $this->db=Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test',(string)getenv('MYINVOICE_DB_NAME'));
        self::assertSame(getenv('MYINVOICE_DB_NAME'),$this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();$this->f=$this->issuanceFixture($this->db);
    }
    protected function tearDown(): void {if($this->db->pdo()->inTransaction())$this->db->pdo()->rollBack();}
    public function testSourcePagesCanReachEveryRecordWithoutDuplicatesOrTenantLeak(): void
    {
        $pdo=$this->db->pdo();
        $pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic pagination',?,'1000000005','0100','CZK','2026-02-01')")->execute([$this->f['supplier'],hash('sha256',random_bytes(20))]);
        $statement=(int)$pdo->lastInsertId();$wanted=[];
        $insert=$pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_name) VALUES (?,'2026-02-01','1.01','CZK','Synthetic payer')");
        for($i=0;$i<130;$i++){$insert->execute([$statement]);$wanted[]=(int)$pdo->lastInsertId();}
        $read=new PaymentWorkflowReadRepository($this->db);$ids=[];$after=0;$queries=[];
        do {
            $before=(int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(\PDO::FETCH_NUM)[1];
            $page=$read->candidates($this->f['supplier'],'bank',$after,25,true);
            $queries[]=(int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(\PDO::FETCH_NUM)[1]-$before;
            self::assertLessThanOrEqual(25,count($page['items']));
            foreach($page['items'] as $row){self::assertSame('101',$row['available_cents']);$ids[]=$row['id'];}
            $after=$page['next_cursor'];
        } while($after!==null);
        self::assertSame($wanted,$ids);self::assertSame([1,1,1,1,1,1],$queries,'One SQL source page, independent of page size or total rows.');
        self::assertSame([],$read->candidates($this->f['supplier']+100000,'bank',0,25,true)['items']);
        self::assertSame([],$read->candidates($this->f['supplier'],'bank',0,25,false)['items'],'Read access does not authorize selecting unposted native owners.');
        $context=$read->context($this->f['supplier'],'2026-02-01T00:00:00+01:00',true,false);
        self::assertCount(100,$context['candidates']);self::assertTrue($context['candidate_coverage']['truncated']);
    }
}
