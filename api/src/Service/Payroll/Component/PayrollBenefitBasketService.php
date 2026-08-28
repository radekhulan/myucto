<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Service\Payroll\Calculation\CalculationStep;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Koše osvobození plnění zaměstnanci — jediná čtecí cesta k limitu a k rozpadu.
 *
 * Limit se NEKOPÍRUJE do mzdové složky ani do vstupu: složka nese jen zařazení do
 * koše a částku drží ruleset. Kdyby se kopírovala, změna průměrné mzdy by musela
 * projít číselníkem každé firmy a starý strop by tiše přežil.
 *
 * Ruleset se bere `forCalculation()`, tedy fail-closed: rozpad má daňový dopad,
 * takže ho neschválená sada nesmí tvrdit.
 */
final class PayrollBenefitBasketService
{
    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    /**
     * Zákonný limit koše pro rozhodné období mzdového vstupu.
     *
     * ROZHODNÝ DEN ČTENÍ RULESETU se liší podle toho, za jaké období zákon limit
     * dává ({@see PayrollBenefitExemptionBasket::accumulatesPerMonth()}):
     *
     *  - roční koš (§ 6 odst. 9 písm. d) a m)) se čte ke KONCI kalendářního roku.
     *    Limit je „za zdaňovací období" a tím je podle § 16b ZDP kalendářní rok;
     *    odečítat ho k měsíci vstupu by znamenalo, že se koš uprostřed roku sám
     *    přeloží.
     *  - měsíční koš (písm. b) a i)) se čte k MĚSÍCI vstupu — jiný měsíc může
     *    spadat pod jiný ruleset a zpětně by se nic přepočítávat nemělo.
     *
     * U příspěvku na stravování ruleset drží limit NA JEDNU SMĚNU a měsíční strop
     * je jeho násobek počtem doložených nároků. Nula nároků tedy dává nulový
     * strop — to není chyba rulesetu, ale výsledek: bez odpracované směny se
     * podle písm. b) neosvobodí nic.
     */
    public function limitMinor(
        PayrollBenefitExemptionBasket $basket,
        string $periodStart,
        int $shiftEntitlements = 0,
    ): int {
        $effectiveOn = $this->effectiveOn($basket, $periodStart);
        $incomeTax = $this->rulesets
            ->forCalculation(PayrollRulesetDomain::IncomeTax, $effectiveOn);
        $value = $incomeTax->parameter($basket->rulesetKey())->value;
        if (!is_int($value) || $value <= 0) {
            throw new PayrollRulesetException(
                "Limit koše {$basket->rulesetKey()} není částka v haléřích.",
            );
        }
        if (!$basket->scalesWithShifts()) {
            return $value;
        }
        $rateValue = $incomeTax
            ->parameter('benefit_exemption.meal.shift_rate')
            ->value;
        $travelMaximum = $this->rulesets
            ->forCalculation(PayrollRulesetDomain::TravelAllowances, $effectiveOn)
            ->parameter('meal_allowance.band_1.tax_exempt_maximum')
            ->value;
        if (!is_string($rateValue) || !is_int($travelMaximum) || $travelMaximum <= 0) {
            throw new PayrollRulesetException(
                'Parametry benefit_exemption.meal.shift_rate a '
                . 'meal_allowance.band_1.tax_exempt_maximum nemají očekávaný typ.',
            );
        }
        $derived = CalculationStep::calculate(
            'benefit_exemption.meal.per_shift',
            $travelMaximum,
            DecimalRate::fromString($rateValue),
            RoundingMode::HalfUp,
        )->outputMinorUnits;
        if ($value !== $derived) {
            throw new PayrollRulesetException(
                'Parametr benefit_exemption.meal.per_shift (' . $value . ') musí odpovídat '
                . 'benefit_exemption.meal.shift_rate (' . $rateValue . ') × '
                . 'meal_allowance.band_1.tax_exempt_maximum (' . $travelMaximum . ') = '
                . $derived . '.',
            );
        }
        if ($shiftEntitlements < 0) {
            throw new PayrollRulesetException(
                'Počet nároků na příspěvek na stravování nesmí být záporný.',
            );
        }

        return $value * $shiftEntitlements;
    }

    private function effectiveOn(
        PayrollBenefitExemptionBasket $basket,
        string $periodStart,
    ): string {
        if ($basket->accumulatesPerMonth()) {
            return $periodStart;
        }

        return substr($periodStart, 0, 4) . '-12-31';
    }

    /**
     * Rozpad plnění proti dosud vyčerpanému úhrnu koše.
     *
     * Nerovnost je NEOSTRÁ — zákon říká „osvobozena v úhrnu DO VÝŠE …", takže
     * úhrn rovný limitu je celý osvobozený a zdaňuje se teprve to, co limit
     * převyšuje. Osvobození se navíc neztrácí zpětně: dřív poskytnutá část
     * zůstává osvobozená a zdanitelný je jen přebytek.
     *
     * Záporná částka (oprava) koš nečerpá a rozpad se u ní nedělá — vrací se celá
     * jako osvobozená nula a zdanitelná nula, protože zápornou položku odbaví
     * až storno vstupu.
     */
    public function split(
        PayrollBenefitExemptionBasket $basket,
        string $periodStart,
        int $usedBeforeMinor,
        int $amountMinor,
        int $shiftEntitlements = 0,
        ?int $usedShiftEntitlements = null,
    ): PayrollBenefitBasketSplit {
        $limit = $this->limitMinor($basket, $periodStart, $shiftEntitlements);
        $amount = max(0, $amountMinor);
        if ($basket->scalesWithShifts()) {
            return $this->splitMeal(
                $basket,
                $limit,
                max(0, $usedBeforeMinor),
                $amount,
                $shiftEntitlements,
                $usedShiftEntitlements,
                $periodStart,
            );
        }
        $headroom = max(0, $limit - max(0, $usedBeforeMinor));
        $exempt = min($amount, $headroom);

        return new PayrollBenefitBasketSplit(
            basket: $basket,
            limitMinor: $limit,
            usedBeforeMinor: max(0, $usedBeforeMinor),
            amountMinor: $amount,
            exemptMinor: $exempt,
            taxableMinor: $amount - $exempt,
            shiftEntitlements: $basket->scalesWithShifts() ? $shiftEntitlements : null,
        );
    }

    private function splitMeal(
        PayrollBenefitExemptionBasket $basket,
        int $limitMinor,
        int $usedBeforeMinor,
        int $amountMinor,
        int $shiftEntitlements,
        ?int $usedShiftEntitlements,
        string $periodStart,
    ): PayrollBenefitBasketSplit {
        if ($usedBeforeMinor > 0 && $usedShiftEntitlements !== $shiftEntitlements) {
            throw new PayrollRulesetException(
                'Počet nároků se proti dříve schválenému příspěvku změnil; '
                . 'před dalším schválením je nutné předchozí vstup stornovat a přepočítat.',
            );
        }
        if ($shiftEntitlements === 0) {
            return new PayrollBenefitBasketSplit(
                basket: $basket,
                limitMinor: 0,
                usedBeforeMinor: $usedBeforeMinor,
                amountMinor: $amountMinor,
                exemptMinor: 0,
                taxableMinor: $amountMinor,
                shiftEntitlements: 0,
                allocation: [
                    'mode' => 'no_entitlement',
                    'entitlement_count' => 0,
                    'amount_per_entitlement_minor' => 0,
                    'limit_per_entitlement_minor' => 0,
                    'exempt_per_entitlement_minor' => 0,
                    'taxable_per_entitlement_minor' => 0,
                ],
            );
        }
        if ($amountMinor % $shiftEntitlements !== 0
            || $usedBeforeMinor % $shiftEntitlements !== 0
        ) {
            throw new PayrollRulesetException(
                'Částka příspěvku na stravování musí být mezi doložené nároky rozdělena '
                . 'rovnoměrně; zadaný měsíční úhrn takto rozdělit nelze.',
            );
        }
        $perShiftLimit = $this->limitMinor($basket, $periodStart, 1);
        $amountPerEntitlement = intdiv($amountMinor, $shiftEntitlements);
        $usedPerEntitlement = intdiv($usedBeforeMinor, $shiftEntitlements);
        $exemptPerEntitlement = min(
            $amountPerEntitlement,
            max(0, $perShiftLimit - $usedPerEntitlement),
        );
        $exempt = $exemptPerEntitlement * $shiftEntitlements;

        return new PayrollBenefitBasketSplit(
            basket: $basket,
            limitMinor: $limitMinor,
            usedBeforeMinor: $usedBeforeMinor,
            amountMinor: $amountMinor,
            exemptMinor: $exempt,
            taxableMinor: $amountMinor - $exempt,
            shiftEntitlements: $shiftEntitlements,
            allocation: [
                'mode' => 'uniform_per_entitlement',
                'entitlement_count' => $shiftEntitlements,
                'amount_per_entitlement_minor' => $amountPerEntitlement,
                'limit_per_entitlement_minor' => $perShiftLimit,
                'exempt_per_entitlement_minor' => $exemptPerEntitlement,
                'taxable_per_entitlement_minor' => $amountPerEntitlement - $exemptPerEntitlement,
            ],
        );
    }
}
