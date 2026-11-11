<?php

declare(strict_types=1);

/**
 * Cron — ukončení dohod, kterým uplynul sjednaný konec.
 *
 * Použití:
 *   php api/bin/cron-payroll-end-agreements.php
 *   php api/bin/cron-payroll-end-agreements.php --supplier=12   (lze opakovat)
 *   php api/bin/cron-payroll-end-agreements.php --today=2026-11-11
 *
 * DPP a DPČ se sjednaným koncem v minulosti zůstávaly aktivní, dokud je někdo
 * neukončil ručně, a kontroly s nimi počítaly jako s běžícími. Skript je jen
 * obal nad {@see \MyInvoice\Service\Payroll\PayrollAgreementAutoEndRunner}, který
 * volá tutéž službu jako ruční akce „Ukončit skončené dohody": přechod stavu
 * ke dni sjednaného konce, kontrolní seznam odchodu a auditní stopa s aktérem
 * „system". Dohodu, která má po konci mzdový vstup, docházku nebo zúčtovaný
 * běh, nechá být; tu musí posoudit člověk. Opakované spuštění nic nezmění.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronPreflight;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Payroll\PayrollAgreementAutoEndRunner;

const CRON_SCRIPT = 'cron-payroll-end-agreements';

$onlySuppliers = [];
$today = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--supplier=')) {
        $id = (int) substr($arg, 11);
        if ($id <= 0) {
            fwrite(STDERR, "Neplatné --supplier: {$arg}\n");
            exit(1);
        }
        $onlySuppliers[] = $id;
        continue;
    }
    if (str_starts_with($arg, '--today=')) {
        $today = substr($arg, 8);
        if (!IsoDate::isValid($today)) {
            fwrite(STDERR, "Neplatné --today (YYYY-MM-DD): {$arg}\n");
            exit(1);
        }
        continue;
    }
    fwrite(STDERR, "Unknown arg: {$arg}\n");
    exit(1);
}

$lightPdo = (new Connection(Config::load(Bootstrap::rootDir())))->pdo();
if (!CronPreflight::hasPayrollSuppliers($lightPdo)) {
    $result = ['skipped' => 'payroll_disabled'];
    CronRun::start($lightPdo, CRON_SCRIPT)->finish('ok', $result, null, null, false);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$container = Bootstrap::buildContainer();

/** @var Connection $connection */
$connection = $container->get(Connection::class);
$run = CronRun::start($connection->pdo(), CRON_SCRIPT);

/** @var PayrollAgreementAutoEndRunner $runner */
$runner = $container->get(PayrollAgreementAutoEndRunner::class);

$startedAt = microtime(true);
try {
    $report = $runner->run($onlySuppliers === [] ? null : $onlySuppliers, $today);
} catch (\Throwable $e) {
    $run->finish('error', ['error' => $e->getMessage()]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$ms = (int) ((microtime(true) - $startedAt) * 1000);
printf(
    "[%s] %s — k %s: firem=%d, ukončeno=%d, ponecháno k posouzení=%d, selhalo=%d, chyb=%d (%d ms)\n",
    date('Y-m-d H:i:s'),
    CRON_SCRIPT,
    $report['as_of'],
    $report['suppliers'],
    $report['ended'],
    $report['blocked'],
    $report['failed'],
    $report['errors'],
    $ms,
);
foreach ($report['failures'] as $failure) {
    fprintf(STDERR, "  ✗ firma #%d — %s\n", $failure['supplier_id'], $failure['message']);
}

// Prací je až ukončený vztah nebo chyba; tick bez kandidátů historii běhů nezanáší.
$run->finish(
    $report['errors'] > 0 ? 'error' : 'ok',
    $report,
    null,
    null,
    $report['ended'] > 0 || $report['failed'] > 0 || $report['errors'] > 0,
);

exit($report['errors'] > 0 ? 2 : 0);
