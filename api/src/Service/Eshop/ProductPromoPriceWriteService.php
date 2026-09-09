<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockItemPromoPriceRepository;

final class ProductPromoPriceWriteService
{
    private const MAX_ROWS = 50;
    private const QTY_MODES = ['stock', 'limited', 'unlimited'];

    public function __construct(
        private readonly Connection $db,
        private readonly StockItemPromoPriceRepository $promos,
    ) {}

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function save(int $supplierId, int $itemId, array $rows): array
    {
        if (count($rows) > self::MAX_ROWS) {
            throw new EshopException(
                'validation_failed',
                'Karta může mít nejvýše ' . self::MAX_ROWS . ' akčních cen.',
                400,
            );
        }

        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        }
        try {
            $lock = $pdo->prepare('SELECT id FROM stock_items WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $lock->execute([$supplierId, $itemId]);
            if ($lock->fetchColumn() === false) {
                throw new EshopException('not_found', 'Karta zboží nenalezena.', 404);
            }

            $prepared = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new EshopException('validation_failed', 'Řádek akční ceny musí být objekt.', 400);
                }
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $existing = $this->promos->find($supplierId, $id);
                    if ($existing === null || (int) $existing['stock_item_id'] !== $itemId) {
                        throw new EshopException('not_found', 'Akční cena nenalezena.', 404);
                    }
                }
                $prepared[] = ['id' => $id, 'data' => $this->prepare($row)];
            }

            $keep = [];
            foreach ($prepared as $row) {
                if ($row['id'] > 0) {
                    $this->promos->update($supplierId, $row['id'], $row['data']);
                    $keep[] = $row['id'];
                } else {
                    $keep[] = $this->promos->insert($supplierId, $itemId, $row['data']);
                }
            }
            $this->promos->deleteForItemExcept($supplierId, $itemId, $keep);
            $pdo->prepare('UPDATE stock_items SET row_version = row_version + 1 WHERE supplier_id = ? AND id = ?')
                ->execute([$supplierId, $itemId]);
            $saved = $this->promos->listForItem($supplierId, $itemId);
            if ($owns) {
                $pdo->commit();
            }
            return $saved;
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function prepare(array $row): array
    {
        $currency = strtoupper(trim((string) ($row['currency_code'] ?? 'CZK')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new EshopException('validation_failed', 'Neplatný kód měny (ISO 4217, 3 znaky).', 400);
        }
        $price = $this->numberOrNull($row['promo_price'] ?? null);
        if ($price === null || bccomp($price, '0', 2) < 0) {
            throw new EshopException('validation_failed', 'Akční cena musí být nezáporné číslo.', 400);
        }
        $mode = (string) ($row['qty_mode'] ?? 'stock');
        if (!in_array($mode, self::QTY_MODES, true)) {
            throw new EshopException('validation_failed', 'Neplatný režim množstevního stropu akce.', 400);
        }
        $limit = $this->numberOrNull($row['qty_limit'] ?? null);
        if ($mode === 'limited' && ($limit === null || bccomp($limit, '0', 3) <= 0)) {
            throw new EshopException('validation_failed', 'Pro omezený počet kusů zadejte kladný počet.', 400);
        }
        if ($mode !== 'limited') {
            $limit = null;
        }
        $from = $this->dateOrNull($row['valid_from'] ?? null);
        $to = $this->dateOrNull($row['valid_to'] ?? null);
        if ($from === false || $to === false) {
            throw new EshopException('validation_failed', 'Neplatné datum platnosti akce.', 400);
        }
        if ($from !== null && $to !== null && $to < $from) {
            throw new EshopException('validation_failed', 'Konec platnosti akce nesmí předcházet jejímu začátku.', 400);
        }

        return [
            'currency_code' => $currency,
            'promo_price' => bcadd($price, '0', 2),
            'label' => $this->limitedString($row['label'] ?? null, 60, 'Název akce'),
            'valid_from' => $from,
            'valid_to' => $to,
            'qty_mode' => $mode,
            'qty_limit' => $limit !== null ? bcadd($limit, '0', 3) : null,
            'is_active' => (bool) ($row['is_active'] ?? true),
            'note' => $this->limitedString($row['note'] ?? null, 255, 'Poznámka'),
        ];
    }

    private function numberOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = str_replace(',', '.', (string) $value);
        return is_numeric($number) ? $number : null;
    }

    private function dateOrNull(mixed $value): string|null|false
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $date = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        return checkdate($month, $day, $year) ? $date : false;
    }

    private function limitedString(mixed $value, int $max, string $label): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $string = trim((string) $value);
        if (mb_strlen($string) > $max) {
            throw new EshopException('validation_failed', "{$label} může mít nejvýše {$max} znaků.", 400);
        }
        return $string;
    }
}
