<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemCustomerPriceRepository;
use MyInvoice\Service\Eshop\Pricing\EffectivePriceResolver;
use MyInvoice\Service\Invoice\UnitPricePrecision;
use PDO;

/**
 * Nacenění skladových řádků dokladu (issue #17) — editor faktury posílá kartu,
 * jednotku a množství a dostane zpátky cenu za ZVOLENOU jednotku.
 *
 * Postup: jednotka → poměr ({@see StockUnitConverter}), množství → základní
 * jednotky, cena za základní jednotku z {@see EffectivePriceResolver} (zákaznická
 * cena, akce se stropem v základních jednotkách) a nakonec cena za zvolenou
 * jednotku = cena za základní jednotku × poměr na haléře. Vlastní cenová
 * logika tu žádná není.
 */
final class StockItemQuoteService
{
    private const MAX_LINES = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly StockUnitConverter $units,
        private readonly EffectivePriceResolver $resolver,
        private readonly StockItemCustomerPriceRepository $customerPrices,
        private readonly StockPriceLevelService $priceLevels,
    ) {}

    /**
     * @param array<string,mixed> $body {client_id, price_level_id?, currency, date, lines: list<{key, stock_item_id, unit, quantity}>}
     * @return array{lines: list<array<string,mixed>>}
     */
    public function quote(int $supplierId, array $body): array
    {
        $clientId = isset($body['client_id']) && $body['client_id'] !== null && $body['client_id'] !== ''
            ? (int) $body['client_id'] : null;
        if ($clientId !== null && ($clientId <= 0 || $this->customerPrices->clientsOfSupplier($supplierId, [$clientId]) === [])) {
            throw new StockException('invalid_client', 'Odběratel nepatří této firmě.', 422, ['client_id' => $body['client_id']]);
        }
        // Hladina zvolená na dokladu přepíše hladinu odběratele. Stejné pravidlo jako při
        // uložení dokladu: musí patřit firmě a být aktivní (nacenit se neaktivní nedá).
        $priceLevelId = $this->priceLevels->assignableLevelId($supplierId, $body['price_level_id'] ?? null, null);
        $currency = strtoupper(trim((string) ($body['currency'] ?? 'CZK')));
        if ($currency === '') {
            $currency = 'CZK';
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new StockException('validation_failed', 'Neplatný kód měny (ISO 4217, 3 znaky).', 422, ['field' => 'currency']);
        }
        $date = trim((string) ($body['date'] ?? ''));
        if ($date === '') {
            $date = date('Y-m-d');
        } else {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($d === false || $d->format('Y-m-d') !== $date) {
                throw new StockException('validation_failed', 'Neplatné datum (formát RRRR-MM-DD).', 422, ['field' => 'date']);
            }
        }
        $raw = $body['lines'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::MAX_LINES) {
            throw new StockException('validation_failed', 'lines musí být seznam nejvýše 500 řádků.', 422, ['field' => 'lines']);
        }

        $lines = [];
        foreach ($raw as $index => $line) {
            if (!is_array($line)) {
                throw new StockException('validation_failed', 'Neplatný řádek.', 422, ['index' => $index]);
            }
            $qty = str_replace(',', '.', trim((string) ($line['quantity'] ?? '')));
            if (preg_match('/^-?[0-9]{1,10}(\.[0-9]{1,3})?$/D', $qty) !== 1) {
                throw new StockException('validation_failed', 'Množství musí být číslo s nejvýše třemi desetinnými místy.', 422, ['index' => $index, 'key' => (string) ($line['key'] ?? '')]);
            }
            $lines[$index] = [
                'key'           => (string) ($line['key'] ?? (string) $index),
                'stock_item_id' => (int) ($line['stock_item_id'] ?? 0),
                'unit'          => isset($line['unit']) && $line['unit'] !== null ? (string) $line['unit'] : null,
                'quantity'      => $qty,
            ];
        }
        if ($lines === []) {
            return ['lines' => []];
        }

        $known = $this->existingItems($supplierId, array_column($lines, 'stock_item_id'));
        foreach ($lines as $index => $line) {
            if (!isset($known[$line['stock_item_id']])) {
                throw new StockException('invalid_stock_item', 'Skladová karta nenalezena.', 422, ['index' => $index, 'key' => $line['key']]);
            }
        }

        $ratios = $this->units->ratios($supplierId, $lines);
        $baseQty = [];
        foreach ($lines as $index => $line) {
            $baseQty[$index] = StockUnitConverter::applyRatio($line['quantity'], $ratios[$index]['numerator'], $ratios[$index]['denominator']);
        }

        // Resolver bere množství per karta; víc řádků téže karty (různé jednotky
        // nebo množství) se proto nacení v samostatných dávkách.
        $prices = [];
        $buckets = [];
        $occurrence = [];
        foreach ($lines as $index => $line) {
            $n = $occurrence[$line['stock_item_id']] = ($occurrence[$line['stock_item_id']] ?? -1) + 1;
            $buckets[$n][$index] = $line['stock_item_id'];
        }
        foreach ($buckets as $bucket) {
            $qtyMap = [];
            foreach ($bucket as $index => $itemId) {
                $qtyMap[$itemId] = ltrim($baseQty[$index], '-');
            }
            $resolved = $this->resolver->resolveMany($supplierId, array_values($bucket), $currency, $qtyMap, $date, $clientId, $priceLevelId);
            foreach ($bucket as $index => $itemId) {
                $prices[$index] = $resolved[$itemId] ?? null;
            }
        }

        $out = [];
        foreach ($lines as $index => $line) {
            $ratio = $ratios[$index];
            $p = $prices[$index];
            // Karta bez ceny v požadované měně → unit_price i base_unit_price jsou
            // null, NIKDY 0: editor dokladu pak ponechá řádku dosavadní cenu.
            $basePrice = $p['unit_price'] ?? null;
            // Ceníková cena = standardní cena karty před zákaznickou cenou, hladinou
            // i akcí. Resolver ji vrací jako `standard_price` jen tehdy, když cenu
            // určil odběratel nebo hladina, jinak je standardní cena v `base_price`.
            $listBase = $p === null ? null : (array_key_exists('standard_price', $p) ? $p['standard_price'] : ($p['base_price'] ?? null));
            $isBase = $ratio['numerator'] === $ratio['denominator'];
            $out[] = [
                'key'                 => $line['key'],
                'stock_item_id'       => $line['stock_item_id'],
                'unit'                => $ratio['unit'],
                'base_unit'           => $ratio['base_unit'],
                'numerator'           => $ratio['numerator'],
                'denominator'         => $ratio['denominator'],
                'base_quantity'       => $baseQty[$index],
                // V základní jednotce přesně řetězec resolveru — stejný jako
                // `effective_price` našeptávače (bez klienta a balení beze změny).
                'unit_price'          => $basePrice === null ? null : ($isBase
                    ? (string) $basePrice
                    : self::unitPrice(StockUnitConverter::applyRatio((string) $basePrice, $ratio['numerator'], $ratio['denominator'], UnitPricePrecision::DECIMALS))),
                'base_unit_price'     => $basePrice !== null ? (string) $basePrice : null,
                // Cena za zvolenou jednotku před slevou (migrace 2035), přepočtená stejně
                // jako unit_price. Editor ji uloží k řádku jako `list_unit_price`.
                'list_unit_price'     => $listBase === null ? null : ($isBase
                    ? (string) $listBase
                    : self::unitPrice(StockUnitConverter::applyRatio((string) $listBase, $ratio['numerator'], $ratio['denominator'], UnitPricePrecision::DECIMALS))),
                'price_source'        => $p['price_source'] ?? (($p['promo_applied'] ?? false) ? 'promo' : 'standard'),
                // Cenová hladina odběratele (1833), jen když cenu určila.
                'price_level'         => $p['price_level'] ?? null,
                'customer_price_id'   => $p['customer_price_id'] ?? null,
                'discount_pct'        => match ($p['price_source'] ?? '') {
                    'customer_discount'    => $p['customer_price']['discount_pct'],
                    'price_level_discount' => $p['discount_pct'],
                    default                => null,
                },
                'promo'               => ($p !== null && $p['promo_applied'])
                    ? ['label' => $p['promo']['label'], 'promo_price' => $p['promo']['promo_price']]
                    : null,
                'promo_reason'        => $p['promo_reason'] ?? 'none',
                'promo_qty_available' => $p['promo_qty_available'] ?? null,
            ];
        }
        return ['lines' => $out];
    }

    private static function unitPrice(string $price): string
    {
        return (string) UnitPricePrecision::toDecimalString($price);
    }

    /**
     * @param list<int> $ids
     * @return array<int,true>
     */
    private function existingItems(int $supplierId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM stock_items WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute(array_merge([$supplierId], $ids));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $out[(int) $id] = true;
        }
        return $out;
    }
}
