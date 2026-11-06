<?php

declare(strict_types=1);

/**
 * Úklid opakovaných vazeb stažení z napojení banky na už evidované pohyby.
 *
 * Automatická synchronizace stahuje okno s překryvem a každé stažení dřív navázalo
 * všechny pohyby z překryvu znovu (`bank_transaction_imports`). Stažení je skrytý
 * podklad měsíčního výpisu, ten pohyby obsahuje dál přes vlastní vazby. Maže se jen
 * vazba stažení (soubor `<poskytovatel>-<napojení>-<od>-<do>.<přípona>`), která
 * nedokládá nic nového: pohyb nese tentýž otisk, nebo ho převedla už dřívější vazba.
 * Vazby měsíčních výpisů, ručně nahraných výpisů a první převod otisku z jiného zdroje
 * zůstávají. Opakované spuštění nic dalšího nesmaže.
 *
 * Použití:
 *   php api/bin/cleanup-bank-import-links.php            (jen spočítá)
 *   php api/bin/cleanup-bank-import-links.php --apply    (smaže)
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;
use MyInvoice\Service\Bank\StatementTransactionScope;

$db = new Connection(Config::load(Bootstrap::rootDir()));
$pdo = $db->pdo();
$apply = in_array('--apply', $argv, true);

$statements = $pdo->query(
    "SELECT ev.id, ev.supplier_id FROM bank_statements ev
      WHERE ev.supplier_id IS NOT NULL
        AND ev.file_name REGEXP '^[a-z0-9_]+-[0-9]+-[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{4}-[0-9]{2}-[0-9]{2}[.][a-z]+$'
        AND EXISTS (SELECT 1 FROM bank_api_evidence_months e WHERE e.evidence_statement_id = ev.id)
        AND NOT EXISTS (SELECT 1 FROM bank_api_months m WHERE m.statement_id = ev.id)
      ORDER BY ev.supplier_id, ev.id"
)->fetchAll(PDO::FETCH_ASSOC);

$redundant = $pdo->prepare(
    'SELECT bti.bank_transaction_id FROM bank_transaction_imports bti
       JOIN bank_transactions bt ON bt.id = bti.bank_transaction_id
      WHERE bti.statement_id = ? AND bti.supplier_id = ?
        AND (bti.import_fingerprint = bt.import_fingerprint
             OR EXISTS (SELECT 1 FROM bank_transaction_imports earlier
                         WHERE earlier.bank_transaction_id = bti.bank_transaction_id
                           AND earlier.import_fingerprint = bti.import_fingerprint
                           AND earlier.statement_id < bti.statement_id
                           AND NOT EXISTS (SELECT 1 FROM bank_api_months m WHERE m.statement_id = earlier.statement_id)))'
);

$links = 0;
$touched = 0;
$locks = [];
try {
    foreach ($statements as $row) {
        $statementId = (int) $row['id'];
        $supplierId = (int) $row['supplier_id'];
        $redundant->execute([$statementId, $supplierId]);
        $ids = array_map('intval', $redundant->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) continue;
        $links += count($ids);
        $touched++;
        if (!$apply) continue;

        if (!isset($locks[$supplierId])) {
            $name = NamedLockName::for($db, 'bank-import', (string) $supplierId);
            $lock = $pdo->prepare('SELECT GET_LOCK(?, 30)');
            $lock->execute([$name]);
            if ((int) $lock->fetchColumn() !== 1) throw new RuntimeException('Probíhá import bankovních pohybů firmy ' . $supplierId . '. Spusťte úklid znovu.');
            $locks[$supplierId] = $name;
        }
        $pdo->beginTransaction();
        try {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $pdo->prepare('DELETE FROM bank_transaction_imports WHERE statement_id = ? AND bank_transaction_id IN ('
                    . implode(',', array_fill(0, count($chunk), '?')) . ')')
                    ->execute([$statementId, ...$chunk]);
            }
            $matched = (int) $pdo->query('SELECT COUNT(*) FROM bank_transactions bt WHERE ' . StatementTransactionScope::sql($statementId)
                . " AND bt.match_status IN ('auto_exact', 'auto_partial', 'manual')")->fetchColumn();
            $pdo->prepare('UPDATE bank_statements SET matched_count = ? WHERE id = ?')->execute([$matched, $statementId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
} finally {
    foreach ($locks as $name) {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
    }
}

echo json_encode(['apply' => $apply, 'statements' => $touched, 'links' => $links], JSON_THROW_ON_ERROR) . PHP_EOL;
