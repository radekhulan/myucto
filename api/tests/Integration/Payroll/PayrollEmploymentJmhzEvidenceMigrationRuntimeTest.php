<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollEmploymentJmhzEvidenceMigrationRuntimeTest extends TestCase
{
    private ?PDO $server = null;
    private string $database = '';
    private string $rootDir = '';
    private Config $config;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        $this->config = Config::load($this->rootDir);
        $this->database = 'myucto_jmhz_employment_' . bin2hex(random_bytes(6));
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
                'CREATE TABLE payroll_employment_terms (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    work_place VARCHAR(255) NULL,
                    regular_workplace VARCHAR(255) NULL
                ) ENGINE=InnoDB',
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('Nelze vytvořit izolovanou DB: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->database !== '') {
            \MyInvoice\Service\Anonymization\DatabaseCloner::dropDatabase($this->server, $this->database);
        }
    }

    public function testMigrationIsIdempotentAndDatabaseRejectsAmbiguousEvidence(): void
    {
        $this->runMigrator();
        $db = $this->db();
        $db->exec('INSERT INTO payroll_employment_terms (regular_workplace) VALUES (NULL)');
        self::assertSame('unverified', $db->query(
            'SELECT jmhz_apz_contribution_status FROM payroll_employment_terms',
        )->fetchColumn());

        $this->expectDatabaseConstraint(static function () use ($db): void {
            $db->exec(
                "INSERT INTO payroll_employment_terms
                    (work_place, jmhz_workplace_country_code)
                 VALUES ('Praha', 'CZ')",
            );
        });
        $this->expectDatabaseConstraint(static function () use ($db): void {
            $db->exec(
                "INSERT INTO payroll_employment_terms
                    (jmhz_apz_contribution_status, jmhz_apz_instrument_code)
                 VALUES ('no', '1')",
            );
        });
        $this->expectDatabaseConstraint(static function () use ($db): void {
            $db->exec(
                "INSERT INTO payroll_employment_terms
                    (jmhz_apz_contribution_status, jmhz_apz_instrument_code)
                 VALUES ('yes', NULL)",
            );
        });
        $this->expectDatabaseConstraint(static function () use ($db): void {
            $db->exec(
                "INSERT INTO payroll_employment_terms
                    (jmhz_external_codebook_overlay_key)
                 VALUES ('orphan-overlay')",
            );
        });

        $db->exec(
            "DELETE FROM migrations
              WHERE filename IN (
                '1345_payroll_employment_jmhz_core_evidence.sql',
                '1346_payroll_employment_jmhz_external_codebook_provenance.sql'
              )",
        );
        $this->runMigrator();
        self::assertSame(3, (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND CONSTRAINT_NAME IN (
                  'chk_payroll_employment_jmhz_workplace',
                  'chk_payroll_employment_jmhz_apz',
                  'chk_payroll_employment_jmhz_external_codebook_provenance'
                )",
        )->fetchColumn());
    }

    /** @param callable():void $operation */
    private function expectDatabaseConstraint(callable $operation): void
    {
        try {
            $operation();
            self::fail('Databáze přijala nejednoznačnou evidenci JMHZ.');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }
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
        $command = [
            PHP_BINARY,
            $this->rootDir . '/api/bin/migrate.php',
            '--no-backfills',
            '--only=1345_payroll_employment_jmhz_core_evidence.sql,1346_payroll_employment_jmhz_external_codebook_provenance.sql',
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
        self::assertSame(0, proc_close($process), "Migrátor selhal.\n{$stdout}\n{$stderr}");
    }
}
