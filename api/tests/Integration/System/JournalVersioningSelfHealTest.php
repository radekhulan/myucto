<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\System;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\Schema\JournalVersioningSelfHeal;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Samooprava SYSTEM VERSIONING po importu dumpu. Test pracuje s vlastní dočasnou
 * tabulkou — versioning deníku sdílené testovací DB se nesmí shazovat.
 */
final class JournalVersioningSelfHealTest extends TestCase
{
    private const PROBE = 'zz_journal_versioning_probe';

    private PDO $pdo;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->pdo = Bootstrap::buildApp()->getContainer()->get(Connection::class)->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        if (stripos((string) $this->pdo->query('SELECT VERSION()')->fetchColumn(), 'mariadb') === false) {
            $this->markTestSkipped('SYSTEM VERSIONING je jen v MariaDB.');
        }
        $this->dropProbe();
        $this->pdo->exec('CREATE TABLE ' . self::PROBE . ' (id INT PRIMARY KEY, amount DECIMAL(12,2) NOT NULL)');
        $this->pdo->exec('INSERT INTO ' . self::PROBE . ' VALUES (1, 100.00)');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropProbe();
        }
    }

    public function testBaseTableGetsVersioningAndKeepsItsRows(): void
    {
        self::assertSame('BASE TABLE', $this->tableType());

        $healed = (new JournalVersioningSelfHeal($this->pdo))->heal([self::PROBE]);

        self::assertSame([self::PROBE], $healed);
        self::assertSame('SYSTEM VERSIONED', $this->tableType());
        self::assertSame('100.00', (string) $this->pdo->query('SELECT amount FROM ' . self::PROBE . ' WHERE id = 1')->fetchColumn());

        // Po doplnění se historie opravdu vede: přepis zanechá předchozí verzi řádku.
        $this->pdo->exec('UPDATE ' . self::PROBE . ' SET amount = 200.00 WHERE id = 1');
        $versions = (int) $this->pdo->query('SELECT COUNT(*) FROM ' . self::PROBE . ' FOR SYSTEM_TIME ALL WHERE id = 1')->fetchColumn();
        self::assertSame(2, $versions);
    }

    public function testAlreadyVersionedAndMissingTablesAreLeftAlone(): void
    {
        $heal = new JournalVersioningSelfHeal($this->pdo);
        $heal->heal([self::PROBE]);

        self::assertSame([], $heal->heal([self::PROBE, 'zz_table_that_does_not_exist']));
        self::assertSame('SYSTEM VERSIONED', $this->tableType());
    }

    public function testJournalTablesOfTestDatabaseAreVersioned(): void
    {
        self::assertSame([], (new JournalVersioningSelfHeal($this->pdo))->heal());
    }

    private function tableType(): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([self::PROBE]);
        return (string) $stmt->fetchColumn();
    }

    private function dropProbe(): void
    {
        $this->pdo->exec('SET @@system_versioning_alter_history = 1');
        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::PROBE);
    }
}
