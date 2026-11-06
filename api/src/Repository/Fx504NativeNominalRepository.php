<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\Fx504CaseProjection;
use MyInvoice\Service\Accounting\JournalLineAmount;

final class Fx504NativeNominalRepository
{
    public function __construct(private readonly Connection $db) {}

    public function normalize(int $supplierId, string $asOf, array $rows, array $headers, bool $currentRead): array
    {
        $native = (new FxCaseOriginReadRepository($this->db))->snapshot($supplierId, $rows, $currentRead);
        $documents = ['invoice' => array_column($native['facts']['invoices'], null, 'id'), 'purchase_invoice' => array_column($native['facts']['purchase_invoices'], null, 'id')];
        $missing = [];
        foreach ($rows as $index => $row) {
            if ($row['currency_code'] !== null && $row['currency_code'] !== 'CZK' && $row['amount_foreign'] !== null) continue;
            $candidates = array_values(array_filter($native['origins'][(int) $row['id']] ?? [], static fn (array $origin): bool => $origin['kind'] === 'document'
                && !in_array($documents[$origin['doc_type']][$origin['doc_id']]['currency_code'] ?? 'CZK', ['', 'CZK'], true)));
            if ($candidates !== []) $missing[$index] = $candidates;
        }
        $evidence = [];
        if ($missing !== []) {
            foreach ((new ClosingRepository($this->db))->fxItemsWithSettlement($supplierId, $asOf, $currentRead) as $item) {
                $key = $item['item']['doc_type'] . ':' . $item['item']['doc_id'] . ':' . $item['item']['account_id'];
                $evidence[$key][] = $item;
            }
        }
        $findings = [];
        $raw = $rows;
        $normalized = [];
        foreach ($missing as $index => $origins) {
            $row = $rows[$index];
            $origin = count($origins) === 1 ? Fx504CaseProjection::origin($origins[0]) : null;
            $proofs = $origin === null ? [] : ($evidence[$origin['doc_type'] . ':' . $origin['doc_id'] . ':' . $row['account_id']] ?? []);
            $proof = count($proofs) === 1 && ($proofs[0]['evidence']['complete'] ?? false) ? $proofs[0] : null;
            $peers = array_filter($raw, static fn (array $other): bool => $other['entry_id'] === $row['entry_id'] && $other['account_id'] === $row['account_id']);
            $parts = [];
            if ($proof !== null && count($peers) === 1) {
                foreach ($proof['evidence']['members'] ?? [] as $member) {
                    $type = !empty($member['bank_transaction_id']) ? 'bank' : (!empty($member['cash_document_id']) ? 'cash' : ($member['journal_source_type'] ?? null));
                    $id = $member['bank_transaction_id'] ?? $member['cash_document_id'] ?? $member['journal_source_id'] ?? 0;
                    if ($type === $row['source_type'] && (int) $id === (int) $row['source_id'] && ($member['included_as_of'] ?? false)) {
                        $parts[$member['representation']] = bcadd($parts[$member['representation']] ?? '0.00', $member['document_amount'], 2);
                    }
                }
            }
            $amounts = array_values(array_unique(array_values($parts)));
            $amount = count($amounts) === 1 ? $amounts[0] : null;
            $expectedSide = $origin === null ? null : ($origin['doc_type'] === 'invoice' ? 'credit' : 'debit');
            if ($amount !== null && bccomp($amount, '0', 2) < 0) $expectedSide = $expectedSide === 'credit' ? 'debit' : 'credit';
            if ($proof === null || $amount === null || $row['side'] !== $expectedSide || $row['is_red_storno']) {
                $findings[] = ['code' => 'case_nominal_unproven', 'line_id' => (int) $row['id'], 'entry_id' => (int) $row['entry_id']];
                continue;
            }
            $rows[$index]['currency_code'] = $proof['item']['currency_code'];
            $rows[$index]['amount_foreign'] = ltrim($amount, '-');
            $rows[$index]['fx_rate'] = $proof['item']['fx_rate'];
            $normalized[$row['entry_id']][(int) $row['line_no']] = $index;
        }
        foreach ($headers as $header) {
            if ($header['reversed_by'] === null || !isset($normalized[$header['id']])) continue;
            foreach ($rows as $index => $row) {
                $sourceIndex = $normalized[$header['id']][(int) $row['line_no']] ?? null;
                if ((int) $row['entry_id'] !== (int) $header['reversed_by'] || $sourceIndex === null) continue;
                if (!Fx504CaseReadRepository::same($raw[$index], JournalLineAmount::reversal($raw[$sourceIndex]))) {
                    $findings[] = ['code' => 'case_nominal_unproven', 'line_id' => (int) $row['id'], 'entry_id' => (int) $row['entry_id']];
                    continue;
                }
                $mirror = JournalLineAmount::reversal($rows[$sourceIndex]);
                foreach (['currency_code', 'amount_foreign', 'fx_rate'] as $field) $rows[$index][$field] = $mirror[$field];
            }
        }
        $unproven = array_fill_keys(array_column($findings, 'line_id'), true);
        foreach ($rows as $row) {
            if ($row['currency_code'] === null || $row['currency_code'] === 'CZK' || $row['amount_foreign'] !== null || isset($unproven[$row['id']])) continue;
            $findings[] = ['code' => 'case_nominal_unproven', 'line_id' => (int) $row['id'], 'entry_id' => (int) $row['entry_id']];
            $unproven[$row['id']] = true;
        }
        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['currency_code'] !== null && $row['currency_code'] !== 'CZK' && $row['amount_foreign'] !== null));
        return ['rows' => $rows, 'findings' => $findings, 'hash' => hash('sha256', json_encode([$raw, $native['facts_hash'], $evidence], JSON_THROW_ON_ERROR))];
    }
}
