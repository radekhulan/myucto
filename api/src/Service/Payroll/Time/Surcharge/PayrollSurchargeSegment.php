<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

use InvalidArgumentException;

/**
 * Jeden den jednoho druhu příplatku: kolik minut a s kolika ztěžujícími vlivy.
 *
 * Den je nejmenší jednotka, se kterou se dá pracovat, protože náhradní volno za
 * přesčas je v `payroll_overtime_compensations` klíčované DNEM PŘESČASU
 * (migrace 1492) — hrubší granularita by odečet neuměla přiřadit, jemnější by
 * neměla oporu v datech.
 *
 * `factors` je počet ztěžujících vlivů podle § 117 („za každý ztěžující vliv").
 * U ostatních příplatků je vždy 1: násobit noční příplatek čímkoli jiným než
 * jedničkou zákon nedovoluje.
 */
final readonly class PayrollSurchargeSegment
{
    public function __construct(
        public PayrollSurchargeKind $kind,
        public string $localDate,
        public int $minutes,
        public int $factors = 1,
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $localDate) !== 1) {
            throw new InvalidArgumentException('Datum příplatku není ve tvaru RRRR-MM-DD.');
        }
        if ($minutes <= 0 || $minutes > 1_440) {
            throw new InvalidArgumentException('Minuty příplatku musí být 1 až 1440.');
        }
        if ($factors < 1 || $factors > 255) {
            throw new InvalidArgumentException('Počet ztěžujících vlivů musí být 1 až 255.');
        }
        if ($factors !== 1 && $kind !== PayrollSurchargeKind::DifficultEnvironment) {
            throw new InvalidArgumentException(
                'Násobek ztěžujících vlivů má smysl jen u § 117.',
            );
        }
    }

    /** Minuty vážené počtem ztěžujících vlivů — jmenovatel § 117. */
    public function weightedMinutes(): int
    {
        return $this->minutes * $this->factors;
    }

    public function withMinutes(int $minutes): self
    {
        return new self($this->kind, $this->localDate, $minutes, $this->factors);
    }
}
