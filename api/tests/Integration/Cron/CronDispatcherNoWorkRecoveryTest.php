<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Cron;

use DateTimeImmutable;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronCatalog;
use MyInvoice\Service\Cron\CronDispatcher;
use MyInvoice\Service\Cron\CronJobGate;
use MyInvoice\Service\Cron\CronProcessLauncher;
use MyInvoice\Service\Cron\CronRun;
use PDO;
use PHPUnit\Framework\TestCase;

final class CronDispatcherNoWorkRecoveryTest extends TestCase
{
    private PDO $pdo;
    private const TABLES = [
        'cron_heartbeat', 'cron_runs', 'cron_dispatch_claims',
        'payroll_submission_transport_attempts',
    ];

    protected function setUp(): void
    {
        $this->pdo = Bootstrap::buildContainer()->get(Connection::class)->pdo();
        foreach (self::TABLES as $table) {
            $this->pdo->exec("CREATE TEMPORARY TABLE `tmp_cron_recovery_$table` LIKE `$table`");
            $this->pdo->exec("ALTER TABLE `tmp_cron_recovery_$table` RENAME TO `$table`");
        }
        CronRun::start($this->pdo, 'cron-jmhz-poll')->finish('error', ['errors' => 1]);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            foreach (array_reverse(self::TABLES) as $table) {
                $this->pdo->exec("DROP TEMPORARY TABLE IF EXISTS `$table`");
            }
        }
    }

    public function testEmptyQueueRecoversOldFailureOnceWithoutLaunchingOrErasingHistory(): void
    {
        $dispatcher = $this->dispatcher();
        $when = new DateTimeImmutable('2026-09-09 12:10:00');
        $report = $dispatcher->tick($when);

        self::assertSame('no_work', $report['skipped']['cron-jmhz-poll']);
        $heartbeat = $this->heartbeat();
        self::assertSame('noop', $heartbeat['last_status']);
        self::assertNotNull($heartbeat['last_ok_at']);
        self::assertNull($heartbeat['last_work_at']);
        self::assertSame(0, (int) $heartbeat['last_exit_code']);
        self::assertSame(['skipped' => 'no_work', 'mode' => 'dispatcher'], json_decode($heartbeat['last_report'], true));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM cron_runs WHERE status = "error"')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM cron_runs')->fetchColumn());

        $dispatcher->tick($when);
        $dispatcher->tick($when->modify('+10 minutes'));
        self::assertSame(1, (int) $this->heartbeat()['noop_ticks']);
    }

    public function testDryRunDoesNotClearFailureOrClaimTheMinute(): void
    {
        $this->dispatcher()->tick(new DateTimeImmutable('2026-09-09 12:10:00'), true);

        self::assertSame('error', $this->heartbeat()['last_status']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM cron_dispatch_claims')->fetchColumn());
    }

    public function testFutureProtocolRetryKeepsFailureVisible(): void
    {
        $this->pdo->exec('DROP TEMPORARY TABLE payroll_submission_transport_attempts');
        $this->pdo->exec('CREATE TEMPORARY TABLE payroll_submission_transport_attempts (
            supplier_id INT, environment VARCHAR(20), submission_id INT, status VARCHAR(30),
            correlation_reference VARCHAR(100), closed_at DATETIME NULL, close_attempts INT,
            next_retry_at DATETIME NULL)');
        $this->pdo->exec('CREATE TEMPORARY TABLE payroll_submissions (
            id INT, supplier_id INT, environment VARCHAR(20), obligation_id INT)');
        $this->pdo->exec('CREATE TEMPORARY TABLE payroll_obligations (
            id INT, supplier_id INT, environment VARCHAR(20), agenda_code VARCHAR(20))');
        try {
            $this->pdo->exec('INSERT INTO payroll_submissions VALUES (1, 1, "production", 1)');
            $this->pdo->exec('INSERT INTO payroll_obligations VALUES (1, 1, "production", "JMHZ")');
            $this->pdo->exec('INSERT INTO payroll_submission_transport_attempts VALUES
                (1, "production", 1, "awaiting_protocol", "synthetic-correlation", NULL, 0,
                 DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))');

            $report = $this->dispatcher()->tick(new DateTimeImmutable('2026-09-09 12:10:00'));

            self::assertSame('no_work', $report['skipped']['cron-jmhz-poll']);
            self::assertSame('error', $this->heartbeat()['last_status']);
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM cron_dispatch_claims')->fetchColumn());
        } finally {
            $this->pdo->exec('DROP TEMPORARY TABLE payroll_submissions');
            $this->pdo->exec('DROP TEMPORARY TABLE payroll_obligations');
        }
    }

    public function testUnknownQueueDoesNotClearFailure(): void
    {
        $this->pdo->exec('ALTER TABLE payroll_submission_transport_attempts DROP COLUMN next_retry_at');
        $launched = [];
        $this->dispatcher($launched)->tick(new DateTimeImmutable('2026-09-09 12:10:00'));

        self::assertSame(['cron-jmhz-poll'], $launched);
        self::assertSame('error', $this->heartbeat()['last_status']);
    }

    private function dispatcher(?array &$launched = null): CronDispatcher
    {
        $disabled = array_values(array_filter(
            array_column(CronCatalog::all(), 'script'),
            static fn (string $script): bool => $script !== 'cron-jmhz-poll',
        ));
        $launcher = new class ($launched) implements CronProcessLauncher {
            public function __construct(private ?array &$launched) {}

            public function launch(string $script, ?string &$error = null): bool
            {
                if ($this->launched === null) {
                    TestCase::fail('Prázdná fronta nesmí spustit proces.');
                }
                $this->launched[] = $script;

                return true;
            }
        };

        return new CronDispatcher(
            $this->pdo,
            new CronJobGate(new Config(['cron' => ['disabled_jobs' => $disabled]]), null),
            $launcher,
        );
    }

    private function heartbeat(): array
    {
        return $this->pdo->query('SELECT * FROM cron_heartbeat WHERE script = "cron-jmhz-poll"')->fetch(PDO::FETCH_ASSOC);
    }
}
