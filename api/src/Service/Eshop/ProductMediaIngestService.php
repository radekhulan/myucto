<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockMediaRepository;
use MyInvoice\Service\Document\DocumentStorage;

final class ProductMediaIngestService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StockMediaRepository $media,
        private readonly DocumentStorage $storage,
    ) {}

    /** @return array{media:array<string,mixed>,created:bool} */
    public function ingestBytes(int $supplierId, int $stockItemId, string $bytes, string $originalName, bool $deduplicate = true): array
    {
        return $this->transactional($supplierId, $stockItemId, function () use ($supplierId, $stockItemId, $bytes, $originalName, $deduplicate): array {
            $stored = $this->storage->storeFromBytes($bytes, $supplierId, $originalName);
            return $this->bind($supplierId, $stockItemId, $stored, $originalName, $deduplicate);
        });
    }

    /** @return array{media:array<string,mixed>,created:bool} */
    public function ingestTemp(int $supplierId, int $stockItemId, string $tmpPath, string $originalName, bool $deduplicate = false): array
    {
        return $this->transactional($supplierId, $stockItemId, function () use ($supplierId, $stockItemId, $tmpPath, $originalName, $deduplicate): array {
            $stored = $this->storage->storeFromTemp($tmpPath, $supplierId, $originalName);
            return $this->bind($supplierId, $stockItemId, $stored, $originalName, $deduplicate);
        });
    }

    private function transactional(int $supplierId, int $stockItemId, callable $handler): array
    {
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare('SELECT id FROM stock_items WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $stmt->execute([$supplierId, $stockItemId]);
            if ($stmt->fetchColumn() === false) {
                throw new EshopException('not_found', 'Karta zboží nenalezena.', 404);
            }
            $result = $handler();
            if ($ownTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function bind(int $supplierId, int $stockItemId, array $stored, string $originalName, bool $deduplicate): array
    {
        if ($deduplicate) {
            $existing = $this->media->findForItemByStorageKey($supplierId, $stockItemId, $stored['sha256']);
            if ($existing !== null) {
                return ['media' => $existing, 'created' => false];
            }
        }
        $rows = $this->media->listForItem($supplierId, $stockItemId);
        $nextOrder = 0;
        $hasPrimary = false;
        foreach ($rows as $row) {
            $nextOrder = max($nextOrder, (int) $row['display_order'] + 1);
            $hasPrimary = $hasPrimary || (bool) $row['is_primary'];
        }
        $mediaType = str_starts_with((string) $stored['mime_type'], 'image/') ? 'image' : 'document';
        $id = $this->media->add($supplierId, $stockItemId, [
            'media_type' => $mediaType,
            'storage_key' => $stored['sha256'],
            'original_name' => $originalName,
            'mime_type' => $stored['mime_type'],
            'size_bytes' => $stored['size_bytes'],
            'display_order' => $nextOrder,
            'export_eshop' => true,
        ]);
        if (!$hasPrimary && $mediaType === 'image') {
            $this->media->setPrimaryFlag($supplierId, $id, true);
        }
        $row = $this->media->find($supplierId, $id);
        if ($row === null) {
            throw new \RuntimeException('media_bind_failed');
        }
        return ['media' => $row, 'created' => true];
    }
}
