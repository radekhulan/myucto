<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use JsonSerializable;
use MyInvoice\Service\Payroll\Calculation\CalculationStep;

final readonly class WithholdingTaxGroupResult implements JsonSerializable
{
    public function __construct(
        public string $group,
        /**
         * Samostatný základ podle § 6 odst. 4 ZDP po zaokrouhlení: do roku 2026
         * na celé koruny dolů (§ 36 odst. 3 věta třetí, srážková daň), od roku
         * 2027 na celé koruny nahoru (§ 6 odst. 18, záloha). Tohle je číslo,
         * které se vykazuje a ze kterého se daň skutečně počítá.
         */
        public int $baseMinorUnits,
        public int $taxMinorUnits,
        public CalculationStep $rateStep,
        /**
         * Úhrn nezaokrouhlených dílčích základů, ze kterého zaokrouhlení
         * vzešlo. Nese se jen do stopy výpočtu, aby šlo dohledat, kolik
         * zaokrouhlení ukrojilo; do žádné aritmetiky nevstupuje.
         */
        public ?int $unroundedBaseMinorUnits = null,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'group' => $this->group,
            'base_minor_units' => $this->baseMinorUnits,
            'unrounded_base_minor_units' => $this->unroundedBaseMinorUnits ?? $this->baseMinorUnits,
            'tax_minor_units' => $this->taxMinorUnits,
            'rate_step' => $this->rateStep->jsonSerialize(),
        ];
    }
}
