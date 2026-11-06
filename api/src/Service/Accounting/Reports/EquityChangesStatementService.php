<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use PDO;

/**
 * Přehled o změnách vlastního kapitálu — § 18 odst. 2 ZoÚ, § 44 vyhl. 500/2002 Sb.
 *
 * Spolu s přehledem o peněžních tocích je součástí závěrky u velké a střední účetní
 * jednotky a u každé s povinným auditem. Systém ho neuměl, takže balíček závěrky hlásil
 * „hotovo" u závěrky, které tahle část chyběla.
 *
 * ── Co výkaz ukazuje ────────────────────────────────────────────────────────
 * Za každou složku vlastního kapitálu počáteční stav, zvýšení, snížení a konečný stav.
 * Vyhláška nepředepisuje pevné řádky jako u rozvahy — vykazují se položky, které účetní
 * jednotka má. Proto se skládá z účtů třídy 4 označených v osnově jako `equity`, ne
 * z pevného seznamu: firma s vlastními analytikami tak dostane výkaz odpovídající své
 * osnově.
 *
 * ── Znaménko ────────────────────────────────────────────────────────────────
 * Vlastní kapitál má kreditní zůstatek, takže se v celém výkazu obrací: zůstatky
 * i pohyby se vykazují KLADNĚ, když kapitál rostou. Bez otočení by výkaz ukazoval
 * základní kapitál záporně, což by čtenář četl jako ztrátu.
 *
 * Zvýšení a snížení se NESČÍTAJÍ do jednoho čísla — vyhláška chce obojí zvlášť
 * a čistá změna informaci o pohybech ztrácí (vklad 1 mil. a výplata 1 mil. by vyšly
 * jako nula, ačkoli se stalo obojí).
 *
 * Read-only: nic neúčtuje.
 */
final class EquityChangesStatementService
{
    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly StatementSupplementPolicy $supplements,
    ) {}

    /**
     * @return array{
     *   period:array{id:int, starts_on:string, ends_on:string},
     *   rows:list<array{account_code:string, name:string, opening:float, increase:float, decrease:float, closing:float}>,
     *   totals:array{opening:float, increase:float, decrease:float, closing:float},
     *   reconciles:bool
     * }
     */
    public function build(int $supplierId, int $periodId): array
    {
        $this->supplements->assert500Supplement($supplierId, $periodId);
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $startsOn = (string) $period['starts_on'];
        $endsOn = (string) $period['ends_on'];

        // Počáteční stav k PRVNÍMU dni období — otevírací zápis má `entry_date = starts_on`,
        // takže „den před" by u firmy přenášející zůstatky vrátil nulu.
        //
        // Z OBOU stavů se vyřazuje uzávěrkový zápis reportovaného období: převádí zůstatky
        // na 702, takže vlastní kapitál uzavřeného roku vycházel na NULU (naměřeno za 2025:
        // konečný stav 0 Kč a základní kapitál „snížený" o celých 10 000). Starší uzávěrkové
        // a otevírací zápisy se ponechávají — jsou zrcadlové a navzájem se ruší.
        $opening = $this->balances($supplierId, $startsOn, $startsOn, $endsOn, true);
        $movements = $this->movements($supplierId, $startsOn, $endsOn);
        $closing = $this->balances($supplierId, $endsOn, $startsOn, $endsOn, false);

        $codes = array_unique(array_merge(
            array_keys($opening),
            array_keys($movements),
            array_keys($closing),
        ));
        sort($codes);

        $rows = [];
        $totals = ['opening' => 0.0, 'increase' => 0.0, 'decrease' => 0.0, 'closing' => 0.0];

        foreach ($codes as $code) {
            $o = $opening[$code]['amount'] ?? 0.0;
            $c = $closing[$code]['amount'] ?? 0.0;
            $inc = $movements[$code]['increase'] ?? 0.0;
            $dec = $movements[$code]['decrease'] ?? 0.0;

            if ((int) round(($o + $c + $inc + $dec) * 100) === 0) {
                continue;   // složka, kterou firma nemá — do výkazu nepatří
            }

            $rows[] = [
                // PŘETYPOVAT: pole je klíčované kódem účtu a PHP z číselného klíče udělá
                // int, takže bez tohohle by v API vyšlo 411 místo "411" — strict porovnání
                // na klientovi (i v testu) by pak selhalo.
                'account_code' => (string) $code,
                'name'         => $closing[$code]['name'] ?? ($opening[$code]['name'] ?? $movements[$code]['name'] ?? ''),
                'opening'      => round($o, 2),
                'increase'     => round($inc, 2),
                'decrease'     => round($dec, 2),
                'closing'      => round($c, 2),
            ];
            $totals['opening'] += $o;
            $totals['increase'] += $inc;
            $totals['decrease'] += $dec;
            $totals['closing'] += $c;
        }

        $totals = array_map(static fn (float $v): float => round($v, 2), $totals);

        return [
            'period'     => ['id' => (int) $period['id'], 'starts_on' => $startsOn, 'ends_on' => $endsOn],
            'rows'       => $rows,
            'totals'     => $totals,
            // Počáteční stav + zvýšení − snížení musí dát konečný stav. Když ne, chybí
            // pohyb nebo se zůstatek vzal z jiného období — výkaz to nesmí zamlčet.
            //
            // Kontroluje se KAŽDÁ SLOŽKA, ne jen součet. Součtová kontrola je slabá:
            // chyba na jednom účtu se v ní vyruší s opačnou chybou na jiném a výkaz se
            // tváří, že sedí. Přesně tak zůstalo skryté dvojí započtení zápisu datovaného
            // na 1. den období — v součtu to vycházelo, po řádcích ne.
            'reconciles' => self::rowsReconcile($rows)
                && (int) round(($totals['opening'] + $totals['increase'] - $totals['decrease']) * 100)
                === (int) round($totals['closing'] * 100),
        ];
    }

    /**
     * Sedí u KAŽDÉ složky počáteční stav + zvýšení − snížení na konečný stav?
     *
     * @param list<array{opening:float, increase:float, decrease:float, closing:float}> $rows
     */
    private static function rowsReconcile(array $rows): bool
    {
        foreach ($rows as $r) {
            $expected = $r['opening'] + $r['increase'] - $r['decrease'];
            if ((int) round($expected * 100) !== (int) round($r['closing'] * 100)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Zůstatky účtů vlastního kapitálu k datu, otočené do kladných hodnot.
     *
     * @return array<string, array{amount:float, name:string}>
     */
    private function balances(int $supplierId, string $asOf, string $periodFrom, string $periodTo, bool $isPeriodStart): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code, MIN(a.name) AS name,
                    ROUND(SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END), 2) AS amount
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = :supplier_id AND e.posted_at IS NOT NULL
                AND e.reversed_by IS NULL
                AND e.entry_date <= :as_of
                -- Zápis datovaný přesně na PRVNÍ den období se nesmí započítat
                -- zároveň do počátečního stavu i mezi pohyby. Do počátečního stavu
                -- patří jen to, co mu předchází, plus otevírací zápis.
                AND (:is_start = 0 OR e.entry_date < :as_of_start OR e.source_type = 'opening')
                AND NOT (e.source_type = 'closing' AND e.entry_date BETWEEN :p_from AND :p_to)
                AND a.account_type = 'equity'
           GROUP BY a.account_code"
        );
        $stmt->execute([
            ":supplier_id" => $supplierId,
            ':as_of' => $asOf,
            ':as_of_start' => $asOf,
            ':is_start' => $isPeriodStart ? 1 : 0,
            ':p_from' => $periodFrom,
            ':p_to' => $periodTo,
        ]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(string) $r['account_code']] = [
                'amount' => (float) $r['amount'],
                'name'   => (string) $r['name'],
            ];
        }

        return $out;
    }

    /**
     * Pohyby za období rozdělené na zvýšení a snížení kapitálu.
     *
     * Otevírací zápis (`source_type = 'opening'`) se VYLUČUJE — je to přenos počátečního
     * stavu, ne pohyb v běžném období; jinak by se každý zůstatek objevil i jako zvýšení.
     *
     * @return array<string, array{increase:float, decrease:float, name:string}>
     */
    private function movements(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code, MIN(a.name) AS name,
                    ROUND(SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE 0 END), 2) AS increase,
                    ROUND(SUM(CASE WHEN l.side = 'debit'  THEN l.signed_amount ELSE 0 END), 2) AS decrease
               FROM journal_entry_lines l
               JOIN journal_entries e   ON e.id = l.entry_id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.supplier_id = ? AND e.posted_at IS NOT NULL
                AND e.reversed_by IS NULL
                AND e.entry_date BETWEEN ? AND ?
                AND e.source_type NOT IN ('opening', 'closing')
                AND a.account_type = 'equity'
           GROUP BY a.account_code"
        );
        $stmt->execute([$supplierId, $from, $to]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(string) $r['account_code']] = [
                'increase' => (float) $r['increase'],
                'decrease' => (float) $r['decrease'],
                'name'     => (string) $r['name'],
            ];
        }

        return $out;
    }
}
