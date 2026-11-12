<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use MyInvoice\Service\Anonymization\DatabaseCloner;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class DatabaseClonerDropTest extends TestCase
{
    private const SCHEMA = 'myucto_drop_0123456789_test';

    public function testLeftoverTriggerBackupsAreReplacedBeforeRetry(): void
    {
        $server = new DropBackupPdo([self::dropError(1010, 'Directory not empty')], [
            ['Trigger' => 'trg_a', 'Table' => 'guarded'],
            ['Trigger' => 'trg_b', 'Table' => 'guarded'],
        ]);
        DatabaseCloner::dropDatabase($server, self::SCHEMA);
        $drop = 'DROP DATABASE IF EXISTS `' . self::SCHEMA . '`';
        $trigger = static fn (string $name): string => 'CREATE OR REPLACE TRIGGER `' . self::SCHEMA . '`.`' . $name . '` BEFORE INSERT ON `'
            . self::SCHEMA . '`.`guarded` FOR EACH ROW SET @myucto_drop_backup = 1';
        self::assertSame(['SHOW TRIGGERS FROM `' . self::SCHEMA . '`'], $server->queries);
        self::assertSame([
            $drop,
            'CREATE TABLE IF NOT EXISTS `' . self::SCHEMA . '`.`guarded` (id INT)',
            $trigger('trg_a'), $trigger('trg_a'),
            $trigger('trg_b'), $trigger('trg_b'),
            $drop,
        ], $server->statements);
    }

    public function testOtherErrorsAreNotRetried(): void
    {
        $error = self::dropError(1044, 'Access denied');
        $server = new DropBackupPdo([$error], [['Trigger' => 'trg_a', 'Table' => 'guarded']]);
        try {
            DatabaseCloner::dropDatabase($server, self::SCHEMA);
            self::fail('Chyba práv se nesmí opakovat ani skrýt.');
        } catch (PDOException $actual) {
            self::assertSame($error, $actual);
        }
        self::assertCount(1, $server->statements);
    }

    public function testPersistentLeftoverEndsWithExplanation(): void
    {
        $error = self::dropError(1010, 'Directory not empty');
        $server = new DropBackupPdo(array_fill(0, 2, $error), []);
        try {
            DatabaseCloner::dropDatabase($server, self::SCHEMA, 2);
            self::fail('Trvalý zbytek se nesmí skrýt.');
        } catch (\RuntimeException $actual) {
            self::assertNotInstanceOf(PDOException::class, $actual);
            self::assertSame($error, $actual->getPrevious());
            self::assertStringContainsString('.TRG-', $actual->getMessage());
            self::assertStringContainsString(self::SCHEMA, $actual->getMessage());
        }
        self::assertSame(array_fill(0, 2, 'DROP DATABASE IF EXISTS `' . self::SCHEMA . '`'), $server->statements);
    }

    private static function dropError(int $vendor, string $message): PDOException
    {
        $error = new PDOException($message);
        $error->errorInfo = ['HY000', $vendor, $message];
        return $error;
    }
}

final class DropBackupPdo extends PDO
{
    public array $statements = [];
    public array $queries = [];

    public function __construct(private array $dropOutcomes, private array $triggers) {}

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;
        if (str_starts_with($statement, 'DROP DATABASE')) {
            $outcome = array_shift($this->dropOutcomes);
            if ($outcome instanceof PDOException) {
                throw $outcome;
            }
        }
        return 0;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->queries[] = $query;
        return new class($this->triggers) extends PDOStatement {
            public function __construct(private array $rows) {}
            public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
            {
                return $this->rows;
            }
        };
    }
}
