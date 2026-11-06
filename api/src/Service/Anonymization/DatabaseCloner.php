<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Infrastructure\Database\TriggerMetadata;
use PDO;

/**
 * Kopie databáze na témž serveru bez externích nástrojů (Linux, Windows, Docker).
 *
 * Kopíruje se ve dvou krocích: {@see copyTables()} založí tabulky a přelije data,
 * {@see finalize()} teprve potom přidá pohledy, triggery a uložené rutiny. Mezi
 * nimi běží pseudonymizace — triggery chránící neměnné mzdové a účetní záznamy by
 * jinak přepis osobních údajů v kopii odmítly.
 *
 * U systémově verzovaných tabulek se přenáší jen aktuální stav, historie ne.
 */
final class DatabaseCloner
{
    public function __construct(private readonly PDO $pdo) {}

    public static function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @return array{
     *   source:string, charset:string, collation:string,
     *   tables: array<string, array{ddl:string, columns:string}>,
     *   system_versioned: list<string>, views: list<string>,
     *   triggers: list<array{table:string, ddl:string, sql_mode:string}>, routines: list<string>,
     *   routine_sql_modes: list<string>
     * }
     */
    public function plan(string $source): array
    {
        $schema = $this->pdo->prepare('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $schema->execute([$source]);
        $row = $schema->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException("Zdrojová databáze {$source} neexistuje.");
        }
        $plan = [
            'source' => $source,
            'charset' => (string) $row['DEFAULT_CHARACTER_SET_NAME'],
            'collation' => (string) $row['DEFAULT_COLLATION_NAME'],
            'tables' => [],
            'system_versioned' => [],
            'views' => [],
            'triggers' => [],
            'routines' => [],
            'routine_sql_modes' => [],
        ];

        $baseTables = [];
        foreach ($this->tableTypes($source) as $name => $type) {
            if ($type === 'VIEW') {
                $plan['views'][] = $this->showCreate('VIEW', $source, $name);
                continue;
            }
            $baseTables[] = $name;
            if ($type === 'SYSTEM VERSIONED') {
                $plan['system_versioned'][] = $name;
            }
        }

        $columns = $this->pdo->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND UPPER(EXTRA) NOT LIKE '%GENERATED%'
              ORDER BY TABLE_NAME, ORDINAL_POSITION",
        );
        $columns->execute([$source]);
        $byTable = [];
        foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $byTable[(string) $column['TABLE_NAME']][] = self::quote((string) $column['COLUMN_NAME']);
        }
        foreach ($baseTables as $name) {
            if (empty($byTable[$name])) {
                throw new \RuntimeException("Tabulka {$name} nemá kopírovatelné sloupce.");
            }
            $plan['tables'][$name] = [
                'ddl' => $this->showCreate('TABLE', $source, $name),
                'columns' => implode(', ', $byTable[$name]),
            ];
            // MariaDB 11.8 hlásí v SHOW FULL TABLES verzovanou tabulku jako BASE TABLE.
            // Verzování proto prozradí až definice. Bez toho by kopie po pseudonymizaci
            // nesla původní hodnoty v historii řádků.
            if (!in_array($name, $plan['system_versioned'], true)
                && preg_match('/\bWITH SYSTEM VERSIONING\b/i', $plan['tables'][$name]['ddl']) === 1) {
                $plan['system_versioned'][] = $name;
            }
        }

        foreach (TriggerMetadata::read($this->pdo, $source) as $trigger) {
            $plan['triggers'][] = [
                'table' => (string) $trigger['EVENT_OBJECT_TABLE'],
                'ddl' => $this->showCreate('TRIGGER', $source, (string) $trigger['TRIGGER_NAME']),
                'sql_mode' => (string) $trigger['SQL_MODE'],
            ];
        }

        $routines = $this->pdo->prepare('SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_TYPE, ROUTINE_NAME');
        $routines->execute([$source]);
        foreach ($routines->fetchAll(PDO::FETCH_ASSOC) as $routine) {
            // sql_mode z SHOW CREATE: information_schema.ROUTINES v MariaDB 11.8 vypisuje
            // ALLOW_INVALID_DATES jako INVALID_DATES a tuhle hodnotu server pak nepřijme.
            $row = $this->showCreateRow((string) $routine['ROUTINE_TYPE'], $source, (string) $routine['ROUTINE_NAME']);
            $plan['routines'][] = self::createStatement($row, (string) $routine['ROUTINE_TYPE'], (string) $routine['ROUTINE_NAME']);
            $plan['routine_sql_modes'][] = (string) ($row['sql_mode'] ?? '');
        }

        return $plan;
    }

    /**
     * Založí cílovou databázi se stejnou znakovou sadou a řazením jako zdroj
     * (proměnné v triggerech dědí řazení databáze) a přelije data tabulek.
     *
     * @param array{source:string, charset:string, collation:string, tables: array<string, array{ddl:string, columns:string}>} $plan
     * @param callable(string):void $log
     */
    public function copyTables(array $plan, string $target, callable $log): void
    {
        $source = $plan['source'];
        $this->pdo->exec('CREATE DATABASE ' . self::quote($target)
            . ' CHARACTER SET ' . $plan['charset'] . ' COLLATE ' . $plan['collation']);
        $this->pdo->exec('USE ' . self::quote($target));
        $this->pdo->exec('SET SESSION FOREIGN_KEY_CHECKS = 0');
        foreach ($plan['tables'] as $name => $table) {
            try {
                $this->pdo->exec($table['ddl']);
            } catch (\Throwable $e) {
                throw new \RuntimeException("Strukturu tabulky {$name} nelze zkopírovat: " . $e->getMessage(), 0, $e);
            }
        }
        $done = 0;
        foreach ($plan['tables'] as $name => $table) {
            try {
                $this->pdo->exec(
                    'INSERT INTO ' . self::quote($target) . '.' . self::quote($name) . ' (' . $table['columns'] . ')'
                    . ' SELECT ' . $table['columns'] . ' FROM ' . self::quote($source) . '.' . self::quote($name),
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException("Data tabulky {$name} nelze zkopírovat: " . $e->getMessage(), 0, $e);
            }
            if (++$done % 100 === 0) {
                $log("  zkopírováno {$done} / " . count($plan['tables']) . ' tabulek');
            }
        }
    }

    /**
     * Trigger i rutina si berou sql_mode ze spojení, které je zakládá, a podle něj
     * se pak vyhodnocuje jejich tělo. Zakládají se proto pod sql_mode originálu.
     *
     * @param array{views: list<string>, triggers: list<array{table:string, ddl:string, sql_mode?:string}>, routines: list<string>, routine_sql_modes?: list<string>} $plan
     */
    public function finalize(array $plan, string $target): void
    {
        $this->pdo->exec('USE ' . self::quote($target));
        foreach ($plan['views'] as $ddl) {
            $this->pdo->exec($ddl);
        }
        $session = (string) $this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $mode = $this->pdo->prepare('SET SESSION sql_mode = ?');
        try {
            foreach ($plan['triggers'] as $trigger) {
                $mode->execute([$trigger['sql_mode'] ?? $session]);
                $this->pdo->exec($trigger['ddl']);
            }
            foreach ($plan['routines'] as $index => $ddl) {
                $mode->execute([$plan['routine_sql_modes'][$index] ?? $session]);
                $this->pdo->exec($ddl);
            }
        } finally {
            $mode->execute([$session]);
        }
    }

    /**
     * Typ každé tabulky. `SHOW FULL TABLES` místo `information_schema.TABLES` —
     * to kvůli statistikám otevírá všechny tabulky a na stovkách tabulek trvá desítky sekund.
     *
     * @return array<string,string> tabulka → BASE TABLE / VIEW / SYSTEM VERSIONED
     */
    public function tableTypes(string $schema): array
    {
        $types = [];
        $statement = $this->pdo->query('SHOW FULL TABLES FROM ' . self::quote($schema));
        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_NUM) as $row) {
            $types[(string) $row[0]] = strtoupper((string) $row[1]);
        }
        ksort($types);

        return $types;
    }

    private function showCreate(string $kind, string $schema, string $name): string
    {
        return self::createStatement($this->showCreateRow($kind, $schema, $name), $kind, $name);
    }

    /** @return array<string,mixed> */
    private function showCreateRow(string $kind, string $schema, string $name): array
    {
        $statement = $this->pdo->query('SHOW CREATE ' . $kind . ' ' . self::quote($schema) . '.' . self::quote($name));
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException("Nelze načíst definici {$kind} {$name}.");
        }
        return $row;
    }

    /** @param array<string,mixed> $row */
    private static function createStatement(array $row, string $kind, string $name): string
    {
        foreach ($row as $key => $value) {
            if (str_starts_with(strtolower((string) $key), 'create ') || $key === 'SQL Original Statement') {
                if ($value === null || $value === '') {
                    throw new \RuntimeException("Definice {$kind} {$name} není čitelná (chybí oprávnění?).");
                }

                return (string) $value;
            }
        }
        throw new \RuntimeException("SHOW CREATE {$kind} {$name} nevrátilo definici.");
    }
}
