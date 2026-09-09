<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemCategoryRepository;
use MyInvoice\Repository\StockItemFeeRepository;
use MyInvoice\Repository\StockItemI18nRepository;
use MyInvoice\Repository\StockItemRepository;
use MyInvoice\Repository\StockItemTagRepository;
use MyInvoice\Repository\StockLocaleRepository;
use MyInvoice\Repository\StockMediaRepository;
use MyInvoice\Repository\StockAttributeValueRepository;
use MyInvoice\Service\Eshop\Pricing\PriceRecomputeDispatcher;

/**
 * Agregát karty Zboží pro eshop (Epic ESHOP) — read model + transakční write
 * všech satelitů nad stock_items. Média se řeší zvlášť (upload, ProductMediaAction).
 *
 * Write je all-or-nothing v jedné transakci: eshopové sloupce stock_items +
 * i18n + kategorie (M:N, max 1 primary) + tagy + parametry + poplatky. Každá
 * vazba se ověří na vlastnictví tenantem (guard proti cross-tenant připojení).
 */
final class ProductCardService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockItemRepository $items,
        private readonly StockItemI18nRepository $i18n,
        private readonly StockLocaleRepository $locales,
        private readonly StockItemCategoryRepository $itemCategories,
        private readonly StockItemTagRepository $itemTags,
        private readonly StockItemFeeRepository $itemFees,
        private readonly StockAttributeValueRepository $attributeValues,
        private readonly StockMediaRepository $media,
        private readonly AttributeValueService $attributeService,
        private readonly PriceRecomputeDispatcher $priceDispatcher,
    ) {}

    /**
     * Plná karta jako agregát. Vrací i satelity; is_stocked=0 karty prostě
     * nemají stock_levels — čtení to snese (satelity na stavu skladu nezávisí).
     * @return array<string,mixed>|null
     */
    public function get(int $supplierId, int $id): ?array
    {
        $base = $this->items->find($supplierId, $id);
        if ($base === null) {
            return null;
        }
        $base['i18n']        = $this->i18n->listForItem($supplierId, $id);
        $base['categories']  = $this->itemCategories->listForItem($supplierId, $id);
        $base['tag_ids']     = $this->itemTags->tagIdsForItem($supplierId, $id);
        $base['attributes']  = $this->attributeValues->listForItem($supplierId, $id);
        $base['fees']        = $this->itemFees->listForItem($supplierId, $id);
        $base['media']       = $this->media->listForItem($supplierId, $id);
        return $base;
    }

    /**
     * Transakční zápis eshop obsahu karty. Klíče v $payload jsou volitelné —
     * zapisují se jen přítomné sekce (partial update agregátu).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed> aktualizovaná karta (agregát)
     */
    public function update(int $supplierId, int $id, array $payload): array
    {
        $base = $this->items->find($supplierId, $id);
        if ($base === null) {
            throw new EshopException('not_found', 'Karta zboží nenalezena.', 404);
        }

        $expectedVersion = (int) ($payload['row_version'] ?? 0);
        if ($expectedVersion <= 0) {
            throw new EshopException('version_required', 'Pro uložení je nutná verze karty.', 400);
        }
        $prepared = $this->prepareWrite($supplierId, $base, $payload);

        $this->tx(function () use ($supplierId, $id, $payload, $expectedVersion, $prepared): void {
            if (!$this->items->updateEshopFieldsVersioned(
                $supplierId,
                $id,
                $expectedVersion,
                $prepared['eshop_fields'],
            )) {
                throw new EshopException(
                    'version_conflict',
                    'Kartu mezitím změnil jiný uživatel. Načtěte aktuální data.',
                    409,
                );
            }
            $this->writeSatellites($supplierId, $id, $payload, $prepared);
            if ($prepared['pricing_base_changed']) {
                $this->priceDispatcher->recomputeItem($supplierId, $id);
            }
        });

        return $this->get($supplierId, $id) ?? [];
    }

    /**
     * Zápis produktového obsahu uvnitř transakce editoru. Verzi zvyšuje
     * koordinující ProductEditorService společně se základními poli.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $payload
     */
    public function updateForEditor(int $supplierId, int $id, array $base, array $payload): bool
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new \LogicException('Editor produktu musí zapisovat v aktivní transakci.');
        }
        $prepared = $this->prepareWrite($supplierId, $base, $payload);
        $this->items->updateEshopFields($supplierId, $id, array_replace([
            'manufacturer_id' => $base['manufacturer_id'] ?? null,
            'warranty_months' => $base['warranty_months'] ?? null,
            'delivery_days' => $base['delivery_days'] ?? null,
            'export_eshop' => (bool) ($base['export_eshop'] ?? false),
            'is_stocked' => (bool) ($base['is_stocked'] ?? true),
            'weight_g' => $base['weight_g'] ?? null,
            'pricing_base' => (string) ($base['pricing_base'] ?? 'weighted_avg'),
        ], $prepared['eshop_fields']));
        $this->writeSatellites($supplierId, $id, $payload, $prepared);
        return $prepared['pricing_base_changed'];
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $payload
     * @return array{eshop_fields:array<string,mixed>,categories:?array,tag_ids:?array,fees:?array,pricing_base_changed:bool}
     */
    private function prepareWrite(int $supplierId, array $base, array $payload): array
    {
        $eshopFields = [];
        if (array_key_exists('manufacturer_id', $payload)) {
            $manufacturerId = $this->intOrNull($payload['manufacturer_id']);
            if ($manufacturerId !== null && !$this->items->manufacturerOwned($supplierId, $manufacturerId)) {
                throw new EshopException('manufacturer_invalid', 'Zvolený výrobce neexistuje.', 422);
            }
            $eshopFields['manufacturer_id'] = $manufacturerId;
        }
        foreach (['warranty_months', 'delivery_days', 'weight_g'] as $field) {
            if (array_key_exists($field, $payload)) {
                $eshopFields[$field] = $this->intOrNull($payload[$field]);
            }
        }
        foreach (['export_eshop', 'is_stocked'] as $field) {
            if (array_key_exists($field, $payload)) {
                $eshopFields[$field] = (bool) $payload[$field];
            }
        }
        $oldPricingBase = (string) ($base['pricing_base'] ?? 'weighted_avg');
        if (array_key_exists('pricing_base', $payload)) {
            $eshopFields['pricing_base'] = $this->pricingBase($payload['pricing_base']);
        }

        return [
            'eshop_fields' => $eshopFields,
            'categories' => $this->prepareCategories($supplierId, $payload),
            'tag_ids' => $this->prepareTags($supplierId, $payload),
            'fees' => $this->prepareFees($supplierId, $payload),
            'pricing_base_changed' => isset($eshopFields['pricing_base'])
                && $eshopFields['pricing_base'] !== $oldPricingBase,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array{categories:?array,tag_ids:?array,fees:?array} $prepared
     */
    private function writeSatellites(int $supplierId, int $id, array $payload, array $prepared): void
    {
        if (array_key_exists('i18n', $payload) && is_array($payload['i18n'])) {
            $this->replaceI18n($supplierId, $id, $payload['i18n']);
        }
        if ($prepared['categories'] !== null) {
            $this->itemCategories->deleteForItem($supplierId, $id);
            foreach ($prepared['categories'] as $category) {
                $this->itemCategories->add(
                    $supplierId,
                    $id,
                    $category['category_id'],
                    $category['is_primary'],
                    $category['display_order'],
                );
            }
        }
        if ($prepared['tag_ids'] !== null) {
            $this->itemTags->deleteForItem($supplierId, $id);
            foreach ($prepared['tag_ids'] as $tagId) {
                $this->itemTags->add($supplierId, $id, $tagId);
            }
        }
        if (array_key_exists('attributes', $payload) && is_array($payload['attributes'])) {
            $this->attributeService->replaceForItem($supplierId, $id, $payload['attributes']);
        }
        if ($prepared['fees'] !== null) {
            $this->itemFees->deleteForItem($supplierId, $id);
            foreach ($prepared['fees'] as $fee) {
                $this->itemFees->add($supplierId, $id, $fee);
            }
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array{category_id:int, is_primary:bool, display_order:int}>|null
     */
    private function prepareCategories(int $supplierId, array $payload): ?array
    {
        if (!array_key_exists('categories', $payload) || !is_array($payload['categories'])) {
            return null;
        }
        $wanted = [];
        $ids = [];
        foreach ($payload['categories'] as $i => $c) {
            $cid = (int) (is_array($c) ? ($c['category_id'] ?? 0) : $c);
            if ($cid <= 0) {
                continue;
            }
            $ids[] = $cid;
            $wanted[$cid] = [
                'category_id'   => $cid,
                'is_primary'    => is_array($c) ? (bool) ($c['is_primary'] ?? false) : false,
                'display_order' => is_array($c) ? (int) ($c['display_order'] ?? $i) : $i,
            ];
        }
        $owned = $this->itemCategories->filterOwned($supplierId, $ids);
        $ownedSet = array_flip($owned);
        $result = [];
        $primaryCount = 0;
        foreach ($wanted as $cid => $row) {
            if (!isset($ownedSet[$cid])) {
                throw new EshopException('category_invalid', "Kategorie id={$cid} nepatří této firmě.", 422, ['category_id' => $cid]);
            }
            if ($row['is_primary']) {
                $primaryCount++;
            }
            $result[] = $row;
        }
        if ($primaryCount > 1) {
            throw new EshopException('multiple_primary_categories', 'Zboží může mít nejvýše jednu hlavní kategorii.', 422);
        }
        // Pokud je aspoň jedna kategorie a žádná není primary, první se stane primary.
        if ($result !== [] && $primaryCount === 0) {
            $result[0]['is_primary'] = true;
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<int>|null
     */
    private function prepareTags(int $supplierId, array $payload): ?array
    {
        if (!array_key_exists('tag_ids', $payload) || !is_array($payload['tag_ids'])) {
            return null;
        }
        $ids = array_values(array_unique(array_map('intval', $payload['tag_ids'])));
        $ids = array_filter($ids, static fn (int $x): bool => $x > 0);
        $owned = $this->itemTags->filterOwned($supplierId, $ids);
        $ownedSet = array_flip($owned);
        foreach ($ids as $tid) {
            if (!isset($ownedSet[$tid])) {
                throw new EshopException('tag_invalid', "Štítek id={$tid} nepatří této firmě.", 422, ['tag_id' => $tid]);
            }
        }
        return array_values($ids);
    }

    /**
     * @param array<string,mixed> $payload
     * @return list<array{fee_type_id:int, amount:string, currency_code:string, vat_included:bool}>|null
     */
    private function prepareFees(int $supplierId, array $payload): ?array
    {
        if (!array_key_exists('fees', $payload) || !is_array($payload['fees'])) {
            return null;
        }
        $result = [];
        $typeIds = [];
        foreach ($payload['fees'] as $f) {
            if (!is_array($f)) {
                continue;
            }
            $typeId = (int) ($f['fee_type_id'] ?? 0);
            if ($typeId <= 0) {
                continue;
            }
            $amountRaw = str_replace(',', '.', (string) ($f['amount'] ?? ''));
            if ($amountRaw === '' || !is_numeric($amountRaw)) {
                throw new EshopException('fee_amount_invalid', 'Částka poplatku musí být číslo.', 400, ['fee_type_id' => $typeId]);
            }
            $currency = strtoupper(trim((string) ($f['currency_code'] ?? 'CZK')));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                $currency = 'CZK';
            }
            $typeIds[] = $typeId;
            $result[] = [
                'fee_type_id'   => $typeId,
                'amount'        => $amountRaw,
                'currency_code' => $currency,
                'vat_included'  => (bool) ($f['vat_included'] ?? false),
            ];
        }
        $owned = array_flip($this->itemFees->filterOwnedTypes($supplierId, $typeIds));
        foreach ($result as $r) {
            if (!isset($owned[$r['fee_type_id']])) {
                throw new EshopException('fee_type_invalid', "Poplatek id={$r['fee_type_id']} nepatří této firmě.", 422, ['fee_type_id' => $r['fee_type_id']]);
            }
        }
        return $result;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function replaceI18n(int $supplierId, int $id, array $rows): void
    {
        // Zjistíme existující locale a smažeme ty, které v payloadu nejsou.
        $existing = $this->i18n->listForItem($supplierId, $id);
        $known = $this->locales->codes($supplierId);
        $keep = [];
        foreach ($rows as $r) {
            $locale = trim((string) ($r['locale'] ?? ''));
            $name = trim((string) ($r['name'] ?? ''));
            if ($locale === '' || $name === '') {
                continue; // prázdný řádek přeskoč (name je povinné)
            }
            if (mb_strlen($locale) > 5) {
                throw new EshopException('validation_failed', "Neplatný kód jazyka „{$locale}\".", 400);
            }
            if (!in_array($locale, $known, true)) {
                if ($locale !== 'cs') {
                    throw new EshopException('unknown_locale', "Jazyk „{$locale}\" není v číselníku jazyků.", 400);
                }
                $this->locales->insert($supplierId, [
                    'code' => 'cs',
                    'name' => 'Čeština',
                    'display_order' => 0,
                    'is_default' => $known === [],
                    'archived' => false,
                ]);
                $known[] = 'cs';
            }
            $keep[$locale] = true;
            $this->i18n->upsert($supplierId, $id, $locale, [
                'name'            => $name,
                'short_desc'      => $this->strOrNull($r['short_desc'] ?? null),
                'description'     => $this->strOrNull($r['description'] ?? null),
                'seo_title'       => $this->strOrNull($r['seo_title'] ?? null),
                'seo_description' => $this->strOrNull($r['seo_description'] ?? null),
                'seo_slug'        => $this->strOrNull($r['seo_slug'] ?? null),
            ]);
        }
        foreach ($existing as $row) {
            if (!isset($keep[$row['locale']])) {
                $this->i18n->deleteLocale($supplierId, $id, (string) $row['locale']);
            }
        }
    }

    private function intOrNull(mixed $v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }

    private function strOrNull(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function pricingBase(mixed $v): string
    {
        $v = (string) $v;
        return in_array($v, ['weighted_avg', 'last_purchase', 'manual'], true) ? $v : 'weighted_avg';
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function tx(callable $fn): mixed
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
