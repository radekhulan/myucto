<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

use JsonSerializable;

final readonly class PayrollDeductionResult implements JsonSerializable
{
    /**
     * `unappliedMinorUnits` je ÚČETNÍ zbytek, ne dluh vůči věřiteli: drží se
     * na něm invariant `unapplied === requested − applied`, který nad zmrazeným
     * snímkem kontroluje {@see \MyInvoice\Service\Payroll\Run\PayrollRunDeductionLedgerApprover}.
     * Proto se u pozastavené dohody rovná celé nárokované částce, přestože se
     * v tom měsíci nemělo srazit nic — pro report je to zavádějící číslo
     * (nález E-17). Kolik se REÁLNĚ nedostalo věřiteli, říká
     * {@see self::shortfallMinorUnits()}; sestavy mají číst tu.
     */
    public function __construct(
        public string $deductionReference,
        public int $priority,
        public int $requestedMinorUnits,
        public int $appliedMinorUnits,
        public int $unappliedMinorUnits,
        public bool $active,
    ) {}

    /**
     * O kolik přišel věřitel proto, že na dohodu nezbyla kapacita. Neaktivní
     * dohoda se v daném měsíci neprovádí, takže z ní žádný schodek nevzniká.
     */
    public function shortfallMinorUnits(): int
    {
        return $this->active ? $this->unappliedMinorUnits : 0;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'deduction_reference' => $this->deductionReference,
            'priority' => $this->priority,
            'requested_minor_units' => $this->requestedMinorUnits,
            'applied_minor_units' => $this->appliedMinorUnits,
            'unapplied_minor_units' => $this->unappliedMinorUnits,
            'active' => $this->active,
        ];
    }
}
