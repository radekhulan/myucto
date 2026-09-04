<?php

declare(strict_types=1);

/**
 * Jednoduchý migrator: spustí SQL soubory z db/migrations/ v abecedním pořadí
 * a sleduje, co už proběhlo, v tabulce `migrations`.
 *
 * Po migracích automaticky detekuje "stale" data (chybějící exchange_rate
 * nebo varsymbol) a spustí příslušné backfill skripty
 * s --apply. Detekční queries jsou rychlé (COUNT s indexem), reálný backfill
 * se rozjede jen pokud něco chybí. Idempotentní — opakovaný běh = no-op.
 *
 * Použití:
 *   php api/bin/migrate.php                # migrace + auto-backfill
 *   php api/bin/migrate.php --status       # jen stav, žádná akce
 *   php api/bin/migrate.php --no-backfills # migrace BEZ auto-backfillu
 *   php api/bin/migrate.php --until=1073_x.sql # aplikovat nejvýše zadanou migraci
 *   php api/bin/migrate.php --below=1000       # jen migrace s číselnou předponou < 1000
 *   php api/bin/migrate.php --only=1000_user_suppliers.sql,1121_price_list_items.sql
 *   php api/bin/migrate.php --repair-definers          # náhled triggerů s chybějícím definerem
 *   php api/bin/migrate.php --repair-definers --apply  # znovuvytvoření pod aktuálním DB účtem
 *
 * `--below` je stabilní alternativa k `--until` — neváže se na konkrétní jméno
 * souboru, které se s každou upstream migrací posouvá. `--below=1000` = přesně
 * upstreamové schéma MyInvoice (MyÚčto začíná na 1000). `--only` aplikuje jen
 * vyjmenované migrace; obojí je určené pro převod dat z MyInvoice (viz
 * manual/06_Prevod_z_MyInvoice.md), ne pro běžný provoz.
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$db      = (new Connection($config))->pdo();

if (in_array('--repair-definers', $argv, true)) {
    repairMissingTriggerDefiners($db, in_array('--apply', $argv, true));
    exit(0);
}

$migrationsDir = $rootDir . '/db/migrations';
if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "Migrations directory not found: {$migrationsDir}\n");
    exit(1);
}

// Zajisti tabulku migrations
$db->exec(
    'CREATE TABLE IF NOT EXISTS migrations ('
    . ' filename VARCHAR(190) PRIMARY KEY,'
    . ' applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
    . ' duration_ms INT UNSIGNED NOT NULL DEFAULT 0'
    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = $db->query('SELECT filename FROM migrations')->fetchAll(\PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$files = glob($migrationsDir . '/*.sql');
sort($files, SORT_STRING);

$until = null;
$below = null;
$only  = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--until=')) {
        $until = basename(substr($arg, strlen('--until=')));
    } elseif (str_starts_with($arg, '--below=')) {
        $raw = trim(substr($arg, strlen('--below=')));
        if (!ctype_digit($raw)) {
            fwrite(STDERR, "--below vyžaduje celé číslo, dostal: {$raw}\n");
            exit(2);
        }
        $below = (int) $raw;
    } elseif (str_starts_with($arg, '--only=')) {
        $list = array_values(array_filter(array_map(
            static fn (string $n): string => basename(trim($n)),
            explode(',', substr($arg, strlen('--only=')))
        )));
        if (!$list) {
            fwrite(STDERR, "--only vyžaduje aspoň jeden název migrace.\n");
            exit(2);
        }
        $only = $list;
    }
}

if ($only !== null && ($until !== null || $below !== null)) {
    fwrite(STDERR, "--only nelze kombinovat s --until ani --below.\n");
    exit(2);
}

if ($until !== null) {
    $untilIndex = array_search($until, array_map('basename', $files), true);
    if ($untilIndex === false) {
        fwrite(STDERR, "Unknown migration for --until: {$until}\n");
        exit(2);
    }
    $files = array_slice($files, 0, $untilIndex + 1);
}

if ($below !== null) {
    // Číselná předpona; připouští i písmenné vsuvky (0026a_…, 0026c_…), které
    // se do řady dostaly dodatečně. Soubor BEZ číselné předpony neumíme zařadit
    // — radši spadnout než ho tiše vynechat a postavit děravé schéma.
    $unnumbered = [];
    $selected   = [];
    foreach ($files as $f) {
        $name = basename($f);
        if (preg_match('/^(\d+)[a-z]*_/i', $name, $m) !== 1) {
            $unnumbered[] = $name;
            continue;
        }
        if ((int) $m[1] < $below) {
            $selected[] = $f;
        }
    }
    if ($unnumbered) {
        fwrite(STDERR, "--below={$below}: tyto migrace nemají číselnou předponu a nelze je zařadit:\n  "
            . implode("\n  ", $unnumbered) . "\n");
        exit(2);
    }
    if (!$selected) {
        fwrite(STDERR, "--below={$below}: žádná migrace tomu neodpovídá.\n");
        exit(2);
    }
    $files = $selected;
}

if ($only !== null) {
    $known   = array_map('basename', $files);
    $unknown = array_values(array_diff($only, $known));
    if ($unknown) {
        fwrite(STDERR, "Unknown migration for --only: " . implode(', ', $unknown) . "\n");
        exit(2);
    }
    // Pořadí drží abecedně seřazený $files, ne pořadí na příkazové řádce.
    $files = array_values(array_filter($files, static fn (string $f): bool => in_array(basename($f), $only, true)));
}

$statusOnly = in_array('--status', $argv, true);

if ($statusOnly) {
    echo "Migration status:\n";
    foreach ($files as $file) {
        $name   = basename($file);
        $marker = isset($applied[$name]) ? '[x]' : '[ ]';
        echo "  {$marker} {$name}\n";
    }
    exit(0);
}

$pending = array_filter($files, fn (string $f) => !isset($applied[basename($f)]));

if (empty($pending)) {
    echo "Žádné nové migrace k aplikaci.\n";
} else {
    echo "Pending migrations: " . count($pending) . "\n";
}

foreach ($pending as $file) {
    $name = basename($file);
    echo "  → {$name} ... ";

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "READ FAILED\n");
        exit(1);
    }

    $start = microtime(true);
    try {
        foreach (splitSqlStatements($sql) as $stmt) {
            // Odstraň leading řádkové komentáře a prázdné řádky, abychom poznali prázdný statement
            $cleaned = preg_replace('/^(\s*--[^\n]*\n)+/', '', $stmt) ?? $stmt;
            $cleaned = trim($cleaned);
            if ($cleaned === '') {
                continue;
            }
            $db->exec($stmt);
        }
    } catch (\Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, '  Error: ' . $e->getMessage() . "\n");
        exit(1);
    }

    $durationMs = (int) ((microtime(true) - $start) * 1000);

    // INSERT IGNORE: pokud dva migrate procesy běží paralelně (např. docker-entrypoint.sh
    // + explicitní `docker compose exec ... migrate.php`), oba mohou považovat stejný
    // soubor za pending. Migrace samotné jsou idempotentní (CREATE TABLE IF NOT EXISTS,
    // ALTER ... IF EXISTS, atd.), takže druhý běh nezmění schema. IGNORE pak zamezí
    // crash na duplicate PK v této bookkeeping tabulce.
    $stmt = $db->prepare('INSERT IGNORE INTO migrations (filename, duration_ms) VALUES (?, ?)');
    $stmt->execute([$name, $durationMs]);
    $alreadyRecorded = $stmt->rowCount() === 0;

    if ($alreadyRecorded) {
        echo "OK ({$durationMs} ms, already recorded — race with another migrator)\n";
    } else {
        echo "OK ({$durationMs} ms)\n";
    }
}

echo "Hotovo.\n";

// Zahoď sdílenou cache introspekce schématu (hasTable/hasColumn). Ta drží odpovědi
// mezi requesty a migrace je právě mohla změnit — bez invalidace by aplikace až do
// vypršení TTL tvrdila, že nový sloupec neexistuje, a tiše by běžela bez feature,
// kterou migrace přinesla. Provádí se i když nebyly žádné pending migrace: cesta je
// levná a stav po `migrate.php` má být vždy konzistentní.
$__schemaCachePath = \MyInvoice\Infrastructure\Database\SchemaCache::pathFor(
    $config->dataDir() ?? $rootDir,
    (string) $config->get('db.name', ''),
);
if (\MyInvoice\Infrastructure\Database\SchemaCache::invalidate($__schemaCachePath)) {
    echo "Cache schématu zneplatněna.\n";
}

// Auto-backfill po migracích — detekuje stale data a spouští příslušné skripty
// s --apply. Skip pokud user dal --no-backfills (CI / read-only deploy).
if (!in_array('--no-backfills', $argv, true)) {
    runAutoBackfills($db, __DIR__);
}

/**
 * Detekuje 2 kategorie chybějících dat a spouští odpovídající backfill skripty.
 * Idempotentní: prázdné COUNT → skip skript. Výstup skriptu se streamuje na
 * stdout/stderr (passthru), aby uživatel viděl pokrok per řádek.
 */
function runAutoBackfills(\PDO $db, string $binDir): void
{
    $checks = [
        [
            'name'    => 'exchange-rates',
            // #238: počítej OBĚ strany — přijaté i VYSTAVENÉ faktury. Skript
            // backfill-exchange-rates.php řeší obojí, ale pokud žádná přijatá kurz
            // nepostrádala, dřív se vůbec nespustil, i když vystavené kurz neměly.
            'reason'  => 'non-CZK faktury (přijaté i vystavené) bez exchange_rate',
            'count'   => "SELECT
                            (SELECT COUNT(*) FROM purchase_invoices pi
                                JOIN currencies cur ON cur.id = pi.currency_id
                               WHERE pi.exchange_rate IS NULL
                                 AND cur.code != 'CZK'
                                 AND pi.status != 'cancelled')
                          + (SELECT COUNT(*) FROM invoices i
                                JOIN currencies cur ON cur.id = i.currency_id
                               WHERE i.exchange_rate IS NULL
                                 AND cur.code != 'CZK'
                                 AND i.status NOT IN ('cancelled', 'draft'))",
            'script'  => 'backfill-exchange-rates.php',
        ],
        [
            'name'    => 'purchase-varsymbols',
            'reason'  => 'přijaté faktury bez varsymbolu',
            'count'   => "SELECT COUNT(*) FROM purchase_invoices
                           WHERE varsymbol IS NULL
                             AND status != 'cancelled'",
            'script'  => 'backfill-purchase-varsymbols.php',
        ],
        [
            // Číselník sazeb členských států je seed migrací 1152/1292/1294, takže po
            // ztrátě obsahu ho migrate.php sám nevrátí — migrace jsou evidované jako
            // proběhlé. Přitom právě „spusťte php api/bin/migrate.php" radí uživateli
            // KAŽDÁ hláška, která na prázdný číselník narazí (import i vystavení
            // odmítne doklad se sazbou vyšší než 0 %). Bez tohohle kroku ta rada lže.
            //
            // Podmínka se ptá na SEEDOVANÉ řádky, ne na prázdnou tabulku: instalace,
            // kde si někdo po ztrátě založil vlastní sazbu (`is_custom = 1`), by jinak
            // vypadala zdravě a seed by se nedotáhl.
            'name'    => 'oss-member-state-rates',
            'reason'  => 'chybějící legislativní číselník sazeb členských států',
            'count'   => "SELECT CASE WHEN EXISTS (
                              SELECT 1 FROM oss_member_state_rates WHERE is_custom = 0
                          ) THEN 0 ELSE 1 END",
            'script'  => 'backfill-oss-rates.php',
        ],
    ];

    echo "\n=== Auto-backfill check ===\n";
    $ranAny = false;
    foreach ($checks as $c) {
        try {
            $count = (int) $db->query($c['count'])->fetchColumn();
        } catch (\Throwable $e) {
            // Tabulka může chybět na čerstvé instalaci před prvními migracemi —
            // tolerance: skip, neházet fatal.
            echo "  [{$c['name']}] skip (DB query failed: " . $e->getMessage() . ")\n";
            continue;
        }
        if ($count === 0) {
            echo "  [{$c['name']}] OK — žádná stale data\n";
            continue;
        }
        $ranAny = true;
        echo "\n→ [{$c['name']}] nalezeno {$count} {$c['reason']}, spouštím {$c['script']} --apply\n";
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binDir . '/' . $c['script']) . ' --apply';
        passthru($cmd, $exitCode);
        if ($exitCode !== 0) {
            fwrite(STDERR, "  ✗ {$c['script']} skončil s exit code {$exitCode}\n");
        }
    }
    if (!$ranAny) {
        echo "Vše OK, žádný backfill nepotřeba.\n";
    }
}

/**
 * Rozdělí SQL na jednotlivé statementy podle aktuálního delimiteru.
 * Default delimiter `;` lze přepnout direktivou `DELIMITER xxx` (klient-side, na vlastním řádku),
 * což je nutné pro CREATE PROCEDURE / TRIGGER s `;` uvnitř těla.
 *
 * Respektuje single-quoted stringy a komentáře `-- ...` a `/* ... *\/`.
 */
function splitSqlStatements(string $sql): array
{
    $stmts = [];
    $current = '';
    $delim = ';';
    $len = strlen($sql);
    $inSingle = false;
    $inLineComment = false;
    $inBlockComment = false;
    $atLineStart = true;

    for ($i = 0; $i < $len; $i++) {
        // DELIMITER directive — pouze na začátku řádku, mimo string/komentář
        if ($atLineStart && !$inSingle && !$inLineComment && !$inBlockComment) {
            $j = $i;
            while ($j < $len && ($sql[$j] === ' ' || $sql[$j] === "\t")) $j++;
            if ($j + 10 <= $len && strcasecmp(substr($sql, $j, 10), 'DELIMITER ') === 0) {
                $eol = strpos($sql, "\n", $j + 10);
                if ($eol === false) $eol = $len;
                $newDelim = trim(substr($sql, $j + 10, $eol - ($j + 10)));
                if ($newDelim !== '') {
                    if (trim($current) !== '') {
                        $stmts[] = $current;
                        $current = '';
                    }
                    $delim = $newDelim;
                }
                $i = $eol; // hlavní cyklus posune na další řádek
                $atLineStart = true;
                continue;
            }
        }
        $atLineStart = false;

        $ch  = $sql[$i];
        $nxt = ($i + 1 < $len) ? $sql[$i + 1] : '';

        if ($inLineComment) {
            $current .= $ch;
            if ($ch === "\n") { $inLineComment = false; $atLineStart = true; }
            continue;
        }
        if ($inBlockComment) {
            $current .= $ch;
            if ($ch === '*' && $nxt === '/') {
                $current .= '/';
                $i++;
                $inBlockComment = false;
            }
            continue;
        }
        if ($inSingle) {
            $current .= $ch;
            if ($ch === '\\' && $nxt !== '') {
                $current .= $nxt;
                $i++;
                continue;
            }
            if ($ch === "'") $inSingle = false;
            continue;
        }

        if ($ch === '-' && $nxt === '-') {
            $current .= '--';
            $i++;
            $inLineComment = true;
            continue;
        }
        if ($ch === '/' && $nxt === '*') {
            $current .= '/*';
            $i++;
            $inBlockComment = true;
            continue;
        }
        if ($ch === "'") {
            $inSingle = true;
            $current .= $ch;
            continue;
        }
        if ($ch === "\n") {
            $current .= $ch;
            $atLineStart = true;
            continue;
        }

        // Match aktuální delimiter (může být multi-char, např. `//`)
        $dlen = strlen($delim);
        if ($dlen > 0 && substr_compare($sql, $delim, $i, $dlen) === 0) {
            if (trim($current) !== '') $stmts[] = $current;
            $current = '';
            $i += $dlen - 1;
            continue;
        }
        $current .= $ch;
    }

    if (trim($current) !== '') $stmts[] = $current;

    return $stmts;
}

/**
 * Opraví triggery přenesené dumpem z jiného serveru, jejichž DEFINER v cílové
 * MariaDB neexistuje. Bez --apply pouze vypíše rozsah. Při aplikaci zachová tělo
 * triggeru i jeho SQL mode a změní jen vlastníka na CURRENT_USER.
 */
function repairMissingTriggerDefiners(\PDO $db, bool $apply): void
{
    $identity = $db->query(
        'SELECT DATABASE() AS database_name, CURRENT_USER() AS authenticated_user'
    )->fetch(\PDO::FETCH_ASSOC);
    $database = (string) ($identity['database_name'] ?? '');
    $currentUser = (string) ($identity['authenticated_user'] ?? '');
    if ($database === '') {
        throw new \RuntimeException('Není vybraná databáze.');
    }

    try {
        $stmt = $db->prepare(
            "SELECT t.TRIGGER_NAME, t.DEFINER
               FROM information_schema.TRIGGERS t
          LEFT JOIN mysql.user u
                 ON u.User = SUBSTRING_INDEX(t.DEFINER, '@', 1)
                AND u.Host = SUBSTRING_INDEX(t.DEFINER, '@', -1)
              WHERE t.TRIGGER_SCHEMA = ?
                AND u.User IS NULL
              ORDER BY t.TRIGGER_NAME"
        );
        $stmt->execute([$database]);
        $broken = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        throw new \RuntimeException(
            'Kontrola definerů vyžaduje oprávnění číst mysql.user: ' . $e->getMessage(),
            0,
            $e,
        );
    }

    echo sprintf(
        "Databáze: %s, aktuální účet: %s, triggery s chybějícím definerem: %d\n",
        $database,
        $currentUser,
        count($broken),
    );
    if ($broken === []) {
        echo "Není co opravovat.\n";
        return;
    }
    if (!$apply) {
        $byDefiner = [];
        foreach ($broken as $row) {
            $definer = (string) $row['DEFINER'];
            $byDefiner[$definer] = ($byDefiner[$definer] ?? 0) + 1;
        }
        foreach ($byDefiner as $definer => $count) {
            echo "  {$definer}: {$count}\n";
        }
        echo "Náhled bez změn. Pro opravu přidej --apply.\n";
        return;
    }

    $quoteIdentifier = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
    $originalMode = (string) $db->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    $repaired = 0;
    try {
        foreach ($broken as $row) {
            $name = (string) $row['TRIGGER_NAME'];
            $show = $db->query('SHOW CREATE TRIGGER ' . $quoteIdentifier($name))->fetch(\PDO::FETCH_ASSOC);
            $originalSql = is_array($show) ? (string) ($show['SQL Original Statement'] ?? '') : '';
            $sqlMode = is_array($show) ? (string) ($show['sql_mode'] ?? '') : '';
            if ($originalSql === '') {
                throw new \RuntimeException("Nelze načíst definici triggeru {$name}.");
            }
            $createSql = preg_replace(
                '/\ACREATE\s+DEFINER=(?:`(?:``|[^`])+`@`(?:``|[^`])+`|\S+)\s+TRIGGER\s+/i',
                'CREATE DEFINER=CURRENT_USER TRIGGER ',
                $originalSql,
                1,
                $replacementCount,
            );
            if (!is_string($createSql) || $replacementCount !== 1) {
                throw new \RuntimeException("Neznámý formát definice triggeru {$name}.");
            }

            $setMode = $db->prepare('SET SESSION sql_mode = ?');
            $setMode->execute([$sqlMode]);
            $db->exec('DROP TRIGGER IF EXISTS ' . $quoteIdentifier($name));
            try {
                $db->exec($createSql);
            } catch (\Throwable $e) {
                try {
                    $db->exec($originalSql);
                } catch (\Throwable) {
                }
                throw new \RuntimeException("Obnova triggeru {$name} selhala: " . $e->getMessage(), 0, $e);
            }
            $repaired++;
        }
    } finally {
        $restoreMode = $db->prepare('SET SESSION sql_mode = ?');
        $restoreMode->execute([$originalMode]);
    }

    echo "Opraveno triggerů: {$repaired}.\n";
}
