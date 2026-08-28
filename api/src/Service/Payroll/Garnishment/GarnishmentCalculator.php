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
        try {
            $version = $this->rulesets->forDate(
                PayrollRulesetDomain::EnforcementDeductions,
                $input->paymentDate,
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
        $excess = max(0, $remainder - $fullyAttachableThreshold);
        $roundingTrace = [
            $protectedTrace,
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
        $fourRule = $this->fourEnforcementRuleApplies(
            $claims,
            $input->pensionEvidence,
            $third,
            $policy,
        );
        $allocation = $this->allocateClaims($claims, $third, $excess, $fourRule, $policy);
        if ($allocation === null) {
            return $this->manualReview(
                $input,
                ['employer_fee_iteration_did_not_converge'],
                $policy->rulesetId(),
                $policy->rulesetHash(),
                $scope,
            );
        }

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

        $withheld = self::addExactly($allocation['claim_total'], $allocation['fee']);

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
     * @return array{
     *   first:array<string,int>,
     *   second:array<string,int>,
     *   fee:int,
     *   claim_total:int
     * }|null
     * @param list<DeductionClaim> $claims
     */
    private function allocateClaims(
        array $claims,
        int $third,
        int $excess,
        bool $fourRule,
        EnforcementDeductionPolicy2026 $policy,
    ): ?array {
        $requestedFee = 0;
        $flatFeeMaximum = $policy->money('employer_flat_fee.maximum.monthly');

        for ($iteration = 0; $iteration < 64; $iteration++) {
            $balances = [];
            foreach ($claims as $claim) {
                $balances[$claim->id] = $claim->outstandingMinorUnits;
            }

            $priorityCapacity = self::addExactly($third, $excess);
            $second = $this->allocatePriorityClaims($claims, $priorityCapacity, $balances);
            $priorityUsed = self::sumExactly($second);
            $generalBeforeFee = self::generalPool($third, $excess, $priorityUsed, $fourRule);
            $actualFee = min($requestedFee, $generalBeforeFee);
            $first = $this->allocateRankedClaims(
                $claims,
                $generalBeforeFee - $actualFee,
                $balances,
            );
            $claimTotal = self::addExactly(self::sumExactly($first), $priorityUsed);
            $grossWithholding = self::addExactly($claimTotal, $actualFee);

            $candidateFee = $this->hasEligibleFeeClaim($claims, $policy) && $grossWithholding > 0
                ? min(
                    $flatFeeMaximum,
                    $generalBeforeFee,
                    self::ceilOneThirdToWholeCrown($grossWithholding),
                )
                : 0;

            if ($candidateFee === $actualFee) {
                return [
                    'first' => $first,
                    'second' => $second,
                    'fee' => $actualFee,
                    'claim_total' => $claimTotal,
                ];
            }
            $requestedFee = $candidateFee;
        }

        return null;
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
     * @param list<DeductionClaim> $claims
     */
    private function hasEligibleFeeClaim(array $claims, EnforcementDeductionPolicy2026 $policy): bool
    {
        $feeOrderEffectiveFrom = $policy->text('employer_flat_fee.order_effective_from');
        foreach ($claims as $claim) {
            if (
                $claim->legalBasis === DeductionLegalBasis::Statutory
                && $claim->orderIssuedOn >= $feeOrderEffectiveFrom
            ) {
                return true;
            }
        }

        return false;
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
        $orders = [];
        foreach ($claims as $claim) {
            if (
                $claim->legalBasis === DeductionLegalBasis::Statutory
                && $claim->enforcementOrderId !== null
            ) {
                $orders[$claim->enforcementOrderId] = true;
            }
        }
        $statutoryCount = count($orders);
        if ($statutoryCount < 4) {
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
        if (
            !$this->isDate($input->paymentDate)
            || $input->paymentDate < $policy->effectiveFrom()
            || $input->paymentDate > $policy->effectiveTo()
        ) {
            $issues[] = 'payment_date_outside_ruleset_2026';
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

        $statutoryOrders = [];
        foreach ($activeClaims as $claim) {
            if (
                $claim->legalBasis === DeductionLegalBasis::Statutory
                && $claim->enforcementOrderId !== null
            ) {
                $statutoryOrders[$claim->enforcementOrderId] = true;
            }
        }
        $statutoryCount = count($statutoryOrders);
        if ($statutoryCount >= 4 && $input->pensionEvidence === PensionEvidence::Unknown) {
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
