<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CatalogJobItemRepository;
use MyInvoice\Repository\ProductMasterRepository;
use PDO;

final class ProductContentTransferService
{
    public const PREVIEW_KIND = 'product_content_transfer_preview';
    public const APPLY_KIND = 'product_content_transfer_apply';

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogJobService $jobs,
        private readonly CatalogJobItemRepository $items,
        private readonly ProductMasterRepository $masters,
    ) {}

    public function preview(int $supplierId, int $masterId, array $payload, ?int $createdBy = null): array
    {
        $version = $this->version($payload, 'master_row_version');
        $ids = $this->ids($payload['stock_item_ids'] ?? null);
        $fields = $this->fields($payload['fields'] ?? null);
        $overwrite = $payload['overwrite'] ?? false;
        if (!is_bool($overwrite)) {
            throw new \InvalidArgumentException('overwrite musí být boolean.');
        }
        $master = $this->masters->detail($supplierId, $masterId)
            ?? throw new EshopException('not_found', 'Master produktu nenalezen.', 404);
        if ($master['row_version'] !== $version) {
            throw new EshopException('version_conflict', 'Master mezitím změnil jiný uživatel.', 409);
        }
        $variantIds = array_flip(array_column($master['variants'], 'stock_item_id'));
        foreach ($ids as $id) {
            if (!isset($variantIds[$id])) {
                throw new EshopException('variant_not_attached', 'Vybraná karta není variantou tohoto masteru.', 422, ['stock_item_id' => $id]);
            }
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $jobId = $this->jobs->enqueue($supplierId, self::PREVIEW_KIND, [
                'master_id' => $masterId, 'master_row_version' => $version, 'fields' => $fields, 'overwrite' => $overwrite,
            ], count($ids), createdBy: $createdBy);
            $versions = $this->versions($supplierId, $ids);
            $rows = [];
            foreach ($ids as $ordinal => $id) {
                $rows[] = ['ordinal' => $ordinal + 1, 'stock_item_id' => $id, 'expected_version' => $versions[$id] ?? null];
            }
            $this->items->append($supplierId, $jobId, $rows);
            $pdo->commit();
            return $this->jobs->find($supplierId, $jobId) ?? [];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function apply(int $supplierId, int $masterId, array $payload, ?int $createdBy = null): array
    {
        $previewId = $this->version($payload, 'preview_job_id');
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM catalog_jobs WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $lock->execute([$supplierId, $previewId]);
            $preview = $lock->fetchColumn() === false ? null : $this->jobs->find($supplierId, $previewId);
            if ($preview === null || $preview['kind'] !== self::PREVIEW_KIND || (int) ($preview['input']['master_id'] ?? 0) !== $masterId) {
                throw new EshopException('not_found', 'Náhled přenosu nenalezen.', 404);
            }
            if ($preview['status'] !== 'completed') {
                throw new EshopException('job_state_conflict', 'Náhled přenosu ještě není dokončen.', 409);
            }
            $master = $this->masters->find($supplierId, $masterId, true);
            if ($master === null || $master['row_version'] !== (int) $preview['input']['master_row_version']) {
                throw new EshopException('version_conflict', 'Obsah masteru se po náhledu změnil.', 409);
            }
            $duplicate = $pdo->prepare("SELECT id FROM catalog_jobs WHERE supplier_id = ? AND kind = ?
                AND JSON_UNQUOTE(JSON_EXTRACT(input_json, '$.preview_job_id')) = ?
                AND status IN ('queued','running','completed') LIMIT 1");
            $duplicate->execute([$supplierId, self::APPLY_KIND, (string) $previewId]);
            if ($duplicate->fetchColumn() !== false) {
                throw new EshopException('job_state_conflict', 'Přenos z tohoto náhledu už byl spuštěn.', 409);
            }
            $jobId = $this->jobs->enqueue($supplierId, self::APPLY_KIND, [
                'preview_job_id' => $previewId,
                'master_id' => $masterId,
                'master_row_version' => $master['row_version'],
                'fields' => $preview['input']['fields'],
            ], createdBy: $createdBy);
            $copy = $pdo->prepare("INSERT INTO catalog_job_items
                (supplier_id, job_id, ordinal, stock_item_id, source_row, expected_version, input_json)
                SELECT ?, ?, ROW_NUMBER() OVER (ORDER BY ordinal), stock_item_id, ordinal, expected_version,
                    JSON_OBJECT('after', JSON_EXTRACT(after_json, '$'))
                FROM catalog_job_items WHERE supplier_id = ? AND job_id = ? AND status = 'ready' ORDER BY ordinal");
            $copy->execute([$supplierId, $jobId, $supplierId, $previewId]);
            $total = $copy->rowCount();
            $pdo->prepare('UPDATE catalog_jobs SET total = ? WHERE supplier_id = ? AND id = ?')->execute([$total, $supplierId, $jobId]);
            $pdo->commit();
            return $this->jobs->find($supplierId, $jobId) ?? [];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function states(int $supplierId, int $masterId, array $ids, array $fields, bool $overwrite): array
    {
        if ($ids === []) {
            return [];
        }
        $master = $this->masters->detail($supplierId, $masterId)
            ?? throw new EshopException('not_found', 'Master produktu nenalezen.', 404);
        $masterI18n = [];
        foreach ($master['i18n'] as $row) {
            $masterI18n[$row['locale']] = $row;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare('SELECT id, row_version, manufacturer_id FROM stock_items WHERE supplier_id = ? AND id IN (' . $ph . ')');
        $stmt->execute([$supplierId, ...$ids]);
        $states = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $states[(int) $row['id']] = [
                'before' => ['row_version' => (int) $row['row_version']],
                'after' => ['row_version' => (int) $row['row_version'] + 1],
            ];
            if (in_array('manufacturer', $fields, true)) {
                $states[(int) $row['id']]['before']['manufacturer_id'] = $row['manufacturer_id'] === null ? null : (int) $row['manufacturer_id'];
                $states[(int) $row['id']]['after']['manufacturer_id'] = !$overwrite && $row['manufacturer_id'] !== null
                    ? (int) $row['manufacturer_id'] : $master['manufacturer_id'];
            }
        }
        $stmt = $this->db->pdo()->prepare('SELECT stock_item_id, locale, name, short_desc, description, seo_title, seo_description
            FROM stock_item_i18n WHERE supplier_id = ? AND stock_item_id IN (' . $ph . ')');
        $stmt->execute([$supplierId, ...$ids]);
        $own = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $own[(int) $row['stock_item_id']][$row['locale']] = $row;
        }
        foreach ($fields as $field) {
            if (!str_starts_with($field, 'i18n.')) {
                continue;
            }
            [, $locale, $name] = explode('.', $field, 3);
            foreach ($states as $id => &$state) {
                $current = $own[$id][$locale][$name] ?? null;
                $source = $masterI18n[$locale][$name] ?? null;
                $state['before']['i18n'][$locale][$name] = $current;
                $state['after']['i18n'][$locale][$name] = !$overwrite && $current !== null && $current !== '' ? $current : $source;
            }
            unset($state);
        }
        return $states;
    }

    public function writeState(int $supplierId, int $masterId, int $itemId, int $expectedVersion, array $after, array $fields): void
    {
        $context = $this->masters->variantContext($supplierId, $itemId, true);
        if ($context === null || $context['master_id'] !== $masterId) {
            throw new EshopException('variant_not_attached', 'Karta už není variantou masteru.', 409);
        }
        $sets = ['row_version = row_version + 1'];
        $params = [];
        if (in_array('manufacturer', $fields, true)) {
            $sets[] = 'manufacturer_id = ?';
            $params[] = $after['manufacturer_id'] ?? null;
        }
        $stmt = $this->db->pdo()->prepare('UPDATE stock_items SET ' . implode(', ', $sets) . ' WHERE supplier_id = ? AND id = ? AND row_version = ?');
        $stmt->execute([...$params, $supplierId, $itemId, $expectedVersion]);
        if ($stmt->rowCount() !== 1) {
            throw new EshopException('version_conflict', 'Kartu mezitím změnil jiný uživatel.', 409);
        }
        foreach ($after['i18n'] ?? [] as $locale => $values) {
            $existing = $this->i18n($supplierId, $itemId, $locale);
            $merged = array_replace($existing ?? [
                'name' => '', 'short_desc' => null, 'description' => null, 'seo_title' => null, 'seo_description' => null, 'seo_slug' => null,
            ], $values);
            if (trim((string) $merged['name']) === '') {
                throw new EshopException('content_name_required', 'Přenos by vytvořil překlad bez názvu.', 422, ['locale' => $locale]);
            }
            $this->db->pdo()->prepare('INSERT INTO stock_item_i18n
                (supplier_id, stock_item_id, locale, name, short_desc, description, seo_title, seo_description, seo_slug)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE name = VALUES(name), short_desc = VALUES(short_desc), description = VALUES(description),
                    seo_title = VALUES(seo_title), seo_description = VALUES(seo_description), seo_slug = VALUES(seo_slug)')
                ->execute([$supplierId, $itemId, $locale, $merged['name'], $merged['short_desc'], $merged['description'], $merged['seo_title'], $merged['seo_description'], $merged['seo_slug']]);
        }
    }

    private function i18n(int $supplierId, int $itemId, string $locale): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT name, short_desc, description, seo_title, seo_description, seo_slug
            FROM stock_item_i18n WHERE supplier_id = ? AND stock_item_id = ? AND locale = ?');
        $stmt->execute([$supplierId, $itemId, $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function fields(mixed $fields): array
    {
        if (!is_array($fields) || !array_is_list($fields) || $fields === [] || count($fields) > 101) {
            throw new \InvalidArgumentException('Vyberte alespoň jedno pole obsahu.');
        }
        $out = [];
        foreach ($fields as $field) {
            if (!is_string($field) || ($field !== 'manufacturer'
                && !preg_match('/^i18n\.([a-z]{2}(?:-[A-Z]{2})?)\.(name|short_desc|description|seo_title|seo_description)$/D', $field))) {
                throw new \InvalidArgumentException('Neplatné pole přenosu obsahu.');
            }
            $out[$field] = $field;
        }
        return array_values($out);
    }

    private function ids(mixed $ids): array
    {
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > 30000) {
            throw new \InvalidArgumentException('Vyberte 1 až 30000 variant.');
        }
        $out = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new \InvalidArgumentException('ID varianty musí být kladné celé číslo.');
            }
            $out[$id] = $id;
        }
        return array_values($out);
    }

    private function versions(int $supplierId, array $ids): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, row_version FROM stock_items WHERE supplier_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute([$supplierId, ...$ids]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = (int) $row['row_version'];
        }
        return $out;
    }

    private function version(array $payload, string $field): int
    {
        if (!is_int($payload[$field] ?? null) || $payload[$field] < 1) {
            throw new \InvalidArgumentException($field . ' musí být kladné celé číslo.');
        }
        return $payload[$field];
    }
}
