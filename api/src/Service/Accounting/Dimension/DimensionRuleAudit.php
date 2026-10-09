<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Dimension;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\TaxEvidence\CashJournalDimensionService;
use PDO;

/**
 * Zpětná kontrola pravidel dimenzí nad deníkem (Firma → Dimenze → Pravidla → Kontrola).
 *
 *   • {@see violations()} — zaúčtované řádky v období, které podle platného pravidla
 *     (vynucení error/warning) nemají hodnotu typu ani rozpad. Pravidla zavedená dnes
 *     nic zpětně neblokují, tahle kontrola ukáže, co v historii (převzaté, ručně
 *     opravované, systémové zápisy) chybí.
 *   • {@see coverage()} — kolik řádků na syntetickém účtu nese hodnotu typu. Podklad
 *     pro návrh pravidel po převodu z jiného systému.
 *
 * Nebere se uzávěrka a otevření účtů (převody zůstatků) a storna ani jimi stornované
 * zápisy — pár storna se v sestavách vynuluje, doplňovat mu dimenzi nemá smysl.
 */
final class DimensionRuleAudit
{
    private const EXEMPT_SOURCES = ['closing', 'opening'];

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingModeRepository $modes,
        private readonly CashJournalDimensionService $cashJournal,
    ) {}

    /**
     * Firma v daňové evidenci (podle roku začátku období) deník nemá; kontrola i pokrytí
     * jdou nad pohyby peněžního deníku ({@see CashJournalDimensionService}).
     */
    private function isTaxEvidence(int $supplierId, string $dateFrom): bool
    {
        return $this->modes->forYear($supplierId, (int) substr($dateFrom, 0, 4)) === 'tax_evidence';
    }

    /**
     * @return array{rows:list<array<string,mixed>>, summary:list<array<string,mixed>>, total:int, truncated:bool}
     */
    public function violations(int $supplierId, string $dateFrom, string $dateTo, int $limit = 200): array
    {
        if ($this->isTaxEvidence($supplierId, $dateFrom)) {
            return $this->cashJournal->violations($supplierId, $dateFrom, $dateTo, $limit);
        }
        $rules = array_values(array_filter(
            (new DimensionRuleService($this->db))->usableRules($supplierId),
            static fn (array $r): bool => $r['enforcement'] !== 'none',
        ));
        $types = [];
        foreach ((new DimensionRepository($this->db))->listTypes($supplierId) as $t) {
            $types[$t['id']] = (string) $t['name'];
        }
        $byType = [];
        foreach ($rules as $rule) {
            $byType[$rule['dimension_type_id']][] = $rule;
        }

        $rows = [];
        $summary = [];
        $total = 0;
        foreach ($byType as $typeId => $typeRules) {
            // Jedna podmínka za typ: řádek odpovídá aspoň jednomu pravidlu (maska + platnost),
            // závažnost je nejpřísnější z pravidel, která na něj dopadají.
            $conds = [];
            $severity = [];
            $params = [];
            $sevParams = [];
            foreach ($typeRules as $rule) {
                [$maskSql, $maskParams] = $rule['mask']->sql('a.account_code');
                $cond = $maskSql;
                $condParams = $maskParams;
                if ($rule['valid_from'] !== null) {
                    $cond .= ' AND je.entry_date >= ?';
                    $condParams[] = $rule['valid_from'];
                }
                if ($rule['valid_to'] !== null) {
                    $cond .= ' AND je.entry_date <= ?';
                    $condParams[] = $rule['valid_to'];
                }
                $conds[] = '(' . $cond . ')';
                array_push($params, ...$condParams);
                $severity[] = 'CASE WHEN ' . $cond . ' THEN ' . ($rule['enforcement'] === 'error' ? 2 : 1) . ' ELSE 0 END';
                array_push($sevParams, ...$condParams);
            }
            $sevSql = count($severity) === 1 ? $severity[0] : 'GREATEST(' . implode(', ', $severity) . ')';
            [$baseSql, $baseParams] = $this->baseSql($supplierId, $dateFrom, $dateTo);
            $where = $baseSql . ' AND (' . implode(' OR ', $conds) . ')
                  AND NOT EXISTS (SELECT 1 FROM journal_entry_line_dimensions d
                                   WHERE d.line_id = l.id AND d.dimension_type_id = ?)
                  AND NOT EXISTS (SELECT 1 FROM journal_entry_line_dimension_splits s
                                   WHERE s.line_id = l.id AND s.dimension_type_id = ?)';
            $whereParams = [...$baseParams, ...$params, $typeId, $typeId];

            $sum = $this->db->pdo()->prepare(
                "SELECT LEFT(a.account_code, 3) AS synthetic, {$sevSql} AS severity, je.source_type,
                        COUNT(*) AS line_count, SUM(l.signed_amount) AS amount
                   {$where}
                  GROUP BY synthetic, severity, je.source_type
                  ORDER BY synthetic, severity DESC, je.source_type"
            );
            $sum->execute([...$sevParams, ...$whereParams]);
            foreach ($sum->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $total += (int) $r['line_count'];
                $summary[] = [
                    'type_id' => $typeId,
                    'type_name' => $types[$typeId] ?? ('#' . $typeId),
                    'synthetic' => (string) $r['synthetic'],
                    'enforcement' => (int) $r['severity'] === 2 ? 'error' : 'warning',
                    'source_type' => (string) $r['source_type'],
                    'lines' => (int) $r['line_count'],
                    'amount' => round((float) $r['amount'], 2),
                ];
            }

            $detail = $this->db->pdo()->prepare(
                "SELECT l.id AS line_id, je.id AS entry_id, je.entry_date, je.document_no, je.source_type, je.source_id,
                        a.account_code, a.name AS account_name, l.side, l.signed_amount AS amount, {$sevSql} AS severity
                   {$where}
                  ORDER BY je.entry_date, je.id, l.line_no, l.id
                  LIMIT " . max(1, $limit)
            );
            $detail->execute([...$sevParams, ...$whereParams]);
            foreach ($detail->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = [
                    'line_id' => (int) $r['line_id'],
                    'entry_id' => (int) $r['entry_id'],
                    'entry_date' => (string) $r['entry_date'],
                    'document_no' => $r['document_no'] !== null ? (string) $r['document_no'] : null,
                    'source_type' => (string) $r['source_type'],
                    'source_id' => $r['source_id'] !== null ? (int) $r['source_id'] : null,
                    'account_code' => (string) $r['account_code'],
                    'account_name' => (string) $r['account_name'],
                    'side' => (string) $r['side'],
                    'amount' => (float) $r['amount'],
                    'type_id' => $typeId,
                    'type_name' => $types[$typeId] ?? ('#' . $typeId),
                    'enforcement' => (int) $r['severity'] === 2 ? 'error' : 'warning',
                ];
            }
        }
        usort($rows, static fn (array $a, array $b): int
            => [$a['entry_date'], $a['entry_id'], $a['line_id'], $a['type_id']] <=> [$b['entry_date'], $b['entry_id'], $b['line_id'], $b['type_id']]);
        return [
            'rows' => array_slice($rows, 0, max(1, $limit)),
            'summary' => $summary,
            'total' => $total,
            'truncated' => $total > $limit,
        ];
    }

    /**
     * Pokrytí syntetických účtů hodnotami typů dimenzí v období.
     *
     * @return list<array{type_id:int, type_name:string, synthetic:string, lines:int, covered:int, ratio:float, suggested:bool}>
     */
    public function coverage(int $supplierId, string $dateFrom, string $dateTo): array
    {
        if ($this->isTaxEvidence($supplierId, $dateFrom)) {
            return $this->cashJournal->coverage($supplierId, $dateFrom, $dateTo);
        }
        $types = (new DimensionRepository($this->db))->listTypes($supplierId, false);
        [$baseSql, $baseParams] = $this->baseSql($supplierId, $dateFrom, $dateTo);
        $out = [];
        foreach ($types as $type) {
            $typeId = (int) $type['id'];
            $stmt = $this->db->pdo()->prepare(
                "SELECT LEFT(a.account_code, 3) AS synthetic, COUNT(*) AS line_count,
                        SUM(EXISTS (SELECT 1 FROM journal_entry_line_dimensions d
                                     WHERE d.line_id = l.id AND d.dimension_type_id = ?)
                            OR EXISTS (SELECT 1 FROM journal_entry_line_dimension_splits s
                                        WHERE s.line_id = l.id AND s.dimension_type_id = ?)) AS covered
                   {$baseSql}
                  GROUP BY synthetic
                  ORDER BY synthetic"
            );
            $stmt->execute([$typeId, $typeId, ...$baseParams]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $lines = (int) $r['line_count'];
                $covered = (int) $r['covered'];
                $synthetic = (string) $r['synthetic'];
                if ($covered === 0 && !in_array($synthetic[0] ?? '', ['5', '6'], true)) {
                    continue;
                }
                $ratio = $lines > 0 ? round($covered / $lines, 4) : 0.0;
                $out[] = [
                    'type_id' => $typeId,
                    'type_name' => (string) $type['name'],
                    'synthetic' => $synthetic,
                    'lines' => $lines,
                    'covered' => $covered,
                    'ratio' => $ratio,
                    // Návrh pravidla: účet, který historie prakticky vždy členila.
                    'suggested' => $lines >= 10 && $ratio >= 0.9,
                ];
            }
        }
        return $out;
    }

    /** @return array{0:string,1:list<int|string>} FROM … WHERE nad zaúčtovanými řádky období */
    private function baseSql(int $supplierId, string $dateFrom, string $dateTo): array
    {
        $exempt = implode(',', array_fill(0, count(self::EXEMPT_SOURCES), '?'));
        return [
            "FROM journal_entries je
             JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
             JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = je.supplier_id
            WHERE je.supplier_id = ? AND je.entry_date BETWEEN ? AND ?
              AND je.posted_at IS NOT NULL
              AND je.reversed_by IS NULL
              AND je.source_type NOT IN ({$exempt})
              AND NOT EXISTS (SELECT 1 FROM journal_entries orig
                               WHERE orig.supplier_id = je.supplier_id AND orig.reversed_by = je.id)",
            [$supplierId, $dateFrom, $dateTo, ...self::EXEMPT_SOURCES],
        ];
    }
}
