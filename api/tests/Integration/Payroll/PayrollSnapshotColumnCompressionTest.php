<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Tests\Support\PayrollTestContainer;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Snímky mzdového běhu jsou ve sloupcích `COMPRESSED` (migrace 2289). Komprese
 * je pro aplikaci průhledná: čte se tentýž řetězec, který se zapsal, takže
 * otisk snímku sedí a kontrola `json_valid` dál odmítne neplatný JSON.
 */
#[Group('integration')]
final class PayrollSnapshotColumnCompressionTest extends TestCase
{
    private const COLUMNS = [
        'payroll_run_revisions' => ['input_snapshot_json', 'result_snapshot_json'],
        'payroll_statutory_results' => ['input_snapshot_json', 'result_snapshot_json'],
        'payroll_statutory_person_results' => ['input_snapshot_json', 'result_snapshot_json'],
        'payroll_statutory_relationship_results' => ['input_snapshot_json', 'result_snapshot_json'],
        'payroll_run_employments' => ['input_json', 'result_json'],
        'payroll_run_persons' => ['result_json'],
    ];

    private Connection $db;

    protected function setUp(): void
    {
        $container = PayrollTestContainer::build();
        $db = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
    }

    protected function tearDown(): void
    {
        $this->db->pdo()->exec('DROP TEMPORARY TABLE IF EXISTS tmp_compressed_snapshot');
    }

    public function testSnapshotColumnsAreCompressedAndKeepTheirChecks(): void
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $statement->execute([$table, $column]);
                self::assertStringContainsString('COMPRESSED', (string) $statement->fetchColumn(), "{$table}.{$column}");
            }
        }
    }

    public function testCompressedSnapshotReadsBackByteForByteWithTheSameHash(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TEMPORARY TABLE tmp_compressed_snapshot (
                id INT UNSIGNED PRIMARY KEY,
                snapshot_json LONGTEXT COMPRESSED NOT NULL CHECK (json_valid(snapshot_json))
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $people = [];
        for ($seq = 1; $seq <= 400; $seq++) {
            $people[] = [
                'employee' => ['id' => $seq, 'full_name' => sprintf('Syntetická Žluťoučká %03d', $seq)],
                'amount_minor' => 360_000 + $seq,
                'note' => "řádek {$seq}\t\"uvozovky\" \\ lomítko",
            ];
        }
        $json = CanonicalJson::encode(['schema_version' => 'test.v1', 'people' => $people]);
        $pdo->prepare('INSERT INTO tmp_compressed_snapshot (id, snapshot_json) VALUES (1, ?)')->execute([$json]);

        $row = $pdo->query('SELECT snapshot_json, LENGTH(snapshot_json) AS bytes FROM tmp_compressed_snapshot WHERE id = 1')
            ->fetch(PDO::FETCH_ASSOC);

        self::assertSame($json, $row['snapshot_json']);
        self::assertSame(strlen($json), (int) $row['bytes']);
        self::assertSame(hash('sha256', $json), hash('sha256', (string) $row['snapshot_json']));

        $this->expectException(\PDOException::class);
        $pdo->prepare('INSERT INTO tmp_compressed_snapshot (id, snapshot_json) VALUES (2, ?)')->execute(['{neplatný']);
    }
}
