<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

/**
 * Rozvrh kapacity mezi dohody o srážkách ze mzdy (§ 2045 a násl. občanského
 * zákoníku, § 148 odst. 2 zákoníku práce).
 *
 * ## Pořadí se řídí dnem doručení dohody plátci mzdy
 *
 * Věřitel nabývá práva na výplatu srážek proti plátci mzdy okamžikem, kdy mu
 * byla dohoda doručena (§ 2045 odst. 2 OZ). Dohoda se přitom provádí jen za
 * podmínek výkonu rozhodnutí srážkami ze mzdy (§ 148 odst. 2 zákoníku práce),
 * takže se na ni použije i § 280 odst. 5 o. s. ř.: „Pořadí pohledávek se řídí
 * dnem, kdy bylo plátci mzdy doručeno nařízení výkonu rozhodnutí. Bylo-li mu
 * doručeno téhož dne nařízení výkonu rozhodnutí pro několik pohledávek, mají
 * tyto pohledávky stejné pořadí; nestačí-li částka na ně připadající k jejich
 * plnému uspokojení, uspokojí se poměrně."
 *
 * Do 8/2026 se dohody řadily jen podle ručně nastaveného `priority` a při shodě
 * podle `strcmp()` referencí, tedy ABECEDNĚ a sekvenčně: dohoda `a-…` dostala
 * všechno a `b-…` nic, i když měly totéž pořadí (nález E-08). Teď rozhoduje
 * nejdřív den doručení, pak `priority`, a uvnitř jedné skupiny se dělí POMĚRNĚ
 * podle nárokovaných částek metodou největších zbytků.
 *
 * ## Co tenhle rozvrh NEŘEŠÍ
 *
 * Kapacita, která sem přijde, je už očištěná o exekuční srážky
 * ({@see \MyInvoice\Service\Payroll\Garnishment\GarnishmentCalculator::voluntaryDeductionCapacity()}),
 * takže dohoda dostane vždy až zbytek po exekucích. Pro dohodu doručenou plátci
 * DŘÍV než exekuční příkaz to je málo — podle § 280 odst. 5 má lepší pořadí
 * a měla by se uspokojit před ním (nález E-03). Rozvrh mezi dohodou a exekucí
 * umí jen exekuční jádro, které pořadí podle dne doručení počítá
 * ({@see \MyInvoice\Service\Payroll\Garnishment\EnforcementPriorityResolver}) —
 * a rejstřík pohledávek na to je připravený:
 * {@see \MyInvoice\Service\Payroll\Garnishment\DeductionLegalBasis::Agreement}
 * je platný právní titul a `priority_date` nese den doručení. Dohodu s lepším
 * pořadím než exekuce je proto potřeba zavést do rejstříku pohledávek, ne do
 * téhle tabulky. Zbývá k tomu doplnit `delivered_on` do
 * `payroll_deduction_agreements` a přemostění v sestavovači mzdového běhu;
 * dokud to není hotové, `deliveredOn` sem chodí jako `null` a chování je
 * shodné s dřívějším.
 */
final class DeductionPriorityResolver
{
    /**
     * @param list<PayrollDeductionRequest> $deductions
     * @return list<PayrollDeductionResult>
     */
    public function resolve(array $deductions, int $capacityMinorUnits): array
    {
        if ($capacityMinorUnits < 0) {
            throw new \InvalidArgumentException('Kapacita dobrovolných srážek nesmí být záporná.');
        }
        usort($deductions, static fn (
            PayrollDeductionRequest $left,
            PayrollDeductionRequest $right,
        ): int => self::deliveryKey($left) <=> self::deliveryKey($right)
            ?: $left->priority <=> $right->priority
            ?: strcmp($left->deductionReference, $right->deductionReference));

        $remaining = $capacityMinorUnits;
        /** @var array<string,int> $applied */
        $applied = [];
        foreach (self::rankGroups($deductions) as $group) {
            $eligible = [];
            $groupTotal = 0;
            foreach ($group as $deduction) {
                $amount = self::eligibleAmount($deduction);
                $eligible[$deduction->deductionReference] = $amount;
                $groupTotal += $amount;
            }
            if ($groupTotal === 0) {
                continue;
            }
            $share = min($remaining, $groupTotal);
            $remaining -= $share;
            foreach (self::split($eligible, $groupTotal, $share) as $reference => $amount) {
                $applied[$reference] = $amount;
            }
            if ($remaining === 0) {
                break;
            }
        }

        $results = [];
        foreach ($deductions as $deduction) {
            $amount = $applied[$deduction->deductionReference] ?? 0;
            $results[] = new PayrollDeductionResult(
                $deduction->deductionReference,
                $deduction->priority,
                $deduction->requestedMinorUnits,
                $amount,
                $deduction->requestedMinorUnits - $amount,
                $deduction->active,
            );
        }

        return $results;
    }

    private static function eligibleAmount(PayrollDeductionRequest $deduction): int
    {
        return $deduction->active
            ? min(
                $deduction->requestedMinorUnits,
                $deduction->remainingLimitMinorUnits
                    ?? $deduction->requestedMinorUnits,
            )
            : 0;
    }

    /**
     * Dohoda bez zaznamenaného dne doručení se řadí až za všechny, u kterých je
     * znám — fail-closed, aby chybějící údaj nikomu pořadí nevylepšil.
     */
    private static function deliveryKey(PayrollDeductionRequest $deduction): string
    {
        return $deduction->deliveredOn ?? '9999-12-31';
    }

    /**
     * Skupiny stejného pořadí. Stejné pořadí = týž den doručení plátci mzdy
     * a totéž `priority`; jen uvnitř nich se podle § 280 odst. 5 věty druhé
     * o. s. ř. dělí poměrně.
     *
     * @param list<PayrollDeductionRequest> $deductions
     * @return list<list<PayrollDeductionRequest>>
     */
    private static function rankGroups(array $deductions): array
    {
        $groups = [];
        $currentKey = null;
        foreach ($deductions as $deduction) {
            $key = self::deliveryKey($deduction) . "\0" . $deduction->priority;
            if ($groups === [] || $key !== $currentKey) {
                $groups[] = [];
                $currentKey = $key;
            }
            $groups[array_key_last($groups)][] = $deduction;
        }

        return $groups;
    }

    /**
     * Poměrné rozdělení metodou největších zbytků, celočíselně a bez floatů.
     * Zbytkové haléře dostane ten, komu při dělení zbylo nejvíc; při shodě
     * rozhoduje reference, aby byl výsledek deterministický.
     *
     * @param array<string,int> $weights
     * @return array<string,int>
     */
    private static function split(array $weights, int $weightTotal, int $pool): array
    {
        $allocated = [];
        $remainders = [];
        $assigned = 0;
        foreach ($weights as $reference => $weight) {
            $product = $pool * $weight;
            $share = intdiv($product, $weightTotal);
            $allocated[$reference] = $share;
            $remainders[$reference] = $product % $weightTotal;
            $assigned += $share;
        }

        $order = array_keys($weights);
        usort($order, static function (string $left, string $right) use ($remainders): int {
            return $remainders[$right] <=> $remainders[$left]
                ?: strcmp($left, $right);
        });
        while ($assigned < $pool) {
            $progressed = false;
            foreach ($order as $reference) {
                if ($assigned === $pool) {
                    break;
                }
                if ($allocated[$reference] >= $weights[$reference]) {
                    continue;
                }
                $allocated[$reference]++;
                $assigned++;
                $progressed = true;
            }
            if (!$progressed) {
                break;
            }
        }

        return $allocated;
    }
}
