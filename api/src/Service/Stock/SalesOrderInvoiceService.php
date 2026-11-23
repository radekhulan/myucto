<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Integration\IntegrationEventPublisher;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceRounding;
use MyInvoice\Service\Oss\OssItemPlanner;
use MyInvoice\Service\SalesQuote\QuoteInvoiceOrigin;
use MyInvoice\Service\SalesQuote\QuoteRepository;
use MyInvoice\Service\Shoptet\ShoptetInvoicingGuard;
use PDO;

final class SalesOrderInvoiceService
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceCalculator $calculator,
        private readonly SalesOrderService $orders,
        private readonly OssItemPlanner $ossPlanner,
        private readonly IntegrationEventPublisher $events,
        private readonly ShoptetInvoicingGuard $shoptetGuard,
    ) {}

    /** Vytvoří pouze draft. Běžné vystavení ani stock_auto_issue tato cesta nespouští. */
    public function createDraft(int $supplierId, int $orderId, int $userId, string $idempotencyKey): array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 191) {
            throw new SalesOrderException('idempotency_key_required', 'Je vyžadován platný idempotency key.');
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $quote = $this->lockQuote($pdo, $supplierId, $orderId);
            $lock = $pdo->prepare('SELECT id, order_uuid, commercial_status, row_version FROM sales_orders WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $lock->execute([$supplierId, $orderId]);
            $header = $lock->fetch(PDO::FETCH_ASSOC);
            if ($header === false) throw new SalesOrderException('not_found', 'Objednávka nenalezena.', 404);
            $operation = $pdo->prepare(
                'SELECT order_id FROM sales_order_operation_keys
                  WHERE supplier_id = ? AND operation = "invoice" AND idempotency_key = ? FOR UPDATE'
            );
            $operation->execute([$supplierId, $idempotencyKey]);
            $operationOrderId = $operation->fetchColumn();
            if ($operationOrderId !== false && (int) $operationOrderId !== $orderId) {
                throw new SalesOrderException('idempotency_conflict', 'Idempotency key už patří jiné objednávce.', 409);
            }
            $linked = $pdo->prepare('SELECT invoice_id FROM sales_order_invoice_links WHERE supplier_id = ? AND order_id = ?');
            $linked->execute([$supplierId, $orderId]);
            $invoiceId = $linked->fetchColumn();
            if ($invoiceId !== false) {
                $pdo->commit();
                return $this->invoices->find((int) $invoiceId) ?? throw new \LogicException('Navázaná faktura nenalezena.');
            }
            if (!in_array($header['commercial_status'], ['confirmed', 'completed'], true)) {
                throw new SalesOrderException('state_conflict', 'Fakturu lze vytvořit jen z potvrzené objednávky.', 409);
            }
            $this->shoptetGuard->assertCanInvoice($supplierId, $orderId);
            $order = $this->orders->detail($supplierId, $orderId) ?? throw new SalesOrderException('not_found', 'Objednávka nenalezena.', 404);
            $today = date('Y-m-d');
            $clientContext = $this->ossPlanner->clientContext((int) $order['client_id'], $order['customer_snapshot']);
            $items = [];
            $quoteKeys = [];
            $quotedItems = $quote === null ? [] : $this->approvedItems($quote);
            foreach ($order['lines'] as $index => $line) {
                $quoteKey = $quote['line_origins'][$line['line_uuid']] ?? null;
                $minutes = null;
                if (isset($quotedItems[$quoteKey])) {
                    $quotedItem = $quotedItems[$quoteKey];
                    if ((float) $line['quantity'] > (float) $quotedItem['quantity']
                        || (int) $line['stock_item_id'] !== (int) $quotedItem['stock_item_id']
                        || $line['unit'] !== ($quotedItem['unit'] ?: 'ks')) {
                        throw new SalesOrderException('quote_order_scope_changed', 'Rozsah položky objednávky překračuje schválenou nabídku.', 409);
                    }
                    $quoteKeys[$index] = $quoteKey;
                    if (!empty($quotedItem['duration_minutes'])) {
                        $minutes = (int) round((float) $line['quantity'] * 60);
                    }
                }
                $effectivePrice = bcmul(
                    (string) $line['unit_price'],
                    bcdiv(bcsub('100', (string) $line['discount_percent'], 4), '100', 6),
                    6,
                );
                $plan = $this->ossPlanner->planIssuedItem($supplierId, $clientContext, (float) $line['vat_rate_snapshot'], (string) $line['unit'], $today, false);
                if ($plan->isRejected()) throw new SalesOrderException('invoice_tax_review_required', $plan->errorMessage() ?? 'Daňové zařazení vyžaduje kontrolu.', 422);
                $items[] = [
                    'description' => (string) $line['description'],
                    'quantity' => (string) $line['quantity'],
                    'duration_minutes' => $minutes,
                    'unit' => (string) $line['unit'],
                    'unit_price_without_vat' => $effectivePrice,
                    'stock_item_id' => $line['stock_item_id'],
                    'warehouse_id' => $line['warehouse_id'],
                    'order_index' => $index,
                ] + $plan->itemColumns();
            }
            if ($quote !== null && ((int) $order['client_id'] !== (int) $quote['client_id']
                || (int) $order['currency_id'] !== (int) $quote['currency_id']
                || (bool) $order['prices_include_vat'] !== (bool) $quote['prices_include_vat'])) {
                throw new SalesOrderException('quote_order_header_changed', 'Odběratel, měna a režim cen objednávky musí odpovídat nabídce.', 409);
            }
            $invoiceId = $this->invoices->createDraft([
                'project_id' => $quote['project_id'] ?? null,
                'client_id' => (int) $order['client_id'],
                'invoice_type' => 'invoice',
                'issue_date' => $today,
                'tax_date' => $today,
                'due_date' => date('Y-m-d', strtotime('+14 days')),
                'currency_id' => (int) $order['currency_id'],
                'prices_include_vat' => (bool) $order['prices_include_vat'],
                'language' => (string) ($order['customer_snapshot']['language'] ?? 'cs'),
                'supplier_order_number' => (string) $order['order_number'],
                'note_below_items' => 'Objednávka: ' . (string) $order['order_number'],
                'discount_percent' => 0,
                'rounding_mode' => InvoiceRounding::supplierDefault($pdo, $supplierId),
                'items' => $items,
            ], $userId);
            $this->invoices->replaceItems($invoiceId, $items);
            if ($quote !== null) {
                QuoteInvoiceOrigin::writeKeys($pdo, $invoiceId, $quoteKeys);
                $quoteKey = ':sales-order:' . $orderId;
                $pdo->prepare(
                    'INSERT INTO sales_quote_documents (supplier_id, quote_id, version_id, invoice_id, idempotency_key, request_hash)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$supplierId, $quote['id'], $quote['version_id'], $invoiceId, $quoteKey, hash('sha256', $quoteKey)]);
            }
            if ($order['exchange_rate'] !== null) {
                $pdo->prepare('UPDATE invoices SET exchange_rate = ? WHERE supplier_id = ? AND id = ?')
                    ->execute([$order['exchange_rate'], $supplierId, $invoiceId]);
            }
            $this->calculator->recompute($invoiceId);
            $pdo->prepare('INSERT INTO sales_order_invoice_links (supplier_id, order_id, invoice_id) VALUES (?, ?, ?)')
                ->execute([$supplierId, $orderId, $invoiceId]);
            $pdo->prepare(
                'INSERT INTO sales_order_operation_keys (supplier_id, order_id, operation, idempotency_key, result_json)
                 VALUES (?, ?, "invoice", ?, ?)'
            )->execute([$supplierId, $orderId, $idempotencyKey, json_encode(['invoice_id' => $invoiceId], JSON_THROW_ON_ERROR)]);
            $pdo->prepare('UPDATE sales_orders SET row_version = row_version + 1 WHERE supplier_id = ? AND id = ?')
                ->execute([$supplierId, $orderId]);
            $version = (int) $header['row_version'] + 1;
            $this->events->publish(
                $supplierId,
                'sales_order',
                (string) $header['order_uuid'],
                'sales_order.invoice_requested',
                $version,
                [
                    'order_uuid' => (string) $header['order_uuid'],
                    'order_id' => $orderId,
                    'row_version' => $version,
                    'invoice_id' => $invoiceId,
                    'invoice_status' => 'draft',
                ],
                'sales_order:' . $header['order_uuid'] . ':invoice:' . hash('sha256', $idempotencyKey),
            );
            $pdo->commit();
            return $this->invoices->find($invoiceId) ?? throw new \LogicException('Faktura nevznikla.');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function lockQuote(PDO $pdo, int $supplierId, int $orderId): ?array
    {
        if (!$this->db->hasColumn('sales_quote_orders', 'line_origins')) {
            return null;
        }
        $reference = $pdo->prepare('SELECT quote_id, version_id, line_origins FROM sales_quote_orders WHERE supplier_id = ? AND order_id = ?');
        $reference->execute([$supplierId, $orderId]);
        $relation = $reference->fetch(PDO::FETCH_ASSOC);
        if ($relation === false) {
            return null;
        }
        $quotes = new QuoteRepository($this->db);
        $head = $quotes->header($supplierId, (int) $relation['quote_id'], true);
        $version = $quotes->version($head, (int) $relation['version_id']);
        return $quotes->present($head, $version) + ['line_origins' => QuoteRepository::decode($relation['line_origins'])];
    }

    private function approvedItems(array $quote): array
    {
        $items = [];
        foreach ($quote['sections'] as $section) {
            if ($section['kind'] === 'required' || in_array($section['id'], $quote['accepted_section_ids'], true)) {
                $items += array_column($section['items'], null, 'id');
            }
        }
        return $items;
    }
}
