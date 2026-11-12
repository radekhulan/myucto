<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\SchemaMetadata;
use MyInvoice\Infrastructure\Database\TriggerMetadata;
use PDO;
use PDOException;

/**
 * Úplné smazání firmy (dodavatele) se všemi jejími daty z databáze instance.
 *
 * Co se maže, služba zjistí ze struktury databáze: řádky každé tabulky se sloupcem
 * `supplier_id` dané firmy a nakonec řádek firmy v `supplier`. Podřízené řádky bez
 * `supplier_id` smaže databáze podle `ON DELETE CASCADE`, vazby `SET NULL` odpojí.
 * Podřízené tabulky s vazbou RESTRICT na data firmy se mažou předem podle rodiče.
 * Na pořadí kaskády se nespoléhá (MariaDB 11.8 a 12.3 ji procházejí jinak): tabulky
 * firmy se mažou jednotlivě v topologickém pořadí a co narazí na odkaz (1451), zkusí
 * se v dalším průchodu. Kontrola cizích klíčů zůstává zapnutá, vypíná se jen pro
 * cyklus vazeb (firma ↔ její výchozí měna) s ověřením, že nezůstal žádný odkaz.
 * Maže se v jedné transakci.
 *
 * Tabulky jen pro přidávání (mzdy, podání, SVJ) hlídá BEFORE DELETE trigger. Při
 * `$allowImmutable` se triggery tabulek, ve kterých má firma řádky, odstraní těsně
 * před transakcí (DDL by ji ukončilo) a hned po ní se VŽDY obnoví. Definice se
 * předem uloží do souboru, aby je šlo obnovit i po pádu procesu
 * ({@see restorePendingTriggers()}, volá se na začátku každého náhledu i mazání).
 *
 * Deník je verzovaný (`SYSTEM VERSIONED`): smazané zápisy zůstanou jen v historii
 * verzí. Auditní stopa `activity_log` je zřetězená hashem, smazání jejích řádků by
 * řetěz přerušilo, proto se zachová (sloupec `supplier_id` nemá cizí klíč).
 */
final class CompanyPurgeService
{
    /** @var list<string> */
    public const RETAINED_TABLES = ['activity_log'];

    private const LOCK = 'myucto_company_purge';
    private const MAX_PASSES = 10;

    private readonly SchemaMetadata $schema;

    public function __construct(private readonly Connection $db, ?SchemaMetadata $schema = null)
    {
        $this->schema = $schema ?? new SchemaMetadata($db);
    }

    /**
     * @return array{
     *     schema: string,
     *     supplier: array{id: int, company_name: string, ic: string},
     *     counts: array<string, int>,
     *     children: list<array{table: string, via: string, rows: int, where: string}>,
     *     guards: array<string, string>,
     *     total: int,
     *     files: list<string>
     * }
     */
    public function preview(int $supplierId, bool $withFiles = false): array
    {
        $this->restorePendingTriggers();
        $pdo = $this->db->pdo();
        $schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $stmt = $pdo->prepare('SELECT id, company_name, ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new CompanyPurgeException(CompanyPurgeException::NOT_FOUND, "Firma {$supplierId} v databázi {$schema} není.");
        }

        $where = self::where($supplierId);
        $tables = $this->companyTables();
        $counts = [];
        foreach ($tables as $table) {
            $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE {$where($table)}")->fetchColumn();
            if ($n > 0) {
                $counts[$table] = $n;
            }
        }
        $children = [];
        foreach ($this->restrictedChildren($tables) as $child) {
            $childWhere = sprintf('`%s` IN (SELECT `%s` FROM `%s` WHERE %s)',
                $child['column'], $child['parent_column'], $child['parent'], $where($child['parent']));
            $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$child['table']}` WHERE {$childWhere}")->fetchColumn();
            if ($n > 0) {
                $children[] = ['table' => $child['table'], 'via' => $child['parent'], 'rows' => $n, 'where' => $childWhere];
            }
        }

        $guards = [];
        $touched = $counts;
        foreach ($children as $child) {
            $touched[$child['table']] = $child['rows'];
        }
        if ($touched !== []) {
            foreach ($this->schema->triggers() as $t) {
                if ($t['EVENT_MANIPULATION'] === 'DELETE' && $t['ACTION_TIMING'] === 'BEFORE' && isset($touched[$t['EVENT_OBJECT_TABLE']])) {
                    $guards[(string) $t['TRIGGER_NAME']] = (string) $t['EVENT_OBJECT_TABLE'];
                }
            }
        }

        return [
            'schema' => $schema,
            'supplier' => ['id' => (int) $row['id'], 'company_name' => (string) $row['company_name'], 'ic' => trim((string) $row['ic'])],
            'counts' => $counts,
            'children' => $children,
            'guards' => $guards,
            'total' => array_sum($counts),
            'files' => $withFiles ? ResetStorageScope::supplierPaths(RuntimePaths::storage(''), $supplierId) : [],
        ];
    }

    /**
     * Smaže firmu se všemi daty. Při chybě se nesmaže nic a triggery se obnoví.
     *
     * @return array{
     *     preview: array<string, mixed>,
     *     deleted: int,
     *     cycle: list<string>,
     *     triggers: int,
     *     files: int,
     *     files_removed: int
     * }
     */
    public function purge(int $supplierId, bool $allowImmutable, bool $withFiles): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Smazání firmy nesmí běžet uvnitř otevřené transakce.');
        }
        if (!$this->lock($pdo)) {
            throw new CompanyPurgeException(CompanyPurgeException::BUSY, 'Právě probíhá mazání jiné firmy. Zkuste to za chvíli znovu.');
        }
        try {
            $plan = $this->preview($supplierId, $withFiles);
            if ($plan['guards'] !== [] && !$allowImmutable) {
                throw new CompanyPurgeException(CompanyPurgeException::IMMUTABLE_REQUIRED,
                    'Firma má řádky v tabulkách jen pro přidávání (' . implode(', ', array_unique($plan['guards'])) . ').');
            }
            $result = $this->deleteRows($pdo, $plan, $supplierId);
        } finally {
            $this->unlock($pdo);
        }

        $removed = 0;
        foreach ($plan['files'] as $path) {
            $removed += ResetStorageScope::remove($path);
        }
        return $result + ['preview' => $plan, 'files' => count($plan['files']), 'files_removed' => $removed];
    }

    /**
     * Obnoví triggery, které po sobě nechal nedokončený běh mazání (pád procesu mezi
     * odstraněním a obnovou). Vrací počet obnovených triggerů.
     */
    public function restorePendingTriggers(): int
    {
        $pdo = $this->db->pdo();
        $schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $file = $this->pendingFile($schema);
        if (!is_file($file) || $pdo->inTransaction() || !$this->lock($pdo)) {
            return 0;
        }
        try {
            $saved = json_decode((string) file_get_contents($file), true);
            $definitions = is_array($saved) && is_array($saved['triggers'] ?? null) ? $saved['triggers'] : [];
            $missing = array_diff_key($definitions, array_fill_keys(array_column(TriggerMetadata::read($pdo, $schema), 'TRIGGER_NAME'), true));
            $failed = $this->createTriggers($pdo, $missing);
            if ($failed !== []) {
                throw new CompanyPurgeException(CompanyPurgeException::TRIGGER_RESTORE_FAILED,
                    'Nepodařilo se obnovit ochranné triggery po přerušeném mazání firmy: ' . implode(', ', array_keys($failed))
                    . '. Definice jsou v ' . $file . '.');
            }
            @unlink($file);
            return count($missing);
        } finally {
            $this->unlock($pdo);
        }
    }

    private function lock(PDO $pdo): bool
    {
        $stmt = $pdo->prepare('SELECT GET_LOCK(CONCAT(?, DATABASE()), 0)');
        $stmt->execute([self::LOCK . ':']);
        return (int) $stmt->fetchColumn() === 1;
    }

    private function unlock(PDO $pdo): void
    {
        $pdo->prepare('SELECT RELEASE_LOCK(CONCAT(?, DATABASE()))')->execute([self::LOCK . ':']);
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{deleted: int, cycle: list<string>, triggers: int}
     */
    private function deleteRows(PDO $pdo, array $plan, int $supplierId): array
    {
        $where = self::where($supplierId);
        $definitions = [];
        foreach (array_keys($plan['guards']) as $trigger) {
            $definitions[$trigger] = $this->triggerDefinition($pdo, $trigger);
        }

        $deleted = 0;
        $cycle = [];
        $dropped = [];
        $abort = ignore_user_abort(true);
        @set_time_limit(0);
        try {
            if ($definitions !== []) {
                $this->savePending($plan['schema'], $definitions);
            }
            foreach (array_keys($definitions) as $trigger) {
                try {
                    $pdo->exec('DROP TRIGGER `' . str_replace('`', '``', $trigger) . '`');
                } catch (PDOException $e) {
                    throw self::privilegeError($e) ?? $e;
                }
                $dropped[$trigger] = $definitions[$trigger];
            }
            $pdo->beginTransaction();
            try {
                $pending = [];
                foreach ($plan['children'] as $child) {
                    $pending[] = ['table' => $child['table'], 'sql' => "DELETE FROM `{$child['table']}` WHERE {$child['where']}", 'own' => false];
                }
                foreach (array_keys($plan['counts']) as $table) {
                    $pending[] = ['table' => $table, 'sql' => "DELETE FROM `{$table}` WHERE {$where($table)}", 'own' => true];
                }
                for ($pass = 0; $pending !== [] && $pass < self::MAX_PASSES; $pass++) {
                    $failed = [];
                    $errors = [];
                    foreach ($pending as $job) {
                        try {
                            $deleted += (int) $pdo->exec($job['sql']);
                        } catch (PDOException $e) {
                            if ((int) ($e->errorInfo[1] ?? 0) !== 1451) {
                                throw $e;
                            }
                            $failed[] = $job;
                            $errors[$job['table']] = $e->getMessage();
                        }
                    }
                    if ($failed !== [] && count($failed) === count($pending)) {
                        $cycleTables = array_values(array_unique(array_column(array_filter($failed, static fn (array $j): bool => $j['own']), 'table')));
                        if ($cycleTables !== []) {
                            $deleted += $this->deleteCycle($pdo, $cycleTables, $where, $errors);
                            $cycle = $cycleTables;
                            $failed = array_values(array_filter($failed, static fn (array $j): bool => !$j['own']));
                        }
                    }
                    $pending = $failed;
                }
                if ($pending !== []) {
                    throw new CompanyPurgeException(CompanyPurgeException::BLOCKED,
                        'Smazání nejde dokončit, na data firmy stále odkazují záznamy v tabulkách: '
                        . implode(', ', array_unique(array_column($pending, 'table'))) . '.');
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1644) {
                    throw $definitions === []
                        ? new CompanyPurgeException(CompanyPurgeException::NO_TRIGGER_PRIVILEGE,
                            'Data firmy chrání proti smazání databázové triggery a ty nejde dočasně vypnout '
                            . '(databázový účet aplikace nemá právo TRIGGER). Nic se nesmazalo.', $e)
                        : new CompanyPurgeException(CompanyPurgeException::BLOCKED,
                            'Smazání zastavila ochrana dat v databázi: ' . (string) ($e->errorInfo[2] ?? $e->getMessage()) . ' Nic se nesmazalo.', $e);
                }
                throw $e;
            }
        } finally {
            $failedRestore = $this->createTriggers($pdo, $dropped);
            if ($failedRestore === [] && $definitions !== []) {
                @unlink($this->pendingFile($plan['schema']));
            }
            ignore_user_abort((bool) $abort);
            if ($failedRestore !== []) {
                throw new CompanyPurgeException(CompanyPurgeException::TRIGGER_RESTORE_FAILED,
                    'Po smazání firmy se nepodařilo obnovit ochranné triggery: ' . implode(', ', array_keys($failedRestore))
                    . '. Obnoví se při dalším pokusu o smazání, definice jsou v ' . $this->pendingFile($plan['schema']) . '.');
            }
        }

        return ['deleted' => $deleted, 'cycle' => $cycle, 'triggers' => count($dropped)];
    }

    /** @return array{sql: string, sql_mode: string} */
    private function triggerDefinition(PDO $pdo, string $trigger): array
    {
        try {
            $create = $pdo->query('SHOW CREATE TRIGGER `' . str_replace('`', '``', $trigger) . '`')->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw self::privilegeError($e) ?? $e;
        }
        $sql = is_array($create) ? (string) ($create['SQL Original Statement'] ?? '') : '';
        if ($sql === '') {
            throw new CompanyPurgeException(CompanyPurgeException::NO_TRIGGER_PRIVILEGE,
                "Definici triggeru {$trigger} nejde načíst, smazání se nespustí. Nic se nesmazalo.");
        }
        return ['sql' => $sql, 'sql_mode' => (string) ($create['sql_mode'] ?? '')];
    }

    /**
     * Vytvoří triggery podle uložených definic. Nesmí-li účet vytvořit trigger s původním
     * DEFINER, vytvoří ho pod sebou (tělo triggerů jen odmítá mazání). Vrací nevytvořené.
     *
     * @param array<string, array{sql: string, sql_mode: string}> $definitions
     * @return array<string, string>
     */
    private function createTriggers(PDO $pdo, array $definitions): array
    {
        if ($definitions === []) {
            return [];
        }
        $failed = [];
        $mode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        try {
            foreach ($definitions as $trigger => $definition) {
                try {
                    $pdo->prepare('SET SESSION sql_mode = ?')->execute([(string) ($definition['sql_mode'] ?? '')]);
                    try {
                        $pdo->exec((string) $definition['sql']);
                    } catch (PDOException $e) {
                        if ((int) ($e->errorInfo[1] ?? 0) === 1359) {
                            continue;
                        }
                        $stripped = preg_replace('/^\s*CREATE\s+DEFINER\s*=\s*\S+\s+TRIGGER\b/i', 'CREATE TRIGGER', (string) $definition['sql'], 1);
                        if (self::privilegeError($e) === null || $stripped === null || $stripped === $definition['sql']) {
                            throw $e;
                        }
                        $pdo->exec($stripped);
                    }
                } catch (\Throwable $e) {
                    $failed[$trigger] = $e->getMessage();
                }
            }
        } finally {
            $pdo->prepare('SET SESSION sql_mode = ?')->execute([$mode]);
        }
        return $failed;
    }

    /** @param array<string, array{sql: string, sql_mode: string}> $definitions */
    private function savePending(string $schema, array $definitions): void
    {
        $file = $this->pendingFile($schema);
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0770, true);
        }
        @file_put_contents($file, json_encode(['schema' => $schema, 'triggers' => $definitions],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function pendingFile(string $schema): string
    {
        return RuntimePaths::storage('company-purge') . '/triggers-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $schema) . '.json';
    }

    private static function privilegeError(PDOException $e): ?CompanyPurgeException
    {
        if (!in_array((int) ($e->errorInfo[1] ?? 0), [1044, 1142, 1227, 1419], true)) {
            return null;
        }
        return new CompanyPurgeException(CompanyPurgeException::NO_TRIGGER_PRIVILEGE,
            'Databázový účet aplikace nemá právo TRIGGER, bez kterého nejde smazat data mezd, podání a SVJ. '
            . 'Nic se nesmazalo. Požádejte správce databáze o přidělení práva TRIGGER.', $e);
    }

    /** @return \Closure(string): string */
    private static function where(int $supplierId): \Closure
    {
        return static fn (string $table): string => $table === 'supplier' ? '`id` = ' . $supplierId : '`supplier_id` = ' . $supplierId;
    }

    /**
     * Tabulky firmy (sloupec `supplier_id`) seřazené tak, aby odkazující tabulka šla před
     * tou, na kterou odkazuje; `supplier` je poslední.
     *
     * @return list<string>
     */
    private function companyTables(): array
    {
        $tables = array_diff_key(array_fill_keys($this->schema->tablesWithColumn('supplier_id'), true), array_fill_keys(self::RETAINED_TABLES, true));
        $referencing = [];
        $seen = [];
        foreach ($this->schema->localForeignKeys() as $fk) {
            $pair = $fk['TABLE_NAME'] . "\0" . $fk['REFERENCED_TABLE_NAME'];
            if (isset($seen[$pair])) {
                continue;
            }
            $seen[$pair] = true;
            if ($fk['TABLE_NAME'] !== $fk['REFERENCED_TABLE_NAME'] && isset($tables[$fk['TABLE_NAME']], $tables[$fk['REFERENCED_TABLE_NAME']])) {
                $referencing[$fk['REFERENCED_TABLE_NAME']][] = $fk['TABLE_NAME'];
            }
        }
        $order = [];
        $state = [];
        $visit = function (string $table) use (&$visit, &$order, &$state, $referencing): void {
            if (isset($state[$table])) {
                return;
            }
            $state[$table] = true;
            foreach ($referencing[$table] ?? [] as $child) {
                $visit($child);
            }
            $order[] = $table;
        };
        foreach (array_keys($tables) as $table) {
            $visit((string) $table);
        }
        return [...$order, 'supplier'];
    }

    /**
     * Podřízené tabulky bez `supplier_id`, které na řádky firmy odkazují vazbou bez
     * `ON DELETE CASCADE`. Databáze je sama nesmaže a mazání rodiče zablokují.
     *
     * @param list<string> $tables
     * @return list<array{table: string, column: string, parent: string, parent_column: string}>
     */
    private function restrictedChildren(array $tables): array
    {
        $rows = array_values(array_filter($this->schema->localForeignKeys(), fn (array $fk): bool
            => in_array($fk['DELETE_RULE'], ['RESTRICT', 'NO ACTION'], true) && !$this->schema->hasColumn($fk['TABLE_NAME'], 'supplier_id')));
        usort($rows, static fn (array $a, array $b): int => SchemaMetadata::compareNames($a['TABLE_NAME'], $b['TABLE_NAME'])
            ?: SchemaMetadata::compareNames($a['COLUMN_NAME'], $b['COLUMN_NAME']));
        $company = array_fill_keys($tables, true);
        $out = [];
        foreach ($rows as $r) {
            if (isset($company[$r['REFERENCED_TABLE_NAME']]) && $r['TABLE_NAME'] !== 'supplier') {
                $out[] = ['table' => (string) $r['TABLE_NAME'], 'column' => (string) $r['COLUMN_NAME'],
                    'parent' => (string) $r['REFERENCED_TABLE_NAME'], 'parent_column' => (string) $r['REFERENCED_COLUMN_NAME']];
            }
        }
        return $out;
    }

    /**
     * Zbytek, který se navzájem drží v cyklu vazeb (řádek firmy odkazuje na svou výchozí
     * měnu, měna na firmu). Tabulky firmy se smažou s vypnutou kontrolou cizích klíčů,
     * řádek firmy až po jejím zapnutí. Nakonec se ověří, že žádný jiný řádek databáze
     * neodkazuje na smazaný řádek; jinak výjimka a celá transakce zpět.
     *
     * @param list<string> $tables
     * @param array<string, string> $errors
     */
    private function deleteCycle(PDO $pdo, array $tables, \Closure $where, array $errors): int
    {
        $refs = array_values(array_filter($this->schema->localForeignKeys(),
            static fn (array $fk): bool => in_array($fk['REFERENCED_TABLE_NAME'], $tables, true)));
        $orphans = static function () use ($pdo, $refs): int {
            $n = 0;
            foreach ($refs as $r) {
                $n += (int) $pdo->query(sprintf(
                    'SELECT COUNT(*) FROM `%1$s` c WHERE c.`%2$s` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `%3$s` p WHERE p.`%4$s` = c.`%2$s`)',
                    $r['TABLE_NAME'], $r['COLUMN_NAME'], $r['REFERENCED_TABLE_NAME'], $r['REFERENCED_COLUMN_NAME']
                ))->fetchColumn();
            }
            return $n;
        };
        $before = $orphans();
        $deleted = 0;
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (array_diff($tables, ['supplier']) as $table) {
                $deleted += (int) $pdo->exec("DELETE FROM `{$table}` WHERE {$where($table)}");
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        if (in_array('supplier', $tables, true)) {
            $deleted += (int) $pdo->exec("DELETE FROM `supplier` WHERE {$where('supplier')}");
        }
        if ($orphans() !== $before) {
            throw new CompanyPurgeException(CompanyPurgeException::BLOCKED,
                'Na řádky firmy odkazují jiná data, smazání nejde dokončit: ' . implode(' | ', $errors));
        }
        return $deleted;
    }
}
