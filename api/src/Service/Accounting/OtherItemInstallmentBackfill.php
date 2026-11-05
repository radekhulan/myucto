<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use PDO;

/**
 * Doplnění `other_item_allocations.installment_id` u úhrad přiřazených před migrací 2237
 * (a u úhrad, které zakládají převody dat bez splátky).
 *
 * Proč PHP a ne UPDATE v migraci: splátku určuje jediné pravidlo
 * {@see OtherItemInstallmentCoverage}. Úhrady se projdou v pořadí payment_on, id a každá
 * dostane první otevřenou splátku po započtení `settled_outside_amount` a dřívějších
 * úhrad, tedy přesně tu, od které ji dřív rozpočítávala předpověď. Stav splátek
 * i předpověď proto po backfillu vychází stejně jako před ním.
 *
 * Idempotentní: bere jen aktivní úhrady bez splátky u položek se splátkovým kalendářem
 * a zapisuje s podmínkou `installment_id IS NULL`. Úhrada nad rámec kalendáře dostane
 * poslední splátku, takže počet „k doplnění" vždy klesne na nulu.
 */
final class OtherItemInstallmentBackfill
{
    private const BATCH = 200;

    public function __construct(private readonly PDO $pdo) {}

    public function pending(?int $supplierId = null): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM other_item_allocations a
              WHERE a.installment_id IS NULL AND a.reversed_on IS NULL
                AND (? IS NULL OR a.supplier_id = ?)
                AND EXISTS (SELECT 1 FROM other_item_installments i
                             WHERE i.supplier_id = a.supplier_id AND i.other_item_id = a.other_item_id)'
        );
        $stmt->execute([$supplierId, $supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param callable(int $allocationId, int $installmentId): void|null $onRow
     * @return array{found:int, updated:int}
     */
    public function run(bool $apply, ?callable $onRow = null, ?int $supplierId = null): array
    {
        $items = $this->pdo->prepare(
            'SELECT DISTINCT a.supplier_id, a.other_item_id FROM other_item_allocations a
              WHERE a.installment_id IS NULL AND a.reversed_on IS NULL AND a.other_item_id > ?
                AND (? IS NULL OR a.supplier_id = ?)
                AND EXISTS (SELECT 1 FROM other_item_installments i
                             WHERE i.supplier_id = a.supplier_id AND i.other_item_id = a.other_item_id)
              ORDER BY a.other_item_id LIMIT ' . self::BATCH
        );
        $installments = $this->pdo->prepare('SELECT id, position, due_on, amount FROM other_item_installments
            WHERE supplier_id = ? AND other_item_id = ? ORDER BY position');
        $allocations = $this->pdo->prepare('SELECT id, installment_id, amount FROM other_item_allocations
            WHERE supplier_id = ? AND other_item_id = ? AND reversed_on IS NULL ORDER BY payment_on, id');
        $settled = $this->pdo->prepare('SELECT settled_outside_amount FROM other_items WHERE supplier_id = ? AND id = ?');
        $update = $this->pdo->prepare('UPDATE other_item_allocations SET installment_id = ?
            WHERE id = ? AND supplier_id = ? AND installment_id IS NULL');
        $found = 0;
        $updated = 0;
        $lastItem = 0;
        do {
            $items->execute([$lastItem, $supplierId, $supplierId]);
            $batch = $items->fetchAll(PDO::FETCH_ASSOC);
            foreach ($batch as $row) {
                $owner = (int) $row['supplier_id'];
                $itemId = (int) $row['other_item_id'];
                $lastItem = $itemId;
                $installments->execute([$owner, $itemId]);
                $plan = $installments->fetchAll(PDO::FETCH_ASSOC);
                $allocations->execute([$owner, $itemId]);
                $settled->execute([$owner, $itemId]);
                $outside = (float) $settled->fetchColumn();
                $done = [];
                foreach ($allocations->fetchAll(PDO::FETCH_ASSOC) as $allocation) {
                    if ($allocation['installment_id'] === null) {
                        $coverage = OtherItemInstallmentCoverage::compute($plan, $done, $outside, '0000-00-00');
                        $target = OtherItemInstallmentCoverage::target($coverage, (float) $allocation['amount'], false)
                            ?? (int) $plan[count($plan) - 1]['id'];
                        $found++;
                        if ($onRow !== null) $onRow((int) $allocation['id'], $target);
                        if ($apply) {
                            $update->execute([$target, (int) $allocation['id'], $owner]);
                            $updated += $update->rowCount();
                        }
                        $allocation['installment_id'] = $target;
                    }
                    $done[] = $allocation;
                }
            }
        } while (count($batch) === self::BATCH);
        return ['found' => $found, 'updated' => $updated];
    }
}
