<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Repository\StockDocumentRepository;
use MyInvoice\Repository\StockLandedCostRepository;
use MyInvoice\Service\Vat\VatStatusService;

final class StockAcquisitionCostService
{
    public function __construct(
        private readonly StockDocumentRepository $docs,
        private readonly StockLandedCostRepository $landedCosts,
        private readonly VatStatusService $vatStatus,
        private readonly StockReferenceGuard $references,
    ) {}

    public function context(int $supplierId, array $invoice): array
    {
        $date = (string) (($invoice['tax_date'] ?? null) ?: ($invoice['issue_date'] ?? ''));
        if ($date === '') {
            throw new StockException('invalid_document', 'Chybí rozhodné datum pro ocenění příjmu.');
        }
        $payer = $this->vatStatus->isVatPayerAt($supplierId, $date);
        $rate = $invoice['exchange_rate'] !== null ? (float) $invoice['exchange_rate'] : 1.0;
        return ['field' => $payer ? 'total_without_vat' : 'total_with_vat', 'rate' => $rate];
    }

    public function amount(array $context, array $item): float
    {
        return (float) $item[$context['field']] * $context['rate'];
    }

    public function unitCost(array $context, array $item): float
    {
        $qty = (float) $item['quantity'];
        return $qty > 0 ? $this->amount($context, $item) / $qty : 0.0;
    }

    public function applyLandedCosts(int $supplierId, int $documentId, string $docDate, array $rawCosts): void
    {
        if ($rawCosts === []) {
            return;
        }

        $bad = $this->references->violations($supplierId, [
            'purchase_invoice_id'      => array_map(
                static fn (mixed $rc): mixed => is_array($rc) ? ($rc['purchase_invoice_id'] ?? null) : null,
                $rawCosts,
            ),
            'purchase_invoice_item_id' => array_map(
                static fn (mixed $rc): mixed => is_array($rc) ? ($rc['purchase_invoice_item_id'] ?? null) : null,
                $rawCosts,
            ),
        ]);
        if ($bad !== []) {
            throw new StockException(
                'invalid_reference',
                'Vedlejší náklad odkazuje na záznam mimo vaši firmu.',
                422,
                $bad,
            );
        }

        $costs = [];
        foreach ($rawCosts as $rc) {
            if (!is_array($rc)) {
                continue;
            }
            $amount = (float) ($rc['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $allocation = ((string) ($rc['allocation'] ?? 'by_value')) === 'by_qty' ? 'by_qty' : 'by_value';
            $amountStr  = number_format($amount, 2, '.', '');
            $this->landedCosts->insert($supplierId, [
                'document_id'              => $documentId,
                'purchase_invoice_id'      => isset($rc['purchase_invoice_id']) && (int) $rc['purchase_invoice_id'] > 0 ? (int) $rc['purchase_invoice_id'] : null,
                'purchase_invoice_item_id' => isset($rc['purchase_invoice_item_id']) && (int) $rc['purchase_invoice_item_id'] > 0 ? (int) $rc['purchase_invoice_item_id'] : null,
                'description'              => trim((string) ($rc['description'] ?? '')) !== '' ? trim((string) $rc['description']) : 'Vedlejší náklad',
                'amount'                   => $amountStr,
                'allocation'               => $allocation,
            ]);
            $costs[] = ['amount' => StockValuation::valueToC($amountStr), 'allocation' => $allocation];
        }
        if ($costs === []) {
            return;
        }

        $lines = $this->docs->lines($supplierId, $documentId);
        if ($lines === []) {
            return;
        }
        $allocLines = array_map(static fn (array $l): array => [
            'value' => StockValuation::valueToC((string) $l['value_total']),
            'qty'   => StockValuation::qtyToT((string) $l['qty']),
        ], $lines);
        $extraPerLine = LandedCostAllocator::allocate($allocLines, $costs);

        foreach ($lines as $i => $l) {
            $extraC    = $extraPerLine[$i] ?? 0;
            $newValueC = StockValuation::valueToC((string) $l['value_total']) + $extraC;
            $this->docs->updateLineValuation(
                $supplierId,
                (int) $l['id'],
                (string) $l['unit_cost'],
                StockValuation::cToDecimal($newValueC),
                StockValuation::cToDecimal($extraC),
                $docDate,
            );
        }
    }

}
