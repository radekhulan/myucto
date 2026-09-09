<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;
use MyInvoice\Service\Bank\AuthoritativeTransactionReconciler;
use MyInvoice\Service\Bank\BankApiMonthlyStatements;

$db = new Connection(Config::load(Bootstrap::rootDir()));
$pdo = $db->pdo();
$accounts = (new BankApiMonthlyStatements($pdo))->pendingBackfillAccounts();
$apply = in_array('--apply', $argv, true);
$done = [];
$processed = 0;
foreach ($accounts as $account) {
    $supplierId = (int) $account['supplier_id'];
    $key = AuthoritativeTransactionReconciler::account((string) $account['account_number'], (string) $account['bank_code']);
    if ($key === null || empty($account['currency'])) throw new RuntimeException('API evidence has an unresolved account or currency.');
    $scope = $supplierId . ':' . $key . ':' . $account['currency'];
    if (isset($done[$scope])) continue;
    $done[$scope] = true;
    if (!$apply) continue;
    $name = NamedLockName::for($db, 'bank-import', (string) $supplierId);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 30)');
    $lock->execute([$name]);
    if ((int) $lock->fetchColumn() !== 1) throw new RuntimeException('Bank import is busy. Retry the backfill.');
    try {
        $pdo->beginTransaction();
        $currencyLock = $pdo->prepare('SELECT id FROM currencies WHERE supplier_id = ? ORDER BY id FOR UPDATE');
        $currencyLock->execute([$supplierId]);
        $currencyLock->fetchAll(PDO::FETCH_COLUMN);
        (new BankApiMonthlyStatements($pdo))->projectAccount($supplierId, (string) $account['account_number'], (string) $account['bank_code'], (string) $account['currency']);
        $pdo->commit();
        $processed++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
    }
}
echo json_encode(['apply' => $apply, 'accounts' => count($done), 'processed' => $processed], JSON_THROW_ON_ERROR) . PHP_EOL;
