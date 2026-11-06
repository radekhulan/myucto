<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyProfileRepository;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Repository\Property\PropertyRegistryRepository;
use MyInvoice\Tests\Support\CloneSandbox;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PropertyResetCleanupTest extends TestCase
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
        $this->server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            $config->get('db.user'), $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->ownedSchema = CloneSandbox::acquire($this->server, $source, true);
        $data = $config->all();
        $data['db']['name'] = $this->ownedSchema;
        for ($i = 0; $i < 1; ++$i) {
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



    public function testNativeKeepResetDeletesModuleCatalogAndOnlyRelatedKeys(): void
    {
        [$supplier, $actor, $moduleType, $moduleValue, $customType, $customValue] = $this->fixture();
        $pdo = $this->connections[0]->pdo();
        $related = $this->key($pdo, $supplier, $actor, 'property', [$moduleType => $moduleValue]);
        $unrelated = $this->key($pdo, $supplier, $actor, 'ordinary', [$customType => $customValue]);
        $before = $pdo->query('SELECT * FROM joint_allocation_key_versions WHERE key_id = ' . $unrelated)->fetchAll(PDO::FETCH_ASSOC);
        $customBefore = $pdo->query('SELECT * FROM dimension_values WHERE id = ' . $customValue)->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("INSERT INTO dimension_account_rules (supplier_id,dimension_type_id,account_mask,default_value_id) VALUES (?,?,'5',?)")->execute([$supplier,$moduleType,$moduleValue]);
        $pdo->prepare("INSERT INTO payroll_dimensions (supplier_id,dimension_type,code,name,valid_from,dimension_value_id) VALUES (?,'cost_center','SYNTHETIC','Synthetic payroll','2026-01-01',?)")->execute([$supplier,$moduleValue]);
        $payroll = (int) $pdo->lastInsertId();
        [$exit, $output] = $this->reset();
        self::assertSame(0, $exit, $output);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM dimension_types WHERE system_key IN ('unit','service')")->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM joint_allocation_keys WHERE id = ' . $related)->fetchColumn());
        self::assertSame($before, $pdo->query('SELECT * FROM joint_allocation_key_versions WHERE key_id = ' . $unrelated)->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($customBefore, $pdo->query('SELECT * FROM dimension_values WHERE id = ' . $customValue)->fetch(PDO::FETCH_ASSOC));
        self::assertNull($pdo->query('SELECT dimension_value_id FROM payroll_dimensions WHERE id = ' . $payroll)->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM dimension_account_rules WHERE dimension_type_id = ' . $moduleType)->fetchColumn());
        foreach (\MyInvoice\Service\Property\PropertyResetCleanup::TABLES as $table) self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), $table);
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM supplier WHERE id = ' . $supplier)->fetchColumn());
    }

    public function testNativeResetRefusesPreservedChildBeforeFirstTruncate(): void
    {
        [, , , $moduleValue, , $customValue] = $this->fixture();
        $pdo = $this->connections[0]->pdo();
        $pdo->prepare('UPDATE dimension_values SET parent_id = ? WHERE id = ?')->execute([$moduleValue,$customValue]);
        $before = $this->fingerprint($pdo);
        [$exit, $output] = $this->reset();
        self::assertSame(1, $exit, $output);
        self::assertSame($before, $this->fingerprint($pdo), 'Preflight refuses before any business history is truncated.');
    }

    public function testDryRunDoesNotChangeRegistryOrCatalog(): void
    {
        $this->fixture();
        $pdo = $this->connections[0]->pdo();
        $before = $this->fingerprint($pdo);
        [$exit, $output] = $this->reset(true);
        self::assertSame(0, $exit, $output);
        self::assertSame($before, $this->fingerprint($pdo));
    }

    private function fixture(): array
    {
        $db = $this->connections[0]; $pdo = $db->pdo();
        $supplier = $this->createIsolatedSupplier($pdo,(int)$pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        $actor = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id,effective_from,accounting_framework) VALUES (?,'1900-01-01','cz_504')")->execute([$supplier]);
        (new PropertyProfileRepository($db))->save($supplier,['expected_revision'=>0,'effective_from'=>'2026-01-01T00:00:00+01:00','enabled'=>true,'reason'=>'Synthetic declaration','title_ref'=>'Synthetic title','idempotency_key'=>'profile'],$actor);
        (new PropertyRegistryRepository($db))->create($supplier,'service',['code'=>'WATER','expected_revision'=>0,'effective_from'=>'2026-01-01T00:00:00+01:00','status'=>'confirmed','reason'=>'Synthetic service','title_ref'=>'Synthetic title','idempotency_key'=>'water','state'=>['name'=>'Synthetic water','active'=>true,'measure'=>'m3','measure_label'=>'']],$actor);
        $stmt=$pdo->prepare("SELECT t.id AS type_id,v.id AS value_id FROM dimension_types t JOIN dimension_values v ON v.type_id=t.id WHERE t.supplier_id=? AND t.system_key='service'"); $stmt->execute([$supplier]);$module=$stmt->fetch(PDO::FETCH_ASSOC);
        $pdo->prepare("INSERT INTO dimension_types (supplier_id,code,name,kind) VALUES (?,'unit','Synthetic ordinary unit','custom')")->execute([$supplier]);$customType=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO dimension_values (supplier_id,type_id,code,name) VALUES (?,?,'ORDINARY','Synthetic ordinary value')")->execute([$supplier,$customType]);$customValue=(int)$pdo->lastInsertId();
        return [$supplier,$actor,(int)$module['type_id'],(int)$module['value_id'],$customType,$customValue];
    }

    private function key(PDO $pdo, int $supplier, int $actor, string $code, array $dimensions): int
    {
        $pdo->prepare('INSERT INTO joint_allocation_keys (supplier_id,code,name,created_by) VALUES (?,?,?,?)')->execute([$supplier,$code,'Synthetic key',$actor]);$id=(int)$pdo->lastInsertId();
        $version=\MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion::normalize(['valid_from'=>'2026-01-01','valid_to'=>'2027-01-01','basis'=>'fixed','explanation'=>'Synthetic documented allocation','cells'=>[['activity'=>1,'activity_kind'=>2,'tax_regime'=>3,'weight'=>'1','additional_dimensions'=>$dimensions]]]);
        $pdo->prepare('INSERT INTO joint_allocation_key_versions (supplier_id,key_id,version_no,valid_from,valid_to,basis,explanation,rounding_policy,cells_json,content_hash,created_by) VALUES (?,?,1,?,?,?,?,?,?,?,?)')->execute([$supplier,$id,$version['valid_from'],$version['valid_to'],$version['basis'],$version['explanation'],$version['rounding_policy'],\MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion::encodeCells($version),$version['content_hash'],$actor]);
        return $id;
    }

    private function fingerprint(PDO $pdo): string
    {
        $tables=['supplier','dimension_types','dimension_values','joint_allocation_keys','joint_allocation_key_versions','property_module_profiles','property_objects','property_revisions'];
        $rows=[];foreach($tables as $table)$rows[$table]=$pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
    }

    private function reset(bool $dry = false): array
    {
        self::assertMatchesRegularExpression('/^myucto_clonesandbox_[a-f0-9]{10}_test$/D',$this->ownedSchema);
        self::assertTrue(CloneSandbox::isLeased($this->ownedSchema));
        $dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.$this->ownedSchema.'_'.bin2hex(random_bytes(4));mkdir($dir);
        $env=getenv();$env['MYINVOICE_DB_NAME']=$this->ownedSchema;$env['MYINVOICE_DATA_DIR']=$dir;
        $command=[PHP_BINARY,Bootstrap::rootDir().'/api/bin/reset.php','--yes','--keep-users-supplier','--skip-files'];if($dry)$command[]='--dry-run';
        $process=proc_open($command,[0=>['pipe','r'],1=>['file',$dir.'/output.log','w'],2=>['file',$dir.'/error.log','w']],$pipes,Bootstrap::rootDir(),$env);
        self::assertIsResource($process);fclose($pipes[0]);$exit=proc_close($process);$output=file_get_contents($dir.'/output.log').file_get_contents($dir.'/error.log');
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($dir);
        return [$exit,$output];
    }
}
