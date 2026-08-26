<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Match;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\ProformaPaymentDocuments;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use PDO;

final class MatchSuggestionService
{
    private const ALLOWED_REASONS = [
        'no_vs', 'no_invoice_with_vs', 'currency_mismatch', 'amount_mismatch', 'ambiguous_vs',
        'no_purchase_with_vs', 'currency_mismatch_purchase', 'amount_mismatch_purchase',
        'ambiguous_vs_purchase', 'no_fuzzy_match', 'ambiguous_fuzzy_match',
        'fuzzy_match_requires_review', 'no_amount_date_match', 'ambiguous_amount_date_match',
        'amount_date_requires_review', 'already_paid_verify',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly MatchCandidateProvider $provider,
        private readonly MatchScorer $scorer,
        private readonly CounterpartyMapService $counterpartyMap,
        private readonly InvoicePaymentService $payments,
        private readonly FinalFromProformaCreator $finalCreator,
        private readonly PaymentTaxDocumentCreator $taxDocCreator,
    ) {}

    /** @param array<string,mixed> $matchResult @return array<string,mixed> */
    public function afterMatch(int $transactionId, array $matchResult): array
    {
        if (in_array((string) ($matchResult['reason'] ?? ''), ['transaction_not_found', 'unknown_supplier_for_account'], true)) {
            return $matchResult;
        }
        $tx = $this->loadTransaction($transactionId);
        if ($tx === null || (int) $tx['supplier_id'] <= 0) return $matchResult;
        $supplierId = (int) $tx['supplier_id'];
        if (($matchResult['status'] ?? 'unmatched') !== 'unmatched') {
            $this->recordTargets($supplierId, $tx, $matchResult, false);
            $this->db->pdo()->prepare(
                "UPDATE bank_match_suggestions SET status = 'superseded', updated_at = NOW()
                  WHERE supplier_id = ? AND bank_transaction_id = ? AND status = 'pending'"
            )->execute([$supplierId, $transactionId]);
            return $matchResult;
        }
        if (!in_array((string) ($matchResult['reason'] ?? ''), self::ALLOWED_REASONS, true)) return $matchResult;
        $candidates = $this->provider->candidatesFor($supplierId, $tx);
        $decision = $this->scorer->decide($candidates);
        if ($decision === 'none') return $matchResult;
        $top = $candidates[0];
        $margin = count($candidates) > 1 ? round((float) $top['score'] - (float) $candidates[1]['score'], 3) : null;
        $kind = $this->kind($top);
        if ($decision === 'auto') {
            $pdo = $this->db->pdo();
            $ownTx = !$pdo->inTransaction();
            $savepoint = 'match_v2_auto_' . $transactionId;
            if ($ownTx) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('SAVEPOINT ' . $savepoint);
            }
            try {
                $lock = $pdo->prepare('SELECT match_status FROM bank_transactions WHERE id = ? FOR UPDATE');
                $lock->execute([$transactionId]);
                if ($lock->fetchColumn() !== 'unmatched') {
                    if ($ownTx) {
                        $pdo->commit();
                    } else {
                        $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    }
                    return $matchResult;
                }
                $pdo->prepare(
                    "UPDATE bank_match_suggestions SET status = 'superseded', updated_at = NOW()
                      WHERE supplier_id = ? AND bank_transaction_id = ? AND status = 'pending'"
                )->execute([$supplierId, $transactionId]);
                $applied = $this->applyCandidate($supplierId, $tx, $top, null, false);
                $suggestionId = $this->insertSuggestion($supplierId, $transactionId, $kind,
                    (string) $matchResult['reason'], $candidates, $margin, 'auto_applied', 0);
                $this->audit($supplierId, $transactionId, 'auto', $top, $margin, $suggestionId, null);
                $this->recordTargets($supplierId, $tx, $applied, false);
                if ($ownTx) {
                    $pdo->commit();
                } else {
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
                return $applied + ['via' => 'match_v2'];
            } catch (\Throwable $e) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                } elseif ($pdo->inTransaction()) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
                throw $e;
            }
        }
        $suggestionId = $this->upsertPending($supplierId, $transactionId, $kind,
            (string) $matchResult['reason'], $candidates, $margin);
        $this->audit($supplierId, $transactionId, 'suggest', $top, $margin, $suggestionId, null);
        return $matchResult + ['match_suggestion_id' => $suggestionId];
    }

    /** @return list<array<string,mixed>> */
    public function listForStatement(int $statementId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.bank_transaction_id, s.kind, s.reason, s.candidates_json,
                    s.top_score, s.margin, s.deterministic_core, s.status, s.created_at
               FROM bank_match_suggestions s
               JOIN bank_transactions bt ON bt.id = s.bank_transaction_id
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bs.id = ? AND s.supplier_id = ? AND s.status = 'pending'
                AND " . \MyInvoice\Repository\BankStatementOwnershipResolver::sqlForColumn('s.supplier_id') . "
              ORDER BY s.created_at, s.id"
        );
        $stmt->execute([$statementId, $supplierId]);
        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'], 'bank_transaction_id' => (int) $row['bank_transaction_id'],
                'kind' => (string) $row['kind'], 'reason' => (string) $row['reason'],
                'top_score' => (float) $row['top_score'],
                'margin' => $row['margin'] === null ? null : (float) $row['margin'],
                'deterministic_core' => (bool) $row['deterministic_core'], 'status' => (string) $row['status'],
                'candidates' => json_decode((string) $row['candidates_json'], true, 512, JSON_THROW_ON_ERROR),
                'created_at' => (string) $row['created_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed> */
    public function accept(int $suggestionId, int $supplierId, int $userId, int $candidateIndex): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM bank_match_suggestions WHERE id = ? AND supplier_id = ? FOR UPDATE');
            $stmt->execute([$suggestionId, $supplierId]);
            $suggestion = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($suggestion === false) throw new MatchSuggestionException('not_found', 'Návrh nebyl nalezen.', 404);
            if ((string) $suggestion['status'] !== 'pending') throw new MatchSuggestionException('already_reviewed', 'Návrh už byl vyřízen.', 409);
            $candidates = json_decode((string) $suggestion['candidates_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!isset($candidates[$candidateIndex]) || !is_array($candidates[$candidateIndex])) {
                throw new MatchSuggestionException('candidate_out_of_range', 'Vybraný kandidát neexistuje.', 422);
            }
            $tx = $this->loadTransaction((int) $suggestion['bank_transaction_id']);
            if ($tx === null || (int) $tx['supplier_id'] !== $supplierId) throw new MatchSuggestionException('not_found', 'Návrh nebyl nalezen.', 404);
            $txLock = $pdo->prepare('SELECT match_status FROM bank_transactions WHERE id = ? FOR UPDATE');
            $txLock->execute([(int) $suggestion['bank_transaction_id']]);
            if ($txLock->fetchColumn() !== 'unmatched') {
                $pdo->prepare("UPDATE bank_match_suggestions SET status = 'superseded', updated_at = NOW() WHERE id = ?")
                    ->execute([$suggestionId]);
                $pdo->commit();
                throw new MatchSuggestionException('already_reviewed', 'Transakce už byla spárována jiným způsobem.', 409);
            }
            $candidate = $candidates[$candidateIndex];
            $result = $this->applyCandidate($supplierId, $tx, $candidate, $userId, true);
            $pdo->prepare(
                "UPDATE bank_match_suggestions SET status = 'accepted', accepted_candidate = ?,
                        reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND supplier_id = ? AND status = 'pending'"
            )->execute([$candidateIndex, $userId ?: null, $suggestionId, $supplierId]);
            $margin = $suggestion['margin'] === null ? null : (float) $suggestion['margin'];
            $this->audit($supplierId, (int) $suggestion['bank_transaction_id'], 'accept', $candidate, $margin, $suggestionId, $userId);
            $fee = isset($candidate['fee_amount']) && $candidate['fee_amount'] !== null ? (float) $candidate['fee_amount'] : null;
            $feePct = $fee !== null && (float) ($candidate['display']['amount'] ?? 0) > 0
                ? $fee / (float) $candidate['display']['amount'] : null;
            $this->recordTargets($supplierId, $tx, $result, true, $feePct, $userId);
            if ($fee !== null) $this->createFeePostingSuggestion($supplierId, (int) $suggestion['bank_transaction_id'], $fee);
            $pdo->commit();
            $result['bank_transaction_id'] = (int) $suggestion['bank_transaction_id'];
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function reject(int $suggestionId, int $supplierId, int $userId, ?string $reason): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM bank_match_suggestions WHERE id = ? AND supplier_id = ? FOR UPDATE');
            $stmt->execute([$suggestionId, $supplierId]);
            $suggestion = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($suggestion === false) throw new MatchSuggestionException('not_found', 'Návrh nebyl nalezen.', 404);
            if ((string) $suggestion['status'] !== 'pending') throw new MatchSuggestionException('already_reviewed', 'Návrh už byl vyřízen.', 409);
            $pdo->prepare(
                "UPDATE bank_match_suggestions SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
                  WHERE id = ? AND supplier_id = ? AND status = 'pending'"
            )->execute([$userId ?: null, $suggestionId, $supplierId]);
            $candidates = json_decode((string) $suggestion['candidates_json'], true, 512, JSON_THROW_ON_ERROR);
            $top = is_array($candidates[0] ?? null) ? $candidates[0] : [];
            if ($reason !== null && trim($reason) !== '') $top['signals']['reject_reason'] = mb_substr(trim($reason), 0, 190);
            $this->audit($supplierId, (int) $suggestion['bank_transaction_id'], 'reject', $top,
                $suggestion['margin'] === null ? null : (float) $suggestion['margin'], $suggestionId, $userId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function recordManual(int $transactionId, int $supplierId, int $userId): void
    {
        $tx = $this->loadTransaction($transactionId);
        if ($tx === null || (int) $tx['supplier_id'] !== $supplierId) return;
        $targets = $this->currentTargets($transactionId);
        if (($targets['invoice_ids'] ?? []) === [] && ($targets['purchase_invoice_id'] ?? null) === null) return;
        $this->recordTargets($supplierId, $tx, $targets, true, null, $userId);
        $this->audit($supplierId, $transactionId, 'manual', $targets, null, null, $userId);
    }

    public function recordUnmatch(int $transactionId, int $supplierId, int $userId): void
    {
        $tx = $this->loadTransaction($transactionId);
        if ($tx === null || (int) $tx['supplier_id'] !== $supplierId) return;
        $targets = $this->currentTargets($transactionId);
        foreach ((array) ($targets['invoice_ids'] ?? []) as $invoiceId) {
            $client = $this->clientForInvoice($supplierId, (int) $invoiceId);
            if ($client > 0) $this->counterpartyMap->recordContradiction($supplierId,
                (string) ($tx['counterparty_account'] ?? ''), (string) ($tx['counterparty_bank'] ?? ''), 'incoming', $client);
        }
        if (($targets['purchase_invoice_id'] ?? null) !== null) {
            $client = $this->clientForPurchase($supplierId, (int) $targets['purchase_invoice_id']);
            if ($client > 0) $this->counterpartyMap->recordContradiction($supplierId,
                (string) ($tx['counterparty_account'] ?? ''), (string) ($tx['counterparty_bank'] ?? ''), 'outgoing', $client);
        }
        $this->db->pdo()->prepare(
            "UPDATE bank_match_audit SET reverted_at = NOW()
              WHERE supplier_id = ? AND bank_transaction_id = ? AND decision = 'auto' AND reverted_at IS NULL"
        )->execute([$supplierId, $transactionId]);
        $this->audit($supplierId, $transactionId, 'unmatch', $targets, null, null, $userId);
    }

    public function createFeePostingSuggestion(int $supplierId, int $transactionId, float $feeAmount): void
    {
        if ($feeAmount <= 0.0) return;
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO bank_posting_suggestions
                (supplier_id, bank_transaction_id, rule_id, source, debit_account_code,
                 credit_account_code, amount, description, status, note, confidence, operation_type)
             VALUES (?, ?, NULL, 'payment_match', '568', '311', ?, 'Stržený poplatek z úhrady',
                     'pending', 'fee_gap', 1.00, 'bank.payment.matched')
             ON DUPLICATE KEY UPDATE source = VALUES(source), debit_account_code = VALUES(debit_account_code),
                credit_account_code = VALUES(credit_account_code), amount = VALUES(amount),
                description = VALUES(description), note = VALUES(note), confidence = VALUES(confidence),
                operation_type = VALUES(operation_type)"
        );
        $stmt->execute([$supplierId, $transactionId, round($feeAmount, 2)]);
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $tx @return array<string,mixed> */
    private function applyCandidate(int $supplierId, array $tx, array $candidate, ?int $userId, bool $manual): array
    {
        $type = (string) ($candidate['type'] ?? '');
        $postedAt = (string) $tx['posted_at'];
        if ($type === 'purchase_invoice') {
            $id = (int) ($candidate['purchase_invoice_id'] ?? 0);
            $stmt = $this->db->pdo()->prepare(
                "SELECT id, status FROM purchase_invoices WHERE id = ? AND supplier_id = ? AND status IN ('received','booked','paid') FOR UPDATE"
            );
            $stmt->execute([$id, $supplierId]);
            $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($purchase === false) throw new MatchSuggestionException('not_found', 'Přijatá faktura nebyla nalezena.', 404);
            $dup = $this->db->pdo()->prepare('SELECT id FROM payment_matches WHERE bank_transaction_id = ? AND purchase_invoice_id = ?');
            $dup->execute([(int) $tx['id'], $id]);
            if ($dup->fetchColumn() === false) {
                if ((string) $purchase['status'] !== 'paid') {
                    $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ? AND supplier_id = ?")
                        ->execute([$postedAt, $id, $supplierId]);
                }
                $this->db->pdo()->prepare(
                    'INSERT INTO payment_matches
                        (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence, matched_by_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$supplierId, $tx['id'], $id, abs((float) $tx['amount']), $manual ? 'manual' : 'auto',
                    $manual ? null : (int) round((float) $candidate['score'] * 100), $userId ?: null]);
            }
            $this->markTransaction((int) $tx['id'], null, $manual ? 'manual' : 'auto_exact', $userId);
            $this->recountStatement((int) $tx['statement_id']);
            return ['status' => $manual ? 'manual' : 'auto_exact', 'matched' => true,
                'purchase_invoice_id' => $id, 'paid_at' => $postedAt];
        }
        $invoiceIds = $type === 'split'
            ? array_values(array_unique(array_map('intval', (array) ($candidate['invoice_ids'] ?? []))))
            : [(int) ($candidate['invoice_id'] ?? 0)];
        if ($invoiceIds === [] || min($invoiceIds) <= 0) throw new MatchSuggestionException('candidate_out_of_range', 'Kandidát nemá platný doklad.', 422);
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.client_id, i.invoice_type, i.status, i.amount_to_pay, i.paid_total,
                    i.exchange_rate, cur.code AS currency
               FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ? AND i.id IN ($placeholders)
                AND i.status IN ('issued','sent','reminded','paid')
                AND i.invoice_type IN ('invoice','proforma') FOR UPDATE"
        );
        $stmt->execute(array_merge([$supplierId], $invoiceIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) !== count($invoiceIds)) throw new MatchSuggestionException('not_found', 'Faktura nebyla nalezena.', 404);
        $byId = [];
        foreach ($rows as $row) $byId[(int) $row['id']] = $row;
        if ($type === 'split') {
            $clientIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['client_id'], $rows)));
            $txCurrency = strtoupper((string) $tx['currency']);
            $currentSum = 0.0;
            foreach ($rows as $row) {
                if (strtoupper((string) $row['currency']) !== $txCurrency) {
                    throw new MatchSuggestionException('candidate_stale', 'Měna faktur se od vytvoření návrhu změnila.', 409);
                }
                $currentSum += max(0.0, round((float) $row['amount_to_pay'] - (float) $row['paid_total'], 2));
            }
            $currentSum = round($currentSum, 2);
            $storedSum = round((float) ($candidate['display']['amount'] ?? 0.0), 2);
            $fee = isset($candidate['fee_amount']) && $candidate['fee_amount'] !== null
                ? round((float) $candidate['fee_amount'], 2) : null;
            $expected = round(abs((float) $tx['amount']) + ($fee ?? 0.0), 2);
            $matchTolerance = $fee === null ? 1.0 : 0.01;
            if (count($clientIds) !== 1 || abs($currentSum - $storedSum) > 0.01 || abs($currentSum - $expected) > $matchTolerance) {
                throw new MatchSuggestionException('candidate_stale', 'Zůstatky faktur se změnily; načtěte nové návrhy.', 409);
            }
        }
        $finalDraftIds = [];
        $taxDocumentIds = [];
        foreach ($invoiceIds as $invoiceId) {
            $row = $byId[$invoiceId];
            $dup = $this->db->pdo()->prepare('SELECT id FROM invoice_payments WHERE bank_transaction_id = ? AND invoice_id = ?');
            $dup->execute([(int) $tx['id'], $invoiceId]);
            if ($dup->fetchColumn() !== false) continue;
            $remaining = round((float) $row['amount_to_pay'] - (float) $row['paid_total'], 2);
            if ($remaining <= 0.0) throw new MatchSuggestionException('already_reviewed', 'Faktura už nemá neuhrazený zůstatek.', 409);
            $paymentAmount = $type === 'invoice'
                ? $this->transactionAmountInInvoiceCurrency($tx, $row, $remaining)
                : $remaining;
            $recorded = $this->payments->recordPayment($invoiceId, $paymentAmount, $postedAt, [
                'source' => 'bank', 'bank_transaction_id' => (int) $tx['id'],
                'variable_symbol' => $tx['variable_symbol'] ?? null, 'bank_reference' => $tx['bank_ref'] ?? null,
                'created_by' => $userId ?: null,
            ]);
            $followUp = ProformaPaymentDocuments::afterPayment(
                $this->finalCreator,
                $this->taxDocCreator,
                $invoiceId,
                (string) $row['invoice_type'],
                (bool) $recorded['became_paid'],
                isset($recorded['payment_id']) ? (int) $recorded['payment_id'] : null,
                $userId ?: 0,
                $postedAt,
                $this->db->pdo(),
            );
            if ($followUp['final_draft_id'] !== null) {
                $finalDraftIds[] = $followUp['final_draft_id'];
            }
            if ($followUp['tax_document_id'] !== null) {
                $taxDocumentIds[] = $followUp['tax_document_id'];
            }
        }
        $this->markTransaction((int) $tx['id'], $invoiceIds[0], $manual ? 'manual' : 'auto_exact', $userId);
        $this->recountStatement((int) $tx['statement_id']);
        $result = ['status' => $manual ? 'manual' : 'auto_exact', 'matched' => true, 'paid_at' => $postedAt];
        if (count($invoiceIds) === 1) $result['invoice_id'] = $invoiceIds[0];
        else { $result['split'] = true; $result['invoice_ids'] = $invoiceIds; }
        if ($finalDraftIds !== []) $result['final_draft_ids'] = $finalDraftIds;
        if ($taxDocumentIds !== []) $result['tax_document_ids'] = $taxDocumentIds;
        return $result;
    }

    /** @return array<string,mixed>|null */
    private function loadTransaction(int $transactionId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.*, bs.account_number AS recipient_account, bs.bank_code AS recipient_bank,
                    bs.supplier_id AS statement_supplier_id,
                    COALESCE(NULLIF(bt.currency,''), NULLIF(bs.currency,''), 'CZK') AS effective_currency
               FROM bank_transactions bt JOIN bank_statements bs ON bs.id = bt.statement_id WHERE bt.id = ?"
        );
        $stmt->execute([$transactionId]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tx === false) return null;

        // SEC-01: má-li výpis autoritativní supplier_id (migrace 1078/1079), je
        // rozhodující — hledání podle čísla účtu je jen legacy fallback. Bez toho
        // šlo accept() provést nad výpisem prokazatelně patřícím jiné firmě, pokud
        // si útočník zapsal její účet do currencies jako jediný kandidát.
        if ($tx['statement_supplier_id'] !== null) {
            $tx['supplier_id'] = (int) $tx['statement_supplier_id'];
            $tx['currency'] = (string) $tx['effective_currency'];
            return $tx;
        }

        $supplierIds = [];
        $currencies = $this->db->pdo()->query('SELECT supplier_id, account_number, iban, bank_code FROM currencies WHERE account_number IS NOT NULL OR iban IS NOT NULL');
        foreach ($currencies->fetchAll(PDO::FETCH_ASSOC) ?: [] as $currency) {
            $candidateBank = AccountNumberNormalizer::canonicalBankCode((string) ($currency['bank_code'] ?? ''), (string) ($currency['iban'] ?? ''));
            $statementBank = AccountNumberNormalizer::canonicalBankCode((string) ($tx['recipient_bank'] ?? ''));
            if ($candidateBank !== null && $statementBank !== null && $candidateBank !== $statementBank) continue;
            if (AccountNumberNormalizer::matchesAny((string) $tx['recipient_account'], $currency['account_number'] ?? null, $currency['iban'] ?? null)) {
                $supplierIds[] = (int) $currency['supplier_id'];
            }
        }
        $supplierIds = array_values(array_unique($supplierIds));
        $supplierId = count($supplierIds) === 1 ? $supplierIds[0] : 0;
        $tx['supplier_id'] = $supplierId;
        $tx['currency'] = (string) $tx['effective_currency'];
        return $tx;
    }

    /** @param array<string,mixed> $candidate */
    private function upsertPending(int $supplierId, int $transactionId, string $kind, string $reason, array $candidates, ?float $margin): int
    {
        $top = $candidates[0];
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO bank_match_suggestions
                (supplier_id, bank_transaction_id, kind, reason, candidates_json, top_score, margin, deterministic_core, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), kind = VALUES(kind), reason = VALUES(reason),
                candidates_json = VALUES(candidates_json), top_score = VALUES(top_score), margin = VALUES(margin),
                deterministic_core = VALUES(deterministic_core), updated_at = NOW()"
        );
        $stmt->execute([$supplierId, $transactionId, $kind, mb_substr($reason, 0, 40),
            json_encode($candidates, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (float) $top['score'], $margin,
            !empty($top['deterministic_core']) ? 1 : 0]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertSuggestion(int $supplierId, int $transactionId, string $kind, string $reason, array $candidates, ?float $margin, string $status, ?int $accepted): int
    {
        $top = $candidates[0];
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO bank_match_suggestions
                (supplier_id, bank_transaction_id, kind, reason, candidates_json, top_score, margin,
                 deterministic_core, status, accepted_candidate, reviewed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$supplierId, $transactionId, $kind, mb_substr($reason, 0, 40),
            json_encode($candidates, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $top['score'], $margin,
            !empty($top['deterministic_core']) ? 1 : 0, $status, $accepted]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $candidate */
    private function audit(int $supplierId, int $transactionId, string $decision, array $candidate, ?float $margin, ?int $suggestionId, ?int $userId): void
    {
        $invoiceIds = isset($candidate['invoice_ids']) && is_array($candidate['invoice_ids'])
            ? array_values(array_map('intval', $candidate['invoice_ids']))
            : (isset($candidate['invoice_id']) && (int) $candidate['invoice_id'] > 0 ? [(int) $candidate['invoice_id']] : null);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO bank_match_audit
                (supplier_id, bank_transaction_id, decision, kind, invoice_ids, purchase_invoice_id,
                 score, margin, deterministic_core, signals_json, suggestion_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $kind = $candidate === [] ? null : $this->kind($candidate);
        $stmt->execute([$supplierId, $transactionId, $decision, $kind,
            $invoiceIds === null ? null : json_encode($invoiceIds, JSON_THROW_ON_ERROR),
            isset($candidate['purchase_invoice_id']) && (int) $candidate['purchase_invoice_id'] > 0 ? (int) $candidate['purchase_invoice_id'] : null,
            isset($candidate['score']) ? (float) $candidate['score'] : null, $margin,
            array_key_exists('deterministic_core', $candidate) ? (!empty($candidate['deterministic_core']) ? 1 : 0) : null,
            isset($candidate['signals']) ? json_encode($candidate['signals'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
            $suggestionId, $userId ?: null]);
    }

    /** @param array<string,mixed> $tx @param array<string,mixed> $targets */
    private function recordTargets(int $supplierId, array $tx, array $targets, bool $manual, ?float $feePct = null, ?int $userId = null): void
    {
        $ids = isset($targets['invoice_ids']) ? (array) $targets['invoice_ids'] : [];
        if (isset($targets['invoice_id'])) $ids[] = (int) $targets['invoice_id'];
        foreach (array_unique(array_map('intval', $ids)) as $invoiceId) {
            $client = $this->clientForInvoice($supplierId, $invoiceId);
            if ($client > 0) $this->counterpartyMap->record($supplierId, (string) ($tx['counterparty_account'] ?? ''),
                (string) ($tx['counterparty_bank'] ?? ''), 'incoming', $client, $manual, $feePct, (int) $tx['id']);
        }
        if (isset($targets['purchase_invoice_id']) && (int) $targets['purchase_invoice_id'] > 0) {
            $client = $this->clientForPurchase($supplierId, (int) $targets['purchase_invoice_id']);
            if ($client > 0) $this->counterpartyMap->record($supplierId, (string) ($tx['counterparty_account'] ?? ''),
                (string) ($tx['counterparty_bank'] ?? ''), 'outgoing', $client, $manual, $feePct, (int) $tx['id']);
        }
    }

    /** @return array<string,mixed> */
    private function currentTargets(int $transactionId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT matched_invoice_id FROM bank_transactions WHERE id = ?');
        $stmt->execute([$transactionId]);
        $invoiceIds = [];
        $matched = $stmt->fetchColumn();
        if ($matched !== false && $matched !== null) $invoiceIds[] = (int) $matched;
        $p = $this->db->pdo()->prepare('SELECT invoice_id FROM invoice_payments WHERE bank_transaction_id = ?');
        $p->execute([$transactionId]);
        $invoiceIds = array_values(array_unique(array_merge($invoiceIds, array_map('intval', $p->fetchAll(PDO::FETCH_COLUMN) ?: []))));
        $purchase = $this->db->pdo()->prepare('SELECT purchase_invoice_id FROM payment_matches WHERE bank_transaction_id = ? AND purchase_invoice_id IS NOT NULL ORDER BY id LIMIT 1');
        $purchase->execute([$transactionId]);
        $purchaseId = $purchase->fetchColumn();
        return ['invoice_ids' => $invoiceIds, 'purchase_invoice_id' => $purchaseId === false ? null : (int) $purchaseId];
    }

    private function clientForInvoice(int $supplierId, int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT client_id FROM invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$invoiceId, $supplierId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function clientForPurchase(int $supplierId, int $purchaseId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT vendor_id FROM purchase_invoices WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$purchaseId, $supplierId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function markTransaction(int $transactionId, ?int $invoiceId, string $status, ?int $userId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE bank_transactions SET matched_invoice_id = ?, match_status = ?, matched_at = NOW(), matched_by = ? WHERE id = ?'
        )->execute([$invoiceId, $status, $userId ?: null, $transactionId]);
    }

    private function recountStatement(int $statementId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE bank_statements SET matched_count = (SELECT COUNT(*) FROM bank_transactions
              WHERE statement_id = ? AND match_status IN ('auto_exact','auto_partial','manual')) WHERE id = ?"
        )->execute([$statementId, $statementId]);
    }

    /** @param array<string,mixed> $tx @param array<string,mixed> $invoice */
    private function transactionAmountInInvoiceCurrency(array $tx, array $invoice, float $fallback): float
    {
        $txCurrency = strtoupper((string) $tx['currency']);
        $invoiceCurrency = strtoupper((string) $invoice['currency']);
        if ($txCurrency === $invoiceCurrency) return round(abs((float) $tx['amount']), 2);
        if ($txCurrency === 'CZK' && (float) $invoice['exchange_rate'] > 0) {
            return round(abs((float) $tx['amount']) / (float) $invoice['exchange_rate'], 2);
        }
        return $fallback;
    }

    /** @param array<string,mixed> $candidate */
    private function kind(array $candidate): string
    {
        $flags = (array) ($candidate['flags'] ?? []);
        foreach (['fee_gap', 'overpayment', 'vs_typo'] as $kind) if (in_array($kind, $flags, true)) return $kind;
        return ($candidate['type'] ?? '') === 'split' ? 'split' : 'single';
    }
}
