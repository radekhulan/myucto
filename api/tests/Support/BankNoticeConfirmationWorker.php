<?php

declare(strict_types=1);

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankNoticeConfirmationService;
use MyInvoice\Service\Bank\BankNoticeConfirmationException;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$input = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (preg_match('/^[A-Za-z0-9_]+_test$/D', $input['database'] ?? '') !== 1) {
    throw new RuntimeException('Worker vyžaduje izolovanou testovací DB.');
}
putenv('MYINVOICE_DB_NAME=' . $input['database']);
$db = Bootstrap::buildContainer()->get(Connection::class);
$pdo = $db->pdo();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $input['database']) {
    throw new RuntimeException('Worker je připojen k jiné DB.');
}
$pdo->beginTransaction();
try {
    // Výslovně založí starší RR snapshot před čekáním na řádkový zámek avíza.
    $pdo->query('SELECT COUNT(*) FROM cash_journal_notes')->fetchColumn();
    file_put_contents($argv[1] . '.ready', json_encode(['ready'=>true,'connection_id'=>(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn()], JSON_THROW_ON_ERROR));
    $service = new BankNoticeConfirmationService($db);
    try {
        $result = $service->reconcileNotice((int) $input['supplier'], (int) $input['notice']);
        $pdo->commit();
    } catch (BankNoticeConfirmationException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->errorCode !== 'bank_busy') throw $e;
        // MariaDB může starý RR snapshot odmítnout 1020 namísto aktuálního čtení.
        // Přípustný je vysvětlený konflikt a nové vyhodnocení bez částečného převodu.
        $result = $service->reconcileNotice((int) $input['supplier'], (int) $input['notice']);
        $result['retried_after_conflict'] = true;
    }
    file_put_contents($argv[1] . '.result', json_encode($result, JSON_THROW_ON_ERROR));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
