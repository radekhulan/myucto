<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\PurchaseBankMatching;
use MyInvoice\Service\Bank\StatementMatcher;

$c = Bootstrap::buildContainer();
if (!str_ends_with((string)$c->get(Connection::class)->pdo()->query('SELECT DATABASE()')->fetchColumn(), '_test')) {
    throw new RuntimeException('Worker smí běžet pouze nad testovací databází.');
}
// Import může ještě držet nepotvrzený pohyb, když se přijímá doklad.
if (($argv[1] ?? '') === 'late_bank') {
    $db = $c->get(Connection::class);
    $pdo = $db->pdo();
    $pdo->beginTransaction();
    (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($db))->acquire((int) $argv[2]);
    $pdo->prepare("INSERT INTO bank_transactions (id,statement_id,posted_at,amount,currency,variable_symbol)
        VALUES (?,?,'2099-06-15',-812.34,'CZK','20990001')")->execute([(int) $argv[5],(int) $argv[6]]);
    echo "READY\n";
    flush();
    usleep(1500000);
    $pdo->commit();
    exit(0);
}
$result = ($argv[1] ?? '') === 'bank'
    ? $c->get(StatementMatcher::class)->match((int)$argv[5])
    : $c->get(PurchaseBankMatching::class)->retry((int)$argv[2], (int)$argv[3], (int)$argv[4]);
echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
