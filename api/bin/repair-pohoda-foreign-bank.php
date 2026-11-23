<?php

declare(strict_types=1);

/**
 * Oprava bankovních účtů v cizí měně převedených z POHODY jako korunové.
 *
 * Převod z POHODY zakládal všechny bankovní účty, výpisy a pohyby v Kč: účet v EUR tak měl
 * korunové částky, deník jeho analytiky 221 neměl měnu a přecenění k rozvahovému dni ho
 * nenabízelo. Skript najde takové účty podle dat (pohyby přepočtené kurzem ČNB, zkratka
 * a název účtu, měnový účet firmy) a převede je na měnu účtu. Pravidla výběru, převodu
 * a pojistky jsou v {@see \MyInvoice\Service\Migration\Pohoda\PohodaForeignBankRepair}.
 *
 * Kurzy ČNB bere z `exchange_rates`, chybějící dny stáhne z ČNB (`--no-fetch-rates` ne).
 * Před opravou je vhodné doplnit historii kurzů: `php api/bin/backfill-cnb-rates.php --from=2025 --to=2026 --apply`.
 *
 * Stejnou opravu spouští Kontrola dat po převodu (stránka v aplikaci a
 * `api/bin/migration-data-check.php --check=foreign_bank_in_czk`); tento skript zůstává tenkou obálkou
 * nad společnou službou opravy pro dávkové běhy přes všechny firmy.
 *
 * Použití:
 *   php api/bin/repair-pohoda-foreign-bank.php --all-pohoda                 # nanečisto, všechny firmy z POHODY
 *   php api/bin/repair-pohoda-foreign-bank.php --supplier=12 --supplier=34  # nanečisto, vybrané firmy
 *   php api/bin/repair-pohoda-foreign-bank.php --supplier=12 --commit       # provést
 *   php api/bin/repair-pohoda-foreign-bank.php --all-pohoda --verbose       # i korunové účty
 *   php api/bin/repair-pohoda-foreign-bank.php --supplier=12 --include-uploaded --commit
 *
 * Výpisy nahrané po převodu (GPC, PDF, banka) k číslu účtu v cizí měně vypíše vždy: v jaké
 * jsou měně, kam patří, zda navazují na předchozí výpis a co s nimi udělat. Volba
 * `--include-uploaded` s `--commit` přepne na měnu účtu ty, které k převedenému účtu v měně
 * jednoznačně patří podle souboru a nemají spárování, úhradu ani zápis. U účtů, které se
 * nepřevedly, vypíše doporučený ruční postup.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Migration\Pohoda\PohodaForeignBankRepair;

$commit = false;
$all = false;
$fetch = true;
$verbose = false;
$includeUploaded = false;
$suppliers = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--commit') {
        $commit = true;
    } elseif ($arg === '--all-pohoda') {
        $all = true;
    } elseif ($arg === '--no-fetch-rates') {
        $fetch = false;
    } elseif ($arg === '--verbose') {
        $verbose = true;
    } elseif ($arg === '--include-uploaded') {
        $includeUploaded = true;
    } elseif (str_starts_with($arg, '--supplier=') && ctype_digit(substr($arg, 11)) && (int) substr($arg, 11) > 0) {
        $suppliers[] = (int) substr($arg, 11);
    } else {
        fwrite(STDERR, "Neznámý nebo neplatný argument: {$arg}\n");
        exit(2);
    }
}
if ($all === ($suppliers !== [])) {
    fwrite(STDERR, "Zadejte --supplier=ID (i opakovaně), nebo --all-pohoda.\n");
    exit(2);
}

$repair = Bootstrap::buildContainer()->get(PohodaForeignBankRepair::class);
$rows = $repair->run($all ? $repair->pohodaSuppliers() : array_values(array_unique($suppliers)), $commit, $fetch, null, $includeUploaded);

$money = static fn (?int $cents): string => $cents === null ? '-' : number_format($cents / 100, 2, ',', ' ');
echo ($commit ? '' : '[NANEČISTO] ') . 'Bankovní účty převzaté z POHODY' . PHP_EOL;
$summary = [];
$uploadSummary = [];
foreach ($rows as $r) {
    $summary[$r['status']] = ($summary[$r['status']] ?? 0) + 1;
    if (!$verbose && $r['status'] === 'czk' && $r['reason'] === null && $r['uploaded'] === []) {
        continue;
    }
    printf(
        "firma %d  %-6s %s/%s  %s  %s  pohybů %d%s%s%s\n",
        $r['supplier_id'], $r['code'], $r['account_number'], $r['bank_code'], $r['status'], $r['currency'], $r['transactions'],
        isset($r['match_pct']) && $r['status'] !== 'already_foreign' ? sprintf(', shoda s kurzem ČNB %s %% (přesně %d, do haléřů položek %d z %d)', number_format((float) $r['match_pct'], 1, ',', ''), $r['exact'], $r['near'], $r['tested']) : '',
        ($r['hint'] ?? null) !== null ? ', náznak: ' . $r['hint'] : '',
        $r['reason'] !== null ? PHP_EOL . '    důvod: ' . $r['reason'] : '',
    );
    foreach ($r['chains'] as $c) {
        if ($r['status'] === 'rechain' || $r['status'] === 'rechained') {
            printf("    rok %d: %d výpisů, počáteční stav %s Kč podle deníku (posun %s Kč zpět)\n",
                $c['year'], count($c['statement_ids']), $money($c['opening_czk']), $money($c['shift_czk']));
            continue;
        }
        if ($c['opening_czk'] === null && $c['opening_error'] === null) {
            printf("    rok %d: %d výpisů, výpisy bez stavů (počáteční ani konečný stav se nepřepočítává)\n", $c['year'], count($c['statement_ids']));
            continue;
        }
        printf("    rok %d: %d výpisů, počáteční stav %s Kč -> %s %s (kurz %s%s), konečný stav %s %s%s\n",
            $c['year'], count($c['statement_ids']), $money($c['opening_czk']), $money($c['opening_foreign']), $r['currency'],
            $c['opening_rate'] !== null ? number_format((float) $c['opening_rate'], 3, ',', '') : '-',
            $c['opening_exact'] === false ? ', přepočet Kč nesedí na haléř' : '',
            $money($c['closing_foreign']), $r['currency'],
            ($c['shift_czk'] ?? 0) !== 0 && $c['shift_czk'] !== null ? sprintf(', ruší posun stavů %s Kč', $money($c['shift_czk'])) : '');
    }
    if (in_array($r['status'], ['convert', 'converted'], true)) {
        printf("    deník %s: %s řádků (zápisy jiných pohybů v Kč %d), párování %d, kurzové rozdíly %d, návrhy zaúčtování ke zrušení %d\n",
            $r['account_code'] ?? 'bez analytiky', $r['journal_lines']['lines'] ?? '-', $r['other_entries'] ?? 0, $r['matches'], $r['fx_differences'], $r['suggestions']);
        foreach ($r['registration'] as $change) {
            echo "    evidence: {$change}" . PHP_EOL;
        }
    }
    if (($r['advice'] ?? null) !== null) {
        echo '    postup: ' . $r['advice'] . PHP_EOL;
    }
    if ($r['reason_code'] === 'low_match') {
        foreach ($r['mismatches'] as $m) {
            printf("    mimo kurz: %s  %s Kč (kurz %s %s)\n", $m['date'], number_format((float) $m['czk'], 2, ',', ' '),
                number_format((float) $m['rate'], 3, ',', ''), $r['currency']);
        }
    }
    foreach ($r['uploaded'] as $u) {
        printf("    nahraný výpis #%d %s (%s, č. %s, %s): v %s, patří do %s%s  %s%s\n",
            $u['id'], $u['file_name'] !== '' ? $u['file_name'] : '-', $u['source'], $u['statement_number'] ?? '-', $u['statement_date'],
            $u['currency'], $u['expected'] ?? '?', $u['evidence'] !== '' ? ' (' . $u['evidence'] . ')' : '',
            $u['status'], $u['diff'] !== null && $u['diff'] !== 0 ? ', rozdíl stavu ' . $money($u['diff']) . ' ' . ($u['expected'] ?? '') : '');
        if ($u['advice'] !== null) {
            echo '        postup: ' . $u['advice'] . PHP_EOL;
        }
        $uploadSummary[$u['status']] = ($uploadSummary[$u['status']] ?? 0) + 1;
    }
    if (isset($r['result'])) {
        printf("    provedeno: pohybů %d, párování %d, řádků deníku %d, otevíracích řádků %d\n",
            $r['result']['transactions'], $r['result']['matches'], $r['result']['journal_lines'], $r['result']['opening_lines']);
    }
}
ksort($summary);
echo 'Souhrn: ' . implode(', ', array_map(static fn (string $k, int $v): string => "{$k} {$v}", array_keys($summary), $summary)) . PHP_EOL;
if ($uploadSummary !== []) {
    ksort($uploadSummary);
    echo 'Nahrané výpisy: ' . implode(', ', array_map(static fn (string $k, int $v): string => "{$k} {$v}", array_keys($uploadSummary), $uploadSummary)) . PHP_EOL;
}
if (!$commit && (($summary['convert'] ?? 0) + ($summary['rechain'] ?? 0) + ($uploadSummary['switch'] ?? 0)) > 0) {
    echo PHP_EOL . 'Nic nezměněno. Opravu provede spuštění s --commit' . (($uploadSummary['switch'] ?? 0) > 0 ? ', nahrané výpisy přepne --include-uploaded' : '') . '.' . PHP_EOL;
}
exit(($summary['failed'] ?? 0) + ($uploadSummary['failed'] ?? 0) > 0 ? 1 : 0);
