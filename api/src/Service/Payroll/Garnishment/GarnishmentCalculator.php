<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use DateTimeImmutable;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use OverflowException;

final class GarnishmentCalculator
{
    /**
     * Provider je POVINNÁ závislost. Volitelný parametr s defaultem by PHP-DI
     * nevyplnilo a výpočet by tiše četl výchozí sadu z kódu — administrátorská
     * změna nezabavitelných částek by se neprojevila (chyba MZ-02-W08).
     */
    public function __construct(
        private readonly PayrollRulesetProvider $rulesets,
        private readonly EnforcementPriorityResolver $priorities = new EnforcementPriorityResolver(),
    ) {}

    public function calculate(GarnishmentInput $input): GarnishmentResult
    {
        $scope = $this->evidenceScope($input);
        $policy = null;
        $rulesetId = null;
        $rulesetHash = null;
        $rulesetIssues = [];
        $rulesetDate = self::januaryFirstOfPaymentYear($input->paymentDate);
        try {
            $version = $this->rulesets->forDate(
                PayrollRulesetDomain::EnforcementDeductions,
                $rulesetDate,
            );
            $rulesetId = $version->id;
            $rulesetHash = $version->canonicalHash;
            $policy = EnforcementDeductionPolicy2026::forRuleset($version);
        } catch (\Throwable) {
            $rulesetIssues[] = $rulesetId === null
                ? 'payment_date_outside_ruleset_2026'
                : 'enforcement_ruleset_incomplete';
        }
        if ($policy === null) {
            // Identita se bere z ÚČINNÉHO rulesetu, kdykoli existuje — i zastavený
            // výsledek musí říct, na čem se zastavil. Teprve když datum nepokrývá
            // žádná sada, zbývá identita výchozí sady z kódu.
            $shipped = EnforcementDeductionPolicy2026::shipped();

            return $this->manualReview(
                $input,
                [...$this->validateInput($input, $shipped, $scope), ...$rulesetIssues],
                $rulesetId ?? $shipped->rulesetId(),
                $rulesetHash ?? $shipped->rulesetHash(),
                $scope,
            );
        }

        $issues = $this->validateInput($input, $policy, $scope);
        if ($issues !== []) {
            return $this->manualReview(
                $input,
                $issues,
                $policy->rulesetId(),
                $policy->rulesetHash(),
                $scope,
            );
        }

        $fullyAttachableThreshold = $policy->money('fully_attachable.threshold.monthly');
        [$protectedAmount, $protectedTrace] = $this->protectedAmount($input, $policy);
        $income = $input->income->garnishableMinorUnits;
        $remainder = max(0, $income - $protectedAmount);
        $thirdsBase = intdiv(
            min($remainder, $fullyAttachableThreshold),
            300,
        ) * 300;
        $third = intdiv($thirdsBase, 3);
        // ZÁMĚRNĚ z nezaokrouhleného zbytku (nález E-09, rozhodnuto 8/2026).
        //
        // § 279 odst. 3 o. s. ř. odkazuje na „zbytek čisté mzdy vypočtené podle
        // odstavce 1 věty první", tedy formálně už na zbytek zaokrouhlený dolů
        // na částku dělitelnou třemi. Doslovný výklad by dal
        // `excess = floor3(remainder) − threshold`, tj. o 0 až 2 Kč méně.
        //
        // Kód drží druhou variantu — zaokrouhlení dolů na dělitelnost třemi se
        // aplikuje jen na tu část zbytku, která se SKUTEČNĚ dělí na třetiny,
        // a část nad hranicí se sráží celá. Důvody:
        //
        //  • zaokrouhlení v odst. 1 má jediný účel: aby třetiny vyšly na celé
        //    koruny beze zbytku. Nad hranicí se na třetiny nedělí nic, takže
        //    tam ta potřeba nevzniká a zaokrouhlení nemá co řešit;
        //  • § 279 odst. 3 věta druhá s plně zabavitelnou částí zachází jako
        //    s celkem, který se rozděluje mezi druhou a první třetinu — ne jako
        //    s dalším dělením na třetiny;
        //  • je to metodika kalkulačky Exekutorské komory i příručky MPSV, na
        //    kterou jsou navázané kontrolní výpočty účetních. Odchylka do 2 Kč
        //    měsíčně by se reklamovala u KAŽDÉ mzdy nad hranicí.
        //
        // Rozhodnutí se nemění bez judikátu nebo změny metodiky MPSV; kdo se
        // k tomu vrátí, ať sem doplní důvod, ne jen nové číslo.
        $excess = max(0, $remainder - $fullyAttachableThreshold);
        $roundingTrace = [
            $protectedTrace,
            [
                // § 4 nař. vlády č. 595/2006 Sb. — viz januaryFirstOfPaymentYear().
                'step' => 'ruleset_effective_date',
                'payment_date' => $input->paymentDate,
                'january_first_of_payment_year' => $rulesetDate,
                'ruleset_id' => $policy->rulesetId(),
            ],
            [
                'step' => 'thirds_base',
                'input_minor_units' => min($remainder, $fullyAttachableThreshold),
                'multiple_minor_units' => 300,
                'rounding' => 'floor',
                'output_minor_units' => $thirdsBase,
            ],
            [
                'step' => 'one_third',
                'input_minor_units' => $thirdsBase,
                'divisor' => 3,
                'rounding' => 'exact_after_thirds_base',
                'output_minor_units' => $third,
            ],
            [
                'step' => 'fully_attachable_excess',
                'threshold_minor_units' => $fullyAttachableThreshold,
                'output_minor_units' => $excess,
            ],
        ];

        if ($input->insolvency->mode === InsolvencyMode::ApprovedStandard) {
            $withheld = min($income, self::addExactly(self::addExactly($third, $third), $excess));
            $allocations = $withheld === 0
                ? []
                : [new GarnishmentAllocation('insolvency-administrator', 0, $withheld)];

            return new GarnishmentResult(
                $input->period,
                GarnishmentStatus::Supported,
                $income,
                $protectedAmount,
                $third,
                $excess,
                0,
                $withheld,
                $income - $withheld,
                false,
                true,
                $allocations,
                [],
                $roundingTrace,
                $policy->rulesetId(),
                $policy->rulesetHash(),
                $scope,
            );
        }

        $claims = $this->activeClaims($input->claims);
        // Pravidlo čtyř exekucí se počítá z EVIDENCE, ne z pohledávek, na které
        // v tomhle měsíci zbyl zůstatek — viz orderedEnforcementCount().
        $fourRule = $this->fourEnforcementRuleApplies(
            $input->claims,
            $input->pensionEvidence,
            $third,
            $policy,
        );
        $allocation = $this->allocateClaims($claims, $third, $excess, $fourRule, $policy);

        $allocations = [];
        foreach ($claims as $claim) {
            $first = $allocation['first'][$claim->id] ?? 0;
            $second = $allocation['second'][$claim->id] ?? 0;
            if ($first === 0 && $second === 0) {
                continue;
            }
            $allocations[] = new GarnishmentAllocation($claim->id, $first, $second);
        }
        usort(
            $allocations,
            static fn (GarnishmentAllocation $a, GarnishmentAllocation $b): int =>
                $a->claimId <=> $b->claimId,
        );

        $withheld = $allocation['withheld'];

        return new GarnishmentResult(
            $input->period,
            GarnishmentStatus::Supported,
            $income,
            $protectedAmount,
            $third,
            $excess,
            $allocation['fee'],
            $withheld,
            $income - $withheld,
            $fourRule,
            false,
            $allocations,
            [],
            $roundingTrace,
            $policy->rulesetId(),
            $policy->rulesetHash(),
            $scope,
        );
    }

    /**
     * Kolik z obecné (nepřednostní) kapacity zbylo po exekučních srážkách —
     * teprve z toho smí zaměstnavatel uspokojit dobrovolnou dohodu o srážkách
     * ze mzdy (§ 148 odst. 2 zákoníku práce: dohoda se provádí jen za podmínek
     * výkonu rozhodnutí srážkami ze mzdy podle § 276 a násl. OSŘ).
     *
     * Vrací 0, kdykoli výsledek není uzavřený nebo běží schválené oddlužení —
     * fail-closed, protože v takovém případě není jisté, co exekuce ještě vezme.
     *
     * A stejnou nulu vrací, když nezabavitelná částka stojí na nedoloženém
     * nároku na vyživovanou osobu nebo manžela. V měsíci bez exekuce se ten
     * doklad kvůli výpočtu srážky nevyžaduje (nemá co ovlivnit), jenže strop
     * dobrovolné dohody se podle § 148 odst. 2 zákoníku práce odvozuje z TÉŽE
     * nezabavitelné částky. Zúžení evidence proto nesmí dohodě otevřít cestu
     * k číslu, které předtím nikdo nedoložil: dřív takovou osobu shodilo ruční
     * posouzení a kapacita byla nula, teď je nula bez blokátoru na celém běhu.
     */
    public function voluntaryDeductionCapacity(GarnishmentResult $result): int
    {
        if ($result->status !== GarnishmentStatus::Supported
            || $result->insolvencyApplied
            || $result->evidenceSource?->protectedAmountIsUnattested() === true
        ) {
            return 0;
        }
        $priorityUsed = 0;
        $generalUsed = $result->employerFlatFeeMinorUnits;
        foreach ($result->allocations as $allocation) {
            $priorityUsed = self::addExactly(
                $priorityUsed,
                $allocation->secondPoolMinorUnits,
            );
            $generalUsed = self::addExactly(
                $generalUsed,
                $allocation->firstPoolMinorUnits,
            );
        }

        return max(0, self::generalPool(
            $result->thirdMinorUnits,
            $result->fullyAttachableExcessMinorUnits,
            $priorityUsed,
            $result->fourEnforcementRuleApplied,
        ) - $generalUsed);
    }

    /**
     * Obecná (nepřednostní) kapacita: první třetina, nevyužitý plně zabavitelný
     * zbytek a — při pravidle čtyř exekucí — i nevyužitá druhá třetina.
     */
    private static function generalPool(
        int $third,
        int $excess,
        int $priorityUsed,
        bool $fourRule,
    ): int {
        $excessUsed = max(0, $priorityUsed - $third);
        $unusedSecondThird = $fourRule
            ? max(0, $third - min($priorityUsed, $third))
            : 0;

        return self::addExactly(
            self::addExactly($third, $excess - $excessUsed),
            $unusedSecondThird,
        );
    }

    /**
     * Paušální náhradu nákladů plátce mzdy PLATÍ OPRÁVNĚNÝ, ne povinný.
     *
     * § 270 odst. 3 o. s. ř. (shodně § 87 odst. 3 exekučního řádu): plátce mzdy
     * si náhradu „odečte ze sražených částek, které mají být vyplaceny nebo
     * zaslány oprávněnému"; právo na náhradu, která nebyla odečtena ze sražené
     * částky před jejím vyplacením, zaniká. Srážka ze mzdy se o paušál tedy
     * NEZVYŠUJE — zaměstnanci se srazí přesně tolik, kolik by se srazilo
     * i bez něj, a z té částky si 50 Kč nechá zaměstnavatel.
     *
     * Dřív se paušál k pohledávkám PŘIČÍTAL (`withheld = claim_total + fee`).
     * Tam, kde srážku omezovala kapacita, vycházel součet náhodou správně,
     * jenže na doběhu exekuce ne: při zbývajícím dluhu 100 Kč se zaměstnanci
     * srazilo 150 Kč. Chyba se týkala každé exekuce s paušálem, tedy prakticky
     * všech od 1. 1. 2022 (nález E-02).
     *
     * Výpočet proto běží v jednom průchodu: nejdřív se rozdělí celá kapacita
     * mezi pohledávky (to je částka sražená ze mzdy), pak se z ní ukrojí
     * paušál. Iterativní hledání pevného bodu odpadlo — paušál už kapacitu
     * neovlivňuje, takže se nemá na čem stáčet.
     *
     * @return array{
     *   first:array<string,int>,
     *   second:array<string,int>,
     *   fee:int,
     *   withheld:int
     * }
     * @param list<DeductionClaim> $claims
     */
    private function allocateClaims(
        array $claims,
        int $third,
        int $excess,
        bool $fourRule,
        EnforcementDeductionPolicy2026 $policy,
    ): array {
        $balances = [];
        foreach ($claims as $claim) {
            $balances[$claim->id] = $claim->outstandingMinorUnits;
        }

        $priorityCapacity = self::addExactly($third, $excess);
        $second = $this->allocatePriorityClaims($claims, $priorityCapacity, $balances);
        $priorityUsed = self::sumExactly($second);
        $first = $this->allocateRankedClaims(
            $claims,
            self::generalPool($third, $excess, $priorityUsed, $fourRule),
            $balances,
        );

        // Sražená částka. Paušál ji nezvyšuje ani nesnižuje — jen se z ní bere.
        $withheld = self::addExactly(self::sumExactly($first), $priorityUsed);

        $fee = $this->hasEligibleFeeClaim($claims, $policy) && $withheld > 0
            ? min(
                // 50 Kč za kalendářní měsíc, a jen jednou na jednoho povinného
                // i při souběhu více pohledávek (§ 3 nař. vlády č. 595/2006 Sb.).
                $policy->money('employer_flat_fee.maximum.monthly'),
                // „nesmí přesáhnout třetinu částky sražené ze mzdy povinného
                // zaokrouhlenou na celé koruny nahoru"
                self::ceilOneThirdToWholeCrown($withheld),
                // Pojistka pro haléřové doběhy: zaokrouhlení nahoru nesmí
                // ukrojit víc, než kolik se vůbec srazilo.
                $withheld,
            )
            : 0;

        $firstNet = $this->carveEmployerFee($this->reverseFirstPoolOrder($claims), $first, $fee);
        $carvedFromFirst = self::sumExactly($first) - self::sumExactly($firstNet);
        $secondNet = $this->carveEmployerFee(
            $this->reverseSecondPoolOrder($claims),
            $second,
            $fee - $carvedFromFirst,
        );

        return [
            'first' => $firstNet,
            'second' => $secondNet,
            'fee' => $fee,
            'withheld' => $withheld,
        ];
    }

    /**
     * Odkud se paušál ukrojí, sráží-li se na víc pohledávek.
     *
     * Náhrada „se uspokojuje před všemi ostatními pohledávkami z první
     * třetiny" (§ 3 odst. 4 nař. vlády č. 595/2006 Sb.), takže o ni nepřijde
     * ten, kdo je v pořadí první, ale ten, na koho by zbylo jako na
     * posledního: krájí se v OBRÁCENÉM pořadí uspokojování. Uvnitř jedné
     * skupiny stejného pořadí se dělí poměrně podle přiznaných částek, aby
     * se pořadí nerozbilo abecedou.
     *
     * Až když první třetina nestačí — typicky měsíc, kdy celou srážku spolkne
     * výživné z druhé třetiny a na první třetinu nedojde — sáhne se do druhé
     * třetiny, opět od konce (§ 280 řeší jen rozvrh MEZI výživnými, ne poměr
     * k náhradě). Bez toho by zaměstnavateli náhrada v takovém měsíci
     * propadla, přestože srážky prováděl; § 270 odst. 3 o. s. ř. přitom
     * dovoluje odečíst ji z kterékoli částky mířící oprávněnému.
     *
     * @param list<list<DeductionClaim>> $groups
     * @param array<string,int> $allocated
     * @return array<string,int>
     */
    private function carveEmployerFee(array $groups, array $allocated, int $remaining): array
    {
        if ($remaining <= 0) {
            return $allocated;
        }

        foreach ($groups as $group) {
            $ids = [];
            $groupTotal = 0;
            foreach ($group as $claim) {
                $amount = $allocated[$claim->id] ?? 0;
                if ($amount > 0) {
                    $ids[$claim->id] = $amount;
                    $groupTotal = self::addExactly($groupTotal, $amount);
                }
            }
            if ($groupTotal === 0) {
                continue;
            }

            $carve = min($remaining, $groupTotal);
            $remaining -= $carve;
            $unassigned = $carve;
            $remainders = [];
            foreach ($ids as $claimId => $amount) {
                $product = self::multiplyExactly($carve, $amount);
                $share = intdiv($product, $groupTotal);
                $allocated[$claimId] -= $share;
                $unassigned -= $share;
                $remainders[$claimId] = $product % $groupTotal;
            }
            if ($unassigned > 0) {
                $order = array_keys($ids);
                usort($order, static function (int|string $a, int|string $b) use ($remainders): int {
                    $remainderOrder = $remainders[$b] <=> $remainders[$a];

                    return $remainderOrder !== 0 ? $remainderOrder : (string) $a <=> (string) $b;
                });
                foreach ($order as $claimId) {
                    if ($unassigned === 0) {
                        break;
                    }
                    if ($allocated[$claimId] === 0) {
                        continue;
                    }
                    $allocated[$claimId]--;
                    $unassigned--;
                }
            }
            if ($remaining === 0) {
                break;
            }
        }

        return $allocated;
    }

    /**
     * Obrácené pořadí uspokojování z první třetiny.
     *
     * @param list<DeductionClaim> $claims
     * @return list<list<DeductionClaim>>
     */
    private function reverseFirstPoolOrder(array $claims): array
    {
        return array_reverse($this->priorities->resolve($claims));
    }

    /**
     * Obrácené pořadí uspokojování z druhé třetiny: nejdřív ostatní přednostní
     * od konce, teprve pak výživné od poslední kategorie.
     *
     * @param list<DeductionClaim> $claims
     * @return list<list<DeductionClaim>>
     */
    private function reverseSecondPoolOrder(array $claims): array
    {
        $groups = array_reverse($this->priorities->resolve(array_values(array_filter(
            $claims,
            static fn (DeductionClaim $claim): bool =>
                $claim->category === ClaimCategory::OtherPriority,
        ))));

        foreach (array_reverse(ClaimCategory::maintenanceCategories()) as $category) {
            $group = array_values(array_filter(
                $claims,
                static fn (DeductionClaim $claim): bool => $claim->category === $category,
            ));
            if ($group !== []) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * @param list<DeductionClaim> $claims
     * @param array<string, int> $balances
     * @return array<string, int>
     */
    private function allocatePriorityClaims(array $claims, int $capacity, array &$balances): array
    {
        $allocated = [];

        foreach (ClaimCategory::maintenanceCategories() as $category) {
            $group = array_values(array_filter(
                $claims,
                static fn (DeductionClaim $claim): bool => $claim->category === $category,
            ));
            $used = $this->allocateProportionally(
                $group,
                $capacity,
                $balances,
                true,
            );
            $allocated = $this->mergeAllocation($allocated, $used);
            $capacity -= self::sumExactly($used);
            if ($capacity === 0) {
                return $allocated;
            }
        }

        $otherPriority = array_values(array_filter(
            $claims,
            static fn (DeductionClaim $claim): bool =>
                $claim->category === ClaimCategory::OtherPriority,
        ));
        $allocated = $this->mergeAllocation(
            $allocated,
            $this->allocateRankedClaims($otherPriority, $capacity, $balances),
        );

        return $allocated;
    }

    /**
     * @param list<DeductionClaim> $claims
     * @param array<string, int> $balances
     * @return array<string, int>
     */
    private function allocateRankedClaims(array $claims, int $capacity, array &$balances): array
    {
        $allocated = [];
        foreach ($this->priorities->resolve($claims) as $priorityGroup) {
            if ($capacity <= 0) {
                break;
            }
            $group = array_values(array_filter(
                $priorityGroup,
                static fn (DeductionClaim $claim): bool => ($balances[$claim->id] ?? 0) > 0,
            ));
            if ($group === []) {
                continue;
            }

            $used = $this->allocateProportionally($group, $capacity, $balances, false);
            $allocated = $this->mergeAllocation($allocated, $used);
            $capacity -= self::sumExactly($used);
        }

        return $allocated;
    }

    /**
     * @param list<DeductionClaim> $claims
     * @param array<string, int> $balances
     * @return array<string, int>
     */
    private function allocateProportionally(
        array $claims,
        int $capacity,
        array &$balances,
        bool $useMaintenanceWeight,
    ): array {
        $available = 0;
        foreach ($claims as $claim) {
            $available = self::addExactly($available, $balances[$claim->id] ?? 0);
        }
        $remainingPool = min($capacity, $available);
        $allocated = [];

        while ($remainingPool > 0) {
            $active = array_values(array_filter(
                $claims,
                static fn (DeductionClaim $claim): bool => ($balances[$claim->id] ?? 0) > 0,
            ));
            if ($active === []) {
                break;
            }

            $weightTotal = 0;
            foreach ($active as $claim) {
                $weight = $useMaintenanceWeight
                    ? (int) $claim->maintenanceWeightMinorUnits
                    : $balances[$claim->id];
                $weightTotal = self::addExactly($weightTotal, $weight);
            }
            // Poměrné dělení potřebuje kladný součet vah. Nulový součet je
            // dosažitelný jen u výživného s nulovou nebo chybějící vahou —
            // to `validateInput()` shodí do ručního posouzení dřív, než se sem
            // dojde (`claim:*:maintenance_weight_missing`). Kdyby se tam ta
            // kontrola někdy vypnula, bez téhle pojistky by výpočet spadl na
            // dělení nulou uprostřed rozvrhu (nález E-13). Fail-safe je
            // rozdělit poměrně podle zůstatků: § 280 odst. 3 sice žádá poměr
            // běžného výživného, ale ten není znám, a nechat věřitele bez
            // ničeho je horší než rozdělit podle dluhu.
            if ($weightTotal <= 0) {
                if ($useMaintenanceWeight) {
                    $useMaintenanceWeight = false;
                    continue;
                }

                break;
            }

            $remainders = [];
            $capped = false;
            $roundPool = $remainingPool;
            foreach ($active as $claim) {
                $weight = $useMaintenanceWeight
                    ? (int) $claim->maintenanceWeightMinorUnits
                    : $balances[$claim->id];
                $product = self::multiplyExactly($roundPool, $weight);
                $floorShare = intdiv($product, $weightTotal);
                $grant = min($floorShare, $balances[$claim->id]);
                if ($floorShare > $balances[$claim->id]) {
                    $capped = true;
                }
                if ($grant > 0) {
                    $allocated[$claim->id] = self::addExactly(
                        $allocated[$claim->id] ?? 0,
                        $grant,
                    );
                    $balances[$claim->id] -= $grant;
                    $remainingPool -= $grant;
                }
                $remainders[$claim->id] = $product % $weightTotal;
            }

            if ($remainingPool === 0) {
                break;
            }
            if ($capped) {
                continue;
            }

            usort($active, static function (DeductionClaim $a, DeductionClaim $b) use ($remainders): int {
                $remainderOrder = $remainders[$b->id] <=> $remainders[$a->id];

                return $remainderOrder !== 0 ? $remainderOrder : $a->id <=> $b->id;
            });
            foreach ($active as $claim) {
                if ($remainingPool === 0) {
                    break;
                }
                if ($balances[$claim->id] === 0) {
                    continue;
                }
                $allocated[$claim->id] = self::addExactly($allocated[$claim->id] ?? 0, 1);
                $balances[$claim->id]--;
                $remainingPool--;
            }
        }

        ksort($allocated, SORT_STRING);

        return $allocated;
    }

    /**
     * Nárok plátce mzdy na paušální náhradu nákladů.
     *
     * Rozhodné je DORUČENÍ příkazu plátci mzdy, ne den jeho vydání. Právo
     * i povinnost plátce mzdy vznikají až doručením (§ 282 odst. 1 a 3
     * o. s. ř.: srážky provádí „po tom, kdy mu bude nařízení výkonu
     * doručeno") a náhrada přísluší za měsíc, v němž plátce srážky skutečně
     * provádí. Příkaz vydaný v prosinci 2021 a doručený v lednu 2022 tedy
     * nárok zakládá, přestože podle data vydání by nevycházel; opačně příkaz
     * vydaný 2021 a doručený 2021 nárok nezakládá, i kdyby se sráželo dál.
     *
     * Do 8/2026 se testovalo `orderIssuedOn`, tedy datum vydání (nález E-11).
     * Den doručení plátci nese `priorityDate` — je to týž údaj, ze kterého
     * § 280 odst. 5 o. s. ř. odvozuje pořadí (sloupec
     * `payroll_enforcement_claims.first_payer_delivered_on`, migrace 1594).
     * Chybí-li, `validateInput()` měsíc stejně shodí do ručního posouzení,
     * takže se tu čte fail-closed jako „nárok nevznikl".
     *
     * @param list<DeductionClaim> $claims
     */
    private function hasEligibleFeeClaim(array $claims, EnforcementDeductionPolicy2026 $policy): bool
    {
        $feeOrderEffectiveFrom = $policy->text('employer_flat_fee.order_effective_from');
        foreach ($claims as $claim) {
            if (
                $claim->legalBasis === DeductionLegalBasis::Statutory
                && $claim->priorityDate !== null
                && $claim->priorityDate >= $feeOrderEffectiveFrom
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kolik NAŘÍZENÝCH a plátci mzdy doručených výkonů rozhodnutí na mzdě leží.
     *
     * § 279 odst. 4 o. s. ř. váže dvě třetiny na to, že „jsou na mzdu povinného
     * současně nařízeny nejméně 4 výkony rozhodnutí k vymožení splatných
     * peněžitých pohledávek" a že usnesení „bylo doručeno plátci mzdy" —
     * o zůstatku pohledávky nemluví. Do 8/2026 se ale počítalo nad
     * `activeClaims()`, které pohledávky s nulovým zůstatkem odfiltruje, takže
     * měsíc, kdy na jednu z pěti exekucí zrovna nic nezbývalo (doplatek přišel
     * jinou cestou, souběžný plátce ji umořil), pravidlo vypnul a povinnému se
     * srazila jen jedna třetina (nález E-15).
     *
     * Počítá se proto nad VŠEMI evidovanými pohledávkami: zastavená exekuce se
     * eviduje jako neaktivní (`active === false`) a do počtu nepatří, kdežto
     * nařízená exekuce s momentálně nulovým zůstatkem ano.
     *
     * @param list<DeductionClaim> $claims
     */
    private function orderedEnforcementCount(array $claims): int
    {
        $orders = [];
        foreach ($claims as $claim) {
            if (
                $claim->active
                && $claim->legalBasis === DeductionLegalBasis::Statutory
                && $claim->orderOrNoticeDelivered
                && $claim->enforcementOrderId !== null
                && trim($claim->enforcementOrderId) !== ''
            ) {
                $orders[$claim->enforcementOrderId] = true;
            }
        }

        return count($orders);
    }

    /**
     * @param list<DeductionClaim> $claims
     */
    private function fourEnforcementRuleApplies(
        array $claims,
        PensionEvidence $pensionEvidence,
        int $third,
        EnforcementDeductionPolicy2026 $policy,
    ): bool {
        if ($this->orderedEnforcementCount($claims) < 4) {
            return false;
        }

        return !(
            $pensionEvidence === PensionEvidence::Verified
            && $third < $policy->money('four_enforcement_rule.pension_exception_limit')
        );
    }

    /**
     * Čtvrtina na manžela/partnera od 1. 1. 2025 (nař. vlády č. 441/2024 Sb.).
     *
     * Do 31. 12. 2024 se manžel do nezabavitelné částky započítával
     * automaticky. Od účinnosti novely náleží čtvrtina jen tehdy, doloží-li
     * povinný plátci mzdy, že jemu NEBO jeho manželovi či partnerovi byl
     * přiznán starobní důchod, invalidní důchod pro invaliditu druhého nebo
     * třetího stupně anebo sirotčí důchod. Stačí jeden z nich.
     *
     * Nedoložený i nezjištěný důchod tedy čtvrtinu nezakládá — v obou
     * případech není splněna zákonná podmínka. Rozdíl mezi nimi je jen
     * v tom, že nezjištěný stav navíc shodí měsíc se srážkou do ručního
     * posouzení; viz {@see SpousePensionEvidence} a {@see evidenceScope()}.
     */
    private static function spouseAllowanceApplies(GarnishmentInput $input): bool
    {
        return $input->eligibleSpouse
            && $input->spousePensionEvidence === SpousePensionEvidence::Documented;
    }

    /** @return array{int, array<string, int|string|bool>} */
    private function protectedAmount(
        GarnishmentInput $input,
        EnforcementDeductionPolicy2026 $policy,
    ): array {
        if ($input->hasMultiplePayers) {
            $amount = (int) $input->protectedAmountOverrideMinorUnits;

            return [
                $amount,
                [
                    'step' => 'protected_amount',
                    'court_decision_override' => true,
                    'rounding' => 'court_determined',
                    'output_minor_units' => $amount,
                ],
            ];
        }

        $spouseAllowance = self::spouseAllowanceApplies($input);
        $allowanceCount = $input->eligibleDependants + ($spouseAllowance ? 1 : 0);
        $shareDenominator = $policy->integer('dependant_share.denominator');
        $factorNumerator = $shareDenominator
            + ($allowanceCount * $policy->integer('dependant_share.numerator'));
        $numerator = self::multiplyExactly(
            $policy->money('protected_amount.debtor_base.monthly'),
            $factorNumerator,
        );
        $denominator = $shareDenominator;
        $amount = self::ceilFractionToMultiple($numerator, $denominator, 100);

        return [
            $amount,
            [
                'step' => 'protected_amount',
                'court_decision_override' => false,
                'eligible_allowance_count' => $allowanceCount,
                'spouse_allowance_applied' => $spouseAllowance,
                'spouse_pension_evidence' => $input->spousePensionEvidence->value,
                'unrounded_numerator' => $numerator,
                'unrounded_denominator' => $denominator,
                'rounding_multiple_minor_units' => 100,
                'rounding' => 'ceil_after_sum',
                'output_minor_units' => $amount,
            ],
        ];
    }

    /**
     * Kdy má která z měsíčních evidencí co dokládat.
     *
     * Dřív se všechny tři vyžadovaly bezpodmínečně, u každé osoby a každý
     * měsíc. Firma o tisíci lidech tak měla ročně 12 000 zápisů, které
     * u člověka bez jediné exekuce nedokládaly nic — a bez nich jí každý
     * mzdový běh skončil nepřebitelným blokátorem `enforcement_manual_review`.
     *
     * Rozsah se proto váže na to, co která evidence skutečně ovlivňuje:
     *
     *  • rejstřík pohledávek rozhoduje o rozdělení srážky mezi pohledávky.
     *    Bez aktivní pohledávky a bez insolvence není co rozdělovat. Insolvence
     *    je uvnitř záměrně, i když si částku určuje sama: souběžná exekuce je
     *    v tom režimu důvod k ručnímu posouzení, takže vědět o ní je věcné;
     *  • u manžela/partnera je od 1. 1. 2025 součástí doložení i důchod podle
     *    nař. vlády č. 441/2024 Sb. (viz {@see spouseAllowanceApplies()}).
     *    Nezjištěný stav (`unknown`, typicky záznam z doby před zavedením
     *    evidence) proto není doložený nárok: v měsíci se srážkou skončí
     *    blokátorem, v měsíci bez srážky jen uzavře kapacitu dobrovolných
     *    dohod. Výslovné „důchod doložen není" je naopak úplná evidence —
     *    čtvrtina prostě nenáleží a nic se neblokuje;
     *  • nárok na vyživovanou osobu a na manžela zvedá nezabavitelnou částku.
     *    Neuplatněný nárok (počet 0, resp. `false`) ji neposouvá a při souběhu
     *    plátců ji stejně určuje soudní rozhodnutí — v obou případech není co
     *    dokládat. Uplatněný a nedoložený nárok v měsíci bez srážky nešíří
     *    issue, ale uzavře kapacitu dobrovolných dohod; viz
     *    {@see EnforcementEvidenceSource::NothingWithheld}.
     *
     * Ostatní kontroly (pořadí pohledávek, právní titul, duplicitní ID,
     * rozhodnutí soudu při souběhu plátců, insolvence) se nemění.
     */
    private function evidenceScope(GarnishmentInput $input): EnforcementEvidenceScope
    {
        $withholdingArises = $this->activeClaims($input->claims) !== []
            || $input->insolvency->mode !== InsolvencyMode::None;
        $allowanceScope = static fn (bool $claimed, bool $declared): EnforcementEvidenceSource =>
            !$claimed || $input->hasMultiplePayers
                ? EnforcementEvidenceSource::NotApplicable
                : ($declared
                    ? EnforcementEvidenceSource::Declared
                    : ($withholdingArises
                        ? EnforcementEvidenceSource::Missing
                        : EnforcementEvidenceSource::NothingWithheld));

        return new EnforcementEvidenceScope(
            $input->claimRegisterEvidenceComplete
                ? EnforcementEvidenceSource::Declared
                : ($withholdingArises
                    ? EnforcementEvidenceSource::Missing
                    : EnforcementEvidenceSource::NotApplicable),
            $allowanceScope(
                $input->eligibleDependants > 0,
                $input->dependantsEvidenceComplete,
            ),
            $allowanceScope(
                $input->eligibleSpouse,
                $input->spouseEvidenceComplete
                    && $input->spousePensionEvidence
                        !== SpousePensionEvidence::Unknown,
            ),
        );
    }

    /** @return list<string> */
    private function validateInput(
        GarnishmentInput $input,
        EnforcementDeductionPolicy2026 $policy,
        EnforcementEvidenceScope $scope,
    ): array {
        $issues = $scope->issues();
        if (
            $input->eligibleSpouse
            && $input->spouseEvidenceComplete
            && $input->spousePensionEvidence === SpousePensionEvidence::Unknown
            && $scope->spouse === EnforcementEvidenceSource::Missing
        ) {
            $issues[] = 'spouse_quarter_pension_evidence_unknown';
        }
        if (!$this->isPeriod($input->period)) {
            $issues[] = 'invalid_payroll_period';
        }
        if (!$this->isDate($input->paymentDate)) {
            $issues[] = 'payment_date_outside_ruleset_2026';
        } else {
            // § 4 nař. vlády č. 595/2006 Sb. žádá hodnoty ve výši platné
            // k 1. lednu roku, do něhož připadá den výplaty, a ty musí platit
            // celý rok. Sada, která končí dřív než 31. prosince (nebo začíná
            // po 1. lednu), tuhle podmínku splnit nemůže — ať už jde
            // o administrátorský override s vnitroroční účinností, nebo
            // o nedopatřením zúžený interval. Fail-closed: měsíc jde na ruční
            // posouzení, nepočítá se z hodnot, které § 4 použít nedovoluje
            // (nález E-06).
            $year = substr($input->paymentDate, 0, 4);
            if ($policy->effectiveFrom() > "{$year}-01-01"
                || $policy->effectiveTo() < "{$year}-12-31"
            ) {
                $issues[] = 'enforcement_ruleset_not_effective_for_whole_year';
            }
        }
        if ($input->income->status !== GarnishmentStatus::Supported) {
            foreach ($input->income->issues as $incomeIssue) {
                $issues[] = "income:{$incomeIssue}";
            }
        }

        if ($input->hasMultiplePayers) {
            if ($input->protectedAmountOverrideMinorUnits === null) {
                $issues[] = 'multiple_payers_protected_amount_decision_missing';
            }
            if (!$input->protectedAmountOverrideVerified) {
                $issues[] = 'multiple_payers_protected_amount_decision_not_verified';
            }
        } else {
            if ($input->protectedAmountOverrideMinorUnits !== null) {
                $issues[] = 'protected_amount_override_without_multiple_payers';
            }
            if ($input->protectedAmountOverrideVerified) {
                $issues[] = 'protected_amount_decision_verified_without_multiple_payers';
            }
        }

        $activeClaims = $this->activeClaims($input->claims);
        $seen = [];
        foreach ($activeClaims as $claim) {
            if (isset($seen[$claim->id])) {
                $issues[] = "claim:{$claim->id}:duplicate_id";
                continue;
            }
            $seen[$claim->id] = true;
            if ($claim->priorityDate === null || !$this->isDate($claim->priorityDate)) {
                $issues[] = "claim:{$claim->id}:delivery_date_missing";
            }
            if (!$claim->priorityClassificationVerified) {
                $issues[] = "claim:{$claim->id}:priority_classification_not_verified";
            }

            if ($claim->legalBasis === DeductionLegalBasis::Statutory) {
                if (!$claim->legalTitleVerified) {
                    $issues[] = "claim:{$claim->id}:legal_title_not_verified";
                }
                if (!$claim->orderOrNoticeDelivered) {
                    $issues[] = "claim:{$claim->id}:order_or_notice_not_delivered";
                }
                if ($claim->orderIssuedOn === null || !$this->isDate($claim->orderIssuedOn)) {
                    $issues[] = "claim:{$claim->id}:order_issue_date_missing";
                }
                if (!$claim->dueMonetaryClaimVerified) {
                    $issues[] = "claim:{$claim->id}:due_monetary_claim_not_verified";
                }
                if ($claim->enforcementOrderId === null || trim($claim->enforcementOrderId) === '') {
                    $issues[] = "claim:{$claim->id}:enforcement_order_id_missing";
                }
            } else {
                if (!$claim->agreementVerified) {
                    $issues[] = "claim:{$claim->id}:deduction_agreement_not_verified";
                }
                if ($claim->category->isPriority()) {
                    $issues[] = "claim:{$claim->id}:voluntary_agreement_cannot_be_priority";
                }
            }

            if ($claim->category->requiresMaintenanceWeight()
                && ($claim->maintenanceWeightMinorUnits ?? 0) <= 0
            ) {
                $issues[] = "claim:{$claim->id}:maintenance_weight_missing";
            }
        }

        if ($this->orderedEnforcementCount($input->claims) >= 4
            && $input->pensionEvidence === PensionEvidence::Unknown
        ) {
            $issues[] = 'four_enforcement_pension_exception_evidence_unknown';
        }

        if ($input->insolvency->mode !== InsolvencyMode::None) {
            if (!$input->insolvency->decisionVerified) {
                $issues[] = 'insolvency_decision_not_verified';
            }
            if (!$input->insolvency->recipientVerified) {
                $issues[] = 'insolvency_recipient_not_verified';
            }
            if ($input->insolvency->mode === InsolvencyMode::ApprovedStandard
                && !$input->insolvency->hasImmutablePaymentInstruction()
            ) {
                $issues[] = 'insolvency_payment_instruction_missing';
            }
            if ($activeClaims !== []) {
                $issues[] = 'concurrent_enforcement_with_insolvency_requires_manual_review';
            }
            if ($input->insolvency->mode === InsolvencyMode::AlertOnly) {
                $issues[] = 'insolvency_alert_cannot_redirect_payment';
            }
            if ($input->insolvency->mode === InsolvencyMode::CourtDeterminedAmount) {
                $issues[] = 'court_determined_insolvency_amount_requires_manual_review';
            }
        } elseif ($input->insolvency->courtDeterminedAmountMinorUnits !== null) {
            $issues[] = 'court_determined_amount_without_insolvency';
        }

        sort($issues, SORT_STRING);

        return array_values(array_unique($issues));
    }

    /**
     * @param list<DeductionClaim> $claims
     * @return list<DeductionClaim>
     */
    private function activeClaims(array $claims): array
    {
        return $this->priorities->orderedActiveClaims($claims);
    }

    /** @param list<string> $issues */
    private function manualReview(
        GarnishmentInput $input,
        array $issues,
        string $rulesetId,
        string $rulesetHash,
        EnforcementEvidenceScope $scope,
    ): GarnishmentResult {
        sort($issues, SORT_STRING);
        $income = $input->income->garnishableMinorUnits;

        return new GarnishmentResult(
            $input->period,
            GarnishmentStatus::ManualReview,
            $income,
            0,
            0,
            0,
            0,
            0,
            $income,
            false,
            false,
            [],
            array_values(array_unique($issues)),
            [],
            $rulesetId,
            $rulesetHash,
            $scope,
        );
    }

    /**
     * @param array<string, int> $target
     * @param array<string, int> $source
     * @return array<string, int>
     */
    private function mergeAllocation(array $target, array $source): array
    {
        foreach ($source as $claimId => $amount) {
            $target[$claimId] = self::addExactly($target[$claimId] ?? 0, $amount);
        }
        ksort($target, SORT_STRING);

        return $target;
    }

    /** @param array<string, int> $amounts */
    private static function sumExactly(array $amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total = self::addExactly($total, $amount);
        }

        return $total;
    }

    private static function ceilOneThirdToWholeCrown(int $minorUnits): int
    {
        $crowns = intdiv($minorUnits, 300);
        if ($minorUnits % 300 !== 0) {
            $crowns++;
        }

        return self::multiplyExactly($crowns, 100);
    }

    private static function ceilFractionToMultiple(
        int $numerator,
        int $denominator,
        int $multiple,
    ): int {
        $combinedDenominator = self::multiplyExactly($denominator, $multiple);
        $units = intdiv($numerator, $combinedDenominator);
        if ($numerator % $combinedDenominator !== 0) {
            $units++;
        }

        return self::multiplyExactly($units, $multiple);
    }

    private static function addExactly(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new OverflowException('Garnishment amount exceeds the integer range.');
        }

        return $left + $right;
    }

    private static function multiplyExactly(int $left, int $right): int
    {
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw new OverflowException('Garnishment multiplication exceeds the integer range.');
        }

        return $left * $right;
    }

    /**
     * Který den rozhoduje o tom, ZE KTERÉ sady se čtou nezabavitelné částky.
     *
     * § 4 nař. vlády č. 595/2006 Sb.: „Při výpočtu nezabavitelné částky
     * a částky, nad kterou se zbytek čisté mzdy srazí bez omezení, se použije
     * částka životního minima jednotlivce, částka normativních nákladů na
     * bydlení … ve výši platné k 1. lednu kalendářního roku, do něhož připadá
     * den výplaty mzdy."
     *
     * Do 8/2026 se sada hledala k SAMOTNÉMU dni výplaty. U dodaných sad, které
     * pokrývají celý kalendářní rok, to vycházelo nastejno — jenže registry
     * rulesetů dovolí administrátorský override s vnitroroční účinností
     * a `forDate()` by ho poslušně použil. Vláda přitom mění životní minimum
     * i normativní náklady na bydlení několikrát za rok a pro srážky ze mzdy
     * se ta změna podle § 4 uplatní až od 1. ledna roku následujícího (nález
     * E-06). Rozhodné datum se proto srovná na 1. leden roku výplaty.
     *
     * Zpětné opravy to nerozbíjí: sada 2025 je účinná 1. 1.–31. 12. 2025
     * a sada 2026 celý rok 2026, takže výplata z kteréhokoli měsíce trefí
     * touž sadu jako dřív. Že sada opravdu platí celý rok, hlídá
     * `enforcement_ruleset_not_effective_for_whole_year` ve {@see validateInput()}.
     *
     * Neplatné datum se vrací beze změny — shodí `forDate()` a měsíc skončí
     * na ručním posouzení, což je táž cesta jako dřív.
     */
    private static function januaryFirstOfPaymentYear(string $paymentDate): string
    {
        if (preg_match('/^(\d{4})-\d{2}-\d{2}$/D', $paymentDate, $matches) !== 1) {
            return $paymentDate;
        }

        return "{$matches[1]}-01-01";
    }

    private function isPeriod(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m', $value);

        return $parsed !== false && $parsed->format('Y-m') === $value;
    }

    private function isDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
