<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Database;

use MyInvoice\Infrastructure\Database\LoggingPdo;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Čítač zápisů smí zůstat stejný jen tehdy, když sledovanou tabulku nic nemohlo změnit. */
final class LoggingPdoTableWriteCountTest extends TestCase
{
    public function testCountsEveryStatementThatCouldChangeTheTable(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'table-writes');
        // Schéma zakládá obyčejné PDO: DDL přes LoggingPdo zjišťuje jméno databáze, které SQLite nezná.
        $plain = new PDO('sqlite:' . $file);
        $plain->exec('CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, code TEXT)');
        $plain->exec('CREATE TABLE other_table (id INTEGER PRIMARY KEY)');
        $plain = null;
        try {
            $this->assertCounts(new LoggingPdo('sqlite:' . $file, '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION], new NullLogger()));
        } finally {
            gc_collect_cycles();
            @unlink($file);
        }
    }

    /** Souhrnný čítač mění každý zápis do jakékoli tabulky i každá hranice transakce, čtení nikdy. */
    public function testWriteCountChangesOnAnyWriteOrBoundary(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'writes');
        $plain = new PDO('sqlite:' . $file);
        $plain->exec('CREATE TABLE first_table (id INTEGER PRIMARY KEY)');
        $plain->exec('CREATE TABLE second_table (id INTEGER PRIMARY KEY)');
        $plain = null;
        try {
            $pdo = new LoggingPdo('sqlite:' . $file, '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION], new NullLogger());
            $pdo->beginTransaction();
            $count = $pdo->writeCount();
            $pdo->query('SELECT * FROM first_table')->fetchAll();
            $pdo->prepare('SELECT * FROM second_table WHERE id = ?')->execute([1]);
            $pdo->exec('SAVEPOINT memo');
            self::assertSame($count, $pdo->writeCount(), 'Čtení ani savepoint data nemění.');
            $pdo->prepare('INSERT INTO second_table (id) VALUES (?)')->execute([1]);
            self::assertSame(++$count, $pdo->writeCount(), 'Zápis do kterékoli tabulky.');
            $pdo->exec('DELETE FROM first_table');
            self::assertSame(++$count, $pdo->writeCount());
            $pdo->exec('ROLLBACK TO SAVEPOINT memo');
            self::assertGreaterThan($count, $count = $pdo->writeCount(), 'Návrat k savepointu.');
            try {
                $pdo->exec('INSERT INTO missing_table VALUES (1)');
            } catch (\PDOException) {
            }
            self::assertGreaterThan($count, $count = $pdo->writeCount(), 'Nepovedený příkaz.');
            $pdo->commit();
            self::assertGreaterThan($count, $pdo->writeCount(), 'Konec transakce.');
            $pdo = null;
        } finally {
            gc_collect_cycles();
            @unlink($file);
        }
    }

    private function assertCounts(LoggingPdo $pdo): void
    {
        $pdo->beginTransaction();
        $count = $pdo->tableWriteCount('chart_of_accounts');

        $pdo->query('SELECT * FROM chart_of_accounts')->fetchAll();
        $pdo->prepare('INSERT INTO other_table (id) VALUES (?)')->execute([1]);
        self::assertSame($count, $pdo->tableWriteCount('chart_of_accounts'), 'Čtení a zápis jinam čítač nemění.');

        $pdo->prepare('INSERT INTO chart_of_accounts (code) VALUES (?)')->execute(['518']);
        self::assertSame(++$count, $pdo->tableWriteCount('CHART_OF_ACCOUNTS'));
        $pdo->exec('UPDATE other_table SET id = id WHERE id IN (SELECT id FROM chart_of_accounts)');
        self::assertSame(++$count, $pdo->tableWriteCount('chart_of_accounts'), 'Zmínka tabulky v zápisu stačí.');

        $pdo->exec('SAVEPOINT memo');
        $pdo->exec('ROLLBACK TO SAVEPOINT memo');
        self::assertSame($count + 1, $pdo->tableWriteCount('chart_of_accounts'), 'Návrat k savepointu může vrátit zápis.');
        $count = $pdo->tableWriteCount('chart_of_accounts');
        $pdo->commit();
        self::assertGreaterThan($count, $count = $pdo->tableWriteCount('chart_of_accounts'), 'Konec transakce zahodí zámky.');
        $pdo->beginTransaction();
        self::assertGreaterThan($count, $count = $pdo->tableWriteCount('chart_of_accounts'));
        try {
            $pdo->exec('INSERT INTO missing_table VALUES (1)');
        } catch (\PDOException) {
        }
        self::assertGreaterThan($count, $count = $pdo->tableWriteCount('chart_of_accounts'), 'Chyba může transakci odvolat.');
        $pdo->rollBack();
        self::assertGreaterThan($count, $pdo->tableWriteCount('chart_of_accounts'));
    }
}
