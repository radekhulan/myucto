<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard;
use MyInvoice\Service\Accounting\Dimension\DimensionAssignmentOrigin;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Allocation\JointAllocationUseGuard;

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
    public function replaceDocumentDimensions(int $supplierId, string $docType, int $docId, array $header, array $items, array $headerOrigins = [], array $itemOrigins = []): void
    {
        (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $docType, $docId, $header, $items, $headerOrigins, $itemOrigins): void {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
            (new IssuanceUseGuard($this->db))->lockClaims($supplierId);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->acquire($supplierId);
            $this->replaceDocumentDimensionsLocked($supplierId, $docType, $docId, $header, $items, $headerOrigins, $itemOrigins);
        });
    }

    private function replaceDocumentDimensionsLocked(int $supplierId, string $docType, int $docId, array $header, array $items, array $headerOrigins, array $itemOrigins): void
    {
        $pdo = $this->db->pdo();
        $current = $this->documentDimensions($supplierId, $docType, $docId, $pdo->inTransaction());
        $header = array_map('intval', $header);
        ksort($header);
        $items = array_filter(array_map(static function (array $dims): array { $dims = array_map('intval', $dims); ksort($dims); return $dims; }, $items), static fn (array $dims, int|string $itemNo): bool => (int) $itemNo > 0 && $dims !== [], ARRAY_FILTER_USE_BOTH);
        ksort($items);
        if ($current['header'] === $header && $current['items'] === $items) return;
        (new IssuanceUseGuard($this->db))->assertDocumentMutable($supplierId, $docType, $docId);
        (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertDocumentMutable($supplierId, $docType, $docId);
        (new JointAllocationUseGuard($this->db))->assertDocumentMutable($supplierId, $docType, $docId);
        $origins = $this->documentOrigins($supplierId, $docType, $docId, $pdo->inTransaction());
        $pdo->prepare('DELETE FROM document_dimensions WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?')->execute([$supplierId, $docType, $docId]);
        $rows = [];
        foreach ([0 => $header] + $items as $itemNo => $dims) {
            $old = $itemNo === 0 ? $current['header'] : ($current['items'][$itemNo] ?? []);
            $oldOrigins = $itemNo === 0 ? $origins['header'] : ($origins['items'][$itemNo] ?? []);
            $newOrigins = $itemNo === 0 ? $headerOrigins : ($itemOrigins[$itemNo] ?? []);
            foreach ($dims as $typeId => $valueId) {
                $origin = DimensionAssignmentOrigin::normalize(($old[$typeId] ?? null) === $valueId ? ($oldOrigins[$typeId] ?? []) : ($newOrigins[$typeId] ?? []));
                $rows[] = [$supplierId, $docType, $docId, (int) $itemNo, (int) $typeId, $valueId, $origin['origin'], $origin['ref']];
            }
        }
        $this->insertDocumentRows($rows);
    }
    /**
     * Doplní hodnotu do hlavičky dokladu jen tam, kde pro typ ještě žádná není —
     * ruční volba uživatele má přednost (převody z jiných systémů).
     */
    public function addHeaderDimensionIfMissing(int $supplierId, string $docType, int $docId, int $typeId, int $valueId, array $origin = []): bool
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $docType, $docId, $typeId, $valueId, $origin): bool {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
            (new IssuanceUseGuard($this->db))->lockClaims($supplierId);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->acquire($supplierId);
            if (isset($this->documentDimensions($supplierId, $docType, $docId, true)['header'][$typeId])) return false;
            (new IssuanceUseGuard($this->db))->assertDocumentMutable($supplierId, $docType, $docId);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertDocumentMutable($supplierId, $docType, $docId);
            (new JointAllocationUseGuard($this->db))->assertDocumentMutable($supplierId, $docType, $docId);
            return $this->addHeaderDimensionLocked($supplierId, $docType, $docId, $typeId, $valueId, $origin);
        });
    }

    private function addHeaderDimensionLocked(int $supplierId, string $docType, int $docId, int $typeId, int $valueId, array $origin): bool
    {
        $origin = DimensionAssignmentOrigin::normalize($origin);
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id, assignment_origin, assignment_origin_ref)
             VALUES (?, ?, ?, 0, ?, ?, ?, ?)'
        );
        $stmt->execute([$supplierId, $docType, $docId, $typeId, $valueId, $origin['origin'], $origin['ref']]);
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
        (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $docType, $fromId, $toId, $toDocType): void {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
            (new IssuanceUseGuard($this->db))->lockClaims($supplierId);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->acquire($supplierId);
            $target = $toDocType ?? $docType;
            $current = $this->documentDimensions($supplierId, $target, $toId, true);
            if ($current['header'] !== [] || $current['items'] !== [] || $this->documentSplits($supplierId, $target, $toId, true) !== []) return;
            (new IssuanceUseGuard($this->db))->assertDocumentMutable($supplierId, $target, $toId);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertDocumentMutable($supplierId, $target, $toId);
            (new JointAllocationUseGuard($this->db))->assertDocumentMutable($supplierId, $target, $toId);
            $this->copyDocumentLocked($supplierId, $docType, $fromId, $toId, $toDocType);
        });
    }

    private function copyDocumentLocked(int $supplierId, string $docType, int $fromId, int $toId, ?string $toDocType): void
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
            'INSERT INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id, assignment_origin, assignment_origin_ref)
             SELECT supplier_id, ?, ?, item_no, dimension_type_id, dimension_value_id, assignment_origin, assignment_origin_ref
               FROM document_dimensions WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?'
        )->execute([$docType, $toId, $supplierId, $fromDocType, $fromId]);
        $pdo->prepare(
            'INSERT INTO document_dimension_splits (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id, share, assignment_origin, assignment_origin_ref)
             SELECT supplier_id, ?, ?, item_no, dimension_type_id, dimension_value_id, share, assignment_origin, assignment_origin_ref
               FROM document_dimension_splits WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?'
        )->execute([$docType, $toId, $supplierId, $fromDocType, $fromId]);
    }

    public function deleteDocument(int $supplierId, string $docType, int $docId): void
    {
        (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $docType, $docId): void {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
            (new IssuanceUseGuard($this->db))->lockClaims($supplierId);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->acquire($supplierId);
            $current = $this->documentDimensions($supplierId, $docType, $docId, true);
            if ($current['header'] === [] && $current['items'] === [] && $this->documentSplits($supplierId, $docType, $docId, true) === []) return;
            (new IssuanceUseGuard($this->db))->assertDocumentMutable($supplierId, $docType, $docId);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertDocumentMutable($supplierId, $docType, $docId);
            (new JointAllocationUseGuard($this->db))->assertDocumentMutable($supplierId, $docType, $docId);
            $this->deleteDocumentLocked($supplierId, $docType, $docId);
        });
    }

    private function deleteDocumentLocked(int $supplierId, string $docType, int $docId): void
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
    public function replaceDocumentSplits(int $supplierId, string $docType, int $docId, array $splits, array $origins = []): void
    {
        (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $docType, $docId, $splits, $origins): void {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
            (new IssuanceUseGuard($this->db))->lockClaims($supplierId);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->acquire($supplierId);
            $this->replaceDocumentSplitsLocked($supplierId, $docType, $docId, $splits, $origins);
        });
    }

    private function replaceDocumentSplitsLocked(int $supplierId, string $docType, int $docId, array $splits, array $origins): void
    {
        $pdo = $this->db->pdo();
        $current = $this->documentSplits($supplierId, $docType, $docId, $pdo->inTransaction());
        $itemNos = array_unique([...array_keys($current), ...array_keys($splits)]);
        $same = true;
        foreach ($itemNos as $itemNo) {
            $same = $same && self::sameSplits($current[$itemNo] ?? [], $splits[$itemNo] ?? []);
        }
        if ($same) return;
        (new IssuanceUseGuard($this->db))->assertDocumentMutable($supplierId, $docType, $docId);
        (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertDocumentMutable($supplierId, $docType, $docId);
        (new JointAllocationUseGuard($this->db))->assertDocumentMutable($supplierId, $docType, $docId);
        $oldOrigins = $this->documentSplitOrigins($supplierId, $docType, $docId, $pdo->inTransaction());
        $pdo->prepare('DELETE FROM document_dimension_splits WHERE supplier_id = ? AND doc_type = ? AND doc_id = ?')->execute([$supplierId, $docType, $docId]);
        $rows = [];
        foreach ($splits as $itemNo => $byType) {
            foreach ($byType as $typeId => $shares) {
                foreach ($shares as $valueId => $share) {
                    $unchanged = isset($current[$itemNo][$typeId][$valueId]) && self::share($current[$itemNo][$typeId][$valueId]) === self::share($share);
                    $origin = DimensionAssignmentOrigin::normalize($unchanged ? ($oldOrigins[$itemNo][$typeId][$valueId] ?? []) : ($origins[$itemNo][$typeId][$valueId] ?? []));
                    array_push($rows, $supplierId, $docType, $docId, (int) $itemNo, (int) $typeId, (int) $valueId, self::share($share), $origin['origin'], $origin['ref']);
                }
            }
        }
        foreach (array_chunk($rows, 9 * 300) as $chunk) {
            $pdo->prepare('INSERT INTO document_dimension_splits (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id, share, assignment_origin, assignment_origin_ref) VALUES ' . implode(',', array_fill(0, intdiv(count($chunk), 9), '(?, ?, ?, ?, ?, ?, ?, ?, ?)')))->execute($chunk);
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
    public function replaceLineSplits(int $supplierId, int $lineId, array $splits, array $origins = []): bool
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $lineId, $splits, $origins): bool {
            (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
            (new IssuanceUseGuard($this->db))->lockClaims($supplierId);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->acquire($supplierId);
            return $this->replaceLineSplitsLocked($supplierId, $lineId, $splits, $origins);
        });
    }

    private function replaceLineSplitsLocked(int $supplierId, int $lineId, array $splits, array $origins): bool
    {
        $current = $this->lineSplits($supplierId, [$lineId], $this->db->pdo()->inTransaction())[$lineId] ?? [];
        if (self::sameSplits($current, $splits)) return false;
        (new \MyInvoice\Service\Nonprofit\Membership\MembershipUseGuard($this->db))->assertLinesMutable($supplierId, [$lineId]);
        (new IssuanceUseGuard($this->db))->assertDimensionLinesMutable($supplierId, [$lineId]);
        (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertLinesMutable($supplierId, [$lineId]);
        (new JointAllocationUseGuard($this->db))->assertLinesMutable($supplierId, [$lineId]);
        (new \MyInvoice\Service\Accounting\Closing\AssetReserveSourceUseGuard($this->db, new JournalEntryRepository($this->db, new \MyInvoice\Service\ActivityLogger($this->db))))->assertLinesMutable($supplierId, [$lineId]);
        $oldOrigins = $this->lineSplitOrigins($supplierId, [$lineId], $this->db->pdo()->inTransaction())[$lineId] ?? [];
        foreach ($splits as $typeId => $shares) {
            foreach ($shares as $valueId => $share) {
                if (isset($current[$typeId][$valueId]) && self::share($current[$typeId][$valueId]) === self::share($share)) $origins[$typeId][$valueId] = $oldOrigins[$typeId][$valueId] ?? [];
            }
        }
        $this->db->pdo()->prepare('DELETE FROM journal_entry_line_dimension_splits WHERE supplier_id = ? AND line_id = ?')->execute([$supplierId, $lineId]);
        $this->insertLineSplits($supplierId, $lineId, $splits, $origins);
        return true;
    }
    /** @param array<int,array<int,float>> $splits typ => hodnota => podíl */
    public function insertLineSplits(int $supplierId, int $lineId, array $splits, array $origins = []): void
    {
        if ($splits === []) return;
        (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $lineId, $splits, $origins): void {
            (new \MyInvoice\Service\Nonprofit\Membership\MembershipUseGuard($this->db))->assertLinesMutable($supplierId, [$lineId]);
            (new \MyInvoice\Service\Property\Payments\PaymentUseGuard($this->db))->assertAssignmentInsertsMutable($supplierId, [$lineId]);
            $this->insertLineSplitsLocked($supplierId, $lineId, $splits, $origins);
        });
    }

    private function insertLineSplitsLocked(int $supplierId, int $lineId, array $splits, array $origins): void
    {
        $rows = [];
        $params = [];
        foreach ($splits as $typeId => $shares) {
            foreach ($shares as $valueId => $share) {
                $origin = DimensionAssignmentOrigin::normalize($origins[$typeId][$valueId] ?? []);
                $rows[] = '(?, ?, ?, ?, ?, ?, ?)';
                array_push($params, $lineId, (int) $typeId, (int) $valueId, $supplierId, self::share($share), $origin['origin'], $origin['ref']);
            }
        }
        if ($rows === []) return;
        $this->db->pdo()->prepare('INSERT INTO journal_entry_line_dimension_splits (line_id, dimension_type_id, dimension_value_id, supplier_id, share, assignment_origin, assignment_origin_ref) VALUES ' . implode(',', $rows))->execute($params);
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
    public function replaceLineDimensions(int $supplierId, int $lineId, array $dims, array $origins = []): bool
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $lineId, $dims, $origins): bool {
            (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
            (new IssuanceUseGuard($this->db))->lockClaims($supplierId);
            (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->acquire($supplierId);
            return $this->replaceLineDimensionsLocked($supplierId, $lineId, $dims, $origins);
        });
    }

    private function replaceLineDimensionsLocked(int $supplierId, int $lineId, array $dims, array $origins): bool
    {
        $current = $this->lineDimensions($supplierId, [$lineId], $this->db->pdo()->inTransaction())[$lineId] ?? [];
        ksort($current);
        ksort($dims);
        $dims = array_map('intval', $dims);
        if ($current === $dims) return false;
        (new \MyInvoice\Service\Nonprofit\Membership\MembershipUseGuard($this->db))->assertLinesMutable($supplierId, [$lineId]);
        (new IssuanceUseGuard($this->db))->assertDimensionLinesMutable($supplierId, [$lineId]);
        (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->assertLinesMutable($supplierId, [$lineId]);
        (new JointAllocationUseGuard($this->db))->assertLinesMutable($supplierId, [$lineId]);
        (new \MyInvoice\Service\Accounting\Closing\AssetReserveSourceUseGuard($this->db, new JournalEntryRepository($this->db, new \MyInvoice\Service\ActivityLogger($this->db))))->assertLinesMutable($supplierId, [$lineId]);
        $oldOrigins = $this->lineOrigins($supplierId, [$lineId], $this->db->pdo()->inTransaction())[$lineId] ?? [];
        foreach ($dims as $typeId => $valueId) {
            if (($current[$typeId] ?? null) === $valueId) $origins[$typeId] = $oldOrigins[$typeId] ?? [];
        }
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM journal_entry_line_dimensions WHERE supplier_id = ? AND line_id = ?')->execute([$supplierId, $lineId]);
        $this->insertLineDimensions($supplierId, $lineId, $dims, $origins);
        return true;
    }
    /** @param array<int,int> $dims typ => hodnota */
    public function insertLineDimensions(int $supplierId, int $lineId, array $dims, array $origins = []): void
    {
        if ($dims === []) return;
        (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $lineId, $dims, $origins): void {
            (new \MyInvoice\Service\Nonprofit\Membership\MembershipUseGuard($this->db))->assertLinesMutable($supplierId, [$lineId]);
            (new \MyInvoice\Service\Property\Payments\PaymentUseGuard($this->db))->assertAssignmentInsertsMutable($supplierId, [$lineId]);
            $this->insertLineDimensionsLocked($supplierId, $lineId, $dims, $origins);
        });
    }

    public function insertLineAssignments(int $supplierId, array $lines): void
    {
        $lines = array_filter($lines, static fn (array $line): bool => !empty($line['dimensions']) || !empty($line['dimension_splits']));
        if ($lines === []) return;
        (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $lines): void {
            (new \MyInvoice\Service\Nonprofit\Membership\MembershipUseGuard($this->db))->assertLinesMutable($supplierId, array_keys($lines));
            (new \MyInvoice\Service\Property\Payments\PaymentUseGuard($this->db))->assertAssignmentInsertsMutable($supplierId, array_keys($lines));
            foreach ($lines as $lineId => $line) {
                $this->insertLineDimensionsLocked($supplierId, (int) $lineId, $line['dimensions'] ?? [], $line['dimension_origins'] ?? []);
                $this->insertLineSplitsLocked($supplierId, (int) $lineId, $line['dimension_splits'] ?? [], $line['dimension_split_origins'] ?? []);
            }
        });
    }

    private function insertLineDimensionsLocked(int $supplierId, int $lineId, array $dims, array $origins): void
    {
        if ($dims === []) return;
        $rows = [];
        $params = [];
        foreach ($dims as $typeId => $valueId) {
            $origin = DimensionAssignmentOrigin::normalize($origins[$typeId] ?? []);
            $rows[] = '(?, ?, ?, ?, ?, ?)';
            array_push($params, $lineId, (int) $typeId, $supplierId, (int) $valueId, $origin['origin'], $origin['ref']);
        }
        $this->db->pdo()->prepare('INSERT INTO journal_entry_line_dimensions (line_id, dimension_type_id, supplier_id, dimension_value_id, assignment_origin, assignment_origin_ref) VALUES ' . implode(',', $rows))->execute($params);
    }

    public function documentOrigins(int $supplierId, string $docType, int $docId, bool $forUpdate = false): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT item_no, dimension_type_id, assignment_origin, assignment_origin_ref FROM document_dimensions WHERE supplier_id = ? AND doc_type = ? AND doc_id = ? ORDER BY item_no, dimension_type_id' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $docType, $docId]);
        $out = ['header' => [], 'items' => []];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $origin = ['origin' => $row['assignment_origin'], 'ref' => $row['assignment_origin_ref']];
            if ((int) $row['item_no'] === 0) $out['header'][(int) $row['dimension_type_id']] = $origin;
            else $out['items'][(int) $row['item_no']][(int) $row['dimension_type_id']] = $origin;
        }
        return $out;
    }

    public function documentSplitOrigins(int $supplierId, string $docType, int $docId, bool $forUpdate = false): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT item_no, dimension_type_id, dimension_value_id, assignment_origin, assignment_origin_ref FROM document_dimension_splits WHERE supplier_id = ? AND doc_type = ? AND doc_id = ? ORDER BY item_no, dimension_type_id, dimension_value_id' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId, $docType, $docId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int) $row['item_no']][(int) $row['dimension_type_id']][(int) $row['dimension_value_id']] = ['origin' => $row['assignment_origin'], 'ref' => $row['assignment_origin_ref']];
        return $out;
    }

    public function lineOrigins(int $supplierId, array $lineIds, bool $forUpdate = false): array
    {
        return $this->assignmentOrigins('journal_entry_line_dimensions', $supplierId, $lineIds, $forUpdate);
    }

    public function lineSplitOrigins(int $supplierId, array $lineIds, bool $forUpdate = false): array
    {
        return $this->assignmentOrigins('journal_entry_line_dimension_splits', $supplierId, $lineIds, $forUpdate);
    }

    public function entryLineOrigins(int $supplierId, int $entryId, bool $forUpdate = false): array
    {
        return $this->entryAssignmentOrigins('journal_entry_line_dimensions', $supplierId, $entryId, $forUpdate);
    }

    public function entryLineSplitOrigins(int $supplierId, int $entryId, bool $forUpdate = false): array
    {
        return $this->entryAssignmentOrigins('journal_entry_line_dimension_splits', $supplierId, $entryId, $forUpdate);
    }

    private function entryAssignmentOrigins(string $table, int $supplierId, int $entryId, bool $forUpdate): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT d.line_id, d.dimension_type_id, d.dimension_value_id, d.assignment_origin, d.assignment_origin_ref
               FROM journal_entry_lines l STRAIGHT_JOIN {$table} d ON d.line_id = l.id
              WHERE l.supplier_id = ? AND l.entry_id = ? AND d.supplier_id = ?
              ORDER BY d.line_id, d.dimension_type_id, d.dimension_value_id" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $entryId, $supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $origin = ['origin' => $row['assignment_origin'], 'ref' => $row['assignment_origin_ref']];
            if ($table === 'journal_entry_line_dimensions') {
                $out[(int) $row['line_id']][(int) $row['dimension_type_id']] = $origin;
            } else {
                $out[(int) $row['line_id']][(int) $row['dimension_type_id']][(int) $row['dimension_value_id']] = $origin;
            }
        }
        return $out;
    }

    private function assignmentOrigins(string $table, int $supplierId, array $lineIds, bool $forUpdate): array
    {
        $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
        $out = [];
        foreach (array_chunk($lineIds, 1000) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->pdo()->prepare("SELECT line_id, dimension_type_id, dimension_value_id, assignment_origin, assignment_origin_ref FROM {$table} WHERE supplier_id = ? AND line_id IN ({$marks}) ORDER BY line_id, dimension_type_id, dimension_value_id" . ($forUpdate ? ' FOR UPDATE' : ''));
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $origin = ['origin' => $row['assignment_origin'], 'ref' => $row['assignment_origin_ref']];
                if ($table === 'journal_entry_line_dimensions') $out[(int) $row['line_id']][(int) $row['dimension_type_id']] = $origin;
                else $out[(int) $row['line_id']][(int) $row['dimension_type_id']][(int) $row['dimension_value_id']] = $origin;
            }
        }
        return $out;
    }

    /** @param list<array{0:int,1:string,2:int,3:int,4:int,5:int,6:string,7:?string}> $rows */
    private function insertDocumentRows(array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->db->pdo()->prepare(
                'INSERT INTO document_dimensions (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id, assignment_origin, assignment_origin_ref) VALUES '
                . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)'))
            )->execute(array_merge(...$chunk));
        }
    }
}
