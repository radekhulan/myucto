<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Cron;

use MyInvoice\Service\Cron\CronPreflight;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CronPreflightJmhzTest extends TestCase
{
    private \Pdo\Sqlite $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \Pdo\Sqlite('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->createFunction('UTC_TIMESTAMP', static fn (): string => '2026-09-08 12:00:00');
        $this->pdo->exec('CREATE TABLE payroll_submission_transport_attempts (
            id INTEGER PRIMARY KEY, supplier_id INTEGER, environment TEXT,
            submission_id INTEGER, status TEXT, correlation_reference TEXT,
            closed_at TEXT, close_attempts INTEGER, next_retry_at TEXT
        )');
        $this->pdo->exec('CREATE TABLE payroll_submissions (
            id INTEGER, supplier_id INTEGER, environment TEXT, obligation_id INTEGER
        )');
        $this->pdo->exec('CREATE TABLE payroll_obligations (
            id INTEGER, supplier_id INTEGER, environment TEXT, agenda_code TEXT
        )');
    }

    public static function attempts(): iterable
    {
        yield 'due poll' => ['awaiting_protocol', 'synthetic', 'JMHZ', 0, null, null, true];
        yield 'due JMHZ25' => ['awaiting_protocol', 'synthetic', 'JMHZ25', 0, '2026-09-08 12:00:00', null, true];
        yield 'future poll' => ['awaiting_protocol', 'synthetic', 'JMHZ', 0, '2026-09-08 12:00:01', null, false];
        yield 'missing correlation' => ['awaiting_protocol', null, 'JMHZ', 0, null, null, false];
        yield 'registration agenda' => ['awaiting_protocol', 'synthetic', 'PREZEC', 0, null, null, false];
        yield 'due close' => ['completed', 'synthetic', 'JMHZ', 7, null, null, true];
        yield 'exhausted close' => ['completed', 'synthetic', 'JMHZ', 8, null, null, false];
        yield 'close without correlation' => ['completed', null, 'JMHZ', 0, null, null, false];
        yield 'future close' => ['completed', 'synthetic', 'JMHZ', 0, '2026-09-08 12:00:01', null, false];
        yield 'closed' => ['completed', 'synthetic', 'JMHZ', 1, null, '2026-09-08 11:00:00', false];
        yield 'expired' => ['expired', 'synthetic', 'JMHZ', 0, null, null, false];
        yield 'failed' => ['failed', 'synthetic', 'JMHZ', 0, null, null, false];
    }

    #[DataProvider('attempts')]
    public function testOnlyWorkSelectedByTheSweepOpensTheGate(
        string $status,
        ?string $correlation,
        string $agenda,
        int $closeAttempts,
        ?string $nextRetry,
        ?string $closedAt,
        bool $expected,
    ): void {
        $this->pdo->prepare('INSERT INTO payroll_obligations VALUES (1, 1, ?, ?)')
            ->execute(['test', $agenda]);
        $this->pdo->exec("INSERT INTO payroll_submissions VALUES (1, 1, 'test', 1)");
        $this->pdo->prepare('INSERT INTO payroll_submission_transport_attempts
            VALUES (1, 1, ?, 1, ?, ?, ?, ?, ?)')
            ->execute(['test', $status, $correlation, $closedAt, $closeAttempts, $nextRetry]);

        self::assertSame($expected, CronPreflight::hasJmhzTransportWork($this->pdo));
    }

    public function testEmptyQueueDoesNotBootstrapTheWorker(): void
    {
        self::assertFalse(CronPreflight::hasJmhzTransportWork($this->pdo));
    }

    public function testAnotherSupplierOrEnvironmentCannotSupplyTheAgenda(): void
    {
        $this->pdo->exec("INSERT INTO payroll_submission_transport_attempts
            VALUES (1, 1, 'test', 1, 'awaiting_protocol', 'synthetic', NULL, 0, NULL)");
        $this->pdo->exec("INSERT INTO payroll_submissions VALUES (1, 2, 'test', 1), (1, 1, 'production', 1)");
        $this->pdo->exec("INSERT INTO payroll_obligations VALUES (1, 2, 'test', 'JMHZ'), (1, 1, 'production', 'JMHZ')");

        self::assertFalse(CronPreflight::hasJmhzTransportWork($this->pdo));
    }

    public function testMissingSchemaStillFailsOpen(): void
    {
        $this->pdo->exec('DROP TABLE payroll_submission_transport_attempts');

        self::assertTrue(CronPreflight::hasJmhzTransportWork($this->pdo));
    }
}
