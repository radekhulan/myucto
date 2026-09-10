<?php

declare(strict_types=1);

use MyInvoice\Bootstrap;
use MyInvoice\Service\Stock\SalesOrderException;
use MyInvoice\Service\Stock\SalesOrderService;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if ($argc !== 6) {
    fwrite(STDERR, "Expected supplier, order, idempotency key, ready file and result file.\n");
    exit(2);
}

$supplierId = filter_var($argv[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$orderId = filter_var($argv[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$idempotencyKey = trim($argv[3]);
$readyFile = $argv[4];
$resultFile = $argv[5];
if ($supplierId === false || $orderId === false || $idempotencyKey === '' || $readyFile === '' || $resultFile === '') {
    fwrite(STDERR, "Invalid worker arguments.\n");
    exit(2);
}

try {
    $container = Bootstrap::buildApp()->getContainer();
    $connection = $container->get(\MyInvoice\Infrastructure\Database\Connection::class);
    $connection->pdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connectionId = (int) $connection->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
    file_put_contents($readyFile, (string) $connectionId);
    $orders = $container->get(SalesOrderService::class);
    $order = $orders->confirm($supplierId, $orderId, $idempotencyKey);
    $result = ['status' => 'ok', 'fulfillment_status' => $order['fulfillment_status']];
} catch (SalesOrderException $e) {
    $result = ['status' => 'error', 'error_code' => $e->errorCode];
} catch (Throwable $e) {
    $result = ['status' => 'unexpected_error', 'exception' => $e::class, 'message' => $e->getMessage()];
}

if (file_put_contents($resultFile, json_encode($result, JSON_THROW_ON_ERROR)) === false) {
    fwrite(STDERR, "Cannot write worker result.\n");
    exit(3);
}
