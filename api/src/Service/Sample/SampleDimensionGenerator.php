<?php

declare(strict_types=1);

namespace MyInvoice\Service\Sample;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\CostCenterRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use PDO;

final class SampleDimensionGenerator
{
    public function __construct(
        private readonly Connection $db,
        private readonly DimensionService $dimensions,
        private readonly DimensionRepository $repo,
        private readonly DimensionAssignmentRepository $assignments,
        private readonly CostCenterRepository $costCenters,
    ) {}

    public function generate(int $supplierId, callable $track): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new \LogicException('Ukázkové dimenze vyžadují otevřenou transakci.');
        $supplier = $this->rows('SELECT id, dimensions_enabled, supplier_group_id FROM supplier WHERE id = ? FOR UPDATE', [$supplierId])[0] ?? null;
        if ($supplier === null) throw new \DomainException('Firma pro ukázkové dimenze neexistuje.');
        if ($this->rows('SELECT entity_id FROM sample_data_entries WHERE supplier_id = ? AND entity_type = "dim_restore_enabled"', [$supplierId]) !== []) {
            throw new \DomainException('Ukázkové dimenze již existují; nejprve je odeberte.');
        }
        if ($supplier['supplier_group_id'] !== null) {
            $this->rows('SELECT id FROM supplier_groups WHERE id = ? FOR UPDATE', [(int) $supplier['supplier_group_id']]);
        }
        $project = $this->rows('SELECT p.id, p.name FROM projects p JOIN clients c ON c.id=p.client_id JOIN sample_data_entries s ON s.supplier_id=c.supplier_id AND s.entity_type="project" AND s.entity_id=p.id WHERE c.supplier_id=? ORDER BY p.id LIMIT 1', [$supplierId])[0] ?? null;
        $car = $this->rows('SELECT c.id, c.name FROM cars c JOIN sample_data_entries s ON s.supplier_id=c.supplier_id AND s.entity_type="car" AND s.entity_id=c.id WHERE c.supplier_id=? ORDER BY c.id LIMIT 1', [$supplierId])[0] ?? null;
        if ($project === null || $car === null) throw new \DomainException('Ukázkové dimenze vyžadují evidovanou ukázkovou zakázku a vozidlo.');

        $counts = ['dimension_types' => 0, 'dimension_values' => 0, 'dimension_documents' => 0, 'dimension_journal_lines' => 0, 'dimension_defaults' => 0, 'dimension_cost_centers' => 0];
        $track('dim_restore_enabled', (int) $supplier['dimensions_enabled'] + 1);
        $this->dimensions->setEnabled($supplierId, true);
        $beforeTypes = array_column($this->repo->listTypes($supplierId), 'id');
        $types = $this->dimensions->ensureDefaultTypes($supplierId);
        $projectType = $this->repo->findType($supplierId, $types['project']);
        if ($projectType['level'] === 'global') {
            $local = $this->repo->findTypeByKind($supplierId, 'project', false);
            $types['project'] = $local !== null ? (int) $local['id'] : (int) $this->dimensions->createType($supplierId, [
                'code' => 'demo_projekt', 'name' => 'Projekt ukázkové firmy', 'kind' => 'project', 'level' => 'company', 'sort_order' => 21,
            ])['id'];
        }
        foreach ($this->repo->listTypes($supplierId) as $type) {
            if (!in_array($type['id'], $beforeTypes, true)) {
                $track('dimension_type', $type['id']);
                $counts['dimension_types']++;
            }
        }
        foreach (['cost_center', 'project', 'vehicle'] as $kind) {
            $type = $this->repo->findType($supplierId, $types[$kind]);
            if (!$type['is_active'] || !empty($type['drives_accounts'])) {
                throw new \DomainException('Ukázkové dimenze vyžadují aktivní typ bez účtotvorného mapování: ' . $type['name']);
            }
        }
        $code = 'DEMO-' . $supplierId . '-PROVOZ';
        for ($n = 2; $this->costCenters->findByCode($supplierId, $code) !== null; $n++) $code = 'DEMO-' . $supplierId . '-PROVOZ-' . $n;
        $centerId = $this->costCenters->create($supplierId, $code, 'Ukázkový provoz');
        $track('dimension_cost_center', $centerId);
        $counts['dimension_cost_centers']++;
        $specs = [
            'cost_center' => ['code' => $code, 'name' => 'Ukázkový provoz', 'cost_center_id' => $centerId],
            'project' => ['code' => 'DEMO-' . $supplierId . '-PROJEKT', 'name' => 'Ukázková zakázka: ' . $project['name'], 'project_id' => (int) $project['id']],
            'vehicle' => ['code' => 'DEMO-' . $supplierId . '-VOZIDLO', 'name' => 'Ukázkové vozidlo: ' . $car['name'], 'car_id' => (int) $car['id']],
        ];
        $map = [];
        foreach ($specs as $kind => $body) {
            $base = $body['code'];
            for ($n = 2; $this->repo->findValueByCode($types[$kind], $body['code']) !== null; $n++) $body['code'] = $base . '-' . $n;
            $value = $this->dimensions->createValue($supplierId, $types[$kind], $body);
            $map[$types[$kind]] = (int) $value['id'];
            $track('dimension_value', (int) $value['id']);
            $counts['dimension_values']++;
        }
        $common = array_diff_key($map, [$types['vehicle'] => true]);
        $centerOnly = [$types['cost_center'] => $map[$types['cost_center']]];
        foreach ($common as $typeId => $valueId) {
            $stmt = $pdo->prepare('INSERT INTO dimension_defaults (supplier_id, project_id, dimension_type_id, dimension_value_id) SELECT ?, ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM dimension_defaults WHERE supplier_id=? AND project_id=? AND dimension_type_id=?)');
            $stmt->execute([$supplierId, $project['id'], $typeId, $valueId, $supplierId, $project['id'], $typeId]);
            if ($stmt->rowCount() > 0) {
                $track('dim_default', (int) $pdo->lastInsertId());
                $counts['dimension_defaults']++;
            }
        }

        $documentMaps = [];
        foreach (['invoice' => 'invoices', 'purchase_invoice' => 'purchase_invoices', 'cash_document' => 'cash_documents'] as $docType => $table) {
            $docs = $this->rows("SELECT DISTINCT d.id, d.project_id FROM `$table` d JOIN sample_data_entries s ON s.supplier_id=d.supplier_id AND s.entity_id=d.id AND s.entity_type IN (?, ?) WHERE d.supplier_id=? ORDER BY d.id", [$docType, $docType === 'invoice' ? 'credit_note' : $docType, $supplierId]);
            foreach ($docs as $doc) {
                $docId = (int) $doc['id'];
                $dims = (int) ($doc['project_id'] ?? 0) === (int) $project['id'] ? $common : $centerOnly;
                if ($docType === 'purchase_invoice' && $this->rows('SELECT 1 FROM purchase_invoice_items WHERE purchase_invoice_id=? AND description LIKE "%Servis firemního automobilu%" LIMIT 1', [$docId]) !== []) $dims[$types['vehicle']] = $map[$types['vehicle']];
                $current = $this->assignments->documentDimensions($supplierId, $docType, $docId);
                $splits = $this->assignments->documentSplits($supplierId, $docType, $docId);
                $added = false;
                foreach ($dims as $typeId => $valueId) {
                    if (isset($current['header'][$typeId]) || isset($splits[0][$typeId])) continue;
                    if ($this->assignments->addHeaderDimensionIfMissing($supplierId, $docType, $docId, $typeId, $valueId)) $added = true;
                }
                $documentMaps[$docType . ':' . $docId] = $current['header'] + $dims;
                if ($added) {
                    $track('dim_doc_' . $docType, $docId);
                    $counts['dimension_documents']++;
                }
            }
        }
        $lines = $this->rows('SELECT l.id, je.source_type, je.source_id FROM journal_entry_lines l JOIN journal_entries je ON je.id=l.entry_id AND je.supplier_id=l.supplier_id JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id WHERE l.supplier_id=? AND a.account_code REGEXP "^[56]"
            AND (EXISTS (SELECT 1 FROM sample_data_entries s WHERE s.supplier_id=je.supplier_id AND s.entity_type="journal_entry" AND s.entity_id=je.id)
              OR EXISTS (SELECT 1 FROM sample_data_entries s WHERE s.supplier_id=je.supplier_id AND s.entity_type=je.source_type AND s.entity_id=je.source_id)
              OR (je.source_type="invoice" AND EXISTS (SELECT 1 FROM sample_data_entries s WHERE s.supplier_id=je.supplier_id AND s.entity_type="credit_note" AND s.entity_id=je.source_id)))
            ORDER BY l.id', [$supplierId]);
        $lineIds = array_column($lines, 'id');
        $currentDimensions = $this->assignments->lineDimensions($supplierId, $lineIds);
        $currentSplits = $this->assignments->lineSplits($supplierId, $lineIds);
        $newAssignments = [];
        foreach ($lines as $line) {
            $lineId = (int) $line['id'];
            $current = $currentDimensions[$lineId] ?? [];
            $splits = $currentSplits[$lineId] ?? [];
            $dims = $documentMaps[$line['source_type'] . ':' . $line['source_id']] ?? $centerOnly;
            $new = array_intersect_key(array_diff_key($dims, $current, $splits), $map);
            $new = array_filter($new, static fn (int $id): bool => in_array($id, $map, true));
            if ($new === []) continue;
            $newAssignments[$lineId] = ['dimensions' => $new];
        }
        foreach (array_chunk($newAssignments, 500, true) as $batch) {
            $this->assignments->insertLineAssignments($supplierId, $batch);
            foreach (array_keys($batch) as $lineId) {
                $track('dim_line', $lineId);
                $counts['dimension_journal_lines']++;
            }
        }
        return $counts;
    }

    private function rows(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
