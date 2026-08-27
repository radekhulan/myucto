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
 *
 * DYNAMICKÉ mazání: vymaže VŠECHNY tabulky kromě keep-listu (viz níže $keep),
 *       takže nezaostává za schématem — nové tabulky (vč. secretů: IMAP hesla,
 *       podpisové certifikáty) se po migraci automaticky vyčistí.
 * Ponechává (globální číselníky + schema): countries, vat_rates, units,
 *       tax_constants, exchange_rates (cache ČNB kurzů — drahé refetchnout), migrations,
 *       statement_versions/statement_rows/statement_account_map (výkazy, seed 1012),
 *       cnb_repo_rates (seed 1048), systémové role a jejich oprávnění (seed 1074).
 *       S --keep-cache navíc ares_cache/vies_cache/crpdph_cache.
 * Globální seed (supplier_id IS NULL) zůstává u: vat_classifications,
 *       bank_email_notice_providers, posting_rules — maže se jen per-tenant.
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

$args = array_flip(array_slice($argv, 1));
$autoYes   = isset($args['--yes']) || isset($args['-y']);
$keepCache = isset($args['--keep-cache']);
$keepUsersSupplier = isset($args['--keep-users-supplier']);
$dryRun    = isset($args['--dry-run']);

$rootDir = Bootstrap::rootDir();

try {
    $config = Config::load($rootDir);
    $pdo    = (new Connection($config))->pdo();
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
    echo "       (měny, číslování, podepisování, e-mail/banka config, číselníky,\n";
    echo "        účtovou osnovu, předkontace a nastavení účetnictví).\n";
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
// se šifrovanými secrety). Pokud přibude nová GLOBÁLNÍ tabulka, přidej ji do $keep,
// jinak se taky smaže.
$keep = [
    'countries',      // globální číselník zemí
    'vat_rates',      // globální sazby DPH
    'units',          // globální měrné jednotky
    'tax_constants',  // globální daňové konstanty
    'exchange_rates', // cache kurzů ČNB — drahé refetchnout
    'migrations',     // evidence schématu
    // Účetní globální seedy z migrací — migrate.php je znovu nenaseeduje
    // (migrace jsou evidované jako proběhlé), proto se mazat NESMÍ.
    'statement_versions',    // definice výkazů (rozvaha/VZZ, vyhl. 500/2002) — seed 1012
    'statement_rows',        // řádky výkazů — seed 1012
    'statement_account_map', // mapování účtů na řádky výkazů — seed 1012
    'cnb_repo_rates',        // repo sazby ČNB pro úrok z prodlení — seed 1048
    'bank_rule_templates',   // globální šablony bankovních pravidel — seed 1056
    'remittance_map',        // globální mapa odvodů na účty ČNB — seed 1056
    // ⚠️ Provozní nastavení instalace, NE uživatelská data. Když řádek zmizí,
    // spadne režim plánovaných úloh zpátky na `individual` — a na instalaci,
    // kde je hostingem nastavený `dispatcher`, se dispatcher ukončí bez práce
    // a NEBĚŽÍ NIC. Heartbeat přitom tiká dál, takže se na to přijde až
    // z monitoringu poskytovatele. Reset uživatelských dat tohle měnit nemá.
    'cron_settings',         // režim plánovaných úloh — migrace 1184/1320
];
// ARES/VIES/CRPDPH cache — defaultně mažeme, s --keep-cache ponecháme.
if ($keepCache) {
    $keep = array_merge($keep, ['ares_cache', 'vies_cache', 'crpdph_cache']);
}

// Tabulky s globálním seedem (supplier_id IS NULL) — smaž jen per-tenant řádky.
$partial = [
    'vat_classifications'         => 'supplier_id IS NOT NULL',
    'bank_email_notice_providers' => 'supplier_id IS NOT NULL', // ponech globální bankovní providery
    'posting_rules'               => 'supplier_id IS NOT NULL', // ponech globální předkontace (seed 1006+)
    'role_permissions'            => 'role_id NOT IN (SELECT id FROM roles WHERE system_key IS NOT NULL)',
    'roles'                       => 'system_key IS NULL', // ponech systémové role (seed 1074)
];

// --keep-users-supplier: zachovej účet(y) + dodavatele + jeho KONFIGURACI (měny, číslování,
// podepisování, e-mail/banka config, číselníky), smaž jen BYZNYS data (klienti, doklady,
// banka, dokumenty, kniha jízd, recurring, importy, daňová podání…). „Start fresh" se
// zachovaným přihlášením a firmou — netřeba znovu setup. Užitečné i pro úklid duplicitních
// sample dat, která vznikla bez evidence (issue #162).
if ($keepUsersSupplier) {
    $keep = array_merge($keep, [
        // Účet a přihlášení
        'users', 'sessions', 'trusted_devices', 'login_otps',
        // Dynamické role musí zůstat spolu s uživateli a per-firma override
        'roles', 'role_permissions', 'user_suppliers',
        // Identita dodavatele + měny + číslování dokladů
        'supplier', 'currencies', 'invoice_counters', 'purchase_invoice_counters', 'app_meta',
        // API tokeny (PAT)
        'api_tokens',
        // Podepisování PDF (konfigurace + klíče)
        'signing_profiles', 'signing_credentials', 'signing_settings',
        'signature_role_profiles', 'signature_user_profiles', 'signature_document_overrides',
        'pdf_signature_output_settings',
        // E-mail / bankovní avíza (konfigurace, NE zpracované zprávy)
        'bank_email_imap_settings', 'bank_email_account_mappings', 'email_templates', 'email_profiles',
        // Vlastní bankovní účty — konfigurace, ne pohyby. Bez nich by
        // bank_email_account_mappings (výše) ukazovalo na neexistující účty a firma
        // by po resetu neměla kam účtovat banku (analytic_suffix, viz 1109).
        'supplier_bank_accounts',
        // ÚČETNÍ KONFIGURACE. Účtová osnova musí přežít spolu se supplierem: firma si
        // v `supplier.accounting_mode` nese double_entry, ale ChartOfAccountsSeeder se
        // volá jen z aktivace účetnictví / změny režimu — ne per-request. Bez osnovy by
        // tenhle režim skončil se zapnutým účetnictvím a prázdným účtovým rozvrhem.
        'chart_of_accounts', 'accounting_supplier_settings', 'accounting_document_series',
        'auto_posting_policy', 'expense_classification_rules',
        'journal_entry_templates', 'journal_entry_template_lines',
        'bank_posting_rules',
        // Per-supplier číselníky
        'expense_categories', 'revenue_categories', 'trip_categories',
        // Daňové profily VČETNĚ vazebních tabulek — samotné tax_profiles bez dětí
        // by zůstaly jako neúplný profil (děti, manžel/ka, činnosti).
        'tax_profiles', 'tax_profile_activities', 'tax_profile_children',
        'tax_profile_child_months', 'tax_profile_spouse_claims',
        // POZOR: sklady, stromy kategorií a pokladny se ZÁMĚRNĚ NEZACHOVÁVAJÍ, i když
        // vypadají jako konfigurace. Jsou to kontejnery byznys dat, která tenhle režim
        // maže (skladové karty a pohyby, pokladní doklady), takže by zůstaly prázdné —
        // a hlavně: `sample_data_entries` se maže, takže sklad z ukázkových dat by přežil
        // BEZ evidence. Sample generátor pak padal na `uq_wh_supplier_code`
        // („Duplicate entry '1-HLAVNI'") a purge už ten sirotek neuměl uklidit.
    ]);
    // vat_classifications + bank_email_notice_providers jsou konfigurace → ponech CELÉ.
    unset($partial['vat_classifications'], $partial['bank_email_notice_providers']);
    $keep[] = 'vat_classifications';
    $keep[] = 'bank_email_notice_providers';
    // Předkontace jsou taky konfigurace — v tomhle režimu ponech i tenant override
    // (např. přesměrování 501 → 501.900 ze seedu analytik), ne jen globální seed.
    unset($partial['posting_rules']);
    $keep[] = 'posting_rules';
}

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

// PDF cache + storage cleanup — vč. přijaté faktury archive + XSD (necháváme)
$dirs = [
    \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices'),
    \MyInvoice\Infrastructure\Config\RuntimePaths::storage('purchase-invoices'),  // archive PDF dodavatelů (fáze 1)
    \MyInvoice\Infrastructure\Config\RuntimePaths::storage('documents'),          // sekce Dokumenty (soubory, náhledy, joby)
    \MyInvoice\Infrastructure\Config\RuntimePaths::storage('cache/mpdf'),
    \MyInvoice\Infrastructure\Config\RuntimePaths::storage('cache/twig'),
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
    \MyInvoice\Infrastructure\Database\SchemaCache::invalidate(
        \MyInvoice\Infrastructure\Database\SchemaCache::pathFor(
            $config->dataDir() ?? $rootDir,
            (string) $config->get('db.name', ''),
        ),
    );
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
