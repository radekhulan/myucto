<?php

declare(strict_types=1);

/**
 * Souběžné čerpání zálohy pro AdvanceDeductionConcurrencyTest: v samostatném spojení zamkne
 * zálohu, zapíše její čerpání jinou konečnou fakturou, ohlásí připravenost souborem, chvíli
 * drží zámek a potvrdí. Test mezitím spustí vlastní čerpání téže zálohy, které na zámek čeká.
 *
 * Spojení i data dostává přes proměnné prostředí, nic nečte z konfigurace aplikace.
 */
$pdo = new PDO(
    'mysql:host=' . getenv('ADV_WORKER_HOST') . ';port=' . getenv('ADV_WORKER_PORT') . ';dbname=' . getenv('ADV_WORKER_DB') . ';charset=utf8mb4',
    (string) getenv('ADV_WORKER_USER'),
    (string) getenv('ADV_WORKER_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$supplier = (int) getenv('ADV_WORKER_SUPPLIER');
$advance = (int) getenv('ADV_WORKER_ADVANCE');
$final = (int) getenv('ADV_WORKER_FINAL');
$amount = (string) getenv('ADV_WORKER_AMOUNT');

$pdo->beginTransaction();
$pdo->prepare('SELECT id FROM purchase_invoices WHERE supplier_id = ? AND id = ? FOR UPDATE')->execute([$supplier, $advance]);
$pdo->prepare(
    "INSERT INTO purchase_advance_deductions (supplier_id, final_invoice_id, advance_invoice_id, amount, source)
     VALUES (?, ?, ?, ?, 'saldo')"
)->execute([$supplier, $final, $advance, $amount]);
// Připravenost se hlásí souborem: čtení roury z proc_open na Windows neumí neblokovat.
touch((string) getenv('ADV_WORKER_READY'));
usleep(3000000);
$pdo->commit();
