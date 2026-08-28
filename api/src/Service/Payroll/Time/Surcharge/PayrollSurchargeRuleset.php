<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

use LogicException;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Ruleset\PayrollRuleValue;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetVersion;

/**
 * Jediná čtecí cesta k zákonným sazbám příplatků § 114 až § 118.
 *
 * Stejný důvod, proč vznikl {@see \MyInvoice\Service\Payroll\Absence\AbsenceRuleset}:
 * dokud čísla žijí jako literály v kalkulátoru, změna v administraci rulesetů se
 * do výsledku nepromítne a mzdový list se rozejde s tím, co je v sadě.
 *
 * Bere se `forDate()`, ne `forCalculation()`. Doména `compensation_averages` má
 * capability `ManualReview` (nárok posuzuje účetní), takže `forCalculation()` by
 * na ní vždy vyhodila výjimku — a příplatky by nešly spočítat vůbec. Jednotlivé
 * parametry `surcharge.*` jsou přitom `Supported`; ruční posouzení se týká
 * nároku, ne sazby.
 */
final class PayrollSurchargeRuleset
{
    private function __construct(public readonly PayrollRulesetVersion $version) {}

    public static function forDate(PayrollRulesetProvider $rulesets, string $date): self
    {
        return new self(
            $rulesets->forDate(PayrollRulesetDomain::CompensationAverages, $date),
        );
    }

    /** Zákonná minimální sazba příplatku jako přesný zlomek. */
    public function statutoryRate(PayrollSurchargeKind $kind): DecimalRate
    {
        $key = $kind->rulesetRateKey();
        $value = $this->raw($key);
        if ($value->type !== 'decimal_rate' || !is_string($value->value)) {
            throw new LogicException("Ruleset neobsahuje desetinnou sazbu {$key}.");
        }
        $rate = DecimalRate::fromString($value->value);
        if ($rate->numerator <= 0) {
            throw new LogicException("Sazba {$key} musí být kladná.");
        }

        return $rate;
    }

    public function basis(PayrollSurchargeKind $kind): PayrollSurchargeBasis
    {
        $key = $kind->rulesetBasisKey();
        $value = $this->raw($key);
        if ($value->type !== 'text' || !is_string($value->value)) {
            throw new LogicException("Ruleset neobsahuje textový základ {$key}.");
        }
        $basis = PayrollSurchargeBasis::tryFrom($value->value);
        if ($basis === null) {
            throw new LogicException("Ruleset uvádí neznámý základ příplatku {$value->value}.");
        }

        return $basis;
    }

    /** Začátek noční doby podle § 78 odst. 1 písm. k) ZP. */
    public function nightWindowStartHour(): int
    {
        return $this->hour('surcharge.night.window_start_hour');
    }

    /** Konec noční doby podle § 78 odst. 1 písm. k) ZP. */
    public function nightWindowEndHour(): int
    {
        return $this->hour('surcharge.night.window_end_hour');
    }

    /**
     * Lhůta pro poskytnutí náhradního volna v kalendářních měsících
     * (§ 114 odst. 2, § 115 odst. 1).
     */
    public function compensatoryTimeOffMonths(PayrollSurchargeKind $kind): int
    {
        if (!$kind->allowsCompensatoryTimeOff()) {
            throw new LogicException(
                "U příplatku {$kind->value} zákon náhradní volno nezná.",
            );
        }
        $key = "surcharge.{$kind->value}.time_off_months";
        $value = $this->raw($key);
        if (!is_int($value->value) || $value->value <= 0) {
            throw new LogicException("Ruleset neobsahuje kladnou lhůtu {$key}.");
        }

        return $value->value;
    }

    private function hour(string $key): int
    {
        $value = $this->raw($key);
        if (!is_int($value->value) || $value->value < 0 || $value->value > 23) {
            throw new LogicException("Ruleset neobsahuje platnou hodinu {$key}.");
        }

        return $value->value;
    }

    private function raw(string $key): PayrollRuleValue
    {
        $value = $this->version->parameters[$key] ?? null;
        if (!$value instanceof PayrollRuleValue) {
            throw new LogicException("Ruleset neobsahuje parametr {$key}.");
        }
        $value->assertCalculationReady($key);

        return $value;
    }
}
