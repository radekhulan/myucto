<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Accrual\AccrualPlanBuilder;
use MyInvoice\Service\Accounting\Accrual\AccrualScheduleCalculator;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\Closing\AccrualDeferralService;

/**
 * Průběžné časové rozlišení a odpisy ve výkazu k datu: zápisy rozpuštění 381/384 a odpisů
 * zaúčtované v deníku s datem PO rozvahovém dni (do konce období).
 *
 * K rozvahovému dni v deníku ještě nejsou, takže se pro výkaz chovají jako nezaúčtované:
 * výkaz z nich ukáže část, která na uplynulou dobu připadá, stejně jako by ji ukázala
 * uzávěrka k tomu dni. Typicky deník převzatý z jiného programu, který rozpuštění roku
 * nese k 31. 12. a odpisy k posledním dnům měsíců i po dni výkazu.
 *
 * Zápis rozpuštění kryje dobu od dne po předchozím zápisu téže řady (stejný druh a číslo
 * dokladu) v období do svého data. Bez předchozího zápisu kryje zápis ke konci období celé
 * období (roční rozpuštění), jinak měsíc svého data.
 *   - rozpuštění 381 (MD 5xx / D 381) a 384 (MD 384 / D 6xx): poměr dnů
 *     ({@see AccrualScheduleCalculator::cumRecognized()}, týž výpočet jako krok uzávěrky);
 *     zápis za jediný měsíc se k datu uvnitř toho měsíce nepočítá, stejně jako měsíční
 *     položka agendy ({@see \MyInvoice\Service\Accounting\Accrual\AccrualAgendaGuard::interimDeltas()});
 *   - odpis (MD 551 / D 07x, 08x): uplynulé celé měsíce
 *     ({@see DepreciationPostingService::elapsedMonthShare()}, týž výpočet jako průběžný odpis karet).
 *
 * Zápisy, které počítá jiná větev průběžného rozlišení, se vynechají: zápisy uzávěrky
 * a otevření roku, rozpuštění položek agendy (vlastní i připojené zápisy, ty počítá agenda)
 * a vlastní zápisy odpisů karet ({@see DepreciationPostingService::interimAccounting()}).
 * Zápis s jinými účty než výše (smíšený, s DPH, se závazkem) se neupravuje.
 */
final class LaterDatedEntrySpread
{
    /** Druhy zápisů uzávěrky, otevření roku a vlastních zápisů, které počítá jiná větev. */
    private const EXCLUDED_SOURCE_TYPES = [
        'closing', 'opening', 'deferred_revenue_accrual', 'prepaid_expense_accrual', 'small_asset_accrual',
        'fx_revaluation', 'income_tax', 'deferred_tax', 'provision', 'profit_distribution',
        'depreciation', 'asset_disposal', 'accrual_release', 'accrual_termination',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly AccrualDeferralService $accruals,
        private readonly DepreciationPostingService $depreciation,
    ) {}

    /**
     * @return array{deltas: array<string,float>, deferred_revenue: float, prepaid_expense: float, depreciation: float, entries: int}
     *   deltas = účet => změna zůstatku MD − D; deferred_revenue = rozpuštěný výnos (384),
     *   prepaid_expense = rozpuštěný náklad (381), depreciation = odpis, vše kladně
     */
    public function compute(int $supplierId, string $periodStartsOn, string $periodEndsOn, string $asOf): array
    {
        $out = ['deltas' => [], 'deferred_revenue' => 0.0, 'prepaid_expense' => 0.0, 'depreciation' => 0.0, 'entries' => 0];
        if ($asOf >= $periodEndsOn || $asOf < $periodStartsOn) {
            return $out;
        }
        $prefix = [
            'prepaid_expense' => substr($this->accruals->deferralAccount($supplierId, AccrualDeferralService::RULE_PREPAID_EXPENSE), 0, 3),
            'deferred_revenue' => substr($this->accruals->deferralAccount($supplierId, AccrualDeferralService::RULE_DEFERRED_REVENUE), 0, 3),
            'depreciation' => substr($this->depreciation->accountingExpenseAccount($supplierId), 0, 3),
        ];

        $entries = $this->candidateEntries($supplierId, $periodStartsOn, $periodEndsOn, $prefix);
        $series = [];
        foreach ($entries as $id => $entry) {
            $kind = self::kind($entry['lines'], $prefix);
            if ($kind === null) {
                unset($entries[$id]);
                continue;
            }
            $entries[$id]['kind'] = $kind;
            $entries[$id]['series'] = $kind . '|' . ($entry['document_no'] !== '' ? $entry['document_no'] : '#' . $id);
            $series[$entries[$id]['series']][] = $entry['date'];
        }

        foreach ($entries as $entry) {
            $date = $entry['date'];
            if ($date <= $asOf) {
                continue;
            }
            $previous = null;
            foreach ($series[$entry['series']] as $d) {
                if ($d < $date && ($previous === null || $d > $previous)) {
                    $previous = $d;
                }
            }
            $from = $previous !== null
                ? AccrualPlanBuilder::dayAfter($previous)
                : ($date === $periodEndsOn ? $periodStartsOn : max($periodStartsOn, substr($date, 0, 7) . '-01'));
            if ($from > $asOf) {
                continue;
            }

            $balance = [];
            $result = [];
            foreach ($entry['lines'] as $code => $line) {
                if ($line['type'] === 'revenue' || $line['type'] === 'expense') {
                    $result[$code] = $line['delta'];
                } else {
                    $balance[$code] = $line['delta'];
                }
            }
            $total = round(array_sum($balance), 2);
            if ((int) round($total * 100) === 0) {
                continue;
            }
            if ($entry['kind'] === 'depreciation') {
                [$elapsed, $months] = DepreciationPostingService::elapsedMonthShare($from, $date, $asOf, $periodEndsOn);
                $part = $months > 0 ? round($total * $elapsed / $months, 2) : 0.0;
            } else {
                $part = substr($from, 0, 7) === substr($date, 0, 7)
                    ? 0.0
                    : AccrualScheduleCalculator::cumRecognized($total, $from, $date, $asOf);
            }
            if ((int) round($part * 100) === 0) {
                continue;
            }
            foreach ([[$balance, 1], [$result, -1]] as [$lines, $sign]) {
                $shares = [];
                $lineTotal = array_sum($lines);
                foreach ($lines as $code => $delta) {
                    $shares[(string) $code] = $lineTotal == 0 ? 0.0 : $delta / $lineTotal;
                }
                foreach (AccrualDeferralService::spreadByAccount($sign * $part, $shares) as $code => $amount) {
                    $out['deltas'][$code] = round(($out['deltas'][$code] ?? 0.0) + $amount, 2);
                }
            }
            $out[$entry['kind']] = round($out[$entry['kind']] + abs($part), 2);
            $out['entries']++;
        }
        $out['deltas'] = array_filter($out['deltas'], static fn (float $v): bool => (int) round($v * 100) !== 0);

        return $out;
    }

    /**
     * Druh zápisu podle jeho účtů, nebo null (zápis se neupravuje).
     *
     * @param array<string,array{type:string, delta:float}> $lines
     * @param array{prepaid_expense:string, deferred_revenue:string, depreciation:string} $prefix
     */
    private static function kind(array $lines, array $prefix): ?string
    {
        $sum = ['prepaid_expense' => 0.0, 'deferred_revenue' => 0.0, 'accumulated' => 0.0, 'depreciation' => 0.0, 'result' => 0.0];
        foreach ($lines as $code => $line) {
            $code = (string) $code;
            $isResult = $line['type'] === 'revenue' || $line['type'] === 'expense';
            $key = match (true) {
                str_starts_with($code, $prefix['prepaid_expense']) => 'prepaid_expense',
                str_starts_with($code, $prefix['deferred_revenue']) => 'deferred_revenue',
                str_starts_with($code, '07'), str_starts_with($code, '08') => 'accumulated',
                $isResult && str_starts_with($code, $prefix['depreciation']) => 'depreciation',
                $isResult => 'result',
                default => null,
            };
            if ($key === null) {
                return null;
            }
            $sum[$key] = round($sum[$key] + $line['delta'], 2);
        }
        $nonZero = static fn (float $v): bool => (int) round($v * 100) !== 0;
        // Rozpuštění 381: D 381 proti nákladu; 384: MD 384 proti výnosu; odpis: MD 551 / D oprávky.
        if ($sum['prepaid_expense'] < 0 && !$nonZero($sum['deferred_revenue']) && !$nonZero($sum['accumulated']) && !$nonZero($sum['depreciation'])) {
            return 'prepaid_expense';
        }
        if ($sum['deferred_revenue'] > 0 && !$nonZero($sum['prepaid_expense']) && !$nonZero($sum['accumulated']) && !$nonZero($sum['depreciation'])) {
            return 'deferred_revenue';
        }
        if ($sum['depreciation'] > 0 && $sum['accumulated'] < 0 && !$nonZero($sum['prepaid_expense']) && !$nonZero($sum['deferred_revenue']) && !$nonZero($sum['result'])) {
            return 'depreciation';
        }
        return null;
    }

    /**
     * Zaúčtované nestornované zápisy období s řádkem na 381/384 nebo účtu odpisů, bez zápisů
     * jiných větví a bez zápisů připojených k položkám agendy. Jeden průchod deníkem období
     * po indexu (supplier_id, entry_date).
     *
     * @param array{prepaid_expense:string, deferred_revenue:string, depreciation:string} $prefix
     * @return array<int,array{date:string, document_no:string, lines:array<string,array{type:string, delta:float}>}>
     */
    private function candidateEntries(int $supplierId, string $from, string $to, array $prefix): array
    {
        $excluded = "'" . implode("', '", self::EXCLUDED_SOURCE_TYPES) . "'";
        $stmt = $this->db->pdo()->prepare(
            "SELECT e.id, e.entry_date, COALESCE(e.document_no, '') AS document_no,
                    a.account_code, a.account_type,
                    SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS delta
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               JOIN chart_of_accounts a   ON a.id = l.account_id
              WHERE e.supplier_id = ? AND e.entry_date >= ? AND e.entry_date <= ?
                AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                AND (e.source_type IS NULL OR e.source_type NOT IN ({$excluded}))
                AND EXISTS (SELECT 1
                              FROM journal_entry_lines xl
                              JOIN chart_of_accounts xa ON xa.id = xl.account_id
                             WHERE xl.entry_id = e.id AND xl.supplier_id = e.supplier_id
                               AND (xa.account_code LIKE ? OR xa.account_code LIKE ? OR xa.account_code LIKE ?))
                AND NOT EXISTS (SELECT 1 FROM accrual_schedule s WHERE s.supplier_id = e.supplier_id AND s.journal_entry_id = e.id)
                AND NOT EXISTS (SELECT 1 FROM accrual_entry_links al WHERE al.supplier_id = e.supplier_id AND al.journal_entry_id = e.id)
              GROUP BY e.id, e.entry_date, e.document_no, a.account_code, a.account_type
              ORDER BY e.entry_date, e.id"
        );
        $like = static fn (string $p): string => addcslashes($p, '%_\\') . '%';
        $stmt->execute([$supplierId, $from, $to, $like($prefix['prepaid_expense']), $like($prefix['deferred_revenue']), $like($prefix['depreciation'])]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id'];
            $out[$id] ??= ['date' => (string) $r['entry_date'], 'document_no' => trim((string) $r['document_no']), 'lines' => []];
            $out[$id]['lines'][(string) $r['account_code']] = ['type' => (string) $r['account_type'], 'delta' => round((float) $r['delta'], 2)];
        }
        return $out;
    }
}
