<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Integration\IntegrationEventPublisher;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Oss\OssItemPlanner;
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
            $order = $this->orders->detail($supplierId, $orderId) ?? throw new SalesOrderException('not_found', 'Objednávka nenalezena.', 404);
            $today = date('Y-m-d');
            $clientContext = $this->ossPlanner->clientContext((int) $order['client_id'], $order['customer_snapshot']);
            $items = [];
            foreach ($order['lines'] as $index => $line) {
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
                    'unit' => (string) $line['unit'],
                    'unit_price_without_vat' => $effectivePrice,
                    'stock_item_id' => $line['stock_item_id'],
                    'warehouse_id' => $line['warehouse_id'],
                    'order_index' => $index,
                ] + $plan->itemColumns();
            }
            $invoiceId = $this->invoices->createDraft([
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
                'items' => $items,
            ], $userId);
            $this->invoices->replaceItems($invoiceId, $items);
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
}
