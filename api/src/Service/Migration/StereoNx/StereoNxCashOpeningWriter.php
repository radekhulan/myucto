<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\StereoNx;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Bank\StatementBalanceService;
use MyInvoice\Service\Migration\Shared\ImportedAccountCode;
use MyInvoice\Service\Migration\Shared\MigratedCashNumber;
use MyInvoice\Service\Migration\Shared\MigratedPaymentWriter;
use PDO;

/** Evidenční doklad pokladny zastoupený v již převzatém otevíracím zápisu. */
final class StereoNxCashOpeningWriter
{
    public function __construct(private readonly Connection $db, private readonly StereoNxImportMap $map) {}

    /** @return int počet nově doložených počátečních dokladů */
    public function write(array $context, array $cashOpenings, array $journalPlan): int
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new StereoNxException('transaction_required', 'Zápis počátečních stavů pokladny vyžaduje transakci převodu.');
        if ($cashOpenings === []) return 0;
        $supplier = (int) $context['supplier_id'];
        (new DimensionPeriodGuard($this->db))->lockPeriods($supplier);
        $proofs = (new StereoNxOpeningJournalProof($this->db))->validate($context, $cashOpenings, $journalPlan, 'cash_opening_unverified');
        $keys = []; $cashIds = [];
        foreach ($cashOpenings as $opening) {
            $key = (string) ($opening['movement']['source_key'] ?? '');
            $id = $context['ids']['cash'][$key] ?? null;
            if ($id === null || isset($cashIds[(int) $id])
                || substr(ImportedAccountCode::normalize((string) ($opening['account_code'] ?? '')), 0, 3) !== '211') self::unverified();
            $keys[] = $key; $cashIds[(int) $id] = true;
        }
        $maps = [];
        sort($keys, SORT_STRING);
        foreach (array_chunk($keys, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $pdo->prepare("SELECT kind,source_key,source_hash,target_id FROM stereo_nx_import_map
                WHERE supplier_id=? AND source_ico=? AND source_company_index=? AND kind IN ('cash','cash_opening')
                  AND source_key IN ({$marks}) ORDER BY kind,source_key FOR UPDATE");
            $q->execute([$supplier, $context['ico'], $context['company_index'], ...$chunk]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $maps[$row['kind']][$row['source_key']] = $row;
        }
        $docs = []; $links = []; $hasVat = []; $allocations = [];
        $ids = array_keys($cashIds); sort($ids, SORT_NUMERIC);
        foreach (array_chunk($ids, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $pdo->prepare("SELECT c.*,r.account_code AS register_account,r.currency_code AS register_currency,r.is_active AS register_active
                FROM cash_documents c FORCE INDEX (uq_cash_document_supplier_id)
                STRAIGHT_JOIN cash_registers r ON r.supplier_id=c.supplier_id AND r.id=c.register_id
                WHERE c.supplier_id=? AND c.id IN ({$marks}) ORDER BY c.id FOR UPDATE");
            $q->execute([$supplier, ...$chunk]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $docs[(int) $row['id']] = $row;
            $q = $pdo->prepare("SELECT l.doc_id,l.entry_id,l.note FROM journal_entry_document_links l FORCE INDEX (idx_jedl_doc)
                WHERE l.supplier_id=? AND l.doc_type='cash' AND l.doc_id IN ({$marks}) ORDER BY l.doc_id,l.entry_id FOR UPDATE");
            $q->execute([$supplier, ...$chunk]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $links[(int) $row['doc_id']][] = $row;
            $q = $pdo->prepare("SELECT v.cash_document_id FROM cash_documents c FORCE INDEX (uq_cash_document_supplier_id)
                STRAIGHT_JOIN cash_document_vat_lines v ON v.cash_document_id=c.id
                WHERE c.supplier_id=? AND c.id IN ({$marks}) FOR UPDATE");
            $q->execute([$supplier, ...$chunk]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $hasVat[(int) $id] = true;
            foreach (['payroll_payment_matches' => 'idx_payroll_payment_match_cash', 'other_item_allocations' => 'idx_other_allocation_cash'] as $table => $index) {
                $q = $pdo->prepare("SELECT cash_document_id FROM {$table} FORCE INDEX ({$index}) WHERE supplier_id=? AND cash_document_id IN ({$marks}) FOR UPDATE");
                $q->execute([$supplier, ...$chunk]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $allocations[(int) $id] = true;
            }
        }
        $writes = [];
        foreach ($cashOpenings as $opening) {
            $movement = $opening['movement']; $key = $movement['source_key'];
            $id = (int) $context['ids']['cash'][$key]; $doc = $docs[$id] ?? null;
            $mapped = $maps['cash'][$key] ?? null; $previous = $maps['cash_opening'][$key] ?? null;
            if ($doc === null || $mapped === null || (int) $mapped['target_id'] !== $id
                || !hash_equals($mapped['source_hash'], StereoNxImporter::sourceHash($movement))
                || $doc['issue_date'] !== $opening['date'] || $doc['currency_code'] !== 'CZK'
                || $doc['register_currency'] !== 'CZK' || !(bool) $doc['register_active']
                || ImportedAccountCode::normalize($doc['register_account']) !== ImportedAccountCode::normalize($opening['account_code'])
                || $doc['purpose'] !== 'other' || $doc['vat_mode'] !== 'none' || isset($hasVat[$id]) || isset($allocations[$id])
                || $doc['journal_entry_id'] !== null || $doc['reversal_entry_id'] !== null
                || $doc['invoice_id'] !== null || $doc['purchase_invoice_id'] !== null || $doc['invoice_payment_id'] !== null
                || $doc['rule_key'] !== null || $doc['counter_account_code'] !== null
                || (float) $doc['fx_rate'] !== 1.0 || ($doc['amount_foreign'] ?? null) !== null
                || ($previous === null && $doc['doc_number'] !== ($context['cash_numbers'][$key]
                    ?? mb_substr((string) ($movement['document_no'] ?? ''), 0, MigratedCashNumber::MAX_LENGTH)))
                || $doc['description'] !== mb_substr((string) ($movement['description'] ?? 'Stereo NX'), 0, 255)
                || StatementBalanceService::cents($doc['total_amount']) * ($doc['doc_type'] === 'in' ? 1 : -1) !== $opening['opening_cents']) self::unverified();
            $hash = self::fingerprint($opening, $proofs[$key]['entries'], $doc['doc_number']);
            $actualLinks = $links[$id] ?? [];
            if ($previous !== null) {
                if ($doc['status'] !== 'posted' || (int) $previous['target_id'] !== $id
                    || !hash_equals($previous['source_hash'], $hash)) self::unverified();
                $expected = $proofs[$key]['entry_ids']; sort($expected, SORT_NUMERIC);
                $actual = [];
                foreach ($actualLinks as $link) {
                    if ($link['note'] !== MigratedPaymentWriter::OPENING_CASH_NOTE) self::unverified();
                    $actual[] = (int) $link['entry_id'];
                }
                sort($actual, SORT_NUMERIC);
                if ($expected !== $actual) self::unverified();
                continue;
            }
            // Starší import zanechal přesně tentýž doložený doklad jako koncept.
            // Jen jeho potvrzení je povoleno; cizí vazba nebo již posted doklad bez důkazu vyžaduje kontrolu.
            if ($doc['status'] !== 'draft' || $actualLinks !== []) self::unverified();
            $writes[] = [$id, $key, $hash, $proofs[$key]['entry_ids']];
        }
        $writer = new MigratedPaymentWriter($this->db);
        foreach ($writes as [$id, $key, $hash, $entryIds]) {
            // linkOpening() vybírá první opening období; zde máme důkaz konkrétních zdrojových kontací.
            $writer->confirmOpeningCash($supplier, $id, $entryIds, $context['user_id'] ?? null);
            $this->map->put($supplier, $context['ico'], $context['company_index'], 'cash_opening', $key, $hash, $id);
        }
        return count($writes);
    }

    public static function fingerprint(array $opening, array $entries, ?string $documentNo = null): string
    {
        $proof = [];
        foreach ($entries as $entry) $proof[$entry['source_key']] = ['hash' => $entry['source_hash'],
            'debit' => StereoNxOpeningJournalProof::account($entry, 'debit'), 'credit' => StereoNxOpeningJournalProof::account($entry, 'credit')];
        ksort($proof, SORT_STRING);
        return StereoNxImportMap::fingerprint(['movement' => StereoNxImporter::sourceHash($opening['movement']),
            'opening_cents' => $opening['opening_cents'], 'account_code' => ImportedAccountCode::normalize($opening['account_code']),
            'date' => $opening['date'], 'entries' => $proof,
            'document_no' => $documentNo ?? mb_substr((string) ($opening['movement']['document_no'] ?? ''), 0, MigratedCashNumber::MAX_LENGTH)]);
    }

    private static function unverified(): never
    {
        throw new StereoNxException('cash_opening_unverified', 'Počáteční stav pokladny nemá nezměněný evidenční doklad a doloženou živou počáteční kontaci.');
    }
}
