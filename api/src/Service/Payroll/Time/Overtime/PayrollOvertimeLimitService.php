<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

use MyInvoice\Repository\Payroll\PayrollOvertimeRepository;
use MyInvoice\Service\Payroll\Run\PayrollRunValidation;

/**
 * Spojka mezi evidencí přesčasu, rulesetem a čistou logikou § 93.
 *
 * ─── PŘEKROČENÝ LIMIT VARUJE, PORUŠENÝ ZÁKAZ SI ŘEKNE O PODPIS ──────────────
 *
 * Překročení limitu podle § 93 je právní vada NA STRANĚ ZAMĚSTNAVATELE, ne vada
 * výpočtu. Přesčas, který zaměstnanec odpracoval, mu zaměstnavatel musí podle
 * § 114 zaplatit i tehdy, když ho nařídil nad zákonný rozsah — neplatnost
 * příkazu nemá za následek, že se odvedená práce neproplácí. Zastavit kvůli
 * tomu výplatu by tedy k první vadě přidalo druhou, těžší, a poškodilo by to
 * jedinou osobu, která na porušení nemá vinu.
 *
 * Proto všechny nálezy o LIMITECH jdou do `payroll_run_validations` jako
 * `warning` s `requires_override = false` a jako `info` u včasného upozornění:
 *
 *   • `blocker` je vyloučený — {@see \MyInvoice\Service\Payroll\Run\PayrollRunWorkflow}
 *     na něm zastaví příkaz `approve`, tedy přesně výplatu.
 *   • `requires_override = true` je u limitů vyloučené TAKÉ, protože workflow
 *     na nevyřešeném overridu zastaví `approve` rovněž. Mzda je podle § 114
 *     splatná bez ohledu na to, jestli někdo výjimku stihl odklepnout.
 *
 * ZÁKAZY práce přesčas se ale posuzují jinak. U mladistvého (§ 245 odst. 1),
 * u těhotné zaměstnankyně (§ 240 odst. 3 věta první), u zaměstnance pečujícího
 * o dítě mladší 1 roku a u zaměstnance s kratší pracovní dobou
 * (§ 78 odst. 1 písm. i)) nejde o překročení stropu, ale o práci, která se
 * konat vůbec neměla. Takový nález nesmí projít mlčky, proto jde do validací
 * jako `warning` s `requires_override = true`:
 *
 *   • Workflow bez vyřízené výjimky `approve` zastaví — účetní se k nálezu
 *     musí vyjádřit, nemůže ho přehlédnout v seznamu varování.
 *   • Cesta ven existuje a je rychlá
 *     ({@see \MyInvoice\Service\Payroll\Run\PayrollRunValidationOverrideService}),
 *     takže se mzda nezadrží — jen se k ní připojí pojmenovaný důvod, kterým
 *     zaměstnavatel porušení zákazu doloženě vzal na vědomí. To je přesně
 *     rozdíl mezi „tichým povolením" a vědomým rozhodnutím.
 *   • `blocker` se nepoužívá ani tady: z blokeru není cesta ven a mzda
 *     zaměstnance, který porušení nezpůsobil, by uvázla natrvalo.
 *
 * Doloženost se tím neztrácí: validace se ukládá k REVIZI běhu, takže u každé
 * schválené revize navždy zůstane, že se na porušení v ten okamžik
 * upozorňovalo. Včasnost řeší docházka — assessment se počítá i pro otevřený
 * měsíc, takže mzdová účetní vidí stav dřív, než běh vůbec vznikne.
 */
final class PayrollOvertimeLimitService
{
    private readonly OvertimeLimitEvaluator $evaluator;

    public function __construct(
        private readonly PayrollOvertimeRepository $repository,
        private readonly OvertimeLimitRules $rules,
        ?OvertimeLimitEvaluator $evaluator = null,
    ) {
        $this->evaluator = $evaluator ?? new OvertimeLimitEvaluator();
    }

    /**
     * @param array<int,?string> $employmentStarts začátek vztahu podle ID, kvůli
     *        kratšímu vyrovnávacímu období na začátku pracovního poměru
     * @param list<int> $employmentIds
     * @return array<int,OvertimeLimitAssessment>
     */
    public function assessMany(
        int $supplierId,
        array $employmentIds,
        string $periodStart,
        string $periodEnd,
        array $employmentStarts = [],
    ): array {
        if ($employmentIds === []) {
            return [];
        }
        $limits = $this->averagingLimits($supplierId, $this->rules->forDate($periodStart), $periodStart);
        $from = self::historyStart($periodStart, $periodEnd, $limits);
        $evidence = $this->repository->segmentsAndManualMonthsForMany(
            $supplierId,
            $employmentIds,
            $from,
            $periodEnd,
        );
        $consents = $this->repository->consentsForMany($supplierId, $employmentIds);
        $compensations = $this->repository->compensationsForMany(
            $supplierId,
            $employmentIds,
            $from,
            $periodEnd,
        );
        $protections = $this->repository->protectionsForMany($supplierId, $employmentIds);
        $profiles = $this->repository->profilesForMany($supplierId, $employmentIds);

        $result = [];
        foreach ($employmentIds as $employmentId) {
            $result[$employmentId] = $this->evaluator->assess(
                $employmentId,
                $periodStart,
                $periodEnd,
                $evidence['segments'][$employmentId] ?? [],
                $consents[$employmentId] ?? [],
                $limits,
                $employmentStarts[$employmentId] ?? null,
                $compensations[$employmentId] ?? [],
                $protections[$employmentId] ?? [],
                $profiles[$employmentId] ?? null,
            );
            if (($evidence['manual_months'][$employmentId] ?? []) !== []) {
                $result[$employmentId] = $result[$employmentId]->withManualSummaryPeriods(
                    $evidence['manual_months'][$employmentId], $from, $periodEnd,
                );
            }
        }

        return $result;
    }

    /** @return list<PayrollRunValidation> */
    public function validations(OvertimeLimitAssessment $assessment): array
    {
        $validations = [];
        foreach ($assessment->findings as $finding) {
            $validations[] = new PayrollRunValidation(
                $finding->severity,
                $finding->code,
                'employment',
                $assessment->employmentId,
                $finding->message,
                '/payroll/time',
                $finding->requiresOverride,
            );
        }

        return $validations;
    }

    /**
     * Vyrovnávací období podle § 93 odst. 4 je firemní údaj, ne parametr
     * rulesetu. Bez nastavení nebo s nastavením, které si neumí obhájit
     * kolektivní smlouvu, se zůstává u zákonných 26 týdnů — kratší okno je
     * konzervativní strana, protože se stejný objem přesčasu poměřuje s nižším
     * stropem.
     */
    private function averagingLimits(
        int $supplierId,
        OvertimeLimits $limits,
        string $periodStart,
    ): OvertimeLimits {
        $period = $this->repository->averagingPeriodFor($supplierId, $periodStart);
        if ($period === null) {
            return $limits;
        }
        try {
            return $limits->withAveragingPeriod(
                $period['weeks'],
                $period['basis'],
                $period['reference'],
            );
        } catch (\InvalidArgumentException) {
            return $limits;
        }
    }

    /**
     * Nejstarší den, který musí být v podkladech: buď začátek kalendářního roku
     * (kvůli ročnímu limitu), nebo začátek nejdelšího možného vyrovnávacího
     * období, podle toho, co je dřív.
     */
    private static function historyStart(
        string $periodStart,
        string $periodEnd,
        OvertimeLimits $limits,
    ): string {
        $yearStart = substr($periodStart, 0, 4) . '-01-01';
        $end = new \DateTimeImmutable($periodEnd, new \DateTimeZone('UTC'));
        $windowStart = $end
            ->modify('-' . ((int) $end->format('N') - 1) . ' days')
            ->modify(sprintf('-%d days', 7 * $limits->averagingMaxWeeks))
            ->format('Y-m-d');

        return min($yearStart, $windowStart);
    }
}
