<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockAttributeRepository;
use MyInvoice\Repository\StockAttributeValueRepository;

/**
 * Validace a zápis typovaných hodnot parametrů karty (Epic ESHOP).
 *
 * Kontroluje hodnotu proti data_type definice atributu (text/number/bool/enum),
 * vlastnictví atributu i enum option tenantem (guard proti cross-tenant vazbě)
 * a single-vs-multivalue omezení. Zápis = delete+insert v transakci (volá
 * ProductCardService pod svou tx; reentrantní).
 */
final class AttributeValueService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockAttributeRepository $attributes,
        private readonly StockAttributeValueRepository $values,
    ) {}

    /**
     * Přepíše hodnoty parametrů karty. Očekává validovaný stockItemId (guard
     * na vlastnictví karty řeší volající ProductCardService).
     *
     * @param list<array{attribute_id:int|string, option_id?:int|string|null,
     *              value_text?:string|null, value_num?:string|int|float|null,
     *              value_bool?:bool|int|null, display_order?:int}> $entries
     */
    public function replaceForItem(int $supplierId, int $stockItemId, array $entries): void
    {
        // Předvalidace mimo tx (rychlé odmítnutí bez zámků).
        $prepared = [];
        $countPerAttr = [];
        foreach ($entries as $i => $e) {
            $attributeId = (int) ($e['attribute_id'] ?? 0);
            if ($attributeId <= 0) {
                throw new EshopException('validation_failed', 'Chybí attribute_id u parametru.', 400);
            }
            $attr = $this->attributes->find($supplierId, $attributeId);
            if ($attr === null) {
                throw new EshopException('attribute_not_found', "Parametr id={$attributeId} neexistuje.", 422);
            }

            $countPerAttr[$attributeId] = ($countPerAttr[$attributeId] ?? 0) + 1;
            if ($countPerAttr[$attributeId] > 1 && !$attr['is_multivalue']) {
                throw new EshopException(
                    'attribute_not_multivalue',
                    "Parametr „{$attr['name']}\" nepovoluje více hodnot.",
                    422,
                    ['attribute_id' => $attributeId],
                );
            }

            $prepared[] = $this->validateValue($supplierId, $attr, $e, (int) ($e['display_order'] ?? $i));
        }

        $this->tx(function () use ($supplierId, $stockItemId, $prepared): void {
            $lock = $this->db->pdo()->prepare('SELECT id FROM stock_items WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $lock->execute([$supplierId, $stockItemId]);
            $axes = $this->db->pdo()->prepare('SELECT attribute_id, option_id FROM product_variant_options WHERE supplier_id = ? AND stock_item_id = ?');
            $axes->execute([$supplierId, $stockItemId]);
            foreach ($axes->fetchAll(\PDO::FETCH_ASSOC) as $axis) {
                $matches = array_values(array_filter($prepared, static fn (array $row): bool => $row['attribute_id'] === (int) $axis['attribute_id']));
                if (count($matches) !== 1 || $matches[0]['option_id'] !== (int) $axis['option_id']) {
                    throw new EshopException('variant_axis_change_required', 'Parametry určující variantu upravte u hlavního produktu.', 422);
                }
            }
            $this->values->deleteForItem($supplierId, $stockItemId);
            foreach ($prepared as $row) {
                $this->values->add($supplierId, $stockItemId, $row);
            }
        });
    }

    /**
     * @param array<string,mixed> $attr
     * @param array<string,mixed> $e
     * @return array{attribute_id:int, option_id:?int, value_text:?string,
     *               value_num:?string, value_bool:?bool, display_order:int}
     */
    private function validateValue(int $supplierId, array $attr, array $e, int $order): array
    {
        $attributeId = (int) $attr['id'];
        $row = [
            'attribute_id' => $attributeId,
            'option_id'    => null,
            'value_text'   => null,
            'value_num'    => null,
            'value_bool'   => null,
            'display_order' => $order,
        ];

        switch ((string) $attr['data_type']) {
            case 'text':
                $text = trim((string) ($e['value_text'] ?? ''));
                if ($text === '') {
                    throw $this->emptyValue($attr);
                }
                if (mb_strlen($text) > 500) {
                    throw new EshopException('validation_failed', "Hodnota parametru „{$attr['name']}\" je příliš dlouhá (max 500).", 400);
                }
                $row['value_text'] = $text;
                break;

            case 'number':
                $raw = $e['value_num'] ?? null;
                if ($raw === null || $raw === '') {
                    throw $this->emptyValue($attr);
                }
                $num = str_replace(',', '.', (string) $raw);
                if (!is_numeric($num)) {
                    throw new EshopException('validation_failed', "Hodnota parametru „{$attr['name']}\" musí být číslo.", 400);
                }
                $row['value_num'] = $num; // string, money/precision-safe
                break;

            case 'bool':
                $b = $e['value_bool'] ?? null;
                if ($b === null || $b === '') {
                    throw $this->emptyValue($attr);
                }
                $row['value_bool'] = (bool) (is_string($b) ? !in_array(strtolower($b), ['0', 'false', 'no', ''], true) : $b);
                break;

            case 'enum':
                $optionId = isset($e['option_id']) && $e['option_id'] !== null ? (int) $e['option_id'] : 0;
                if ($optionId <= 0) {
                    throw $this->emptyValue($attr);
                }
                $option = $this->attributes->findOption($supplierId, $optionId);
                if ($option === null || (int) $option['attribute_id'] !== $attributeId) {
                    throw new EshopException(
                        'attribute_option_invalid',
                        "Neplatná volba parametru „{$attr['name']}\".",
                        422,
                        ['attribute_id' => $attributeId, 'option_id' => $optionId],
                    );
                }
                $row['option_id'] = $optionId;
                break;

            default:
                throw new EshopException('validation_failed', "Neznámý typ parametru „{$attr['data_type']}\".", 400);
        }

        return $row;
    }

    /** @param array<string,mixed> $attr */
    private function emptyValue(array $attr): EshopException
    {
        return new EshopException(
            'attribute_value_required',
            "Hodnota parametru „{$attr['name']}\" je povinná.",
            400,
            ['attribute_id' => (int) $attr['id']],
        );
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
