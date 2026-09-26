<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PayrollStereoSourceJmhzMigrationRuntimeTest extends TestCase
{
    public function testRenamedStereoMigrationCleansOnlyOldMarkers(): void
    {
        $connection = Bootstrap::buildContainer()->get(Connection::class);
        $db = $connection->pdo();
        self::assertStringEndsWith('_test', (string) $db->query('SELECT DATABASE()')->fetchColumn());

        $db->exec('CREATE TEMPORARY TABLE migrations (filename VARCHAR(255) PRIMARY KEY) ENGINE=InnoDB');
        try {
            $filenames = [
                '1867_payroll_migration_stereo_nx_source.sql',
                '1893_payroll_migration_stereo_nx_source.sql',
                '1900_payroll_migration_stereo_nx_source.sql',
                '1892_journal_red_storno.sql',
                '1900_invoice_rounding_mode.sql',
                'unrelated_migration.sql',
            ];
            $insert = $db->prepare('INSERT INTO migrations (filename) VALUES (?)');
            foreach ($filenames as $filename) $insert->execute([$filename]);

            $migration = file_get_contents(dirname(__DIR__, 4) . '/db/migrations/1901_payroll_migration_stereo_nx_source.sql');
            self::assertIsString($migration);
            self::assertSame(1, preg_match('/DELETE FROM migrations WHERE filename IN \([^;]+\);/s', $migration, $match));
            $db->exec($match[0]);
            $db->exec($match[0]);

            self::assertSame(
                ['1900_invoice_rounding_mode.sql', 'unrelated_migration.sql'],
                $db->query('SELECT filename FROM migrations ORDER BY filename')->fetchAll(PDO::FETCH_COLUMN),
            );
        } finally {
            $db->exec('DROP TEMPORARY TABLE IF EXISTS migrations');
            $connection->close();
        }
    }

    public function testJmhzMigrationKeepsExistingStereoSourceUntilFinalSourceMigration(): void
    {
        $connection = Bootstrap::buildContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $db = $connection->pdo();
        self::assertStringEndsWith('_test', (string) $db->query('SELECT DATABASE()')->fetchColumn());

        $oldMode = (string) $db->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $db->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        try {
            // The experimental Stereo branch may have stored rows before master 1892 runs.
            $db->exec("CREATE TEMPORARY TABLE tmp_stereo_source_1892 (
                id INT PRIMARY KEY,
                source ENUM('pamica','pohoda','money_s3','other','stereo_nx') NOT NULL
            ) ENGINE=InnoDB");
            $db->exec("INSERT INTO tmp_stereo_source_1892 (id, source) VALUES (1, 'stereo_nx'), (2, 'other')");

            $this->applySourceAlter($db, '1892_payroll_takeover_source_jmhz.sql');
            $db->exec("INSERT INTO tmp_stereo_source_1892 (id, source) VALUES (3, 'jmhz')");
            $this->assertSourcesPreserved($db);

            // Replaying 1892 and then applying the final Stereo migration must be safe.
            $this->applySourceAlter($db, '1892_payroll_takeover_source_jmhz.sql');
            $this->applySourceAlter($db, '1901_payroll_migration_stereo_nx_source.sql');
            $this->applySourceAlter($db, '1901_payroll_migration_stereo_nx_source.sql');
            $this->assertSourcesPreserved($db);
        } finally {
            $db->exec('DROP TEMPORARY TABLE IF EXISTS tmp_stereo_source_1892');
            $db->prepare('SET SESSION sql_mode = ?')->execute([$oldMode]);
            $connection->close();
        }
    }

    private function applySourceAlter(PDO $db, string $filename): void
    {
        $migration = file_get_contents(dirname(__DIR__, 4) . '/db/migrations/' . $filename);
        self::assertIsString($migration);
        self::assertSame(1, preg_match(
            '/ALTER TABLE payroll_migration_reference_totals\s+MODIFY COLUMN source ENUM\([^;]+\) NOT NULL;/s',
            $migration,
            $match,
        ), $filename . ' must contain a source ENUM migration.');
        $sql = str_replace('payroll_migration_reference_totals', 'tmp_stereo_source_1892', $match[0]);
        $db->exec($sql);
    }

    private function assertSourcesPreserved(PDO $db): void
    {
        self::assertSame(
            ['stereo_nx', 'other', 'jmhz'],
            $db->query('SELECT source FROM tmp_stereo_source_1892 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
        );
    }
}
