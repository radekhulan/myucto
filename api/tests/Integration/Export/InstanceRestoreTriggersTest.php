<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Export;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Export\Instance\InstanceExportException;
use MyInvoice\Service\Export\Instance\InstanceRestoreTriggers;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class InstanceRestoreTriggersTest extends TestCase
{
    private PDO $server;
    private PDO $pdo;
    private string $database = '';
    private Config $config;

    protected function setUp(): void
    {
        $this->config = Config::load(dirname(__DIR__, 4));
        $this->server = $this->connect();
        $this->database = 'myucto_restore_triggers_' . bin2hex(random_bytes(6));
        $this->server->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->pdo = $this->connect($this->database);
        $this->createTable();
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE'");
        $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->pdo->exec('CREATE TRIGGER z_first BEFORE INSERT ON evidence FOR EACH ROW SET NEW.value = NEW.value + 1');
        $this->pdo->exec('CREATE TRIGGER a_second BEFORE INSERT ON evidence FOR EACH ROW SET NEW.value = NEW.value * 2');
        $this->pdo->exec("CREATE TRIGGER immutable BEFORE UPDATE ON evidence FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Evidence is immutable'");
        $this->pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        $this->pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->server) && preg_match('/^myucto_restore_triggers_[a-f0-9]{12}$/D', $this->database) === 1) {
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        }
    }

    public function testSnapshotImportPreservesStoredDataAndRestoresOrderedRuntimeGuards(): void
    {
        $before = $this->triggerMetadata();
        $context = $this->context();
        $checked = false;
        $result = (new InstanceRestoreTriggers($this->pdo))->run(function (): string {

            $this->pdo->beginTransaction();
            $this->pdo->exec('INSERT INTO evidence VALUES (1, 7)');
            $this->pdo->exec('UPDATE evidence SET value = 9 WHERE id = 1');
            $this->pdo->commit();
            return 'restored';
        }, function () use (&$checked): void {
            self::assertCount(3, $this->triggerMetadata());
            $checked = true;
        });
        self::assertTrue($checked);
        self::assertSame('restored', $result);
        self::assertSame($before, $this->triggerMetadata());
        self::assertSame($context, $this->context());
        self::assertSame(9, (int) $this->pdo->query('SELECT value FROM evidence WHERE id = 1')->fetchColumn());
        $this->assertRecoveryRemoved();
        $this->pdo->exec('INSERT INTO evidence VALUES (2, 7)');
        self::assertSame(16, (int) $this->pdo->query('SELECT value FROM evidence WHERE id = 2')->fetchColumn());
        $this->expectException(PDOException::class);
        $this->pdo->exec('UPDATE evidence SET value = 10 WHERE id = 1');
    }

    public function testImportFailureRollsBackBeforeDdlAndRestoresGuards(): void
    {
        $before = $this->triggerMetadata();
        $context = $this->context();
        try {
            (new InstanceRestoreTriggers($this->pdo))->run(function (): void {
                $this->pdo->beginTransaction();
                $this->pdo->exec('INSERT INTO evidence VALUES (1, 7)');
                throw new \RuntimeException('synthetic import failure');
            }, static fn () => null);
            self::fail('Import failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('synthetic import failure', $exception->getMessage());
        }
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM evidence')->fetchColumn());
        self::assertSame($before, $this->triggerMetadata());
        self::assertSame($context, $this->context());
        $this->assertRecoveryRemoved();
    }

    public function testFailedRecreationPersistsRecoveryForANewConnectionBeforeTargetCheck(): void
    {
        $before = $this->triggerMetadata();
        try {
            (new InstanceRestoreTriggers($this->pdo))->run(function (): void {
                $this->pdo->exec('DROP TABLE evidence');
            }, static fn () => null);
            self::fail('Missing trigger target must prevent successful restore.');
        } catch (PDOException $exception) {
            self::assertStringContainsString('evidence', $exception->getMessage());
        }
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM instance_restore_trigger_recovery')->fetchColumn());
        $this->createTable();
        $this->pdo = $this->connect($this->database);
        $context = $this->context();
        try {
            (new InstanceRestoreTriggers($this->pdo))->run(
                static function (): void { self::fail('Target check must prevent import.'); },
                function () use ($before): void {
                    self::assertSame($before, $this->triggerMetadata());
                    throw new \RuntimeException('target not empty');
                },
            );
            self::fail('Target check failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('target not empty', $exception->getMessage());
        }
        self::assertSame($before, $this->triggerMetadata());
        self::assertSame($context, $this->context());
        $this->assertRecoveryRemoved();
    }

    public function testUnfinishedCallbackTransactionCannotBeImplicitlyCommittedByTriggerDdl(): void
    {
        try {
            (new InstanceRestoreTriggers($this->pdo))->run(function (): void {
                $this->pdo->beginTransaction();
                $this->pdo->exec('INSERT INTO evidence VALUES (1, 7)');
            }, static fn () => null);
            self::fail('Open callback transaction must be rejected.');
        } catch (InstanceExportException $exception) {
            self::assertSame('restore_transaction_unfinished', $exception->errorCode);
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM evidence')->fetchColumn());
        self::assertCount(3, $this->triggerMetadata());
        $this->assertRecoveryRemoved();
    }

    public function testActiveCallerTransactionIsRejectedWithoutDdl(): void
    {
        $this->pdo->beginTransaction();
        try {
            (new InstanceRestoreTriggers($this->pdo))->run(static fn () => null, static fn () => null);
            self::fail('Caller transaction must be rejected.');
        } catch (InstanceExportException $exception) {
            self::assertSame('restore_transaction_active', $exception->errorCode);
        }
        self::assertTrue($this->pdo->inTransaction());
        self::assertCount(3, $this->triggerMetadata());
        $this->assertRecoveryRemoved();
    }

    public function testConcurrentRestoreIsRejectedAndLockIsReleasedAfterFailure(): void
    {
        $other = $this->connect($this->database);
        $lock = 'instance-restore:' . hash('sha256', $this->database);
        $statement = $other->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$lock]);
        self::assertSame(1, (int) $statement->fetchColumn());
        try {
            (new InstanceRestoreTriggers($this->pdo))->run(static fn () => null, static fn () => null);
            self::fail('Concurrent restore must be rejected.');
        } catch (InstanceExportException $exception) {
            self::assertSame('restore_locked', $exception->errorCode);
        } finally {
            $statement = $other->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$lock]);
        }
        try {
            (new InstanceRestoreTriggers($this->pdo))->run(static fn () => null, static function (): void {
                throw new \RuntimeException('synthetic target failure');
            });
            self::fail('Target failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('synthetic target failure', $exception->getMessage());
        }
        $statement = $other->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$lock]);
        self::assertSame(1, (int) $statement->fetchColumn());
        $statement = $other->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$lock]);
        self::assertCount(3, $this->triggerMetadata());
    }
    private function connect(string $database = ''): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4%s', $this->config->get('db.host', '127.0.0.1'), (int) $this->config->get('db.port', 3306), $database === '' ? '' : ';dbname=' . $database),
            (string) $this->config->get('db.user'),
            (string) $this->config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }

    private function createTable(): void
    {
        $this->pdo->exec('CREATE TABLE evidence (id INT NOT NULL PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB');
    }

    private function triggerMetadata(): array
    {
        return $this->pdo->query('SELECT TRIGGER_NAME, ACTION_ORDER, ACTION_STATEMENT, SQL_MODE, CHARACTER_SET_CLIENT, COLLATION_CONNECTION, DATABASE_COLLATION, DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function context(): array
    {
        return $this->pdo->query('SELECT @@SESSION.sql_mode, @@SESSION.character_set_client, @@SESSION.collation_connection')->fetch(PDO::FETCH_ASSOC);
    }

    private function assertRecoveryRemoved(): void
    {
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instance_restore_trigger_recovery'")->fetchColumn());
    }
}
