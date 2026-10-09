<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

final class InvoiceKhSections
{
    public function __construct(
        private readonly DphBookBuilder $book,
        private readonly VatLedgerService $ledger,
    ) {}

    /** @param array<int,array<string,mixed>> $groups */
    public function addToGroups(int $supplierId, array &$groups, string $direction, bool $includeVat = false): void
    {
        $ids = [];
        foreach ($groups as $group) {
            foreach ($group['invoices'] as $invoice) {
                $ids[] = (int) $invoice['id'];
            }
        }
        if ($ids === []) return;

        $claimInfo = $direction === 'received' ? $this->ledger->purchaseClaimInfo($supplierId, $ids) : [];
        $periods = [];
        foreach ($groups as $group) {
            foreach ($group['invoices'] as $invoice) {
                $id = (int) $invoice['id'];
                $date = $direction === 'received'
                    ? ($claimInfo[$id]['claim_date'] ?? '')
                    : ($invoice['month_bucket'] ?? '');
                $period = substr((string) $date, 0, 7);
                if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1) {
                    $periods[$period][$id] = true;
                }
            }
        }

        $sectionsById = [];
        $classificationsById = [];
        $linesById = [];
        foreach ($periods as $period => $periodIds) {
            $book = $this->book->build($supplierId, (int) substr($period, 0, 4), (int) substr($period, 5, 2));
            if ($includeVat) {
                foreach ($this->ledger->rows($supplierId, $book['period']['start'], $book['period']['end'], true) as $row) {
                    $id = (int) ($row['invoice_id'] ?? 0);
                    $source = $direction === 'issued' ? 'sale' : 'purchase';
                    if (!isset($periodIds[$id]) || ($row['source'] ?? '') !== $source
                        || ($row['document_kind'] ?? '') === 'cash') continue;
                    $code = (string) ($row['code'] ?? '');
                    if ($code !== '') $classificationsById[$id][$code] = true;
                }
            }
            foreach ($book['sections'] as $section) {
                foreach ($section['rows'] as $row) {
                    $id = (int) ($row['invoice_id'] ?? 0);
                    $kh = (string) ($row['kh_section'] ?? '');
                    if (!isset($periodIds[$id]) || ($row['direction'] ?? '') !== $direction
                        || ($row['document_kind'] ?? '') === 'cash' || !empty($row['is_correction'])) continue;
                    if ($kh !== '') $sectionsById[$id][$kh] = true;
                    if ($includeVat) {
                        $line = (string) ($section['dphdp3_line'] ?? '');
                        if ($line !== '' && $line !== '000') $linesById[$id][$line] = true;
                    }
                }
            }
        }

        foreach ($groups as &$group) {
            foreach ($group['invoices'] as &$invoice) {
                $sections = array_keys($sectionsById[(int) $invoice['id']] ?? []);
                sort($sections, SORT_NATURAL);
                $invoice['kh_sections'] = $sections;
                if ($includeVat) {
                    $codes = array_map('strval', array_keys($classificationsById[(int) $invoice['id']] ?? []));
                    $lines = array_map('strval', array_keys($linesById[(int) $invoice['id']] ?? []));
                    sort($codes, SORT_NATURAL);
                    sort($lines, SORT_NATURAL);
                    $invoice['vat_classification_codes'] = $codes;
                    $invoice['vat_return_lines'] = $lines;
                }
            }
            unset($invoice);
        }
        unset($group);
    }
}
