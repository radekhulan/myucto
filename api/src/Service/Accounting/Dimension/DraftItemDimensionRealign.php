<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Dimenze položek konceptu faktury zůstanou u svých položek i po náhradě položek.
 *
 * Dimenze položky se ukládají podle POŘADÍ řádku (`document_dimensions.item_no`, pořadí
 * `ORDER BY order_index, id`, {@see DocumentDimensionContext}), ne podle ID. Náhrada položek
 * je DELETE + INSERT, takže vložení nebo odebrání řádku posune dimenze dalších řádků na jinou
 * položku. Editor v aplikaci to řeší sám (snímek a uložení dimenzí po uložení dokladu), klient
 * API (MCP) ne. Pošle-li klient u položek jejich `id` z detailu dokladu, převede se tady pořadí
 * dimenzí ze starých pozic na nové; položka bez `id` je nová a dimenze nemá.
 *
 * Jen pro KONCEPT: koncept nemá řádky v deníku, takže se nic nepřerazítkovává. U zaúčtovaného
 * dokladu jde změna dimenzí přes {@see DimensionService::saveDocument()} s přeúčtováním.
 */
final class DraftItemDimensionRealign
{
    /** Typ dokladu v document_dimensions => [tabulka položek, sloupec dokladu]. */
    private const ITEMS = [
        'invoice' => ['invoice_items', 'invoice_id'],
        'purchase_invoice' => ['purchase_invoice_items', 'purchase_invoice_id'],
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * ID položek v pořadí, ve kterém k nim patří `item_no` (od 1). Volat PŘED náhradou položek.
     *
     * @return list<int>
     */
    public function snapshot(string $docType, int $docId): array
    {
        [$table, $parent] = self::ITEMS[$docType] ?? throw new \InvalidArgumentException('Neznámý typ dokladu.');
        $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE {$parent} = ? ORDER BY order_index, id");
        $stmt->execute([$docId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Přesune dimenze položek za jejich položkami. Volat PO náhradě položek.
     *
     * @param list<int>               $before       {@see snapshot()} před náhradou
     * @param array<int|string,mixed> $payloadItems položky z těla požadavku v pořadí, jak se uložily
     */
    public function realign(int $supplierId, string $docType, int $docId, array $before, array $payloadItems): void
    {
        [$table, $parent] = self::ITEMS[$docType] ?? throw new \InvalidArgumentException('Neznámý typ dokladu.');
        if ($before === []) {
            return;
        }
        // Slevové řádky generuje server, z těla se neukládají.
        $sent = array_values(array_filter(
            $payloadItems,
            static fn ($item): bool => is_array($item) && ($item['item_kind'] ?? 'standard') !== 'discount',
        ));
        $sentIds = array_map(static fn (array $item): int => (int) ($item['id'] ?? 0), $sent);
        if (array_filter($sentIds) === []) {
            return;   // klient ID neposlal, není podle čeho párovat
        }

        $pdo = $this->db->pdo();
        $count = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM document_dimensions WHERE supplier_id = ? AND doc_type = ? AND doc_id = ? AND item_no > 0)
                  + (SELECT COUNT(*) FROM document_dimension_splits WHERE supplier_id = ? AND doc_type = ? AND doc_id = ? AND item_no > 0)'
        );
        $count->execute([$supplierId, $docType, $docId, $supplierId, $docType, $docId]);
        if ((int) $count->fetchColumn() === 0) {
            return;
        }

        // Nové řádky vznikly v pořadí těla (ID roste), slevové řádky až za nimi.
        $kind = $docType === 'invoice' ? ", COALESCE(item_kind, 'standard') AS item_kind" : ", 'standard' AS item_kind";
        $rows = $pdo->prepare("SELECT id, order_index{$kind} FROM {$table} WHERE {$parent} = ? ORDER BY id");
        $rows->execute([$docId]);
        $inserted = array_values(array_filter(
            $rows->fetchAll(PDO::FETCH_ASSOC) ?: [],
            static fn (array $row): bool => $row['item_kind'] !== 'discount',
        ));
        if (count($inserted) !== count($sent)) {
            return;   // tělo se neuložilo 1:1 (nemělo by nastat), raději nic neměnit
        }
        $newPosition = array_flip($this->snapshot($docType, $docId));
        $oldPosition = array_flip($before);

        $map = [];   // stará pozice => nová pozice (obě od 1)
        foreach ($sentIds as $k => $oldId) {
            if ($oldId > 0 && isset($oldPosition[$oldId])) {
                $map[$oldPosition[$oldId] + 1] = $newPosition[(int) $inserted[$k]['id']] + 1;
            }
        }
        $this->realignPositions($supplierId, $docType, $docId, count($before), $map);
    }

    /** @param array<int,int> $map Stará pozice => nová pozice (obě od 1). */
    public function realignPositions(int $supplierId, string $docType, int $docId, int $oldCount, array $map): void
    {
        if (count($map) === $oldCount && array_all($map, static fn (int $new, int $old): bool => $new === $old)) {
            return;
        }

        $pdo = $this->db->pdo();
        foreach (['document_dimensions', 'document_dimension_splits'] as $dimTable) {
            $select = $pdo->prepare(
                "SELECT * FROM {$dimTable} WHERE supplier_id = ? AND doc_type = ? AND doc_id = ? AND item_no > 0 FOR UPDATE"
            );
            $select->execute([$supplierId, $docType, $docId]);
            $stored = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($stored === []) {
                continue;
            }
            $pdo->prepare("DELETE FROM {$dimTable} WHERE supplier_id = ? AND doc_type = ? AND doc_id = ? AND item_no > 0")
                ->execute([$supplierId, $docType, $docId]);
            foreach ($stored as $row) {
                $to = $map[(int) $row['item_no']] ?? null;
                if ($to === null) {
                    continue;   // položka byla odebrána, její dimenze s ní
                }
                $row['item_no'] = $to;
                $columns = array_keys($row);
                $pdo->prepare(
                    "INSERT INTO {$dimTable} (" . implode(', ', $columns) . ') VALUES ('
                    . implode(', ', array_fill(0, count($columns), '?')) . ')'
                )->execute(array_values($row));
            }
        }
    }
}
