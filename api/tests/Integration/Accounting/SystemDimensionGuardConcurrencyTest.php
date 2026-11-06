<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class SystemDimensionGuardConcurrencyTest extends TestCase
{
    private Connection $dbA;
    private Connection $dbB;
    private DimensionRepository $dimensions;
    private array $suppliers = [];
    private array $periods = [];
    private array $types = [];
    private array $values = [];

    protected function setUp(): void
    {
        $a = Bootstrap::buildContainer();
        $b = Connection::withoutSharedTestConnection(static function () {
            $container = Bootstrap::buildContainer();
            $container->get(Connection::class);
            return $container;
        });
        $this->dbA = $a->get(Connection::class);
        $this->dbB = $b->get(Connection::class);
        $this->dimensions = $b->get(DimensionRepository::class);
        $pdo = $this->dbA->pdo();
        self::assertNotSame($pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), $this->dbB->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn());
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $currency = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, min($country, $currency, $vat));
        foreach (['unrelated', 'owner'] as $key) {
            $pdo->prepare('INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(['Synthetic dimension lock ' . $key, 'Test 1', 'Test', '11000', $country, uniqid('dimension-lock-') . '@example.com', $currency, $vat, 'double_entry']);
            $this->suppliers[$key] = (int) $pdo->lastInsertId();
            $this->periods[$key] = $a->get(AccountingPeriodRepository::class)->create($this->suppliers[$key], 2095, '2095-01-01', '2095-12-31');
        }
        foreach (['ordinary' => null, 'system' => 'activity_kind'] as $key => $system) {
            $pdo->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind, system_key) VALUES (?, ?, ?, 'custom', ?)")
                ->execute([$this->suppliers['owner'], $key, 'Synthetic ' . $key, $system]);
            $this->types[$key] = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO dimension_values (type_id, supplier_id, code, name) VALUES (?, ?, ?, ?)')
                ->execute([$this->types[$key], $this->suppliers['owner'], 'VALUE', 'Synthetic value']);
            $this->values[$key] = (int) $pdo->lastInsertId();
        }
        $this->dbB->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $this->dbB->pdo()->exec('SET SESSION innodb_snapshot_isolation = 0');
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbB, $this->dbA] as $db) {
            if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
        }
        foreach ($this->suppliers as $supplier) {
            $this->dbA->pdo()->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$supplier]);
            $this->dbA->pdo()->prepare('DELETE FROM supplier WHERE id = ?')->execute([$supplier]);
        }
        $this->dbB->close();
    }

    public static function independentWrites(): array
    {
        return [['ordinary', 'type_name'], ['ordinary', 'value_name'], ['ordinary', 'note'], ['ordinary', 'create'], ['system', 'note'], ['system', 'create'], ['system', 'type_name'], ['system', 'value_name']];
    }

    #[DataProvider('independentWrites')]
    public function testUnrelatedPeriodDoesNotBlockMetadataOrCreate(string $kind, string $operation): void
    {
        $this->lockUnrelatedPeriod();
        $supplier = $this->suppliers['owner'];
        if ($operation === 'create') {
            $type = $this->dimensions->findType($supplier, $this->types[$kind]);
            $id = $this->dimensions->createValue($type, ['code' => 'NEW', 'name' => 'Synthetic new']);
            self::assertSame('NEW', $this->dimensions->findValue($supplier, $id)['code']);
        } elseif ($operation === 'type_name') {
            $this->dimensions->updateType($supplier, $this->types[$kind], ['name' => 'Synthetic renamed']);
            self::assertSame('Synthetic renamed', $this->dimensions->findType($supplier, $this->types[$kind])['name']);
        } else {
            $field = $operation === 'note' ? 'note' : 'name';
            $this->dimensions->updateValue($supplier, $this->values[$kind], [$field => 'Synthetic changed']);
            self::assertSame('Synthetic changed', $this->dimensions->findValue($supplier, $this->values[$kind])[$field]);
        }
    }

    public static function structuralWrites(): array
    {
        return [['ordinary', 'parent'], ['system', 'parent'], ['system', 'cost_center'], ['ordinary', 'delete'], ['system', 'delete']];
    }

    #[DataProvider('structuralWrites')]
    public function testStructuralHistoryPreflightPrecedesAnyPrivateRowLock(string $kind, string $operation): void
    {
        $this->lockUnrelatedPeriod();
        $b = $this->dbB->pdo();
        $b->beginTransaction();
        $failure = null;
        try {
            if ($operation === 'delete') {
                $this->dimensions->deleteValue($this->suppliers['owner'], $this->values[$kind]);
            } else {
                $this->dimensions->updateValue($this->suppliers['owner'], $this->values[$kind], [$operation === 'parent' ? 'parent_id' : 'cost_center_id' => null]);
            }
        } catch (PDOException $e) {
            $failure = $e;
        }
        self::assertNotNull($failure);
        self::assertSame(1205, (int) $failure->errorInfo[1]);
        self::assertTrue($b->inTransaction());
        $a = $this->dbA->pdo();
        self::assertSame($this->periods['owner'], (int) $a->query('SELECT id FROM accounting_periods WHERE id = ' . $this->periods['owner'] . ' FOR UPDATE NOWAIT')->fetchColumn());
        self::assertSame($this->values[$kind], (int) $a->query('SELECT id FROM dimension_values WHERE id = ' . $this->values[$kind] . ' FOR UPDATE NOWAIT')->fetchColumn());
    }

    public function testSystemMetadataStillWaitsForItsOwnPeriodBeforeLockingTheValue(): void
    {
        $a = $this->dbA->pdo();
        $a->beginTransaction();
        $a->prepare('SELECT id FROM accounting_periods WHERE id = ? FOR UPDATE')->execute([$this->periods['owner']]);
        $this->dbB->pdo()->beginTransaction();
        $failure = null;
        try {
            $this->dimensions->updateValue($this->suppliers['owner'], $this->values['system'], ['note' => 'Synthetic changed']);
        } catch (PDOException $e) {
            $failure = $e;
        }
        self::assertNotNull($failure);
        self::assertSame(1205, (int) $failure->errorInfo[1]);
        self::assertSame($this->values['system'], (int) $a->query('SELECT id FROM dimension_values WHERE id = ' . $this->values['system'] . ' FOR UPDATE NOWAIT')->fetchColumn());
    }

    public function testSystemBuiltinMeaningUsesCurrentRowAfterStaleSnapshot(): void
    {
        $b = $this->dbB->pdo();
        $b->beginTransaction();
        self::assertNull($this->dimensions->findValue($this->suppliers['owner'], $this->values['system'])['semantic_key']);
        $this->dbA->pdo()->prepare("UPDATE dimension_values SET semantic_key = 'main' WHERE id = ?")->execute([$this->values['system']]);
        $this->expectException(DimensionException::class);
        $this->dimensions->updateValue($this->suppliers['owner'], $this->values['system'], ['name' => 'Changed meaning']);
    }

    public function testTypeAbsentFromOldSnapshotCannotBypassSystemCreateGuard(): void
    {
        $b = $this->dbB->pdo();
        $b->beginTransaction();
        $this->dimensions->listTypes($this->suppliers['owner']);
        $a = $this->dbA->pdo();
        $a->prepare("INSERT INTO dimension_types (supplier_id, code, name, kind, system_key) VALUES (?, 'activity', 'Synthetic activity', 'custom', 'activity')")->execute([$this->suppliers['owner']]);
        $type = ['id' => (int) $a->lastInsertId(), 'supplier_id' => $this->suppliers['owner'], 'supplier_group_id' => null, 'system_key' => null];
        $this->expectException(DimensionException::class);
        $this->dimensions->createValue($type, ['code' => 'FAKE', 'name' => 'Changed meaning']);
    }

    private function lockUnrelatedPeriod(): void
    {
        $pdo = $this->dbA->pdo();
        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM accounting_periods WHERE id = ? FOR UPDATE')->execute([$this->periods['unrelated']]);
    }
}
