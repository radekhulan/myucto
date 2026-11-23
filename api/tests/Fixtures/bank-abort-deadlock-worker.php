<?php

declare(strict_types=1);

require $argv[1] . '/api/vendor/autoload.php';

$config = \MyInvoice\Infrastructure\Config\Config::load($argv[1]);
$table = $argv[2];
if (!str_ends_with((string) $config->get('db.name'), '_test')
    || !\MyInvoice\Tests\Support\TestDatabaseHost::isAllowed((string) ($config->get('db.host')))
    || !preg_match('/^test_bank_abort_[a-f0-9]{12}$/D', $table)) {
    exit(2);
}
try {
    $pdo = (new \MyInvoice\Infrastructure\Database\Connection($config))->pdo();
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');
    $pdo->beginTransaction();
    file_put_contents($argv[3], 'locking');
    $pdo->exec("UPDATE {$table} SET value = value + 1 WHERE id = 1");
    file_put_contents($argv[3], 'weighting');
    $weight = $pdo->prepare("UPDATE {$table} SET value = value + 1 WHERE id = ?");
    for ($id = 3; $id <= 5002; $id++) $weight->execute([$id]);
    file_put_contents($argv[3], 'ready');
    $pdo->exec("UPDATE {$table} SET value = value + 1 WHERE id = 2");
    $pdo->commit();
    echo "DEADLOCK_PEER_COMMITTED\n";
} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'DEADLOCK_PEER_FAILED:' . ($e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0));
    exit(1);
}
