<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use JsonSerializable;

final readonly class RelationshipTaxResult implements JsonSerializable
{
    public function __construct(
        public string $relationshipReference,
        public EmploymentRelationshipKind $kind,
        public int $taxableBaseMinorUnits,
        public TaxRegime $regime,
        public ?string $withholdingGroup,
        /**
         * Příjem vztahu podle § 6 odst. 4 ZDP bez osvobozených (JMHZ 10555),
         * jen od roku 2027 ({@see SeparateTaxBaseRegime::SeparateAdvance}):
         * dílčí základ, když vztah spadl do samostatného základu, jinak 0.
         * `null` = výsledek do roku 2026, který klíč nenese (bajtová stabilita).
         */
        public ?int $separateBaseIncomeMinorUnits = null,
    ) {}

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'relationship_reference' => $this->relationshipReference,
            'kind' => $this->kind->value,
            'taxable_base_minor_units' => $this->taxableBaseMinorUnits,
            'regime' => $this->regime->value,
            'withholding_group' => $this->withholdingGroup,
        ] + ($this->separateBaseIncomeMinorUnits === null
            ? []
            : ['separate_base_income_minor_units' => $this->separateBaseIncomeMinorUnits]);
    }
}
