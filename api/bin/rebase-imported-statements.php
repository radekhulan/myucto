<?php

declare(strict_types=1);

/**
 * Potvrzení posunu stavů převzatých výpisů podle prvního výpisu banky.
 *
 * Nahrání výpisu banky posune stavy výpisů převzatých z jiného programu sám jen do
 * {@see \MyInvoice\Service\Bank\ImportedStatementRebase::HOLD_LIMIT}; větší posun ohlásí
 * (`imported_balances_rebase_held`) a čeká na potvrzení. Skript posun spočte pro zadaný
 * výpis banky znovu, vypíše ho a s `--apply` provede. Převzaté výpisy různých účtů zdroje
 * se stejným číslem účtu neposune ani tak — nejdřív je potřeba opravit měnu účtu
 * (`repair-pohoda-foreign-bank.php`).
 *
 * Použití:
 *   php api/bin/rebase-imported-statements.php --supplier=12 --statement=345           # nanečisto
 *   php api/bin/rebase-imported-statements.php --supplier=12 --statement=345 --apply
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\ImportedStatementRebase;

$apply = false;
$supplierId = 0;
$statementId = 0;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (preg_match('/^--supplier=(\d+)$/D', $arg, $m) === 1) {
        $supplierId = (int) $m[1];
    } elseif (preg_match('/^--statement=(\d+)$/D', $arg, $m) === 1) {
        $statementId = (int) $m[1];
    } else {
        fwrite(STDERR, "Neznámý argument: {$arg}\n");
        exit(2);
    }
}
if ($supplierId < 1 || $statementId < 1) {
    fwrite(STDERR, "Zadejte --supplier=ID a --statement=ID výpisu banky.\n");
    exit(2);
}

$container = Bootstrap::buildContainer();
$db = $container->get(Connection::class);
$pdo = $db->pdo();
$pdo->beginTransaction();
try {
    $result = (new ImportedStatementRebase($db))->rebase($supplierId, $statementId, true);
    if ($result === null) {
        $pdo->rollBack();
        echo "Výpis #{$statementId} firmy {$supplierId} nemá co dorovnávat (není první výpis banky účtu za převzatými výpisy, nebo stavy sedí).\n";
        exit(0);
    }
    if ($result['status'] === 'mixed_sources') {
        $pdo->rollBack();
        echo sprintf("Převzaté výpisy účtu pocházejí z různých účtů zdroje (%s), posun %s se neprovede.\n",
            implode(', ', $result['sources'] ?? []), number_format($result['shift'], 2, ',', ' '));
        exit(1);
    }
    echo sprintf("%sPosun %d převzatých výpisů o %s, počáteční stav %s.\n", $apply ? '' : '[NANEČISTO] ', $result['statements'],
        number_format($result['shift'], 2, ',', ' '), number_format($result['opening'], 2, ',', ' '));
    if (!$apply) {
        $pdo->rollBack();
        echo "Nic nezměněno. Posun provede spuštění s --apply.\n";
        exit(0);
    }
    $container->get(ActivityLogger::class)->log('bank.imported_balances_rebased', null, 'bank_statement', $statementId,
        ['source' => 'cli', 'confirmed' => true] + $result, null, null, $supplierId);
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Chyba: ' . $e->getMessage() . "\n");
    exit(1);
}
