<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\PropertyRegistryRepository;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PropertyAccountingConcurrencyTest extends TestCase
{
    use IsolatedSupplierTrait;
    private string $ownedSchema;
    private PDO $server;
    private array $connections = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $config = $container->get(Config::class);
        $source = (string) $container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $source);
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $this->ownedSchema = 'myucto_property_context_' . bin2hex(random_bytes(5)) . '_test';
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
        self::assertMatchesRegularExpression('/^myucto_property_context_[a-f0-9]{10}_test$/D', $this->ownedSchema);
        $this->server->exec('DROP DATABASE ' . DatabaseCloner::quote($this->ownedSchema));
    }

    public function testEmptyPeriodCurrentProfileAndNestedBankOwnership(): void
    {
        [$a,$b]=$this->connections;
        $pdo=$a->pdo();$pdo->beginTransaction();
        $supplier=$this->createIsolatedSupplier($pdo,(int)$pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        $actor=(int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks(supplier_id,effective_from,accounting_framework) VALUES (?,'2096-01-01','cz_504')")->execute([$supplier]);
        $profile=['expected_revision'=>0,'effective_from'=>'2096-01-01T00:00:00+01:00','enabled'=>true,'reason'=>'Synthetic','title_ref'=>'SYN','idempotency_key'=>'first'];
        (new PropertyProfileRepository($a))->save($supplier,$profile,$actor);
        $object=(new PropertyRegistryRepository($a))->create($supplier,'service',['code'=>'WATER','expected_revision'=>0,'status'=>'confirmed','effective_from'=>'2096-01-01T00:00:00+01:00',
            'state'=>['name'=>'Synthetic water','active'=>true,'measure'=>'unit','measure_label'=>''],'reason'=>'Synthetic','title_ref'=>'SYN','idempotency_key'=>'service'],$actor);
        $value=(new PropertyReadRepository($a))->find($supplier,$object['id'],'2096-06-01T00:00:00+02:00')['dimension_value_id'];
        $q=$pdo->prepare('SELECT type_id FROM dimension_values WHERE id=?');$q->execute([$value]);$type=(int)$q->fetchColumn();
        $pdo->prepare("INSERT INTO assets(supplier_id,inventory_number,name,input_price,acquisition_date,put_into_use_date) VALUES (?,'SYN','Synthetic',49.99,'2096-08-01','2096-08-01')")->execute([$supplier]);$asset=(int)$pdo->lastInsertId();
        $pdo->commit();
        $pdo->beginTransaction();
        self::assertTrue((new PropertyProfileRepository($a))->at($supplier,'2096-08-01T00:00:00+02:00')['enabled']);
        $q=$pdo->prepare('SELECT COUNT(*) FROM accounting_periods WHERE supplier_id=?');$q->execute([$supplier]);self::assertSame(0,(int)$q->fetchColumn());
        $profile['expected_revision']=1;$profile['effective_from']='2096-07-01T00:00:00+02:00';$profile['enabled']=false;$profile['idempotency_key']='disabled';
        (new PropertyProfileRepository($b))->save($supplier,$profile,$actor);
        $context=(new \MyInvoice\Service\Accounting\Dimension\DocumentDimensionContext($a))->lockCurrent($supplier,'asset',$asset);
        try {
            (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($a))->assertDocumentAssignments($supplier,[['dimensions'=>[$type=>$value]]],$context['date']);
            self::fail('Empty-period caller snapshot must not retain a disabled profile.');
        } catch (\MyInvoice\Service\Accounting\PostingException $e) {self::assertSame('property_profile_required',$e->errorCode);}
        $b->pdo()->exec('SET SESSION innodb_lock_wait_timeout=1');
        $b->pdo()->beginTransaction();
        try {$b->pdo()->prepare('SELECT id FROM supplier WHERE id=? FOR UPDATE NOWAIT')->execute([$supplier]);self::fail('Empty period context must still serialize the supplier.');}
        catch (\PDOException $e) {self::assertSame(1205,(int)$e->errorInfo[1]);}
        $b->pdo()->rollBack();$pdo->rollBack();

        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default,account_number,bank_code) VALUES (?,'CZK','Synthetic','Kč','Synthetic','Synthetic',2,1,1,'19-1000000005','0100')")->execute([$supplier]);$currency=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_statements(file_name,file_hash,account_number,bank_code,currency,statement_date,transaction_count,matched_count) VALUES ('synthetic.gpc',?,'19-1000000005','0100','CZK','2096-06-01',1,0)")->execute([hash('sha256',(string)$supplier)]);$statement=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions(statement_id,posted_at,amount,currency,description) VALUES (?,'2096-06-01',49.99,'CZK','Synthetic')")->execute([$statement]);$transaction=(int)$pdo->lastInsertId();$pdo->commit();
        $pdo->beginTransaction();
        self::assertTrue((new \MyInvoice\Repository\BankStatementOwnershipResolver($a))->transactionOwned($transaction,$supplier));
        $b->pdo()->prepare('UPDATE currencies SET account_number=NULL WHERE id=?')->execute([$currency]);
        self::assertTrue((new \MyInvoice\Repository\BankStatementOwnershipResolver($a))->transactionOwned($transaction,$supplier),'Readonly default deliberately keeps its caller snapshot.');
        try {(new \MyInvoice\Service\Accounting\Dimension\DocumentDimensionContext($a))->lockCurrent($supplier,'bank_transaction',$transaction);self::fail('Nested ownership read must see the removed account.');}
        catch (\MyInvoice\Service\Accounting\Dimension\DimensionException $e) {self::assertSame('not_found',$e->errorCode);}
        $pdo->rollBack();
        $b->pdo()->prepare("UPDATE currencies SET account_number='19-1000000005' WHERE id=?")->execute([$currency]);
        $pdo->beginTransaction();
        self::assertTrue((new \MyInvoice\Repository\BankStatementOwnershipResolver($a))->transactionOwned($transaction,$supplier));
        $b->pdo()->beginTransaction();
        $other=$this->createIsolatedSupplier($b->pdo(),$supplier);
        $b->pdo()->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,account_number,bank_code) VALUES (?,'CZK','Synthetic','Kč','Synthetic','Synthetic','19-1000000005','0100')")->execute([$other]);
        $b->pdo()->commit();
        try {(new \MyInvoice\Service\Accounting\Dimension\DocumentDimensionContext($a))->lockCurrent($supplier,'bank_transaction',$transaction);self::fail('Nested COUNT must see a newly ambiguous owner.');}
        catch (\MyInvoice\Service\Accounting\Dimension\DimensionException $e) {self::assertSame('not_found',$e->errorCode);}
        $pdo->rollBack();

        $b->pdo()->beginTransaction();
        $period=(new \MyInvoice\Repository\AccountingPeriodRepository($b))->create($supplier,2096,'2096-01-01','2096-12-31');
        (new \MyInvoice\Service\Accounting\ChartOfAccountsSeeder($b))->seedForSupplier($supplier,'2096-01-01');
        $q=$b->pdo()->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code='221'");$q->execute([$supplier]);$account=(int)$q->fetchColumn();
        $profile['expected_revision']=2;$profile['enabled']=true;$profile['effective_from']='2096-09-01T00:00:00+02:00';$profile['idempotency_key']='enabled';(new PropertyProfileRepository($b))->save($supplier,$profile,$actor);
        $b->pdo()->commit();
        $pdo->beginTransaction();
        self::assertTrue((new PropertyProfileRepository($a))->at($supplier,'2096-11-01T00:00:00+01:00')['enabled']);
        $profile['expected_revision']=3;$profile['enabled']=false;$profile['effective_from']='2096-10-01T00:00:00+02:00';$profile['idempotency_key']='disabled-again';(new PropertyProfileRepository($b))->save($supplier,$profile,$actor);
        $journal=new \MyInvoice\Repository\JournalEntryRepository($a,new \MyInvoice\Service\ActivityLogger($a));
        try {$journal->insert(['supplier_id'=>$supplier,'period_id'=>$period,'entry_date'=>'2096-11-01','source_type'=>'manual'],[['account_id'=>$account,'side'=>'debit','amount'=>'49.99','dimensions'=>[$type=>$value]]]);self::fail('Actual Journal insert must see current disabled profile.');}
        catch (\MyInvoice\Service\Accounting\PostingException $e) {self::assertSame('property_profile_required',$e->errorCode);}
        $q=$pdo->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id=?');$q->execute([$supplier]);self::assertSame(0,(int)$q->fetchColumn());
        $pdo->rollBack();
    }
}
