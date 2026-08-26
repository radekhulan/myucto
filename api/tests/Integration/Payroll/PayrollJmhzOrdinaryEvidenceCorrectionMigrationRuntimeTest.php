<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Config\Config;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollJmhzOrdinaryEvidenceCorrectionMigrationRuntimeTest extends TestCase
{
    private ?PDO $server = null;
    private string $database = '';
    private string $rootDir = '';
    private Config $config;

    protected function setUp(): void
    {
        $this->rootDir = dirname(__DIR__, 4);
        $this->config = Config::load($this->rootDir);
        $this->database = 'myucto_jmhz_ordinary_correction_' . bin2hex(random_bytes(6));
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
            $this->server->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->db()->exec(
                "CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB;
                 CREATE TABLE payroll_employees (
                    id BIGINT UNSIGNED NOT NULL, supplier_id INT UNSIGNED NOT NULL,
                    PRIMARY KEY (id), UNIQUE KEY uq_employee (supplier_id,id)
                 ) ENGINE=InnoDB;
                 CREATE TABLE payroll_employments (
                    id BIGINT UNSIGNED NOT NULL, supplier_id INT UNSIGNED NOT NULL,
                    employee_id BIGINT UNSIGNED NOT NULL,
                    PRIMARY KEY (id), UNIQUE KEY uq_employment (supplier_id,id)
                 ) ENGINE=InnoDB;
                 CREATE TABLE payroll_runs (
                    id BIGINT UNSIGNED NOT NULL, supplier_id INT UNSIGNED NOT NULL,
                    period_start DATE NOT NULL, current_revision_no INT UNSIGNED NOT NULL,
                    PRIMARY KEY (id), UNIQUE KEY uq_run (supplier_id,id)
                 ) ENGINE=InnoDB;
                 CREATE TABLE payroll_run_revisions (
                    id BIGINT UNSIGNED NOT NULL, supplier_id INT UNSIGNED NOT NULL,
                    run_id BIGINT UNSIGNED NOT NULL, revision_no INT UNSIGNED NOT NULL,
                    revision_kind VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL,
                    PRIMARY KEY (id), UNIQUE KEY uq_revision (supplier_id,id),
                    UNIQUE KEY uq_revision_run (supplier_id,id,run_id)
                 ) ENGINE=InnoDB;
                 CREATE TABLE payroll_run_employments (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    supplier_id INT UNSIGNED NOT NULL,
                    revision_id BIGINT UNSIGNED NOT NULL,
                    employee_id BIGINT UNSIGNED NOT NULL,
                    employment_id BIGINT UNSIGNED NOT NULL,
                    UNIQUE KEY uq_run_employment_owner
                      (supplier_id,revision_id,employee_id,employment_id)
                 ) ENGINE=InnoDB;
                 CREATE TABLE payroll_jmhz_preparation_snapshots (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    builder_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                    CONSTRAINT chk_payroll_jmhz_preparation_builder
                      CHECK (builder_version IN ('jmhz-preparation-source.v1','jmhz-preparation-source.v2'))
                 ) ENGINE=InnoDB;",
            );
        } catch (\Throwable $exception) {
            $this->markTestSkipped('Nelze vytvořit izolovanou DB: ' . $exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->server !== null && $this->database !== '') {
            $this->server->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        }
    }

    public function testCurrentApprovedCorrectionRevisionCanFreezeOrdinaryEvidence(): void
    {
        $this->runMigrator('1363_payroll_jmhz_eldp_evidence.sql');
        $this->runMigrator('1364_payroll_jmhz_eldp_evidence_hardening.sql');
        $this->runMigrator('1366_payroll_jmhz_ordinary_evidence.sql');
        $this->runMigrator('1543_payroll_jmhz_ordinary_evidence_correction_revision.sql');
        $this->runMigrator('1544_payroll_jmhz_eldp_evidence_correction_revision.sql');
        $db = $this->db();
        $db->exec(
            "INSERT INTO users VALUES (1);
             INSERT INTO payroll_employees VALUES (11,7);
             INSERT INTO payroll_employments VALUES (21,7,11);
             INSERT INTO payroll_runs VALUES (31,7,'2026-08-01',2);
             INSERT INTO payroll_run_revisions VALUES (42,7,31,2,'correction','approved');
             INSERT INTO payroll_run_employments
                (supplier_id,revision_id,employee_id,employment_id)
             VALUES (7,42,11,21);
             INSERT INTO payroll_jmhz_ordinary_evidence_snapshots
                (supplier_id,run_id,source_revision_id,employee_id,employment_id,
                 period_start,schema_reference,source_manifest_json,source_manifest_sha256,
                 snapshot_ciphertext,snapshot_fingerprint,request_fingerprint,
                 idempotency_key_hash,confirmed_by,confirmed_at)
             VALUES (7,31,42,11,21,'2026-08-01','payroll-jmhz-ordinary-evidence.v1',
                     '{}',REPEAT('a',64),'enc:v2:synthetic',REPEAT('b',64),REPEAT('c',64),
                     UNHEX(REPEAT('01',32)),1,'2026-08-26 00:00:00.000000');
             INSERT INTO payroll_jmhz_eldp_evidence_snapshots
                (supplier_id,environment,run_id,source_revision_id,employee_id,
                 employment_id,period_start,schema_reference,section_count,
                 source_manifest_json,source_manifest_sha256,snapshot_ciphertext,
                 snapshot_fingerprint,request_fingerprint,idempotency_key_hash,created_by)
             VALUES (7,'test',31,42,11,21,'2026-08-01',
                     'payroll-jmhz-eldp-evidence.v1',1,'{}',REPEAT('d',64),
                     'enc:v2:synthetic',REPEAT('e',64),REPEAT('f',64),UNHEX(REPEAT('02',32)),1)",
        );

        self::assertSame(
            1,
            (int) $db->query('SELECT COUNT(*) FROM payroll_jmhz_ordinary_evidence_snapshots')->fetchColumn(),
        );
        self::assertSame(
            1,
            (int) $db->query('SELECT COUNT(*) FROM payroll_jmhz_eldp_evidence_snapshots')->fetchColumn(),
        );

        $db->exec(
            "DELETE FROM migrations
              WHERE filename IN (
                '1543_payroll_jmhz_ordinary_evidence_correction_revision.sql',
                '1544_payroll_jmhz_eldp_evidence_correction_revision.sql'
              )",
        );
        $this->runMigrator('1543_payroll_jmhz_ordinary_evidence_correction_revision.sql');
        $this->runMigrator('1544_payroll_jmhz_eldp_evidence_correction_revision.sql');
        self::assertSame(
            1,
            (int) $db->query('SELECT COUNT(*) FROM payroll_jmhz_ordinary_evidence_snapshots')->fetchColumn(),
        );
        self::assertSame(
            1,
            (int) $db->query('SELECT COUNT(*) FROM payroll_jmhz_eldp_evidence_snapshots')->fetchColumn(),
        );
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

    private function runMigrator(string $filename): void
    {
        $command = [PHP_BINARY, $this->rootDir . '/api/bin/migrate.php', '--no-backfills', "--only={$filename}"];
        $environment = getenv();
        $environment['MYINVOICE_DB_NAME'] = $this->database;
        $environment['MYSQL_DATABASE'] = $this->database;
        $pipes = [];
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->rootDir,
            $environment,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), "Migrátor selhal.\n{$stdout}\n{$stderr}");
    }
}
