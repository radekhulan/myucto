<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\Connector\BankConnectionService;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\BankConnectorOperationException;
use MyInvoice\Service\Bank\Connector\BankConnectorRegistry;
use MyInvoice\Service\Bank\Connector\CsasSharedConsentSync;
use MyInvoice\Service\Bank\Connector\BankPaymentSignatureService;
use MyInvoice\Service\Cron\CronPreflight;
use MyInvoice\Service\Cron\CronRun;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::load(Bootstrap::rootDir());
$connection = new Connection($config);
$run = CronRun::start($connection->pdo(), 'cron-bank-connections');

if (!CronPreflight::hasBankConnections($connection->pdo())) {
    fwrite(STDOUT, "[bank-connections] žádné aktivní bankovní spojení - přeskočeno.\n");
    $run->finish('ok', ['skipped' => 'no enabled bank connections']);
    exit(0);
}

try {
    $container = Bootstrap::buildContainer();
    $service = $container->get(BankConnectionService::class);
    $summary = $service->syncAll();
    // Stav podpisu a provedení dávek, které se podepisují v bance (Česká spořitelna).
    // Dotaz nic nezakládá; chyba jedné dávky jen odloží její další dotaz.
    try {
        $signatures = $container->get(BankPaymentSignatureService::class)
            ->pollDue($container->get(BankConnectorRegistry::class)->signableProviders());
    } catch (Throwable) {
        $signatures = ['polled' => 0, 'errors' => 1];
    }
    $summary['payment_signatures'] = $signatures;
    // Souhlasy ČS sdílené mezi firmami (CsasSharedConsent): napojí čekající účty firem instalace.
    // Při vyčerpané kvótě banky čeká na další běh; chyba nemění výsledek synchronizace.
    if (!in_array(BankConnectorException::RATE_LIMITED, array_column($summary['details'], 'skipped'), true)) {
        try {
            $shared = $container->get(CsasSharedConsentSync::class)->linkPending();
            if ($shared !== null && $shared !== []) {
                $summary['csas_shared_consent'] = array_count_values(array_column($shared, 'status'));
            }
        } catch (Throwable $e) {
            $summary['csas_shared_consent'] = ['error' => $e instanceof BankConnectorOperationException ? $e->errorCode : 'failed'];
        }
    }
    echo '[' . date('Y-m-d H:i:s') . '] bank-connections: '
        . json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $run->finish(
        $summary['errors'] > 0 ? 'error' : 'ok',
        $summary,
        $summary['errors'] > 0 ? 'Některá bankovní spojení se nepodařilo synchronizovat.' : null,
    );
    exit($summary['errors'] > 0 ? 1 : 0);
} catch (Throwable) {
    $run->finish('error', [], 'Synchronizace bankovních spojení selhala.');
    fwrite(STDERR, "[bank-connections] synchronizace selhala.\n");
    exit(1);
}
