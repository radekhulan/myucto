<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\GoPay;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use PDO;
use PDOException;

final class GoPayService
{
    public function __construct(
        private readonly Connection $db,
        private readonly GoPayClearingXmlParser $parser,
        private readonly PostingService $posting,
        private readonly BankPostingService $bankPosting,
        private readonly JournalEntryRepository $journal,
    ) {}

    /** @return array<string,mixed> */
    public function settings(int $supplierId, string $currency = 'CZK'): array
    {
        $currency = $this->currency($currency);
        $stmt = $this->db->pdo()->prepare(
            'SELECT gs.*,
                    ga.account_code gopay_account_code, ga.name gopay_account_name,
                    ra.account_code receivable_account_code, ra.name receivable_account_name,
                    fa.account_code fee_account_code, fa.name fee_account_name,
                    ca.account_code clearing_account_code, ca.name clearing_account_name,
                    ba.account_code destination_bank_account_code, ba.name destination_bank_account_name
               FROM gopay_settings gs
               JOIN chart_of_accounts ga ON ga.id=gs.gopay_account_id AND ga.supplier_id=gs.supplier_id
               JOIN chart_of_accounts ra ON ra.id=gs.receivable_account_id AND ra.supplier_id=gs.supplier_id
               JOIN chart_of_accounts fa ON fa.id=gs.fee_account_id AND fa.supplier_id=gs.supplier_id
               JOIN chart_of_accounts ca ON ca.id=gs.clearing_account_id AND ca.supplier_id=gs.supplier_id
               JOIN chart_of_accounts ba ON ba.id=gs.destination_bank_account_id AND ba.supplier_id=gs.supplier_id
              WHERE gs.supplier_id=? AND gs.currency=?'
        );
        $stmt->execute([$supplierId, $currency]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $accounts = $this->db->pdo()->prepare(
            'SELECT id,account_code,name,account_type,is_synthetic,parent_id
               FROM chart_of_accounts
              WHERE supplier_id=? AND is_active=1
                AND (account_code LIKE "221%" OR account_code LIKE "261%"
                     OR account_code LIKE "311%" OR account_type="expense")
              ORDER BY account_code'
        );
        $accounts->execute([$supplierId]);
        $options = array_map(static function (array $account): array {
            $account['id'] = (int) $account['id'];
            $account['is_synthetic'] = (bool) $account['is_synthetic'];
            $account['parent_id'] = $account['parent_id'] !== null ? (int) $account['parent_id'] : null;
            return $account;
        }, $accounts->fetchAll(PDO::FETCH_ASSOC));

        return [
            'configured' => $row !== null,
            'settings' => $row === null ? [
                'currency' => $currency,
                'gopay_account_id' => null,
                'receivable_account_id' => null,
                'fee_account_id' => null,
                'clearing_account_id' => null,
                'destination_bank_account_id' => null,
                'payout_account_number' => '115-1391640287',
                'payout_bank_code' => '0100',
                'payout_date_tolerance_days' => 3,
            ] : $this->normalizeSettings($row),
            'account_options' => $options,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function saveSettings(int $supplierId, array $input, ?int $userId): array
    {
        $this->assertDoubleEntry($supplierId);
        $currency = $this->currency((string) ($input['currency'] ?? 'CZK'));
        $ids = [
            'gopay_account_id' => (int) ($input['gopay_account_id'] ?? 0),
            'receivable_account_id' => (int) ($input['receivable_account_id'] ?? 0),
            'fee_account_id' => (int) ($input['fee_account_id'] ?? 0),
            'clearing_account_id' => (int) ($input['clearing_account_id'] ?? 0),
            'destination_bank_account_id' => (int) ($input['destination_bank_account_id'] ?? 0),
        ];
        foreach ($ids as $field => $id) {
            if ($id <= 0) {
                throw new GoPayException('settings_incomplete', 'Vyber všechny účty pro automatické účtování.', 422, ['field' => $field]);
            }
        }
        if ($ids['gopay_account_id'] === $ids['destination_bank_account_id']) {
            throw new GoPayException('accounts_not_distinct', 'GoPay účet a cílový bankovní účet musí být různé analytiky.');
        }

        $accounts = $this->accountsById($supplierId, array_values($ids));
        $this->assertAccount($accounts, $ids['gopay_account_id'], '221', null, 'gopay_account_id');
        $this->assertAccount($accounts, $ids['destination_bank_account_id'], '221', null, 'destination_bank_account_id');
        $this->assertAccount($accounts, $ids['receivable_account_id'], '311', null, 'receivable_account_id');
        $this->assertAccount($accounts, $ids['clearing_account_id'], '261', null, 'clearing_account_id');
        $this->assertAccount($accounts, $ids['fee_account_id'], null, 'expense', 'fee_account_id');

        $accountNumber = preg_replace('/\s+/', '', trim((string) ($input['payout_account_number'] ?? '')));
        $bankCode = trim((string) ($input['payout_bank_code'] ?? ''));
        if (!is_string($accountNumber) || preg_match('/^(?:[0-9]{1,6}-)?[0-9]{1,10}$/', $accountNumber) !== 1) {
            throw new GoPayException('invalid_payout_account', 'Číslo výplatního účtu GoPay nemá platný český formát.');
        }
        if (preg_match('/^[0-9]{4}$/', $bankCode) !== 1) {
            throw new GoPayException('invalid_payout_bank_code', 'Kód banky GoPay musí mít čtyři číslice.');
        }
        $tolerance = (int) ($input['payout_date_tolerance_days'] ?? 3);
        if ($tolerance < 0 || $tolerance > 14) {
            throw new GoPayException('invalid_tolerance', 'Tolerance data musí být 0 až 14 dní.');
        }

        $this->db->pdo()->prepare(
            'INSERT INTO gopay_settings
                (supplier_id,currency,gopay_account_id,receivable_account_id,fee_account_id,
                 clearing_account_id,destination_bank_account_id,payout_account_number,
                 payout_bank_code,payout_date_tolerance_days,updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                gopay_account_id=VALUES(gopay_account_id),
                receivable_account_id=VALUES(receivable_account_id),
                fee_account_id=VALUES(fee_account_id),
                clearing_account_id=VALUES(clearing_account_id),
                destination_bank_account_id=VALUES(destination_bank_account_id),
                payout_account_number=VALUES(payout_account_number),
                payout_bank_code=VALUES(payout_bank_code),
                payout_date_tolerance_days=VALUES(payout_date_tolerance_days),
                updated_by=VALUES(updated_by)'
        )->execute([
            $supplierId, $currency, $ids['gopay_account_id'], $ids['receivable_account_id'],
            $ids['fee_account_id'], $ids['clearing_account_id'], $ids['destination_bank_account_id'],
            $accountNumber, $bankCode, $tolerance, $userId,
        ]);

        return $this->settings($supplierId, $currency);
    }

    /** @return list<array<string,mixed>> */
    public function listClearings(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,clearing_id,account_name,currency,variable_symbol,cleared_from,cleared_to,
                    performed_on,amount_gross,amount_fee,amount_storno,amount_storno_fee,
                    amount_transfer,amount_sent,file_name,status,movement_count,posted_count,
                    issue_count,payout_match_transaction_id,bank_transaction_id,imported_at,processed_at
               FROM gopay_clearings WHERE supplier_id=? ORDER BY performed_on DESC,id DESC'
        );
        $stmt->execute([$supplierId]);
        return array_map($this->normalizeClearing(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    public function detail(int $supplierId, int $clearingId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT gc.*,bt.posted_at bank_posted_on,bt.amount bank_amount,bt.counterparty_account,
                    bt.counterparty_bank,je.document_no bank_journal_document_no
               FROM gopay_clearings gc
          LEFT JOIN bank_transactions bt ON bt.id=gc.bank_transaction_id
          LEFT JOIN journal_entries je ON je.id=gc.bank_journal_entry_id AND je.supplier_id=gc.supplier_id
              WHERE gc.id=? AND gc.supplier_id=?'
        );
        $stmt->execute([$clearingId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new GoPayException('not_found', 'GoPay vyúčtování nebylo nalezeno.', 404);
        }

        $movements = $this->db->pdo()->prepare(
            'SELECT gm.*,i.varsymbol invoice_number,cn.varsymbol credit_note_number,
                    je.document_no journal_document_no
               FROM gopay_movements gm
          LEFT JOIN invoices i ON i.id=gm.invoice_id AND i.supplier_id=gm.supplier_id
          LEFT JOIN invoices cn ON cn.id=gm.credit_note_id AND cn.supplier_id=gm.supplier_id
          LEFT JOIN journal_entries je ON je.id=gm.journal_entry_id AND je.supplier_id=gm.supplier_id
              WHERE gm.clearing_id=? AND gm.supplier_id=? ORDER BY gm.performed_on,gm.id'
        );
        $movements->execute([$clearingId, $supplierId]);
        $items = array_map(static function (array $movement): array {
            foreach (['id', 'clearing_id', 'invoice_id', 'invoice_payment_id', 'credit_note_id', 'journal_entry_id'] as $field) {
                $movement[$field] = $movement[$field] !== null ? (int) $movement[$field] : null;
            }
            $movement['amount'] = (float) $movement['amount'];
            return $movement;
        }, $movements->fetchAll(PDO::FETCH_ASSOC));

        $result = $this->normalizeClearing($row);
        $result['has_file'] = true;
        $result['movements'] = $items;
        return $result;
    }

    /** @return array{content:string,file_name:string} */
    public function download(int $supplierId, int $clearingId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT file_content,file_name FROM gopay_clearings WHERE id=? AND supplier_id=?');
        $stmt->execute([$clearingId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new GoPayException('not_found', 'GoPay vyúčtování nebylo nalezeno.', 404);
        }
        return ['content' => (string) $row['file_content'], 'file_name' => (string) $row['file_name']];
    }

    /** @return array{deleted:bool,deleted_entry_ids:list<int>,preserved_bank_entry_id:int|null} */
    public function delete(int $supplierId, int $clearingId, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $ownTx = $this->beginUnit($pdo, 'gopay_delete');
        try {
            $stmt = $pdo->prepare(
                'SELECT id,clearing_id,payout_match_transaction_id,bank_transaction_id,bank_journal_entry_id,
                        bank_journal_entry_owned
                   FROM gopay_clearings
                  WHERE id=? AND supplier_id=? FOR UPDATE'
            );
            $stmt->execute([$clearingId, $supplierId]);
            $clearing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($clearing)) {
                throw new GoPayException('not_found', 'GoPay vyúčtování nebylo nalezeno.', 404);
            }

            $entries = $pdo->prepare(
                'SELECT DISTINCT je.id,je.entry_date,je.source_type,je.source_id,je.reversed_by,
                        ap.status period_status,
                        EXISTS(SELECT 1 FROM journal_entries original
                                WHERE original.supplier_id=je.supplier_id
                                  AND original.reversed_by=je.id) is_reversal
                   FROM journal_entries je
                   JOIN accounting_periods ap ON ap.id=je.period_id AND ap.supplier_id=je.supplier_id
                  WHERE je.supplier_id=?
                    AND ((je.source_type="gopay" AND je.source_id IN
                          (SELECT id FROM gopay_movements WHERE clearing_id=? AND supplier_id=?))
                         OR je.id=?)
                  FOR UPDATE'
            );
            $bankEntryOwned = (bool) $clearing['bank_journal_entry_owned'];
            if (!$bankEntryOwned
                && $clearing['bank_journal_entry_id'] !== null
                && $clearing['bank_transaction_id'] !== null) {
                $legacyOwned = $pdo->prepare(
                    'SELECT 1 FROM journal_entries
                      WHERE id=? AND supplier_id=? AND source_type="bank" AND source_id=? AND description=?
                      FOR UPDATE'
                );
                $legacyOwned->execute([
                    (int) $clearing['bank_journal_entry_id'],
                    $supplierId,
                    (int) $clearing['bank_transaction_id'],
                    'Přijetí vyúčtování GoPay ' . (string) $clearing['clearing_id'],
                ]);
                $bankEntryOwned = $legacyOwned->fetchColumn() !== false;
            }
            $bankEntryId = $clearing['bank_journal_entry_id'] !== null
                && $bankEntryOwned
                    ? (int) $clearing['bank_journal_entry_id'] : 0;
            $entries->execute([$supplierId, $clearingId, $supplierId, $bankEntryId]);
            $entryRows = $entries->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $locked = $pdo->prepare(
                'SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id=? FOR UPDATE'
            );
            $locked->execute([$supplierId]);
            $lockedUntil = $locked->fetchColumn();
            foreach ($entryRows as $entry) {
                if ((string) $entry['period_status'] !== 'open') {
                    throw new GoPayException(
                        'period_not_open',
                        'Vyúčtování obsahuje účetní zápis v uzavřeném období.',
                        409,
                    );
                }
                if ($lockedUntil !== false && $lockedUntil !== null
                    && (string) $entry['entry_date'] <= (string) $lockedUntil) {
                    throw new GoPayException(
                        'date_locked',
                        'Vyúčtování obsahuje účetní zápis v uzamčené části účetnictví.',
                        409,
                    );
                }
                if ($entry['reversed_by'] !== null || (bool) $entry['is_reversal']) {
                    throw new GoPayException(
                        'entry_has_reversal',
                        'Vyúčtování obsahuje stornovaný účetní zápis. Nejprve vyřeš jeho storno v deníku.',
                        409,
                    );
                }
            }

            $deletedEntryIds = [];
            foreach ($entryRows as $entry) {
                $entryId = (int) $entry['id'];
                if ((string) $entry['source_type'] === 'bank') {
                    $this->bankPosting->prepareEntryDeletion(
                        $supplierId,
                        (int) $entry['source_id'],
                        $entryId,
                        ['user_id' => $userId, 'reason' => 'gopay_clearing_delete'],
                    );
                }
                $deleteEntry = $pdo->prepare('DELETE FROM journal_entries WHERE id=? AND supplier_id=?');
                $deleteEntry->execute([$entryId, $supplierId]);
                if ($deleteEntry->rowCount() !== 1) {
                    throw new \RuntimeException('Účetní zápis GoPay se nepodařilo smazat.');
                }
                $deletedEntryIds[] = $entryId;
            }

            $transactionIds = array_values(array_unique(array_filter([
                $clearing['payout_match_transaction_id'] !== null ? (int) $clearing['payout_match_transaction_id'] : 0,
                $clearing['bank_transaction_id'] !== null ? (int) $clearing['bank_transaction_id'] : 0,
            ])));
            foreach ($transactionIds as $transactionId) {
                $pdo->prepare(
                    'UPDATE bank_transactions bt
                        SET bt.match_status="unmatched",bt.matched_at=NULL,bt.matched_by=NULL
                      WHERE bt.id=? AND bt.matched_invoice_id IS NULL
                        AND NOT EXISTS(SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id=bt.id)
                        AND NOT EXISTS(SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id=bt.id)
                        AND NOT EXISTS(SELECT 1 FROM journal_entries je
                                        WHERE je.supplier_id=? AND je.source_type="bank"
                                          AND je.source_id=bt.id AND je.reversed_by IS NULL)'
                )->execute([$transactionId, $supplierId]);
            }

            $deleteClearing = $pdo->prepare('DELETE FROM gopay_clearings WHERE id=? AND supplier_id=?');
            $deleteClearing->execute([$clearingId, $supplierId]);
            if ($deleteClearing->rowCount() !== 1) {
                throw new \RuntimeException('GoPay vyúčtování se nepodařilo smazat.');
            }

            $this->commitUnit($pdo, $ownTx, 'gopay_delete');
            return [
                'deleted' => true,
                'deleted_entry_ids' => $deletedEntryIds,
                'preserved_bank_entry_id' => $clearing['bank_journal_entry_id'] !== null
                    && !$bankEntryOwned
                        ? (int) $clearing['bank_journal_entry_id'] : null,
            ];
        } catch (\Throwable $e) {
            $this->rollbackUnit($pdo, $ownTx, 'gopay_delete');
            throw $e;
        }
    }

    /** @return array{duplicate:bool,clearing:array<string,mixed>} */
    public function import(int $supplierId, ?int $userId, string $fileName, string $xml): array
    {
        $this->assertDoubleEntry($supplierId);
        $parsed = $this->parser->parse($xml);
        $this->requireSettings($supplierId, $parsed['currency']);
        $hash = hash('sha256', $xml);
        $fileName = $this->safeFileName($fileName);

        $existing = $this->findExistingClearing($supplierId, $parsed['clearing_id'], $hash);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['file_hash'], $hash)
                || (string) $existing['clearing_id'] !== $parsed['clearing_id']) {
                throw new GoPayException('clearing_conflict', 'Stejný clearing ID nebo obsah už existuje s jinými údaji.', 409);
            }
            $id = (int) $existing['id'];
            $this->process($supplierId, $id, $userId);
            return ['duplicate' => true, 'clearing' => $this->detail($supplierId, $id)];
        }

        $pdo = $this->db->pdo();
        $ownTx = $this->beginUnit($pdo, 'gopay_import');
        try {
            $pdo->prepare(
                'INSERT INTO gopay_clearings
                    (supplier_id,clearing_id,account_name,currency,variable_symbol,cleared_from,cleared_to,
                     performed_on,amount_gross,amount_credit_note,amount_fee,amount_fee_external,
                     amount_storno,amount_storno_fee,amount_transfer,amount_sent,file_name,file_hash,
                     file_content,movement_count,imported_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $supplierId, $parsed['clearing_id'], $parsed['account_name'], $parsed['currency'],
                $parsed['variable_symbol'], $parsed['cleared_from'], $parsed['cleared_to'], $parsed['performed_on'],
                $parsed['amount_gross'], $parsed['amount_credit_note'], $parsed['amount_fee'],
                $parsed['amount_fee_external'], $parsed['amount_storno'], $parsed['amount_storno_fee'],
                $parsed['amount_transfer'], $parsed['amount_sent'], $fileName, $hash, $xml,
                count($parsed['movements']), $userId,
            ]);
            $clearingPk = (int) $pdo->lastInsertId();
            $insertMovement = $pdo->prepare(
                'INSERT INTO gopay_movements
                    (supplier_id,clearing_id,external_id,movement_type,performed_on,amount,order_id,
                     payment_session_id,account_movement_id,payment_channel,counterparty_name)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($parsed['movements'] as $movement) {
                $insertMovement->execute([
                    $supplierId, $clearingPk, $movement['external_id'], $movement['movement_type'],
                    $movement['performed_on'], $movement['amount'], $movement['order_id'],
                    $movement['payment_session_id'], $movement['account_movement_id'],
                    $movement['payment_channel'], $movement['counterparty_name'],
                ]);
            }
            $this->commitUnit($pdo, $ownTx, 'gopay_import');
        } catch (\Throwable $e) {
            $this->rollbackUnit($pdo, $ownTx, 'gopay_import');
            if ($e instanceof PDOException && ($e->errorInfo[0] ?? null) === '23000') {
                $existing = $this->findExistingClearing($supplierId, $parsed['clearing_id'], $hash);
                if ($existing !== null && hash_equals((string) $existing['file_hash'], $hash)) {
                    $clearingPk = (int) $existing['id'];
                    $this->process($supplierId, $clearingPk, $userId);
                    return ['duplicate' => true, 'clearing' => $this->detail($supplierId, $clearingPk)];
                }
            }
            throw $e;
        }

        $this->process($supplierId, $clearingPk, $userId);
        return ['duplicate' => false, 'clearing' => $this->detail($supplierId, $clearingPk)];
    }

    /** @return array<string,mixed> */
    public function process(int $supplierId, int $clearingId, ?int $userId): array
    {
        $this->assertDoubleEntry($supplierId);
        $clearing = $this->clearingRow($supplierId, $clearingId);
        $this->requireSettings($supplierId, (string) $clearing['currency']);
        $this->db->pdo()->prepare('UPDATE gopay_clearings SET status="processing" WHERE id=? AND supplier_id=?')
            ->execute([$clearingId, $supplierId]);

        $ids = $this->db->pdo()->prepare('SELECT id FROM gopay_movements WHERE clearing_id=? AND supplier_id=? ORDER BY id');
        $ids->execute([$clearingId, $supplierId]);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $movementId) {
            $this->processMovement($supplierId, (int) $movementId, $userId);
        }
        $this->matchPayout($supplierId, $clearingId, $userId);
        $this->refreshClearingStatus($supplierId, $clearingId);
        return $this->detail($supplierId, $clearingId);
    }

    /** @return array<string,mixed>|null */
    public function payoutCandidateForTransaction(int $supplierId, int $transactionId): ?array
    {
        $transaction = $this->payoutTransaction($supplierId, $transactionId);
        $candidates = $this->payoutCandidates($supplierId, $transaction);
        if (count($candidates) > 1) {
            throw new GoPayException('payout_ambiguous', 'Bankovnímu pohybu odpovídá více GoPay vyúčtování.', 409);
        }
        if ($candidates === []) {
            return null;
        }

        $candidate = $this->normalizeClearing($candidates[0]);
        $candidate['transaction_source'] = (string) $transaction['source'];
        return $candidate;
    }

    /** @return array<string,mixed> */
    public function associatePayoutTransaction(
        int $supplierId,
        int $clearingId,
        int $transactionId,
        ?int $userId,
    ): array {
        $this->assertDoubleEntry($supplierId);
        $transaction = $this->payoutTransaction($supplierId, $transactionId);
        $candidates = $this->payoutCandidates($supplierId, $transaction);
        $candidateIds = array_map(static fn (array $row): int => (int) $row['id'], $candidates);
        if (!in_array($clearingId, $candidateIds, true)) {
            throw new GoPayException('payout_candidate_mismatch', 'Bankovní pohyb neodpovídá zvolenému GoPay vyúčtování.', 409);
        }
        if (count($candidateIds) !== 1) {
            throw new GoPayException('payout_ambiguous', 'Bankovnímu pohybu odpovídá více GoPay vyúčtování.', 409);
        }

        $pdo = $this->db->pdo();
        $ownTx = $this->beginUnit($pdo, 'gopay_payout_associate');
        try {
            $locked = $pdo->prepare('SELECT payout_match_transaction_id,bank_transaction_id FROM gopay_clearings WHERE id=? AND supplier_id=? FOR UPDATE');
            $locked->execute([$clearingId, $supplierId]);
            $clearing = $locked->fetch(PDO::FETCH_ASSOC);
            if (!is_array($clearing)) {
                throw new GoPayException('not_found', 'GoPay vyúčtování nebylo nalezeno.', 404);
            }
            if ($clearing['bank_transaction_id'] !== null && (int) $clearing['bank_transaction_id'] !== $transactionId) {
                throw new GoPayException('payout_already_posted', 'GoPay vyúčtování už je zaúčtované proti jinému bankovnímu pohybu.', 409);
            }
            if ((string) $transaction['source'] === 'email_notice'
                && $clearing['payout_match_transaction_id'] !== null
                && (int) $clearing['payout_match_transaction_id'] !== $transactionId) {
                throw new GoPayException('payout_already_matched', 'GoPay vyúčtování už je spárované s jiným avízem.', 409);
            }

            $pdo->prepare('UPDATE gopay_clearings SET payout_match_transaction_id=? WHERE id=? AND supplier_id=?')
                ->execute([$transactionId, $clearingId, $supplierId]);

            if ((string) $transaction['source'] === 'email_notice') {
                $pdo->prepare(
                    'UPDATE bank_transactions
                        SET match_status="manual",matched_at=NOW(),matched_by=?
                      WHERE id=?'
                )->execute([$userId, $transactionId]);
                $pdo->prepare(
                    'UPDATE gopay_clearings
                        SET payout_issue_code="email_notice_provisional",
                            payout_issue_message="Avízo je spárované. Zaúčtování převodu počká na bankovní výpis."
                      WHERE id=? AND supplier_id=?'
                )->execute([$clearingId, $supplierId]);
            } else {
                $this->matchPayout($supplierId, $clearingId, $userId);
            }
            $this->refreshClearingStatus($supplierId, $clearingId);
            $this->commitUnit($pdo, $ownTx, 'gopay_payout_associate');
        } catch (\Throwable $e) {
            $this->rollbackUnit($pdo, $ownTx, 'gopay_payout_associate');
            throw $e;
        }

        return $this->detail($supplierId, $clearingId);
    }

    public function completeTransferredPayout(int $supplierId, int $transactionId, ?int $userId = null): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM gopay_clearings
              WHERE supplier_id=? AND payout_match_transaction_id=? LIMIT 2'
        );
        $stmt->execute([$supplierId, $transactionId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) !== 1) {
            return false;
        }

        $clearingId = (int) $ids[0];
        $this->matchPayout($supplierId, $clearingId, $userId);
        $this->refreshClearingStatus($supplierId, $clearingId);
        $row = $this->clearingRow($supplierId, $clearingId);
        return (int) ($row['bank_transaction_id'] ?? 0) === $transactionId
            && $row['bank_journal_entry_id'] !== null;
    }

    private function processMovement(int $supplierId, int $movementId, ?int $userId): void
    {
        $pdo = $this->db->pdo();
        $ownTx = $this->beginUnit($pdo, 'gopay_movement');
        try {
            $stmt = $pdo->prepare(
                'SELECT gm.*,gc.clearing_id provider_clearing_id,gc.currency,
                        gs.gopay_account_id,gs.receivable_account_id,gs.fee_account_id,gs.clearing_account_id
                   FROM gopay_movements gm
                   JOIN gopay_clearings gc ON gc.id=gm.clearing_id AND gc.supplier_id=gm.supplier_id
                   JOIN gopay_settings gs ON gs.supplier_id=gm.supplier_id AND gs.currency=gc.currency
                  WHERE gm.id=? AND gm.supplier_id=? FOR UPDATE'
            );
            $stmt->execute([$movementId, $supplierId]);
            $movement = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($movement)) {
                throw new GoPayException('movement_not_found', 'GoPay pohyb nebyl nalezen.', 404);
            }
            if ($movement['status'] === 'posted' && $movement['journal_entry_id'] !== null) {
                $existing = $this->journal->find((int) $movement['journal_entry_id'], $supplierId);
                if (is_array($existing) && ($existing['reversed_by'] ?? null) === null) {
                    $this->commitUnit($pdo, $ownTx, 'gopay_movement');
                    return;
                }
            }

            $accounts = $this->accountsById($supplierId, [
                (int) $movement['gopay_account_id'], (int) $movement['receivable_account_id'],
                (int) $movement['fee_account_id'], (int) $movement['clearing_account_id'],
            ]);
            $gopay = $accounts[(int) $movement['gopay_account_id']]['account_code'];
            $receivable = $accounts[(int) $movement['receivable_account_id']]['account_code'];
            $fee = $accounts[(int) $movement['fee_account_id']]['account_code'];
            $clearing = $accounts[(int) $movement['clearing_account_id']]['account_code'];
            $amount = number_format(abs((float) $movement['amount']), 2, '.', '');

            $links = ['invoice_id' => null, 'invoice_payment_id' => null, 'credit_note_id' => null];
            [$debit, $credit, $description] = match ((string) $movement['movement_type']) {
                'credit' => $this->creditPosting($supplierId, $movement, $gopay, $receivable, $links),
                'storno' => $this->stornoPosting($supplierId, $movement, $receivable, $gopay, $links),
                'storno_fee' => [$fee, $gopay, 'Poplatek GoPay za vratku'],
                'clearing_fee' => [$fee, $gopay, 'Poplatek GoPay za vyúčtování a zpracování plateb'],
                'fee_credit' => [$gopay, $fee, 'Dobropis poplatků GoPay'],
                'payout' => [$clearing, $gopay, 'Převod vyúčtování GoPay na běžný účet'],
                default => throw new GoPayException('unsupported_movement', 'Nepodporovaný typ GoPay pohybu.'),
            };

            $entryId = $this->posting->postDocument($supplierId, 'gopay', $movementId, [
                ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
                ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
            ], [
                'entry_date' => (string) $movement['performed_on'],
                'document_date' => (string) $movement['performed_on'],
                'document_no' => 'GP-' . $movement['provider_clearing_id'] . '-' . $movementId,
                'description' => $description,
                'posted' => true,
                'posted_by' => $userId,
                'user_id' => $userId,
            ]);

            $pdo->prepare(
                'UPDATE gopay_movements
                    SET invoice_id=?,invoice_payment_id=?,credit_note_id=?,journal_entry_id=?,
                        status="posted",issue_code=NULL,issue_message=NULL,processed_at=NOW()
                  WHERE id=? AND supplier_id=?'
            )->execute([
                $links['invoice_id'], $links['invoice_payment_id'], $links['credit_note_id'],
                $entryId, $movementId, $supplierId,
            ]);
            $this->commitUnit($pdo, $ownTx, 'gopay_movement');
        } catch (\Throwable $e) {
            $this->rollbackUnit($pdo, $ownTx, 'gopay_movement');
            $status = $e instanceof GoPayException ? 'unmatched' : 'error';
            $code = $e instanceof GoPayException ? $e->errorCode
                : ($e instanceof PostingException ? $e->errorCode : 'processing_failed');
            $message = mb_substr($e->getMessage(), 0, 500);
            $pdo->prepare(
                'UPDATE gopay_movements SET status=?,issue_code=?,issue_message=?,processed_at=NOW()
                  WHERE id=? AND supplier_id=? AND status<>"posted"'
            )->execute([$status, $code, $message, $movementId, $supplierId]);
        }
    }

    /** @param array<string,mixed> $movement @param array<string,?int> $links @return array{string,string,string} */
    private function creditPosting(int $supplierId, array $movement, string $gopay, string $receivable, array &$links): array
    {
        $match = $this->matchInvoicePayment($supplierId, $movement);
        $links['invoice_id'] = (int) $match['invoice_id'];
        $links['invoice_payment_id'] = (int) $match['payment_id'];
        return [$gopay, $receivable, 'GoPay úhrada faktury ' . (string) $match['varsymbol'] . ' (' . (string) $movement['order_id'] . ')'];
    }

    /** @param array<string,mixed> $movement @param array<string,?int> $links @return array{string,string,string} */
    private function stornoPosting(int $supplierId, array $movement, string $receivable, string $gopay, array &$links): array
    {
        $match = $this->matchCreditNote($supplierId, $movement);
        $links['credit_note_id'] = (int) $match['id'];
        return [$receivable, $gopay, 'GoPay vratka k dobropisu ' . (string) $match['varsymbol'] . ' (' . (string) $movement['order_id'] . ')'];
    }

    /** @param array<string,mixed> $movement @return array<string,mixed> */
    private function matchInvoicePayment(int $supplierId, array $movement): array
    {
        $paymentSessionId = trim((string) ($movement['payment_session_id'] ?? ''));
        $amount = number_format(abs((float) $movement['amount']), 2, '.', '');
        $currency = (string) $movement['currency'];
        if ($paymentSessionId !== '') {
            $stmt = $this->db->pdo()->prepare(
                'SELECT p.id payment_id,p.invoice_id,p.amount,p.currency,i.varsymbol,
                        i.supplier_order_number,i.note_below_items
                   FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id AND i.supplier_id=p.supplier_id
                  WHERE p.supplier_id=? AND p.bank_reference=?
                    AND i.invoice_type IN ("invoice","proforma")'
            );
            $stmt->execute([$supplierId, 'GOPAY:' . $paymentSessionId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 1) {
                throw new GoPayException('payment_reference_ambiguous', 'GoPay ID je uložené u více úhrad.');
            }
            if (count($rows) === 1) {
                if (number_format((float) $rows[0]['amount'], 2, '.', '') !== $amount || (string) $rows[0]['currency'] !== $currency) {
                    throw new GoPayException('payment_amount_mismatch', 'GoPay platba se liší částkou nebo měnou od úhrady faktury.');
                }
                $this->assertInvoicePosted($supplierId, (int) $rows[0]['invoice_id']);
                return $rows[0];
            }
        }

        $orderId = trim((string) ($movement['order_id'] ?? ''));
        if ($orderId === '') {
            throw new GoPayException('invoice_reference_missing', 'Platba nemá GoPay ID ani číslo objednávky.');
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id payment_id,p.invoice_id,p.amount,p.currency,i.varsymbol,
                    i.supplier_order_number,i.note_below_items
               FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id AND i.supplier_id=p.supplier_id
              WHERE p.supplier_id=? AND p.amount=? AND p.currency=?
                AND i.invoice_type IN ("invoice","proforma")
                AND (i.supplier_order_number=?
                     OR (i.supplier_order_number IS NULL AND i.note_below_items LIKE ?))'
        );
        $stmt->execute([$supplierId, $amount, $currency, $orderId, '%' . $orderId . '%']);
        $rows = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),
            fn (array $row): bool => $this->documentHasOrder($row, $orderId)));
        if (count($rows) !== 1) {
            throw new GoPayException(count($rows) === 0 ? 'invoice_not_found' : 'invoice_ambiguous',
                count($rows) === 0 ? 'K GoPay platbě nebyla nalezena faktura a její úhrada.' : 'K GoPay platbě bylo nalezeno více faktur.');
        }
        $this->assertInvoicePosted($supplierId, (int) $rows[0]['invoice_id']);
        return $rows[0];
    }

    /** @param array<string,mixed> $movement @return array<string,mixed> */
    private function matchCreditNote(int $supplierId, array $movement): array
    {
        $orderId = trim((string) ($movement['order_id'] ?? ''));
        if ($orderId === '') {
            throw new GoPayException('credit_note_reference_missing', 'Vratka nemá číslo objednávky.');
        }
        $amount = number_format(abs((float) $movement['amount']), 2, '.', '');
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id,i.varsymbol,i.supplier_order_number,i.note_below_items,i.parent_invoice_id
               FROM invoices i JOIN currencies c ON c.id=i.currency_id
              WHERE i.supplier_id=? AND i.invoice_type="credit_note" AND ABS(i.amount_to_pay)=?
                AND c.code=?
                AND (i.supplier_order_number=?
                     OR (i.supplier_order_number IS NULL AND i.note_below_items LIKE ?))'
        );
        $stmt->execute([$supplierId, $amount, (string) $movement['currency'], $orderId, '%' . $orderId . '%']);
        $rows = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),
            fn (array $row): bool => $this->documentHasOrder($row, $orderId)));
        if (count($rows) !== 1) {
            throw new GoPayException(count($rows) === 0 ? 'credit_note_not_found' : 'credit_note_ambiguous',
                count($rows) === 0 ? 'K GoPay vratce nebyl nalezen dobropis.' : 'K GoPay vratce bylo nalezeno více dobropisů.');
        }
        $this->assertInvoicePosted($supplierId, (int) $rows[0]['id']);
        return $rows[0];
    }

    private function assertInvoicePosted(int $supplierId, int $invoiceId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM journal_entries
              WHERE supplier_id=? AND source_type="invoice" AND source_id=?
                AND posted_at IS NOT NULL AND reversed_by IS NULL LIMIT 1'
        );
        $stmt->execute([$supplierId, $invoiceId]);
        if ($stmt->fetchColumn() === false) {
            throw new GoPayException('invoice_not_posted', 'Faktura nebo dobropis ještě není zaúčtovaný v deníku.');
        }
    }

    private function matchPayout(int $supplierId, int $clearingId, ?int $userId): void
    {
        $pdo = $this->db->pdo();
        $ownTx = $this->beginUnit($pdo, 'gopay_payout');
        try {
            $stmt = $pdo->prepare(
                'SELECT gc.*,gs.payout_account_number,gs.payout_bank_code,gs.payout_date_tolerance_days,
                        ba.account_code destination_bank_account_code,ca.account_code clearing_account_code
                   FROM gopay_clearings gc
                   JOIN gopay_settings gs ON gs.supplier_id=gc.supplier_id AND gs.currency=gc.currency
                   JOIN chart_of_accounts ba ON ba.id=gs.destination_bank_account_id AND ba.supplier_id=gs.supplier_id
                   JOIN chart_of_accounts ca ON ca.id=gs.clearing_account_id AND ca.supplier_id=gs.supplier_id
                  WHERE gc.id=? AND gc.supplier_id=? FOR UPDATE'
            );
            $stmt->execute([$clearingId, $supplierId]);
            $clearing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($clearing)) {
                throw new GoPayException('not_found', 'GoPay vyúčtování nebylo nalezeno.', 404);
            }
            if ((float) $clearing['amount_sent'] <= 0.0) {
                $pdo->prepare('UPDATE gopay_clearings SET payout_issue_code=NULL,payout_issue_message=NULL WHERE id=? AND supplier_id=?')
                    ->execute([$clearingId, $supplierId]);
                $this->commitUnit($pdo, $ownTx, 'gopay_payout');
                return;
            }

            $tolerance = (int) $clearing['payout_date_tolerance_days'];
            $candidates = $pdo->prepare(
                'SELECT bt.id,bt.amount,bt.currency,bt.posted_at,bt.variable_symbol,
                        bt.counterparty_account,bt.counterparty_bank
                   FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id
                  WHERE bs.supplier_id=? AND bt.source="statement" AND bs.source IN ("gpc","pdf")
                    AND bt.amount=? AND COALESCE(bt.currency,bs.currency)=?
                    AND bt.variable_symbol=?
                    AND bt.posted_at BETWEEN DATE_SUB(?,INTERVAL ? DAY) AND DATE_ADD(?,INTERVAL ? DAY)'
            );
            $candidates->execute([
                $supplierId, $clearing['amount_sent'], $clearing['currency'], $clearing['variable_symbol'],
                $clearing['performed_on'], $tolerance, $clearing['performed_on'], $tolerance,
            ]);
            $rows = array_values(array_filter($candidates->fetchAll(PDO::FETCH_ASSOC), function (array $row) use ($clearing): bool {
                return $this->accountKey((string) ($row['counterparty_account'] ?? '')) === $this->accountKey((string) $clearing['payout_account_number'])
                    && trim((string) ($row['counterparty_bank'] ?? '')) === (string) $clearing['payout_bank_code'];
            }));
            $matchedTransactionId = $clearing['payout_match_transaction_id'] !== null
                ? (int) $clearing['payout_match_transaction_id'] : null;
            if ($matchedTransactionId !== null) {
                $matchedSource = $pdo->prepare('SELECT source FROM bank_transactions WHERE id=?');
                $matchedSource->execute([$matchedTransactionId]);
                if ($matchedSource->fetchColumn() === 'statement') {
                    $rows = array_values(array_filter(
                        $rows,
                        static fn (array $row): bool => (int) $row['id'] === $matchedTransactionId,
                    ));
                }
            }
            if (count($rows) !== 1) {
                $matchedNotice = false;
                if ($matchedTransactionId !== null) {
                    $notice = $pdo->prepare('SELECT 1 FROM bank_transactions WHERE id=? AND source="email_notice"');
                    $notice->execute([$matchedTransactionId]);
                    $matchedNotice = $notice->fetchColumn() !== false;
                }
                $code = $matchedNotice ? 'email_notice_provisional'
                    : (count($rows) === 0 ? 'payout_not_found' : 'payout_ambiguous');
                $message = $matchedNotice
                    ? 'Avízo je spárované. Zaúčtování převodu počká na bankovní výpis.'
                    : (count($rows) === 0
                        ? 'Příchozí bankovní převod odpovídající clearingu zatím nebyl nalezen.'
                        : 'Clearingu odpovídá více bankovních převodů.');
                $pdo->prepare('UPDATE gopay_clearings SET bank_transaction_id=NULL,bank_journal_entry_id=NULL,bank_journal_entry_owned=0,payout_issue_code=?,payout_issue_message=? WHERE id=? AND supplier_id=?')
                    ->execute([$code, $message, $clearingId, $supplierId]);
                $this->commitUnit($pdo, $ownTx, 'gopay_payout');
                return;
            }

            $txId = (int) $rows[0]['id'];
            $existing = $this->journal->findBySource($supplierId, 'bank', $txId);
            if ($existing !== null && ($existing['reversed_by'] ?? null) === null) {
                $entryId = (int) $existing['id'];
                $entryOwned = (int) ($clearing['bank_journal_entry_id'] ?? 0) === $entryId
                    && (bool) ($clearing['bank_journal_entry_owned'] ?? false);
                if (!$this->entryHasPair($supplierId, $entryId, (string) $clearing['destination_bank_account_code'], (string) $clearing['clearing_account_code'], (float) $clearing['amount_sent'])) {
                    throw new GoPayException('payout_posting_conflict', 'Bankovní převod je už zaúčtovaný na jiné účty.', 409);
                }
            } else {
                $result = $this->bankPosting->postManual($supplierId, $txId, [
                    'debit_account_code' => (string) $clearing['destination_bank_account_code'],
                    'credit_account_code' => (string) $clearing['clearing_account_code'],
                    'description' => 'Přijetí vyúčtování GoPay ' . (string) $clearing['clearing_id'],
                ], ['user_id' => $userId, 'posted_by' => $userId]);
                $entryId = (int) $result['entry_id'];
                $entryOwned = true;
            }
            $pdo->prepare(
                'UPDATE gopay_clearings
                    SET payout_match_transaction_id=?,bank_transaction_id=?,bank_journal_entry_id=?,bank_journal_entry_owned=?,
                        payout_issue_code=NULL,payout_issue_message=NULL
                  WHERE id=? AND supplier_id=?'
            )->execute([$txId, $txId, $entryId, $entryOwned ? 1 : 0, $clearingId, $supplierId]);
            $pdo->prepare(
                'UPDATE bank_transactions
                    SET match_status=IF(match_status="unmatched","manual",match_status),
                        matched_at=COALESCE(matched_at,NOW()),matched_by=COALESCE(matched_by,?)
                  WHERE id=?'
            )->execute([$userId, $txId]);
            $this->commitUnit($pdo, $ownTx, 'gopay_payout');
        } catch (\Throwable $e) {
            $this->rollbackUnit($pdo, $ownTx, 'gopay_payout');
            $code = $e instanceof GoPayException ? $e->errorCode
                : ($e instanceof PostingException ? $e->errorCode : 'payout_processing_failed');
            $pdo->prepare(
                'UPDATE gopay_clearings SET payout_issue_code=?,payout_issue_message=? WHERE id=? AND supplier_id=?'
            )->execute([$code, mb_substr($e->getMessage(), 0, 500), $clearingId, $supplierId]);
        }
    }

    private function refreshClearingStatus(int $supplierId, int $clearingId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) movement_count,SUM(status="posted") posted_count,SUM(status<>"posted") movement_issues
               FROM gopay_movements WHERE clearing_id=? AND supplier_id=?'
        );
        $stmt->execute([$clearingId, $supplierId]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $payout = $this->db->pdo()->prepare('SELECT amount_sent,payout_issue_code FROM gopay_clearings WHERE id=? AND supplier_id=?');
        $payout->execute([$clearingId, $supplierId]);
        $row = $payout->fetch(PDO::FETCH_ASSOC) ?: [];
        $payoutIssue = (float) ($row['amount_sent'] ?? 0) > 0.0 && ($row['payout_issue_code'] ?? null) !== null ? 1 : 0;
        $issues = (int) ($counts['movement_issues'] ?? 0) + $payoutIssue;
        $this->db->pdo()->prepare(
            'UPDATE gopay_clearings SET status=?,movement_count=?,posted_count=?,issue_count=?,processed_at=NOW()
              WHERE id=? AND supplier_id=?'
        )->execute([
            $issues === 0 ? 'processed' : 'needs_review',
            (int) ($counts['movement_count'] ?? 0), (int) ($counts['posted_count'] ?? 0),
            $issues, $clearingId, $supplierId,
        ]);
    }

    /** @return array<string,mixed> */
    private function payoutTransaction(int $supplierId, int $transactionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.*,bs.source statement_source,bs.currency statement_currency
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id=bt.statement_id
              WHERE bt.id=? AND bs.supplier_id=?'
        );
        $stmt->execute([$transactionId, $supplierId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($transaction)) {
            throw new GoPayException('bank_transaction_not_found', 'Bankovní pohyb nebyl nalezen.', 404);
        }
        $source = (string) ($transaction['source'] ?? '');
        $statementSource = (string) ($transaction['statement_source'] ?? '');
        $valid = ($source === 'email_notice' && $statementSource === 'email_notice')
            || ($source === 'statement' && in_array($statementSource, ['gpc', 'pdf'], true));
        if (!$valid) {
            throw new GoPayException('unsupported_bank_source', 'Pohyb není avízo ani položka bankovního výpisu.', 409);
        }
        return $transaction;
    }

    /** @param array<string,mixed> $transaction @return list<array<string,mixed>> */
    private function payoutCandidates(int $supplierId, array $transaction): array
    {
        if ((float) $transaction['amount'] <= 0.0) {
            return [];
        }
        $currency = strtoupper((string) ($transaction['currency'] ?: $transaction['statement_currency'] ?: ''));
        $stmt = $this->db->pdo()->prepare(
            'SELECT gc.*,gs.payout_account_number,gs.payout_bank_code,gs.payout_date_tolerance_days
               FROM gopay_clearings gc
               JOIN gopay_settings gs ON gs.supplier_id=gc.supplier_id AND gs.currency=gc.currency
              WHERE gc.supplier_id=? AND gc.amount_sent>0
                AND ABS(gc.amount_sent-?)<=0.005 AND gc.currency=?
                AND gc.performed_on BETWEEN DATE_SUB(?,INTERVAL 14 DAY) AND DATE_ADD(?,INTERVAL 14 DAY)
                AND (gc.bank_transaction_id IS NULL OR gc.bank_transaction_id=?)
              ORDER BY gc.id'
        );
        $stmt->execute([
            $supplierId,
            number_format((float) $transaction['amount'], 2, '.', ''),
            $currency,
            $transaction['posted_at'],
            $transaction['posted_at'],
            (int) $transaction['id'],
        ]);

        $transactionSymbol = $this->symbolKey((string) ($transaction['variable_symbol'] ?? ''));
        $transactionAccount = $this->accountKey((string) ($transaction['counterparty_account'] ?? ''));
        $transactionBank = trim((string) ($transaction['counterparty_bank'] ?? ''));
        $transactionDate = new \DateTimeImmutable((string) $transaction['posted_at']);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($transactionSymbol === '' || $transactionSymbol !== $this->symbolKey((string) $row['variable_symbol'])) {
                continue;
            }
            if ($transactionAccount === '' || $transactionAccount !== $this->accountKey((string) $row['payout_account_number'])) {
                continue;
            }
            if ($transactionBank === '' || $transactionBank !== trim((string) $row['payout_bank_code'])) {
                continue;
            }
            $performedOn = new \DateTimeImmutable((string) $row['performed_on']);
            $days = abs((int) $performedOn->diff($transactionDate)->format('%r%a'));
            if ($days > (int) $row['payout_date_tolerance_days']) {
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function requireSettings(int $supplierId, string $currency): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM gopay_settings WHERE supplier_id=? AND currency=?');
        $stmt->execute([$supplierId, $currency]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new GoPayException('settings_missing', 'Před importem nastav účty modulu GoPay.', 409);
        }
        return $row;
    }

    private function assertDoubleEntry(int $supplierId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id=?');
        $stmt->execute([$supplierId]);
        if ($stmt->fetchColumn() !== 'double_entry') {
            throw new GoPayException('not_double_entry', 'GoPay automatické účtování vyžaduje podvojné účetnictví.', 409);
        }
    }

    /** @param list<int> $ids @return array<int,array<string,mixed>> */
    private function accountsById(int $supplierId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,account_code,name,account_type,is_active,is_synthetic FROM chart_of_accounts
              WHERE supplier_id=? AND id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$supplierId], $ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = $row;
        }
        if (count($out) !== count($ids)) {
            throw new GoPayException('account_not_found', 'Některý zvolený účet nepatří této firmě.');
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $accounts */
    private function assertAccount(array $accounts, int $id, ?string $prefix, ?string $type, string $field): void
    {
        $account = $accounts[$id] ?? null;
        if (!is_array($account) || !(bool) $account['is_active']
            || ($prefix !== null && !str_starts_with((string) $account['account_code'], $prefix))
            || ($type !== null && (string) $account['account_type'] !== $type)) {
            throw new GoPayException('invalid_account', 'Zvolený účet neodpovídá požadované účetní skupině.', 422, ['field' => $field]);
        }
    }

    /** @return array<string,mixed>|null */
    private function findExistingClearing(int $supplierId, string $clearingId, string $hash): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id,clearing_id,file_hash FROM gopay_clearings WHERE supplier_id=? AND (clearing_id=? OR file_hash=?) LIMIT 1');
        $stmt->execute([$supplierId, $clearingId, $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function clearingRow(int $supplierId, int $clearingId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM gopay_clearings WHERE id=? AND supplier_id=?');
        $stmt->execute([$clearingId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new GoPayException('not_found', 'GoPay vyúčtování nebylo nalezeno.', 404);
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function normalizeSettings(array $row): array
    {
        foreach (['id', 'supplier_id', 'gopay_account_id', 'receivable_account_id', 'fee_account_id', 'clearing_account_id', 'destination_bank_account_id', 'updated_by'] as $field) {
            $row[$field] = $row[$field] !== null ? (int) $row[$field] : null;
        }
        $row['payout_date_tolerance_days'] = (int) $row['payout_date_tolerance_days'];
        return $row;
    }

    /** @return array<string,mixed> */
    private function normalizeClearing(array $row): array
    {
        foreach (['id', 'movement_count', 'posted_count', 'issue_count', 'payout_match_transaction_id', 'bank_transaction_id', 'bank_journal_entry_id', 'imported_by'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $row[$field] !== null ? (int) $row[$field] : null;
            }
        }
        unset($row['bank_journal_entry_owned']);
        foreach (['amount_gross', 'amount_credit_note', 'amount_fee', 'amount_fee_external', 'amount_storno', 'amount_storno_fee', 'amount_transfer', 'amount_sent', 'bank_amount'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $row[$field] !== null ? (float) $row[$field] : null;
            }
        }
        unset($row['file_content']);
        return $row;
    }

    private function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new GoPayException('invalid_currency', 'Měna musí být platný třípísmenný kód.');
        }
        return $currency;
    }

    private function safeFileName(string $fileName): string
    {
        $parts = preg_split('~[\\\\/]~', $fileName) ?: [];
        $name = trim((string) end($parts));
        if ($name === '' || !str_ends_with(strtolower($name), '.xml')) {
            $name = 'GoPay-clearing.xml';
        }
        return mb_substr($name, 0, 255);
    }

    private function noteHasOrder(string $note, string $orderId): bool
    {
        return preg_match('/(?:^|\R)\s*Objednávka:\s*' . preg_quote($orderId, '/') . '\s*(?:\R|$)/iu', $note) === 1;
    }

    /** @param array<string,mixed> $document */
    private function documentHasOrder(array $document, string $orderId): bool
    {
        $stored = trim((string) ($document['supplier_order_number'] ?? ''));
        if ($stored !== '') {
            return mb_strtoupper($stored) === mb_strtoupper(trim($orderId));
        }
        return $this->noteHasOrder((string) ($document['note_below_items'] ?? ''), $orderId);
    }

    private function accountKey(string $account): string
    {
        return ltrim((string) preg_replace('/[^0-9]/', '', $account), '0');
    }

    private function symbolKey(string $symbol): string
    {
        return ltrim((string) preg_replace('/[^0-9]/', '', $symbol), '0');
    }

    private function entryHasPair(int $supplierId, int $entryId, string $debitCode, string $creditCode, float $amount): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT coa.account_code,jel.side,jel.amount
               FROM journal_entry_lines jel JOIN chart_of_accounts coa ON coa.id=jel.account_id
              WHERE jel.entry_id=? AND jel.supplier_id=? ORDER BY jel.line_no,jel.id'
        );
        $stmt->execute([$entryId, $supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 2) {
            return false;
        }
        $expected = number_format($amount, 2, '.', '');
        $foundDebit = false;
        $foundCredit = false;
        foreach ($rows as $row) {
            if (number_format((float) $row['amount'], 2, '.', '') !== $expected) {
                return false;
            }
            $foundDebit = $foundDebit || ($row['side'] === 'debit' && $row['account_code'] === $debitCode);
            $foundCredit = $foundCredit || ($row['side'] === 'credit' && $row['account_code'] === $creditCode);
        }
        return $foundDebit && $foundCredit;
    }

    private function beginUnit(PDO $pdo, string $savepoint): bool
    {
        if ($pdo->inTransaction()) {
            $pdo->exec('SAVEPOINT ' . $savepoint);
            return false;
        }
        $pdo->beginTransaction();
        return true;
    }

    private function commitUnit(PDO $pdo, bool $ownTx, string $savepoint): void
    {
        if ($ownTx) {
            $pdo->commit();
            return;
        }
        $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
    }

    private function rollbackUnit(PDO $pdo, bool $ownTx, string $savepoint): void
    {
        if ($ownTx) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return;
        }
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
    }
}
