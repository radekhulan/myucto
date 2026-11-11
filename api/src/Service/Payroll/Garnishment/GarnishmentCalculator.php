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
        if ($input->severanceMultiples !== []) {
            return $this->calculateWithSeverance($input);
        }
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
            $issues = [...$this->validateInput($input, $shipped, $scope), ...$rulesetIssues];
            $nothingWithheld = $this->nothingWithheldResult(
                $input,
                $issues,
                $rulesetId ?? $shipped->rulesetId(),
                $rulesetHash ?? $shipped->rulesetHash(),
                $scope,
            );

            return $nothingWithheld ?? $this->manualReview(
                $input,
                $issues,
                $rulesetId ?? $shipped->rulesetId(),
                $rulesetHash ?? $shipped->rulesetHash(),
                $scope,
            );
        }

        $issues = $this->validateInput($input, $policy, $scope);
        if ($issues !== []) {
            $nothingWithheld = $this->nothingWithheldResult(
                $input,
                $issues,
                $policy->rulesetId(),
                $policy->rulesetHash(),
                $scope,
            );

            return $nothingWithheld ?? $this->manualReview(
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

        if ($input->insolvency->mode->redirectsPaymentToAdministrator()) {
            $capacity = min($income, self::addExactly(self::addExactly($third, $third), $excess));
            $withheld = $this->insolvencyWithholding($input, $capacity, $roundingTrace);
            $suspended = count($this->activeClaims($input->claims));
            if ($suspended > 0) {
                $roundingTrace[] = [
                    'step' => 'enforcement_suspended_by_insolvency',
                    'suspended_claims' => $suspended,
                ];
            }
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
        // Zahájené insolvenční řízení (§ 109 odst. 1 písm. d) IZ): dohodou
        // založené právo na výplatu srážek ze mzdy uplatnit nelze, takže
        // dohoda o pořadí s exekucemi vůbec nesoutěží.
        $insolvencyCommenced = $input->insolvency->mode->depositsEnforcementDeductions();
        $agreements = $insolvencyCommenced ? [] : $this->bridgedAgreements($input);
        if ($insolvencyCommenced) {
            $roundingTrace[] = [
                'step' => 'insolvency_commenced_deposit',
                'deposited_claims' => count($claims),
            ];
        }
        // Pravidlo čtyř exekucí se počítá z EVIDENCE, ne z pohledávek, na které
        // v tomhle měsíci zbyl zůstatek — viz orderedEnforcementCount().
        // Dohody se do počtu nezapočítávají: § 279 odst. 4 o. s. ř. mluví
        // o NAŘÍZENÝCH výkonech rozhodnutí, ne o dobrovolných dohodách.
        $fourRule = $this->fourEnforcementRuleApplies(
            $input->claims,
            $input->pensionEvidence,
            $third,
            $policy,
        );
        $allocation = $this->allocateClaims(
            $claims,
            $agreements,
            $third,
            $excess,
            $fourRule,
            $policy,
            !self::isSeveranceMultipleIncome($input),
        );
        if ($allocation['voluntary_reserved'] > 0) {
            $roundingTrace[] = [
                'step' => 'voluntary_agreement_priority_reserve',
                'output_minor_units' => $allocation['voluntary_reserved'],
                'agreements' => count($agreements),
            ];
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
            $insolvencyCommenced,
            $allocations,
            [],
            $roundingTrace,
            $policy->rulesetId(),
            $policy->rulesetHash(),
            $scope,
        );
    }

    /**
     * Měsíc, ve kterém se spolu se mzdou vyplácí odstupné.
     *
     * § 299 odst. 4 věta první o. s. ř.: „Z odstupného se srážky vypočítávají
     * zvlášť z každého násobku průměrného výdělku, měsíčního platu … ze kterých
     * byla odvozena výše nebo minimální výše odstupného stanovená jinými
     * právními předpisy." Odstupné 3× průměr jsou tedy tři samostatné měsíční
     * příjmy, každý se svou nezabavitelnou částkou (§ 278 o. s. ř.) a svými
     * třetinami (§ 279), ne jedna trojnásobná mzda. Dřív se odstupné přičetlo
     * ke mzdě posledního měsíce a z celku se odečetla JEDNA nezabavitelná
     * částka — povinnému se srazilo víc, než zákon dovoluje (nález CYK-B16a).
     *
     * Postup:
     *  1. Mzda měsíce výplaty se spočítá jako obvykle (i s paušálem plátce
     *     mzdy a s dohodami o srážkách, které se berou jen ze mzdy).
     *  2. Každý násobek se spočítá jako samostatný měsíc v pořadí doby
     *     poskytování odstupného. Zůstatky pohledávek se mezi nimi snižují
     *     o to, co předchozí výpočet přiznal (§ 276 o. s. ř.), stejně jako
     *     v {@see GarnishmentBatchCalculator}.
     *  3. Z násobku se paušální náhrada nákladů neodečítá: plátce odstupného
     *     na ni podle § 301 odst. 2 o. s. ř. nemá nárok („Osoba … vůči které má
     *     povinný nárok … na příjmy podle § 299 odst. 1 písm. b) až d) a f)
     *     až l), nemá nárok na paušálně stanovenou náhradu nákladů"); odstupné
     *     je písm. g).
     *  4. Výsledky se sečtou do jednoho výsledku osoby a měsíce — sražená
     *     částka, nezabavitelné částky, třetiny i rozdělení mezi pohledávky.
     *     Strop § 281 o. s. ř. drží každý dílčí výpočet sám, takže drží
     *     i součet.
     *
     * Věta druhá § 299 odst. 4: nastoupí-li povinný v době poskytování
     * odstupného do práce nebo mu vznikne jiný příjem podle odst. 1 až 3,
     * „považují se jednotlivé násobky … za měsíční příjem, na který se vztahují
     * ustanovení o výkonu rozhodnutí srážkami ze mzdy" — násobek se v takovém
     * měsíci sčítá s druhým příjmem a vlastní nezabavitelná částka mu nenáleží.
     * Plátce odstupného druhý příjem nezná; počítá proto násobek s nulovou
     * nezabavitelnou částkou jen tehdy, když účetní potvrdí, že ji za ten měsíc
     * započítává druhý plátce (stejně jako u souběhu plátců, § 293 odst. 4).
     * Bez potvrzení se výpočet zastaví a nic se neodhaduje.
     *
     * Soudem určenou měsíční splátku v oddlužení (§ 398 odst. 5 IZ) nejde
     * na násobky přenést bez výkladu výroku soudu — zastaví se na ručním
     * posouzení.
     */
    private function calculateWithSeverance(GarnishmentInput $input): GarnishmentResult
    {
        $wage = $this->calculate($input->withoutSeverance());
        $multiples = GarnishmentInput::sortedMultiples($input->severanceMultiples);
        $totalIncome = $wage->garnishableIncomeMinorUnits;
        foreach ($multiples as $multiple) {
            $totalIncome = self::addExactly($totalIncome, $multiple->amountMinorUnits);
        }
        $issues = $wage->status === GarnishmentStatus::Supported ? [] : $wage->issues;
        if ($input->insolvency->mode === InsolvencyMode::CourtDeterminedAmount) {
            $issues[] = 'severance_with_court_determined_insolvency_installment';
        }
        $carried = [];
        foreach ($input->claims as $claim) {
            $carried[$claim->id] = $claim->outstandingMinorUnits;
        }
        self::reduceCarried($carried, $wage);
        /** @var list<array{0:SeveranceMultiple,1:GarnishmentResult}> $segments */
        $segments = [];
        if ($issues === []) {
            foreach ($multiples as $multiple) {
                if ($multiple->otherIncomeOverlap && !$multiple->otherPayerAppliesProtectedAmount) {
                    $issues[] = 'severance_multiple_other_income_unresolved';
                    continue;
                }
                $segment = $this->calculate($this->severanceSegmentInput($input, $multiple, $carried));
                if ($segment->status !== GarnishmentStatus::Supported) {
                    array_push($issues, ...$segment->issues);
                    continue;
                }
                self::reduceCarried($carried, $segment);
                $segments[] = [$multiple, $segment];
            }
        }
        if ($issues !== []) {
            sort($issues, SORT_STRING);

            return new GarnishmentResult(
                $input->period,
                GarnishmentStatus::ManualReview,
                $totalIncome,
                0,
                0,
                0,
                0,
                0,
                $totalIncome,
                false,
                false,
                [],
                array_values(array_unique($issues)),
                [],
                $wage->rulesetId,
                $wage->rulesetHash,
                $wage->evidenceSource,
            );
        }

        $protected = $wage->protectedAmountMinorUnits;
        $third = $wage->thirdMinorUnits;
        $excess = $wage->fullyAttachableExcessMinorUnits;
        $withheld = $wage->totalWithheldMinorUnits;
        $payment = $wage->employeePaymentMinorUnits;
        /** @var array<string,array{0:int,1:int}> $pools */
        $pools = [];
        foreach ($wage->allocations as $allocation) {
            $pools[$allocation->claimId] = [
                $allocation->firstPoolMinorUnits,
                $allocation->secondPoolMinorUnits,
            ];
        }
        $trace = $wage->roundingTrace;
        foreach ($segments as [$multiple, $segment]) {
            $protected = self::addExactly($protected, $segment->protectedAmountMinorUnits);
            $third = self::addExactly($third, $segment->thirdMinorUnits);
            $excess = self::addExactly($excess, $segment->fullyAttachableExcessMinorUnits);
            $withheld = self::addExactly($withheld, $segment->totalWithheldMinorUnits);
            $payment = self::addExactly($payment, $segment->employeePaymentMinorUnits);
            foreach ($segment->allocations as $allocation) {
                $current = $pools[$allocation->claimId] ?? [0, 0];
                $pools[$allocation->claimId] = [
                    self::addExactly($current[0], $allocation->firstPoolMinorUnits),
                    self::addExactly($current[1], $allocation->secondPoolMinorUnits),
                ];
            }
            $trace[] = [
                'step' => 'severance_multiple',
                'legal_basis' => 'osr-299-4',
                'multiple_index' => $multiple->index,
                'multiples' => count($multiples),
                'income_minor_units' => $segment->garnishableIncomeMinorUnits,
                'protected_amount_minor_units' => $segment->protectedAmountMinorUnits,
                'third_minor_units' => $segment->thirdMinorUnits,
                'fully_attachable_excess_minor_units' =>
                    $segment->fullyAttachableExcessMinorUnits,
                'withheld_minor_units' => $segment->totalWithheldMinorUnits,
                'employer_flat_fee_minor_units' => $segment->employerFlatFeeMinorUnits,
                'other_income_overlap' => $multiple->otherIncomeOverlap,
            ];
        }
        $allocations = [];
        foreach ($pools as $claimId => [$first, $second]) {
            if ($first === 0 && $second === 0) {
                continue;
            }
            $allocations[] = new GarnishmentAllocation((string) $claimId, $first, $second);
        }
        usort(
            $allocations,
            static fn (GarnishmentAllocation $a, GarnishmentAllocation $b): int =>
                $a->claimId <=> $b->claimId,
        );

        return new GarnishmentResult(
            $input->period,
            GarnishmentStatus::Supported,
            $totalIncome,
            $protected,
            $third,
            $excess,
            $wage->employerFlatFeeMinorUnits,
            $withheld,
            $payment,
            $wage->fourEnforcementRuleApplied,
            $wage->insolvencyApplied,
            $allocations,
            [],
            $trace,
            $wage->rulesetId,
            $wage->rulesetHash,
            $wage->evidenceSource,
        );
    }

    /** @param array<string,int> $carried */
    private function severanceSegmentInput(
        GarnishmentInput $input,
        SeveranceMultiple $multiple,
        array $carried,
    ): GarnishmentInput {
        $claims = [];
        foreach ($input->claims as $claim) {
            $claims[] = $claim->withOutstanding(
                min($claim->outstandingMinorUnits, $carried[$claim->id] ?? $claim->outstandingMinorUnits),
            );
        }
        $otherPayer = $multiple->otherIncomeOverlap;

        return new GarnishmentInput(
            $input->period,
            $input->paymentDate,
            new GarnishableIncomeResult(
                GarnishmentStatus::Supported,
                $multiple->amountMinorUnits,
                0,
                [],
                [[
                    'id' => "severance-multiple-{$multiple->index}",
                    'kind' => GarnishableIncomeKind::SeveranceMultiple->value,
                    'amount_minor_units' => $multiple->amountMinorUnits,
                    'payer_id' => 'severance',
                    'treatment' => 'garnishable',
                ]],
            ),
            $claims,
            $input->eligibleDependants,
            $input->dependantsEvidenceComplete,
            $input->eligibleSpouse,
            $input->spouseEvidenceComplete,
            $input->pensionEvidence,
            $otherPayer ? true : $input->hasMultiplePayers,
            $otherPayer ? 0 : $input->protectedAmountOverrideMinorUnits,
            $input->insolvency,
            $otherPayer ? true : $input->protectedAmountOverrideVerified,
            $input->claimRegisterEvidenceComplete,
            $input->spousePensionEvidence,
        );
    }

    /** @param array<string,int> $carried */
    private static function reduceCarried(array &$carried, GarnishmentResult $result): void
    {
        foreach ($result->allocations as $allocation) {
            if (!isset($carried[$allocation->claimId])) {
                continue;
            }
            $carried[$allocation->claimId] = max(
                0,
                $carried[$allocation->claimId] - $allocation->totalMinorUnits,
            );
        }
    }

    /**
     * Příjem je jeden násobek odstupného — plátce z něj nemá nárok na paušální
     * náhradu nákladů (§ 301 odst. 2 o. s. ř.).
     */
    private static function isSeveranceMultipleIncome(GarnishmentInput $input): bool
    {
        if ($input->income->trace === []) {
            return false;
        }
        foreach ($input->income->trace as $item) {
            if ($item['kind'] !== GarnishableIncomeKind::SeveranceMultiple->value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Kolik plátce mzdy srazí ve prospěch insolvenčního správce.
     *
     * ── Schválené oddlužení (standardní rozsah) ─────────────────────────────
     *
     * § 398 odst. 3 zákona č. 182/2006 Sb. (insolvenční zákon): při oddlužení
     * plněním splátkového kalendáře se zpeněžením majetkové podstaty dlužník
     * vydává správci „z příjmů, které získá po schválení oddlužení, částku ve
     * stejném rozsahu, v jakém z nich mohou být při výkonu rozhodnutí nebo při
     * exekuci uspokojeny přednostní pohledávky". Srážka je tedy celá zabavitelná
     * část, tedy obě třetiny i plně zabavitelný zbytek — přesně to, co spočítal
     * volající. Paušál podle § 270 odst. 3 o. s. ř. se neúčtuje, protože nejde
     * o výkon rozhodnutí srážkami ze mzdy.
     *
     * ── Soudem určená jiná výše měsíčních splátek ───────────────────────────
     *
     * § 398 odst. 5 IZ: „Dlužníku, který o to požádal v návrhu na povolení
     * oddlužení nebo v odůvodněných případech nejpozději při jednání
     * s insolvenčním správcem podle § 410 odst. 2, může insolvenční soud
     * stanovit jinou výši měsíčních splátek." Žádost podává dlužník podle
     * § 391 odst. 2 IZ, a to výslovně „o stanovení NIŽŠÍCH než zákonem
     * určených měsíčních splátek". Takové usnesení určuje splátku ČÁSTKOU,
     * ne rozsahem podle o. s. ř., a plátce mzdy podle § 406 odst. 3 písm. d)
     * IZ provádí „stanovené srážky" — tedy srážky v rozsahu, který mu uložil
     * výrok soudu. Sám výši nepočítá a nepřepočítává ji zpět na zákonnou.
     *
     * (Pozor na záměnu: § 398 odst. 4 IZ upravuje POŘADÍ uspokojování
     * pohledávek za majetkovou podstatou, ne výši splátky.)
     *
     * Soudem určená částka se proto sráží tak, jak ji soud určil, ale NIKDY
     * nad zákonnou kapacitu. Soud podle § 391 odst. 2 IZ splátku jen SNIŽUJE
     * a nezabavitelná částka podle § 278 o. s. ř. ve spojení s nař. vlády
     * č. 595/2006 Sb. je hranice, kterou plátce mzdy prolomit nesmí ani na
     * příkaz. V měsíci s nízkým příjmem tedy kapacita na určenou splátku
     * nestačí a srazí se jen kapacita — doplacení splátky je věc dlužníka
     * a insolvenčního soudu, ne plátce mzdy.
     *
     * Zdroje: https://www.zakonyprolidi.cz/cs/2006-182#p398,
     * https://www.zakonyprolidi.cz/cs/2006-182#p391,
     * https://www.zakonyprolidi.cz/cs/2006-182#p406,
     * https://www.zakonyprolidi.cz/cs/1963-99#p278.
     *
     * Chybějící částka se sem nedostane — {@see validateInput()} ji zastaví
     * výtkou `court_determined_insolvency_amount_missing`.
     *
     * @param list<array<string, int|string|bool>> $roundingTrace
     */
    private function insolvencyWithholding(
        GarnishmentInput $input,
        int $capacity,
        array &$roundingTrace,
    ): int {
        if ($input->insolvency->mode !== InsolvencyMode::CourtDeterminedAmount) {
            return $capacity;
        }
        $courtAmount = $input->insolvency->courtDeterminedAmountMinorUnits ?? 0;
        $withheld = min($capacity, $courtAmount);
        $roundingTrace[] = [
            'step' => 'court_determined_installment',
            'court_amount_minor_units' => $courtAmount,
            'statutory_capacity_minor_units' => $capacity,
            'output_minor_units' => $withheld,
        ];

        return $withheld;
    }

    /**
     * Kolik z obecné (nepřednostní) kapacity zbylo po exekučních srážkách —
     * teprve z toho smí zaměstnavatel uspokojit dobrovolnou dohodu o srážkách
     * ze mzdy (§ 148 odst. 2 zákoníku práce: dohoda se provádí jen za podmínek
     * výkonu rozhodnutí srážkami ze mzdy podle § 276 a násl. OSŘ).
     *
     * „Po exekučních srážkách" NEZNAMENÁ „až po nich v pořadí". Dohody
     * s doloženým dnem doručení plátci mzdy soutěží o obecnou část společně
     * s exekucemi podle § 280 odst. 5 o. s. ř. ({@see GarnishmentInput::$voluntaryAgreements}),
     * takže dohoda doručená dřív exekuci ubere a částka, která na ni připadla,
     * se sem vrátí jako nevyužitá kapacita: vzorec ji nemusí přičítat, protože
     * do `allocations` — a tím do `generalUsed` — se přemostěné dohody vůbec
     * nedostanou. Dohoda bez dne doručení se nepřemosťuje a dostane jen zbytek
     * po exekucích, přesně jako do 8/2026 (nález E-03).
     *
     * Vrací 0, kdykoli výsledek není uzavřený nebo běží insolvence (zahájené
     * řízení i oddlužení). To není opatrnost, ale zákon: § 109 odst. 1 písm. d) IZ říká, že po
     * zahájení insolvenčního řízení „nelze uplatnit dohodou věřitele
     * a dlužníka založené právo na výplatu srážek ze mzdy nebo jiných příjmů,
     * s nimiž se při výkonu rozhodnutí nakládá jako se mzdou nebo platem"
     * (https://www.zakonyprolidi.cz/cs/2006-182#p109). Dobrovolná dohoda se
     * tedy neprovádí bez ohledu na to, kolik kapacity po insolvenční srážce
     * zbylo.
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
     * Paušální náhradu nákladů plátce mzdy HRADÍ POVINNÝ, ne oprávněný.
     *
     * § 270 odst. 2 o. s. ř.: „Plátce mzdy má vůči povinnému nárok na paušálně
     * stanovenou náhradu nákladů, které mu vznikly za kalendářní měsíc, v němž
     * provádí srážky ze mzdy povinného. Náklady plátce mzdy jsou náklady
     * výkonu rozhodnutí.“ Na náklady výkonu se nařízení výkonu vztahuje také
     * (§ 270 odst. 1). Exekuční řád to říká přímo, § 87 odst. 3: „Paušálně
     * stanovenou náhradu nákladů hradí plátci mzdy povinný.“ Podle § 279
     * odst. 1 věty poslední se náhrada „uspokojuje před všemi ostatními
     * pohledávkami z první třetiny“ a plátce ji podle § 291 odst. 3 odečítá
     * ze sražených částek před jejich vyplacením.
     *
     * Dva důsledky:
     *
     *  - Kde srážku omezuje kapacita (běžný měsíc), bere se náhrada ze
     *    sražené částky a oprávněný dostane o ni méně; jeho pohledávka se
     *    umořuje jen tím, co mu skutečně došlo.
     *  - Kde kapacita stačí na víc (doběh exekuce, pohledávka uspokojená celá
     *    z druhé třetiny), srazí se náhrada NAVÍC z volné první třetiny:
     *    oprávněný dostane celý zbytek pohledávky a exekuce skončí. Při
     *    zbývajícím dluhu 1 000 Kč se srazí 1 050 Kč.
     *
     * Mezi lety se tu drželo opačné čtení („platí oprávněný“, nález E-02):
     * na doběhu se srazil jen zbývající dluh, oprávněný dostal o náhradu
     * méně a nedoplatek se v dalších měsících znovu krátil o další náhradu,
     * až zůstalo srážení 1 Kč měsíčně jen na náhradu, které neskončilo nikdy.
     *
     * Výše náhrady: nejvýše 50 Kč za měsíc a jen jednou na povinného,
     * a „nesmí přesáhnout třetinu částky sražené ze mzdy povinného
     * zaokrouhlenou na celé koruny nahoru“. Sraženou částkou je celá srážka
     * včetně náhrady, proto se strop u malých částek hledá postupným
     * snižováním (u zbytku dluhu 10 Kč vyjde náhrada 6 Kč a srážka 16 Kč).
     *
     * @return array{
     *   first:array<string,int>,
     *   second:array<string,int>,
     *   fee:int,
     *   withheld:int,
     *   voluntary_reserved:int
     * }
     * @param list<DeductionClaim> $claims
     * @param list<DeductionClaim> $agreements
     */
    private function allocateClaims(
        array $claims,
        array $agreements,
        int $third,
        int $excess,
        bool $fourRule,
        EnforcementDeductionPolicy2026 $policy,
        bool $employerFeeAllowed = true,
    ): array {
        $balances = [];
        foreach ([...$claims, ...$agreements] as $claim) {
            $balances[$claim->id] = $claim->outstandingMinorUnits;
        }

        // Druhá třetina patří podle § 280 odst. 1 a 2 o. s. ř. výhradně
        // přednostním pohledávkám; dohoda o srážkách přednostní být nemůže
        // (§ 148 odst. 2 zákoníku práce), takže do téhle fáze nevstupuje.
        $priorityCapacity = self::addExactly($third, $excess);
        $second = $this->allocatePriorityClaims($claims, $priorityCapacity, $balances);
        $priorityUsed = self::sumExactly($second);
        // Obecná (nepřednostní) část se rozděluje podle POŘADÍ, a to je podle
        // § 280 odst. 5 o. s. ř. den doručení plátci mzdy — bez ohledu na to,
        // jestli je titulem exekuční příkaz, nebo dohoda o srážkách. Dohody
        // proto soutěží společně s exekucemi; co připadne jim, se ale nesráží
        // tady, jen se to o ně zmenší (viz níž).
        $generalPool = self::generalPool($third, $excess, $priorityUsed, $fourRule);
        $agreementIds = [];
        foreach ($agreements as $agreement) {
            $agreementIds[$agreement->id] = true;
        }

        // Náhrada se uspokojuje z první třetiny PŘED všemi ostatními (§ 279
        // odst. 1 věta poslední): obecná část se o ni zmenší dřív, než se
        // rozdělí podle pořadí. Kde kapacita nestačí, přijde tím o náhradu
        // poslední v pořadí; kde stačí, dostanou oprávnění všechno a náhrada
        // se srazí navíc. Výše náhrady závisí na sražené částce a ta na
        // náhradě, proto se hledá od stropu dolů.
        $fee = $employerFeeAllowed && $this->hasEligibleFeeClaim($claims, $policy)
            // 50 Kč za kalendářní měsíc, a jen jednou na jednoho povinného
            // i při souběhu více pohledávek. VÝHRADNĚ z první třetiny: § 280
            // odst. 2 vypočítává, co se platí z druhé třetiny, taxativně
            // a náhrada mezi tím není.
            ? min($policy->money('employer_flat_fee.maximum.monthly'), $generalPool)
            : 0;
        while (true) {
            $rankedBalances = $balances;
            $ranked = $this->allocateRankedClaims(
                [...$claims, ...$agreements],
                $generalPool - $fee,
                $rankedBalances,
            );
            // Rozdělení výsledku. Částka, kterou si vzaly dohody, NENÍ
            // exekuční srážkou: nevyplácí ji exekuční jádro ani nevstupuje do
            // `withheld`. Sráží ji až čistá mzda z kapacity dobrovolných
            // srážek, kterou {@see self::voluntaryDeductionCapacity()} spočítá
            // jako zbytek obecné části po exekucích — a ten se o tuhle částku
            // právě zvětšil. Kdyby se započetla na obou místech, zaměstnanci
            // by se srazila dvakrát.
            $first = [];
            $voluntaryReserved = 0;
            foreach ($ranked as $claimId => $amount) {
                if (isset($agreementIds[$claimId])) {
                    $voluntaryReserved = self::addExactly($voluntaryReserved, $amount);
                    continue;
                }
                $first[$claimId] = $amount;
            }
            // Co se vyplatí oprávněným.
            $claimsTotal = self::addExactly(self::sumExactly($first), $priorityUsed);
            if ($fee === 0) {
                break;
            }
            // Náhrada náleží za měsíc, „v němž provádí srážky“ (§ 270 odst. 2),
            // a „nesmí přesáhnout třetinu částky sražené ze mzdy povinného
            // zaokrouhlenou na celé koruny nahoru“; sraženou částkou je celá
            // srážka včetně náhrady.
            $cap = self::ceilOneThirdToWholeCrown(self::addExactly($claimsTotal, $fee));
            if ($fee > $cap) {
                $fee = $cap;
                continue;
            }
            if ($claimsTotal > 0) {
                break;
            }
            // Náhrada by spolkla celou srážku a oprávněnému by nedošlo nic:
            // srážet jen na náhradu se nesmí (exekuce by tak nikdy neskončila),
            // proto o korunu méně a znovu.
            $fee = max(0, $fee - 100);
        }
        $balances = $rankedBalances;
        $firstNet = $first;
        // Sražená částka: co připadá pohledávkám, a náhrada plátce mzdy.
        $withheld = self::addExactly($claimsTotal, $fee);

        return [
            'first' => $firstNet,
            'second' => $second,
            'fee' => $fee,
            'withheld' => $withheld,
            'voluntary_reserved' => $voluntaryReserved,
        ];
    }

    /**
     * Dohody o srážkách ze mzdy, které smějí soutěžit o pořadí s exekucemi.
     *
     * Filtruje se dvakrát fail-closed:
     *
     *  • dohoda BEZ dne doručení plátci mzdy se nepřemosťuje vůbec. § 280
     *    odst. 5 o. s. ř. odvozuje pořadí právě z něj a bez data by ho dohoda
     *    neměla čím doložit; {@see EnforcementPriorityResolver} by ji sice
     *    zařadil až za všechny, ale nechat ji úplně stranou je totéž a je to
     *    čitelnější — chování legacy dohod tak zůstává beze změny;
     *  • dohoda, jejíž identifikátor UŽ V REJSTŘÍKU JE, se zahodí. Táž dohoda
     *    zapsaná i jako pohledávka rejstříku (případ `voluntary_agreement`) se
     *    sráží a vyplácí exekučním jádrem; kdyby se k ní ještě přemostila,
     *    ubrala by kapacitu sama sobě a zaměstnanci by se srazila dvakrát.
     *
     * @return list<DeductionClaim>
     */
    private function bridgedAgreements(GarnishmentInput $input): array
    {
        if ($input->voluntaryAgreements === []) {
            return [];
        }
        $registered = [];
        foreach ($input->claims as $claim) {
            $registered[$claim->id] = true;
        }

        return array_values(array_filter(
            $this->activeClaims($input->voluntaryAgreements),
            fn (DeductionClaim $agreement): bool =>
                $agreement->priorityDate !== null
                && $this->isDate($agreement->priorityDate)
                && !isset($registered[$agreement->id]),
        ));
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
            // Příjemce se ověřuje jen tam, kam se platí. Při pouhém zahájení
            // řízení se sražené částky deponují a nikam neodcházejí, takže
            // není čí účet ověřovat — vyžadovat ho by běh zastavilo zbytečně.
            if ($input->insolvency->mode->redirectsPaymentToAdministrator()
                && !$input->insolvency->recipientVerified
            ) {
                $issues[] = 'insolvency_recipient_not_verified';
            }
            if ($input->insolvency->mode->redirectsPaymentToAdministrator()
                && !$input->insolvency->hasImmutablePaymentInstruction()
            ) {
                $issues[] = 'insolvency_payment_instruction_missing';
            }
            if ($input->insolvency->mode === InsolvencyMode::CourtDeterminedAmount
                && $input->insolvency->courtDeterminedAmountMinorUnits === null
            ) {
                $issues[] = 'court_determined_insolvency_amount_missing';
            }
            foreach ($this->concurrentEnforcementIssues($activeClaims, $input) as $issue) {
                $issues[] = $issue;
            }
        } elseif ($input->insolvency->courtDeterminedAmountMinorUnits !== null) {
            $issues[] = 'court_determined_amount_without_insolvency';
        }

        sort($issues, SORT_STRING);

        return array_values(array_unique($issues));
    }

    /**
     * Souběh evidované exekuce a insolvence — co se ještě smí vykonat.
     *
     * ── Proč evidovaná exekuce NESMÍ zastavit mzdu (nález N-09) ─────────────
     *
     * Do 9/2026 shodila jakákoli aktivní pohledávka v rejstříku každý měsíc
     * osoby v oddlužení na ruční posouzení a nesrazilo se nic. Prakticky každý
     * dlužník v oddlužení má přitom v evidenci starší exekuce, takže se
     * takovému člověku mzda nespočítala vůbec — a to i tehdy, když je právní
     * režim naprosto jednoznačný.
     *
     * Jednoznačný je proto, že exekuční srážky se insolvencí ZASTAVUJÍ:
     *
     *  • § 109 odst. 1 písm. c) zákona č. 182/2006 Sb. (insolvenční zákon):
     *    „výkon rozhodnutí či exekuci, která by postihovala majetek ve
     *    vlastnictví dlužníka … lze nařídit nebo zahájit, nelze jej však
     *    provést"; k úkonům a rozhodnutím, které tomu odporují, se podle
     *    § 109 odst. 6 IZ nepřihlíží. Účinky nastávají podle § 109 odst. 4 IZ
     *    okamžikem zveřejnění vyhlášky o zahájení řízení v insolvenčním
     *    rejstříku. „Provedením" je přitom až VÝPLATA oprávněnému, ne samotná
     *    srážka — rozsudek Nejvyššího soudu sp. zn. 29 Cdo 5295/2016
     *    (R 4/2020): „provádí plátce mzdy srážky ze mzdy povinného dále, ale
     *    nevyplácí je oprávněnému, dokud tyto účinky nepominou";
     *  • § 140e odst. 1 IZ: po rozhodnutí o úpadku nelze exekuci na majetek
     *    v majetkové podstatě ani nařídit, ani zahájit;
     *  • § 406 odst. 3 písm. d) a odst. 5 IZ: rozhodnutím o schválení
     *    oddlužení soud přikáže plátci mzdy provádět stanovené srážky
     *    a nevyplácet je dlužníku, doručí mu je do vlastních rukou a sražené
     *    částky plátce mzdy zasílá insolvenčnímu správci — i před právní mocí.
     *
     * Od schválení oddlužení tedy exekuce nedostane nic a nemá se ani co
     * deponovat: celá zabavitelná část patří insolvenčnímu správci. Právě
     * proto se tahle úleva váže na režimy, ve kterých už výrok soudu existuje
     * ({@see InsolvencyMode::redirectsPaymentToAdministrator()}). Při pouhém
     * zahájení řízení se podle R 4/2020 sráží v rozsahu dosavadních exekucí
     * a celá částka se DEPONUJE ({@see InsolvencyMode::depositsEnforcementDeductions()});
     * souběh tam není důvod k ručnímu posouzení, protože se nikomu nic
     * nevyplácí a depozitum uvolní až rozhodnutí.
     *
     * Starší exekuce se tím neruší, jen se nevykonává — v rejstříku zůstává
     * evidovaná (v agendě případů stav
     * {@see EnforcementCaseStatus::DeferredNoWithholding}) a pořadí podle
     * § 280 odst. 5 o. s. ř. jí zůstává. Exekuční příkaz doručený plátci mzdy
     * až později se rovněž jen eviduje. Kolik se srazí, řeší
     * {@see insolvencyWithholding()}.
     *
     * Zdroje: https://www.zakonyprolidi.cz/cs/2006-182#p109,
     * https://www.zakonyprolidi.cz/cs/2006-182#p140e,
     * https://www.zakonyprolidi.cz/cs/2006-182#p406,
     * https://sbirka.nsoud.cz/sbirka/5858/ (R 4/2020).
     *
     * ── Kde fail-closed ZŮSTÁVÁ ────────────────────────────────────────────
     *
     * Zákaz provedení exekuce má v témže § 109 odst. 1 písm. c) IZ dvě výjimky
     * a obě míří přesně na přednostní pohledávky:
     *
     *  • věta druhá: pro pohledávky za majetkovou podstatou (§ 168 IZ)
     *    a pohledávky jim postavené na roveň (§ 169 IZ) exekuci provést LZE,
     *    ovšem jen „na základě rozhodnutí insolvenčního soudu vydaného podle
     *    § 203 odst. 5 a s omezeními tímto rozhodnutím založenými";
     *  • věta třetí: pro pohledávky věřitelů na výživném ZE ZÁKONA vzniklé po
     *    zahájení insolvenčního řízení lze exekuci srážkami ze mzdy provádět
     *    až do prohlášení konkursu nebo schválení oddlužení, ledaže soud
     *    rozhodne jinak.
     *
     * Do které z těch škatulek konkrétní přednostní pohledávka patří, závisí
     * na tom, KDY vznikla, zda jde o výživné ze zákona nebo smluvní a zda
     * o ní insolvenční soud rozhodl — a nic z toho plátce mzdy z evidence
     * nevyčte: rejstřík zná kategorii podle § 279 odst. 2 o. s. ř. a den
     * doručení exekučního příkazu, ne původ pohledávky. Přednostní pohledávka
     * vedle insolvence proto zůstává na ručním posouzení. Nepřednostní
     * pohledávka do žádné z výjimek spadat nemůže (běžný dluh se uspokojuje
     * v insolvenci), takže se jen eviduje.
     *
     * Zdroje: https://www.zakonyprolidi.cz/cs/2006-182#p168,
     * https://www.zakonyprolidi.cz/cs/2006-182#p169,
     * https://www.zakonyprolidi.cz/cs/2006-182#p203.
     *
     * @param list<DeductionClaim> $activeClaims
     * @return list<string>
     */
    private function concurrentEnforcementIssues(
        array $activeClaims,
        GarnishmentInput $input,
    ): array {
        if (!$input->insolvency->mode->redirectsPaymentToAdministrator()) {
            return [];
        }
        foreach ($activeClaims as $claim) {
            if ($claim->legalBasis === DeductionLegalBasis::Statutory
                && $claim->category->isPriority()
            ) {
                return [
                    'concurrent_priority_enforcement_with_insolvency'
                    . '_requires_manual_review',
                ];
            }
        }

        return [];
    }

    /**
     * @param list<DeductionClaim> $claims
     * @return list<DeductionClaim>
     */
    private function activeClaims(array $claims): array
    {
        return $this->priorities->orderedActiveClaims($claims);
    }

    /**
     * Chybějící nebo vnitroročně účinná sada nezabavitelných částek u člověka,
     * ze kterého se stejně nic nesráží.
     *
     * ── Proč to nesmí blokovat běh ──────────────────────────────────────────
     *
     * § 4 nař. vlády č. 595/2006 Sb. žádá hodnoty platné k 1. lednu roku, do
     * něhož připadá DEN VÝPLATY. Prosincová mzda se ale podle § 141 odst. 1
     * zákoníku práce běžně vyplácí až v lednu, takže KAŽDÝ prosincový běh sahá
     * po sadě příštího roku — a ta v době zpracování legitimně existovat
     * nemusí (vláda nařízení vydává na přelomu roku). Do téhle opravy skončil
     * takový běh nepřebitelným blokátorem `enforcement_manual_review`
     * u každé osoby, i ve firmě, kde není jediná exekuce. Prosinec tím nešlo
     * zaúčtovat vůbec.
     *
     * Nezabavitelná částka je vstupem JEDINÉHO výpočtu — kolik se smí srazit.
     * Kde se nesráží nic (žádná aktivní pohledávka, žádné oddlužení a žádná
     * dohoda o srážkách ze mzdy), nemá co ovlivnit a její neznalost nemá co
     * zastavit. Je to totéž zúžení, jaké už drží {@see evidenceScope()}
     * u měsíčních evidencí.
     *
     * Fail-closed zůstává tam, kde na částce záleží: s pohledávkou,
     * v oddlužení i s dohodou o srážkách (jejíž strop se podle § 148 odst. 2
     * zákoníku práce odvozuje z TÉŽE nezabavitelné částky) se dál vrací ruční
     * posouzení. A vrací se i tehdy, když je ve výsledku jakákoli JINÁ výtka
     * než nedostupnost sady — ta se tímhle nepřebíjí.
     *
     * @param list<string> $issues
     */
    private function nothingWithheldResult(
        GarnishmentInput $input,
        array $issues,
        string $rulesetId,
        string $rulesetHash,
        EnforcementEvidenceScope $scope,
    ): ?GarnishmentResult {
        $remaining = array_values(array_diff($issues, self::RULESET_AVAILABILITY_ISSUES));
        if ($remaining !== []) {
            return null;
        }
        if ($this->activeClaims($input->claims) !== []
            || $input->insolvency->mode !== InsolvencyMode::None
            || $input->voluntaryAgreements !== []
        ) {
            return null;
        }
        $income = $input->income->garnishableMinorUnits;

        return new GarnishmentResult(
            $input->period,
            GarnishmentStatus::Supported,
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
            [],
            [[
                'step' => 'nothing_withheld_without_ruleset',
                'payment_date' => $input->paymentDate,
                'january_first_of_payment_year' =>
                    self::januaryFirstOfPaymentYear($input->paymentDate),
                'ruleset_id' => $rulesetId,
            ]],
            $rulesetId,
            $rulesetHash,
            $scope,
        );
    }

    /**
     * Výtky, které mluví JEN o dostupnosti a účinnosti sady nezabavitelných
     * částek — tedy o vstupu, který je bez srážky bez vlivu.
     */
    private const RULESET_AVAILABILITY_ISSUES = [
        'payment_date_outside_ruleset_2026',
        'enforcement_ruleset_incomplete',
        'enforcement_ruleset_not_effective_for_whole_year',
    ];

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
