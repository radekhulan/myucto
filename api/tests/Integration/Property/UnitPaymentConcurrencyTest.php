<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Tests\Support\CloneSandbox;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Payments\{PaymentLocks,PaymentSourceProof,PaymentException};
use MyInvoice\Repository\Property\Payments\PaymentRepository;
use PDO;
use PHPUnit\Framework\TestCase;
final class UnitPaymentConcurrencyTest extends TestCase
{
    use \MyInvoice\Tests\Support\ChargeIssuanceFixture;
    use \MyInvoice\Tests\Support\PaymentCorrectionFixture;
    private Connection $db;
    private array $f;
    private string $ownedSchema;
    private PDO $server;
    private array $connections=[];
    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $config = $container->get(Config::class);
        $source = (string) $container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $source);
        self::assertTrue(\MyInvoice\Tests\Support\TestDatabaseHost::isAllowed((string) ($config->get('db.host'))), 'Testovací DB musí být lokální nebo v db.test_allowed_hosts.');
        $this->server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->ownedSchema = CloneSandbox::acquire($this->server, $source, true);
        $data = $config->all();
        $data['db']['name'] = $this->ownedSchema;
        for ($i = 0; $i < 2; ++$i) {
            $db = Connection::withoutSharedTestConnection(fn () => new Connection(new Config($data, $config->dataDir())));
            $db->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            $db->pdo()->exec('SET SESSION innodb_snapshot_isolation = 0');
            $db->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connections[] = $db;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
            $db->close();
        }
        self::assertMatchesRegularExpression('/^myucto_clonesandbox_[a-f0-9]{10}_test$/D', $this->ownedSchema);
        self::assertTrue(CloneSandbox::isLeased($this->ownedSchema));
        CloneSandbox::dropOrRelease($this->server, $this->ownedSchema, [$this->ownedSchema]);
    }


    public function testOldSnapshotSeesConsumedSourceAndReservedCreditAndPeriodLockSerializes():void
    {
        [$a,$b]=$this->connections;$a->pdo()->beginTransaction();$f=$this->issuanceFixture($a);$bank=$this->bank($a,$f);$a->pdo()->commit();
        $a->pdo()->beginTransaction();$a->pdo()->query('SELECT COUNT(*) FROM unit_payment_sources')->fetchColumn();
        $request=['kind'=>'allocation','source'=>['kind'=>'bank','id'=>$bank,'expected_version'=>0],'amount_cents'=>'6000','accounting_date'=>'2026-02-01','reason'=>'Synthetic independently entitled credit','debts'=>[],
            'credit_entitlement'=>['third_party' => ['basis' => 'other_documented', 'evidence' => 'Synthetic documented creditor title'], 'unit_id'=>array_values($f['units'])[0],'party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic creditor title','due_date'=>'2026-03-01']];
        $writerB=$this->writer($b);$operation=$writerB->apply($f['supplier'],$request,$writerB->preview($f['supplier'],$request)['preview_hash'],'winning',$f['actor']);
        try{$this->writer($a)->preview($f['supplier'],$request);self::fail('Old snapshot hid current source usage.');}catch(PaymentException $e){self::assertSame('property_payment_source_version_conflict',$e->errorCode);}
        $a->pdo()->rollBack();
        $a->pdo()->beginTransaction();$a->pdo()->query('SELECT COUNT(*) FROM unit_credit_reservations')->fetchColumn();
        $reserve=['amount_cents'=>'5000','party_ids'=>[$f['clients'][1]],'disposition'=>'Synthetic disposition','recipient_account'=>'1000000005/0100'];
        \MyInvoice\Tests\Support\RefundAccountFixture::document($b->pdo(),$f['supplier'],$f['clients'][1]);
        $writerB->reserve($f['supplier'],$operation['credit_id'],1,$reserve,'reserved',$f['actor']);
        try{$this->writer($a)->reserve($f['supplier'],$operation['credit_id'],1,$reserve,'stale',$f['actor']);self::fail('Old snapshot hid reservation.');}catch(PaymentException $e){self::assertSame('property_payment_version_conflict',$e->errorCode);}
        $a->pdo()->rollBack();
        self::assertSame('1000',$writerB->credit($f['supplier'],$operation['credit_id'])['available_cents']);
        $a->pdo()->beginTransaction();(new PaymentLocks($a))->acquire($f['supplier']);
        $b->pdo()->exec('SET SESSION innodb_lock_wait_timeout=1');
        try{$writerB->preview($f['supplier'],$request);self::fail('Payment bypassed period ownership.');}catch(\PDOException $e){self::assertSame(1205,(int)$e->errorInfo[1]);}
        $a->pdo()->rollBack();self::assertFalse($b->pdo()->inTransaction());
    }
    private function writer(Connection $db):PaymentRepository
    {
        $container=Bootstrap::buildContainer();$container->set(Connection::class,$db);return new PaymentRepository($db,$container->get(PostingService::class));
    }
    public function testOldSnapshotCannotHideReliefOrReverseItsOriginalAllocation():void
    {
        [$a,$b]=$this->connections;$this->db=$a;$a->pdo()->beginTransaction();$this->f=$this->issuanceFixture($a);$f=$this->f;$base=$this->settledPaymentFixture('90000','0');$request=$this->negativePaymentCorrection($base,'800','10000');$a->pdo()->commit();
        $a->pdo()->beginTransaction();$a->pdo()->query('SELECT COUNT(*) FROM unit_payment_corrections')->fetchColumn();$this->db=$b;$writer=$this->correctionWriter();$writer->apply($f['supplier'],$request,$writer->preview($f['supplier'],$request)['preview_hash'],'correction',$f['actor']);
        $this->db=$a;(new PaymentLocks($a))->acquire($f['supplier']);$debt=(new \MyInvoice\Repository\Property\Payments\DebtRepository($a))->current($f['supplier'],$base['debt']['id']);self::assertSame('80000',$debt['paid']['principal:'.$base['line_id']]);
        try{$this->correctionPayments()->reverse($f['supplier'],$base['operation']['id'],2,'2026-02-03','Synthetic stale reversal','reverse',$f['actor']);self::fail('Old snapshot hid relief.');}catch(PaymentException $e){self::assertSame('property_payment_allocation_has_relief',$e->errorCode);}
        $a->pdo()->rollBack();
    }
    private function bank(Connection $db,array $f):int
    {
        $pdo=$db->pdo();$pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic',?,'1000000005','0100','CZK','2026-02-01')")->execute([$f['supplier'],hash('sha256',random_bytes(20))]);$statement=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency) VALUES (?,'2026-02-01','100.00','CZK')")->execute([$statement]);$id=(int)$pdo->lastInsertId();
        $container=Bootstrap::buildContainer();$container->set(Connection::class,$db);$container->get(PostingService::class)->postDocument($f['supplier'],'bank',$id,[['account_code'=>'221','side'=>'debit','amount'=>'100.00'],['account_code'=>'379.900','side'=>'credit','amount'=>'100.00']],['entry_date'=>'2026-02-01','posted_by'=>$f['actor']]);return $id;
    }
}
