<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use MyInvoice\Service\Anonymization\DatabaseCloner;
use MyInvoice\Tests\Support\CloneSandbox;
use MyInvoice\Tests\Support\OwnedTestDatabaseCleanup;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Znovu použitá databáze musí být k nerozeznání od čerstvé kopie přes
 * {@see DatabaseCloner}: tabulky, obsah a AUTO_INCREMENT, s triggery i bez nich.
 * Obnova musí zvládnout tabulku, jejíž trigger by původní řádky znovu nevložil,
 * verzovanou tabulku s historií i změněnou strukturu, kterou neopravuje, ale
 * databázi založí znovu.
 *
 * Zdrojem je malá syntetická databáze se stejnými druhy objektů jako testovací DB
 * (cizí klíč, generovaný sloupec, verzovaná tabulka, tabulka s triggerem odmítajícím
 * INSERT, pohled, funkce). Pomocník pracuje s každou tabulkou a objektem stejně, takže
 * na počtu tabulek nezáleží; kopie celé testovací DB (775 tabulek) stála přes minutu.
 */
#[Group('integration')]
final class CloneSandboxTest extends TestCase
{
    private PDO $server;

    private string $source = '';

    /** @var list<string> */
    private array $leased = [];

    /** @var list<string> */
    private array $fresh = [];

    protected function setUp(): void
    {
        $config = Config::load(Bootstrap::rootDir());
        self::assertStringEndsWith('_test', (string) $config->get('db.name'));
        self::assertContains($config->get('db.host'), ['localhost', '127.0.0.1', '::1']);
        $this->server = new PDO('mysql:host=' . $config->get('db.host') . ';port=' . $config->get('db.port', 3306) . ';charset=utf8mb4',
            (string) $config->get('db.user'), (string) $config->get('db.pass', ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->source = $this->syntheticSource();
    }

    protected function tearDown(): void
    {
        foreach ($this->leased as $schema) {
            if (CloneSandbox::isLeased($schema)) {
                CloneSandbox::release($this->server, $schema);
            }
        }
        // dropAll() ukončí spojení stojící v zahazovaných databázích.
        $this->server->exec('USE ' . DatabaseCloner::quote((string) Config::load(Bootstrap::rootDir())->get('db.name')));
        CloneSandbox::dropAll();
        foreach ($this->fresh as $schema) {
            OwnedTestDatabaseCleanup::drop($this->server, $schema, $this->fresh);
        }
    }

    public function testReusedDatabaseMatchesFreshCopyWithAndWithoutTriggers(): void
    {
        $base = $this->source;
        $full = $this->fresh($base, true);
        $tables = $this->fresh($base, false);
        $expectedFull = $this->state($full);
        $expectedTables = $this->state($tables);
        self::assertNotSame($expectedFull['objects'], $expectedTables['objects']);

        $first = $this->acquire($base, true);
        self::assertSame($first, $this->server->query('SELECT DATABASE()')->fetchColumn());
        self::assertSame(0, (int) $this->server->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn());
        self::assertEquals($expectedFull, $this->state($first));

        $protected = 'payroll_jmhz_codebooks';
        self::assertGreaterThan(0, (int) $this->server->query('SELECT COUNT(*) FROM ' . $protected)->fetchColumn());
        self::assertTrue($this->hasInsertTrigger($protected));
        $this->server->exec('TRUNCATE TABLE ' . $protected);
        $supplier = (int) $this->server->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->server->prepare('UPDATE supplier SET company_name = ? WHERE id = ?')->execute(['Synthetic changed supplier', $supplier]);
        $journal = 'SELECT COUNT(*) FROM journal_entries FOR SYSTEM_TIME ALL';
        $versions = (int) $this->server->query($journal)->fetchColumn();
        $this->server->prepare("INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, description) VALUES (?, 0, '2026-01-15', 'manual', 'Synthetic sandbox entry')")->execute([$supplier]);
        $this->server->exec("UPDATE journal_entries SET description = 'Synthetic changed entry' WHERE description = 'Synthetic sandbox entry'");
        self::assertSame($versions + 2, (int) $this->server->query($journal)->fetchColumn());
        $counter = array_key_first($expectedFull['increments']);
        $this->server->exec('ALTER TABLE ' . DatabaseCloner::quote($counter) . ' AUTO_INCREMENT = ' . ($expectedFull['increments'][$counter] + 100));
        self::assertNotEquals($expectedFull, $this->state($first));
        $this->release($first);

        $writes = CloneSandbox::$writes;
        $second = $this->acquire($base, true);
        self::assertSame($first, $second, 'Změněný obsah se obnoví ve stejné databázi.');
        self::assertSame($writes + 1, CloneSandbox::$writes);
        self::assertEquals($expectedFull, $this->state($second));
        self::assertTrue($this->hasInsertTrigger($protected));
        self::assertSame($versions, (int) $this->server->query($journal)->fetchColumn(), 'Verzovaná tabulka se obnoví bez historie.');
        $this->release($second);

        $writes = CloneSandbox::$writes;
        $same = $this->acquire($base, true);
        self::assertSame($first, $same);
        self::assertSame($writes, CloneSandbox::$writes, 'Nezměněnou databázi není co obnovovat.');

        // Zahozený trigger se vrátí: pohledy, triggery a rutiny se založí znovu celé.
        $trigger = $this->server->query("SHOW TRIGGERS LIKE 'payroll_jmhz_codebooks'")->fetch(PDO::FETCH_ASSOC);
        $this->server->exec('DROP TRIGGER ' . DatabaseCloner::quote((string) $trigger['Trigger']));
        $this->release($same);
        $restored = $this->acquire($base, true);
        self::assertSame($first, $restored);
        self::assertEquals($expectedFull, $this->state($restored));
        $this->release($restored);

        $withoutTriggers = $this->acquire($base, false);
        self::assertNotSame($first, $withoutTriggers, 'Databáze s triggery a bez nich se nemíchají.');
        self::assertFalse(TriggerMetadata::any($this->server));
        self::assertEquals($expectedTables, $this->state($withoutTriggers));

        // Zdrojem může být i jiná zapůjčená databáze, třeba s fixturou testu.
        $this->server->prepare('UPDATE supplier SET company_name = ? WHERE id = ?')->execute(['Synthetic fixture supplier', $supplier]);
        $copy = $this->acquire($withoutTriggers, false);
        self::assertNotSame($withoutTriggers, $copy);
        self::assertSame('Synthetic fixture supplier', $this->server->query('SELECT company_name FROM supplier WHERE id = ' . $supplier)->fetchColumn());
        self::assertEquals($this->state($withoutTriggers), $this->state($copy));
        $this->release($copy);
        $this->release($withoutTriggers);

        $back = $this->acquire($base, false);
        self::assertSame($withoutTriggers, $back);
        self::assertEquals($expectedTables, $this->state($back));

        $this->server->exec('ALTER TABLE supplier ADD COLUMN synthetic_drift INT NULL');
        $this->release($back);
        $rebuilt = $this->acquire($base, false);
        self::assertSame($copy, $rebuilt, 'Změněnou strukturu databáze neopravuje, vezme jinou.');
        $exists = $this->server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $exists->execute([$back]);
        self::assertSame(0, (int) $exists->fetchColumn(), 'Databáze se změněnou strukturou se zahodí.');
        self::assertEquals($expectedTables, $this->state($rebuilt));
    }

    public function testDirtyTableRestorePreservesStrictTriggerSemanticsAndSessionMode(): void
    {
        $session = (string) $this->server->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        try {
            $this->addStrictCastSource();
            $this->server->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
            $first = $this->acquire($this->source, true);
            $this->assertStrictCastRejected();
            $this->server->exec("UPDATE sandbox_labels SET label = '8' WHERE id = 1");
            $this->release($first);

            $restored = $this->acquire($this->source, true);
            self::assertSame($first, $restored, 'Obnova musí zachovat stejnou zapůjčenou databázi.');
            self::assertSame('NO_ENGINE_SUBSTITUTION', $this->server->query('SELECT @@SESSION.sql_mode')->fetchColumn());
            self::assertSame('7', $this->server->query('SELECT label FROM sandbox_labels WHERE id = 1')->fetchColumn());
            self::assertSame(7, (int) $this->server->query('SELECT numeric_value FROM sandbox_numbers WHERE id = 1')->fetchColumn());
            $this->assertStrictCastRejected();
        } finally {
            if ($this->server->inTransaction()) $this->server->rollBack();
            $this->server->prepare('SET SESSION sql_mode = ?')->execute([$session]);
        }
    }

    public function testSameTriggerBodyWithDifferentSqlModeIsRestoredToStrictSemantics(): void
    {
        $session = (string) $this->server->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        try {
            $this->addStrictCastSource();
            $sourceTrigger = $this->castTriggerMetadata($this->source);
            $this->server->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
            $first = $this->acquire($this->source, true);
            $this->server->exec('DROP TRIGGER trg_sandbox_strict_cast');
            $this->createCastTrigger();
            $changedTrigger = $this->castTriggerMetadata($first);
            self::assertSame($sourceTrigger['ACTION_STATEMENT'], $changedTrigger['ACTION_STATEMENT']);
            self::assertNotSame($sourceTrigger['SQL_MODE'], $changedTrigger['SQL_MODE']);
            $this->server->beginTransaction();
            $this->server->exec("INSERT INTO sandbox_labels (id, label) VALUES (2, 'not-a-number')");
            self::assertSame(0, (int) $this->server->query('SELECT numeric_value FROM sandbox_numbers WHERE id = 2')->fetchColumn());
            $this->server->rollBack();
            $this->release($first);

            $restored = $this->acquire($this->source, true);
            self::assertSame($first, $restored, 'Samotný režim triggeru se musí obnovit bez nové databáze.');
            self::assertSame('NO_ENGINE_SUBSTITUTION', $this->server->query('SELECT @@SESSION.sql_mode')->fetchColumn());
            $this->assertStrictCastRejected();
            self::assertSame($sourceTrigger['SQL_MODE'], $this->castTriggerMetadata($restored)['SQL_MODE']);
        } finally {
            if ($this->server->inTransaction()) $this->server->rollBack();
            $this->server->prepare('SET SESSION sql_mode = ?')->execute([$session]);
        }
    }

    private function addStrictCastSource(): void
    {
        $this->server->exec('USE ' . DatabaseCloner::quote($this->source));
        $this->server->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $this->server->exec('CREATE TABLE sandbox_labels (id INT PRIMARY KEY, label VARCHAR(32) NOT NULL) ENGINE=InnoDB');
        $this->server->exec('CREATE TABLE sandbox_numbers (id INT PRIMARY KEY, numeric_value INT NOT NULL) ENGINE=InnoDB');
        $this->createCastTrigger();
        $this->server->exec("INSERT INTO sandbox_labels (id, label) VALUES (1, '7')");
    }

    private function createCastTrigger(): void
    {
        $this->server->exec('CREATE TRIGGER trg_sandbox_strict_cast AFTER INSERT ON sandbox_labels FOR EACH ROW '
            . 'INSERT INTO sandbox_numbers (id, numeric_value) VALUES (NEW.id, NEW.label)');
    }

    private function castTriggerMetadata(string $schema): array
    {
        foreach (TriggerMetadata::read($this->server, $schema) as $trigger) {
            if ($trigger['TRIGGER_NAME'] === 'trg_sandbox_strict_cast') return $trigger;
        }
        self::fail('Syntetický trigger pro ověření režimu SQL chybí.');
    }

    private function assertStrictCastRejected(): void
    {
        try {
            $this->server->exec("INSERT INTO sandbox_labels (id, label) VALUES (2, 'not-a-number')");
            self::fail('Převod textu na INT musí trigger ve striktním režimu odmítnout.');
        } catch (\PDOException $error) {
            self::assertSame(1366, (int) ($error->errorInfo[1] ?? 0));
        }
        self::assertSame([1], array_map('intval', $this->server->query('SELECT id FROM sandbox_labels ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame([7], array_map('intval', $this->server->query('SELECT numeric_value FROM sandbox_numbers ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function syntheticSource(): string
    {
        $schema = 'myucto_sandboxsrc_' . bin2hex(random_bytes(5)) . '_test';
        $this->fresh[] = $schema;
        $this->server->exec('CREATE DATABASE ' . DatabaseCloner::quote($schema) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->server->exec('USE ' . DatabaseCloner::quote($schema));
        foreach ([
            'CREATE TABLE supplier (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, company_name VARCHAR(190) NOT NULL,
                name_upper VARCHAR(190) AS (UPPER(company_name)) VIRTUAL) ENGINE=InnoDB',
            "INSERT INTO supplier (company_name) VALUES ('Synthetic supplier A'), ('Synthetic supplier B')",
            'CREATE TABLE journal_entries (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, supplier_id INT UNSIGNED NOT NULL,
                period_id INT UNSIGNED NOT NULL, entry_date DATE NOT NULL, source_type VARCHAR(32) NOT NULL, description VARCHAR(255) NULL,
                CONSTRAINT fk_sandbox_journal_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id)) ENGINE=InnoDB WITH SYSTEM VERSIONING',
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, description)
                VALUES (1, 0, '2026-01-02', 'manual', 'Synthetic entry 1'), (2, 0, '2026-01-03', 'manual', 'Synthetic entry 2')",
            "UPDATE journal_entries SET description = 'Synthetic entry 1 revised' WHERE id = 1",
            'CREATE TABLE payroll_jmhz_codebooks (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, code VARCHAR(32) NOT NULL UNIQUE) ENGINE=InnoDB',
            "INSERT INTO payroll_jmhz_codebooks (code) VALUES ('SYN-A'), ('SYN-B'), ('SYN-C')",
            "CREATE TRIGGER trg_sandbox_codebook_insert BEFORE INSERT ON payroll_jmhz_codebooks FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic codebooks are sealed'",
            "CREATE TRIGGER trg_sandbox_codebook_update BEFORE UPDATE ON payroll_jmhz_codebooks FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic codebooks are immutable'",
            "CREATE TRIGGER trg_sandbox_journal_update BEFORE UPDATE ON journal_entries FOR EACH ROW
                BEGIN IF OLD.source_type = 'payroll' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic payroll entries are immutable'; END IF; END",
            "SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',ALLOW_INVALID_DATES')",
            'CREATE PROCEDURE sp_sandbox_count_entries() SELECT COUNT(*) FROM journal_entries',
            'SET SESSION sql_mode = DEFAULT',
        ] as $statement) {
            $this->server->exec($statement);
        }

        return $schema;
    }

    private function acquire(string $source, bool $triggers): string
    {
        $schema = CloneSandbox::acquire($this->server, $source, $triggers);
        $this->leased[] = $schema;

        return $schema;
    }

    private function release(string $schema): void
    {
        CloneSandbox::release($this->server, $schema);
    }

    private function fresh(string $base, bool $triggers): string
    {
        $schema = 'myucto_sandbox_fresh_' . bin2hex(random_bytes(5)) . '_test';
        $this->fresh[] = $schema;
        $cloner = new DatabaseCloner($this->server);
        $plan = $cloner->plan($base);
        $cloner->copyTables($plan, $schema, static function (): void {});
        if ($triggers) {
            $cloner->finalize($plan, $schema);
        }

        return $schema;
    }

    private function hasInsertTrigger(string $table): bool
    {
        foreach ($this->server->query('SHOW TRIGGERS LIKE ' . $this->server->quote($table))->fetchAll(PDO::FETCH_ASSOC) as $trigger) {
            if ($trigger['Event'] === 'INSERT') {
                return true;
            }
        }

        return false;
    }

    /** @return array{tables:string, objects:string, rows:array<string,string>, increments:array<string,int>} */
    private function state(string $database): array
    {
        $state = (new \ReflectionMethod(CloneSandbox::class, 'state'))->invoke(null, $this->server, $database, true);
        unset($state['layout']);

        return $state;
    }
}
