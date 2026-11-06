<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\AdvanceSettlementEvidenceReader;
use MyInvoice\Service\Bank\FxPaymentSettlement;
use MyInvoice\Support\Sql\CreditNoteRefundExpr;
use MyInvoice\Support\Sql\LinkedManualSettlementSql;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

final class FxSettlementEvidenceRepository
{
    public function __construct(private readonly Connection $db, private readonly SaldoRepository $saldo) {}

    public function read(int $supplierId, array $item, string $asOf, bool $currentRead = false): array
    {
        $pdo = $this->db->pdo();
        if ($currentRead || $pdo->inTransaction()) return $this->readSnapshot($supplierId, $item, $asOf, $currentRead);
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        try {
            return $this->readSnapshot($supplierId, $item, $asOf, false);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    private function readSnapshot(int $supplierId, array $item, string $asOf, bool $currentRead): array
    {
        if ($currentRead) {
            if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Current FX settlement evidence requires a transaction.');
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        }
        $type = (string) $item['doc_type'];
        if (!in_array($type, ['invoice', 'purchase_invoice'], true)) throw new \InvalidArgumentException('Invalid FX document type.');
        $id = (int) $item['doc_id'];
        $table = $type === 'invoice' ? 'invoices' : 'purchase_invoices';
        $document = $this->rows("SELECT d.*, c.code AS currency_code FROM {$table} d JOIN currencies c ON c.id=d.currency_id AND c.supplier_id=d.supplier_id WHERE d.supplier_id=? AND d.id=?", [$supplierId, $id], $currentRead)[0] ?? null;
        if ($document === null) return $this->incomplete('document_missing');
        $components = $this->saldo->settlementComponents($supplierId, $type, $id, (int) $item['account_id'], $asOf, $currentRead);
        if ($components === null) return $this->incomplete('document_ledger_missing');

        $members = $this->members($supplierId, $type, $id, $currentRead);
        $manual = $this->manualMembers($supplierId, $type, $id, (int) $item['account_id'], $asOf, $currentRead);
        $manualLifecycle = $this->manualLifecycle($supplierId, $type, $id, (int) $item['account_id'], $currentRead);
        $hasEvidence = $members !== [] || $manualLifecycle !== [];
        $sources = [];
        $nativeByBank = [];
        $legacyByBank = [];
        $findings = strtoupper((string) $item['currency_code']) === strtoupper((string) $document['currency_code']) ? [] : ['document_currency_conflict'];
        foreach ($members as &$member) {
            $bankId = (int) ($member['bank_transaction_id'] ?? 0);
            $cashId = (int) ($member['cash_document_id'] ?? 0);
            $sourceType = $bankId > 0 ? 'bank' : ($cashId > 0 ? 'cash' : ($member['journal_source_type'] ?? null));
            $sourceId = $bankId ?: ($cashId ?: (int) ($member['journal_source_id'] ?? 0));
            $source = null;
            if ($sourceType !== null) {
                $sourceKey = $sourceType . ':' . $sourceId;
                $sources[$sourceKey] ??= $this->postedSource($supplierId, $sourceType, $sourceId, $asOf, $currentRead);
                $source = $sources[$sourceKey];
            }
            $date = $source['effective_date'] ?? $member['effective_date'];
            $member['effective_date'] = $date;
            $member['included_as_of'] = $date !== null && substr((string) $date, 0, 10) <= $asOf;
            if ($member['included_as_of'] && $source !== null && $source['effective_date'] === null
                && (!isset($member['status']) || in_array($member['status'], ['confirmed', 'posted'], true))
                && array_any($source['entries'], static fn (array $entry): bool => $entry['posted_at'] !== null && $entry['reversed_on'] !== null && $entry['reversed_on'] <= $asOf)) {
                $findings[] = 'payment_posting_reversed';
            }
            if (array_key_exists('document_amount', $member)) {
                if ($member['included_as_of'] && $member['representation'] === 'payment_match'
                    && !in_array(strtoupper((string) $member['currency']), [strtoupper((string) $document['currency_code']), 'CZK'], true)
                    && !self::postedInDocumentCurrency($source, $item, (string) $document['currency_code'], $type, $member['document_amount'])) {
                    $findings[] = 'legacy_payment_currency_unproven';
                }
                $member['document_amount'] = JournalLineAmount::decimal($member['document_amount']);
                if (isset($member['status']) && !in_array($member['status'], ['confirmed', 'posted'], true)) {
                    $member['included_as_of'] = $member['included_as_of'] && $member['reversed_on'] !== null && $member['reversed_on'] > $asOf;
                }
                continue;
            }
            if ($member['representation'] === 'invoice_payment') {
                if ($member['included_as_of'] && strtoupper((string) $member['currency']) !== strtoupper((string) $document['currency_code'])) $findings[] = 'payment_currency_conflict';
                $member['document_amount'] = JournalLineAmount::decimal($member['amount']);
                if ($bankId > 0) $nativeByBank[$bankId][] = $member;
            } else {
                $currency = strtoupper((string) $member['currency']);
                $documentCurrency = strtoupper((string) $document['currency_code']);
                if ($currency !== $documentCurrency && $currency !== 'CZK') {
                    if ($member['included_as_of']) $findings[] = 'legacy_payment_currency_unproven';
                    $member['document_amount'] = null;
                } else {
                    $amount = (float) $member['amount'];
                    $full = FxPaymentSettlement::isFullCzkSettlement($amount, abs((float) $document['amount_to_pay']), $documentCurrency, (float) $document['exchange_rate'], $currency);
                    $member['document_amount'] = JournalLineAmount::decimal(FxPaymentSettlement::amountInInvoiceCurrency($amount, $documentCurrency, (float) $document['exchange_rate'], $currency, abs((float) $document['amount_to_pay']), $full));
                }
                if ($type === 'invoice' && $member['document_amount'] !== null && bccomp((string) $member['bank_amount'], '0', 2) < 0) $member['document_amount'] = bcsub('0', $member['document_amount'], 2);
                if ($bankId > 0) $legacyByBank[$bankId][] = $member;
            }
        }
        unset($member);

        $legacy = '0.00';
        foreach ($legacyByBank as $bankId => $group) {
            $legacyAmount = $this->sumMembers($group);
            if (isset($nativeByBank[$bankId])) {
                if ($legacyAmount === null || bccomp($legacyAmount, $this->sumMembers($nativeByBank[$bankId]) ?? '0.00', 2) !== 0) $findings[] = 'payment_representation_conflict';
                continue;
            }
            if ($legacyAmount !== null) $legacy = bcadd($legacy, $legacyAmount, 2);
        }

        $advanceEvidence = (new AdvanceSettlementEvidenceReader($this->db))->forFxItem($supplierId, $item, $asOf, $currentRead);
        array_push($findings, ...$advanceEvidence['findings']);
        $advance = $advanceEvidence['active'] ? $advanceEvidence['applied_foreign'] : JournalLineAmount::decimal($components['advance_on_account']);
        $nominal = $advanceEvidence['active'] ? JournalLineAmount::decimal($item['amount_foreign']) : bcadd(JournalLineAmount::decimal($components['amount_to_pay']), $advance, 2);
        if ($type === 'invoice') {
            $settled = bcadd(JournalLineAmount::decimal($components['paid_as_of']), $legacy, 2);
            $fullyPaid = !$hasEvidence && CreditNoteRefundExpr::isRefundedAsOf((string) $document['invoice_type'], (string) $document['status'], $document['paid_at'], $asOf, (float) $document['amount_to_pay'], $document['parent_invoice_id'] === null ? null : (int) $document['parent_invoice_id']);
        } else {
            $settled = bcadd(bcadd(JournalLineAmount::decimal($components['paid_from_matches']), JournalLineAmount::decimal($components['paid_from_cash']), 2), JournalLineAmount::decimal($components['settled_by_offsets']), 2);
            $fullyPaid = !$advanceEvidence['active'] && !$hasEvidence && (bool) ($components['fully_paid_as_of'] ?? false);
        }
        $legacyPaidStatus = $type === 'invoice' && !$hasEvidence
            && $document['paid_at'] !== null && substr((string) $document['paid_at'], 0, 10) <= $asOf
            && $document['parent_invoice_id'] === null && bccomp($nominal, '0', 2) > 0;
        $fullyPaid = $fullyPaid || $legacyPaidStatus;
        $settled = bcadd($settled, $advance, 2);
        if ($fullyPaid) $settled = $nominal;
        $diagnostics = [];
        $foreign = JournalLineAmount::decimal($item['amount_foreign']);
        if (bccomp($nominal, '0', 2) === 0) {
            if (bccomp($foreign, '0', 2) !== 0) $findings[] = 'settlement_nominal_missing';
            $remaining = $foreign;
        } else {
            $ratio = bcdiv($settled, $nominal, 16);
            if (bccomp($ratio, '0', 16) < 0 || bccomp($ratio, '1', 16) > 0) {
                $findings[] = 'settlement_amount_conflict';
                $remaining = $foreign;
            } else {
                $remaining = JournalLineAmount::decimal(bcmul($foreign, bcsub('1', $ratio, 16), 16));
            }
        }
        $manualCzk = '0.00';
        $manualForeign = '0.00';
        foreach ($manual as $member) {
            if (!(bool) $member['allocation_complete']) $findings[] = 'manual_allocation_unproven';
            if ($member['currency_code'] !== null && strtoupper((string) $member['currency_code']) !== strtoupper((string) $document['currency_code'])) $findings[] = 'manual_currency_conflict';
            $manualCzk = bcadd($manualCzk, JournalLineAmount::decimal($member['settled']), 2);
            $manualForeign = bcadd($manualForeign, JournalLineAmount::decimal($member['settled_foreign']), 2);
        }
        if ($manual !== []) {
            $originals = $this->rows("SELECT l.id FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id=e.id AND l.supplier_id=e.supplier_id LEFT JOIN journal_entries rev ON rev.id=e.reversed_by AND rev.supplier_id=e.supplier_id WHERE e.supplier_id=? AND e.source_type=? AND e.source_id=? AND e.posted_at IS NOT NULL AND e.entry_date<=? AND (e.reversed_by IS NULL OR rev.entry_date>?) AND l.account_id=? AND l.currency_code=? ORDER BY l.id", [$supplierId, $type, $id, $asOf, $asOf, (int) $item['account_id'], (string) $item['currency_code']], $currentRead);
            if (count($originals) !== 1) $findings[] = 'manual_original_allocation_unproven';
        }
        $booked = bcsub(JournalLineAmount::decimal(bcmul($remaining, (string) $item['fx_rate'], 16)), $manualCzk, 2);
        if ($advanceEvidence['active']) {
            $adjustment = bcsub($advanceEvidence['applied_czk'], JournalLineAmount::decimal(bcmul($advance, (string) $item['fx_rate'], 16)), 2);
            $booked = bcsub($booked, $adjustment, 2);
        }
        $remaining = bcsub($remaining, $manualForeign, 2);
        if ($hasEvidence && bccomp($nominal, '0', 2) !== 0) {
            $headerPaidAsOf = $document['paid_at'] !== null && substr((string) $document['paid_at'], 0, 10) <= $asOf;
            if ($headerPaidAsOf !== (bccomp($remaining, '0', 2) === 0)) $diagnostics[] = 'payment_header_divergence';
        }
        if (bccomp($foreign, '0', 2) !== 0 && bccomp(bcdiv($remaining, $foreign, 16), '0', 16) < 0) $findings[] = 'manual_settlement_amount_conflict';
        $evidence = [
            'complete' => $findings === [],
            'findings' => array_values(array_unique($findings)),
            'diagnostics' => $diagnostics,
            'document_currency' => (string) $document['currency_code'],
            'nominal_document' => $nominal,
            'settled_document' => $settled,
            'advance_document' => $advance,
            'advance_evidence' => $advanceEvidence,
            'legacy_paid_status' => $legacyPaidStatus,
            'remaining_foreign' => $remaining,
            'booked_czk' => $booked,
            'manual_settlement_czk' => $manualCzk,
            'manual_settlement_foreign' => $manualForeign,
            'manual_members' => $manual,
            'members' => $members,
            'posted_sources' => $sources,
        ];
        if ($findings === ['document_currency_conflict']
            && (new FxClosedHomePositionRepository($this->db))->proves($supplierId, $item, $document, $asOf, $evidence, $currentRead)) {
            $evidence['findings'] = [];
            $evidence['complete'] = true;
            $evidence['closed_home_position_proven'] = true;
        }
        return $evidence;
    }

    /**
     * Platba v jiné cizí měně než doklad (karta z EUR účtu na fakturu v USD): párování částku
     * v měně dokladu nenese, ale bankovní zápis ano. Jediný řádek na saldokontě v měně dokladu
     * se stejnou částkou úhradu dokládá, převod měn udělala banka.
     *
     * @param array<string,mixed>|null $source {@see postedSource()}
     * @param array<string,mixed> $item
     */
    private static function postedInDocumentCurrency(?array $source, array $item, string $documentCurrency, string $type, mixed $documentAmount): bool
    {
        if ($source === null || $documentAmount === null) return false;
        $side = $type === 'invoice' ? 'credit' : 'debit';
        $lines = array_values(array_filter($source['lines'], static fn (array $line): bool =>
            (int) $line['account_id'] === (int) $item['account_id'] && $line['side'] === $side));
        return count($lines) === 1
            && strtoupper((string) $lines[0]['currency_code']) === strtoupper($documentCurrency)
            && $lines[0]['signed_amount_foreign'] !== null
            && bccomp(JournalLineAmount::decimal($lines[0]['signed_amount_foreign']), JournalLineAmount::decimal($documentAmount), 2) === 0;
    }

    private function manualLifecycle(int $supplierId, string $type, int $id, int $accountId, bool $currentRead): array
    {
        $sql = LinkedManualSettlementSql::lifecycleMembersSql($type, $type === 'invoice' ? 'credit' : 'debit', "ca.id = {$accountId} AND dl.doc_id = {$id}", $currentRead);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function manualMembers(int $supplierId, string $type, int $id, int $accountId, string $asOf, bool $currentRead): array
    {
        $sql = LinkedManualSettlementSql::membersSql($type, $type === 'invoice' ? 'credit' : 'debit', "ca.id = {$accountId} AND dl.doc_id = {$id}", currentRead: $currentRead);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $asOf, $asOf]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function members(int $supplierId, string $type, int $id, bool $currentRead): array
    {
        $members = [];
        if ($type === 'invoice') {
            foreach ($this->rows("SELECT ip.id,ip.amount,ip.currency,ip.source,ip.bank_transaction_id,cd.id AS cash_document_id,COALESCE(DATE(bt.posted_at),ip.paid_on) AS effective_date FROM invoice_payments ip LEFT JOIN bank_transactions bt ON bt.id=ip.bank_transaction_id LEFT JOIN cash_documents cd ON cd.invoice_payment_id=ip.id AND cd.supplier_id=ip.supplier_id WHERE ip.supplier_id=? AND ip.invoice_id=? ORDER BY ip.id", [$supplierId, $id], $currentRead) as $row) {
                $members[] = ['representation' => 'invoice_payment', ...$row];
            }
        }
        $column = $type === 'invoice' ? 'invoice_id' : 'purchase_invoice_id';
        $purchaseProjection = $type === 'purchase_invoice' ? ',' . PurchaseSettledExpr::bankAmountSql('pm', 'bt', 'bs', 'd', 'cur') . ' AS document_amount' : '';
        $purchaseJoin = $type === 'purchase_invoice' ? ' JOIN purchase_invoices d ON d.id=pm.purchase_invoice_id AND d.supplier_id=pm.supplier_id JOIN currencies cur ON cur.id=d.currency_id' : '';
        foreach ($this->rows("SELECT pm.id,pm.amount,bt.amount AS bank_amount,pm.bank_transaction_id,UPPER(COALESCE(NULLIF(bt.currency,''),bs.currency)) AS currency,DATE(bt.posted_at) AS effective_date{$purchaseProjection} FROM payment_matches pm JOIN bank_transactions bt ON bt.id=pm.bank_transaction_id JOIN bank_statements bs ON bs.id=bt.statement_id AND bs.supplier_id=pm.supplier_id{$purchaseJoin} WHERE pm.supplier_id=? AND pm.{$column}=? ORDER BY pm.id", [$supplierId, $id], $currentRead) as $row) {
            $members[] = ['representation' => 'payment_match', 'cash_document_id' => null, ...$row];
        }
        if ($type === 'purchase_invoice') {
            $cashAmount = PurchaseSettledExpr::cashAmountSql('cd', 'd', 'cur');
            foreach ($this->rows("SELECT cd.id,cd.id AS cash_document_id,cd.issue_date AS effective_date,cd.status,rev.entry_date AS reversed_on,{$cashAmount} AS document_amount FROM cash_documents cd JOIN purchase_invoices d ON d.id=cd.purchase_invoice_id AND d.supplier_id=cd.supplier_id JOIN currencies cur ON cur.id=d.currency_id LEFT JOIN journal_entries rev ON rev.id=cd.reversal_entry_id AND rev.supplier_id=cd.supplier_id WHERE cd.supplier_id=? AND cd.purchase_invoice_id=? ORDER BY cd.id", [$supplierId, $id], $currentRead) as $row) {
                $members[] = ['representation' => 'purchase_cash', ...$row];
            }
            foreach ($this->rows("SELECT s.id,s.id AS journal_source_id,s.settled_on AS effective_date,s.amount AS document_amount,s.status,rev.entry_date AS reversed_on FROM invoice_settlements s LEFT JOIN journal_entries e ON e.id=s.journal_entry_id AND e.supplier_id=s.supplier_id LEFT JOIN journal_entries rev ON rev.id=e.reversed_by AND rev.supplier_id=e.supplier_id WHERE s.supplier_id=? AND s.doc_type='purchase_invoice' AND s.doc_id=? ORDER BY s.id", [$supplierId, $id], $currentRead) as $row) {
                $members[] = ['representation' => 'purchase_settlement', 'journal_source_type' => 'settlement', ...$row];
            }
            foreach ($this->rows("SELECT oi.id,oa.id AS journal_source_id,oa.agreement_date AS effective_date,oi.amount AS document_amount,oa.status,rev.entry_date AS reversed_on FROM offset_agreement_items oi JOIN offset_agreements oa ON oa.id=oi.agreement_id AND oa.supplier_id=oi.supplier_id LEFT JOIN journal_entries e ON e.id=oa.journal_entry_id AND e.supplier_id=oa.supplier_id LEFT JOIN journal_entries rev ON rev.id=e.reversed_by AND rev.supplier_id=e.supplier_id WHERE oi.supplier_id=? AND oi.doc_type='purchase_invoice' AND oi.doc_id=? ORDER BY oi.id", [$supplierId, $id], $currentRead) as $row) {
                $members[] = ['representation' => 'purchase_offset', 'journal_source_type' => 'offset', ...$row];
            }
            foreach ($this->rows("SELECT id,offset_on AS effective_date,amount AS document_amount,credit_note_id FROM credit_note_offsets WHERE supplier_id=? AND doc_type='purchase_invoice' AND invoice_id=? ORDER BY id", [$supplierId, $id], $currentRead) as $row) {
                $members[] = ['representation' => 'purchase_credit_note', ...$row];
            }
        }
        return $members;
    }

    private function postedSource(int $supplierId, string $type, int $id, string $asOf, bool $currentRead): array
    {
        $entries = $this->rows("SELECT e.id,e.entry_date,e.posted_at,e.reversed_by,r.entry_date AS reversed_on FROM journal_entries e LEFT JOIN journal_entries r ON r.id=e.reversed_by AND r.supplier_id=e.supplier_id WHERE e.supplier_id=? AND e.source_type=? AND e.source_id=? ORDER BY e.id", [$supplierId, $type, $id], $currentRead);
        $lines = [];
        $effective = null;
        foreach ($entries as $entry) {
            if ($entry['posted_at'] === null || ($entry['reversed_on'] !== null && $entry['reversed_on'] <= $asOf)) continue;
            $effective = $effective === null ? $entry['entry_date'] : min($effective, $entry['entry_date']);
            foreach ($this->rows('SELECT id,account_id,side,signed_amount,signed_amount_foreign,currency_code FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? ORDER BY line_no,id', [$supplierId, (int) $entry['id']], $currentRead) as $line) {
                $lines[] = ['entry_id' => (int) $entry['id'], 'entry_date' => $entry['entry_date'], ...$line];
            }
        }
        return ['source_type' => $type, 'source_id' => $id, 'effective_date' => $effective, 'entries' => $entries, 'lines' => $lines];
    }

    private function sumMembers(array $members): ?string
    {
        $sum = '0.00';
        foreach ($members as $member) {
            if (!$member['included_as_of']) continue;
            if ($member['document_amount'] === null) return null;
            $sum = bcadd($sum, $member['document_amount'], 2);
        }
        return $sum;
    }

    private function rows(string $sql, array $params, bool $currentRead): array
    {
        $stmt = $this->db->pdo()->prepare($sql . ($currentRead ? ' FOR UPDATE' : ''));
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function incomplete(string $finding): array
    {
        return ['complete' => false, 'findings' => [$finding]];
    }
}
