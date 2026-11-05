<?php

declare(strict_types=1);

namespace MyInvoice\Service\WorkReport;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\Invoice\InvoiceCalculator;

final class WorkReportBillingService
{
    public function __construct(private readonly Connection $db, private readonly WorkReportRepository $reports,
        private readonly InvoiceRepository $invoices, private readonly InvoiceCalculator $calculator) {}

    public function assertCurrent(int $invoiceId, int $supplierId): void
    {
        $report = $this->reports->findByInvoice($invoiceId, $supplierId);
        if ($report === null || $report['billing_snapshot'] === null) return;
        if ($report['billing_stale']) throw new WorkReportBillingConflict('Účtování výkazu se změnilo. Znovu přeneste rekapitulaci do faktury.');
        $actual = array_values(array_filter($this->invoices->itemsFor($invoiceId), static fn (array $item): bool => (int) ($item['linked_work_report_id'] ?? 0) === (int) $report['id'] && ($item['work_report_billing_key'] ?? null) !== null));
        $expected = $report['billing_preview']['items'];
        if (count($actual) !== count($expected)) throw new WorkReportBillingConflict('Položky účtování výkazu byly změněny. Znovu přeneste rekapitulaci.');
        $byKey = array_column($actual, null, 'work_report_billing_key');
        foreach ($expected as $row) {
            $stored = $byKey[$row['work_report_billing_key']] ?? null;
            if ($stored !== null) $stored = \MyInvoice\Service\Invoice\TimeBilling::normalizeInvoiceItem($stored);
            foreach (['description', 'quantity', 'duration_minutes', 'unit', 'unit_price_without_vat', 'vat_rate_snapshot'] as $key) {
                if ($stored === null || (is_numeric($row[$key]) ? abs((float) ($stored[$key] ?? 0) - (float) $row[$key]) > 0.000001 : ($stored[$key] ?? null) !== $row[$key])) {
                    throw new WorkReportBillingConflict('Položky účtování výkazu byly změněny. Znovu přeneste rekapitulaci.');
                }
            }
        }
    }

    /** Volající drží zámek faktury; pouze přenos smí potvrdit aktuální otisk výkazu. */
    public function transfer(int $invoiceId, int $supplierId, string $fingerprint, \MyInvoice\Service\Oss\OssItemPlanner $planner, ?int $legacyItemId = null): array
    {
        $report = $this->reports->findByInvoice($invoiceId, $supplierId);
        if ($report === null || $report['billing_snapshot'] === null) throw new \InvalidArgumentException('Výkaz nemá cenové ujednání.');
        $preview = $report['billing_preview'];
        if (!hash_equals($preview['fingerprint'], $fingerprint)) throw new \DomainException('Náhled je zastaralý. Obnovte jej před přenosem.');
        $storedItems = \MyInvoice\Service\Stock\InvoiceItemTracking::fromStored($this->invoices->itemsFor($invoiceId));
        if ($legacyItemId !== null) {
            $legacy = array_column($storedItems, null, 'id')[$legacyItemId] ?? null;
            if ($legacy === null || ($legacy['item_kind'] ?? 'standard') !== 'standard' || !empty($legacy['stock_item_id'])
                || !empty($legacy['asset_id']) || !empty($legacy['small_asset_id']) || !empty($legacy['work_report_billing_key'])) {
                throw new \InvalidArgumentException('Nahrazovaný souhrnný řádek musí být běžná položka této faktury bez vazby na sklad či majetek.');
            }
        }
        $items = array_values(array_filter($storedItems, static fn (array $item): bool => (int) $item['id'] !== $legacyItemId && !((int) ($item['linked_work_report_id'] ?? 0) === (int) $report['id'] && ($item['work_report_billing_key'] ?? null) !== null)));
        $invoice = $this->invoices->find($invoiceId);
        $planned = array_map(static function (array $item): array {
            $item['vat_rate'] = $item['vat_rate_snapshot'];
            // Paušál i pásma jsou práce; jednotka ks u paušálu neurčuje druh plnění.
            $item['unit'] = 'h';
            unset($item['vat_rate_id'], $item['vat_rate_snapshot']);
            return $item;
        }, $preview['items']);
        try {
            $planned = $planner->planIssuedItems($supplierId, (int) $invoice['client_id'],
                (string) (($invoice['tax_date'] ?? null) ?: $invoice['issue_date']), (bool) $invoice['reverse_charge'], $planned);
        } catch (\RuntimeException $e) {
            if (\MyInvoice\Infrastructure\Database\TransactionFailure::aborted($e)) throw $e;
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
        foreach ($planned as $index => $item) {
            $item['unit'] = $preview['items'][$index]['unit'];
            $items[] = $item;
        }
        foreach ($items as $index => &$item) $item['order_index'] = $index;
        unset($item);
        $this->invoices->replaceItems($invoiceId, $items);
        $this->calculator->recompute($invoiceId);
        $this->db->pdo()->prepare('UPDATE work_reports SET billing_transferred_fingerprint = ? WHERE id = ?')->execute([$fingerprint, $report['id']]);
        return ['work_report' => $this->reports->findByInvoice($invoiceId, $supplierId), 'items' => $this->invoices->itemsFor($invoiceId), 'preview' => $preview];
    }
}
