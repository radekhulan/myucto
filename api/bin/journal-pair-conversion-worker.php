<?php

declare(strict_types=1);

/**
 * Worker převodu starších zápisů na dvojice (náhled nanečisto i provedení).
 *
 * Použití:
 *   php api/bin/journal-pair-conversion-worker.php --run-id=N
 *
 * Spouští ho JournalPairConversionService na pozadí hned po založení běhu. Zpracuje
 * jen běh ve stavu `queued` (převzetí je atomické, dva workery téhož běhu nevadí);
 * průběh a výsledek zapisuje do `journal_pair_conversion_runs`. Zrušený běh skončí
 * po rozpracované dávce. Opakovaný převod je bezpečný: rozdělené zápisy už jsou
 * dvojice a přeskočí se.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Accounting\Pair\JournalPairConversionJob;

$runId = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--run-id=')) {
        $runId = (int) substr($arg, 9);
        continue;
    }
    fwrite(STDERR, "Unknown arg: $arg\n");
    exit(1);
}
if ($runId === null || $runId <= 0) {
    fwrite(STDERR, "Usage: php journal-pair-conversion-worker.php --run-id=N\n");
    exit(1);
}

set_time_limit(0);
ignore_user_abort(true);

$container = Bootstrap::buildContainer();
try {
    $container->get(JournalPairConversionJob::class)->run($runId);
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] run #{$runId}: " . $e->getMessage() . "\n");
    exit(5);
}
exit(0);
