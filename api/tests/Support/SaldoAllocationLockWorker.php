<?php

declare(strict_types=1);

/**
 * Souběžný rozpad zápisu pro JournalSettlementConcurrencyTest: v samostatném spojení zamkne
 * doklad, zapíše rozpad jiného řádku, ohlásí připravenost souborem, chvíli drží zámek a potvrdí. Test
 * mezitím spustí vlastní rozpad téhož dokladu, který na zámek čeká.
 *
 * Spojení i data dostává přes proměnné prostředí, nic nečte z konfigurace aplikace.
 */
$pdo = new PDO(
    'mysql:host=' . getenv('SALDO_WORKER_HOST') . ';port=' . getenv('SALDO_WORKER_PORT') . ';dbname=' . getenv('SALDO_WORKER_DB') . ';charset=utf8mb4',
    (string) getenv('SALDO_WORKER_USER'),
    (string) getenv('SALDO_WORKER_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$supplier = (int) getenv('SALDO_WORKER_SUPPLIER');
$doc = (int) getenv('SALDO_WORKER_DOC');
$entry = (int) getenv('SALDO_WORKER_ENTRY');
$lineNo = (int) getenv('SALDO_WORKER_LINE_NO');
$account = (int) getenv('SALDO_WORKER_ACCOUNT');
$amount = (string) getenv('SALDO_WORKER_AMOUNT');

$pdo->beginTransaction();
$pdo->prepare('SELECT id FROM purchase_invoices WHERE supplier_id = ? AND id = ? FOR UPDATE')->execute([$supplier, $doc]);
$pdo->prepare(
    "INSERT INTO journal_settlement_allocations (supplier_id, entry_id, line_no, account_id, side, kind, doc_type, doc_id, amount)
     VALUES (?, ?, ?, ?, 'debit', 'settlement', 'purchase_invoice', ?, ?)"
)->execute([$supplier, $entry, $lineNo, $account, $doc, $amount]);
// Připravenost se hlásí souborem: čtení roury z proc_open na Windows neumí neblokovat.
touch((string) getenv('SALDO_WORKER_READY'));
usleep(3000000);
$pdo->commit();
