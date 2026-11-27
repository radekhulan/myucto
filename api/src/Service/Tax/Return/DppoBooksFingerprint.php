<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\TaxConstantsRepository;
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
 *
 * Přiznání finalizované před zavedením otisku se posuzuje náhradně podle výsledku
 * hospodaření uloženého v podkladech přiznání ({@see assess()}).
 */
final class DppoBooksFingerprint
{
    private const VERSION = 'v1';
    private const AXES = ['activity', 'activity_kind', 'tax_regime'];

    /** Otisk odpovídá účetnictví. */
    public const REASON_FINGERPRINT_MATCH = 'fingerprint_match';
    /** Otisk neodpovídá: účetnictví se po finalizaci změnilo. */
    public const REASON_FINGERPRINT_MISMATCH = 'fingerprint_mismatch';
    /** Bez otisku: uložený VH přiznání se shoduje s VH z aktuálního účetnictví. */
    public const REASON_VH_MATCH = 'vh_match';
    /** Bez otisku: VH se liší, ale základ daně po zaokrouhlení a daň zůstávají stejné. */
    public const REASON_VH_DIFFERS_TAX_SAME = 'vh_differs_tax_same';
    /** Bez otisku: VH se liší a mění základ daně, přiznání je zastaralé. */
    public const REASON_VH_DIFFERS_TAX_CHANGED = 'vh_differs_tax_changed';
    /** Nelze posoudit (bez otisku i bez uloženého VH, nezisková organizace, rok bez období). */
    public const REASON_NOT_ASSESSABLE = 'not_assessable';

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

    /**
     * Výsledek hospodaření před zdaněním pro DPPO (ř. 10): výsledkové účty bez 59x, bez
     * uzavření knih, se skladovými sloty. Stejné vymezení zápisů jako otisk; podklady DPPO
     * ho berou odsud ({@see DppoReturnDataProvider::gather()}).
     */
    public function profitBeforeTax(int $supplierId, string $startsOn, string $endsOn): float
    {
        $stmt = $this->db->pdo()->prepare(
            "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . " SELECT COALESCE(SUM(" . self::PROFIT_SQL . "), 0) AS vh
               " . self::profitScopeSql()
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn, ClosingSourceId::STOCK_SLOT_BASE]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** Skupiny můstku {@see profitBeforeTaxBridge()} v pořadí zobrazení. */
    public const BRIDGE_GROUPS = ['deferred_revenue', 'prepaid_expenses', 'accruals', 'depreciation', 'other'];

    /**
     * Můstek VH před zdaněním ({@see profitBeforeTax()}) mezi dnem výkazu a koncem období:
     * VH ze zápisů do `$asOf` včetně a zápisy datované po něm do `$endsOn`, rozdělené podle
     * povahy zápisu. Vymezení zápisů i účtů je to samé jako u profitBeforeTax(), takže
     * `as_of` + Σ skupin = profitBeforeTax($startsOn, $endsOn) na haléř.
     *
     * Povaha se pozná podle účtů celého zápisu (storno nese tytéž účty jako rušený zápis):
     * protiúčet 384 = rozpuštění výnosů příštích období, 381 = nákladů příštích období,
     * 383/385/388/389 = výdaje a příjmy příštích období a dohadné položky, účet 551 = odpisy,
     * jinak ostatní. Zápis s víc znaky spadne do první skupiny v tomto pořadí.
     *
     * @return array{as_of: float, groups: array<string,float>}
     */
    public function profitBeforeTaxBridge(int $supplierId, string $startsOn, string $asOf, string $endsOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            "WITH RECURSIVE " . JournalTaxOrigin::cte($supplierId) . ",
             later_nature AS (
                SELECT le.id AS entry_id,
                       MAX(CASE WHEN la.account_code LIKE '384%' THEN 1 ELSE 0 END) AS has_384,
                       MAX(CASE WHEN la.account_code LIKE '381%' THEN 1 ELSE 0 END) AS has_381,
                       MAX(CASE WHEN la.account_code LIKE '383%' OR la.account_code LIKE '385%'
                                  OR la.account_code LIKE '388%' OR la.account_code LIKE '389%' THEN 1 ELSE 0 END) AS has_accrual,
                       MAX(CASE WHEN la.account_code LIKE '551%' THEN 1 ELSE 0 END) AS has_551
                  FROM journal_entries le
                  JOIN journal_entry_lines ll ON ll.supplier_id = le.supplier_id AND ll.entry_id = le.id
                  JOIN chart_of_accounts la   ON la.id = ll.account_id
                 WHERE le.supplier_id = ? AND le.posted_at IS NOT NULL
                   AND le.entry_date > ? AND le.entry_date <= ?
                 GROUP BY le.id
             )
             SELECT CASE WHEN e.entry_date <= ? THEN 'as_of'
                         WHEN n.has_384 = 1 THEN 'deferred_revenue'
                         WHEN n.has_381 = 1 THEN 'prepaid_expenses'
                         WHEN n.has_accrual = 1 THEN 'accruals'
                         WHEN n.has_551 = 1 THEN 'depreciation'
                         ELSE 'other' END AS bucket,
                    COALESCE(SUM(" . self::PROFIT_SQL . "), 0) AS vh
               " . self::profitScopeSql('LEFT JOIN later_nature n ON n.entry_id = e.id') . "
              GROUP BY bucket"
        );
        $stmt->execute([
            $supplierId, $asOf, $endsOn,
            $asOf,
            $supplierId, $startsOn, $endsOn, ClosingSourceId::STOCK_SLOT_BASE,
        ]);
        $cents = array_fill_keys(['as_of', ...self::BRIDGE_GROUPS], 0);
        foreach ($stmt->fetchAll(\PDO::FETCH_KEY_PAIR) as $bucket => $vh) {
            $cents[(string) $bucket] = (int) round((float) $vh * 100);
        }

        return [
            'as_of' => round($cents['as_of'] / 100, 2),
            'groups' => array_map(static fn (int $c): float => round($c / 100, 2), array_intersect_key($cents, array_flip(self::BRIDGE_GROUPS))),
        ];
    }

    private const PROFIT_SQL = "CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END";

    /**
     * FROM a WHERE výsledku hospodaření před zdaněním (parametry firma, od, do, skladový
     * slot). Jediné místo vymezení zápisů pro {@see profitBeforeTax()} i můstek.
     */
    private static function profitScopeSql(string $extraJoin = ''): string
    {
        return "FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               " . JournalTaxOrigin::join() . "
               JOIN chart_of_accounts a ON a.id = l.account_id
               {$extraJoin}
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND " . JournalTaxOrigin::includedSql() . "
                AND a.account_type IN ('revenue','expense')
                AND a.account_code NOT LIKE '59%'";
    }

    /**
     * Odpovídá finalizované DPPO aktuálnímu účetnictví?
     *
     * S uloženým otiskem rozhoduje otisk. Přiznání finalizované bez otisku se posoudí podle
     * výsledku hospodaření uloženého v podkladech (`computed.podklady.vh`) proti VH
     * z aktuálních knih spočtenému stejně jako pro DPPO ({@see profitBeforeTax()}): shoda =
     * aktuální; rozdíl, po kterém vyjde stejný zaokrouhlený základ daně (ř. 270) i daň,
     * = neaktuální, ale daň platí (`vh_differs_tax_same`); jinak zastaralé. Základ se
     * přepočte výpočtem DPPO nad uloženými podklady a vstupy přiznání dvakrát, s uloženým
     * a s aktuálním VH, takže se liší jen VH a zaokrouhlení i limity jsou stejné jako v DPPO.
     *
     * @param array<string,mixed> $computed uložený výpočet přiznání (income_tax_returns.computed)
     * @param array<string,mixed> $inputs ruční vstupy přiznání
     * @return array{current:?bool, reason:string, stored_vh?:float, current_vh?:float, vh_difference?:float}
     */
    public function assess(int $supplierId, int $year, ?string $storedFingerprint, array $computed, array $inputs): array
    {
        if ($storedFingerprint !== null && $storedFingerprint !== '') {
            $current = $this->forYear($supplierId, $year);
            return $current !== null && hash_equals($storedFingerprint, $current)
                ? ['current' => true, 'reason' => self::REASON_FINGERPRINT_MATCH]
                : ['current' => false, 'reason' => self::REASON_FINGERPRINT_MISMATCH];
        }
        $podklady = is_array($computed['podklady'] ?? null) ? $computed['podklady'] : [];
        $period = (new AccountingPeriodRepository($this->db))->findByYear($supplierId, $year);
        if (!isset($podklady['vh']) || !is_numeric($podklady['vh']) || $period === null
            || isset($podklady['nonprofit']) || ($podklady['accounting_decree'] ?? '500') === '504') {
            return ['current' => null, 'reason' => self::REASON_NOT_ASSESSABLE];
        }
        $storedVh = round((float) $podklady['vh'], 2);
        $currentVh = $this->profitBeforeTax($supplierId, (string) $period['starts_on'], (string) $period['ends_on']);
        $detail = ['stored_vh' => $storedVh, 'current_vh' => $currentVh, 'vh_difference' => round($currentVh - $storedVh, 2)];
        if ((int) round($storedVh * 100) === (int) round($currentVh * 100)) {
            return ['current' => true, 'reason' => self::REASON_VH_MATCH] + $detail;
        }
        return ['current' => false, 'reason' => $this->taxUnaffected($year, $podklady, $inputs, $currentVh)
            ? self::REASON_VH_DIFFERS_TAX_SAME : self::REASON_VH_DIFFERS_TAX_CHANGED] + $detail;
    }

    /**
     * Přiznání je pro průvodce uzávěrkou zastaralé (daň z něj se nepoužije). Rozdíl VH,
     * který nemění zaokrouhlený základ ani daň, přiznání nezneplatní.
     *
     * @param array{current:?bool, reason:string} $assessment výsledek {@see assess()}
     */
    public static function isStale(array $assessment): bool
    {
        return $assessment['current'] === false && $assessment['reason'] !== self::REASON_VH_DIFFERS_TAX_SAME;
    }

    /**
     * Vyjde s aktuálním VH stejný zaokrouhlený základ daně (ř. 270) i daň? Selže-li
     * přepočet nad uloženými podklady, bere se přiznání jako dotčené (zastaralé).
     *
     * @param array<string,mixed> $podklady
     * @param array<string,mixed> $inputs
     */
    private function taxUnaffected(int $year, array $podklady, array $inputs, float $currentVh): bool
    {
        try {
            $constants = (new TaxConstantsRepository($this->db))->forExactYear($year);
            $calc = new DppoReturnCalculator();
            $stored = $calc->compute($podklady, $inputs, $constants);
            $current = $calc->compute(['vh' => $currentVh] + $podklady, $inputs, $constants);
        } catch (\Throwable) {
            return false;
        }
        $base = static function (array $result): ?float {
            foreach ((array) ($result['lines'] ?? []) as $line) {
                if ((int) ($line['line'] ?? 0) === 270) {
                    return round((float) $line['value'], 2);
                }
            }
            return null;
        };
        $storedBase = $base($stored);
        return $storedBase !== null && $storedBase === $base($current)
            && round((float) ($stored['summary']['total_tax'] ?? 0), 2) === round((float) ($current['summary']['total_tax'] ?? 0), 2);
    }
}
