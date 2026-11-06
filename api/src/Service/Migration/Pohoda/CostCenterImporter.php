<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CostCenterRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionService;

/**
 * Střediska z deníku POHODY jako dimenze Středisko (Firma → Dimenze), stejně jako převod
 * z Money S3. Kód střediska na řádek zapisuje {@see ChartJournalImporter}; tady vznikne
 * středisko, hodnota dimenze a razítko řádků nově převedených zápisů. Doklad dostane
 * středisko, pokud je na všech jeho řádcích stejné, aby ho přeúčtování dokladu neztratilo.
 *
 * Export bez středisek nic nemění a dimenze u firmy nezapíná. Opakovaný převod zápisy
 * nepřevádí znovu, takže středisko změněné v MyÚčtu nepřepíše.
 */
final class CostCenterImporter
{
    /** Zdroj zápisu v deníku => typ dokladu v document_dimensions. */
    private const DOCUMENT_SOURCES = [
        'purchase_invoice' => 'purchase_invoice',
        'invoice' => 'invoice',
        'cash' => 'cash_document',
        'bank' => 'bank_transaction',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly CostCenterRepository $costCenters,
        private readonly DimensionRepository $dimensions,
        private readonly DimensionService $dimensionService,
    ) {}

    public function run(PohodaContext $ctx): void
    {
        if ($ctx->costCenters === []) {
            return;
        }
        $this->dimensionService->setEnabled($ctx->supplierId, true);
        $typeId = (int) $this->dimensionService->ensureDefaultTypes($ctx->supplierId, ['stredisko'])['cost_center'];
        $pdo = $this->db->pdo();
        $stamp = $pdo->prepare("INSERT IGNORE INTO journal_entry_line_dimensions
                (line_id, dimension_type_id, supplier_id, dimension_value_id, assignment_origin, assignment_origin_ref)
            SELECT l.id, ?, l.supplier_id, ?, 'explicit', 'pohoda_import'
              FROM journal_entry_lines l
             WHERE l.supplier_id = ? AND l.cost_center = ?");
        $lines = 0;
        foreach ($ctx->costCenters as $code => $name) {
            $code = (string) $code;
            $stamp->execute([$typeId, $this->value($ctx, $typeId, $code, $name), $ctx->supplierId, $code]);
            $lines += $stamp->rowCount();
        }
        $this->stampDocuments($ctx->supplierId, $typeId);
        $ctx->protocol->info(PohodaReconciler::STEP, 'cost_centers', sprintf(
            'Střediska z POHODY: %d (%s), %d řádků deníku. Dimenze jsou zapnuté v Firma → Dimenze.',
            count($ctx->costCenters), implode(', ', array_map('strval', array_keys($ctx->costCenters))), $lines,
        ), ['codes' => array_map('strval', array_keys($ctx->costCenters))]);
    }

    private function value(PohodaContext $ctx, int $typeId, string $code, string $name): int
    {
        $existing = $this->dimensions->findValueByCode($typeId, mb_substr($code, 0, 50));
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        $center = $this->costCenters->findByCode($ctx->supplierId, $code);
        $centerId = $center !== null ? (int) $center['id'] : $this->costCenters->create($ctx->supplierId, $code, mb_substr($name !== '' ? $name : $code, 0, 190));
        return $this->dimensions->createValue((array) $this->dimensions->findType($ctx->supplierId, $typeId), [
            'code' => mb_substr($code, 0, 50),
            'name' => mb_substr($name !== '' ? $name : $code, 0, 190),
            'is_active' => true,
            'car_id' => null,
            'cost_center_id' => $centerId,
        ]);
    }

    private function stampDocuments(int $supplierId, int $typeId): void
    {
        $pdo = $this->db->pdo();
        $insert = $pdo->prepare('INSERT IGNORE INTO document_dimensions
            (supplier_id, doc_type, doc_id, item_no, dimension_type_id, dimension_value_id) VALUES (?, ?, ?, 0, ?, ?)');
        // STRAIGHT_JOIN jako u Money S3: čerstvě naplněné tabulky nemají statistiky.
        $rows = $pdo->prepare("SELECT je.source_id, MIN(jd.dimension_value_id) AS value_id
              FROM journal_entries je
              STRAIGHT_JOIN journal_entry_lines jel ON jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
              STRAIGHT_JOIN journal_entry_line_dimensions jd ON jd.line_id = jel.id
             WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id IS NOT NULL AND jd.dimension_type_id = ?
             GROUP BY je.source_id
            HAVING COUNT(DISTINCT jd.dimension_value_id) = 1");
        foreach (self::DOCUMENT_SOURCES as $sourceType => $docType) {
            $rows->execute([$supplierId, $sourceType, $typeId]);
            foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $insert->execute([$supplierId, $docType, (int) $row['source_id'], $typeId, (int) $row['value_id']]);
            }
        }
    }
}
