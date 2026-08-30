<?php

declare(strict_types=1);

/**
 * SAMPLE DATA — generuje testovací data pro vývoj.
 *
 *   php api/bin/sample.php                 # interaktivní potvrzení
 *   php api/bin/sample.php --yes           # bez ptaní
 *   php api/bin/sample.php --supplier=7    # konkrétní firma
 *   php api/bin/sample.php --list          # výpis firem a jestli už mají data
 *
 * Bez `--supplier` se vezme jediná firma v databázi. Jakmile je jich víc, skript
 * nehádá — vypíše je i s tím, která je prázdná, a čeká na explicitní volbu. Dřív
 * mlčky sáhl po `MIN(id)`, což u instalace s víc firmami nasypalo ukázková data
 * do té nesprávné.
 *
 * Vytvoří pro zvolenou firmu rozsáhlý syntetický dataset za posledních 12 měsíců:
 *   - 24 klientů, 36 zakázek, 120 vydaných faktur a 12 dobropisů
 *   - 12 dodavatelů a 120 přijatých faktur
 *   - 1 pokladna a 7 příjmových/výdajových pokladních dokladů
 *   - pro s.r.o./PO nebo plátce DPH také podvojné účetnictví, sklad,
 *     120 skladových dokladů, 2 majetky, 6 bankovních výpisů / 120 pohybů
 *     3 e-shopové kategorie, 3 výrobci, zaúčtovaný účetní deník a ukázkové
 *     případy ve všech frontách sekce Automatizace
 *   - kniha jízd: 1 firemní auto, 15 jízd, 6 tankování
 *
 * Vyžaduje již proběhlý `setup.php` (admin user + supplier v DB).
 *
 * Doporučené pořadí spouštění (fresh dev install):
 *   1. cp cfg.sample.php cfg.php  +  vyplň db/smtp/pepper
 *   2. php api/bin/setup.php       # interaktivně: migrace + supplier + admin
 *   3. php api/bin/sample.php      # tento skript — testovací data
 *   ── později ──
 *      php api/bin/reset.php       # wipe všeho, pak znovu setup + sample
 *
 * Logika je sdílená s HTTP setup wizardem (POST /api/auth/setup-sample) —
 * implementace v `Service\Sample\SampleDataGenerator`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403); exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Sample\SampleDataGenerator;

$autoYes = in_array('--yes', $argv, true) || in_array('-y', $argv, true);
$listOnly = in_array('--list', $argv, true);

// `--supplier=7` i `--supplier 7`; prázdná nebo nečíselná hodnota je chyba, ne tichý pád na null.
$wantedSupplier = null;
foreach ($argv as $i => $arg) {
    if (str_starts_with($arg, '--supplier=')) {
        $wantedSupplier = substr($arg, strlen('--supplier='));
    } elseif ($arg === '--supplier') {
        $wantedSupplier = $argv[$i + 1] ?? '';
    }
}
if ($wantedSupplier !== null && preg_match('/^[1-9]\d*$/', $wantedSupplier) !== 1) {
    fwrite(STDERR, "[sample] --supplier čeká ID firmy, dostal '{$wantedSupplier}'.\n");
    fwrite(STDERR, "         Seznam firem: php api/bin/sample.php --list\n");
    exit(1);
}
$wantedSupplierId = $wantedSupplier === null ? null : (int) $wantedSupplier;

$app = Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(Connection::class)->pdo();

$adminId = (int) $pdo->query(
    "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
      WHERE r.system_key = 'superadmin' AND r.role_type = 'superadmin' AND r.is_active = 1
        AND u.is_active = 1 ORDER BY u.id LIMIT 1"
)->fetchColumn();

// Obsazenost počítáme stejnou trojicí jako guard v SampleDataGeneratoru, aby
// výpis nikdy netvrdil „prázdná" o firmě, kterou generátor vzápětí odmítne.
$suppliers = $pdo->query(
    'SELECT s.id, s.company_name,
            (SELECT COUNT(*) FROM clients           c WHERE c.supplier_id = s.id)
          + (SELECT COUNT(*) FROM invoices          i WHERE i.supplier_id = s.id)
          + (SELECT COUNT(*) FROM purchase_invoices p WHERE p.supplier_id = s.id) AS records
       FROM supplier s ORDER BY s.id'
)->fetchAll(\PDO::FETCH_ASSOC);

$printSuppliers = static function (array $rows): void {
    foreach ($rows as $row) {
        $state = (int) $row['records'] > 0
            ? sprintf('má data (%d klientů/dokladů)', (int) $row['records'])
            : 'prázdná — lze generovat';
        fwrite(STDERR, sprintf("           --supplier=%-4d %-40s %s\n", (int) $row['id'], (string) $row['company_name'], $state));
    }
};

if ($listOnly) {
    if ($suppliers === []) {
        fwrite(STDERR, "[sample] V databázi není žádná firma. Spusť php api/bin/setup.php\n");
        exit(1);
    }
    fwrite(STDERR, "[sample] Firmy v databázi:\n");
    $printSuppliers($suppliers);
    exit(0);
}

if ($adminId === 0 || $suppliers === []) {
    fwrite(STDERR, sprintf("[sample] Chybí předpoklady (admin: %d, firem: %d).\n", $adminId, count($suppliers)));
    fwrite(STDERR, "[sample] Spusť nejdřív interaktivní setup:\n         php api/bin/setup.php\n");
    exit(1);
}

$byId = [];
foreach ($suppliers as $row) {
    $byId[(int) $row['id']] = $row;
}

if ($wantedSupplierId !== null) {
    if (!isset($byId[$wantedSupplierId])) {
        fwrite(STDERR, "[sample] Firma #$wantedSupplierId v databázi není. Na výběr je:\n");
        $printSuppliers($suppliers);
        exit(1);
    }
    $supplier = $byId[$wantedSupplierId];
} elseif (count($suppliers) === 1) {
    $supplier = $suppliers[0];
} else {
    // Víc firem a žádná volba: netipovat. Dřív se mlčky vzalo MIN(id) a data
    // spadla do první založené firmy, i když prázdná byla úplně jiná.
    $count = count($suppliers);
    fwrite(STDERR, sprintf(
        "[sample] V databázi jsou %d %s — vyber, do které se má generovat:\n",
        $count,
        $count < 5 ? 'firmy' : 'firem'
    ));
    $printSuppliers($suppliers);
    exit(1);
}

$supplierId = (int) $supplier['id'];
$supplierName = (string) $supplier['company_name'];

// Guard: sample data se generují JEN do prázdné firmy (stejně jako HTTP setup wizard).
// Bez něj druhý běh duplikoval klienty/doklady a padal na UNIQUE (cars.registration).
if ((int) $supplier['records'] > 0) {
    fwrite(STDERR, "[sample] Firma #$supplierId ($supplierName) už má klienty nebo doklady —\n");
    fwrite(STDERR, "         ukázková data lze generovat jen do prázdné firmy.\n");
    $empty = array_values(array_filter($suppliers, static fn (array $r): bool => (int) $r['records'] === 0));
    if ($empty !== []) {
        fwrite(STDERR, "         Prázdné firmy, do kterých generovat lze:\n");
        $printSuppliers($empty);
    }
    fwrite(STDERR, "         Nebo tuhle nejdřív vyprázdni:\n");
    fwrite(STDERR, "           php api/bin/reset.php --keep-users-supplier   (smaže jen byznys data)\n");
    fwrite(STDERR, "           php api/bin/reset.php                         (úplný reset)\n");
    fwrite(STDERR, "         nebo v aplikaci: Nastavení → Odebrat ukázková data.\n");
    exit(1);
}

echo "================================================\n";
echo "  MyÚčto.cz — ROZŠÍŘENÁ UKÁZKOVÁ DATA\n";
echo "================================================\n";
echo "  Supplier:   #$supplierId ($supplierName)\n";
echo "  Admin:      #$adminId\n";
echo "  Vygeneruje: 24 klientů, 12 dodavatelů, 120 vydaných + 120 přijatých faktur,\n";
echo "              pokladnu se 7 doklady, sklad, e-shopové číselníky, majetek, banku a deník.\n";
echo "  Období:     posledních 12 měsíců\n";
echo "================================================\n\n";

if (!$autoYes) {
    echo "Pokračovat? (ANO): ";
    if (trim((string) fgets(STDIN)) !== 'ANO') { echo "Zrušeno.\n"; exit(0); }
}

$generator = $container->get(SampleDataGenerator::class);
try {
    $r = $generator->generate($supplierId, $adminId);
} catch (\Throwable $e) {
    fwrite(STDERR, "[sample] Generování selhalo: " . $e->getMessage() . "\n");
    exit(1);
}

echo "================================================\n";
printf("  HOTOVO. %d klientů, %d dodavatelů, %d zakázek, %d vydaných a %d přijatých faktur.\n", $r['clients'], $r['vendors'], $r['projects'], $r['invoices'], $r['purchase_invoices']);
printf("          %d dobropisů, %d skladových dokladů, %d majetky, %d výpisů / %d pohybů.\n", $r['credit_notes'], $r['stock_documents'], $r['assets'], $r['bank_statements'], $r['bank_transactions']);
printf("          Pokladna: %d / %d dokladů. E-shop: %d kategorie / %d výrobci.\n", $r['cash_registers'], $r['cash_documents'], $r['eshop_categories'], $r['manufacturers']);
printf("          Účetní deník: %d zaúčtovaných zápisů. Podvojné účetnictví: %s.\n", $r['journal_entries'], $r['accounting_enabled'] ? 'ano' : 'ne');
printf("          Automatizace: %d samo / %d ke schválení / %d potřebuje mě / %d potvrzeno.\n", $r['automation_auto'], $r['automation_pending'], $r['automation_needs_input'], $r['automation_approved']);
printf("          Kniha jízd: %d auto, %d jízd, %d tankování.\n", $r['cars'], $r['trips'], $r['fuelings']);
foreach ($r['warnings'] as $warning) fwrite(STDERR, "[sample] Upozornění: {$warning}\n");
echo "================================================\n";
