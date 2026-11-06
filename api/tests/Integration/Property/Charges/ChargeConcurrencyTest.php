<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property\Charges;

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
final class ChargeConcurrencyTest extends TestCase
{
    use \MyInvoice\Tests\Support\PropertyChargeFixture;
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
        $this->ownedSchema = 'myucto_charge_race_' . bin2hex(random_bytes(5)) . '_test';
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
        self::assertMatchesRegularExpression('/^myucto_charge_race_[a-f0-9]{10}_test$/D', $this->ownedSchema);
        $this->server->exec('DROP DATABASE ' . DatabaseCloner::quote($this->ownedSchema));
    }

    public function testOldSnapshotCannotSaveOldRegistryOrOverwriteVersion(): void
    {
        [$a,$b]=$this->connections; $a->pdo()->beginTransaction(); $f=$this->chargeFixture($a); $a->pdo()->commit();
        $request=self::chargeRequest($f);
        $a->pdo()->beginTransaction();
        $old=(new \MyInvoice\Service\Property\Charges\ChargePreviewService($a))->preview($f['supplier'],$request);
        $change=self::registryRequest('unused',$f['states']['U01']);unset($change['code']);$change['expected_revision']=1;$change['idempotency_key']='concurrent-basis';
        $change['state']['attributes']['floor_area']=['num'=>'120','den'=>'1'];
        (new PropertyRegistryRepository($b))->revise($f['supplier'],$f['units']['U01'],'unit',$change,$f['actor']);
        $this->refused('property_charge_preview_stale',fn()=>(new \MyInvoice\Repository\Property\Charges\ChargeRepository($a))->saveVersion($f['supplier'],['request'=>$request,'preview_hash'=>$old['preview_hash'],'idempotency_key'=>'stale'],$f['actor']));
        self::assertSame([],(new \MyInvoice\Repository\Property\Charges\ChargeReadRepository($a))->versions($f['supplier'],$f['house']['id'],true));
        $a->pdo()->rollBack();
        $a->pdo()->beginTransaction();
        $old=(new \MyInvoice\Service\Property\Charges\ChargePreviewService($a))->preview($f['supplier'],$request);
        $current=(new \MyInvoice\Service\Property\Charges\ChargePreviewService($b))->preview($f['supplier'],$request);
        $saved=(new \MyInvoice\Repository\Property\Charges\ChargeRepository($b))->saveVersion($f['supplier'],['request'=>$request,'preview_hash'=>$current['preview_hash'],'idempotency_key'=>'winning'],$f['actor']);
        $this->refused('property_charge_revision_conflict',fn()=>(new \MyInvoice\Repository\Property\Charges\ChargeRepository($a))->saveVersion($f['supplier'],['request'=>$request,'preview_hash'=>$old['preview_hash'],'idempotency_key'=>'losing'],$f['actor']));
        $a->pdo()->rollBack();
        self::assertSame($saved,(new \MyInvoice\Repository\Property\Charges\ChargeReadRepository($b))->at($f['supplier'],$f['house']['id'],$request['effective_from']));
    }
    private function refused(string $code,callable $work): void
    {
        try { $work(); self::fail('Expected '.$code); }
        catch(\MyInvoice\Service\Property\Charges\ChargeException $e) { self::assertSame($code,$e->errorCode); }
    }
}
