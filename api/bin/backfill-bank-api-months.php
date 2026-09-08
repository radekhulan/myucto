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
$query = "SELECT DISTINCT bs.supplier_id, bs.account_number, bs.bank_code, bs.currency
    FROM bank_statements bs WHERE bs.source IN ('bank_api', 'gpc') AND bs.supplier_id IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM bank_api_months m WHERE m.statement_id = bs.id)
    AND " . BankApiMonthlyStatements::visibleSql() . ' ORDER BY bs.supplier_id';
$accounts = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
$apply = in_array('--apply', $argv, true);
$done = [];
$processed = 0;
foreach ($accounts as $account) {
    $supplierId = (int) $account['supplier_id'];
    if (!(new BankApiMonthlyStatements($pdo))->hasApiAccount($supplierId, (string) $account['account_number'], (string) $account['bank_code'], (string) $account['currency'])) continue;
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
