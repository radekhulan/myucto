<?php

declare(strict_types=1);

namespace MyInvoice\Service\TaxEvidence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Demo\DemoMode;

final class AnnualClosingService
{
    private const CHECKLIST_KEYS = [
        'cash_journal_reviewed', 'non_cash_reviewed', 'fixed_assets_inventoried',
        'inventory_inventoried', 'receivables_inventoried', 'liabilities_inventoried',
        'high_value_purchases_reviewed', 'transition_reviewed', 'foreign_exchange_reviewed',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly CashJournalService $cashJournal,
        private readonly TaxConstantsRepository $constants,
        private readonly TaxExpenseAllocationCalculator $taxExpenses,
        private readonly DemoMode $demo,
    ) {}

    /** @return array<string,mixed> */
    public function get(int $supplierId, int $year): array
    {
        $this->assertYear($year);
        $row = $this->find($supplierId, $year);
        if ($row === null && $this->demo->enabled()) {
            // Ukázka je jen ke čtení: otevření uzávěrky roku ji nezakládá, ukáže se
            // prázdný rozpracovaný stav se stejnými výchozími hodnotami jako v tabulce.
            return [
                'id' => 0, 'supplier_id' => $supplierId, 'year' => $year, 'status' => 'draft',
                'checklist' => [], 'opening_balances' => [], 'closing_balances' => [],
                'unsupported_cases' => [], 'source_snapshot' => [], 'source_hash' => null,
                'row_version' => 1, 'finalized_at' => null, 'finalized_by' => null, 'adjustments' => [],
            ];
        }
        if ($row === null) {
            $this->db->pdo()->prepare(
                'INSERT INTO tax_evidence_closings (supplier_id, year) VALUES (?, ?)'
            )->execute([$supplierId, $year]);
            $row = $this->find($supplierId, $year);
        }
        return $row ?? [];
    }

    /** @return array<string,mixed> */
    public function save(int $supplierId, int $year, array $data, int $expectedVersion, ?int $userId): array
    {
        $row = $this->get($supplierId, $year);
        if (($row['status'] ?? '') === 'final') {
            throw new \DomainException('Uzávěrka je finální; nejprve ji vraťte do rozpracovaného stavu.');
        }
        // Vynechaná sekce i vynechaný klíč uvnitř sekce drží uložený stav (issue #113);
        // explicitní null sekci vyprázdní.
        $section = static fn (string $key): array => array_key_exists($key, $data)
            ? ($data[$key] === null ? [] : array_replace((array) ($row[$key] ?? []), (array) $data[$key]))
            : (array) ($row[$key] ?? []);
        $checklistData = $section('checklist');
        $checklist = [];
        foreach (self::CHECKLIST_KEYS as $key) {
            $checklist[$key] = !empty($checklistData[$key]);
        }
        $opening = $this->balances($section('opening_balances'));
        $closing = $this->balances($section('closing_balances'));
        $unsupported = array_values(array_filter(array_map(
            static fn ($v): string => mb_substr(trim((string) $v), 0, 500),
            array_key_exists('unsupported_cases', $data)
                ? (array) ($data['unsupported_cases'] ?? [])
                : (array) ($row['unsupported_cases'] ?? []),
        )));
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
        $stmt = $pdo->prepare(
            'UPDATE tax_evidence_closings
                SET checklist = ?, opening_balances = ?, closing_balances = ?, unsupported_cases = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND year = ? AND status = \'draft\' AND row_version = ?'
        );
        $stmt->execute([$this->json($checklist), $this->json($opening), $this->json($closing),
            $this->json($unsupported), $supplierId, $year, $expectedVersion]);
        if ($stmt->rowCount() === 0) {
            throw new \DomainException('Uzávěrka byla mezitím změněna; načtěte ji znovu.');
        }
        if (array_key_exists('adjustments', $data) && is_array($data['adjustments'])) {
            $this->replaceAdjustments((int) $row['id'], $supplierId, $year, $data['adjustments'], $userId);
        }
        if ($ownTx) {
            $pdo->commit();
        }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $this->get($supplierId, $year);
    }

    /** @return array<string,mixed> */
    public function finalize(int $supplierId, int $year, int $expectedVersion, ?int $userId): array
    {
        $row = $this->get($supplierId, $year);
        if (($row['status'] ?? '') === 'final') {
            throw new \DomainException('Uzávěrka je již finální.');
        }
        $missing = [];
        foreach (self::CHECKLIST_KEYS as $key) {
            if (empty($row['checklist'][$key])) {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            throw new \DomainException('Uzávěrku nelze dokončit; chybí kontroly: ' . implode(', ', $missing) . '.');
        }
        if ((array) ($row['unsupported_cases'] ?? []) !== []) {
            throw new \DomainException('Uzávěrka obsahuje nepodporované situace, které musí posoudit účetní nebo daňový poradce.');
        }
        $this->taxExpenses->assertAnnualCoefficientReady($supplierId, $year);
        $assetLimit = (float) ($this->constants->forYear($year)['fixed_asset_limit'] ?? 80000);
        $highValue = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM purchase_invoices pi
              WHERE pi.supplier_id = ? AND pi.status <> 'cancelled' AND pi.document_kind NOT IN ('advance', 'tax_document')
                AND YEAR(COALESCE(pi.paid_at, pi.issue_date)) = ?
                AND pi.total_with_vat > ? AND pi.tax_deductible = 1 AND COALESCE(pi.is_fixed_asset, 0) = 0
                AND NOT EXISTS (SELECT 1 FROM purchase_invoice_vat_allocations a
                                  WHERE a.supplier_id = pi.supplier_id AND a.purchase_invoice_id = pi.id)"
        );
        $highValue->execute([$supplierId, $year, $assetLimit]);
        if ((int) $highValue->fetchColumn() > 0) {
            throw new \DomainException('Vysoké nákupy bez označení majetku nebo řádkové daňové alokace musí být před uzávěrkou posouzeny.');
        }
        $journal = $this->cashJournal->build(
            $supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year), ['year' => $year]
        );
        foreach ((array) ($journal['warnings'] ?? []) as $warning) {
            if (is_array($warning) && !empty($warning['blocking'])) {
                throw new \DomainException((string) ($warning['message'] ?? 'Peněžní deník obsahuje blokující chybu.'));
            }
        }
        $snapshot = [
            'year' => $year,
            'journal' => [
                'totals' => $journal['totals'] ?? [],
                'rows' => array_map(static fn (array $r): array => [
                    'source_type' => $r['source_type'] ?? null,
                    'source_id' => $r['source_id'] ?? null,
                    'date' => $r['date'] ?? null,
                    'direction' => $r['direction'] ?? null,
                    'income' => $r['income'] ?? null,
                    'expense' => $r['expense'] ?? null,
                    'bucket' => $r['bucket'] ?? null,
                    'base' => $r['base'] ?? 0,
                    'vat' => $r['vat'] ?? 0,
                ], (array) ($journal['rows'] ?? [])),
            ],
            'opening_balances' => $row['opening_balances'],
            'closing_balances' => $row['closing_balances'],
            'adjustments' => $row['adjustments'],
            'checklist' => $row['checklist'],
        ];
        $json = $this->json($snapshot);
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tax_evidence_closings
                SET status = \'final\', source_snapshot = ?, source_hash = ?, finalized_at = NOW(), finalized_by = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND year = ? AND status = \'draft\' AND row_version = ?'
        );
        $stmt->execute([$json, hash('sha256', $json), $userId, $supplierId, $year, $expectedVersion]);
        if ($stmt->rowCount() === 0) {
            throw new \DomainException('Uzávěrka byla mezitím změněna; načtěte ji znovu.');
        }
        return $this->get($supplierId, $year);
    }

    /** @return array<string,mixed> */
    public function reopen(int $supplierId, int $year, int $expectedVersion): array
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tax_evidence_closings
                SET status = \'draft\', source_snapshot = NULL, source_hash = NULL,
                    finalized_at = NULL, finalized_by = NULL, row_version = row_version + 1
              WHERE supplier_id = ? AND year = ? AND status = \'final\' AND row_version = ?'
        );
        $stmt->execute([$supplierId, $year, $expectedVersion]);
        if ($stmt->rowCount() === 0) {
            throw new \DomainException('Uzávěrku nelze znovu otevřít; načtěte aktuální stav.');
        }
        return $this->get($supplierId, $year);
    }

    /** @return array<string,mixed>|null */
    private function find(int $supplierId, int $year): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, year, status, checklist, opening_balances, closing_balances,
                    unsupported_cases, source_snapshot, source_hash, row_version, finalized_at, finalized_by
               FROM tax_evidence_closings WHERE supplier_id = ? AND year = ?'
        );
        $stmt->execute([$supplierId, $year]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        foreach (['checklist', 'opening_balances', 'closing_balances', 'unsupported_cases', 'source_snapshot'] as $key) {
            $decoded = json_decode((string) ($row[$key] ?? ''), true);
            $row[$key] = is_array($decoded) ? $decoded : [];
        }
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['year'] = (int) $row['year'];
        $row['row_version'] = (int) $row['row_version'];
        $row['finalized_by'] = $row['finalized_by'] === null ? null : (int) $row['finalized_by'];
        $row['adjustments'] = $this->adjustments($supplierId, (int) $row['id']);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function adjustments(int $supplierId, int $closingId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, adjustment_on, kind, direction, amount, description, evidence_ref
               FROM tax_evidence_non_cash_adjustments WHERE supplier_id = ? AND closing_id = ? ORDER BY adjustment_on, id'
        );
        $stmt->execute([$supplierId, $closingId]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'adjustment_on' => (string) $r['adjustment_on'],
            'kind' => (string) $r['kind'], 'direction' => (string) $r['direction'],
            'amount' => round((float) $r['amount'], 2), 'description' => (string) $r['description'],
            'evidence_ref' => $r['evidence_ref'] === null ? null : (string) $r['evidence_ref'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    private function replaceAdjustments(int $closingId, int $supplierId, int $year, array $items, ?int $userId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM tax_evidence_non_cash_adjustments WHERE supplier_id = ? AND closing_id = ?')
            ->execute([$supplierId, $closingId]);
        $stmt = $pdo->prepare(
            'INSERT INTO tax_evidence_non_cash_adjustments
                (supplier_id, closing_id, adjustment_on, kind, direction, amount, description, evidence_ref, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $kinds = ['setoff','barter','in_kind_income','debt_forgiveness','private_use','shortage','damage','inventory','receivable','payable','section23_other'];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $kind = (string) ($item['kind'] ?? 'section23_other');
            $direction = (string) ($item['direction'] ?? 'neutral');
            $stmt->execute([$supplierId, $closingId, $this->date($item['adjustment_on'] ?? '', $year),
                in_array($kind, $kinds, true) ? $kind : 'section23_other',
                in_array($direction, ['increase', 'decrease', 'neutral'], true) ? $direction : 'neutral',
                max(0, round((float) ($item['amount'] ?? 0), 2)),
                mb_substr(trim((string) ($item['description'] ?? '')), 0, 500),
                mb_substr(trim((string) ($item['evidence_ref'] ?? '')), 0, 190) ?: null, $userId]);
        }
    }

    /** @return array<string,float> */
    private function balances(array $data): array
    {
        $out = [];
        foreach (['fixed_assets','cash','bank','inventory','receivables','other_assets','liabilities','reserves','depreciation'] as $key) {
            $out[$key] = max(0, round((float) ($data[$key] ?? 0), 2));
        }
        return $out;
    }

    private function date(mixed $value, int $year): string
    {
        $date = trim((string) $value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date || (int) $parsed->format('Y') !== $year) {
            throw new \DomainException('Datum nepeněžní operace musí ležet v uzavíraném roce.');
        }
        return $date;
    }

    private function assertYear(int $year): void
    {
        if ($year < 2018 || $year > (int) date('Y') + 1) {
            throw new \DomainException('Neplatný rok uzávěrky.');
        }
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: '{}';
    }
}
