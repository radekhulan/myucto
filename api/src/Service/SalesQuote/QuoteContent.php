<?php

declare(strict_types=1);

namespace MyInvoice\Service\SalesQuote;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Codebook\UnitPrecision;
use MyInvoice\Service\Invoice\InvoiceMath;
use MyInvoice\Service\Invoice\TimeBilling;
use PDO;
use Symfony\Component\Uid\Uuid;

final class QuoteContent
{
    public function __construct(
        private readonly Connection $db,
        private readonly UnitPrecision $units,
    ) {}

    public function normalize(int $supplierId, array $body, array $previous = []): array
    {
        $clientId = self::id($body['client_id'] ?? null, 'Vyberte odběratele.');
        $currencyId = self::id($body['currency_id'] ?? null, 'Vyberte měnu.');
        $projectId = self::optionalId($body['project_id'] ?? null);
        $validUntil = self::string($body['valid_until'] ?? null, 10, 'Zadejte platnost nabídky.');
        if (!IsoDate::isValid($validUntil) || $validUntil < '1000-01-01') {
            throw new QuoteException('quote_date', 'Zadejte platné datum platnosti.');
        }

        $pdo = $this->db->pdo();
        $clientQuery = $pdo->prepare(
            'SELECT c.company_name, c.first_name, c.last_name, c.street, c.city, c.zip, c.ic, c.dic,
                    c.main_email, c.language, c.reverse_charge, co.iso2 AS country,
                    s.is_vat_payer
               FROM clients c JOIN supplier s ON s.id = c.supplier_id
               JOIN countries co ON co.id = c.country_id
              WHERE c.supplier_id = ? AND c.id = ? AND c.is_customer = 1 AND c.archived_at IS NULL'
        );
        $clientQuery->execute([$supplierId, $clientId]);
        $client = $clientQuery->fetch(PDO::FETCH_ASSOC);
        if ($client === false) {
            throw new QuoteException('quote_client', 'Vyberte aktivního odběratele z této firmy.');
        }
        $currencies = $pdo->prepare('SELECT code FROM currencies WHERE supplier_id = ? AND id = ? AND is_active = 1');
        $currencies->execute([$supplierId, $currencyId]);
        $currency = $currencies->fetchColumn();
        if ($currency === false) {
            throw new QuoteException('quote_currency', 'Vyberte aktivní měnu z této firmy.');
        }
        if ($projectId !== null) {
            $project = $pdo->prepare('SELECT 1 FROM projects p JOIN clients c ON c.id = p.client_id WHERE c.supplier_id = ? AND p.id = ? AND p.client_id = ? AND p.archived_at IS NULL');
            $project->execute([$supplierId, $projectId, $clientId]);
            if ($project->fetchColumn() === false) {
                throw new QuoteException('quote_project', 'Zakázka musí patřit zvolenému odběrateli.');
            }
        }

        $sections = $body['sections'] ?? [];
        if (!is_array($sections) || !array_is_list($sections) || count($sections) > 30) {
            throw new QuoteException('quote_sections', 'Nabídka může mít nejvýše 30 sekcí.');
        }
        [$cards, $warehouses, $rates] = $this->references($supplierId, $sections);
        $previousSections = array_column($previous['sections'] ?? [], null, 'id');
        $pricesIncludeVat = ($body['prices_include_vat'] ?? false) === true;
        $reverseCharge = (bool) $client['is_vat_payer'] && (bool) $client['reverse_charge'];
        $language = $body['language'] ?? $client['language'];
        if (!in_array($language, ['cs', 'en'], true)) {
            throw new QuoteException('quote_language', 'Vyberte jazyk nabídky.');
        }

        $seenSections = [];
        $seenItems = [];
        $itemCount = 0;
        $markdownBytes = 0;
        $normalized = [];
        foreach ($sections as $section) {
            if (!is_array($section) || !in_array($section['kind'] ?? null, ['required', 'optional', 'info'], true)) {
                throw new QuoteException('quote_section_kind', 'Vyberte typ sekce.');
            }
            $sectionId = self::stableId($section['id'] ?? null, $previousSections, $seenSections);
            $markdown = self::string($section['markdown'] ?? '', 24000, 'Text sekce je příliš dlouhý.', true);
            $markdownBytes += strlen($markdown);
            if ($markdownBytes > 120000) {
                throw new QuoteException('quote_text', 'Součet textů všech sekcí může mít nejvýše 120 kB.');
            }
            $items = $section['items'] ?? [];
            if (!is_array($items) || !array_is_list($items) || ($itemCount += count($items)) > 300
                || ($section['kind'] === 'info' && $items !== [])) {
                throw new QuoteException('quote_items', 'Nabídka má nejvýše 300 položek. Informační sekce obsahuje pouze text.');
            }
            $priorItems = array_column($previousSections[$sectionId]['items'] ?? [], null, 'id');
            $normalizedItems = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new QuoteException('quote_items', 'Položka nabídky není platná.');
                }
                $id = self::stableId($item['id'] ?? null, $priorItems, $seenItems);
                $normalizedItems[] = $this->item($item, $id, $cards, $warehouses, $rates, (bool) $client['is_vat_payer']);
            }
            if ($this->units->check($normalizedItems) !== []) {
                throw new QuoteException('unit_precision', UnitPrecision::ERROR_MESSAGE);
            }
            $calculated = self::calculate($normalizedItems, $pricesIncludeVat, $reverseCharge);
            foreach ($normalizedItems as $index => &$item) {
                $amounts = $calculated['items'][$index];
                $item['totals'] = ['without_vat' => $amounts['base'], 'vat' => $amounts['vat'], 'with_vat' => $amounts['with']];
            }
            unset($item);
            $normalized[] = [
                'id' => $sectionId,
                'title' => self::string($section['title'] ?? null, 190, 'Zadejte název sekce.'),
                'kind' => $section['kind'],
                'markdown' => $markdown,
                'items' => $normalizedItems,
                'totals' => $calculated['totals'],
            ];
        }

        $client['name'] = trim((string) $client['company_name']) ?: trim($client['first_name'] . ' ' . $client['last_name']);
        unset($client['is_vat_payer'], $client['reverse_charge']);
        return [
            'title' => self::string($body['title'] ?? null, 190, 'Zadejte předmět nabídky.'),
            'client_id' => $clientId, 'client_name' => $client['name'], 'client_snapshot' => $client,
            'project_id' => $projectId, 'currency_id' => $currencyId, 'currency' => $currency,
            'valid_until' => $validUntil, 'language' => $language, 'prices_include_vat' => $pricesIncludeVat,
            'reverse_charge' => $reverseCharge, 'sections' => $normalized,
        ];
    }

    public static function totals(array $data, array $selected = []): array
    {
        $totals = ['without_vat' => 0.0, 'vat' => 0.0, 'with_vat' => 0.0];
        foreach ($data['sections'] as $section) {
            if ($section['kind'] !== 'required' && !in_array($section['id'], $selected, true)) {
                continue;
            }
            foreach ($totals as $key => $value) {
                $totals[$key] = round($value + $section['totals'][$key], 2);
            }
        }
        if ($totals['with_vat'] > 999999999999.99) {
            throw new QuoteException('quote_total', 'Celková cena nabídky je příliš vysoká.');
        }
        return $totals;
    }

    private function item(array $item, string $id, array $cards, array $warehouses, array $rates, bool $vatPayer): array
    {
        $cardId = self::optionalId($item['stock_item_id'] ?? null);
        $warehouseId = self::optionalId($item['warehouse_id'] ?? null);
        $vatId = self::optionalId($item['vat_rate_id'] ?? null);
        $kind = $item['kind'] ?? null;
        if ($cardId !== null) {
            $card = $cards[$cardId] ?? null;
            if ($card === null) {
                throw new QuoteException('quote_stock_item', 'Vyberte aktivní kartu z této firmy.');
            }
            $kind = match ($card['item_type']) {
                'material' => 'material',
                'service' => 'work',
                default => 'product',
            };
            if ($warehouseId !== null && (!(bool) $card['is_stocked'] || !isset($warehouses[$warehouseId]))) {
                throw new QuoteException('quote_warehouse', 'Vyberte aktivní sklad pro skladovanou kartu.');
            }
        } elseif ($warehouseId !== null) {
            throw new QuoteException('quote_warehouse', 'Výběr skladu vyžaduje skladovou kartu.');
        }
        if (!in_array($kind, ['product', 'material', 'work'], true)) {
            throw new QuoteException('quote_item_kind', 'Vyberte druh položky.');
        }
        if ($vatId !== null && !isset($rates[$vatId])) {
            throw new QuoteException('quote_vat', 'Vyberte platnou sazbu DPH.');
        }
        $row = [
            'id' => $id,
            'kind' => $kind,
            'description' => self::string($item['description'] ?? null, 500, 'Zadejte popis položky.'),
            'quantity' => self::number($item['quantity'] ?? null, 0.001, 9999999.999, 3, 'Zadejte kladné množství.'),
            'unit' => self::string($item['unit'] ?? '', 20, 'Jednotka položky je příliš dlouhá.', true),
            'unit_price' => self::number($item['unit_price'] ?? null, 0, 999999999, 6, 'Zadejte platnou jednotkovou cenu.'),
            'discount_percent' => self::number($item['discount_percent'] ?? 0, 0, 100, 4, 'Sleva musí být od 0 do 100 %.'),
            'vat_rate' => $vatPayer ? ($vatId === null ? self::number($item['vat_rate'] ?? 0, 0, 100, 2, 'Zadejte platnou sazbu DPH.') : (float) $rates[$vatId]) : 0.0,
            'vat_rate_id' => $vatPayer ? $vatId : null,
            'stock_item_id' => $cardId,
            'warehouse_id' => $warehouseId,
            'duration_minutes' => null,
        ];
        if (isset($item['duration_minutes'])) {
            if ($kind !== 'work' || $cardId !== null || !TimeBilling::isHourUnit($row['unit'])) {
                throw new QuoteException('quote_duration', 'Trvání lze zadat u hodinové práce bez katalogové karty.');
            }
            $row['duration_minutes'] = (int) self::number($item['duration_minutes'], 1, 599999999, 0, 'Zadejte platnou délku práce.');
            $row['quantity'] = round($row['duration_minutes'] / 60, 3);
        }
        return $row;
    }

    private function references(int $supplierId, array $sections): array
    {
        $cards = $warehouses = $rates = [];
        foreach ($sections as $section) {
            $items = is_array($section) ? ($section['items'] ?? []) : [];
            if (!is_array($items) || count($items) > 300) {
                throw new QuoteException('quote_items', 'Nabídka může mít nejvýše 300 položek.');
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new QuoteException('quote_items', 'Položka nabídky není platná.');
                }
                foreach (['stock_item_id' => &$cards, 'warehouse_id' => &$warehouses, 'vat_rate_id' => &$rates] as $key => &$ids) {
                    $id = self::optionalId($item[$key] ?? null);
                    if ($id !== null) {
                        $ids[$id] = $id;
                    }
                }
                unset($ids);
            }
        }

        $pdo = $this->db->pdo();
        $cardRows = $warehouseRows = $rateRows = [];
        if ($cards !== []) {
            $stmt = $pdo->prepare('SELECT id, item_type, is_stocked FROM stock_items WHERE supplier_id = ? AND is_active = 1 AND id IN (' . self::placeholders($cards) . ')');
            $stmt->execute([$supplierId, ...array_values($cards)]);
            $cardRows = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'id');
        }
        if ($warehouses !== []) {
            $stmt = $pdo->prepare('SELECT id FROM warehouses WHERE supplier_id = ? AND is_active = 1 AND id IN (' . self::placeholders($warehouses) . ')');
            $stmt->execute([$supplierId, ...array_values($warehouses)]);
            $warehouseRows = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
        }
        if ($rates !== []) {
            $stmt = $pdo->prepare('SELECT id, rate_percent FROM vat_rates WHERE id IN (' . self::placeholders($rates) . ')');
            $stmt->execute(array_values($rates));
            $rateRows = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'rate_percent', 'id');
        }
        return [$cardRows, $warehouseRows, $rateRows];
    }

    private static function calculate(array $items, bool $gross, bool $reverseCharge): array
    {
        $lines = array_map(static fn (array $item): array => [
            'quantity' => $item['quantity'],
            'duration_minutes' => $item['duration_minutes'],
            'unit_price_without_vat' => round($item['unit_price'] * (1 - $item['discount_percent'] / 100), 6),
            'vat_rate_snapshot' => $item['vat_rate'],
        ], $items);
        $computed = InvoiceMath::compute($lines, $reverseCharge, $gross);
        if ($computed['totals']['with_vat'] > 999999999999.99) {
            throw new QuoteException('quote_total', 'Celková cena sekce je příliš vysoká.');
        }
        return $computed;
    }

    public static function string(mixed $value, int $max, string $error, bool $canBeEmpty = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max
            || str_contains($value, "\0") || (!$canBeEmpty && trim($value) === '')) {
            throw new QuoteException('quote_text', $error);
        }
        return trim($value);
    }

    private static function number(mixed $value, float $min, float $max, int $decimals, string $error): float
    {
        if (!is_numeric($value)) {
            throw new QuoteException('quote_number', $error);
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < $min || $number > $max || abs(round($number, $decimals) - $number) > 1e-8) {
            throw new QuoteException('quote_number', $error);
        }
        return $number;
    }

    private static function id(mixed $value, string $error): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new QuoteException('quote_reference', $error);
        }
        return $id;
    }

    private static function optionalId(mixed $value): ?int
    {
        return $value === null ? null : self::id($value, 'Odkaz na číselník není platný.');
    }

    private static function stableId(mixed $value, array $previous, array &$seen): string
    {
        $id = is_string($value) && isset($previous[$value]) ? $value : Uuid::v4()->toRfc4122();
        if (isset($seen[$id])) {
            throw new QuoteException('quote_duplicate_row', 'Sekce nebo položka je uvedena vícekrát.');
        }
        $seen[$id] = true;
        return $id;
    }

    private static function placeholders(array $keys): string
    {
        return implode(',', array_fill(0, count($keys), '?'));
    }
}
