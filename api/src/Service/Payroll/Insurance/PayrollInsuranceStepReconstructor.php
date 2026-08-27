<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Insurance;

use MyInvoice\Service\Payroll\Calculation\CalculationStep;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\PayrollRounding;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetRegistry;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;

/**
 * MZ-11 — rekonstrukce mezikroku zdravotního pojistného u revizí, které ho
 * neuložily.
 *
 * Revize spočtené dřív, než se mezikroky začaly ukládat, nesou částku, ale ne
 * sazbu. Vracet u nich napořád `not_recorded` je bezpečné, ale je to slepá
 * ulička: takový rozklad nevznikne nikdy. Dopočet z DNEŠNÍ sady pravidel je
 * ovšem horší než mlčení — popisoval by jiný výpočet než ten, který částku dal.
 *
 * Cesta ven je rekonstrukce ze sady pravidel ZMRAZENÉ V TÉ REVIZI, přijatá jen
 * proti důkazu. Rekonstrukce projde, teprve když platí OBOJÍ:
 *
 *  1. **Shoda otisku sady.** Výsledek nese `ruleset_id` a `ruleset_hash`
 *     ({@see PayrollRulesetVersion::$canonicalHash}). Sazba se bere jen z verze,
 *     jejíž otisk se s uloženým shoduje bajt na bajt. Otisk pokrývá parametry
 *     i účinnost, takže shoda znamená „je to tatáž sada, ne jen tentýž název".
 *  2. **Shoda částky.** Zrekonstruovaný krok se po zaokrouhlení musí rovnat
 *     ULOŽENÉ částce na haléř. Tím se ověřuje i to, co otisk pokrýt nemůže:
 *     že dnešní postup výpočtu je tentýž jako tehdejší.
 *
 * Nesedí-li cokoli, vrací se `null` a rozklad zůstane `not_recorded`. Tím to
 * přestává být odhad a stává se to důkazem: rekonstruovaná sazba je jediná,
 * která z doložené sady pravidel vede přesně na uloženou částku.
 *
 * POČÍTÁ SE ZA BĚHU, NEUKLÁDÁ SE. Uložený zákonný výsledek je neměnný (append-only
 * tabulky s triggery) a psát rekonstrukci vedle něj by založilo druhý zdroj
 * pravdy, který zestárne: kdyby se sada pravidel v administraci přepsala, uložený
 * důkaz by dál tvrdil shodu otisku, která už neplatí. Přepočet je O(1) na osobu
 * a odpověď se tak vždy dokládá tím, co je k dispozici TEĎ.
 */
final class PayrollInsuranceStepReconstructor
{
    public const HEALTH_STANDARD_LABEL = 'monthly-health-insurance-standard';
    public const HEALTH_TOP_UP_LABEL = 'monthly-health-insurance-minimum-top-up';
    public const HEALTH_RATE_PARAMETER = 'total.rate';

    public function __construct(private readonly PayrollRulesetRegistry $rulesets) {}

    /**
     * Zrekonstruovaný krok zdravotního pojistného, nebo `null`, když ho nejde
     * doložit.
     *
     * @return array{step:array<string,mixed>,version:PayrollRulesetVersion}|null
     */
    public function healthStep(
        string $label,
        string $rulesetId,
        string $rulesetHash,
        int $inputMinorUnits,
        int $storedAmountMinorUnits,
    ): ?array {
        if ($inputMinorUnits <= 0 || $storedAmountMinorUnits <= 0) {
            return null;
        }
        $version = $this->frozenVersion($rulesetId, $rulesetHash);
        if ($version === null) {
            return null;
        }
        try {
            $parameter = $version->parameter(self::HEALTH_RATE_PARAMETER);
            if ($parameter->type !== 'decimal_rate' || !is_string($parameter->value)) {
                return null;
            }
            $step = CalculationStep::calculate(
                $label,
                $inputMinorUnits,
                DecimalRate::fromString($parameter->value),
                RoundingMode::Ceil,
            );
            if (PayrollRounding::ceilToCzk($step->outputMinorUnits) !== $storedAmountMinorUnits) {
                return null;
            }

            return ['step' => $step->jsonSerialize(), 'version' => $version];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{step:array<string,mixed>,minimum_step:?array<string,mixed>,version:PayrollRulesetVersion,rounding_method:string}|null
     */
    public function healthMinimumTopUpStep(
        string $rulesetId,
        string $rulesetHash,
        int $assessmentBaseMinorUnits,
        int $minimumBaseMinorUnits,
        int $storedStandardMinorUnits,
        int $storedTopUpMinorUnits,
    ): ?array {
        if ($assessmentBaseMinorUnits < 0
            || $minimumBaseMinorUnits <= $assessmentBaseMinorUnits
            || $storedStandardMinorUnits < 0
            || $storedTopUpMinorUnits <= 0
        ) {
            return null;
        }
        $version = $this->frozenVersion($rulesetId, $rulesetHash);
        if ($version === null) {
            return null;
        }
        try {
            $parameter = $version->parameter(self::HEALTH_RATE_PARAMETER);
            if ($parameter->type !== 'decimal_rate' || !is_string($parameter->value)) {
                return null;
            }
            $rate = DecimalRate::fromString($parameter->value);
            $standardStep = CalculationStep::calculate(
                self::HEALTH_STANDARD_LABEL,
                $assessmentBaseMinorUnits,
                $rate,
                RoundingMode::Ceil,
            );
            $topUpStep = CalculationStep::calculate(
                self::HEALTH_TOP_UP_LABEL,
                $minimumBaseMinorUnits - $assessmentBaseMinorUnits,
                $rate,
                RoundingMode::Ceil,
            );
            $minimumStep = CalculationStep::calculate(
                'monthly-health-insurance-minimum-total',
                $minimumBaseMinorUnits,
                $rate,
                RoundingMode::Ceil,
            );
            $standard = PayrollRounding::ceilToCzk($standardStep->outputMinorUnits);
            $topUp = PayrollRounding::healthMinimumTopUp(
                $standard,
                PayrollRounding::ceilToCzk($minimumStep->outputMinorUnits),
            );
            $roundingMethod = 'rounded_total_difference';
            if ($standard !== $storedStandardMinorUnits || $topUp !== $storedTopUpMinorUnits) {
                $legacyTopUp = PayrollRounding::ceilToCzk($topUpStep->outputMinorUnits);
                if ($standard !== $storedStandardMinorUnits || $legacyTopUp !== $storedTopUpMinorUnits) {
                    return null;
                }
                $roundingMethod = 'legacy_separate_top_up';
            }

            return [
                'step' => $topUpStep->jsonSerialize(),
                'minimum_step' => $roundingMethod === 'rounded_total_difference'
                    ? $minimumStep->jsonSerialize()
                    : null,
                'version' => $version,
                'rounding_method' => $roundingMethod,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Verze sady pravidel, jejíž otisk se shoduje s uloženým. Hledá se mezi
     * efektivní (default ⊕ override) a dodanou verzí téhož ID; jiná cesta, jak
     * se k historické verzi dostat, není a domýšlet ji nesmíme.
     */
    private function frozenVersion(string $rulesetId, string $rulesetHash): ?PayrollRulesetVersion
    {
        if ($rulesetId === '' || preg_match('/^[0-9a-f]{64}$/', $rulesetHash) !== 1) {
            return null;
        }
        $candidates = [];
        try {
            $entry = $this->rulesets->entry($rulesetId);
            if ($entry !== null && $entry['version'] instanceof PayrollRulesetVersion) {
                $candidates[] = $entry['version'];
            }
            $default = $this->rulesets->defaultVersion($rulesetId);
            if ($default !== null) {
                $candidates[] = $default;
            }
        } catch (\Throwable) {
            return null;
        }
        foreach ($candidates as $candidate) {
            if (hash_equals($rulesetHash, $candidate->canonicalHash)) {
                return $candidate;
            }
        }

        return null;
    }
}
