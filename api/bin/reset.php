<?php

declare(strict_types=1);

/**
 * RESET — vymaže všechna uživatelská data ze systému (ponechá schéma + globální číselníky).
 *
 *   php api/bin/reset.php             # interaktivní potvrzení
 *   php api/bin/reset.php --yes       # bez ptaní
 *   php api/bin/reset.php --dry-run   # NIC nemaže, jen vypíše co by smazal (+ počty řádků)
 *   php api/bin/reset.php --yes --keep-cache   # ponechá ARES/VIES cache
 *   php api/bin/reset.php --keep-users-supplier # ponechá účet(y) + dodavatele + jeho konfiguraci,
 *                                               # smaže jen byznys data (klienti, doklady, banka…)
 *   php api/bin/reset.php --skip-files          # soubory v storage/ nechá být
 *   php api/bin/reset.php --force-files         # smaže soubory firem i ve sdíleném úložišti
 *
 * --keep-users-supplier zachová vše, co je konfigurací firmy: historii plátcovství DPH,
 *       režim a období účetnictví, osnovu a předkontace, nastavení mezd (stav modulu,
 *       zaměstnavatelská politika, účtárny, mzdové složky), podepisování a napojení.
 *       Zařazení KAŽDÉ tabulky (ponechat / smazat) drží
 *       GlobalSeedTables::RESET_KEEP_USERS_SUPPLIER(_WIPES); nová tabulka bez zařazení
 *       shodí guard ResetKeepUsersSupplierClassificationTest.
 *
 * SOUBORY: storage/ může sdílet víc databází (dev stroj bez MYINVOICE_DATA_DIR). Reset
 *       proto maže jen adresáře sup-N / supplier-N firem z resetované databáze
 *       (ResetStorageScope::TENANT_AREAS, při úplném resetu i loga). Najde-li adresář
 *       firmy, kterou databáze nezná, úložiště je sdílené a vlastní id může patřit
 *       i jiné databázi: soubory pak NEMAŽE, dokud nedostane --force-files. Na stroji
 *       s víc instancemi nastav každé vlastní MYINVOICE_DATA_DIR.
 *
 * DYNAMICKÉ mazání: vymaže VŠECHNY tabulky kromě keep-listu (viz níže $keep),
 *       takže nezaostává za schématem — nové tabulky (vč. secretů: IMAP hesla,
 *       podpisové certifikáty) se po migraci automaticky vyčistí.
 * Ponechává globální číselníky, evidenci schématu a provozní údaje instance podle
 *       GlobalSeedTables::RESET_KEEP (s --keep-cache navíc RESET_KEEP_CACHE).
 *       U tabulek z GlobalSeedTables::RESET_PARTIAL (globální seed + per-tenant
 *       override) maže jen per-tenant řádky.
 * Ztracený globální seed vrátí `php api/bin/restore-global-seeds.php --apply`.
 * POZOR na per-tenant seedy z migrací (analytiky osnovy, bankovní pravidla): migrace
 *       jsou evidované jako proběhlé, takže je migrate.php po resetu NEOBNOVÍ. Co má
 *       dostat každá firma, patří do kódu (ChartOfAccountsTemplate + ChartOfAccountsSeeder),
 *       ne jen do migrace — viz db/migrations/README-post-setup.md.
 * Vše ostatní (users, supplier, currencies, doklady, banka, dokumenty, podpisy,
 *       importy, cache přepočtů, …) se TRUNCATE.
 *
 * Pozn.: currencies jsou per-supplier (multi-tenant), takže s ním padají.
 * Po resetu setup.php založí novému supplier defaultní CZK + EUR.
 *
 * Po resetu spusť znovu:
 *   php api/bin/setup.php       # admin + supplier + currencies
 *   php api/bin/sample.php      # (volitelné) testovací data
 *
 * Pro úplný restart včetně schema: DROP DATABASE + CREATE DATABASE + migrate.php
 * (reset.php schema záměrně neshazuje).
 */

// === CLI guard — odmítni HTTP přístup ===
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Tento skript lze spustit pouze z příkazové řádky (CLI).\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Config\CfgLocalWriter;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Service\System\GlobalSeedTables;
use MyInvoice\Service\System\ResetStorageScope;
use MyInvoice\Service\Property\PropertyResetCleanup;

$args = array_flip(array_slice($argv, 1));
$autoYes   = isset($args['--yes']) || isset($args['-y']);
$keepCache = isset($args['--keep-cache']);
$keepUsersSupplier = isset($args['--keep-users-supplier']);
$dryRun    = isset($args['--dry-run']);
$skipFiles  = isset($args['--skip-files']);
$forceFiles = isset($args['--force-files']);

$rootDir = Bootstrap::rootDir();

try {
    $config = Config::load($rootDir);
    $connection = new Connection($config);
    $pdo    = $connection->pdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "[reset] Chyba: " . $e->getMessage() . "\n");
    fwrite(STDERR, "[reset] Pravděpodobně chybí cfg.php nebo DB. Spusť `php api/bin/setup.php`.\n");
    exit(1);
}

echo "================================================\n";
echo "  MyÚčto.cz — RESET DATA\n";
echo "================================================\n";
echo "  DB:   " . $config->get('db.name') . " @ " . $config->get('db.host') . "\n";
echo "  Root: $rootDir\n";
echo "================================================\n\n";

// Stats před resetem
$counts = [];
foreach (['users', 'invoices', 'clients', 'projects', 'bank_statements', 'activity_log'] as $t) {
    try {
        $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    } catch (\Throwable) {
        $counts[$t] = '?';
    }
}
echo "Aktuální stav:\n";
foreach ($counts as $t => $c) printf("  %-20s %s\n", $t, $c);
echo "\n";

if ($keepUsersSupplier) {
    echo "Režim: --keep-users-supplier — ZACHOVÁ uživatele, dodavatele a jeho konfiguraci\n";
    echo "       (měny, číslování, podepisování, e-mail/banka config, číselníky, historii\n";
    echo "        plátcovství DPH, režim a období účetnictví, osnovu, předkontace, nastavení mezd).\n";
    echo "       Smaže BYZNYS data: klienti, doklady, banka, dokumenty, kniha jízd, recurring…\n\n";
}

if ($dryRun) {
    echo "Režim: --dry-run — NIC se nemaže, jen výpis.\n\n";
}

if (!$autoYes && !$dryRun) {
    echo $keepUsersSupplier
        ? "POZOR: smaže všechna byznys data (účet a firma zůstanou). Pokračovat? (napiš 'ANO'): "
        : "POZOR: smaže veškerá data v systému. Pokračovat? (napiš 'ANO'): ";
    $answer = trim((string) fgets(STDIN));
    if ($answer !== 'ANO') {
        echo "Zrušeno.\n";
        exit(0);
    }
}

// Reset je DYNAMICKÝ: vymaže VŠECHNY tabulky kromě keep-listu (globální číselníky +
// schema + drahé cache). Díky tomu nezaostává za schématem — nové tabulky se po
// migraci automaticky vyčistí (důležité např. pro IMAP účty / podpisové certifikáty
// se šifrovanými secrety). Keep-list i tabulky s globálním seedem (smaže se jen
// per-tenant část) drží GlobalSeedTables; guard ResetKeepsGlobalCodebooksTest nutí
// každou tabulku, kterou plní migrace, zařadit tam.
$keep = GlobalSeedTables::resetKeep($keepCache);
$partial = GlobalSeedTables::RESET_PARTIAL;

// --keep-users-supplier: zachovej účet(y) + dodavatele + jeho KONFIGURACI (DPH v čase,
// režim a období účetnictví, osnova, předkontace, nastavení mezd, podepisování, napojení),
// smaž jen BYZNYS data. „Start fresh" se zachovaným přihlášením a firmou — netřeba znovu
// setup. Užitečné i pro úklid duplicitních sample dat, která vznikla bez evidence (#162).
// Zařazení každé tabulky drží GlobalSeedTables::RESET_KEEP_USERS_SUPPLIER(_WIPES).
if ($keepUsersSupplier) {
    ['keep' => $keep, 'partial' => $partial] = GlobalSeedTables::resetKeepUsersSupplier($keepCache);
}

$propertyPlan = null;
if ($keepUsersSupplier) {
    try {
        $propertyPlan = PropertyResetCleanup::plan($pdo);
        printf("[reset] Registr SVJ: odstraní %d typů, %d hodnot a %d souvisejících alokačních klíčů.\n",
            count($propertyPlan['type_ids']), count($propertyPlan['value_ids']), count($propertyPlan['key_ids']));
    } catch (\Throwable $e) {
        fwrite(STDERR, '[reset] ' . $e->getMessage() . "\n");
        exit(1);
    }
}

// Firmy TÉTO databáze. Soubory se mažou jen v jejich adresářích (sup-N / supplier-N),
// protože storage/ může sdílet víc databází (dev stroj bez MYINVOICE_DATA_DIR).
$ownSupplierIds = array_map('intval', $pdo->query('SELECT id FROM supplier')->fetchAll(\PDO::FETCH_COLUMN));

$allTables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
$versionedTables = array_fill_keys(
    $pdo->query(
        "SELECT TABLE_NAME
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_TYPE = 'SYSTEM VERSIONED'"
    )->fetchAll(\PDO::FETCH_COLUMN),
    true
);

echo $dryRun ? "\n[reset] DRY-RUN — co by se smazalo:\n" : "\n[reset] Mažu tabulky…\n";
if (!$dryRun) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
}
$total = 0;
$failed = 0;
foreach ($allTables as $t) {
    if (in_array($t, $keep, true)) {
        if ($dryRun) {
            echo sprintf("  KEEP     %-40s %6d řádků\n", $t, (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn());
        }
        continue;
    }
    if (isset($partial[$t])) {
        try {
            if ($dryRun) {
                $would = (int) $pdo->query("SELECT COUNT(*) FROM `$t` WHERE {$partial[$t]}")->fetchColumn();
                $stays = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() - $would;
                echo sprintf("  PARTIAL  %-40s smaže %d, ponechá %d\n", $t, $would, $stays);
                $total++;
                continue;
            }
            $deleted = $pdo->exec("DELETE FROM `$t` WHERE {$partial[$t]}");
            echo "  ✓ $t (ponechán globální seed, smazáno {$deleted} tenant řádků)\n";
            $total++;
        } catch (\PDOException $e) {
            echo "  - $t (skipped: " . $e->getMessage() . ")\n";
            $failed++;
        }
        continue;
    }
    if ($dryRun) {
        echo sprintf("  TRUNCATE %-40s %6d řádků\n", $t, (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn());
        $total++;
        continue;
    }
    try {
        $pdo->exec("TRUNCATE TABLE `$t`");
        echo "  ✓ $t\n";
        $total++;
    } catch (\PDOException $e) {
        // Fallback DELETE — TRUNCATE může v některých případech selhat i s FK_CHECKS=0.
        try {
            $pdo->exec("DELETE FROM `$t`");
            if (isset($versionedTables[$t])) {
                $pdo->exec("DELETE HISTORY FROM `$t`");
                echo "  ✓ $t (DELETE + HISTORY)\n";
            } else {
                echo "  ✓ $t (DELETE)\n";
            }
            $total++;
        } catch (\PDOException $e2) {
            echo "  - $t (skipped: " . $e2->getMessage() . ")\n";
            $failed++;
        }
    }
}

if (!$dryRun) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

if (!$dryRun && $propertyPlan !== null) {
    try {
        PropertyResetCleanup::apply($pdo, $propertyPlan);
    } catch (\Throwable $e) {
        fwrite(STDERR, '[reset] ' . $e->getMessage() . "\n");
        $failed++;
    }
}

// Značky „ukázka už byla založena" zůstávají se supplierem. Vynulovat se smí jen ta,
// jejíž ukázku reset smazal; jinak by se ukázka, kterou si uživatel vědomě smazal,
// vrátila (vzor importu docházky i ukázkové napojení jsou konfigurace a zůstávají).
if ($keepUsersSupplier) {
    $markers = $pdo->query("SHOW COLUMNS FROM supplier LIKE '%\\_sample\\_seeded\\_at'")->fetchAll(\PDO::FETCH_COLUMN);
    foreach ($markers as $column) {
        $sampleTable = GlobalSeedTables::SAMPLE_MARKERS[$column] ?? null;
        if ($sampleTable !== null && in_array($sampleTable, $keep, true)) {
            continue;
        }
        if ($dryRun) {
            echo "  RESET    supplier.{$column}\n";
            continue;
        }
        $pdo->exec("UPDATE supplier SET `{$column}` = NULL");
        echo "  ✓ supplier.{$column} vynulováno\n";
    }
}

// Soubory firem: jen adresáře sup-N / supplier-N firem TÉTO databáze (viz ResetStorageScope).
$storagePlan = ResetStorageScope::plan(RuntimePaths::storage(), $ownSupplierIds, !$keepUsersSupplier);
echo "\n[reset] Soubory firem v " . RuntimePaths::storage() . ":\n";
if ($skipFiles) {
    echo "  --skip-files: soubory se nemažou.\n";
} elseif ($storagePlan['foreign'] !== [] && !$forceFiles) {
    echo "  ! Úložiště obsahuje " . count($storagePlan['foreign']) . " adresářů firem, které tahle databáze nezná\n";
    echo "    (sdílí ho jiná instance, nebo zbytky starších resetů). Soubory se NEMAŽOU,\n";
    echo "    jiná databáze může mít firmu se stejným id. Nastav vlastní MYINVOICE_DATA_DIR,\n";
    echo "    nebo po kontrole spusť znovu s --force-files (smaže jen firmy této databáze).\n";
} else {
    foreach ($storagePlan['own'] as $path) {
        if ($dryRun) {
            echo "  · $path\n";
            continue;
        }
        $count = ResetStorageScope::remove($path);
        echo "  ✓ $path ($count souborů)\n";
    }
    if ($storagePlan['own'] === []) {
        echo "  (žádné)\n";
    }
}

// Cache se po smazání přegenerují, sdílet je nevadí.
$dirs = [
    RuntimePaths::storage('cache/mpdf'),
    RuntimePaths::storage('cache/twig'),
];
echo $dryRun ? "\n[reset] DRY-RUN — cache adresáře by se vyčistily:\n" : "\n[reset] Čistím cache adresáře…\n";
foreach ($dirs as $d) {
    if (is_dir($d)) {
        if ($dryRun) {
            echo "  · $d\n";
            continue;
        }
        $count = wipeDir($d);
        echo "  ✓ $d ($count souborů)\n";
    }
}

// Značka „setup hotový" a cache schématu. Bez smazání značky by po resetu, který
// vymazal `users`, FirstRunLock dál tvrdil, že instalace je inicializovaná, a
// uživatel by místo setup wizardu viděl login, do kterého se nemá čím přihlásit.
// S --keep-users-supplier účty zůstávají → značka platí dál a sahat na ni nesmíme.
if (!$dryRun) {
    if (!$keepUsersSupplier && \MyInvoice\Infrastructure\Config\InstallStateCache::invalidate()) {
        echo "\n[reset] Značka „setup hotový\" zrušena.\n";
    }
    $connection->invalidateSchemaCache();
}

// Zruš setup-time MFA přepínače v cfg.local.php (jinak by stará hodnota přežila nový setup).
// S --keep-users-supplier účet zůstává → NEsahej na auth policy (nesnižuj bezpečnost).
if (!$keepUsersSupplier && !$dryRun) {
    try {
        CfgLocalWriter::setKeys(CfgLocalWriter::resolveTargetDir($rootDir), [
            'auth.require_mfa' => null,
            'auth.allowed_mfa_methods' => ['passkey', 'totp'],
            'auth.require_totp' => false,
        ]);
        echo "\n[reset] cfg.local.php: MFA politika vrácena na výchozí hodnoty\n";
    } catch (\Throwable $e) {
        echo "\n[reset] cfg.local.php: nelze zapsat (" . $e->getMessage() . ") — uprav ručně, pokud potřebuješ.\n";
    }
}

echo "\n================================================\n";
if ($dryRun) {
    echo "  DRY-RUN. Smazalo by se $total tabulek. Nic se NEZMĚNILO.\n";
} else {
    echo $failed === 0
        ? "  HOTOVO. Vymazáno $total tabulek.\n"
        : "  HOTOVO S CHYBAMI. Vymazáno $total tabulek, nesmazáno $failed tabulek.\n";
    echo $keepUsersSupplier
        ? "  Účet a dodavatel zůstaly zachované — můžeš rovnou zadávat reálná data.\n"
        : "  Spusť `php api/bin/setup.php` pro nové úvodní nastavení.\n";
}
echo "================================================\n";

if ($failed > 0) {
    exit(1);
}

function wipeDir(string $dir): int
{
    $count = 0;
    $iter = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $f) {
        if ($f->isDir()) {
            @rmdir($f->getPathname());
        } else {
            if (@unlink($f->getPathname())) $count++;
        }
    }
    return $count;
}
