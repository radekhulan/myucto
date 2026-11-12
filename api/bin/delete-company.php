<?php

declare(strict_types=1);

/**
 * Smazání firmy (dodavatele) se všemi jejími daty z databáze instance.
 *
 *   php api/bin/delete-company.php --id=7                      # náhled: co by se smazalo
 *   php api/bin/delete-company.php --id=7 --confirm=12345678   # smazat (potvrzení IČO firmy)
 *   php api/bin/delete-company.php --id=7 --confirm=12345678 --files
 *
 * Hodí se pro zopakování převodu do čisté firmy nebo úklid zkušební firmy. Stejnou službu
 * ({@see CompanyPurgeService}) používá smazání firmy v aplikaci. Smaže doklady, deník,
 * mzdy a vše ostatní. Nelze to vrátit - před smazáním si udělejte zálohu databáze.
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
 * běžné dotazy aplikace je nevidí. Auditní stopa `activity_log` (zřetězená hashem) zůstává. Tabulky jen pro přidávání (mzdy, podání, audit) mají
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
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\System\CompanyPurgeService;

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

try {
    $container = Bootstrap::buildContainer();
    $service = $container->get(CompanyPurgeService::class);
    $plan = $service->preview($supplierId, isset($opts['files']));
    $ico = $plan['supplier']['ic'];
    echo sprintf("Databáze %s, firma %d: %s (IČO %s)\n", $plan['schema'], $supplierId, $plan['supplier']['company_name'], $ico !== '' ? $ico : '-');
    foreach ($plan['children'] as $child) {
        echo sprintf("  %-48s %8d  (podřízené řádky přes %s)\n", $child['table'], $child['rows'], $child['via']);
    }
    $listed = $plan['counts'];
    arsort($listed);
    foreach ($listed as $table => $n) {
        echo sprintf("  %-48s %8d\n", $table, $n);
    }
    echo sprintf("Celkem %d řádků v %d tabulkách, podřízené řádky smaže databáze vazbou.\n", $plan['total'], count($plan['counts']));
    foreach ($plan['files'] as $path) {
        echo '  úložiště: ' . $path . "\n";
    }
    foreach ($plan['guards'] as $trigger => $table) {
        echo sprintf("  trigger %s hlídá mazání v %s\n", $trigger, $table);
    }

    if (!isset($opts['confirm'])) {
        echo "\nNáhled - nic se nesmazalo. Smazání: --confirm=<IČO firmy>"
            . ($plan['guards'] !== [] ? ' --immutable' : '') . ".\n";
        exit(0);
    }
    if ($ico === '' || (string) $opts['confirm'] !== $ico) {
        fwrite(STDERR, "--confirm musí být IČO firmy ({$ico}).\n");
        exit(2);
    }
    if ($plan['guards'] !== [] && !isset($opts['immutable'])) {
        fwrite(STDERR, "Firma má řádky v tabulkách jen pro přidávání (viz triggery výše). Smazání s nimi: přidejte --immutable.\n");
        exit(2);
    }

    $result = $service->purge($supplierId, isset($opts['immutable']), isset($opts['files']));
    if ($result['cycle'] !== []) {
        echo sprintf("  cyklus vazeb (%s) smazán s ověřením, že nezůstal žádný odkaz\n", implode(', ', $result['cycle']));
    }
    if ($result['triggers'] > 0) {
        echo sprintf("Obnoveno %d triggerů.\n", $result['triggers']);
    }
    echo sprintf("Smazáno %d řádků v tabulkách firmy (bez podřízených řádků smazaných vazbou).\n", $result['deleted']);
    if ($result['files'] > 0) {
        echo sprintf("Smazáno %d položek úložiště (%d souborů).\n", $result['files'], $result['files_removed']);
    }
    $container->get(ActivityLogger::class)->log('supplier.deleted', null, 'supplier', $supplierId, [
        'source' => 'cli',
        'company_name' => $plan['supplier']['company_name'],
        'ic' => $ico,
        'rows' => $plan['total'],
        'tables' => count($plan['counts']),
        'files' => $result['files_removed'],
    ]);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Chyba: ' . $e->getMessage() . "\n");
    exit(1);
}
