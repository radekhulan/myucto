<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Database;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\ReadOnlySnapshot;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Snímek sestavy: druhé spojení zapíše a potvrdí řádek mezi dvěma čteními, první
 * spojení ho uvnitř snímku nesmí vidět ani při výchozí izolaci READ COMMITTED.
 */
#[Group('integration')]
final class ReadOnlySnapshotTest extends TestCase
{
    /** @var list<Connection> */
    private array $connections = [];
    private string $table = '';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildContainer();
        $config = $container->get(Config::class);
        $database = (string) $container->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn();
        self::assertStringEndsWith('_test', $database);
        for ($i = 0; $i < 2; ++$i) {
            $this->connections[] = Connection::withoutSharedTestConnection(fn () => new Connection(new Config($config->all(), $config->dataDir())));
        }
        $this->table = 'snapshot_probe_' . bin2hex(random_bytes(5));
        $this->connections[1]->pdo()->exec("CREATE TABLE {$this->table} (id INT PRIMARY KEY) ENGINE=InnoDB");
        $this->connections[1]->pdo()->exec("INSERT INTO {$this->table} VALUES (1)");
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            if ($db->pdo()->inTransaction()) $db->pdo()->rollBack();
        }
        if ($this->table !== '' && $this->connections !== []) {
            $this->connections[1]->pdo()->exec("DROP TABLE IF EXISTS {$this->table}");
        }
        foreach ($this->connections as $db) $db->close();
    }

    public function testSnapshotHidesConcurrentCommitAndRefusesWrites(): void
    {
        [$report, $writer] = [$this->connections[0]->pdo(), $this->connections[1]->pdo()];
        // Server s výchozí izolací READ COMMITTED: snímek si REPEATABLE READ musí nastavit sám.
        $report->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $count = fn (): int => (int) $report->query("SELECT COUNT(*) FROM {$this->table}")->fetchColumn();

        $seen = ReadOnlySnapshot::run($report, function () use ($report, $writer, $count): array {
            $before = $count();
            $writer->exec("INSERT INTO {$this->table} VALUES (2)");
            $after = $count();
            try {
                $report->exec("INSERT INTO {$this->table} VALUES (3)");
                $write = 0;
            } catch (\PDOException $e) {
                $write = (int) ($e->errorInfo[1] ?? 0);
            }
            return [$before, $after, $write];
        });
        self::assertSame([1, 1, 1792], $seen, 'Řádek potvrzený po začátku snímku není vidět a zápis selže (1792).');
        self::assertFalse($report->inTransaction());
        self::assertSame(2, $count());
    }

    public function testInsideForeignTransactionOnlyRunsTheRead(): void
    {
        $pdo = $this->connections[0]->pdo();
        $pdo->beginTransaction();
        // Cizí transakce zůstává zapisovatelná a otevřená.
        ReadOnlySnapshot::run($pdo, fn () => $pdo->exec("INSERT INTO {$this->table} VALUES (5)"));
        self::assertTrue($pdo->inTransaction());
        $pdo->rollBack();
    }
}
