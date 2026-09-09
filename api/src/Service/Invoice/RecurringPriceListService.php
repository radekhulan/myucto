<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;

final class RecurringPriceListService
{
    private const POLICIES = ['fixed', 'current', 'review_required'];
    private const DESCRIPTION_SOURCES = ['catalog', 'template'];

    public function __construct(
        private readonly PriceListItemResolver $resolver,
        private readonly Connection $db,
    ) {}

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public function prepareForSave(
        array $items,
        int $supplierId,
        int $clientId,
        int $currencyId,
        bool $pricesIncludeVat,
        DateTimeImmutable $referenceDate,
        bool $newTemplate,
    ): array {
        $bad = (new \MyInvoice\Http\TenantReferenceGuard($this->db))->itemViolations($supplierId, $items, ['price_list_item_id']);
        if ($bad !== []) {
            throw new PriceListResolutionException('invalid_reference', \MyInvoice\Http\TenantReferenceGuard::message($bad));
        }
        $normalized = [];
        $idsToResolve = [];
        foreach (array_values($items) as $index => $item) {
            $row = $this->normalizeItem($item, $index);
            $normalized[] = $row;
            if ($row['price_list_item_id'] === null) continue;

            $needsCanonicalValues = $newTemplate
                || !empty($item['accept_catalog_changes'])
                || empty($row['catalog_price_source'])
                || $row['catalog_policy'] === 'current';
            if ($needsCanonicalValues) $idsToResolve[] = $row['price_list_item_id'];
        }

        if (!$this->isAvailable($supplierId)) {
            foreach ($normalized as &$row) {
                if ($row['price_list_item_id'] === null) continue;
                $row['price_list_item_id'] = null;
                $row['catalog_policy'] = 'fixed';
                $row['description_source'] = 'template';
                $row['catalog_price_source'] = null;
                $row['catalog_source_currency_code'] = null;
                $row['catalog_source_unit_price'] = null;
                $row['catalog_exchange_rate'] = null;
                $row['catalog_exchange_rate_date'] = null;
            }
            unset($row);
            return $normalized;
        }

        $resolved = $idsToResolve === [] ? [] : $this->resolver->resolveMany(
            $idsToResolve,
            $supplierId,
            $clientId,
            $currencyId,
            $pricesIncludeVat,
            $referenceDate,
        );

        foreach ($normalized as &$row) {
            $id = $row['price_list_item_id'];
            if ($id === null || !isset($resolved[$id])) continue;
            $row = $this->applyResolved($row, $resolved[$id]);
        }
        unset($row);
        return $normalized;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public function resolveForGeneration(
        array $items,
        int $supplierId,
        int $clientId,
        int $currencyId,
        bool $pricesIncludeVat,
        DateTimeImmutable $referenceDate,
    ): array {
        if (!$this->isAvailable($supplierId)) {
            return array_map(function (array $item, int $index): array {
                $row = $this->normalizeItem($item, $index);
                $row['catalog_policy'] = 'fixed';
                return $row;
            }, array_values($items), array_keys(array_values($items)));
        }

        $ids = [];
        foreach ($items as $item) {
            if (!empty($item['price_list_item_id']) && ($item['catalog_policy'] ?? 'fixed') !== 'fixed') {
                $ids[] = (int) $item['price_list_item_id'];
            }
        }
        $resolved = $ids === [] ? [] : $this->resolver->resolveMany(
            $ids,
            $supplierId,
            $clientId,
            $currencyId,
            $pricesIncludeVat,
            $referenceDate,
        );

        $out = [];
        foreach (array_values($items) as $index => $item) {
            $row = $this->normalizeItem($item, $index);
            $id = $row['price_list_item_id'];
            if ($id === null || $row['catalog_policy'] === 'fixed') {
                $out[] = $row;
                continue;
            }

            $current = $resolved[$id];
            if ($row['catalog_policy'] === 'review_required') {
                $this->assertApprovedSnapshot($row, $current);
            }
            $out[] = $this->applyResolved($row, $current);
        }
        return $out;
    }

    /** @param array<string,mixed> $item */
    private function normalizeItem(array $item, int $index): array
    {
        $priceListItemId = !empty($item['price_list_item_id']) ? (int) $item['price_list_item_id'] : null;
        $policy = (string) ($item['catalog_policy'] ?? 'fixed');
        if (!in_array($policy, self::POLICIES, true)) {
            throw new PriceListResolutionException('invalid_catalog_policy', 'Neplatná politika ceníkové položky.', $priceListItemId);
        }
        $descriptionSource = (string) ($item['description_source'] ?? 'template');
        if (!in_array($descriptionSource, self::DESCRIPTION_SOURCES, true)) {
            throw new PriceListResolutionException('invalid_description_source', 'Neplatný zdroj popisu ceníkové položky.', $priceListItemId);
        }

        if ($priceListItemId === null) {
            $policy = 'fixed';
            $descriptionSource = 'template';
        }

        return [
            ...$item,
            'price_list_item_id' => $priceListItemId,
            'catalog_policy' => $policy,
            'description_source' => $descriptionSource,
            'catalog_price_source' => $priceListItemId !== null ? ($item['catalog_price_source'] ?? null) : null,
            'catalog_source_currency_code' => $priceListItemId !== null ? ($item['catalog_source_currency_code'] ?? null) : null,
            'catalog_source_unit_price' => $priceListItemId !== null && isset($item['catalog_source_unit_price'])
                ? (float) $item['catalog_source_unit_price'] : null,
            'catalog_exchange_rate' => $priceListItemId !== null && isset($item['catalog_exchange_rate'])
                ? (float) $item['catalog_exchange_rate'] : null,
            'catalog_exchange_rate_date' => $priceListItemId !== null ? ($item['catalog_exchange_rate_date'] ?? null) : null,
            'description' => (string) ($item['description'] ?? ''),
            'quantity' => (float) ($item['quantity'] ?? 1),
            'unit' => (string) ($item['unit'] ?? 'ks'),
            'unit_price_without_vat' => (float) ($item['unit_price_without_vat'] ?? 0),
            'vat_rate_id' => (int) ($item['vat_rate_id'] ?? 0),
            'order_index' => (int) ($item['order_index'] ?? $index),
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $resolved */
    private function applyResolved(array $row, array $resolved): array
    {
        if ($row['description_source'] === 'catalog') {
            $row['description'] = (string) $resolved['description'];
        }
        $row['unit'] = (string) $resolved['unit'];
        $row['unit_price_without_vat'] = (float) $resolved['unit_price_without_vat'];
        $row['vat_rate_id'] = (int) $resolved['vat_rate_id'];
        $row['catalog_price_source'] = (string) $resolved['catalog_price_source'];
        $row['catalog_source_currency_code'] = (string) $resolved['catalog_source_currency_code'];
        $row['catalog_source_unit_price'] = (float) $resolved['catalog_source_unit_price'];
        $row['catalog_exchange_rate'] = $resolved['catalog_exchange_rate'];
        $row['catalog_exchange_rate_date'] = $resolved['catalog_exchange_rate_date'];
        return $row;
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $current */
    private function assertApprovedSnapshot(array $snapshot, array $current): void
    {
        $changed = (string) ($snapshot['catalog_price_source'] ?? '') !== (string) $current['catalog_price_source']
            || (string) ($snapshot['catalog_source_currency_code'] ?? '') !== (string) $current['catalog_source_currency_code']
            || !$this->sameMoney($snapshot['catalog_source_unit_price'] ?? null, $current['catalog_source_unit_price'])
            || (string) $snapshot['unit'] !== (string) $current['unit']
            || (int) $snapshot['vat_rate_id'] !== (int) $current['vat_rate_id'];

        if ($snapshot['description_source'] === 'catalog') {
            $changed = $changed || (string) $snapshot['description'] !== (string) $current['description'];
        }
        if ($changed) {
            throw new PriceListResolutionException(
                'price_list_review_required',
                'Ceníková položka se změnila a vyžaduje kontrolu šablony.',
                (int) $snapshot['price_list_item_id'],
            );
        }
    }

    private function sameMoney(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) return $left === $right;
        return abs((float) $left - (float) $right) < 0.005;
    }

    private function isAvailable(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT stock_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $stockEnabled = $stmt->fetchColumn();
        return $stockEnabled !== false && (int) $stockEnabled !== 1;
    }
}
