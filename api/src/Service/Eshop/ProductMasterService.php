<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ProductMasterRepository;
use PDO;

final class ProductMasterService
{
    private const MAX_VARIANTS = 500;

    public function __construct(private readonly Connection $db, private readonly ProductMasterRepository $masters) {}

    public function list(int $supplierId, array $query): array
    {
        $status = (string) ($query['status'] ?? 'active');
        $term = trim((string) ($query['query'] ?? ''));
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
        $limit = filter_var($query['limit'] ?? 50, FILTER_VALIDATE_INT);
        if (!in_array($status, ['active', 'archived', 'all'], true) || $page === false || $page < 1 || $limit === false || $limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('Neplatný filtr nebo stránkování masterů.');
        }
        return $this->masters->list($supplierId, $status, $term, $page, $limit);
    }

    public function detail(int $supplierId, int $masterId): array
    {
        return $this->masters->detail($supplierId, $masterId)
            ?? throw new EshopException('not_found', 'Master produktu nenalezen.', 404);
    }

    public function create(int $supplierId, array $payload): array
    {
        $prepared = $this->prepareMaster($supplierId, $payload);
        return $this->tx(function () use ($supplierId, $prepared): array {
            $pdo = $this->db->pdo();
            $pdo->prepare('INSERT INTO product_masters (supplier_id, name, manufacturer_id) VALUES (?, ?, ?)')
                ->execute([$supplierId, $prepared['name'], $prepared['manufacturer_id']]);
            $id = (int) $pdo->lastInsertId();
            $this->replaceMasterContent($supplierId, $id, $prepared);
            return $this->detail($supplierId, $id);
        });
    }

    public function update(int $supplierId, int $masterId, array $payload): array
    {
        $expected = $this->requiredVersion($payload, 'row_version');
        $prepared = $this->prepareMaster($supplierId, $payload);
        return $this->tx(function () use ($supplierId, $masterId, $expected, $prepared): array {
            $before = $this->masters->detail($supplierId, $masterId)
                ?? throw new EshopException('not_found', 'Master produktu nenalezen.', 404);
            if ($before['row_version'] !== $expected) {
                throw new EshopException('version_conflict', 'Master mezitím změnil jiný uživatel.', 409);
            }
            if ($before['variants'] !== [] && $this->axisIds($before['axes']) !== $prepared['axis_attribute_ids']) {
                throw new EshopException('axes_in_use', 'Osy masteru s připojenými variantami nelze změnit.', 409);
            }
            $stmt = $this->db->pdo()->prepare('UPDATE product_masters SET name = ?, manufacturer_id = ?, row_version = row_version + 1
                WHERE supplier_id = ? AND id = ? AND row_version = ?');
            $stmt->execute([$prepared['name'], $prepared['manufacturer_id'], $supplierId, $masterId, $expected]);
            if ($stmt->rowCount() !== 1) {
                throw new EshopException('version_conflict', 'Master mezitím změnil jiný uživatel.', 409);
            }
            $this->replaceMasterContent($supplierId, $masterId, $prepared);
            $changed = $this->changedMasterFields($before, $prepared);
            $this->invalidateInheritedVariants($supplierId, $masterId, $changed);
            return $this->detail($supplierId, $masterId);
        });
    }

    public function changeStatus(int $supplierId, int $masterId, int $expectedVersion, string $status): array
    {
        return $this->tx(function () use ($supplierId, $masterId, $expectedVersion, $status): array {
            $stmt = $this->db->pdo()->prepare('UPDATE product_masters SET status = ?, row_version = row_version + 1
                WHERE supplier_id = ? AND id = ? AND row_version = ?');
            $stmt->execute([$status, $supplierId, $masterId, $expectedVersion]);
            if ($stmt->rowCount() !== 1) {
                if ($this->masters->find($supplierId, $masterId) === null) {
                    throw new EshopException('not_found', 'Master produktu nenalezen.', 404);
                }
                throw new EshopException('version_conflict', 'Master mezitím změnil jiný uživatel.', 409);
            }
            return $this->detail($supplierId, $masterId);
        });
    }

    public function attach(int $supplierId, int $masterId, array $payload): array
    {
        $masterVersion = $this->requiredVersion($payload, 'master_row_version');
        $variants = $payload['variants'] ?? null;
        if (!is_array($variants) || !array_is_list($variants) || $variants === [] || count($variants) > self::MAX_VARIANTS) {
            throw new \InvalidArgumentException('Varianty musí být neprázdný seznam do 500 položek.');
        }
        return $this->tx(function () use ($supplierId, $masterId, $masterVersion, $variants): array {
            $master = $this->masters->find($supplierId, $masterId, true)
                ?? throw new EshopException('not_found', 'Master produktu nenalezen.', 404);
            if ($master['status'] !== 'active') {
                throw new EshopException('master_archived', 'Archivovaný master nelze měnit.', 409);
            }
            if ($master['row_version'] !== $masterVersion) {
                throw new EshopException('version_conflict', 'Master mezitím změnil jiný uživatel.', 409);
            }
            $axisIds = $this->loadAxisIds($supplierId, $masterId);
            $byId = [];
            foreach ($variants as $variant) {
                if (!is_array($variant) || !is_int($variant['stock_item_id'] ?? null) || !is_int($variant['row_version'] ?? null)) {
                    throw new \InvalidArgumentException('Varianta musí obsahovat stock_item_id a row_version.');
                }
                $byId[$variant['stock_item_id']] = $variant;
            }
            if (count($byId) !== count($variants)) {
                throw new \InvalidArgumentException('Karta smí být ve výběru jen jednou.');
            }
            ksort($byId, SORT_NUMERIC);
            foreach ($byId as $itemId => $variant) {
                $item = $this->lockItem($supplierId, (int) $itemId);
                if ($item === null) {
                    throw new EshopException('not_found', 'Karta zboží nenalezena.', 404, ['stock_item_id' => $itemId]);
                }
                if ((int) $item['row_version'] !== $variant['row_version']) {
                    throw new EshopException('version_conflict', 'Kartu mezitím změnil jiný uživatel.', 409, ['stock_item_id' => $itemId]);
                }
                if ($this->masters->variantContext($supplierId, (int) $itemId, true) !== null) {
                    throw new EshopException('variant_already_attached', 'Karta už patří k masteru.', 409, ['stock_item_id' => $itemId]);
                }
                $options = $this->prepareOptions($supplierId, $axisIds, $variant['options'] ?? []);
                $inheritance = $this->prepareInheritance($variant['inheritance'] ?? []);
                $signature = $this->optionSignature($options);
                try {
                    $this->db->pdo()->prepare('INSERT INTO product_variants
                        (supplier_id, master_id, stock_item_id, inherit_manufacturer, option_signature) VALUES (?, ?, ?, ?, UNHEX(?))')
                        ->execute([$supplierId, $masterId, $itemId, (int) $inheritance['manufacturer'], $signature]);
                } catch (\PDOException $e) {
                    if ((string) $e->getCode() === '23000') {
                        throw new EshopException('option_combination_conflict', 'Kombinace voleb už u masteru existuje.', 409);
                    }
                    throw $e;
                }
                $this->writeOptions($supplierId, $masterId, (int) $itemId, $options);
                $this->writeInheritance($supplierId, $masterId, (int) $itemId, $inheritance['i18n']);
                $this->bumpItem($supplierId, (int) $itemId, $variant['row_version']);
            }
            $this->bumpMaster($supplierId, $masterId, $masterVersion);
            return $this->detail($supplierId, $masterId);
        });
    }

    public function updateVariant(int $supplierId, int $masterId, int $itemId, array $payload): array
    {
        $itemVersion = $this->requiredVersion($payload, 'row_version');
        $linkVersion = $this->requiredVersion($payload, 'link_row_version');
        return $this->tx(function () use ($supplierId, $masterId, $itemId, $itemVersion, $linkVersion, $payload): array {
            $master = $this->masters->find($supplierId, $masterId, true)
                ?? throw new EshopException('not_found', 'Master produktu nenalezen.', 404);
            $context = $this->masters->variantContext($supplierId, $itemId, true);
            if ($context === null || $context['master_id'] !== $masterId) {
                throw new EshopException('not_found', 'Varianta masteru nenalezena.', 404);
            }
            if ($context['link_row_version'] !== $linkVersion || (int) ($this->lockItem($supplierId, $itemId)['row_version'] ?? 0) !== $itemVersion) {
                throw new EshopException('version_conflict', 'Variantu mezitím změnil jiný uživatel.', 409);
            }
            $options = array_key_exists('options', $payload)
                ? $this->prepareOptions($supplierId, $this->loadAxisIds($supplierId, $masterId), $payload['options'])
                : $this->loadOptions($supplierId, $masterId, $itemId);
            $inheritance = array_key_exists('inheritance', $payload)
                ? $this->prepareInheritance($payload['inheritance'])
                : $this->loadInheritance($supplierId, $masterId, $itemId, $context['inherit_manufacturer']);
            try {
                $stmt = $this->db->pdo()->prepare('UPDATE product_variants SET inherit_manufacturer = ?, option_signature = UNHEX(?), row_version = row_version + 1
                    WHERE supplier_id = ? AND master_id = ? AND stock_item_id = ? AND row_version = ?');
                $stmt->execute([(int) $inheritance['manufacturer'], $this->optionSignature($options), $supplierId, $masterId, $itemId, $linkVersion]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    throw new EshopException('option_combination_conflict', 'Kombinace voleb už u masteru existuje.', 409);
                }
                throw $e;
            }
            if ($stmt->rowCount() !== 1) {
                throw new EshopException('version_conflict', 'Variantu mezitím změnil jiný uživatel.', 409);
            }
            $this->writeOptions($supplierId, $masterId, $itemId, $options);
            $this->writeInheritance($supplierId, $masterId, $itemId, $inheritance['i18n']);
            $this->bumpItem($supplierId, $itemId, $itemVersion);
            return $this->detail($supplierId, $masterId);
        });
    }

    public function detachPreview(int $supplierId, int $masterId, int $itemId): array
    {
        $detail = $this->detail($supplierId, $masterId);
        foreach ($detail['variants'] as $variant) {
            if ($variant['stock_item_id'] === $itemId) {
                return [
                    'master_id' => $masterId,
                    'stock_item_id' => $itemId,
                    'row_version' => $variant['row_version'],
                    'link_row_version' => $variant['link_row_version'],
                    'materialized' => $variant['effective'],
                ];
            }
        }
        throw new EshopException('not_found', 'Varianta masteru nenalezena.', 404);
    }

    public function detach(int $supplierId, int $masterId, int $itemId, array $payload): array
    {
        $itemVersion = $this->requiredVersion($payload, 'row_version');
        $linkVersion = $this->requiredVersion($payload, 'link_row_version');
        return $this->tx(function () use ($supplierId, $masterId, $itemId, $itemVersion, $linkVersion): array {
            $this->masters->find($supplierId, $masterId, true)
                ?? throw new EshopException('not_found', 'Master produktu nenalezen.', 404);
            $preview = $this->detachPreview($supplierId, $masterId, $itemId);
            if ($preview['row_version'] !== $itemVersion || $preview['link_row_version'] !== $linkVersion) {
                throw new EshopException('version_conflict', 'Variantu mezitím změnil jiný uživatel.', 409);
            }
            $item = $this->lockItem($supplierId, $itemId);
            if ($item === null || (int) $item['row_version'] !== $itemVersion) {
                throw new EshopException('version_conflict', 'Kartu mezitím změnil jiný uživatel.', 409);
            }
            $effective = $preview['materialized'];
            $this->db->pdo()->prepare('UPDATE stock_items SET manufacturer_id = ?, row_version = row_version + 1
                WHERE supplier_id = ? AND id = ? AND row_version = ?')
                ->execute([$effective['manufacturer_id'], $supplierId, $itemId, $itemVersion]);
            $upsert = $this->db->pdo()->prepare('INSERT INTO stock_item_i18n
                (supplier_id, stock_item_id, locale, name, short_desc, description, seo_title, seo_description)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE name = VALUES(name), short_desc = VALUES(short_desc), description = VALUES(description),
                    seo_title = VALUES(seo_title), seo_description = VALUES(seo_description)');
            foreach ($effective['i18n'] as $row) {
                if (trim((string) ($row['name'] ?? '')) === '') {
                    continue;
                }
                $upsert->execute([$supplierId, $itemId, $row['locale'], $row['name'], $row['short_desc'], $row['description'], $row['seo_title'], $row['seo_description']]);
            }
            $stmt = $this->db->pdo()->prepare('DELETE FROM product_variants
                WHERE supplier_id = ? AND master_id = ? AND stock_item_id = ? AND row_version = ?');
            $stmt->execute([$supplierId, $masterId, $itemId, $linkVersion]);
            if ($stmt->rowCount() !== 1) {
                throw new EshopException('version_conflict', 'Variantu mezitím změnil jiný uživatel.', 409);
            }
            return ['master_id' => $masterId, 'stock_item_id' => $itemId, 'row_version' => $itemVersion + 1, 'materialized' => $effective];
        });
    }

    private function prepareMaster(int $supplierId, array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Název masteru je povinný a smí mít nejvýše 255 znaků.');
        }
        $manufacturer = $payload['manufacturer_id'] ?? null;
        if ($manufacturer !== null && (!is_int($manufacturer) || $manufacturer < 1 || !$this->ownedManufacturer($supplierId, $manufacturer))) {
            throw new EshopException('manufacturer_invalid', 'Zvolený výrobce neexistuje.', 422);
        }
        $i18n = $this->prepareI18n($supplierId, $payload['i18n'] ?? []);
        $axes = $this->prepareAxes($supplierId, $payload['axis_attribute_ids'] ?? []);
        return ['name' => $name, 'manufacturer_id' => $manufacturer, 'i18n' => $i18n, 'axis_attribute_ids' => $axes];
    }

    private function prepareI18n(int $supplierId, mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \InvalidArgumentException('Překlady musí být seznam.');
        }
        $known = $this->knownLocales($supplierId);
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['locale'] ?? null) || !in_array($row['locale'], $known, true)) {
                throw new EshopException('unknown_locale', 'Neznámý jazyk masteru.', 422);
            }
            $locale = $row['locale'];
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '' || isset($out[$locale])) {
                throw new \InvalidArgumentException('Každý jazyk musí mít právě jeden neprázdný název.');
            }
            $out[$locale] = ['locale' => $locale, 'name' => $name];
            foreach (array_slice(ProductMasterRepository::I18N_FIELDS, 1) as $field) {
                $value = $row[$field] ?? null;
                $out[$locale][$field] = $value === null || trim((string) $value) === '' ? null : trim((string) $value);
            }
        }
        return array_values($out);
    }

    private function prepareAxes(int $supplierId, mixed $ids): array
    {
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > 8) {
            throw new \InvalidArgumentException('Osy musí být seznam do osmi atributů.');
        }
        $ids = array_values(array_unique($ids));
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('ID osy musí být kladné celé číslo.');
            }
        }
        if ($ids !== []) {
            $stmt = $this->db->pdo()->prepare('SELECT id FROM stock_attributes WHERE supplier_id = ? AND data_type = \'enum\' AND is_multivalue = 0 AND archived = 0 AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute([$supplierId, ...$ids]);
            $owned = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            sort($owned);
            $expected = $ids;
            sort($expected);
            if ($owned !== $expected) {
                throw new EshopException('axis_invalid', 'Osa musí být aktivní jednovýběrový enum atribut této firmy.', 422);
            }
        }
        return $ids;
    }

    private function prepareOptions(int $supplierId, array $axisIds, mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== count($axisIds)) {
            throw new EshopException('variant_options_invalid', 'Varianta musí mít právě jednu volbu pro každou osu.', 422);
        }
        $byAttribute = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_int($row['attribute_id'] ?? null) || !is_int($row['option_id'] ?? null)) {
                throw new EshopException('variant_options_invalid', 'Neplatná volba varianty.', 422);
            }
            $byAttribute[$row['attribute_id']] = $row['option_id'];
        }
        if (count($byAttribute) !== count($rows) || array_diff($axisIds, array_keys($byAttribute)) !== []) {
            throw new EshopException('variant_options_invalid', 'Volby varianty neodpovídají osám masteru.', 422);
        }
        if ($rows !== []) {
            $pairs = [];
            $params = [$supplierId];
            foreach ($byAttribute as $attributeId => $optionId) {
                $pairs[] = '(attribute_id = ? AND id = ?)';
                $params[] = $attributeId;
                $params[] = $optionId;
            }
            $stmt = $this->db->pdo()->prepare('SELECT attribute_id, id FROM stock_attribute_options WHERE supplier_id = ? AND (' . implode(' OR ', $pairs) . ')');
            $stmt->execute($params);
            if (count($stmt->fetchAll(PDO::FETCH_ASSOC)) !== count($byAttribute)) {
                throw new EshopException('variant_options_invalid', 'Volba varianty nepatří zvolené ose této firmy.', 422);
            }
        }
        $out = [];
        foreach ($axisIds as $attributeId) {
            $out[] = ['attribute_id' => $attributeId, 'option_id' => $byAttribute[$attributeId]];
        }
        return $out;
    }

    private function prepareInheritance(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Dědění musí být objekt.');
        }
        $manufacturer = $value['manufacturer'] ?? true;
        if (!is_bool($manufacturer)) {
            throw new \InvalidArgumentException('Dědění výrobce musí být boolean.');
        }
        $i18n = $value['i18n'] ?? [];
        if (!is_array($i18n)) {
            throw new \InvalidArgumentException('Dědění překladů musí být objekt.');
        }
        $normalized = [];
        foreach ($i18n as $locale => $flags) {
            if (!is_string($locale) || !is_array($flags) || array_diff(array_keys($flags), ProductMasterRepository::I18N_FIELDS) !== []) {
                throw new \InvalidArgumentException('Neplatné dědění překladu.');
            }
            foreach (ProductMasterRepository::I18N_FIELDS as $field) {
                $flag = $flags[$field] ?? true;
                if (!is_bool($flag)) {
                    throw new \InvalidArgumentException('Příznak dědění musí být boolean.');
                }
                $normalized[$locale][$field] = $flag;
            }
        }
        return ['manufacturer' => $manufacturer, 'i18n' => $normalized];
    }

    private function replaceMasterContent(int $supplierId, int $masterId, array $prepared): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM product_master_i18n WHERE supplier_id = ? AND master_id = ?')->execute([$supplierId, $masterId]);
        $stmt = $pdo->prepare('INSERT INTO product_master_i18n
            (supplier_id, master_id, locale, name, short_desc, description, seo_title, seo_description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($prepared['i18n'] as $row) {
            $stmt->execute([$supplierId, $masterId, $row['locale'], $row['name'], $row['short_desc'], $row['description'], $row['seo_title'], $row['seo_description']]);
        }
        $pdo->prepare('DELETE FROM product_master_axes WHERE supplier_id = ? AND master_id = ?')->execute([$supplierId, $masterId]);
        $stmt = $pdo->prepare('INSERT INTO product_master_axes (supplier_id, master_id, attribute_id, display_order) VALUES (?, ?, ?, ?)');
        foreach ($prepared['axis_attribute_ids'] as $order => $attributeId) {
            $stmt->execute([$supplierId, $masterId, $attributeId, $order]);
        }
    }

    private function writeOptions(int $supplierId, int $masterId, int $itemId, array $options): void
    {
        $pdo = $this->db->pdo();
        $axisIds = array_column($options, 'attribute_id');
        $pdo->prepare('DELETE FROM product_variant_options WHERE supplier_id = ? AND master_id = ? AND stock_item_id = ?')
            ->execute([$supplierId, $masterId, $itemId]);
        if ($axisIds !== []) {
            $pdo->prepare('DELETE FROM stock_item_attribute_values WHERE supplier_id = ? AND stock_item_id = ? AND attribute_id IN (' . implode(',', array_fill(0, count($axisIds), '?')) . ')')
                ->execute([$supplierId, $itemId, ...$axisIds]);
        }
        $variantStmt = $pdo->prepare('INSERT INTO product_variant_options
            (supplier_id, master_id, stock_item_id, attribute_id, option_id, display_order) VALUES (?, ?, ?, ?, ?, ?)');
        $itemStmt = $pdo->prepare('INSERT INTO stock_item_attribute_values
            (supplier_id, stock_item_id, attribute_id, option_id, display_order) VALUES (?, ?, ?, ?, ?)');
        foreach ($options as $order => $option) {
            $variantStmt->execute([$supplierId, $masterId, $itemId, $option['attribute_id'], $option['option_id'], $order]);
            $itemStmt->execute([$supplierId, $itemId, $option['attribute_id'], $option['option_id'], $order]);
        }
    }

    private function writeInheritance(int $supplierId, int $masterId, int $itemId, array $i18n): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM product_variant_i18n_inheritance WHERE supplier_id = ? AND master_id = ? AND stock_item_id = ?')
            ->execute([$supplierId, $masterId, $itemId]);
        $stmt = $pdo->prepare('INSERT INTO product_variant_i18n_inheritance
            (supplier_id, master_id, stock_item_id, locale, inherit_name, inherit_short_desc, inherit_description, inherit_seo_title, inherit_seo_description)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($i18n as $locale => $flags) {
            $stmt->execute([$supplierId, $masterId, $itemId, $locale, (int) $flags['name'], (int) $flags['short_desc'],
                (int) $flags['description'], (int) $flags['seo_title'], (int) $flags['seo_description']]);
        }
    }

    private function loadAxisIds(int $supplierId, int $masterId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT attribute_id FROM product_master_axes WHERE supplier_id = ? AND master_id = ? ORDER BY display_order, attribute_id');
        $stmt->execute([$supplierId, $masterId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function loadOptions(int $supplierId, int $masterId, int $itemId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT attribute_id, option_id FROM product_variant_options
            WHERE supplier_id = ? AND master_id = ? AND stock_item_id = ? ORDER BY display_order, attribute_id');
        $stmt->execute([$supplierId, $masterId, $itemId]);
        return array_map(static fn (array $row): array => ['attribute_id' => (int) $row['attribute_id'], 'option_id' => (int) $row['option_id']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function loadInheritance(int $supplierId, int $masterId, int $itemId, bool $manufacturer): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM product_variant_i18n_inheritance WHERE supplier_id = ? AND master_id = ? AND stock_item_id = ?');
        $stmt->execute([$supplierId, $masterId, $itemId]);
        $i18n = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach (ProductMasterRepository::I18N_FIELDS as $field) {
                $i18n[$row['locale']][$field] = (bool) $row['inherit_' . $field];
            }
        }
        return ['manufacturer' => $manufacturer, 'i18n' => $i18n];
    }

    private function changedMasterFields(array $before, array $prepared): array
    {
        $changed = ['manufacturer' => $before['manufacturer_id'] !== $prepared['manufacturer_id'], 'i18n' => []];
        $old = [];
        foreach ($before['i18n'] as $row) {
            $old[$row['locale']] = $row;
        }
        $new = [];
        foreach ($prepared['i18n'] as $row) {
            $new[$row['locale']] = $row;
        }
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $locale) {
            foreach (ProductMasterRepository::I18N_FIELDS as $field) {
                if (($old[$locale][$field] ?? null) !== ($new[$locale][$field] ?? null)) {
                    $changed['i18n'][$locale][$field] = true;
                }
            }
        }
        return $changed;
    }

    private function invalidateInheritedVariants(int $supplierId, int $masterId, array $changed): void
    {
        $conditions = [];
        $params = [];
        if ($changed['manufacturer']) {
            $conditions[] = 'v.inherit_manufacturer = 1';
        }
        foreach ($changed['i18n'] as $locale => $fields) {
            foreach (array_keys($fields) as $field) {
                $conditions[] = '(NOT EXISTS (SELECT 1 FROM product_variant_i18n_inheritance i WHERE i.supplier_id = v.supplier_id
                    AND i.master_id = v.master_id AND i.stock_item_id = v.stock_item_id AND i.locale = ?)
                    OR EXISTS (SELECT 1 FROM product_variant_i18n_inheritance i WHERE i.supplier_id = v.supplier_id
                    AND i.master_id = v.master_id AND i.stock_item_id = v.stock_item_id AND i.locale = ? AND i.inherit_' . $field . ' = 1))';
                $params[] = $locale;
                $params[] = $locale;
            }
        }
        if ($conditions === []) {
            return;
        }
        $stmt = $this->db->pdo()->prepare('UPDATE stock_items s JOIN product_variants v ON v.stock_item_id = s.id AND v.supplier_id = s.supplier_id
            SET s.row_version = s.row_version + 1 WHERE v.supplier_id = ? AND v.master_id = ? AND (' . implode(' OR ', $conditions) . ')');
        $stmt->execute([$supplierId, $masterId, ...$params]);
    }

    private function lockItem(int $supplierId, int $itemId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, row_version FROM stock_items WHERE supplier_id = ? AND id = ? FOR UPDATE');
        $stmt->execute([$supplierId, $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function bumpItem(int $supplierId, int $itemId, int $expectedVersion): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE stock_items SET row_version = row_version + 1 WHERE supplier_id = ? AND id = ? AND row_version = ?');
        $stmt->execute([$supplierId, $itemId, $expectedVersion]);
        if ($stmt->rowCount() !== 1) {
            throw new EshopException('version_conflict', 'Kartu mezitím změnil jiný uživatel.', 409);
        }
    }

    private function bumpMaster(int $supplierId, int $masterId, int $expectedVersion): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE product_masters SET row_version = row_version + 1 WHERE supplier_id = ? AND id = ? AND row_version = ?');
        $stmt->execute([$supplierId, $masterId, $expectedVersion]);
        if ($stmt->rowCount() !== 1) {
            throw new EshopException('version_conflict', 'Master mezitím změnil jiný uživatel.', 409);
        }
    }

    private function ownedManufacturer(int $supplierId, int $manufacturerId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM manufacturers WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $manufacturerId]);
        return $stmt->fetchColumn() !== false;
    }

    private function knownLocales(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT code FROM stock_locales WHERE supplier_id = ? AND archived = 0');
        $stmt->execute([$supplierId]);
        $locales = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('cs', $locales, true)) {
            $locales[] = 'cs';
        }
        return $locales;
    }

    public static function optionSignature(array $options): ?string
    {
        if ($options === []) {
            return null;
        }
        return hash('sha256', implode('|', array_map(static fn (array $row): string => $row['attribute_id'] . ':' . $row['option_id'], $options)));
    }

    private function axisIds(array $axes): array
    {
        return array_map(static fn (array $axis): int => $axis['attribute_id'], $axes);
    }

    private function requiredVersion(array $payload, string $field): int
    {
        $value = $payload[$field] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new \InvalidArgumentException($field . ' musí být kladné celé číslo.');
        }
        return $value;
    }

    private function tx(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            return $callback();
        }
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
