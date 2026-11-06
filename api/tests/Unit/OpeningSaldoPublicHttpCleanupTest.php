<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit;

use MyInvoice\Tests\Integration\Property\OpeningSaldoPublicHttpTest;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpeningSaldoPublicHttpCleanupTest extends TestCase
{
    private const SCHEMA = 'myucto_s6_saldo_http_01234567890123456789_test';

    public static function outcomes(): array
    {
        $cases = [];
        foreach (['configured' => 'myucto_synthetic_test', 'unset' => false, 'empty' => ''] as $name => $prior) {
            $cases[$name . ' transient'] = [$prior, 1010, 1, 2, false];
            $cases[$name . ' permanent'] = [$prior, 1010, 5, 5, true];
            $cases[$name . ' permission'] = [$prior, 1044, 1, 1, true];
        }
        return $cases;
    }

    #[DataProvider('outcomes')]
    public function testActualCleanupRestoresEnvironmentAndBoundsOwnedDatabaseRetries(string|false $prior, int $vendor, int $failures, int $attempts, bool $throws): void
    {
        $message = $vendor === 1010 ? 'Directory not empty' : 'Access denied';
        $error = new PDOException($message);
        $error->errorInfo = ['HY000', $vendor, $message];
        $server = new OpeningSaldoCleanupPdo(self::SCHEMA, $error, $failures);
        $outer = getenv('MYINVOICE_DB_NAME');
        try {
            putenv($prior === false ? 'MYINVOICE_DB_NAME' : 'MYINVOICE_DB_NAME=' . $prior);
            $capturedPrior = getenv('MYINVOICE_DB_NAME');
            putenv('MYINVOICE_DB_NAME=' . self::SCHEMA);
            $test = new OpeningSaldoPublicHttpTest('testOpeningFlowsThroughCuratedKeysetPageWithTemporalPersonalAndUncertainCoverage');
            foreach (['server' => $server, 'owned' => self::SCHEMA, 'priorDatabase' => $capturedPrior] as $name => $value) {
                (new \ReflectionProperty($test, $name))->setValue($test, $value);
            }
            $caught = null;
            try {
                (new \ReflectionMethod($test, 'tearDown'))->invoke($test);
            } catch (PDOException $actual) {
                $caught = $actual;
            }
            self::assertSame($capturedPrior, getenv('MYINVOICE_DB_NAME'));
            self::assertSame($throws ? $error : null, $caught);
            self::assertSame(array_fill(0, $attempts, 'DROP DATABASE IF EXISTS `' . self::SCHEMA . '`'), $server->statements);
            self::assertSame($throws ? self::SCHEMA : null, $server->created);
            self::assertSame($throws ? [] : [self::SCHEMA], $server->checkedSchemas);
        } finally {
            putenv($outer === false ? 'MYINVOICE_DB_NAME' : 'MYINVOICE_DB_NAME=' . $outer);
        }
    }
}

final class OpeningSaldoCleanupPdo extends PDO
{
    public array $statements = [];
    public array $checkedSchemas = [];

    public function __construct(public ?string $created, private PDOException $error, private int $failures) {}

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;
        if ($this->failures-- > 0) {
            throw $this->error;
        }
        return 0;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        TestCase::assertSame('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?', $query);
        return new class($this) extends PDOStatement {
            public function __construct(private OpeningSaldoCleanupPdo $server) {}
            public function execute(?array $params = null): bool
            {
                $this->server->checkedSchemas = $params;
                return true;
            }
            public function fetchColumn(int $column = 0): mixed { return 0; }
        };
    }
}
