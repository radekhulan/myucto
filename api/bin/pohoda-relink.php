<?php

declare(strict_types=1);

/**
 * Opravný převod vazeb z POHODY: nový export do už vedeného účetnictví firmy.
 *
 * Zápisy zadané v MyÚčtu zůstanou, nové doklady z POHODY se nepřidají, doplní se jen vazby
 * (daňové doklady k zálohám a platbám, úhrady, zápočty). Mzdy ani sklad se nepřevádějí.
 * Stejný převod nabízí průvodce Přechod z POHODY volbou „Opravit vazby už převedené firmy".
 * U agendy vedené v daňové evidenci navíc doplní pokladnu a pokladní doklady, které dřívější
 * převod nepřevzal (od prvního pokladního dokladu zadaného v MyÚčtu už ne), a úhrady z nich.
 * Logika je v {@see \MyInvoice\Service\Migration\Pohoda\PohodaRelinkRunner}.
 *
 * Výchozí je zkouška nanečisto, zápis jen s --commit. Každá agenda se zapíše do historie
 * převodů. Kód návratu: 0 = v pořádku, 1 = převod skončil chybou, 2 = neplatné zadání.
 *
 * Použití:
 *   php api/bin/pohoda-relink.php --supplier=12 --zip=/cesta/export.zip            # nanečisto, všechny agendy firmy
 *   php api/bin/pohoda-relink.php --supplier=12 --zip=/cesta/12345678_2026 --commit # složka agendy, zápis
 *   php api/bin/pohoda-relink.php --supplier=12 --zip=/cesta/export --year=2026     # jen agenda roku 2026
 *   volitelně --user=ID (autor zápisů a běhu v historii), --allow-filed-vat (daňová evidence: převzít pokladní
 *   doklady s DPH i do podaného období DPH; potom je potřeba dodatečné přiznání a následné KH)
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Migration\Pohoda\PohodaRelinkRunner;

$usage = "Použití: php api/bin/pohoda-relink.php --supplier=ID --zip=<ZIP exportu nebo složka agendy> [--year=RRRR] [--user=ID] [--allow-filed-vat] [--commit]\n";
$supplierId = null;
$source = null;
$year = null;
$userId = null;
$commit = false;
$allowFiledVat = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--commit') {
        $commit = true;
    } elseif ($arg === '--allow-filed-vat') {
        $allowFiledVat = true;
    } elseif (preg_match('/^--supplier=(\d+)$/', $arg, $m) === 1 && (int) $m[1] > 0) {
        $supplierId = (int) $m[1];
    } elseif (preg_match('/^--zip=(.+)$/', $arg, $m) === 1) {
        $source = $m[1];
    } elseif (preg_match('/^--year=(\d{4})$/', $arg, $m) === 1) {
        $year = (int) $m[1];
    } elseif (preg_match('/^--user=(\d+)$/', $arg, $m) === 1 && (int) $m[1] > 0) {
        $userId = (int) $m[1];
    } else {
        fwrite(STDERR, "Neznámý nebo neplatný parametr {$arg}.\n" . $usage);
        exit(PohodaRelinkRunner::EXIT_INVALID);
    }
}
if ($supplierId === null || $source === null) {
    fwrite(STDERR, $usage);
    exit(PohodaRelinkRunner::EXIT_INVALID);
}

$runner = Bootstrap::buildContainer()->get(PohodaRelinkRunner::class);
exit($runner->run($supplierId, $source, $commit, $year, $userId, static function (string $line): void {
    echo $line . PHP_EOL;
}, $allowFiledVat));
