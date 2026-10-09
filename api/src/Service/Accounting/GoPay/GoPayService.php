<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\GoPay;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\MovementClassificationRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\TaxEvidence\TaxEvidenceYearLock;
use PDO;
use PDOException;

final class GoPayService
{
    use GoPayUnitOfWork;

    public function __construct(
        private readonly Connection $db,
        private readonly GoPayClearingXmlParser $parser,
        private readonly GoPayStatementXlsxParser $statementParser,
        private readonly BankPostingService $bankPosting,
        private readonly JournalEntryRepository $journal,
        private readonly InvoicePaymentService $invoicePayments,
        private readonly ActivityLogger $activity,
        private readonly GoPayMovementPoster $poster,
        private readonly GoPayPendingService $pending,
        private readonly MovementClassificationRepository $classifications,
    ) {}

    /** Poznámka zařazení výplaty v peněžním deníku; podle ní ho smazání vyúčtování pozná. */
    private const PAYOUT_CLASSIFICATION_NOTE = 'GoPay: výplata vyúčtování ';

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
          LEFT JOIN chart_of_accounts ga ON ga.id=gs.gopay_account_id AND ga.supplier_id=gs.supplier_id
          LEFT JOIN chart_of_accounts ra ON ra.id=gs.receivable_account_id AND ra.supplier_id=gs.supplier_id
          LEFT JOIN chart_of_accounts fa ON fa.id=gs.fee_account_id AND fa.supplier_id=gs.supplier_id
          LEFT JOIN chart_of_accounts ca ON ca.id=gs.clearing_account_id AND ca.supplier_id=gs.supplier_id
          LEFT JOIN chart_of_accounts ba ON ba.id=gs.destination_bank_account_id AND ba.supplier_id=gs.supplier_id
              WHERE gs.supplier_id=? AND gs.currency=?'
        );
        $stmt->execute([$supplierId, $currency]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $doubleEntry = $this->isDoubleEntry($supplierId);
        // Podvojné účetnictví účtuje do všech pěti účtů; daňové evidenci stačí výplatní účet.
        if ($row !== null && $doubleEntry && !$this->hasAllAccounts($row)) {
            $row = null;
        }
        if (!$doubleEntry) {
            return [
                'configured' => $row !== null,
                'mode' => 'tax_evidence',
                'settings' => $row === null ? $this->defaultSettings($currency) : $this->normalizeSettings($row),
                'account_options' => [],
            ];
        }

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
            'mode' => 'double_entry',
            'settings' => $row === null ? $this->defaultSettings($currency) : $this->normalizeSettings($row),
            'account_options' => $options,
        ];
    }

    /** @return array<string,mixed> */
    private function defaultSettings(string $currency): array
    {
        return [
            'currency' => $currency,
            'gopay_account_id' => null,
            'receivable_account_id' => null,
            'fee_account_id' => null,
            'clearing_account_id' => null,
            'destination_bank_account_id' => null,
            'payout_account_number' => '115-1391640287',
            'payout_bank_code' => '0100',
            'payout_date_tolerance_days' => 3,
        ];
    }

    /** @param array<string,mixed> $row */
    private function hasAllAccounts(array $row): bool
    {
        foreach (['gopay_account_id', 'receivable_account_id', 'fee_account_id', 'clearing_account_id', 'destination_bank_account_id'] as $field) {
            if (($row[$field] ?? null) === null) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function saveSettings(int $supplierId, array $input, ?int $userId): array
    {
        $this->assertSupportedMode($supplierId);
        if (!$this->isDoubleEntry($supplierId)) {
            return $this->saveTaxEvidenceSettings($supplierId, $input, $userId);
        }
        $currency = $this->currency((string) ($input['currency'] ?? 'CZK'));
        // Chybějící klíč = ponechat uloženou hodnotu (částečná aktualizace); validuje se sloučený stav.
        $stmt = $this->db->pdo()->prepare('SELECT * FROM gopay_settings WHERE supplier_id=? AND currency=?');
        $stmt->execute([$supplierId, $currency]);
        $stored = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $input += array_intersect_key($stored, array_flip([
            'gopay_account_id', 'receivable_account_id', 'fee_account_id', 'clearing_account_id',
            'destination_bank_account_id', 'payout_account_number', 'payout_bank_code', 'payout_date_tolerance_days',
        ]));
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

        $accounts = $this->poster->accountsById($supplierId, array_values($ids));
        $this->assertAccount($accounts, $ids['gopay_account_id'], '221', null, 'gopay_account_id');
        $this->assertAccount($accounts, $ids['destination_bank_account_id'], '221', null, 'destination_bank_account_id');
        $this->assertAccount($accounts, $ids['receivable_account_id'], '311', null, 'receivable_account_id');
        $this->assertAccount($accounts, $ids['clearing_account_id'], '261', null, 'clearing_account_id');
        $this->assertAccount($accounts, $ids['fee_account_id'], null, 'expense', 'fee_account_id');

        [$accountNumber, $bankCode, $tolerance] = $this->payoutFields($input);

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

    /**
     * Daňová evidence: GoPay se neúčtuje do účtů, nastavení nese jen výplatní účet a
     * toleranci data. Účty uložené dřív (firma vedla podvojné účetnictví) zůstávají.
     *
     * @param array<string,mixed> $input @return array<string,mixed>
     */
    private function saveTaxEvidenceSettings(int $supplierId, array $input, ?int $userId): array
    {
        $currency = $this->currency((string) ($input['currency'] ?? 'CZK'));
        $stmt = $this->db->pdo()->prepare('SELECT * FROM gopay_settings WHERE supplier_id=? AND currency=?');
        $stmt->execute([$supplierId, $currency]);
        $stored = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $input += array_intersect_key($stored, array_flip([
            'payout_account_number', 'payout_bank_code', 'payout_date_tolerance_days',
        ]));
        [$accountNumber, $bankCode, $tolerance] = $this->payoutFields($input);

        $this->db->pdo()->prepare(
            'INSERT INTO gopay_settings
                (supplier_id,currency,payout_account_number,payout_bank_code,payout_date_tolerance_days,updated_by)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                payout_account_number=VALUES(payout_account_number),
                payout_bank_code=VALUES(payout_bank_code),
                payout_date_tolerance_days=VALUES(payout_date_tolerance_days),
                updated_by=VALUES(updated_by)'
        )->execute([$supplierId, $currency, $accountNumber, $bankCode, $tolerance, $userId]);

        return $this->settings($supplierId, $currency);
    }

    /** @param array<string,mixed> $input @return array{string,string,int} */
    private function payoutFields(array $input): array
    {
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
        return [$accountNumber, $bankCode, $tolerance];
    }

    /** @return list<array<string,mixed>> */
    public function listClearings(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id,clearing_id,account_name,currency,variable_symbol,cleared_from,cleared_to,
                    performed_on,amount_gross,amount_fee,amount_storno,amount_storno_fee,
                    amount_transfer,amount_sent,file_name,status,movement_count,posted_count,
                    issue_count,payout_match_transaction_id,bank_transaction_id,imported_at,processed_at,
                    pdf_name,pdf_size_bytes,pdf_uploaded_at,
                    (pdf_content IS NOT NULL AND OCTET_LENGTH(pdf_content)>0) has_pdf
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

    /** @return array{content:string,file_name:string,file_format:string,content_type:string} */
    public function download(int $supplierId, int $clearingId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT file_content,file_name FROM gopay_clearings WHERE id=? AND supplier_id=?');
        $stmt->execute([$clearingId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new GoPayException('not_found', 'GoPay vyúčtování nebylo nalezeno.', 404);
        }
        $format = self::fileFormat((string) $row['file_name']);
        return [
            'content' => (string) $row['file_content'],
            'file_name' => (string) $row['file_name'],
            'file_format' => $format,
            'content_type' => match ($format) {
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'xls' => 'application/vnd.ms-excel',
                default => 'application/xml; charset=UTF-8',
            },
        ];
    }

    /** Formát původního souboru podle přípony uloženého názvu: xml (Clearing), xlsx/xls (výpis). */
    public static function fileFormat(string $fileName): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return in_array($extension, ['xlsx', 'xls'], true) ? $extension : 'xml';
    }

    /** @return array{content:string,file_name:string} */
    public function downloadPdf(int $supplierId, int $clearingId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pdf_content,pdf_name FROM gopay_clearings WHERE id=? AND supplier_id=?'
        );
        $stmt->execute([$clearingId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !$this->hasContent($row['pdf_content'] ?? null)) {
            throw new GoPayException('pdf_not_found', 'PDF vyúčtování nebylo nalezeno.', 404);
        }
        return [
            'content' => (string) $row['pdf_content'],
            'file_name' => (string) ($row['pdf_name'] ?: 'GoPay-clearing.pdf'),
        ];
    }

    /** @return array<string,mixed> */
    public function uploadPdf(int $supplierId, int $clearingId, string $fileName, string $content): array
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE gopay_clearings
                SET pdf_content=?,pdf_name=?,pdf_hash=?,pdf_size_bytes=?,pdf_uploaded_at=NOW()
              WHERE id=? AND supplier_id=?'
        );
        $stmt->execute([
            $content,
            $this->safePdfFileName($fileName),
            hash('sha256', $content),
            strlen($content),
            $clearingId,
            $supplierId,
        ]);
        if ($stmt->rowCount() === 0) {
            $this->clearingRow($supplierId, $clearingId);
        }
        return $this->detail($supplierId, $clearingId);
    }

    /** @return array<string,mixed> */
    public function deletePdf(int $supplierId, int $clearingId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE gopay_clearings
                SET pdf_content=NULL,pdf_name=NULL,pdf_hash=NULL,pdf_size_bytes=NULL,pdf_uploaded_at=NULL
              WHERE id=? AND supplier_id=?'
        );
        $stmt->execute([$clearingId, $supplierId]);
        if ($stmt->rowCount() === 0) {
            $this->clearingRow($supplierId, $clearingId);
        }
        return $this->detail($supplierId, $clearingId);
    }

    /** @return array{deleted:bool,deleted_entry_ids:list<int>,preserved_bank_entry_id:int|null} */
    public function delete(int $supplierId, int $clearingId, ?int $userId): array
    {
        $this->assertClearingYearsOpen($supplierId, $clearingId);
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
                'SELECT DISTINCT je.id,je.source_type,je.source_id
                   FROM journal_entries je
                  WHERE je.supplier_id=?
                    AND ((je.source_type="gopay" AND je.source_id IN
                          (SELECT id FROM gopay_movements
                            WHERE clearing_id=? AND supplier_id=? AND origin="clearing"))
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
            $this->poster->assertEntriesRemovable(
                $supplierId,
                array_map(static fn (array $entry): int => (int) $entry['id'], $entryRows),
                'Vyúčtování',
            );

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
                $classification = $this->classifications->find($supplierId, 'bank', $transactionId);
                if ($classification !== null
                    && str_starts_with((string) ($classification['note'] ?? ''), self::PAYOUT_CLASSIFICATION_NOTE)) {
                    $this->classifications->delete($supplierId, 'bank', $transactionId, $userId);
                }
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

            // Smazání importu neruší obchodní fakt platby ani vratky. Stejně jako u úhrady
            // faktury se odstraňuje účetní import a vazby, nikoli platební stav dokladu.
            // Pohyb založený úhradou faktury patří úhradě: vrací se mezi čekající i se
            // zápisem ke dni platby a příští import vyúčtování ho převezme znovu.
            $this->pending->releaseFromClearing($supplierId, $clearingId);
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

    /**
     * @param array{file_name:string,content:string}|null $pdf
     * @return array{duplicate:bool,clearing:array<string,mixed>}
     */
    public function import(int $supplierId, ?int $userId, string $fileName, string $xml, ?array $pdf = null): array
    {
        $this->assertSupportedMode($supplierId);
        $statement = GoPayStatementXlsxParser::isSpreadsheet($xml);
        $parsed = $statement ? $this->statementParser->parse($xml) : $this->parser->parse($xml);
        $this->requireSettings($supplierId, $parsed['currency']);
        $this->assertTaxEvidenceYearsOpen($supplierId, array_merge(
            [(string) $parsed['performed_on']],
            array_map(static fn (array $m): string => (string) $m['performed_on'], $parsed['movements']),
        ));
        // Výpis stažený znovu má jiné bajty (datum vytvoření, zip), ale stejný obsah.
        $hash = $statement
            ? hash('sha256', json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
            : hash('sha256', $xml);
        $fileName = $this->safeFileName($fileName, $parsed['file_format'] ?? 'xml');

        $existing = $this->findExistingClearing($supplierId, $parsed['clearing_id'], $hash);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['file_hash'], $hash)
                || (string) $existing['clearing_id'] !== $parsed['clearing_id']) {
                throw new GoPayException('clearing_conflict', 'Stejný clearing ID nebo obsah už existuje s jinými údaji.', 409);
            }
            $id = (int) $existing['id'];
            if ($pdf !== null) {
                $this->uploadPdf($supplierId, $id, $pdf['file_name'], $pdf['content']);
            }
            $this->process($supplierId, $id, $userId);
            return ['duplicate' => true, 'clearing' => $this->detail($supplierId, $id)];
        }

        $movements = $this->withoutRecordedMovements($supplierId, $parsed['movements']);
        if ($parsed['movements'] !== [] && $movements === []) {
            throw new GoPayException(
                'already_recorded',
                'Všechny pohyby souboru už jsou evidované v dříve načteném vyúčtování nebo výpisu GoPay.',
                409,
            );
        }
        if ($statement) {
            $parsed = GoPayStatementXlsxParser::summarize(
                $parsed['clearing_id'], $parsed['cleared_from'], $parsed['cleared_to'], $movements,
            ) + $parsed;
        }

        $pdo = $this->db->pdo();
        $ownTx = $this->beginUnit($pdo, 'gopay_import');
        try {
            $pdo->prepare(
                'INSERT INTO gopay_clearings
                    (supplier_id,clearing_id,account_name,currency,variable_symbol,cleared_from,cleared_to,
                     performed_on,amount_gross,amount_credit_note,amount_fee,amount_fee_external,
                     amount_storno,amount_storno_fee,amount_transfer,amount_sent,file_name,file_hash,
                     file_content,pdf_content,pdf_name,pdf_hash,pdf_size_bytes,pdf_uploaded_at,
                     movement_count,imported_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,IF(? IS NULL,NULL,NOW()),?,?)'
            )->execute([
                $supplierId, $parsed['clearing_id'], $parsed['account_name'], $parsed['currency'],
                $parsed['variable_symbol'], $parsed['cleared_from'], $parsed['cleared_to'], $parsed['performed_on'],
                $parsed['amount_gross'], $parsed['amount_credit_note'], $parsed['amount_fee'],
                $parsed['amount_fee_external'], $parsed['amount_storno'], $parsed['amount_storno_fee'],
                $parsed['amount_transfer'], $parsed['amount_sent'], $fileName, $hash, $xml,
                $pdf['content'] ?? null,
                $pdf !== null ? $this->safePdfFileName($pdf['file_name']) : null,
                $pdf !== null ? hash('sha256', $pdf['content']) : null,
                $pdf !== null ? strlen($pdf['content']) : null,
                $pdf['content'] ?? null,
                count($movements), $userId,
            ]);
            $clearingPk = (int) $pdo->lastInsertId();
            $insertMovement = $pdo->prepare(
                'INSERT INTO gopay_movements
                    (supplier_id,clearing_id,external_id,movement_type,performed_on,amount,order_id,
                     payment_session_id,account_movement_id,payment_channel,counterparty_name)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            foreach ($movements as $movement) {
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
                    if ($pdf !== null) {
                        $this->uploadPdf($supplierId, $clearingPk, $pdf['file_name'], $pdf['content']);
                    }
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
        $this->assertSupportedMode($supplierId);
        $clearing = $this->clearingRow($supplierId, $clearingId);
        $this->requireSettings($supplierId, (string) $clearing['currency']);
        $this->assertClearingYearsOpen($supplierId, $clearingId);
        $this->db->pdo()->prepare('UPDATE gopay_clearings SET status="processing" WHERE id=? AND supplier_id=?')
            ->execute([$clearingId, $supplierId]);

        $this->pending->adoptIntoClearing($supplierId, $clearingId);
        $ids = $this->db->pdo()->prepare('SELECT id FROM gopay_movements WHERE clearing_id=? AND supplier_id=? ORDER BY id');
        $ids->execute([$clearingId, $supplierId]);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $movementId) {
            $this->poster->post($supplierId, (int) $movementId, $userId);
        }
        $this->reconcileCreditNoteStatuses($supplierId, $clearingId, $userId);
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
        $this->assertSupportedMode($supplierId);
        $this->assertClearingYearsOpen($supplierId, $clearingId);
        $transaction = $this->payoutTransaction($supplierId, $transactionId);
        $this->assertTaxEvidenceYearsOpen($supplierId, [(string) $transaction['posted_at']]);
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
            && ($row['bank_journal_entry_id'] !== null || !$this->isDoubleEntry($supplierId));
    }

    /** Jen dobropisy, stejně jako GoPayMovementPoster::matchCreditNote (tam je důvod). */
    private function reconcileCreditNoteStatuses(int $supplierId, int $clearingId, ?int $userId): void
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT gm.credit_note_id, MAX(gm.performed_on) refunded_on
               FROM gopay_movements gm
               JOIN invoices i ON i.id = gm.credit_note_id AND i.supplier_id = gm.supplier_id
                AND i.invoice_type = 'credit_note' AND i.amount_to_pay <= 0
                AND i.status IN ('issued', 'sent', 'reminded', 'paid')
              WHERE gm.supplier_id = ? AND gm.clearing_id = ? AND gm.movement_type = 'storno'
                AND gm.status = 'posted' AND gm.credit_note_id IS NOT NULL
              GROUP BY gm.credit_note_id"
        );
        $stmt->execute([$supplierId, $clearingId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $creditNoteId = (int) $row['credit_note_id'];
            $refundedOn = (string) $row['refunded_on'];
            $changed = $this->invoicePayments->markCreditNoteRefunded(
                $creditNoteId,
                $supplierId,
                $refundedOn,
            );
            if ($changed) {
                $this->activity->log('invoice.paid', $userId, 'invoice', $creditNoteId, [
                    'paid_at' => $refundedOn,
                    'source' => 'gopay',
                    'clearing_id' => $clearingId,
                ], supplierId: $supplierId);
            }
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
              LEFT JOIN chart_of_accounts ba ON ba.id=gs.destination_bank_account_id AND ba.supplier_id=gs.supplier_id
              LEFT JOIN chart_of_accounts ca ON ca.id=gs.clearing_account_id AND ca.supplier_id=gs.supplier_id
                  WHERE gc.id=? AND gc.supplier_id=? FOR UPDATE'
            );
            $stmt->execute([$clearingId, $supplierId]);
            $clearing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($clearing)) {
                throw new GoPayException('not_found', 'GoPay vyúčtování nebylo nalezeno.', 404);
            }
            if ((float) $clearing['amount_sent'] <= 0.0 || !$this->ownsPayout($supplierId, $clearingId)) {
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
                  WHERE bs.supplier_id=? AND bt.source="statement" AND bs.source IN ' . \MyInvoice\Service\Bank\BankStatementSource::sqlList() . '
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
            if (!$this->isDoubleEntry($supplierId)) {
                // Daňová evidence nemá zápis: vazba výplaty na bankovní pohyb stačí, peněžní
                // deník podle ní pohyb zařadí jako převod mezi vlastními prostředky.
                $entryId = null;
                $entryOwned = false;
                $this->classifications->upsert(
                    $supplierId, 'bank', $txId, 'transfer',
                    self::PAYOUT_CLASSIFICATION_NOTE . (string) $clearing['clearing_id'], $userId,
                );
            } elseif ($existing !== null && ($existing['reversed_by'] ?? null) === null) {
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
            || ($source === 'statement' && \MyInvoice\Service\Bank\BankStatementSource::isStatement($statementSource));
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
                AND EXISTS(SELECT 1 FROM gopay_movements gm
                            WHERE gm.clearing_id=gc.id AND gm.supplier_id=gc.supplier_id
                              AND gm.movement_type="payout")
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
        if (!is_array($row) || ($this->isDoubleEntry($supplierId) && !$this->hasAllAccounts($row))) {
            throw new GoPayException('settings_missing', 'Před importem nastav účty modulu GoPay.', 409);
        }
        return $row;
    }

    /** GoPay vede podvojné účetnictví (zápisy) i daňová evidence (peněžní deník). */
    private function assertSupportedMode(int $supplierId): void
    {
        $mode = $this->accountingMode($supplierId);
        if ($mode !== 'double_entry' && $mode !== 'tax_evidence') {
            throw new GoPayException('not_double_entry', 'GoPay vyžaduje podvojné účetnictví nebo daňovou evidenci.', 409);
        }
    }

    private function isDoubleEntry(int $supplierId): bool
    {
        return $this->accountingMode($supplierId) === 'double_entry';
    }

    private function accountingMode(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id=?');
        $stmt->execute([$supplierId]);
        $mode = $stmt->fetchColumn();
        return is_string($mode) ? $mode : null;
    }

    /**
     * Daňová evidence: pohyby vyúčtování vstupují do peněžního deníku (poplatky, vratky,
     * výplata), takže je v roce s dokončenou roční uzávěrkou nejde načíst, přepracovat
     * ani smazat. Podvojné účetnictví hlídá období přes PostingService.
     *
     * @param list<string> $dates
     */
    private function assertTaxEvidenceYearsOpen(int $supplierId, array $dates): void
    {
        if ($this->isDoubleEntry($supplierId)) {
            return;
        }
        $years = [];
        foreach ($dates as $date) {
            if (preg_match('/^(\d{4})-\d{2}-\d{2}/', $date, $m) === 1) {
                $years[(int) $m[1]] = true;
            }
        }
        ksort($years);
        foreach (array_keys($years) as $year) {
            if (TaxEvidenceYearLock::isFinal($this->db, $supplierId, $year)) {
                throw new GoPayException(TaxEvidenceYearLock::ERROR_CODE, TaxEvidenceYearLock::message($year), 409);
            }
        }
    }

    private function assertClearingYearsOpen(int $supplierId, int $clearingId): void
    {
        if ($this->isDoubleEntry($supplierId)) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT gc.performed_on FROM gopay_clearings gc WHERE gc.id=? AND gc.supplier_id=?
             UNION ALL
             SELECT gm.performed_on FROM gopay_movements gm WHERE gm.clearing_id=? AND gm.supplier_id=?
             UNION ALL
             SELECT bt.posted_at FROM gopay_clearings gc
               JOIN bank_transactions bt ON bt.id=gc.bank_transaction_id
              WHERE gc.id=? AND gc.supplier_id=?'
        );
        $stmt->execute([$clearingId, $supplierId, $clearingId, $supplierId, $clearingId, $supplierId]);
        $this->assertTaxEvidenceYearsOpen($supplierId, array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
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

    /**
     * Výplatu clearingu páruje s bankou jen záznam, který drží její pohyb. Výplatu,
     * kterou už dříve přinesl jiný soubor (XML clearingu i výpis ji nesou pod stejným
     * identifikátorem), import do nového záznamu nezaloží.
     */
    private function ownsPayout(int $supplierId, int $clearingId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM gopay_movements WHERE clearing_id=? AND supplier_id=? AND movement_type="payout" LIMIT 1'
        );
        $stmt->execute([$clearingId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Pohyby, které už eviduje dříve načtené vyúčtování nebo výpis, se znovu nezakládají.
     * XML clearingu a výpis účtu se překrývají: výpis nemá ID pohybu ani GoPay ID
     * platby, proto se kromě identifikátoru a GoPay ID porovnává typ, číslo objednávky,
     * částka a datum (±3 dny). Každý evidovaný pohyb vyřadí nejvýše jeden nový.
     *
     * @param list<array<string,?string>> $movements
     * @return list<array<string,?string>>
     */
    private function withoutRecordedMovements(int $supplierId, array $movements): array
    {
        if ($movements === []) {
            return [];
        }
        $pdo = $this->db->pdo();
        $externalIds = array_values(array_unique(array_map(static fn (array $m): string => (string) $m['external_id'], $movements)));
        $known = $pdo->prepare(
            'SELECT external_id FROM gopay_movements
              WHERE supplier_id=? AND external_id IN (' . implode(',', array_fill(0, count($externalIds), '?')) . ')'
        );
        $known->execute(array_merge([$supplierId], $externalIds));
        $knownIds = array_fill_keys($known->fetchAll(PDO::FETCH_COLUMN), true);

        $dates = array_map(static fn (array $m): string => (string) $m['performed_on'], $movements);
        $recorded = $pdo->prepare(
            'SELECT id,movement_type,performed_on,amount,order_id,payment_session_id
               FROM gopay_movements
              WHERE supplier_id=? AND clearing_id IS NOT NULL
                AND movement_type IN ("credit","storno","storno_fee")
                AND performed_on BETWEEN DATE_SUB(?,INTERVAL 3 DAY) AND DATE_ADD(?,INTERVAL 3 DAY)
              ORDER BY id'
        );
        $recorded->execute([$supplierId, min($dates), max($dates)]);
        $candidates = $recorded->fetchAll(PDO::FETCH_ASSOC);
        $used = [];

        $out = [];
        foreach ($movements as $movement) {
            if (isset($knownIds[(string) $movement['external_id']])) {
                continue;
            }
            $match = null;
            foreach ($candidates as $index => $candidate) {
                if (isset($used[$index]) || $candidate['movement_type'] !== $movement['movement_type']) {
                    continue;
                }
                $session = (string) ($movement['payment_session_id'] ?? '');
                if ($session !== '' && $session === (string) ($candidate['payment_session_id'] ?? '')) {
                    $match = $index;
                    break;
                }
                $order = trim((string) ($movement['order_id'] ?? ''));
                $days = abs((int) (new \DateTimeImmutable((string) $candidate['performed_on']))
                    ->diff(new \DateTimeImmutable((string) $movement['performed_on']))->format('%r%a'));
                if ($order !== '' && strcasecmp($order, trim((string) ($candidate['order_id'] ?? ''))) === 0
                    && number_format((float) $candidate['amount'], 2, '.', '') === number_format((float) $movement['amount'], 2, '.', '')
                    && $days <= 3) {
                    $match = $index;
                    break;
                }
            }
            if ($match !== null) {
                $used[$match] = true;
                continue;
            }
            $out[] = $movement;
        }
        return $out;
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
        foreach (['id', 'movement_count', 'posted_count', 'issue_count', 'payout_match_transaction_id', 'bank_transaction_id', 'bank_journal_entry_id', 'imported_by', 'pdf_size_bytes'] as $field) {
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
        if (array_key_exists('file_name', $row)) {
            $row['file_format'] = self::fileFormat((string) $row['file_name']);
        }
        $row['has_pdf'] = array_key_exists('has_pdf', $row)
            ? (bool) $row['has_pdf']
            : $this->hasContent($row['pdf_content'] ?? null);
        unset($row['file_content'], $row['pdf_content'], $row['pdf_hash']);
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

    private function safeFileName(string $fileName, string $format): string
    {
        $parts = preg_split('~[\\\\/]~', $fileName) ?: [];
        $name = trim((string) end($parts));
        if ($name === '' || !str_ends_with(strtolower($name), '.' . $format)) {
            $name = $format === 'xml' ? 'GoPay-clearing.xml' : 'GoPay-vypis.' . $format;
        }
        return mb_substr($name, 0, 255);
    }

    private function safePdfFileName(string $fileName): string
    {
        $parts = preg_split('~[\\\\/]~', $fileName) ?: [];
        $name = trim((string) end($parts));
        if ($name === '' || !str_ends_with(strtolower($name), '.pdf')) {
            $name = 'GoPay-clearing.pdf';
        }
        return mb_substr($name, 0, 255);
    }

    private function hasContent(mixed $content): bool
    {
        return is_string($content) && $content !== '';
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
}
