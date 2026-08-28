<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

use JsonSerializable;
use MyInvoice\Service\Payroll\Calculation\CalculationStep;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;

/**
 * Výsledek jednoho druhu příplatku za měsíc, i s nezaokrouhleným zlomkem.
 *
 * ── Proč se nepoužívá {@see CalculationStep} na samotnou částku ──────────────
 *
 * `CalculationStep` umí `vstup × čitatel / jmenovatel`, ale jmenovatel bere
 * z {@see DecimalRate}, tedy mocninu deseti. Příplatek potřebuje jmenovatel
 * `jmenovatel_sazby × 60`, protože se počítá z HODINOVÉHO základu za MINUTY.
 * Kdyby se to poskládalo ze dvou kroků, zaokrouhlovalo by se dvakrát a chyba by
 * se přes desítky hodin kumulovala — u dvousměnného provozu klidně o koruny
 * měsíčně, systematicky v neprospěch zaměstnance.
 *
 * Řádek proto počítá JEDNÍM zlomkem a nese ho celý, stejně jako to dělá
 * {@see \MyInvoice\Service\Payroll\Absence\SicknessCompensationCalculator}.
 * `CalculationStep` se používá tam, kde je přesný: na odvození HODINOVÉ sazby
 * příplatku, které se čte na výplatní pásce.
 */
final readonly class PayrollSurchargeLine implements JsonSerializable
{
    /**
     * @param list<array{local_date:string,minutes:int,factors:int,weighted_minutes:int}> $segments
     * @param list<array<string,mixed>> $waivedSegments doba, za kterou se příplatek nepočítá
     */
    private function __construct(
        public PayrollSurchargeKind $kind,
        public PayrollSurchargeBasis $basis,
        public int $basisHourlyMinor,
        public DecimalRate $rate,
        public bool $rateIsAgreed,
        public int $minutes,
        public int $weightedMinutes,
        public int $unroundedNumerator,
        public int $unroundedDenominator,
        public RoundingMode $roundingMode,
        public int $amountMinor,
        public CalculationStep $hourlySurchargeStep,
        public array $segments,
        public array $waivedSegments,
    ) {}

    /**
     * @param list<PayrollSurchargeSegment> $segments
     * @param list<array<string,mixed>> $waivedSegments
     */
    public static function calculate(
        PayrollSurchargeKind $kind,
        PayrollSurchargeBasis $basis,
        int $basisHourlyMinor,
        DecimalRate $rate,
        bool $rateIsAgreed,
        array $segments,
        array $waivedSegments = [],
    ): self {
        if ($basisHourlyMinor <= 0) {
            throw PayrollSurchargeException::of(
                'basis_missing',
                sprintf(
                    'Příplatek %s nelze spočítat: %s musí být kladný.',
                    $kind->section(),
                    $basis->label(),
                ),
            );
        }

        $minutes = 0;
        $weighted = 0;
        $trace = [];
        foreach ($segments as $segment) {
            if ($segment->kind !== $kind) {
                throw PayrollSurchargeException::of(
                    'segment_kind_mismatch',
                    'Řádek příplatku dostal dobu jiného druhu.',
                );
            }
            $minutes += $segment->minutes;
            $weighted += $segment->weightedMinutes();
            $trace[] = [
                'local_date' => $segment->localDate,
                'minutes' => $segment->minutes,
                'factors' => $segment->factors,
                'weighted_minutes' => $segment->weightedMinutes(),
            ];
        }

        // Hodinová sazba příplatku je exaktní krok: základ × sazba, jmenovatel je
        // mocnina deseti, žádné minuty se do něj nepletou.
        $hourlyStep = CalculationStep::calculate(
            "surcharge.{$kind->value}.hourly",
            $basisHourlyMinor,
            $rate,
            RoundingMode::HalfUp,
        );

        $numerator = self::multiplyExactly(
            self::multiplyExactly($basisHourlyMinor, $rate->numerator),
            $weighted,
        );
        $denominator = self::multiplyExactly($rate->denominator, 60);
        $amount = RoundingMode::HalfUp->roundFraction($numerator, $denominator);

        return new self(
            $kind,
            $basis,
            $basisHourlyMinor,
            $rate,
            $rateIsAgreed,
            $minutes,
            $weighted,
            $numerator,
            $denominator,
            RoundingMode::HalfUp,
            $amount,
            $hourlyStep,
            $trace,
            $waivedSegments,
        );
    }

    private static function multiplyExactly(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw PayrollSurchargeException::of(
                'negative_factor',
                'Výpočet příplatku nepracuje se zápornými činiteli.',
            );
        }
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw PayrollSurchargeException::of(
                'overflow',
                'Výpočet příplatku překročil celočíselný rozsah.',
            );
        }

        return $left * $right;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->kind->value,
            'section' => $this->kind->section(),
            'component_code' => $this->kind->componentCode(),
            'basis' => $this->basis->value,
            'basis_hourly_minor' => $this->basisHourlyMinor,
            'rate' => $this->rate->jsonSerialize(),
            'rate_is_agreed' => $this->rateIsAgreed,
            'minutes' => $this->minutes,
            'weighted_minutes' => $this->weightedMinutes,
            'unrounded_numerator' => $this->unroundedNumerator,
            'unrounded_denominator' => $this->unroundedDenominator,
            'rounding_mode' => $this->roundingMode->value,
            'amount_minor' => $this->amountMinor,
            'hourly_surcharge_minor' => $this->hourlySurchargeStep->outputMinorUnits,
            'hourly_surcharge_step' => $this->hourlySurchargeStep->jsonSerialize(),
            'segments' => $this->segments,
            'waived_segments' => $this->waivedSegments,
        ];
    }
}
