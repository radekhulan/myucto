<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;

/**
 * Otisk daňově relevantního účetnictví období DPPO, uložený k přiznání při finalizaci.
 *
 * Bere zůstatky výsledkových účtů období (bez 59x) po kódech účtů a daňových osách 504
 * (činnost, druh činnosti, daňový režim), se stejným vymezením zápisů jako výsledek
 * hospodaření v podkladech DPPO ({@see DppoReturnDataProvider::profitBeforeTax()}):
 * bez uzavření knih a bez počátečních stavů, se skladovými sloty. Zaúčtování daně z příjmů
 * (591/341), odložené daně (592) ani uzavření knih po finalizaci otisk nezmění. Otisk je
 * obsahový (kódy účtů a dimenzí, částky), ne id řádků, takže přeúčtování se stejným
 * výsledkem ho nemění.
 */
final class DppoBooksFingerprint
{
    private const VERSION = 'v1';
    private const AXES = ['activity', 'activity_kind', 'tax_regime'];

    public function __construct(private readonly Connection $db) {}

    /** Otisk období DPPO roku; null = rok nemá účetní období (podklady nejdou z deníku). */
    public function forYear(int $supplierId, int $year): ?string
    {
        $period = (new AccountingPeriodRepository($this->db))->findByYear($supplierId, $year);
        if ($period === null) {
            return null;
        }
        return $this->forPeriod($supplierId, (string) $period['starts_on'], (string) $period['ends_on']);
    }

    public function forPeriod(int $supplierId, string $startsOn, string $endsOn): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT system_key, id FROM dimension_types WHERE supplier_id = ? AND system_key IN (' . implode(',', array_fill(0, count(self::AXES), '?')) . ')'
        );
        $stmt->execute([$supplierId, ...self::AXES]);
        $types = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

        $joins = '';
        $columns = [];
        foreach (self::AXES as $axis) {
            if (!isset($types[$axis])) {
                continue;
            }
            $joins .= " LEFT JOIN journal_entry_line_dimensions d_{$axis} ON d_{$axis}.line_id = l.id AND d_{$axis}.dimension_type_id = " . (int) $types[$axis]
                . " LEFT JOIN dimension_values v_{$axis} ON v_{$axis}.id = d_{$axis}.dimension_value_id";
            $columns[] = "v_{$axis}.code";
        }
        $group = implode('', array_map(static fn (string $column): string => ', ' . $column, $columns));
        $stmt = $this->db->pdo()->prepare(
            'WITH RECURSIVE ' . JournalTaxOrigin::cte($supplierId) . ' SELECT a.account_code' . $group . ",
                    SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END) AS amount
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id
               " . JournalTaxOrigin::join() . '
               JOIN chart_of_accounts a ON a.id = l.account_id' . $joins . "
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND " . JournalTaxOrigin::includedSql() . "
                AND e.source_type <> 'opening'
                AND a.account_type IN ('revenue','expense')
                AND a.account_code NOT LIKE '59%'
              GROUP BY a.account_code" . $group . '
             HAVING amount <> 0
              ORDER BY a.account_code' . $group
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn, ClosingSourceId::STOCK_SLOT_BASE]);
        $rows = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as $row) {
            $amount = array_pop($row);
            $rows[] = [...$row, bcadd((string) $amount, '0', 2)];
        }

        return self::VERSION . ':' . hash('sha256', json_encode(
            ['DPPOBOOKS1', $startsOn, $endsOn, $rows],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** Odpovídá uložený otisk aktuálnímu účetnictví? null = otisk nebyl uložen (nelze posoudit). */
    public function isCurrent(int $supplierId, int $year, ?string $stored): ?bool
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        $current = $this->forYear($supplierId, $year);
        return $current !== null && hash_equals($stored, $current);
    }
}
