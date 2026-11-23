<?php

declare(strict_types=1);

/**
 * Hlídač vyhlášení legislativy a číselníků pro mzdy. Běží na serveru Velín z cronu, nikoli v CI.
 *
 * Sleduje (jen veřejné zdroje, jen čtení): balíky MPSV/ČSSZ pro JMHZ a REGZEC (proti připnutým
 * manifestům repa), stránky NV2027/NV2026, Q&A a Aktuality MPSV, stránky ČSSZ k JMHZ, číselníky
 * obcí (CISOB) a států (CZEM) ČSÚ pro 2027 a nové předpisy Sbírky zákonů (RSS) se slovy stravné,
 * minimální mzda, JMHZ, normativní nájemné, nezabavitelné a dalšími (seznam v
 * tools/payroll-watch-sources.php).
 *
 * Stav je v storage/monitoring/payroll-legislation-watch.json (mimo git). Prvním během vznikne
 * výchozí stav a nic se nehlásí (až na rozdíl balíku proti připnutému souboru). Chyba zdroje je
 * varování, uložený stav tohoto zdroje se nemění a skript končí kódem 0.
 *
 * Použití: php tools/watchPayrollSources.php [--notify] [--dry-run] [--only=id,id] [--state=SOUBOR] [--list]
 *   --notify    při změně zavolá velin-notify -t "MyÚčto legislativa" -p 4 "<zpráva>" (je-li v PATH)
 *   --dry-run   nic nezapisuje do stavu
 *   --list      vypíše identifikátory zdrojů
 *
 * Cron na Velínu (denně 6:47, po sloučení do master):
 *   47 6 * * * cd /srv/work/myucto.cz && php tools/watchPayrollSources.php --notify >> ~/logs/myucto-legis.log 2>&1
 */

use MyInvoice\Tooling\PayrollSourceWatcher;

require_once __DIR__ . '/PayrollSourceWatcher.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Tento skript lze spustit jen z příkazové řádky.\n");
    exit(1);
}

$notify = false;
$dryRun = false;
$list = false;
$only = null;
$statePath = dirname(__DIR__) . '/storage/monitoring/payroll-legislation-watch.json';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--notify') {
        $notify = true;
    } elseif ($argument === '--dry-run') {
        $dryRun = true;
    } elseif ($argument === '--list') {
        $list = true;
    } elseif (str_starts_with($argument, '--only=')) {
        $only = array_values(array_filter(explode(',', substr($argument, 7))));
    } elseif (str_starts_with($argument, '--state=') && strlen($argument) > 8) {
        $statePath = substr($argument, 8);
    } else {
        fwrite(STDERR, "Neznámý argument {$argument}.\n");
        exit(2);
    }
}

try {
    $sources = require __DIR__ . '/payroll-watch-sources.php';
    if ($list) {
        foreach ($sources as $id => $source) {
            fwrite(STDOUT, sprintf("%-28s %s\n", $id, $source['label']));
        }
        exit(0);
    }
    $report = (new PayrollSourceWatcher($sources))->watch($statePath, !$dryRun, $only);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . gmdate('c') . "] watchPayrollSources selhal: {$e->getMessage()}\n");
    exit(1);
}

echo '[' . $report['checked_at'] . '] zkontrolováno ' . $report['checked'] . ' zdrojů, změn ' . $report['change_count']
    . ', varování ' . count($report['warnings'])
    . ($report['baselined'] !== [] ? ', nový výchozí stav: ' . count($report['baselined']) : '') . PHP_EOL;
foreach ($report['changes'] as $change) {
    echo "ZMENA {$change['label']}: {$change['message']}\n  {$change['url']}\n";
    foreach ($change['details'] as $detail) {
        echo "  - {$detail}\n";
    }
}
foreach ($report['warnings'] as $warning) {
    fwrite(STDERR, "VAROVANI {$warning}\n");
}

if ($notify && $report['changed']) {
    $lines = [];
    foreach ($report['changes'] as $change) {
        $lines[] = $change['label'] . ': ' . $change['message'] . ($change['details'] !== [] ? ' - ' . $change['details'][0] : '')
            . ' ' . $change['url'];
    }
    $message = mb_substr(implode("\n", $lines), 0, 1500);
    $path = trim((string) shell_exec('command -v velin-notify 2>/dev/null'));
    if ($path === '') {
        fwrite(STDERR, "VAROVANI velin-notify není v PATH, notifikace se neposílá.\n");
    } else {
        $process = proc_open([$path, '-t', 'MyÚčto legislativa', '-p', '4', $message], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) {
            fwrite(STDERR, "VAROVANI velin-notify selhal.\n");
        }
    }
}
exit(0);
