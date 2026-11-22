<?php

declare(strict_types=1);

namespace MyInvoice\Service\SalesQuote;

use MyInvoice\Service\Invoice\InvoiceItemKind;
use MyInvoice\Service\Invoice\TimeBilling;
use PDO;

final class QuoteInvoiceOrigin
{
    public static function rows(PDO $pdo, int $invoiceId, bool $lock = false): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, description, sales_quote_item_key, order_index, quantity, duration_minutes, unit, stock_item_id
               FROM invoice_items WHERE invoice_id = ? AND sales_quote_item_key IS NOT NULL' . ($lock ? ' FOR UPDATE' : ''),
        );
        $stmt->execute([$invoiceId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
    }

    /**
     * @param array<int,string> $keys mapování pořadí řádku na položku nabídky
     */
    public static function writeKeys(PDO $pdo, int $invoiceId, array $keys): void
    {
        if ($keys === []) {
            return;
        }
        $cases = [];
        $params = [];
        foreach ($keys as $order => $key) {
            $cases[] = 'WHEN ? THEN ?';
            array_push($params, $order, $key);
        }
        $orders = implode(', ', array_fill(0, count($keys), '?'));
        $case = implode(' ', $cases);
        $stmt = $pdo->prepare(
            "UPDATE invoice_items SET sales_quote_item_key = CASE order_index {$case} END
              WHERE invoice_id = ? AND item_kind = 'standard' AND order_index IN ({$orders})",
        );
        $stmt->execute([...$params, $invoiceId, ...array_keys($keys)]);
    }

    public static function keepKeys(array $current, array $next, array $existingIds): array
    {
        if ($current === []) {
            return [];
        }

        $currentByOrder = array_column($current, null, 'order_index');
        $providedIds = array_fill_keys(array_column($next, 'id'), true);
        $existing = array_fill_keys($existingIds, true);
        $used = [];
        $orders = [];
        $keys = [];
        foreach (array_values($next) as $index => $item) {
            $id = (int) ($item['id'] ?? 0);
            $order = (int) ($item['order_index'] ?? $index);
            $orders[$order] = ($orders[$order] ?? 0) + 1;
            if ($id > 0 && !isset($existing[$id])) {
                throw new \InvalidArgumentException('Položky faktury se mezitím změnily. Otevřete ji znovu.');
            }
            $old = $current[$id] ?? null;
            if ($old === null && !array_key_exists('id', $item) && !InvoiceItemKind::isText($item)) {
                $candidate = $currentByOrder[$order] ?? null;
                if ($candidate !== null && !isset($providedIds[$candidate['id']])) {
                    if ((string) $candidate['description'] !== (string) ($item['description'] ?? '')
                        || self::quantity($candidate) !== self::quantity($item)) {
                        throw new \InvalidArgumentException('Položky převzaté z nabídky upravte podle jejich ID.');
                    }
                    $old = $candidate;
                    $id = (int) $candidate['id'];
                }
            }
            if ($old === null) {
                continue;
            }
            if (isset($used[$id]) || InvoiceItemKind::isText($item) || ($item['item_kind'] ?? InvoiceItemKind::STANDARD) !== InvoiceItemKind::STANDARD) {
                throw new \InvalidArgumentException('Převzatý řádek nabídky musí být na faktuře právě jednou jako cenová položka.');
            }
            if ((string) ($item['unit'] ?? '') !== (string) $old['unit'] || (int) ($item['stock_item_id'] ?? 0) !== (int) $old['stock_item_id']) {
                throw new \InvalidArgumentException('Měrnou jednotku ani skladovou kartu převzaté položky nelze změnit.');
            }
            if (abs(self::quantity($item)) > abs(self::quantity($old)) || self::quantity($item) * self::quantity($old) < 0) {
                throw new \InvalidArgumentException('Další množství převezměte z nabídky do samostatného dokladu.');
            }
            if ((float) ($item['unit_price_without_vat'] ?? 0) < 0) {
                throw new \InvalidArgumentException('Převzatá položka nabídky nemůže mít zápornou jednotkovou cenu.');
            }
            $used[$id] = true;
            $keys[$order] = (string) $old['sales_quote_item_key'];
        }

        foreach (array_keys($keys) as $order) {
            if ($orders[$order] !== 1) {
                throw new \InvalidArgumentException('Převzatý řádek nabídky musí mít jedinečné pořadí.');
            }
        }
        return $keys;
    }

    private static function quantity(array $item): float
    {
        $duration = TimeBilling::durationMinutes($item);
        return $duration === null ? round((float) ($item['quantity'] ?? 0), 3) : $duration / 60;
    }
}
