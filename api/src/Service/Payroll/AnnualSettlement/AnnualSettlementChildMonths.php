<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

/**
 * Jedno dítě a měsíce, v nichž na ně náleželo daňové zvýhodnění (§ 35c odst. 10).
 *
 * `order` je pořadí pro určení výše (§ 35c odst. 1) — v rámci roku se nesmí
 * měnit, jinak by roční částka závisela na tom, který měsíc se náhodou vezme.
 * Pokud se pořadí v evidenci během roku změní, uplatní se to jako odmítnutí,
 * ne jako průměr: viz `AnnualTaxSettlementCalculator`.
 *
 * `ztpPMonths` je podmnožina `months` — dítě může průkaz ZTP/P získat uprostřed
 * roku a § 35c odst. 7 zvyšuje částku jen za tu dobu.
 */
final readonly class AnnualSettlementChildMonths
{
    public function __construct(
        public string $childReference,
        public int $order,
        public int $months,
        public int $ztpPMonths,
        public array $claimedMonths = [],
        public array $ztpPClaimedMonths = [],
    ) {
        if (trim($childReference) === '' || mb_strlen($childReference) > 128) {
            throw new \InvalidArgumentException('Odkaz na dítě není platný.');
        }
        if ($order < 1) {
            throw new \InvalidArgumentException('Pořadí dítěte musí být kladné.');
        }
        if ($months < 1 || $months > AnnualTaxRates::MONTHS_IN_YEAR) {
            throw new \InvalidArgumentException(
                'Počet měsíců vyživování musí být 1 až 12.',
            );
        }
        if ($ztpPMonths < 0 || $ztpPMonths > $months) {
            throw new \InvalidArgumentException(
                'Měsíce s průkazem ZTP/P nesmí přesáhnout měsíce vyživování.',
            );
        }
        $this->validateMonthVector($claimedMonths, $months, 'Měsíce vyživování');
        $this->validateMonthVector(
            $ztpPClaimedMonths,
            $ztpPMonths,
            'Měsíce s průkazem ZTP/P',
        );
        if ($claimedMonths !== []
            && array_diff($ztpPClaimedMonths, $claimedMonths) !== []
        ) {
            throw new \InvalidArgumentException(
                'Měsíce s průkazem ZTP/P musí být podmnožinou měsíců vyživování.',
            );
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'child_reference' => $this->childReference,
            'order' => $this->order,
            'months' => $this->months,
            'ztp_p_months' => $this->ztpPMonths,
            'claimed_months' => $this->claimedMonths,
            'ztp_p_claimed_months' => $this->ztpPClaimedMonths,
        ];
    }

    /** @param list<int> $months */
    private function validateMonthVector(
        array $months,
        int $expectedCount,
        string $label,
    ): void {
        if ($months === []) {
            return;
        }
        if (!array_is_list($months)
            || count($months) !== $expectedCount
            || $months !== array_values(array_unique($months))
        ) {
            throw new \InvalidArgumentException("{$label} nejsou platný měsíční vektor.");
        }
        foreach ($months as $month) {
            if (!is_int($month) || $month < 1 || $month > AnnualTaxRates::MONTHS_IN_YEAR) {
                throw new \InvalidArgumentException("{$label} nejsou platný měsíční vektor.");
            }
        }
    }
}
