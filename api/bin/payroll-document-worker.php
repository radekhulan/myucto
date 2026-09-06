<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronPreflight;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Payroll\Document\Delivery\PayrollSecureDeliveryService;
use MyInvoice\Service\Payroll\Document\PayrollAnnualDocumentBatchQueueService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentBatchQueueService;

// Spuštěno cronem, nebo ručně/z aplikace? Heartbeat i preflight brána patří
// jen cronovému běhu: ruční spuštění nemá co posouvat provozní přehled a
// cílený běh se nesmí dát umlčet bránou.
$cronScript = defined('MYINVOICE_CRON_SCRIPT') ? (string) MYINVOICE_CRON_SCRIPT : null;

$limit = 25;
$arguments = $_SERVER['argv'] ?? [];
foreach ($arguments as $argument) {
    if (!is_string($argument)) {
        continue;
    }
    if (str_starts_with($argument, '--limit=')) {
        $limit = max(1, min(500, (int) substr($argument, 8)));
    }
}

$lockDir = RuntimePaths::storage('locks');
if (!is_dir($lockDir) && !mkdir($lockDir, 0750, true) && !is_dir($lockDir)) {
    fwrite(STDERR, "Nelze vytvořit adresář zámku.\n");
    exit(3);
}
$lock = fopen($lockDir . '/payroll-document-worker.lock', 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Worker mzdových dokumentů už běží.\n");
    exit(0);
}

try {
    // Preflight PŘED stavbou kontejneru: u instalace, kde zrovna nikdo negeneruje
    // pásky (drtivá většina minut), by jinak každý tick postavil celý DI kontejner,
    // jen aby zjistil, že fronta je prázdná. Heartbeat se zapíše i tak — tichý
    // tick musí být v přehledu rozeznatelný od nespuštěné úlohy.
    if ($cronScript !== null) {
        $lightPdo = (new Connection(Config::load(Bootstrap::rootDir())))->pdo();
        if (!CronPreflight::hasPayrollDocumentWork($lightPdo)) {
            $idle = ['processed' => 0, 'gate' => 'empty_queue'];
            CronRun::start($lightPdo, $cronScript)->finish('ok', $idle);
            fwrite(STDOUT, json_encode(
                $idle,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) . PHP_EOL);
            return;
        }
    }

    $container = Bootstrap::buildContainer();
    $run = $cronScript === null
        ? null
        : CronRun::start($container->get(Connection::class)->pdo(), $cronScript);
    try {
        $worker = $container->get(PayrollDocumentBatchQueueService::class);
        if (!$worker instanceof PayrollDocumentBatchQueueService) {
            throw new RuntimeException('Worker mzdových dokumentů není dostupný.');
        }
        // Měsíční pásky mají přednost: jsou vázané na výplatní termín, roční
        // dokumenty na lhůtu v měsících. Roční fronta dostane, co ze stropu zbude,
        // ale vždy aspoň jeden pokus — jinak by ji rušný měsíc vyhladověl.
        $monthly = $worker->processAvailable($limit);
        $annualWorker = $container->get(PayrollAnnualDocumentBatchQueueService::class);
        if (!$annualWorker instanceof PayrollAnnualDocumentBatchQueueService) {
            throw new RuntimeException('Worker ročních mzdových dokumentů není dostupný.');
        }
        $annual = $annualWorker->processAvailable(
            max(1, $limit - (int) $monthly['processed']),
        );
        // Rozesílka zabezpečených odkazů běží až POSLEDNÍ a vždycky: nemá smysl
        // posílat odkaz na pásku, která se ještě nevyrenderovala. Sama se drží
        // zavřená, dokud není zapnutý `payroll.secure_delivery.enabled` — na instanci
        // bez toho přepínače se nic neodešle, i kdyby ve frontě něco leželo.
        $deliveryWorker = $container->get(PayrollSecureDeliveryService::class);
        if (!$deliveryWorker instanceof PayrollSecureDeliveryService) {
            throw new RuntimeException('Worker doručení mzdových dokumentů není dostupný.');
        }
        $delivery = $deliveryWorker->dispatchAvailable($limit);

        $report = ['monthly' => $monthly, 'annual' => $annual, 'delivery' => $delivery];
        fwrite(
            STDOUT,
            json_encode(
                $report,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) . PHP_EOL,
        );
        // `didWork` se nechává na heuristice CronRun: samé nuly = noop, takže
        // v `cron_runs` zůstane jen tick, který opravdu něco vyrenderoval.
        $run?->finish('ok', $report);
    } catch (Throwable $e) {
        $run?->finish('error', ['error' => $e->getMessage()], $e->getMessage(), 1);
        throw $e;
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
