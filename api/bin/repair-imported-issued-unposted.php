<?php

declare(strict_types=1);

use MyInvoice\Bootstrap;
use MyInvoice\Service\Import\ImportedIssuedInvoiceRepair;

if (PHP_SAPI !== 'cli') {
    exit("Jen z příkazové řádky.\n");
}

$usage = 'Použití: php api/bin/repair-imported-issued-unposted.php --supplier=ID [--batch=ID] [--commit] [--reset-paid]';
$supplierId = null;
$batch = null;
$commit = false;
$resetPaid = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help') {
        echo $usage . PHP_EOL;
        exit(0);
    }
    if ($supplierId === null && str_starts_with($arg, '--supplier=')) {
        $supplierId = filter_var(substr($arg, 11), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    } elseif ($batch === null && preg_match('/^--batch=([a-zA-Z0-9_-]{1,32})$/D', $arg, $match) === 1) {
        $batch = $match[1];
    } elseif ($arg === '--commit') {
        $commit = true;
    } elseif ($arg === '--reset-paid') {
        $resetPaid = true;
    } else {
        fwrite(STDERR, 'Neplatný parametr: ' . $arg . PHP_EOL . $usage . PHP_EOL);
        exit(2);
    }
}
if (!is_int($supplierId)) {
    fwrite(STDERR, $usage . PHP_EOL);
    exit(2);
}

require __DIR__ . '/../vendor/autoload.php';

$outcomes = ['ready' => 'K zaúčtování', 'posted' => 'Zaúčtováno', 'locked' => 'Zamčeno', 'skipped' => 'Přeskočeno', 'failed' => 'Chyba'];
$counts = array_fill_keys(array_keys($outcomes), 0);
$totals = [];
$paidWithoutPayment = 0;
$paidReset = 0;
$locked = fopen('php://temp', 'w+');
$amount = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ');
echo ($commit ? '' : '[NANEČISTO] ') . 'Nezaúčtované vydané doklady z importu, firma #' . $supplierId . PHP_EOL;

try {
    $repair = Bootstrap::buildContainer()->get(ImportedIssuedInvoiceRepair::class);
    foreach ($repair->run($supplierId, $batch, $commit, $resetPaid) as $row) {
        $base = (int) round((float) $row['base'] * 100);
        $vat = (int) round((float) $row['vat'] * 100);
        $currency = $row['currency'];
        $outcome = $row['outcome'];
        $counts[$outcome]++;
        $totals[$currency]['base'] = ($totals[$currency]['base'] ?? 0) + $base;
        $totals[$currency]['vat'] = ($totals[$currency]['vat'] ?? 0) + $vat;
        $paidWithoutPayment += (int) $row['paid_without_payment'];
        $paidReset += (int) $row['paid_reset'];

        $line = sprintf("%s | %s | základ %s %s | DPH %s %s | %s%s | %s - %s\n",
            $row['varsymbol'], $row['entry_date'], $amount($base), $currency, $amount($vat), $currency,
            $row['status'], $row['paid_without_payment'] ? ' (zaplaceno bez úhrady)' : '',
            $outcomes[$outcome], $row['reason']);
        fwrite($outcome === 'locked' ? $locked : STDOUT, $line);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

if ($counts['locked'] > 0) {
    echo PHP_EOL . 'Doklady v zamčených nebo podaných obdobích' . PHP_EOL;
    rewind($locked);
    fpassthru($locked);
}
fclose($locked);

echo PHP_EOL . 'Celkem dokladů: ' . array_sum($counts) . PHP_EOL;
foreach ($outcomes as $outcome => $label) {
    echo $label . ': ' . $counts[$outcome] . PHP_EOL;
}
foreach ($totals as $currency => $total) {
    echo sprintf("Součet %s: základ %s, DPH %s\n", $currency, $amount($total['base']), $amount($total['vat']));
}
echo 'Zaplaceno bez úhrady: ' . $paidWithoutPayment . PHP_EOL;
if ($commit) {
    echo 'Opravený stav úhrady: ' . $paidReset . PHP_EOL;
}
exit($counts['failed'] > 0 ? 1 : 0);
