<?php

declare(strict_types=1);

namespace MyInvoice\Service\SalesQuote;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Stock\SalesOrderException;
use MyInvoice\Service\Stock\SalesOrderService;
use PDO;
use Symfony\Component\Uid\Uuid;

final class QuoteOrderService
{
    public function __construct(
        private readonly Connection $db,
        private readonly QuoteRepository $quotes,
        private readonly QuoteInvoiceService $invoices,
        private readonly SalesOrderService $orders,
    ) {}

    public function convert(int $supplierId, int $id, int $userId, array $body): int
    {
        $key = QuoteContent::string($body['idempotency_key'] ?? null, 128, 'Chybí identifikátor převodu.');
        return $this->quotes->transaction(function () use ($supplierId, $id, $userId, $key): int {
            $header = $this->quotes->header($supplierId, $id, true);
            if ($header['status'] !== 'accepted') {
                throw new QuoteException('quote_order_state', 'Objednávku lze založit jen z přijaté nabídky.', 409);
            }
            $pdo = $this->db->pdo();
            $existing = $pdo->prepare(
                "SELECT l.order_id FROM sales_quote_orders l JOIN sales_orders o ON o.id = l.order_id AND o.supplier_id = l.supplier_id
                  WHERE l.supplier_id = ? AND l.quote_id = ? AND (l.idempotency_key = ? OR o.commercial_status <> 'cancelled')
                  ORDER BY l.idempotency_key = ? DESC, l.order_id DESC LIMIT 1"
            );
            $existing->execute([$supplierId, $id, $key, $key]);
            $orderId = $existing->fetchColumn();
            if ($orderId !== false) {
                return (int) $orderId;
            }

            $version = $this->quotes->version($header);
            $quote = $this->quotes->present($header, $version);
            if ($quote['reverse_charge']) {
                throw new QuoteException('quote_order_reverse_charge', 'Nabídku v režimu přenesení daňové povinnosti převeďte přímo do konceptu faktury.');
            }
            $summary = $this->invoices->summary($supplierId, $id, $quote);
            foreach ($summary['documents'] as $document) {
                if ($document['type'] === 'invoice' && !in_array($document['status'], ['cancelled', 'storno'], true)) {
                    throw new QuoteException('quote_order_billed', 'K nabídce již existuje koncept nebo vystavená faktura.', 409);
                }
            }
            $warehouses = $pdo->prepare(
                'SELECT id FROM warehouses WHERE supplier_id = ? AND is_active = 1 AND is_sellable = 1
                 ORDER BY is_default DESC, id LIMIT 1'
            );
            $warehouses->execute([$supplierId]);
            $warehouse = $warehouses->fetchColumn();
            $acceptedItems = [];
            foreach ($quote['sections'] as $section) {
                if ($section['kind'] === 'required' || in_array($section['id'], $quote['accepted_section_ids'], true)) {
                    array_push($acceptedItems, ...$section['items']);
                }
            }
            if ($acceptedItems === []) {
                throw new QuoteException('quote_order_empty', 'Schválená nabídka nemá žádné položky k objednání.');
            }
            $cardIds = array_values(array_unique(array_filter(array_column($acceptedItems, 'stock_item_id'))));
            $stocked = [];
            if ($cardIds !== []) {
                $stock = $pdo->prepare(
                    'SELECT id, is_stocked FROM stock_items WHERE supplier_id = ? AND is_active = 1 AND id IN ('
                    . implode(', ', array_fill(0, count($cardIds), '?')) . ')'
                );
                $stock->execute([$supplierId, ...$cardIds]);
                $stocked = array_column($stock->fetchAll(PDO::FETCH_ASSOC), 'is_stocked', 'id');
            }
            $rates = $pdo->query(
                "SELECT id, rate_percent FROM vat_rates WHERE country = 'CZ' AND is_reverse_charge = 0
                   AND valid_from <= CURRENT_DATE AND (valid_to IS NULL OR valid_to >= CURRENT_DATE) ORDER BY is_default DESC, id"
            )->fetchAll(PDO::FETCH_ASSOC);
            $rateIds = [];
            foreach ($rates as $rate) {
                $rateIds[(string) (float) $rate['rate_percent']] ??= (int) $rate['id'];
            }
            $lines = [];
            $origins = [];
            foreach ($acceptedItems as $item) {
                $uuid = Uuid::v4()->toRfc4122();
                $origins[$uuid] = $item['id'];
                $warehouseId = $item['warehouse_id'] ?? (!empty($stocked[$item['stock_item_id'] ?? 0]) ? $warehouse : null);
                $lines[] = [
                    'line_uuid' => $uuid,
                    'description' => $item['description'],
                    'quantity' => (string) $item['quantity'],
                    'unit' => $item['unit'],
                    'unit_price' => (string) $item['unit_price'],
                    'discount_percent' => (string) $item['discount_percent'],
                    'vat_rate_id' => $item['vat_rate_id'] ?? $rateIds[(string) (float) $item['vat_rate']] ?? null,
                    'stock_item_id' => $item['stock_item_id'],
                    'warehouse_id' => $warehouseId ?: null,
                ];
            }
            try {
                $order = $this->orders->create($supplierId, [
                    'client_id' => $quote['client_id'],
                    'currency_id' => $quote['currency_id'],
                    'prices_include_vat' => $quote['prices_include_vat'],
                    'lines' => $lines,
                ], $userId, ownsTransaction: false);
            } catch (SalesOrderException $e) {
                throw new QuoteException($e->errorCode, $e->getMessage(), $e->httpStatus);
            }
            $orderId = (int) $order['id'];
            $pdo->prepare('INSERT INTO sales_quote_orders (supplier_id, quote_id, version_id, order_id, idempotency_key, line_origins) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$supplierId, $id, $version['id'], $orderId, $key, QuoteRepository::encode($origins)]);
            $this->quotes->event($supplierId, $id, (int) $version['id'], $userId, 'order', ['order_id' => $orderId]);
            return $orderId;
        });
    }

    public function currentOrder(int $supplierId, int $id): ?int
    {
        $query = $this->db->pdo()->prepare(
            "SELECT l.order_id FROM sales_quote_orders l JOIN sales_orders o ON o.id = l.order_id AND o.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.quote_id = ? AND o.commercial_status <> 'cancelled'
              ORDER BY l.order_id DESC LIMIT 1"
        );
        $query->execute([$supplierId, $id]);
        $orderId = $query->fetchColumn();
        return $orderId === false ? null : (int) $orderId;
    }
}
