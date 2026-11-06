<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollJmhzPreparationSourceV4MigrationRuntimeTest extends TestCase
{
    private ?PDO $server = null;
    private string $database = '';
    private string $rootDir = '';
    private Config $config;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        $this->config = Config::load($this->rootDir);
        $this->database = 'myucto_jmhz_prep_v4_' . bin2hex(random_bytes(6));
        try {
            $this->server = new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;charset=utf8mb4',
                    (string) $this->config->get('db.host', '127.0.0.1'),
                    (int) $this->config->get('db.port', 3306),
                ),
                (string) $this->config->get('db.user'),
                (string) $this->config->get('db.pass', ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $this->server->exec(
                "CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
            );
            $this->db()->exec(
                "CREATE TABLE payroll_jmhz_preparation_snapshots (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    builder_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                    CONSTRAINT chk_payroll_jmhz_preparation_builder CHECK (
                      builder_version IN (
                        'jmhz-preparation-source.v1',
                        'jmhz-preparation-source.v2',
                        'jmhz-preparation-source.v3'
                      )
                    )
                 ) ENGINE=InnoDB;",
            );
        } catch (\Throwable $exception) {
            $this->markTestSkipped(
                'Nelze vytvořit izolovanou DB: ' . $exception->getMessage(),
            );
        }
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->database !== '') {
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        }
    }

    public function testMigrationIsIdempotentAndAcceptsEveryStoredContract(): void
    {
        $this->runMigrator();
        $this->db()->exec(
            "INSERT INTO payroll_jmhz_preparation_snapshots (builder_version)
             VALUES ('jmhz-preparation-source.v1'),
                    ('jmhz-preparation-source.v2'),
                    ('jmhz-preparation-source.v3'),
                    ('jmhz-preparation-source.v4')",
        );
        $this->db()->exec(
            "DELETE FROM migrations
              WHERE filename = '1365_payroll_jmhz_preparation_source_v4.sql'",
        );
        $this->runMigrator();
        $count = $this->db()->prepare(
            'SELECT COUNT(*) FROM payroll_jmhz_preparation_snapshots',
        );
        $count->execute();
        self::assertSame(4, (int) $count->fetchColumn());
    }

    private function db(): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) $this->config->get('db.host', '127.0.0.1'),
                (int) $this->config->get('db.port', 3306),
                $this->database,
            ),
            (string) $this->config->get('db.user'),
            (string) $this->config->get('db.pass', ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function runMigrator(): void
    {
        $filename = '1365_payroll_jmhz_preparation_source_v4.sql';
        $command = [
            PHP_BINARY,
            $this->rootDir . '/api/bin/migrate.php',
            '--no-backfills',
            "--only={$filename}",
        ];
        $environment = getenv();
        $environment['MYINVOICE_DB_NAME'] = $this->database;
        $environment['MYSQL_DATABASE'] = $this->database;
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->rootDir,
            $environment,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(
            0,
            proc_close($process),
            "Migrátor selhal.\n{$stdout}\n{$stderr}",
        );
    }
}
