<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemRepository;

final class StockItemTemplateService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockItemDuplicationService $duplicates,
        private readonly StockItemRepository $items,
    ) {}

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, name, content_json, row_version, created_at, updated_at FROM stock_item_templates WHERE supplier_id = ? ORDER BY name, id');
        $stmt->execute([$supplierId]);
        return array_map(fn (array $row): array => $this->cast($row), $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(int $supplierId, int $sourceId, array $input): array
    {
        if (!isset($input['name']) || !is_string($input['name'])) {
            throw new StockException('validation_failed', 'Název šablony je povinný (max. 120 znaků).', 422);
        }
        $name = trim($input['name']);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new StockException('validation_failed', 'Název šablony je povinný (max. 120 znaků).', 422);
        }
        $sections = $this->duplicates->normalizeSections($input['sections'] ?? null);
        $expectedVersion = (int) ($input['row_version'] ?? 0);
        if ($expectedVersion <= 0) {
            throw new StockException('version_required', 'Pro uložení šablony je nutná verze zdrojové karty.', 400);
        }

        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Uložení šablony očekává vlastní transakci.');
        }
        $pdo->beginTransaction();
        try {
            $content = $this->duplicates->captureSnapshot($supplierId, $sourceId, $sections, $expectedVersion);
            $stmt = $pdo->prepare('INSERT INTO stock_item_templates (supplier_id, name, content_json) VALUES (?, ?, ?)');
            try {
                $stmt->execute([$supplierId, $name, json_encode($content, JSON_THROW_ON_ERROR)]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    throw new StockException('template_name_taken', 'Šablona s tímto názvem už existuje.', 409);
                }
                throw $e;
            }
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($supplierId, $id) ?? throw new \LogicException('Šablona chybí.');
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function apply(int $supplierId, int $templateId, array $input): array
    {
        [$sku, $name] = $this->duplicates->identity($input);
        $expectedVersion = (int) ($input['row_version'] ?? 0);
        if ($expectedVersion <= 0) {
            throw new StockException('version_required', 'Pro použití šablony je nutná její verze.', 400);
        }

        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Použití šablony očekává vlastní transakci.');
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT content_json, row_version FROM stock_item_templates WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $stmt->execute([$supplierId, $templateId]);
            $template = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($template === false) {
                throw new StockException('not_found', 'Šablona skladové karty nenalezena.', 404);
            }
            if ((int) $template['row_version'] !== $expectedVersion) {
                throw new StockException('version_conflict', 'Šablona se mezitím změnila.', 409);
            }
            $content = json_decode((string) $template['content_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($content)) {
                throw new StockException('invalid_template', 'Obsah šablony není podporovaný.', 422);
            }
            $newId = $this->duplicates->instantiateSnapshot($supplierId, $content, $sku, $name);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->items->find($supplierId, $newId) ?? throw new \LogicException('Karta vytvořená ze šablony chybí.');
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, name, content_json, row_version, created_at, updated_at FROM stock_item_templates WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    public function delete(int $supplierId, int $id, int $version): bool
    {
        if ($version <= 0) {
            throw new StockException('version_required', 'Pro smazání šablony je nutná její verze.', 400);
        }
        if ($this->find($supplierId, $id) === null) {
            throw new StockException('not_found', 'Šablona skladové karty nenalezena.', 404);
        }
        $stmt = $this->db->pdo()->prepare('DELETE FROM stock_item_templates WHERE supplier_id = ? AND id = ? AND row_version = ?');
        $stmt->execute([$supplierId, $id, $version]);
        if ($stmt->rowCount() === 0) {
            throw new StockException('version_conflict', 'Šablona se mezitím změnila.', 409);
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['row_version'] = (int) $row['row_version'];
        $content = json_decode((string) $row['content_json'], true, 512, JSON_THROW_ON_ERROR);
        $row['sections'] = is_array($content['sections'] ?? null) ? array_values($content['sections']) : [];
        unset($row['content_json']);
        return $row;
    }
}
