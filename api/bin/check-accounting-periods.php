<?php

declare(strict_types=1);

/**
 * Kontrola (a volitelně náprava) účetních období pro EXISTUJÍCÍ instalace.
 *
 * Chybějící účetní období se v aplikaci projeví až ve chvíli, kdy někdo klikne na
 * „Zaúčtovat" — a to hláškou, která ho pošle jinam, než kde se období zakládá. Nová
 * instalace tenhle stav nemá, protože jí ho založí setup/aktivace; už běžící instalace
 * ale ano, a nikdo o tom neví, dokud na to nenarazí. Tenhle skript ho najde dopředu.
 *
 * Hlásí tři různé stavy (rozlišuje je {@see AccountingPeriodHealthService}):
 *   - `no_periods`        … firma nemá jediné období → patří do průvodce aktivací
 *                           účetnictví, ne do tohohle skriptu (hranice prvního období
 *                           a počáteční rozvaha jsou účetní rozhodnutí),
 *   - `current_missing`   … řada existuje, ale nepokrývá dnešek (zapomenutý přelom
 *                           roku) → `--fix` opraví,
 *   - `documents_outside` … doklady k zaúčtování s datem mimo jakékoli období (import
 *                           historie) → `--fix` doplní i tyhle roky.
 *
 * CO `--fix` DĚLÁ A CO NE
 * ------------------------------------------------------------------------------
 * Zakládá VÝHRADNĚ období, které NEEXISTUJE, a to přes tentýž
 * {@see AccountingPeriodProvisioner}, který období otevírá při účtování a importu —
 * žádné druhé pravidlo. Existující řádek se nikdy nemění, takže se nelze dotknout
 * stavu closing/closed/approved (§35 ZoÚ). Hranice se dědí z existující řady, takže
 * hospodářský rok (§21a ZDP) nedostane kalendářní pokračování.
 *
 * První období firmy bez jediného období (`no_periods`) skript nezakládá: jeho hranice
 * a počáteční rozvaha jsou účetní rozhodnutí a patří do průvodce aktivací.
 *
 * Bez `--fix` je skript READ-ONLY, takže ho lze pustit i proti produkci.
 *
 * Použití:
 *   php api/bin/check-accounting-periods.php                 # kontrola všech firem (read-only)
 *   php api/bin/check-accounting-periods.php --supplier=1    # jen jedna firma
 *   php api/bin/check-accounting-periods.php --fix           # + doplnit chybějící období
 *
 * Argumenty:
 *   --supplier=<id>   (volitelné) jen tahle firma; bez něj všechny s podvojným účetnictvím
 *   --fix             doplnit chybějící období (nikdy nemění existující)
 *   --quiet           vypsat jen firmy s nálezem
 *
 * Návratový kód: 0 = bez nálezu (nebo vše opraveno), 1 = zbývá nález, 2 = chyba argumentů.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingPeriodHealthService;
use MyInvoice\Service\Accounting\AccountingPeriodProvisioner;

/** @return string|null hodnota --key=value nebo null */
function argValue(array $argv, string $key): ?string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, "--{$key}=")) {
            return substr($a, strlen($key) + 3);
        }
    }
    return null;
}

$supplierArg = argValue($argv, 'supplier');
$supplierId = $supplierArg === null ? null : (int) $supplierArg;
$fix = in_array('--fix', $argv, true);
$quiet = in_array('--quiet', $argv, true);

if ($supplierId !== null && $supplierId <= 0) {
    fwrite(STDERR, "Neplatné --supplier=<id>.\n");
    exit(2);
}

$container = Bootstrap::buildApp()->getContainer();
$connection = $container->get(Connection::class);
$pdo = $connection->pdo();
$health = new AccountingPeriodHealthService($connection);
$provisioner = $container->get(AccountingPeriodProvisioner::class);

$sql = "SELECT id, COALESCE(NULLIF(display_name,''), company_name) AS name
          FROM supplier
         WHERE accounting_mode = 'double_entry' AND accounting_enabled = 1";
$params = [];
if ($supplierId !== null) {
    $sql .= ' AND id = ?';
    $params[] = $supplierId;
}
$sql .= ' ORDER BY id';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($suppliers === []) {
    echo "Žádná firma s podvojným účetnictvím — není co kontrolovat.\n";
    exit(0);
}

$today = date('Y-m-d');
echo ($fix ? '' : '[READ-ONLY] ') . "Kontrola účetních období k {$today} — firem: " . count($suppliers) . "\n\n";

$findings = 0;
$fixed = 0;
foreach ($suppliers as $row) {
    $sid = (int) $row['id'];
    $name = (string) $row['name'];
    $state = $health->diagnose($sid, $today);

    if ($fix && in_array($state['state'], ['current_missing', 'documents_outside'], true)) {
        // Guardy jsou uvnitř provisioneru: existující období se nemění (§35 ZoÚ),
        // hranice se dědí z řady, daňová evidence se přeskočí.
        $before = $state;
        $provisioner->ensureOpenPeriodForDate($sid, $today, AccountingPeriodProvisioner::REASON_MAINTENANCE);
        if ($state['outside_count'] > 0) {
            // Rozsah dokladů mimo období naráz — tentýž vstupní bod, který používá import.
            $provisioner->ensureOpenPeriodsForRange(
                $sid,
                (string) $state['outside_min_date'],
                (string) $state['outside_max_date'],
                AccountingPeriodProvisioner::REASON_MAINTENANCE,
            );
        }
        $state = $health->diagnose($sid, $today);
        if ($state['state'] !== $before['state'] || $state['outside_count'] < $before['outside_count']) {
            $fixed++;
            printf("  #%-4d %-40s OPRAVENO — období doplněna\n", $sid, mb_substr($name, 0, 40));
            if ($state['state'] === 'ok') {
                continue;
            }
        }
    }

    if ($state['state'] === 'ok') {
        if (!$quiet) {
            printf("  #%-4d %-40s ok (období %s–%s)\n", $sid, mb_substr($name, 0, 40),
                (string) $state['earliest_starts_on'], (string) $state['latest_ends_on']);
        }
        continue;
    }

    $findings++;
    $label = strtoupper($state['severity']);
    printf("  #%-4d %-40s [%s] %s\n", $sid, mb_substr($name, 0, 40), $label, $state['state']);
    switch ($state['state']) {
        case 'no_periods':
            echo "        Firma nemá jediné účetní období — nedá se zaúčtovat nic.\n";
            echo "        Náprava: průvodce aktivací účetnictví (Účetnictví → Aktivace a doúčtování,\n";
            echo "        /admin/accounting-activation). Tenhle skript první období nezakládá záměrně:\n";
            echo "        jeho hranice a počáteční rozvaha jsou účetní rozhodnutí.\n";
            break;
        case 'current_missing':
            printf("        Poslední období končí %s, dnešek do žádného nespadá.\n", (string) $state['latest_ends_on']);
            echo $fix
                ? "        Automatické otevření neprošlo — zkontroluj řadu období ručně.\n"
                : "        Náprava: pusť znovu s --fix, nebo Účetnictví → Uzávěrka.\n";
            break;
        case 'documents_outside':
            printf(
                "        %d dokladů k zaúčtování má datum mimo období (%s–%s); řada je %s–%s.\n",
                $state['outside_count'],
                (string) $state['outside_min_date'],
                (string) $state['outside_max_date'],
                (string) $state['earliest_starts_on'],
                (string) $state['latest_ends_on'],
            );
            echo "        Typicky import historie z doby před zavedením účetnictví.\n";
            echo $fix
                ? "        Doplnění neprošlo — řada je nepravidelná (překryv) nebo je rok mimo rozsah 2000–2200.\n"
                : "        Náprava: pusť znovu s --fix (chybějící roky se doplní), nebo rozšiř účetnictví\n";
            if (!$fix) {
                echo "        na dřívější datum průvodcem aktivace (kvůli počátečním stavům).\n";
            }
            break;
    }
}

echo "\n═══ SOUHRN ═════════════════════════════════════════════════════\n";
printf("  firem zkontrolováno: %d\n", count($suppliers));
printf("  opraveno (--fix):    %d\n", $fixed);
printf("  zbývá nálezů:        %d\n", $findings);
echo "════════════════════════════════════════════════════════════════\n";
if (!$fix && $findings > 0) {
    echo "\n(read-only — nic nebylo zapsáno; navazující období otevřeš přepínačem --fix)\n";
}

exit($findings > 0 ? 1 : 0);
