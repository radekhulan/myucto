<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\LedgerReportRepository;

/**
 * Hlavní kniha (Epic F2): per účet PS (R6) + měsíční obraty + KS v rozsahu
 * uvnitř účetního období. Čte jen zaúčtované zápisy (R1), drafty hlásí
 * informativně přes draft_count; analytiky rolované na syntetiku (R15),
 * volitelně rozpad `analytics=1`. Zahrnuje VŠECHNY účty vč. 7xx (R2 — úplný
 * opis deníku). Peníze se sčítají v haléřích, výstup float na 2 des. místa.
 */
final class GeneralLedgerService
{
    public function __construct(
        private readonly LedgerReportRepository $ledger,
        private readonly AccountingPeriodRepository $periods,
    ) {}

    /**
     * @param array{vendor?:string, client?:string, item?:string} $filters hledání dle
     *        dodavatele (přijaté faktury) / odběratele (vydané faktury) / textu položky
     *        faktury — viz {@see LedgerReportRepository::counterpartyFilter()}
     * @return array<string,mixed> struktura dle spec §2.4
     */
    public function build(int $supplierId, int $periodId, ?string $from, ?string $to, bool $analytics = false, array $filters = [], bool $afterClosing = false): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) {
            throw new ReportException('period_not_found', 'Účetní období #' . $periodId . ' neexistuje.', 404);
        }
        $from = ($from === null || $from === '') ? (string) $period['starts_on'] : $from;
        $to   = ($to === null || $to === '') ? (string) $period['ends_on'] : $to;
        return $this->buildRange(
            $supplierId,
            $period,
            $from,
            $to,
            (string) $period['starts_on'],
            $analytics,
            $filters,
            $afterClosing,
            false,
        );
    }

    /**
     * @param array{vendor?:string, client?:string, item?:string} $filters
     * @return array<string,mixed>
     */
    public function buildAllPeriods(int $supplierId, ?string $from = null, ?string $to = null, bool $analytics = false, array $filters = [], bool $afterClosing = false): array
    {
        $periods = $this->periods->listForTenant($supplierId);
        if ($periods === []) {
            throw new ReportException('period_not_found', 'Firma nemá žádné účetní období.', 404);
        }

        $starts = array_column($periods, 'starts_on');
        $ends = array_column($periods, 'ends_on');
        $rangeStart = (string) min($starts);
        $rangeEnd = (string) max($ends);

        return $this->buildRange(
            $supplierId,
            null,
            ($from === null || $from === '') ? $rangeStart : $from,
            ($to === null || $to === '') ? $rangeEnd : $to,
            $rangeStart,
            $analytics,
            $filters,
            $afterClosing,
            true,
        );
    }

    /**
     * @param array<string,mixed>|null $period
     * @param array{vendor?:string, client?:string, item?:string} $filters
     * @return array<string,mixed>
     */
    private function buildRange(int $supplierId, ?array $period, string $from, string $to, string $periodStart, bool $analytics, array $filters, bool $afterClosing, bool $allPeriods): array
    {
        $excludeAllOpenings = $allPeriods && !$afterClosing;

        $rows    = $this->ledger->trialBalanceRows($supplierId, $from, $to, $periodStart, $analytics, $filters, !$afterClosing, $excludeAllOpenings);
        $monthly = $this->ledger->monthlyTurnovers($supplierId, $from, $to, $analytics, $filters, !$afterClosing, $excludeAllOpenings);
        $months  = $this->monthsBetween($from, $to);

        $monthlyByAccount = [];
        foreach ($monthly as $m) {
            $monthlyByAccount[$m['account_id']][$m['month']] = ['md' => $m['md'], 'd' => $m['d']];
        }

        $accounts = [];
        $totals = ['opening_md' => 0, 'opening_d' => 0, 'turnover_md' => 0, 'turnover_d' => 0, 'closing_md' => 0, 'closing_d' => 0];
        foreach ($rows as $r) {
            $psMd = self::cents($r['ps_md']);
            $psD  = self::cents($r['ps_d']);
            $tMd  = self::cents($r['to_md']);
            $tD   = self::cents($r['to_d']);
            if ($psMd === 0 && $psD === 0 && $tMd === 0 && $tD === 0) {
                continue; // účty bez pohybu i bez PS se vynechávají
            }
            $monthMap = [];
            foreach ($months as $ym) {
                $mv = $monthlyByAccount[(int) $r['id']][$ym] ?? null;
                $monthMap[$ym] = ['md' => $mv['md'] ?? 0.0, 'd' => $mv['d'] ?? 0.0];
            }
            // KS: delta = PSmd − PSd + Omd − Od; delta > 0 → closing_md, jinak closing_d
            $delta = $psMd - $psD + $tMd - $tD;
            $ksMd = $delta > 0 ? $delta : 0;
            $ksD  = $delta > 0 ? 0 : -$delta;

            $accounts[] = [
                'account_id'   => (int) $r['id'],
                'account_code' => (string) $r['account_code'],
                'name'         => (string) $r['name'],
                'account_type' => (string) $r['account_type'],
                'is_synthetic' => (bool) $r['is_synthetic'],
                'opening_md'   => $psMd / 100,
                'opening_d'    => $psD / 100,
                'months'       => $monthMap,
                'turnover_md'  => $tMd / 100,
                'turnover_d'   => $tD / 100,
                'closing_md'   => $ksMd / 100,
                'closing_d'    => $ksD / 100,
            ];

            $totals['opening_md']  += $psMd;
            $totals['opening_d']   += $psD;
            $totals['turnover_md'] += $tMd;
            $totals['turnover_d']  += $tD;
            $totals['closing_md']  += $ksMd;
            $totals['closing_d']   += $ksD;
        }

        return [
            'period' => $period === null ? null : [
                'id'          => (int) $period['id'],
                'fiscal_year' => (int) $period['fiscal_year'],
                'starts_on'   => (string) $period['starts_on'],
                'ends_on'     => (string) $period['ends_on'],
            ],
            'all_periods' => $allPeriods,
            'from'        => $from,
            'to'          => $to,
            'analytics'   => $analytics,
            'vendor'      => $filters['vendor'] ?? null,
            'client'      => $filters['client'] ?? null,
            'item'        => $filters['item'] ?? null,
            'draft_count' => $this->ledger->draftCount($supplierId, $from, $to),
            'months'      => $months,
            'accounts'    => $accounts,
            'totals'      => array_map(static fn (int $c): float => $c / 100, $totals),
        ];
    }

    /**
     * @return list<string> měsíce 'Y-m' v rozsahu (včetně krajních)
     */
    private function monthsBetween(string $from, string $to): array
    {
        $months = [];
        $cursor = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
        $end    = new \DateTimeImmutable(substr($to, 0, 7) . '-01');
        while ($cursor <= $end) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('first day of next month');
        }
        return $months;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
