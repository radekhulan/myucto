<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Infrastructure\Database;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\SchemaMetadata;
use PDO;
use PHPUnit\Framework\TestCase;

final class SchemaMetadataTest extends TestCase
{
    private Connection $db;
    private SchemaMetadata $schema;
    private string $table;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $this->schema = new SchemaMetadata($this->db);
        $this->table = 'schema_metadata_probe_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec("DROP TABLE IF EXISTS `{$this->table}`");
        $pdo->prepare('DELETE FROM migrations WHERE filename = ?')->execute(["9999_{$this->table}.sql"]);
    }

    public function testMatchesInformationSchemaQueries(): void
    {
        $pdo = $this->db->pdo();
        $expected = $pdo->query("SELECT c.TABLE_NAME FROM information_schema.COLUMNS c
               JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
              WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'supplier_id' AND t.TABLE_TYPE IN ('BASE TABLE', 'SYSTEM VERSIONED') ORDER BY c.TABLE_NAME")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotSame([], $expected);
        self::assertSame($expected, $this->schema->tablesWithColumn('supplier_id'));

        $expected = $pdo->query("SELECT DISTINCT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL")
            ->fetchAll(PDO::FETCH_NUM);
        $pairs = [];
        foreach ($this->schema->localForeignKeys() as $fk) {
            $pairs[$fk['TABLE_NAME'] . "\0" . $fk['REFERENCED_TABLE_NAME']] ??= [$fk['TABLE_NAME'], $fk['REFERENCED_TABLE_NAME']];
        }
        self::assertSame($expected, array_values($pairs));

        $expected = $pdo->query("SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME
               FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                 ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
              WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_SCHEMA = DATABASE() AND r.DELETE_RULE IN ('RESTRICT', 'NO ACTION')
                AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS c
                                 WHERE c.TABLE_SCHEMA = k.TABLE_SCHEMA AND c.TABLE_NAME = k.TABLE_NAME AND c.COLUMN_NAME = 'supplier_id')
              ORDER BY k.TABLE_NAME, k.COLUMN_NAME")->fetchAll(PDO::FETCH_NUM);
        $rows = array_values(array_filter($this->schema->localForeignKeys(), fn (array $fk): bool
            => in_array($fk['DELETE_RULE'], ['RESTRICT', 'NO ACTION'], true) && !$this->schema->hasColumn($fk['TABLE_NAME'], 'supplier_id')));
        usort($rows, static fn (array $a, array $b): int => SchemaMetadata::compareNames($a['TABLE_NAME'], $b['TABLE_NAME'])
            ?: SchemaMetadata::compareNames($a['COLUMN_NAME'], $b['COLUMN_NAME']));
        $actual = array_map(static fn (array $r): array => [$r['TABLE_NAME'], $r['COLUMN_NAME'], $r['REFERENCED_TABLE_NAME'], $r['REFERENCED_COLUMN_NAME']], $rows);
        self::assertSame(self::sortedTies($expected), self::sortedTies($actual));

        $rules = $pdo->query('SELECT TABLE_NAME, CONSTRAINT_NAME, DELETE_RULE, UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        $byName = [];
        foreach ($rules as $rule) {
            $byName[$rule['TABLE_NAME'] . "\0" . $rule['CONSTRAINT_NAME']] = [$rule['DELETE_RULE'], $rule['UPDATE_RULE']];
        }
        foreach ($this->schema->foreignKeys() as $fk) {
            self::assertSame($byName[$fk['TABLE_NAME'] . "\0" . $fk['CONSTRAINT_NAME']], [$fk['DELETE_RULE'], $fk['UPDATE_RULE']]);
        }
    }

    public function testDdlAndMigrationInvalidateMetadata(): void
    {
        $pdo = $this->db->pdo();
        self::assertNotContains($this->table, $this->schema->tablesWithColumn('supplier_id'));
        $triggers = count($this->schema->triggers());

        $pdo->exec("CREATE TABLE `{$this->table}` (id INT PRIMARY KEY, supplier_id INT UNSIGNED NULL)");
        self::assertContains($this->table, $this->schema->tablesWithColumn('supplier_id'));
        $pdo->exec("CREATE TRIGGER `{$this->table}_bd` BEFORE DELETE ON `{$this->table}` FOR EACH ROW SET @schema_metadata_probe = OLD.id");
        self::assertCount($triggers + 1, $this->schema->triggers());
        $pdo->exec("DROP TABLE `{$this->table}`");
        self::assertNotContains($this->table, $this->schema->tablesWithColumn('supplier_id'));
        self::assertCount($triggers, $this->schema->triggers());

        $config = Bootstrap::buildContainer()->get(\MyInvoice\Infrastructure\Config\Config::class);
        $raw = new PDO('mysql:host=' . $config->get('db.host', '127.0.0.1') . ';port=' . (int) $config->get('db.port', 3306)
            . ';dbname=' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . ';charset=utf8mb4',
            (string) $config->get('db.user'), (string) $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $raw->exec("CREATE TABLE `{$this->table}` (id INT PRIMARY KEY, supplier_id INT UNSIGNED NULL)");
        $raw->prepare('INSERT INTO migrations (filename) VALUES (?)')->execute(["9999_{$this->table}.sql"]);
        self::assertContains($this->table, $this->schema->tablesWithColumn('supplier_id'));
    }

    private static function sortedTies(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row[0] . "\0" . $row[1]][] = $row;
        }
        foreach ($groups as &$group) {
            sort($group);
        }
        unset($group);
        return $groups;
    }
}
