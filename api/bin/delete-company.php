<?php

declare(strict_types=1);

/**
 * Smazání firmy (dodavatele) se všemi jejími daty z databáze instance.
 *
 *   php api/bin/delete-company.php --id=7                      # náhled: co by se smazalo
 *   php api/bin/delete-company.php --id=7 --confirm=12345678   # smazat (potvrzení IČO firmy)
 *   php api/bin/delete-company.php --id=7 --confirm=12345678 --files
 *
 * Hodí se pro zopakování převodu do čisté firmy nebo úklid zkušební firmy. Nastavení
 * aplikace firmu smaže jen prázdnou; tento nástroj smaže i doklady, deník, mzdy a vše
 * ostatní. Nelze to vrátit - před smazáním si udělejte zálohu databáze.
 *
 * Co se maže, nástroj zjistí ze struktury databáze, takže nepotřebuje seznam tabulek:
 * řádky každé tabulky se sloupcem `supplier_id` dané firmy a nakonec řádek firmy
 * v `supplier`. Podřízené řádky bez `supplier_id` (položky dokladů, pohyby výpisů…)
 * smaže databáze sama podle pravidel cizích klíčů (`ON DELETE CASCADE`), vazby
 * `SET NULL` odpojí. Kontrola cizích klíčů zůstává zapnutá: řádek, na který ještě
 * odkazuje jiný řádek, se smaže v dalším průchodu; co nejde smazat ani tak, smazání
 * zastaví a vše se vrátí. Maže se v jedné transakci.
 *
 * Deník je verzovaný (`SYSTEM VERSIONED`): smazané zápisy zůstanou jen v historii verzí,
 * běžné dotazy aplikace je nevidí. Tabulky jen pro přidávání (mzdy, podání, audit) mají
 * trigger, který mazání zakáže. Má-li v nich firma řádky, nástroj bez `--immutable`
 * skončí a vypíše je; s ním triggery na dobu mazání odstraní a pak obnoví (DDL
 * transakci ukončí, proto se obnovují i při chybě).
 *
 * `--files` smaže i soubory firmy v úložišti: složky a soubory `sup-<id>` a `supplier-<id>`
 * v prvních třech úrovních `storage/` (`ResetStorageScope::supplierPaths`), například PDF
 * vydaných i přijatých faktur, jejich zdrojové soubory, přílohy, archivy, podpisové
 * profily a loga.
 *
 * Exit kódy: 0 ok · 1 chyba běhu · 2 chybné argumenty
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\System\ResetStorageScope;

$opts = [];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
        continue;
    }
    $body = substr($arg, 2);
    $eq = strpos($body, '=');
    $opts[$eq === false ? $body : substr($body, 0, $eq)] = $eq === false ? true : substr($body, $eq + 1);
}

$usage = <<<TXT
Smazání firmy se všemi daty.

  --id=ID              id firmy (tabulka supplier)
  --confirm=IČO        provést smazání; hodnota musí být IČO firmy (bez něj jen náhled)
  --files              smazat i soubory firmy v úložišti (sup-<id>)
  --immutable          dočasně odstranit triggery tabulek jen pro přidávání (mzdy, podání)
  --help

TXT;

if (isset($opts['help'])) {
    echo $usage;
    exit(0);
}
$supplierId = isset($opts['id']) && is_string($opts['id']) && ctype_digit($opts['id']) ? (int) $opts['id'] : 0;
if ($supplierId <= 0) {
    fwrite(STDERR, "Chybí --id.\n\n" . $usage);
    exit(2);
}

/**
 * Tabulky firmy (sloupec `supplier_id`) seřazené tak, aby odkazující tabulka šla před
 * tou, na kterou odkazuje; `supplier` je poslední.
 *
 * @return list<string>
 */
function companyTables(PDO $pdo, string $schema): array
{
    $stmt = $pdo->prepare(
        "SELECT c.TABLE_NAME FROM information_schema.COLUMNS c
           JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
          WHERE c.TABLE_SCHEMA = ? AND c.COLUMN_NAME = 'supplier_id' AND t.TABLE_TYPE IN ('BASE TABLE', 'SYSTEM VERSIONED') ORDER BY c.TABLE_NAME"
    );
    $stmt->execute([$schema]);
    $tables = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
    $fks = $pdo->prepare(
        'SELECT DISTINCT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL'
    );
    $fks->execute([$schema, $schema]);
    $referencing = [];
    foreach ($fks->fetchAll(PDO::FETCH_ASSOC) as $fk) {
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
        $visit($table);
    }
    return [...$order, 'supplier'];
}

/**
 * Podřízené tabulky bez `supplier_id`, které na řádky firmy odkazují vazbou bez
 * `ON DELETE CASCADE` (např. položky platebního příkazu na fakturu). Databáze je
 * sama nesmaže a mazání rodiče zablokují, proto se mažou předem podle rodiče.
 *
 * @param list<string> $tables
 * @return list<array{table:string,column:string,parent:string,parent_column:string}>
 */
function restrictedChildren(PDO $pdo, string $schema, array $tables): array
{
    $stmt = $pdo->prepare(
        "SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME
           FROM information_schema.KEY_COLUMN_USAGE k
           JOIN information_schema.REFERENTIAL_CONSTRAINTS r
             ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
          WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_SCHEMA = ? AND r.DELETE_RULE IN ('RESTRICT', 'NO ACTION')
            AND NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS c
                             WHERE c.TABLE_SCHEMA = k.TABLE_SCHEMA AND c.TABLE_NAME = k.TABLE_NAME AND c.COLUMN_NAME = 'supplier_id')
          ORDER BY k.TABLE_NAME, k.COLUMN_NAME"
    );
    $stmt->execute([$schema, $schema]);
    $company = array_fill_keys($tables, true);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($company[$r['REFERENCED_TABLE_NAME']]) && $r['TABLE_NAME'] !== 'supplier') {
            $out[] = ['table' => $r['TABLE_NAME'], 'column' => $r['COLUMN_NAME'],
                'parent' => $r['REFERENCED_TABLE_NAME'], 'parent_column' => $r['REFERENCED_COLUMN_NAME']];
        }
    }
    return $out;
}

/**
 * Zbytek, který se navzájem drží v cyklu vazeb (řádek firmy odkazuje na svou výchozí
 * měnu, měna na firmu). Tabulky firmy se smažou s vypnutou kontrolou cizích klíčů,
 * řádek firmy až po jejím zapnutí - vazby cizích tabulek na firmu (uživatel s firmou
 * jako výchozí, `SET NULL`) tak databáze odpojí sama. Nakonec se ověří, že žádný jiný
 * řádek databáze neodkazuje na smazaný řádek; jinak výjimka a celá transakce zpět.
 *
 * @param list<string> $tables
 * @param array<string,string> $errors
 */
function deleteCycle(PDO $pdo, string $schema, array $tables, Closure $where, array $errors): int
{
    $fks = $pdo->prepare(
        'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IN (' . implode(', ', array_fill(0, count($tables), '?')) . ')'
    );
    $fks->execute([$schema, $schema, ...$tables]);
    $refs = $fks->fetchAll(PDO::FETCH_ASSOC);
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
        throw new RuntimeException('Na řádky firmy odkazují jiná data, smazání nejde dokončit: ' . implode(' | ', $errors));
    }
    echo sprintf("  cyklus vazeb (%s) smazán s ověřením, že nezůstal žádný odkaz\n", implode(', ', $tables));
    return $deleted;
}

try {
    $pdo = Bootstrap::buildContainer()->get(Connection::class)->pdo();
    $schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $company = $pdo->prepare('SELECT id, company_name, ic FROM supplier WHERE id = ?');
    $company->execute([$supplierId]);
    $row = $company->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        fwrite(STDERR, "Firma {$supplierId} v databázi {$schema} není.\n");
        exit(1);
    }
    $ico = trim((string) $row['ic']);
    echo sprintf("Databáze %s, firma %d: %s (IČO %s)\n", $schema, $supplierId, $row['company_name'], $ico !== '' ? $ico : '-');

    $where = static fn (string $table): string => $table === 'supplier' ? '`id` = ' . $supplierId : '`supplier_id` = ' . $supplierId;
    $counts = [];
    $allTables = companyTables($pdo, $schema);
    foreach ($allTables as $table) {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}` WHERE {$where($table)}")->fetchColumn();
        if ($n > 0) {
            $counts[$table] = $n;
        }
    }
    $children = [];
    foreach (restrictedChildren($pdo, $schema, $allTables) as $child) {
        $childWhere = sprintf('`%s` IN (SELECT `%s` FROM `%s` WHERE %s)',
            $child['column'], $child['parent_column'], $child['parent'], $where($child['parent']));
        $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$child['table']}` WHERE {$childWhere}")->fetchColumn();
        if ($n > 0) {
            $children[] = ['table' => $child['table'], 'where' => $childWhere, 'via' => $child['parent']];
            echo sprintf("  %-48s %8d  (podřízené řádky přes %s)\n", $child['table'], $n, $child['parent']);
        }
    }
    $listed = $counts;
    arsort($listed);
    foreach ($listed as $table => $n) {
        echo sprintf("  %-48s %8d\n", $table, $n);
    }
    echo sprintf("Celkem %d řádků v %d tabulkách, podřízené řádky smaže databáze vazbou.\n", array_sum($counts), count($counts));

    $files = [];
    if (isset($opts['files'])) {
        $files = ResetStorageScope::supplierPaths(RuntimePaths::storage(''), $supplierId);
        foreach ($files as $path) {
            echo '  úložiště: ' . $path . "\n";
        }
    }

    $guards = [];
    if ($counts !== []) {
        $stmt = $pdo->prepare(
            "SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = ? AND EVENT_MANIPULATION = 'DELETE' AND ACTION_TIMING = 'BEFORE'"
        );
        $stmt->execute([$schema]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
            if (isset($counts[$t['EVENT_OBJECT_TABLE']])) {
                $guards[$t['TRIGGER_NAME']] = $t['EVENT_OBJECT_TABLE'];
            }
        }
    }
    foreach ($guards as $trigger => $table) {
        echo sprintf("  trigger %s hlídá mazání v %s\n", $trigger, $table);
    }

    if (!isset($opts['confirm'])) {
        echo "\nNáhled - nic se nesmazalo. Smazání: --confirm=<IČO firmy>"
            . ($guards !== [] ? ' --immutable' : '') . ".\n";
        exit(0);
    }
    if ($ico === '' || (string) $opts['confirm'] !== $ico) {
        fwrite(STDERR, "--confirm musí být IČO firmy ({$ico}).\n");
        exit(2);
    }
    if ($guards !== [] && !isset($opts['immutable'])) {
        fwrite(STDERR, "Firma má řádky v tabulkách jen pro přidávání (viz triggery výše). Smazání s nimi: přidejte --immutable.\n");
        exit(2);
    }

    // Triggery se odstraní před transakcí (DDL by ji ukončilo) a obnoví se vždy.
    $restore = [];
    foreach (array_keys($guards) as $trigger) {
        $create = $pdo->query('SHOW CREATE TRIGGER `' . str_replace('`', '``', $trigger) . '`')->fetch(PDO::FETCH_ASSOC);
        $restore[$trigger] = (string) ($create['SQL Original Statement'] ?? '');
        if ($restore[$trigger] === '') {
            throw new RuntimeException("Definici triggeru {$trigger} nejde načíst, smazání se nespustí.");
        }
    }
    foreach (array_keys($restore) as $trigger) {
        $pdo->exec('DROP TRIGGER `' . str_replace('`', '``', $trigger) . '`');
    }
    try {
        $pdo->beginTransaction();
        try {
            $deleted = 0;
            foreach ($children as $child) {
                $deleted += (int) $pdo->exec("DELETE FROM `{$child['table']}` WHERE {$child['where']}");
            }
            $pending = array_keys($counts);
            // Řádek, na který ještě odkazuje jiný řádek firmy vazbou RESTRICT, se smaže
            // v dalším průchodu; selhaný příkaz InnoDB vrátí sám, transakce běží dál.
            for ($pass = 0; $pending !== [] && $pass < 10; $pass++) {
                $failed = [];
                foreach ($pending as $table) {
                    try {
                        $deleted += (int) $pdo->exec("DELETE FROM `{$table}` WHERE {$where($table)}");
                    } catch (PDOException $e) {
                        if ((int) ($e->errorInfo[1] ?? 0) !== 1451) {
                            throw $e;
                        }
                        $failed[$table] = $e->getMessage();
                    }
                }
                if ($failed !== [] && count($failed) === count($pending)) {
                    $deleted += deleteCycle($pdo, $schema, array_keys($failed), $where, $failed);
                    $failed = [];
                }
                $pending = array_keys($failed);
            }
            if ($pending !== []) {
                throw new RuntimeException('Smazání nejde dokončit, zbývají tabulky: ' . implode(', ', $pending));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    } finally {
        foreach ($restore as $trigger => $sql) {
            $pdo->exec($sql);
        }
        if ($restore !== []) {
            echo sprintf("Obnoveno %d triggerů.\n", count($restore));
        }
    }
    echo sprintf("Smazáno %d řádků v tabulkách firmy (bez podřízených řádků smazaných vazbou).\n", $deleted);

    $removed = 0;
    foreach ($files as $path) {
        $removed += ResetStorageScope::remove($path);
    }
    if ($files !== []) {
        echo sprintf("Smazáno %d položek úložiště (%d souborů).\n", count($files), $removed);
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Chyba: ' . $e->getMessage() . "\n");
    exit(1);
}
