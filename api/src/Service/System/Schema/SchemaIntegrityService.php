<?php

declare(strict_types=1);

namespace MyInvoice\Service\System\Schema;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Export\Instance\InstanceRestoreTriggers;
use PDO;

/**
 * Kontrola struktury databáze proti migracím.
 *
 * `migrations_pending` říká jen, jestli každá migrace PROBĚHLA. Neřekne, jestli
 * struktura, kterou měla vytvořit, v databázi pořád je a vypadá tak, jak má:
 * dump obnovený bez triggerů, databáze nahraná ze starší verze, ruční ALTER,
 * migrace upravená po nasazení nebo tabulka, které chybí SYSTEM VERSIONING.
 * To všechno projde jako „migrace v pořádku".
 *
 * Porovnává se živá databáze s referenčním otiskem `db/schema.snapshot.json`,
 * který vzniká z čisté databáze postavené výhradně z migrací a ke kterému CI
 * hlídá, že odpovídá aktuální řadě migrací.
 *
 * Závažnost:
 *   - fail — chybí něco, na čem stojí správnost dat: tabulka, sloupec, jiný typ
 *     nebo NULL, unikátní index, cizí klíč, CHECK, trigger, rutina, auditní historie,
 *   - warn — odchylka, která funkci nebere: collation, výchozí hodnota, engine,
 *     neunikátní index, jiné tělo triggeru, objekty navíc.
 */
final class SchemaIntegrityService
{
    public const STATUS_OK   = 'ok';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';
    public const STATUS_SKIP = 'skip';

    /** Tabulky, které aplikace zakládá za běhu mimo migrace. */
    private const RUNTIME_TABLES = [InstanceRestoreTriggers::RECOVERY_TABLE];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $rootDir,
    ) {}

    public static function forConnection(Connection $db): self
    {
        return new self($db->pdo(), Bootstrap::rootDir());
    }

    public function snapshotPath(): string
    {
        return $this->rootDir . '/' . SchemaSnapshot::RELATIVE_PATH;
    }

    /** @return list<string> */
    public function migrationFiles(): array
    {
        $files = array_map('basename', glob($this->rootDir . '/db/migrations/*.sql') ?: []);
        sort($files, SORT_STRING);
        return $files;
    }

    /** @return list<string>|null null = tabulka migrations chybí */
    public function appliedMigrations(): ?array
    {
        try {
            $applied = $this->pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable) {
            return null;
        }
        $applied = array_map('strval', $applied);
        sort($applied, SORT_STRING);
        return $applied;
    }

    /**
     * @return array{
     *     status:string,
     *     reason:string,
     *     counts:array{fail:int,warn:int},
     *     findings:list<array{severity:string,code:string,object:string,expected:string,actual:string}>
     * }
     */
    public function report(): array
    {
        $skip = static fn (string $reason): array => [
            'status' => self::STATUS_SKIP, 'reason' => $reason, 'counts' => ['fail' => 0, 'warn' => 0], 'findings' => [],
        ];

        $snapshot = SchemaSnapshot::load($this->snapshotPath());
        if ($snapshot === null) {
            return $skip('snapshot_missing');
        }
        $applied = $this->appliedMigrations();
        if ($applied === null) {
            return $skip('migrations_table_missing');
        }
        $files = $this->migrationFiles();
        if ($snapshot['migrations'] !== $files) {
            return $skip('snapshot_outdated');
        }
        if (array_diff($files, $applied) !== []) {
            // Rozdíl by byl jen seznam toho, co nespuštěná migrace teprve přinese —
            // to hlásí `migrations_pending` a srozumitelněji.
            return $skip('migrations_pending');
        }

        $findings = [];
        foreach (array_diff($applied, $files) as $unknown) {
            $findings[] = self::finding(self::STATUS_WARN, 'migration_unknown', $unknown, '', 'applied');
        }
        $findings = array_merge($findings, self::compare($snapshot, SchemaSnapshot::capture($this->pdo, $files)));

        return self::summarize($findings);
    }

    /**
     * @param list<array{severity:string,code:string,object:string,expected:string,actual:string}> $findings
     * @return array{status:string,reason:string,counts:array{fail:int,warn:int},findings:list<array{severity:string,code:string,object:string,expected:string,actual:string}>}
     */
    public static function summarize(array $findings): array
    {
        usort($findings, static fn (array $a, array $b): int =>
            [$a['severity'] === self::STATUS_FAIL ? 0 : 1, $a['object'], $a['code']]
            <=> [$b['severity'] === self::STATUS_FAIL ? 0 : 1, $b['object'], $b['code']]);

        $counts = ['fail' => 0, 'warn' => 0];
        foreach ($findings as $finding) {
            $counts[$finding['severity']]++;
        }

        return [
            'status'   => $counts['fail'] > 0 ? self::STATUS_FAIL : ($counts['warn'] > 0 ? self::STATUS_WARN : self::STATUS_OK),
            'reason'   => '',
            'counts'   => $counts,
            'findings' => $findings,
        ];
    }

    /**
     * Porovná referenční otisk se zachycenou databází. Čistá funkce — žádná DB.
     *
     * @param array<string,mixed> $expected
     * @param array<string,mixed> $actual
     * @return list<array{severity:string,code:string,object:string,expected:string,actual:string}>
     */
    public static function compare(array $expected, array $actual): array
    {
        $findings = [];

        $expectedDbCollation = (string) ($expected['database_collation'] ?? '');
        $actualDbCollation = (string) ($actual['database_collation'] ?? '');
        if ($expectedDbCollation !== '' && $expectedDbCollation !== $actualDbCollation) {
            $findings[] = self::finding(self::STATUS_WARN, 'database_collation', '(databáze)', $expectedDbCollation, $actualDbCollation);
        }

        $expectedTables = (array) ($expected['tables'] ?? []);
        $actualTables = (array) ($actual['tables'] ?? []);

        foreach ($expectedTables as $name => $want) {
            $name = (string) $name;
            $have = $actualTables[$name] ?? null;
            if (!is_array($have)) {
                $findings[] = self::finding(self::STATUS_FAIL, 'table_missing', $name, (string) $want['type'], '');
                continue;
            }
            if ($want['type'] !== $have['type']) {
                $findings[] = self::finding(self::STATUS_FAIL, 'table_type', $name, (string) $want['type'], (string) $have['type']);
            }
            if ($want['engine'] !== $have['engine']) {
                $findings[] = self::finding(self::STATUS_WARN, 'table_engine', $name, (string) $want['engine'], (string) $have['engine']);
            }
            if ($want['collation'] !== $have['collation']) {
                $findings[] = self::finding(self::STATUS_WARN, 'table_collation', $name, (string) $want['collation'], (string) $have['collation']);
            }
            if (($want['definition'] ?? null) !== ($have['definition'] ?? null)) {
                $findings[] = self::finding(self::STATUS_WARN, 'view_changed', $name, (string) ($want['definition'] ?? ''), (string) ($have['definition'] ?? ''));
            }

            foreach ((array) $want['columns'] as $column => $definition) {
                $object = $name . '.' . $column;
                $current = $have['columns'][$column] ?? null;
                if ($current === null) {
                    $findings[] = self::finding(self::STATUS_FAIL, 'column_missing', $object, (string) $definition, '');
                } elseif ($current !== $definition) {
                    $core = self::columnCore((string) $definition) !== self::columnCore((string) $current);
                    $findings[] = self::finding(
                        $core ? self::STATUS_FAIL : self::STATUS_WARN,
                        $core ? 'column_changed' : 'column_attributes',
                        $object, (string) $definition, (string) $current,
                    );
                }
            }
            foreach (array_diff_key((array) $have['columns'], (array) $want['columns']) as $column => $definition) {
                $findings[] = self::finding(self::STATUS_WARN, 'column_extra', $name . '.' . $column, '', (string) $definition);
            }

            $findings = array_merge(
                $findings,
                self::diffNamed($name, 'index', (array) $want['indexes'], (array) $have['indexes'],
                    static fn (string $name, string $definition): bool => $name === 'PRIMARY' || str_starts_with($definition, 'UNIQUE ')),
                self::diffNamed($name, 'foreign_key', (array) $want['foreign_keys'], (array) $have['foreign_keys'],
                    static fn (): bool => true),
                self::diffNamed($name, 'check', (array) $want['checks'], (array) $have['checks'],
                    static fn (): bool => true, changedIsFail: false),
            );
        }

        foreach (array_diff_key($actualTables, $expectedTables) as $name => $have) {
            if (!in_array((string) $name, self::RUNTIME_TABLES, true)) {
                $findings[] = self::finding(self::STATUS_WARN, 'table_extra', (string) $name, '', (string) ($have['type'] ?? ''));
            }
        }

        $findings = array_merge(
            $findings,
            self::diffNamed('', 'trigger', (array) ($expected['triggers'] ?? []), (array) ($actual['triggers'] ?? []),
                static fn (): bool => true, changedIsFail: false),
            self::diffNamed('', 'routine', (array) ($expected['routines'] ?? []), (array) ($actual['routines'] ?? []),
                static fn (): bool => true, changedIsFail: false),
        );

        return $findings;
    }

    /**
     * Rozdíl dvou pojmenovaných sad (indexy, klíče, triggery…). Objekt, který v databázi
     * chybí pod svým jménem, ale se stejnou definicí existuje pod jiným, je jen
     * PŘEJMENOVANÝ — typicky automaticky pojmenovaný cizí klíč z jiného pořadí migrací.
     * Hlásí se jako varování, ne jako chybějící + přebývající dvojice.
     *
     * @param array<string,string> $want
     * @param array<string,string> $have
     * @param callable(string,string):bool $missingIsFail
     * @return list<array{severity:string,code:string,object:string,expected:string,actual:string}>
     */
    private static function diffNamed(
        string $table,
        string $kind,
        array $want,
        array $have,
        callable $missingIsFail,
        bool $changedIsFail = true,
    ): array {
        $findings = [];
        $prefix = $table !== '' ? $table . '.' : '';
        $extra = array_diff_key($have, $want);

        foreach ($want as $name => $definition) {
            $name = (string) $name;
            $definition = (string) $definition;
            if (array_key_exists($name, $have)) {
                if ($have[$name] !== $definition) {
                    $findings[] = self::finding(
                        $changedIsFail ? self::STATUS_FAIL : self::STATUS_WARN,
                        $kind . '_changed', $prefix . $name, $definition, (string) $have[$name],
                    );
                }
                continue;
            }
            $renamedTo = array_search($definition, $extra, true);
            if ($renamedTo !== false) {
                unset($extra[$renamedTo]);
                $findings[] = self::finding(self::STATUS_WARN, $kind . '_renamed', $prefix . $name, $name, (string) $renamedTo);
                continue;
            }
            $findings[] = self::finding(
                $missingIsFail($name, $definition) ? self::STATUS_FAIL : self::STATUS_WARN,
                $kind . '_missing', $prefix . $name, $definition, '',
            );
        }
        foreach ($extra as $name => $definition) {
            $findings[] = self::finding(self::STATUS_WARN, $kind . '_extra', $prefix . $name, '', (string) $definition);
        }

        return $findings;
    }

    /** Typ a NULL/NOT NULL — část definice sloupce, jejíž změna mění, co jde uložit. */
    private static function columnCore(string $definition): string
    {
        return preg_match('/^(.*?) (NOT NULL|NULL)(?: |$)/', $definition, $m) === 1 ? $m[1] . ' ' . $m[2] : $definition;
    }

    /** @return array{severity:string,code:string,object:string,expected:string,actual:string} */
    private static function finding(string $severity, string $code, string $object, string $expected, string $actual): array
    {
        return ['severity' => $severity, 'code' => $code, 'object' => $object, 'expected' => $expected, 'actual' => $actual];
    }
}
