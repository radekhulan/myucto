<?php

declare(strict_types=1);

[$script, $root, $supplier, $line, $dimensions, $snapshot, $resultFile] = $argv;
require $root . '/api/vendor/autoload.php';
$container = \MyInvoice\Bootstrap::buildContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();
if (!str_ends_with((string) $pdo->query('SELECT DATABASE()')->fetchColumn(), '_test')) throw new \RuntimeException('Test database required.');
$pdo->exec('SET SESSION innodb_lock_wait_timeout=15');
$pdo->beginTransaction();
if ($snapshot === '1') $pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
file_put_contents($resultFile, json_encode(['connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn()], JSON_THROW_ON_ERROR));
try {
    $container->get(\MyInvoice\Repository\DimensionAssignmentRepository::class)->insertLineDimensions((int) $supplier, (int) $line, json_decode($dimensions, true, flags: JSON_THROW_ON_ERROR));
    $result = 'accepted';
} catch (\MyInvoice\Service\Accounting\PostingException $e) {
    $result = $e->errorCode;
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
file_put_contents($resultFile, json_encode(['result' => $result], JSON_THROW_ON_ERROR));
