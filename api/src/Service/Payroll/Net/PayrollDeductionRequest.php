<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

final readonly class PayrollDeductionRequest
{
    /**
     * @param ?string $deliveredOn Den, kdy byla dohoda o srážce doručena plátci
     *   mzdy (`RRRR-MM-DD`). Od toho dne vzniká věřiteli podle § 2045 odst. 2
     *   občanského zákoníku právo na výplatu srážek proti plátci mzdy a podle
     *   § 148 odst. 2 zákoníku práce ve spojení s § 280 odst. 5 o. s. ř. se od
     *   něj odvozuje POŘADÍ. `null` je legacy: dohody zaevidované dřív, než se
     *   den doručení začal ukládat. Řadí se fail-closed až za všechny dohody se
     *   známým dnem doručení a pořadí jim určí `priority`.
     */
    public function __construct(
        public string $deductionReference,
        public int $priority,
        public int $requestedMinorUnits,
        public ?int $remainingLimitMinorUnits,
        public bool $active,
        public ?string $deliveredOn = null,
    ) {
        if ($deductionReference === '') {
            throw new \InvalidArgumentException('Srážka musí mít neprázdný identifikátor.');
        }
        if ($priority < 0 || $requestedMinorUnits < 0) {
            throw new \InvalidArgumentException('Pořadí ani požadovaná srážka nesmí být záporné.');
        }
        if ($remainingLimitMinorUnits !== null && $remainingLimitMinorUnits < 0) {
            throw new \InvalidArgumentException('Zbývající limit srážky nesmí být záporný.');
        }
        if ($deliveredOn !== null
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $deliveredOn) !== 1
        ) {
            throw new \InvalidArgumentException(
                'Den doručení dohody plátci mzdy musí být datum ve tvaru RRRR-MM-DD.',
            );
        }
    }
}
