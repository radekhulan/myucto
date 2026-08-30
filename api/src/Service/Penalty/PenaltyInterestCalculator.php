<?php

declare(strict_types=1);

namespace MyInvoice\Service\Penalty;

use DateTimeImmutable;
use MyInvoice\Repository\TaxConstantsRepository;

/**
 * Výpočet zákonného úroku z prodlení dle nařízení vlády č. 351/2013 Sb.
 *
 * Roční sazba úroku = 2týdenní repo sazba ČNB platná k PRVNÍMU DNI kalendářního
 * pololetí, **v němž DOŠLO K PRODLENÍ** (§ 2) — dokonavý vid míří na okamžik
 * VZNIKU prodlení, tato JEDNA sazba se FIXUJE na celou dobu prodlení a dál se
 * NEMĚNÍ, i když prodlení trvá přes další pololetí (ustálený výklad, stejně
 * počítají běžné veřejné kalkulačky úroku z prodlení). Zvýšená o 8 procentních
 * bodů.
 *
 * Prodlení běží ode dne následujícího po splatnosti (§ 1970 obč. zák.) do dne
 * úhrady / rozhodného dne (včetně). Denní úrok:
 *
 *     úrok = jistina × (repo_k_počátku_prodlení + 8) / 100 × dny / dní_v_roce
 *
 * kde dní_v_roce = 365, resp. 366 v přestupném roce (actual/actual). Segmentace
 * v `$segments` rozděluje období přes hranici kalendářního roku (kvůli jmenovateli
 * 365 vs. 366) a při částečné úhradě (kvůli změně jistiny). Sazbu neovlivňuje —
 * `repo_rate`/`annual_rate` jsou ve všech segmentech stejné.
 */
final class PenaltyInterestCalculator
{
    /**
     * Zákonná přirážka k repo sazbě v procentních bodech (NV 351/2013, § 2) — pouze
     * DOKUMENTOVANÝ FALLBACK pro rok, který číselník daňových konstant nezná.
     *
     * Živá hodnota je klíč `penalty_repo_surcharge_points`
     * ({@see \MyInvoice\Repository\TaxConstantsRepository::penaltyRepoSurchargePoints()}),
     * aby ji šlo po novele nařízení opravit číselníkem jako každou jinou roční konstantu.
     * Repo sazby samotné jsou v DB (`cnb_repo_rates`) už dávno; přirážka do kódu nepatřila,
     * protože špatná hodnota znamená špatnou částku penále na dokladu, který jde klientovi.
     */
    public const SURCHARGE_POINTS = 8.0;

    public function __construct(
        private readonly RepoRateProvider $rates,
        private readonly TaxConstantsRepository $taxConstants,
    ) {}

    /**
     * @param ?DateTimeImmutable $accrualFrom Volitelný posun počátku ÚČTOVÁNÍ dnů (ne sazby) —
     *   pro navazující penalizaci, která pokrývá jen dny NEPOKRYTÉ předchozí penalizační
     *   fakturou (den po jejím `penalty_covered_through`). Sazba zůstává fixovaná k PŮVODNÍMU
     *   počátku prodlení (`$dueDate`+1) dle § 2 NV 351/2013 — `$accrualFrom` mění jen to, od
     *   kterého dne se dny/úrok skutečně počítají (zabrání dvojímu vyúčtování téhož období).
     *   Je-li dřívější než skutečný počátek prodlení, ignoruje se.
     * @param list<array{paid_on:string, amount:float}> $payments Úhrady snižují jistinu
     *   od dne následujícího po přijetí; v den úhrady úrok ještě běží.
     * @return array{
     *   principal: float,
     *   due_date: string,
     *   as_of: string,
     *   total_days: int,
     *   total_interest: float,
     *   surcharge_points: float,
     *   segments: list<array{from:string, to:string, days:int, repo_rate:float, annual_rate:float, day_count_basis:int, interest:float}>
     * }
     * @throws \DomainException když pro pololetí vzniku prodlení není nastavena repo sazba
     */
    public function compute(
        float $principal,
        DateTimeImmutable $dueDate,
        DateTimeImmutable $asOf,
        ?DateTimeImmutable $accrualFrom = null,
        array $payments = [],
    ): array
    {
        $principal = round($principal, 2);
        $due   = $dueDate->setTime(0, 0);
        $asOf  = $asOf->setTime(0, 0);

        // Prodlení běží ode dne následujícího po splatnosti.
        $delayStart = $due->modify('+1 day');

        // Sazba i přirážka se FIXUJÍ k pololetí, ve kterém prodlení VZNIKLO — jedna
        // hodnota pro celou dobu prodlení (viz doc-block třídy), NEmění se se segmenty ani
        // posunem $accrualFrom (ten mění jen počátek účtování dnů, ne sazbu).
        $halfStart = $this->halfYearStart($delayStart);
        // Přirážka je roční daňová konstanta (§ 2 NV 351/2013), ne hodnota v kódu —
        // číselník ji po novele nařízení opraví bez releasu, stejně jako repo sazby v DB.
        $surcharge = $this->taxConstants->penaltyRepoSurchargePoints((int) $halfStart->format('Y'));

        $base = [
            'principal'        => $principal,
            'due_date'         => $due->format('Y-m-d'),
            'as_of'            => $asOf->format('Y-m-d'),
            'total_days'       => 0,
            'total_interest'   => 0.0,
            'surcharge_points' => $surcharge,
            'segments'         => [],
        ];

        // Není po splatnosti (ani jistina) → žádný úrok (no-op).
        if ($principal <= 0.0 || $asOf <= $due) {
            return $base;
        }

        $repo = $this->rates->rateOn($halfStart);
        if ($repo === null) {
            throw new \DomainException(
                'Repo sazba ČNB k ' . $halfStart->format('Y-m-d') . ' není nastavena — '
                    . 'doplňte ji v číselníku repo sazeb.'
            );
        }
        $annual = $repo + $surcharge;

        $cursor = $delayStart;
        if ($accrualFrom !== null) {
            $accrualFrom = $accrualFrom->setTime(0, 0);
            if ($accrualFrom > $cursor) {
                $cursor = $accrualFrom;
            }
        }

        // Celé období už bylo dřívější penalizací pokryto → žádný nový úrok.
        if ($cursor > $asOf) {
            return $base;
        }

        $paymentsByDate = [];
        foreach ($payments as $payment) {
            $amount = round((float) ($payment['amount'] ?? 0), 2);
            if ($amount <= 0.0 || empty($payment['paid_on'])) {
                continue;
            }
            $date = (new DateTimeImmutable((string) $payment['paid_on']))->setTime(0, 0)->format('Y-m-d');
            $paymentsByDate[$date] = round(($paymentsByDate[$date] ?? 0.0) + $amount, 2);
        }
        ksort($paymentsByDate);

        $outstanding = $principal;
        foreach ($paymentsByDate as $paidOn => $amount) {
            if (new DateTimeImmutable($paidOn) >= $cursor) {
                break;
            }
            $outstanding = max(0.0, round($outstanding - $amount, 2));
        }

        $segments = [];
        $totalInterest = 0.0;
        $totalDays = 0;

        while ($cursor <= $asOf && $outstanding > 0.0) {
            // Segment nikdy nepřekračuje hranici kalendářního roku ani datum úhrady.
            // Sazba je ve všech segmentech stejná (fixovaná výše).
            $yearEnd = new DateTimeImmutable($cursor->format('Y') . '-12-31');
            $segEnd  = $yearEnd < $asOf ? $yearEnd : $asOf;
            $paymentOnSegmentEnd = null;
            foreach ($paymentsByDate as $paidOn => $_amount) {
                $paymentDate = new DateTimeImmutable($paidOn);
                if ($paymentDate < $cursor) {
                    continue;
                }
                if ($paymentDate <= $segEnd) {
                    $segEnd = $paymentDate;
                    $paymentOnSegmentEnd = $paidOn;
                }
                break;
            }

            $days = (int) $cursor->diff($segEnd)->days + 1; // včetně krajních dnů
            $basis  = $this->daysInYear((int) $cursor->format('Y'));
            $interest = $outstanding * ($annual / 100.0) * $days / $basis;

            $segments[] = [
                'from'            => $cursor->format('Y-m-d'),
                'to'              => $segEnd->format('Y-m-d'),
                'days'            => $days,
                'repo_rate'       => round($repo, 3),
                'annual_rate'     => round($annual, 3),
                'day_count_basis' => $basis,
                'interest'        => round($interest, 2),
            ];
            $totalInterest += $interest;
            $totalDays += $days;

            if ($paymentOnSegmentEnd !== null) {
                $outstanding = max(0.0, round($outstanding - $paymentsByDate[$paymentOnSegmentEnd], 2));
                unset($paymentsByDate[$paymentOnSegmentEnd]);
            }
            $cursor = $segEnd->modify('+1 day');
        }

        $base['segments']       = $segments;
        $base['total_days']     = $totalDays;
        $base['total_interest'] = round($totalInterest, 2);
        return $base;
    }

    private function halfYearStart(DateTimeImmutable $d): DateTimeImmutable
    {
        $year  = (int) $d->format('Y');
        $month = (int) $d->format('n');
        return new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month <= 6 ? 1 : 7));
    }

    private function daysInYear(int $year): int
    {
        return ($year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0)) ? 366 : 365;
    }
}
