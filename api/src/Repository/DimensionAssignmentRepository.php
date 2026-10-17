<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankNoticeMutationGuard;
use PDO;

/**
 * Přiřazení hodnot dimenzí dokladům (`document_dimensions`) a řádkům deníku
 * (`journal_entry_line_dimensions`), migrace 1860. Dimenze se ukládají jako mapa
 * typ => hodnota; jedna hodnota za typ vynucuje primární klíč.
 *
 * Validaci (hodnota viditelná firmě, patří k typu) dělá volající přes
 * {@see \MyInvoice\Service\Accounting\Dimension\DimensionService::normalize()} —
 * tady se jen zapisuje.
 */
final class DimensionAssignmentRepository
{
    public const DOC_TYPES = ['purchase_invoice', 'invoice', 'cash_document', 'bank_transaction', 'journal_template', 'recurring_template', 'other_item', 'asset'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{header:array<int,int>, items:array<int,array<int,int>>}
     */
    public function documentDimensions(int $supplierId, string $docType, int $docId, bool $forUpdate = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT item_no, dimension_type_id, dimension_value_id FROM document_dimensions
              WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?
              ORDER BY item_no, dimension_type_id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $docType, $docId]);
        $out = ['header' => [], 'items' => []];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $itemNo = (int) $r['item_no'];
            if ($itemNo === 0) {
                $out['header'][(int) $r['dimension_type_id']] = (int) $r['dimension_value_id'];
            } else {
                $out['items'][$itemNo][(int) $r['dimension_type_id']] = (int) $r['dimension_value_id'];
            }
        }
        return $out;
    }

    /**
     * @param array<int,int> $header typ => hodnota
     * @param array<int,array<int,int>> $items pořadí položky (od 1) => typ => hodnota
     */
    public function replaceDocumentDimensions(int $supplierId, string $docType, int $docId, array $header, array $items): void
    {
        (new BankNoticeMutationGuard($this->db))->mutate($supplierId, $docType === 'bank_transaction' ? $docId : null, fn () => $this->replaceDocumentDimensionsMutable($supplierId, $docType, $docId, $header, $items));
    }

    private function replaceDocumentDimensionsMutable(int $supplierId, string $docType, int $docId, array $header, array $items): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM document_dimensions WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?')
            ->execute([$supplierId, $docType, $docId]);
        $rows = [];
        foreach ($header as $typeId => $valueId) {
            $rows[] = [$supplierId, $docType, $docId, 0, (int) $typeId, (int) $valueId];
        }
        foreach ($items as $itemNo => $dims) {
            if ((int) $itemNo <= 0) {
                continue;
            }
            foreach ($dims as $typeId => $valueId) {
                $rows[] = [$supplierId, $docType, $docId, (int) $itemNo, (int) $typeId, (int) $valueId];
            }
        }
        $this->insertDocumentRows($rows);
    }

    /**
     * Doplní hodnotu do hlavičky dokladu jen tam, kde pro typ ještě žádná není —
     * ruční volba uživatele má přednost (převody z jiných systémů).
     */
    public function addHeaderDimensionIfMissing(int $supplierId, string $docType, int $docId, int $typeId, int $valueId): bool
    {
        return (new BankNoticeMutationGuard($this->db))->mutate($supplierId, $docType === 'bank_transaction' ? $docId : null, fn () => $this->addHeaderDimensionIfMissingMutable($supplierId, $docType, $docId, $typeId, $valueId));
    }

    private function addHeaderDimensionIfMissingMutable(int $supplierId, string $docType, int $docId, int $typeId, int $valueId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id)
             VALUES (?, ?, ?, 0, ?, ?)'
        );
        $stmt->execute([$supplierId, $docType, $docId, $typeId, $valueId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Zkopíruje dimenze dokladu (hlavička, položky i rozpady) na nový doklad — kopie,
     * dobropis, vyúčtování proformy, faktura ze šablony pravidelné fakturace (F1). Položky
     * se párují podle pořadí, takže cílový doklad musí mít položky zdroje ve stejném pořadí
     * a na začátku. Cíl, který už nějaké dimenze má, se nemění.
     */
    public function copyDocument(int $supplierId, string $docType, int $fromId, int $toId, ?string $toDocType = null): void
    {
        (new BankNoticeMutationGuard($this->db))->mutate($supplierId, ($toDocType ?? $docType) === 'bank_transaction' ? $toId : null, fn () => $this->copyDocumentMutable($supplierId, $docType, $fromId, $toId, $toDocType));
    }

    private function copyDocumentMutable(int $supplierId, string $docType, int $fromId, int $toId, ?string $toDocType = null): void
    {
        $fromDocType = $docType;
        $docType = $toDocType ?? $docType;
        $pdo = $this->db->pdo();
        $exists = $pdo->prepare(
            'SELECT 1 FROM document_dimensions WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?
             UNION ALL
             SELECT 1 FROM document_dimension_splits WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?
             LIMIT 1'
        );
        $exists->execute([$supplierId, $docType, $toId, $supplierId, $docType, $toId]);
        if ($exists->fetchColumn() !== false) {
            return;
        }
        $pdo->prepare(
            'INSERT INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id)
             SELECT supplier_id, ?, ?, item_no, dimension_type_id, dimension_value_id
               FROM document_dimensions WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?'
        )->execute([$docType, $toId, $supplierId, $fromDocType, $fromId]);
        $pdo->prepare(
            'INSERT INTO document_dimension_splits (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id, share)
             SELECT supplier_id, ?, ?, item_no, dimension_type_id, dimension_value_id, share
               FROM document_dimension_splits WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?'
        )->execute([$docType, $toId, $supplierId, $fromDocType, $fromId]);
    }

    public function deleteDocument(int $supplierId, string $docType, int $docId): void
    {
        $this->db->pdo()->prepare('DELETE FROM document_dimensions WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?')
            ->execute([$supplierId, $docType, $docId]);
        $this->db->pdo()->prepare('DELETE FROM document_dimension_splits WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?')
            ->execute([$supplierId, $docType, $docId]);
    }

    // ── rozpad mezi více hodnot (document_dimension_splits, journal_entry_line_dimension_splits) ──
    //
    // Rozpad = typ => [hodnota => podíl 0–1], součet podílů 1. Pro jeden (řádek, typ)
    // platí buď jediná hodnota, nebo rozpad — validaci dělá DimensionService::normalizeSplits().

    /** @return array<int,array<int,array<int,float>>> pořadí položky (0 = hlavička) => typ => hodnota => podíl */
    public function documentSplits(int $supplierId, string $docType, int $docId, bool $forUpdate = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT item_no, dimension_type_id, dimension_value_id, share FROM document_dimension_splits
              WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?
              ORDER BY item_no, dimension_type_id, dimension_value_id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $docType, $docId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['item_no']][(int) $r['dimension_type_id']][(int) $r['dimension_value_id']] = (float) $r['share'];
        }
        return $out;
    }

    /** @param array<int,array<int,array<int,float>>> $splits pořadí položky => typ => hodnota => podíl */
    public function replaceDocumentSplits(int $supplierId, string $docType, int $docId, array $splits): void
    {
        (new BankNoticeMutationGuard($this->db))->mutate($supplierId, $docType === 'bank_transaction' ? $docId : null, fn () => $this->replaceDocumentSplitsMutable($supplierId, $docType, $docId, $splits));
    }

    private function replaceDocumentSplitsMutable(int $supplierId, string $docType, int $docId, array $splits): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM document_dimension_splits WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?')
            ->execute([$supplierId, $docType, $docId]);
        $rows = [];
        foreach ($splits as $itemNo => $byType) {
            foreach ($byType as $typeId => $shares) {
                foreach ($shares as $valueId => $share) {
                    array_push($rows, $supplierId, $docType, $docId, (int) $itemNo, (int) $typeId, (int) $valueId, self::share($share));
                }
            }
        }
        foreach (array_chunk($rows, 7 * 300) as $chunk) {
            $pdo->prepare(
                'INSERT INTO document_dimension_splits
                    (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id, share) VALUES '
                . implode(',', array_fill(0, intdiv(count($chunk), 7), '(?, ?, ?, ?, ?, ?, ?)'))
            )->execute($chunk);
        }
    }

    /**
     * @param list<int> $lineIds
     * @return array<int,array<int,array<int,float>>> řádek => typ => hodnota => podíl
     */
    public function lineSplits(int $supplierId, array $lineIds, bool $forUpdate = false): array
    {
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
        $out = [];
        foreach (array_chunk($lineIds, 1000) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT line_id, dimension_type_id, dimension_value_id, share FROM journal_entry_line_dimension_splits
                  WHERE supplier_id = ? AND line_id IN ({$marks})
                  ORDER BY line_id, dimension_type_id, dimension_value_id" . ($forUpdate ? ' FOR UPDATE' : '')
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int) $r['line_id']][(int) $r['dimension_type_id']][(int) $r['dimension_value_id']] = (float) $r['share'];
            }
        }
        return $out;
    }

    /** @return array<int,array<int,array<int,float>>> řádek => typ => hodnota => podíl */
    public function entryLineSplits(int $supplierId, int $entryId, bool $forUpdate = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.line_id, s.dimension_type_id, s.dimension_value_id, s.share
               FROM journal_entry_lines l
               STRAIGHT_JOIN journal_entry_line_dimension_splits s ON s.line_id = l.id
              WHERE l.supplier_id = ? AND l.entry_id = ?
              ORDER BY s.line_id, s.dimension_type_id, s.dimension_value_id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $entryId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['line_id']][(int) $r['dimension_type_id']][(int) $r['dimension_value_id']] = (float) $r['share'];
        }
        return $out;
    }

    /**
     * Přepíše rozpad řádku deníku. Volající ověřuje období přes DimensionPeriodGuard.
     *
     * @param array<int,array<int,float>> $splits typ => hodnota => podíl
     * @return bool zda se něco změnilo
     */
    public function replaceLineSplits(int $supplierId, int $lineId, array $splits): bool
    {
        $current = $this->lineSplits($supplierId, [$lineId], $this->db->pdo()->inTransaction())[$lineId] ?? [];
        if (self::sameSplits($current, $splits)) {
            return false;
        }
        $this->db->pdo()->prepare('DELETE FROM journal_entry_line_dimension_splits WHERE supplier_id = ? AND line_id = ?')
            ->execute([$supplierId, $lineId]);
        $this->insertLineSplits($supplierId, $lineId, $splits);
        return true;
    }

    /** @param array<int,array<int,float>> $splits typ => hodnota => podíl */
    public function insertLineSplits(int $supplierId, int $lineId, array $splits): void
    {
        $rows = [];
        $params = [];
        foreach ($splits as $typeId => $shares) {
            foreach ($shares as $valueId => $share) {
                $rows[] = '(?, ?, ?, ?, ?)';
                array_push($params, $lineId, (int) $typeId, (int) $valueId, $supplierId, self::share($share));
            }
        }
        if ($rows === []) {
            return;
        }
        $this->db->pdo()->prepare(
            'INSERT INTO journal_entry_line_dimension_splits (line_id, dimension_type_id, dimension_value_id, supplier_id, share) VALUES '
            . implode(',', $rows)
        )->execute($params);
    }

    /**
     * @param array<int,array<int,float>> $a
     * @param array<int,array<int,float>> $b
     */
    public static function sameSplits(array $a, array $b): bool
    {
        $norm = static function (array $s): array {
            $out = [];
            foreach ($s as $typeId => $shares) {
                foreach ($shares as $valueId => $share) {
                    $out[(int) $typeId . ':' . (int) $valueId] = self::share($share);
                }
            }
            ksort($out);
            return $out;
        };
        return $norm($a) === $norm($b);
    }

    public static function share(float|int|string $share): string
    {
        return number_format((float) $share, 10, '.', '');
    }

    /**
     * Hlavičkové dimenze dokladů pro seznam, jen je-li sekce u firmy zapnutá.
     * Null = dimenze vypnuté, seznam je vůbec neposílá.
     *
     * @param list<int> $docIds
     * @return array<int,array<int,int>>|null
     */
    public function headerDimensionsIfEnabled(int $supplierId, string $docType, array $docIds): ?array
    {
        if ($docIds === [] || !(new DimensionRepository($this->db))->enabled($supplierId)) {
            return null;
        }
        return $this->headerDimensionsFor($supplierId, $docType, $docIds);
    }

    /**
     * Hlavičkové dimenze více dokladů najednou (seznamy, přehledy).
     *
     * @param list<int> $docIds
     * @return array<int,array<int,int>> doklad => typ => hodnota
     */
    public function headerDimensionsFor(int $supplierId, string $docType, array $docIds): array
    {
        $docIds = array_values(array_unique(array_map('intval', $docIds)));
        if ($docIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($docIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT doc_id, dimension_type_id, dimension_value_id FROM document_dimensions
              WHERE supplier_id = ? AND doc_type = ? AND item_no = 0 AND doc_id IN ({$marks})"
        );
        $stmt->execute([$supplierId, $docType, ...$docIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['doc_id']][(int) $r['dimension_type_id']] = (int) $r['dimension_value_id'];
        }
        return $out;
    }

    /**
     * @param list<int> $lineIds
     * @return array<int,array<int,int>> řádek => typ => hodnota
     */
    public function lineDimensions(int $supplierId, array $lineIds, bool $forUpdate = false): array
    {
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
        if ($lineIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($lineIds, 1000) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT line_id, dimension_type_id, dimension_value_id FROM journal_entry_line_dimensions FORCE INDEX (PRIMARY)
                  WHERE supplier_id = ? AND line_id IN ({$marks})" . ($forUpdate ? ' FOR UPDATE' : '')
            );
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int) $r['line_id']][(int) $r['dimension_type_id']] = (int) $r['dimension_value_id'];
            }
        }
        return $out;
    }

    /** @return array<int,array<int,int>> řádek => typ => hodnota */
    public function entryLineDimensions(int $supplierId, int $entryId, bool $forUpdate = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT d.line_id, d.dimension_type_id, d.dimension_value_id
               FROM journal_entry_lines l
               STRAIGHT_JOIN journal_entry_line_dimensions d ON d.line_id = l.id
              WHERE l.supplier_id = ? AND l.entry_id = ?' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $entryId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['line_id']][(int) $r['dimension_type_id']] = (int) $r['dimension_value_id'];
        }
        return $out;
    }

    /**
     * Přepíše dimenze řádku deníku. Volající ověřuje období přes DimensionPeriodGuard.
     *
     * @param array<int,int> $dims typ => hodnota
     * @return bool zda se něco změnilo
     */
    public function replaceLineDimensions(int $supplierId, int $lineId, array $dims): bool
    {
        $current = $this->lineDimensions($supplierId, [$lineId], $this->db->pdo()->inTransaction())[$lineId] ?? [];
        ksort($current);
        ksort($dims);
        if ($current === array_map('intval', $dims)) {
            return false;
        }
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM journal_entry_line_dimensions WHERE supplier_id = ? AND line_id = ?')
            ->execute([$supplierId, $lineId]);
        $this->insertLineDimensions($supplierId, $lineId, $dims);
        return true;
    }

    /** @param array<int,int> $dims typ => hodnota */
    public function insertLineDimensions(int $supplierId, int $lineId, array $dims): void
    {
        if ($dims === []) {
            return;
        }
        $rows = [];
        $params = [];
        foreach ($dims as $typeId => $valueId) {
            $rows[] = '(?, ?, ?, ?)';
            array_push($params, $lineId, (int) $typeId, $supplierId, (int) $valueId);
        }
        $this->db->pdo()->prepare(
            'INSERT INTO journal_entry_line_dimensions (line_id, dimension_type_id, supplier_id, dimension_value_id) VALUES '
            . implode(',', $rows)
        )->execute($params);
    }

    /** @param list<array{0:int,1:string,2:int,3:int,4:int,5:int}> $rows */
    private function insertDocumentRows(array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->db->pdo()->prepare(
                'INSERT INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id) VALUES '
                . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)'))
            )->execute(array_merge(...$chunk));
        }
    }
}
