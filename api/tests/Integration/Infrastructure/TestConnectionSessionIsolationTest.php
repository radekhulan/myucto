<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Infrastructure;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class TestConnectionSessionIsolationTest extends TestCase
{
    public function testResetRestoresIsolationAndSnapshotAfterAnotherTestChangedThem(): void
    {
        $container = Bootstrap::buildContainer();
        $connection = $container->get(Connection::class);
        $config = $container->get(Config::class);
        self::assertStringEndsWith('_test', (string) $config->get('db.name'));
        $pdo = $connection->pdo();
        try {
            $pdo->exec("SET SESSION transaction_isolation = 'READ-UNCOMMITTED', innodb_snapshot_isolation = 0");
            self::assertSame(['READ-UNCOMMITTED', '0'], $this->state($pdo));
            self::assertSame([], Connection::resetSharedTestSessions());
            self::assertSame(['REPEATABLE-READ', '1'], $this->state($pdo));
            $next = new Connection($config);
            self::assertSame($pdo, $next->pdo(), 'Reset musí zachovat použitelné sdílené spojení.');
            self::assertSame(1, (int) $next->pdo()->query('SELECT 1')->fetchColumn());
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec("SET SESSION transaction_isolation = 'REPEATABLE-READ', innodb_snapshot_isolation = 1");
        }
    }

    public function testResetKeepsClassTransactionRowsAndSavepoints(): void
    {
        $connection = Bootstrap::buildContainer()->get(Connection::class);
        $pdo = $connection->pdo();
        $table = 'session_reset_' . bin2hex(random_bytes(8));
        $pdo->exec('CREATE TEMPORARY TABLE `' . $table . '` (id INT PRIMARY KEY) ENGINE=InnoDB');
        try {
            $pdo->exec("SET SESSION transaction_isolation = 'REPEATABLE-READ', innodb_snapshot_isolation = 1");
            $pdo->beginTransaction();
            $pdo->exec('INSERT INTO `' . $table . '` VALUES (1)');
            $pdo->exec('SAVEPOINT class_fixture');
            $pdo->exec('INSERT INTO `' . $table . '` VALUES (2)');
            $pdo->exec("SET SESSION transaction_isolation = 'READ-UNCOMMITTED', innodb_snapshot_isolation = 0");
            self::assertSame([], Connection::resetSharedTestSessions(true));
            self::assertTrue($pdo->inTransaction());
            self::assertSame(['REPEATABLE-READ', '1'], $this->state($pdo));
            self::assertSame([1, 2], array_map('intval', $pdo->query('SELECT id FROM `' . $table . '` ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
            $pdo->exec('ROLLBACK TO SAVEPOINT class_fixture');
            self::assertSame([1], array_map('intval', $pdo->query('SELECT id FROM `' . $table . '` ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
            $pdo->exec('INSERT INTO `' . $table . '` VALUES (3)');
            self::assertSame([1, 3], array_map('intval', $pdo->query('SELECT id FROM `' . $table . '` ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
            $pdo->rollBack();
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn());
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS `' . $table . '`');
            $pdo->exec("SET SESSION transaction_isolation = 'REPEATABLE-READ', innodb_snapshot_isolation = 1");
        }
    }

    public function testUnsharedTestConnectionUsesTheSameIsolationBaseline(): void
    {
        $config = Bootstrap::buildContainer()->get(Config::class);
        self::assertStringEndsWith('_test', (string) $config->get('db.name'));
        $connection = Connection::withoutSharedTestConnection(static fn (): Connection => new Connection($config));
        try {
            $pdo = $connection->pdo();
            self::assertSame(['REPEATABLE-READ', '1'], $this->state($pdo));
            self::assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
        } finally {
            $connection->close();
        }
    }

    private function state(PDO $pdo): array
    {
        return array_map('strval', $pdo->query('SELECT @@SESSION.transaction_isolation, @@SESSION.innodb_snapshot_isolation')->fetch(PDO::FETCH_NUM));
    }
}
