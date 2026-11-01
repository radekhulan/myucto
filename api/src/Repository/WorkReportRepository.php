<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\TimeBilling;
use MyInvoice\Service\WorkReport\WorkReportDisplayOptions;
use PDO;

final class WorkReportRepository
{
    public function __construct(private readonly Connection $db) {}

    public function findByInvoice(int $invoiceId, int $supplierId): ?array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT wr.*, s.work_report_display_options AS display_defaults
               FROM invoices i
               JOIN work_reports wr ON wr.invoice_id = i.id
               JOIN supplier s ON s.id = i.supplier_id
              WHERE i.supplier_id = ? AND i.id = ?');
        $stmt->execute([$supplierId, $invoiceId]);
        $wr = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($wr === false) return null;

        $wr['total_hours']          = (float) $wr['total_hours'];
        $wr['total_amount']         = (float) $wr['total_amount'];
        $wr['vat_rate_id']          = isset($wr['vat_rate_id']) ? (int) $wr['vat_rate_id'] : null;
        $wr['material_title']       = $wr['material_title'] !== null ? (string) $wr['material_title'] : null;
        $wr['material_total']       = (float) ($wr['material_total'] ?? 0);
        $wr['material_vat_rate_id'] = isset($wr['material_vat_rate_id']) ? (int) $wr['material_vat_rate_id'] : null;

        // Sazba DPH materiálu (procenta) + součet s DPH — pro výkaz materiálu u plátce
        // (PDF tiskne „Celkem bez DPH" i „Celkem s DPH"). material_total je základ daně
        // (netto, suma quantity × unit_price), proto DPH připočítáme shora základu.
        $wr['material_vat_rate']       = null;
        $wr['material_total_with_vat'] = $wr['material_total'];
        if ($wr['material_vat_rate_id'] !== null) {
            $rate = $this->ratePercent($wr['material_vat_rate_id']);
            if ($rate !== null) {
                $wr['material_vat_rate']       = $rate;
                $wr['material_total_with_vat'] = round(
                    $wr['material_total'] + round($wr['material_total'] * $rate / 100, 2),
                    2
                );
            }
        }

        $wr = self::withDisplayOptions($wr);
        $wr['items']                = $this->itemsFor((int) $wr['id']);
        $wr['materials']            = $this->materialsFor((int) $wr['id']);
        return $wr;
    }

    /**
     * Batch varianta findByInvoice() — načte výkazy pro dané ID faktur v jednom
     * dotazu (+ dávkově items, materials, sazby DPH), keyed by invoice_id. Nahrazuje
     * N+1 smyčku `foreach ($rows as $row) findByInvoice($row['id'], $supplierId)` v buildPreview.
     * Transformace per-výkaz jsou identické s findByInvoice().
     *
     * @param list<int> $invoiceIds
     * @return array<int, array<string,mixed>> invoice_id → výkaz (jako findByInvoice)
     */
    public function findByInvoiceIds(array $invoiceIds, int $supplierId): array
    {
        $invoiceIds = array_values(array_unique(array_map('intval', $invoiceIds)));
        if ($invoiceIds === []) {
            return [];
        }
        $pdo = $this->db->pdo();
        $place = implode(',', array_fill(0, count($invoiceIds), '?'));

        $stmt = $pdo->prepare("SELECT wr.*, s.work_report_display_options AS display_defaults
               FROM invoices i
               JOIN work_reports wr ON wr.invoice_id = i.id
               JOIN supplier s ON s.id = i.supplier_id
              WHERE i.supplier_id = ? AND i.id IN ($place)");
        $stmt->execute([$supplierId, ...$invoiceIds]);
        $reports = [];        // invoice_id → wr
        $wrIds = [];          // work_report id list (pro items/materials)
        $rateIds = [];        // material_vat_rate_id set (pro batched ratePercent)
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $wr) {
            $invoiceId = (int) $wr['invoice_id'];
            if (isset($reports[$invoiceId])) {
                continue; // zachovej první výskyt (jako fetch() ve findByInvoice)
            }
            $wr['total_hours']          = (float) $wr['total_hours'];
            $wr['total_amount']         = (float) $wr['total_amount'];
            $wr['vat_rate_id']          = isset($wr['vat_rate_id']) ? (int) $wr['vat_rate_id'] : null;
            $wr['material_title']       = $wr['material_title'] !== null ? (string) $wr['material_title'] : null;
            $wr['material_total']       = (float) ($wr['material_total'] ?? 0);
            $wr['material_vat_rate_id'] = isset($wr['material_vat_rate_id']) ? (int) $wr['material_vat_rate_id'] : null;
            $wr['material_vat_rate']       = null;
            $wr['material_total_with_vat'] = $wr['material_total'];
            if ($wr['material_vat_rate_id'] !== null) {
                $rateIds[$wr['material_vat_rate_id']] = $wr['material_vat_rate_id'];
            }
            $reports[$invoiceId] = $wr;
            $wrIds[] = (int) $wr['id'];
        }

        if ($reports === []) {
            return [];
        }

        // Sazby DPH materiálu dávkově.
        $rates = [];
        if ($rateIds !== []) {
            $rateIds = array_values($rateIds);
            $rp = implode(',', array_fill(0, count($rateIds), '?'));
            $rstmt = $pdo->prepare("SELECT id, rate_percent FROM vat_rates WHERE id IN ($rp)");
            $rstmt->execute($rateIds);
            foreach ($rstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rates[(int) $r['id']] = (float) $r['rate_percent'];
            }
        }

        // Items + materials všech výkazů dávkově, seskupené po work_report_id.
        $wp = implode(',', array_fill(0, count($wrIds), '?'));
        $itemsBy = [];
        $istmt = $pdo->prepare(
            "SELECT id, work_report_id, description, work_date, hours, duration_minutes, rate, total_amount, order_index
               FROM work_report_items
              WHERE work_report_id IN ($wp)
           ORDER BY work_report_id, order_index, id"
        );
        $istmt->execute($wrIds);
        foreach ($istmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $wrid = (int) $r['work_report_id'];
            unset($r['work_report_id']);
            $r['hours']        = (float) $r['hours'];
            $r['duration_minutes'] = $r['duration_minutes'] !== null ? (int) $r['duration_minutes'] : null;
            $r['rate']         = (float) $r['rate'];
            $r['total_amount'] = (float) $r['total_amount'];
            $r['order_index']  = (int) $r['order_index'];
            $r['id']           = (int) $r['id'];
            $r['work_date']    = $r['work_date'] ?: null;
            $itemsBy[$wrid][] = $r;
        }

        $matsBy = [];
        $mstmt = $pdo->prepare(
            "SELECT id, work_report_id, description, quantity, unit, unit_price, total_amount, order_index
               FROM work_report_materials
              WHERE work_report_id IN ($wp)
           ORDER BY work_report_id, order_index, id"
        );
        $mstmt->execute($wrIds);
        foreach ($mstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $wrid = (int) $r['work_report_id'];
            unset($r['work_report_id']);
            $r['id']           = (int) $r['id'];
            $r['quantity']     = (float) $r['quantity'];
            $r['unit']         = (string) $r['unit'];
            $r['unit_price']   = (float) $r['unit_price'];
            $r['total_amount'] = (float) $r['total_amount'];
            $r['order_index']  = (int) $r['order_index'];
            $matsBy[$wrid][] = $r;
        }

        // Dopočet sazby materiálu + items/materials per výkaz (identicky s findByInvoice).
        foreach ($reports as $invoiceId => $wr) {
            $wrid = (int) $wr['id'];
            if ($wr['material_vat_rate_id'] !== null && isset($rates[$wr['material_vat_rate_id']])) {
                $rate = $rates[$wr['material_vat_rate_id']];
                $wr['material_vat_rate']       = $rate;
                $wr['material_total_with_vat'] = round(
                    $wr['material_total'] + round($wr['material_total'] * $rate / 100, 2),
                    2
                );
            }
            $wr['items']     = $itemsBy[$wrid] ?? [];
            $wr['materials'] = $matsBy[$wrid] ?? [];
            $reports[$invoiceId] = self::withDisplayOptions($wr);
        }

        return $reports;
    }

    /** Procentní sazba DPH z číselníku (rate_percent), nebo null když ID neexistuje. */
    private function ratePercent(int $vatRateId): ?float
    {
        $stmt = $this->db->pdo()->prepare('SELECT rate_percent FROM vat_rates WHERE id = ?');
        $stmt->execute([$vatRateId]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (float) $val;
    }

    /** @return list<array<string,mixed>> */
    private function itemsFor(int $workReportId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id, description, work_date, hours, duration_minutes, rate, total_amount, order_index
               FROM work_report_items
              WHERE work_report_id = ?
           ORDER BY order_index, id'
        );
        $stmt->execute([$workReportId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['hours']        = (float) $r['hours'];
            $r['duration_minutes'] = $r['duration_minutes'] !== null ? (int) $r['duration_minutes'] : null;
            $r['rate']         = (float) $r['rate'];
            $r['total_amount'] = (float) $r['total_amount'];
            $r['order_index']  = (int) $r['order_index'];
            $r['id']           = (int) $r['id'];
            $r['work_date']    = $r['work_date'] ?: null;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function materialsFor(int $workReportId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id, description, quantity, unit, unit_price, total_amount, order_index
               FROM work_report_materials
              WHERE work_report_id = ?
           ORDER BY order_index, id'
        );
        $stmt->execute([$workReportId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']           = (int) $r['id'];
            $r['quantity']     = (float) $r['quantity'];
            $r['unit']         = (string) $r['unit'];
            $r['unit_price']   = (float) $r['unit_price'];
            $r['total_amount'] = (float) $r['total_amount'];
            $r['order_index']  = (int) $r['order_index'];
        }
        return $rows;
    }

    /**
     * Uloží work_report (upsert) + nahradí items (část PRÁCE).
     * Nesahá na materiál (material_*, work_report_materials).
     * Vrací id work_reportu.
     *
     * @param list<array<string,mixed>>|null $items null = řádky i součty existujícího výkazu ponechat
     */
    public function save(int $invoiceId, int $supplierId, ?int $projectId, string $title, ?array $items, ?int $vatRateId = null): int
    {
        $pdo = $this->db->pdo();
        $existing = $this->findByInvoice($invoiceId, $supplierId);

        // project_id je nullable — faktura nemusí mít zakázku.
        $projectIdParam = ($projectId !== null && $projectId > 0) ? $projectId : null;
        if ($items === null && $existing) {
            $id = (int) $existing['id'];
            $pdo->prepare('UPDATE work_reports SET project_id=?, title=?, vat_rate_id=? WHERE id=?')
                ->execute([$projectIdParam, $title, $vatRateId, $id]);
            return $id;
        }
        $items ??= [];

        $items = array_map(
            static fn (array $item): array => TimeBilling::normalizeWorkReportItem($item),
            array_values($items),
        );

        $totalHours  = 0.0;
        $totalAmount = 0.0;
        foreach ($items as $it) {
            $totalHours  += (float) ($it['hours'] ?? 0);
            $totalAmount += $it['duration_minutes'] !== null
                ? TimeBilling::workAmount($it)
                : TimeBilling::workAmountInput($it);
        }

        if ($existing) {
            $id = (int) $existing['id'];
            $pdo->prepare(
                'UPDATE work_reports SET project_id=?, title=?, total_hours=?, total_amount=?, vat_rate_id=? WHERE id=?'
            )->execute([$projectIdParam, $title, $totalHours, $totalAmount, $vatRateId, $id]);
        } else {
            $insertReport = $pdo->prepare(
                'INSERT INTO work_reports (invoice_id, project_id, title, total_hours, total_amount, vat_rate_id)
                 SELECT i.id, ?, ?, ?, ?, ? FROM invoices i WHERE i.supplier_id = ? AND i.id = ?'
            );
            $insertReport->execute([$projectIdParam, $title, $totalHours, $totalAmount, $vatRateId, $supplierId, $invoiceId]);
            if ($insertReport->rowCount() !== 1) {
                throw new \InvalidArgumentException('Faktura nepatří zadané firmě.');
            }
            $id = (int) $pdo->lastInsertId();
        }

        // Nahradit items
        $pdo->prepare('DELETE FROM work_report_items WHERE work_report_id = ?')->execute([$id]);
        $insert = $pdo->prepare(
            'INSERT INTO work_report_items (work_report_id, description, work_date, hours, duration_minutes, rate, total_amount, order_index)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        foreach ($items as $idx => $it) {
            $hours = (float) ($it['hours'] ?? 0);
            $rate  = (float) ($it['rate'] ?? 0);
            $workDate = isset($it['work_date']) ? trim((string) $it['work_date']) : '';
            $insert->execute([
                $id,
                (string) ($it['description'] ?? ''),
                $workDate !== '' ? $workDate : null,
                $hours,
                $it['duration_minutes'],
                $rate,
                TimeBilling::workAmount($it),
                (int) ($it['order_index'] ?? $idx),
            ]);
        }

        return $id;
    }

    /**
     * Uloží část MATERIÁL téže work_reports řádky (upsert) + nahradí work_report_materials.
     * Řádka work_reports vznikne lazy, pokud ještě neexistuje (title = materialTitle fallback).
     * Nesahá na práci (title/total_hours/total_amount/work_report_items).
     * Vrací id work_reportu.
     *
     * @param list<array<string,mixed>>|null $materials null = řádky i součet existujícího výkazu ponechat
     */
    public function saveMaterials(int $invoiceId, int $supplierId, ?int $projectId, ?string $materialTitle, ?int $materialVatRateId, ?array $materials): int
    {
        $pdo = $this->db->pdo();
        $existing = $this->findByInvoice($invoiceId, $supplierId);
        if ($materials === null && $existing) {
            $id = (int) $existing['id'];
            $pdo->prepare('UPDATE work_reports SET material_title=?, material_vat_rate_id=? WHERE id=?')
                ->execute([$materialTitle, $materialVatRateId, $id]);
            return $id;
        }
        $materials ??= [];

        $materialTotal = 0.0;
        foreach ($materials as $m) {
            $materialTotal += round((float) ($m['quantity'] ?? 0) * (float) ($m['unit_price'] ?? 0), 2);
        }

        $projectIdParam = ($projectId !== null && $projectId > 0) ? $projectId : null;

        if ($existing) {
            $id = (int) $existing['id'];
            $pdo->prepare(
                'UPDATE work_reports SET material_title=?, material_total=?, material_vat_rate_id=? WHERE id=?'
            )->execute([$materialTitle, $materialTotal, $materialVatRateId, $id]);
        } else {
            // Lazy vznik řádky — práce zatím prázdná (title je NOT NULL → fallback na materialTitle).
            $title = ($materialTitle !== null && $materialTitle !== '') ? $materialTitle : 'Materiál';
            $insertReport = $pdo->prepare(
                'INSERT INTO work_reports (invoice_id, project_id, title, total_hours, total_amount, material_title, material_total, material_vat_rate_id)
                 SELECT i.id, ?, ?, 0, 0, ?, ?, ? FROM invoices i WHERE i.supplier_id = ? AND i.id = ?'
            );
            $insertReport->execute([$projectIdParam, $title, $materialTitle, $materialTotal, $materialVatRateId, $supplierId, $invoiceId]);
            if ($insertReport->rowCount() !== 1) {
                throw new \InvalidArgumentException('Faktura nepatří zadané firmě.');
            }
            $id = (int) $pdo->lastInsertId();
        }

        // Nahradit řádky materiálu
        $pdo->prepare('DELETE FROM work_report_materials WHERE work_report_id = ?')->execute([$id]);
        $insert = $pdo->prepare(
            'INSERT INTO work_report_materials (work_report_id, description, quantity, unit, unit_price, total_amount, order_index)
             VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($materials as $idx => $m) {
            $quantity  = (float) ($m['quantity'] ?? 0);
            $unitPrice = (float) ($m['unit_price'] ?? 0);
            $unit      = trim((string) ($m['unit'] ?? 'ks'));
            $insert->execute([
                $id,
                (string) ($m['description'] ?? ''),
                $quantity,
                $unit !== '' ? $unit : 'ks',
                $unitPrice,
                round($quantity * $unitPrice, 2),
                (int) ($m['order_index'] ?? $idx),
            ]);
        }

        return $id;
    }

    /** Existuje sazba DPH v číselníku? (validace vat_rate_id z výkazu.) */
    public function vatRateExists(int $vatRateId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM vat_rates WHERE id = ?');
        $stmt->execute([$vatRateId]);
        return $stmt->fetchColumn() !== false;
    }

    private static function withDisplayOptions(array $report): array
    {
        $report['display_options'] = WorkReportDisplayOptions::decode($report['display_options'] ?? null);
        $report['effective_display_options'] = WorkReportDisplayOptions::resolve($report['display_defaults'] ?? null, $report['display_options']);
        unset($report['display_defaults']);
        return $report;
    }

    /** Mění jen zobrazení; řádky a finanční součty zůstávají uložené. */
    public function saveDisplayOptions(int $invoiceId, ?array $options, int $supplierId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE work_reports wr JOIN invoices i ON i.id = wr.invoice_id
                SET wr.display_options = ? WHERE i.supplier_id = ? AND i.id = ?'
        )->execute([$options === null ? null : json_encode((object) $options, JSON_THROW_ON_ERROR), $supplierId, $invoiceId]);
    }

    public function deleteByInvoice(int $invoiceId, int $supplierId): bool
    {
        $pdo = $this->db->pdo();
        return $pdo->prepare('DELETE wr FROM work_reports wr JOIN invoices i ON i.id = wr.invoice_id
            WHERE i.supplier_id = ? AND i.id = ?')->execute([$supplierId, $invoiceId]);
    }
}
