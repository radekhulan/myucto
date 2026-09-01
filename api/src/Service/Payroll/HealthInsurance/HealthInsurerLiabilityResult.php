<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

use JsonSerializable;

final readonly class HealthInsurerLiabilityResult implements JsonSerializable
{
    public function __construct(
        public string $insurerCode,
        public int $personCount,
        public int $assessmentBaseMinorUnits,
        public int $employeeContributionMinorUnits,
        public int $employerContributionMinorUnits,
        public int $totalContributionMinorUnits,
        /**
         * Vyměřovací základ pro Přehled o platbě pojistného zaměstnavatele.
         *
         * Liší se od `assessmentBaseMinorUnits` právě u lidí, kterým se dopočítává
         * minimální vyměřovací základ: pojistné se odvádí z minima, takže i PPZ musí
         * vykázat minimum. Kdyby se hlásil skutečný příjem, nesouhlasilo by pojistné
         * se 13,5 % z vykázaného základu a pojišťovna podání rozporuje.
         */
        public int $ppzAssessmentBaseMinorUnits = 0,
    ) {}

    /** @return array<string,int|string> */
    public function jsonSerialize(): array
    {
        return [
            'insurer_code' => $this->insurerCode,
            'person_count' => $this->personCount,
            'assessment_base_minor_units' => $this->assessmentBaseMinorUnits,
            'employee_contribution_minor_units' => $this->employeeContributionMinorUnits,
            'employer_contribution_minor_units' => $this->employerContributionMinorUnits,
            'total_contribution_minor_units' => $this->totalContributionMinorUnits,
            'ppz_assessment_base_minor_units' => $this->ppzAssessmentBaseMinorUnits,
        ];
    }
}
