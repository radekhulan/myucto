<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemRepository;

final class StockItemLifecycleService
{
    public const STATUSES = ['draft', 'ready', 'retired'];

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemRepository $items,
    ) {}

    /** @return array<string,mixed> */
    public function transition(int $supplierId, int $itemId, string $status, int $expectedVersion): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new StockException('invalid_lifecycle_status', 'Neplatný stav skladové karty.', 422);
        }
        if ($expectedVersion <= 0) {
            throw new StockException('version_required', 'Pro změnu stavu je nutná verze karty.', 400);
        }

        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Změna životního cyklu očekává vlastní transakci.');
        }
        $pdo->beginTransaction();
        try {
            $locked = $pdo->prepare('SELECT id, row_version FROM stock_items WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $locked->execute([$supplierId, $itemId]);
            $row = $locked->fetch();
            if ($row === false) {
                throw new StockException('not_found', 'Skladová karta nenalezena.', 404);
            }
            if ((int) $row['row_version'] !== $expectedVersion) {
                throw new StockException('version_conflict', 'Kartu mezitím změnil jiný uživatel. Načtěte aktuální data.', 409);
            }
            $retired = $status === 'retired';
            $draft = $status === 'draft';
            $update = $pdo->prepare('UPDATE stock_items
                SET lifecycle_status = ?, retired_at = CASE WHEN ? THEN CURRENT_TIMESTAMP ELSE NULL END,
                    is_active = ?, export_eshop = CASE WHEN ? THEN 0 ELSE export_eshop END,
                    row_version = row_version + 1
                WHERE supplier_id = ? AND id = ? AND row_version = ?');
            $update->execute([$status, (int) $retired, (int) (!$retired && !$draft), (int) ($retired || $draft), $supplierId, $itemId, $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new StockException('version_conflict', 'Kartu mezitím změnil jiný uživatel. Načtěte aktuální data.', 409);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $this->items->find($supplierId, $itemId) ?? throw new \LogicException('Karta po uložení chybí.');
    }
}
