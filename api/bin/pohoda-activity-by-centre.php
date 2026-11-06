<?php

declare(strict_types=1);

/**
 * Přeřazení činnosti neziskové organizace podle střediska po převodu z POHODY: řádky nákladů
 * a výnosů převedených zápisů dostanou hlavní nebo hospodářskou činnost podle tabulky účetní.
 *
 *   php api/bin/pohoda-activity-by-centre.php --supplier=<id> --map="VÝUKA=main,ZvukSvětlo=economic" [--dry-run]
 *
 * Řádky bez střediska a se střediskem mimo mapu zůstávají. Uzavřená období, řádky s rozpadem
 * a stornované zápisy se přeskočí a vypíší v souhrnu. Opakované spuštění nic nezmění.
 *
 * Návratové kódy: 0 = hotovo, 1 = chyba běhu, 2 = chybné použití nebo neplatná mapa.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Pohoda\NonprofitActivityByCentre;
use MyInvoice\Service\Migration\Pohoda\PohodaException;

const PABC_USAGE = <<<TXT
Použití:
  php api/bin/pohoda-activity-by-centre.php --supplier=<id> --map="VÝUKA=main,ZvukSvětlo=economic" [--dry-run]

TXT;

function pabcFail(string $message, int $code = 2): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

$opts = getopt('', ['supplier:', 'map:', 'dry-run']);
if (!isset($opts['supplier'], $opts['map']) || (int) $opts['supplier'] <= 0) {
    pabcFail(PABC_USAGE);
}
$dryRun = array_key_exists('dry-run', $opts);

try {
    $map = NonprofitActivityByCentre::parseMap((string) $opts['map']);
    $container = Bootstrap::buildContainer();
    $stmt = $container->get(Connection::class)->pdo()->prepare('SELECT company_name FROM supplier WHERE id = ?');
    $stmt->execute([(int) $opts['supplier']]);
    $company = $stmt->fetchColumn();
    if ($company === false) {
        pabcFail('Firma nenalezena.');
    }
    $result = $container->get(NonprofitActivityByCentre::class)->run((int) $opts['supplier'], $map, $dryRun);
} catch (PohodaException $e) {
    pabcFail($e->getMessage());
} catch (\Throwable $e) {
    pabcFail('Chyba: ' . $e->getMessage(), 1);
}

$labels = ['main' => 'hlavní', 'economic' => 'hospodářská'];
$outcomes = [
    NonprofitActivityByCentre::OUTCOME_CHANGED => $dryRun ? 'přeřadí se' : 'přeřazeno',
    NonprofitActivityByCentre::OUTCOME_UNCHANGED => 'beze změny',
    NonprofitActivityByCentre::OUTCOME_SPLIT => 'přeskočeno: rozpad řádku',
    NonprofitActivityByCentre::OUTCOME_REVERSED => 'přeskočeno: storno',
    NonprofitActivityByCentre::OUTCOME_CENTRE_CHANGED => 'přeskočeno: středisko změněné v MyÚčtu',
    NonprofitActivityByCentre::OUTCOME_NOT_504 => 'přeskočeno: období mimo vyhlášku 504',
    'period_not_open' => 'přeskočeno: uzavřené období',
];

echo sprintf("%s%s (firma %d)\n", $dryRun ? 'Zkouška nanečisto: ' : '', $company, (int) $opts['supplier']);
foreach ($result['rows'] as $row) {
    echo sprintf(
        "  %s -> %s: %d řádků, MD %s, D %s (%s)\n",
        $row['centre'], $labels[$row['activity']], $row['lines'], $row['debit'], $row['credit'],
        $outcomes[$row['outcome']] ?? 'přeskočeno: ' . $row['outcome'],
    );
}
echo sprintf(
    "Přeřazeno: %d, beze změny: %d, přeskočeno: %d, bez střediska: %d\n",
    $result['changed'], $result['unchanged'], $result['skipped'], $result['no_centre'],
);
foreach ($result['outside_map'] as $code => $lines) {
    echo sprintf("Středisko mimo mapu: %s, %d řádků zůstává\n", $code, $lines);
}
foreach ($result['unknown_centres'] as $code) {
    echo sprintf("Středisko z mapy bez řádků: %s (kód se porovnává přesně)\n", $code);
}
