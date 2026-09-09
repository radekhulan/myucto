<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

defined('MYINVOICE_PARALLEL_RUNNER_FUNCTIONS_ONLY') || define('MYINVOICE_PARALLEL_RUNNER_FUNCTIONS_ONLY', true);
require_once dirname(__DIR__, 2) . '/bin/test-parallel.php';

final class ParallelClonePlanTest extends TestCase
{
    public function testOneSourcePlanServesTwoClonesWithoutRepeatedMetadataQueries(): void
    {
        $pdo = $this->createStub(PDO::class);
        $metadata = [];
        $executed = [];
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$metadata): PDOStatement {
            $metadata[] = $sql;
            $rows = match (true) {
                str_contains($sql, 'KEY_COLUMN_USAGE') => [['TABLE_NAME' => 'child', 'REFERENCED_TABLE_NAME' => 'parent']],
                str_contains($sql, 'information_schema.COLUMNS') => [
                    ['TABLE_NAME' => 'child', 'COLUMN_NAME' => 'id'],
                    ['TABLE_NAME' => 'child', 'COLUMN_NAME' => 'parent_id'],
                    ['TABLE_NAME' => 'parent', 'COLUMN_NAME' => 'id'],
                ],
                str_contains($sql, 'information_schema.TRIGGERS') => [
                    ['TRIGGER_NAME' => 'child_a', 'EVENT_OBJECT_TABLE' => 'child'],
                    ['TRIGGER_NAME' => 'child_b', 'EVENT_OBJECT_TABLE' => 'child'],
                ],
                str_contains($sql, 'information_schema.ROUTINES') => [],
                str_contains($sql, "TABLE_TYPE = 'VIEW'") => [],
                default => ['child', 'parent'],
            };
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('fetchAll')->willReturn($rows);
            return $statement;
        });
        $pdo->method('query')->willReturnCallback(function (string $sql) use (&$metadata): PDOStatement {
            $metadata[] = $sql;
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('fetch')->willReturn(['Create Table' => 'DDL ' . $sql]);
            return $statement;
        });
        $pdo->method('exec')->willReturnCallback(static function (string $sql) use (&$executed): int {
            $executed[] = $sql;
            return 0;
        });
        $plan = \sourceClonePlan($pdo, 'synthetic_test');
        self::assertSame(['parent', 'child'], array_keys($plan['tables']));
        self::assertSame('`id`, `parent_id`', $plan['tables']['child']['columns']);
        $sourceQueryCount = count($metadata);
        \cloneDatabase($pdo, $plan, 'synthetic_first_test');
        \cloneDatabase($pdo, $plan, 'synthetic_second_test');
        self::assertCount($sourceQueryCount + 2, $metadata);
        self::assertCount(2, array_filter($executed, static fn (string $sql): bool => str_contains($sql, 'DDL SHOW CREATE TRIGGER `synthetic_test`.`child_a`')));
        self::assertContains('INSERT INTO `synthetic_first_test`.`child` (`id`, `parent_id`) SELECT `id`, `parent_id` FROM `synthetic_test`.`child`', $executed);
        self::assertContains('INSERT INTO `synthetic_second_test`.`child` (`id`, `parent_id`) SELECT `id`, `parent_id` FROM `synthetic_test`.`child`', $executed);
        self::assertSame('SET SESSION FOREIGN_KEY_CHECKS = 1', end($executed));
    }

    public function testSourceCannotBeDroppedAsItsOwnTarget(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('exec');
        $this->expectException(\RuntimeException::class);
        \cloneDatabase($pdo, ['source' => 'synthetic_test'], 'synthetic_test');
    }

    public function testFailedCloneDropsOnlyItsTargetAndRestoresForeignKeys(): void
    {
        $pdo = $this->createStub(PDO::class);
        $executed = [];
        $pdo->method('exec')->willReturnCallback(static function (string $sql) use (&$executed): int {
            $executed[] = $sql;
            if ($sql === 'invalid DDL') {
                throw new \RuntimeException('synthetic failure');
            }
            return 0;
        });
        try {
            \cloneDatabase($pdo, ['source' => 'synthetic_test', 'tables' => ['item' => ['ddl' => 'invalid DDL']]], 'synthetic_worker_test');
            self::fail('Failed DDL must fail the clone.');
        } catch (\RuntimeException $e) {
            self::assertSame('DDL tabulky item nelze zkopírovat.', $e->getMessage());
            self::assertSame('synthetic failure', $e->getPrevious()?->getMessage());
            self::assertSame(['DROP DATABASE IF EXISTS `synthetic_worker_test`', 'SET SESSION FOREIGN_KEY_CHECKS = 1'], array_slice($executed, -2));
            self::assertNotContains('DROP DATABASE IF EXISTS `synthetic_test`', $executed);
        }
    }
}
