<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Stock\StockDocumentService;
use MyInvoice\Service\Stock\StockException;

$input = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$database = $input['database'] ?? '';
if (preg_match('/^[A-Za-z0-9_]+_test$/D', $database) !== 1) {
    throw new RuntimeException('The stock worker requires an explicit isolated test database.');
}
foreach (['MYINVOICE_DB_NAME', 'MYSQL_DATABASE'] as $name) {
    putenv($name . '=' . $database);
    $_ENV[$name] = $_SERVER[$name] = $database;
}
$container = Bootstrap::buildApp()->getContainer();
$pdo = $container->get(Connection::class)->pdo();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
    throw new RuntimeException('The stock worker connected to a different database.');
}
file_put_contents($argv[1] . '.ready.tmp', json_encode([
    'connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
    'database' => $database,
], JSON_THROW_ON_ERROR));
rename($argv[1] . '.ready.tmp', $argv[1] . '.ready');
$write = static function () use ($container, $input): void {
    if (($input['operation'] ?? 'update') === 'post') {
        $container->get(StockDocumentService::class)->post($input['sid'], $input['id'], $input['user']);
    } else {
        $container->get(StockDocumentService::class)->updateDraft($input['sid'], $input['id'], $input['body'], $input['user']);
    }
};
try {
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
    try {
        $write();
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) !== 1205) {
            throw $e;
        }
        file_put_contents($argv[1] . '.blocked', '1205');
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');
        $write();
    }
    file_put_contents($argv[1] . '.result', 'updated');
} catch (StockException $e) {
    file_put_contents($argv[1] . '.result', $e->errorCode);
}
