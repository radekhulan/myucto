<?php

declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Payments\{PaymentLocks,PaymentSourceProof,PaymentException};
use MyInvoice\Repository\Property\Payments\PaymentRepository;
use PDO;
use PHPUnit\Framework\TestCase;
final class PaymentWorkflowConcurrencyTest extends TestCase
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
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $this->ownedSchema = 'myucto_workflow_race_' . bin2hex(random_bytes(5)) . '_test';
        $this->server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $cloner = new DatabaseCloner($this->server);
        $plan = $cloner->plan($source);
        $cloner->copyTables($plan, $this->ownedSchema, static function (): void {});
        $cloner->finalize($plan, $this->ownedSchema);
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
        self::assertMatchesRegularExpression('/^myucto_workflow_race_[a-f0-9]{10}_test$/D', $this->ownedSchema);
        $this->server->exec('DROP DATABASE ' . DatabaseCloner::quote($this->ownedSchema));
    }



    public function testOldSnapshotDiscoversNativeOwnerAndExactFreeCapacityBeforeAnySecondWrite():void
    {
        [$a,$b]=$this->connections;$this->db=$b;$b->pdo()->beginTransaction();$this->f=$this->issuanceFixture($b);$f=$this->f;
        $pdo=$b->pdo();$pdo->prepare("INSERT INTO bank_statements (supplier_id,file_name,file_hash,account_number,bank_code,currency,statement_date) VALUES (?,'Synthetic race',?,'1000000005','0100','CZK','2026-02-01')")->execute([$f['supplier'],hash('sha256',random_bytes(20))]);$statement=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id,posted_at,amount,currency,counterparty_account,counterparty_bank) VALUES (?,'2026-02-01','100.00','CZK','1000000005','0100')")->execute([$statement]);$bank=(int)$pdo->lastInsertId();$b->pdo()->commit();
        $request=['source'=>['kind'=>'bank','id'=>$bank],'clearing_cells'=>[['key'=>'unresolved','amount_cents'=>'10000','dimensions'=>[]]],'parts'=>[['key'=>'documented-credit','request'=>['kind'=>'allocation','amount_cents'=>'6000','accounting_date'=>'2026-02-01','reason'=>'Synthetic evidenced entitlement','debts'=>[], 'credit_entitlement'=>['third_party' => ['basis' => 'other_documented', 'evidence' => 'Synthetic documented creditor title'], 'unit_id'=>$f['units']['G12'],'party_ids'=>[$f['clients'][1]],'evidence'=>'Synthetic entitlement deed','due_date'=>'2026-03-01']]]]];
        $a->pdo()->beginTransaction();$a->pdo()->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
        $writer=$this->facade($b);$first=$writer->confirm($f['supplier'],$request,$writer->preview($f['supplier'],$request)['preview_hash'],'race-first',$f['actor']);
        $request['parts'][0]['request']['amount_cents']='1000';$next=$this->facade($a)->preview($f['supplier'],$request);
        self::assertNull($next['snapshot']['native']);self::assertSame($first['journal_entry_id'],$next['snapshot']['source']['existing']['receipt']['ledger']['entry']['id']);self::assertSame('3000',$next['snapshot']['available_cents']);
        $a->pdo()->rollBack();
        $a->pdo()->beginTransaction();(new PaymentLocks($a))->acquire($f['supplier']);$b->pdo()->exec('SET SESSION innodb_lock_wait_timeout=1');
        try{$this->facade($b)->preview($f['supplier'],$request);self::fail('Workflow bypassed current period ownership.');}catch(\PDOException $e){self::assertSame(1205,(int)$e->errorInfo[1]);}
        $a->pdo()->rollBack();self::assertFalse($b->pdo()->inTransaction());
        $writer=$this->facade($b);$done=$writer->confirm($f['supplier'],$request,$writer->preview($f['supplier'],$request)['preview_hash'],'race-second',$f['actor']);self::assertSame($first['journal_entry_id'],$done['journal_entry_id']);self::assertSame('3000',$done['available_cents']);
        $q=$b->pdo()->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id=? AND source_type='bank' AND source_id=?");$q->execute([$f['supplier'],$bank]);self::assertSame(1,(int)$q->fetchColumn());
    }
    private function facade(Connection $db): \MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowFacade
    {
        $container=Bootstrap::buildContainer();$container->set(Connection::class,$db);return $container->get(\MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowFacade::class);
    }
}
