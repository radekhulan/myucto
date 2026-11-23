<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Service\Payroll\Component\PayrollExemptionBasis;

final readonly class PayslipLine
{
    private const MAX_MINOR_UNITS = 1_000_000_000_000;

    public function __construct(
        public string $label,
        public int $amountMinorUnits,
        /**
         * Podklad nezdanění složky. `null` = složka je zdanitelný příjem.
         *
         * § 142 odst. 6 zákoníku práce žádá „údaje o jednotlivých složkách
         * mzdy": u složky, ze které se nic nesráží, je podklad součástí toho
         * údaje — jinak z pásky nejde přečíst, proč se liší od ostatních.
         */
        public ?PayrollExemptionBasis $exemptionBasis = null,
        /** Nezdaněná část částky; u koše § 6 odst. 9 jen zmrazený podíl. */
        public int $exemptPartMinorUnits = 0,
        public ?array $hourlyDetail = null,
    ) {
        if ($hourlyDetail !== null) PayslipHourlyDetail::validateInput($hourlyDetail, $amountMinorUnits);
        if (trim($label) === '' || mb_strlen($label) > 160) {
            throw new \InvalidArgumentException('Payslip line label must not be empty.');
        }

        if ($amountMinorUnits < -self::MAX_MINOR_UNITS || $amountMinorUnits > self::MAX_MINOR_UNITS) {
            throw new \InvalidArgumentException('Payslip line amount is outside the supported range.');
        }

        if ($exemptionBasis === null && $exemptPartMinorUnits !== 0) {
            throw new \InvalidArgumentException(
                'Zdanitelná složka výplatní pásky nesmí nést nezdaněnou část.',
            );
        }

        // Oprava minulého období je záporná složka a její nezdaněná část je
        // záporná taky. Rozsah se proto drží znaménkem částky, ne nulou.
        if ($exemptPartMinorUnits < min(0, $amountMinorUnits)
            || $exemptPartMinorUnits > max(0, $amountMinorUnits)
        ) {
            throw new \InvalidArgumentException(
                'Nezdaněná část složky výplatní pásky je mimo částku složky.',
            );
        }
    }

    public function reportedExemptMinorUnits(): int
    {
        return $this->exemptionBasis?->isReportedAsExemptIncome() === true
            ? $this->exemptPartMinorUnits
            : 0;
    }

    public function notSubjectToTaxMinorUnits(): int
    {
        return $this->exemptionBasis === PayrollExemptionBasis::NotSubjectToTax
            ? $this->exemptPartMinorUnits
            : 0;
    }

    /**
     * @return array{
     *   label:string,
     *   amount_minor_units:int,
     *   exemption_basis:?string,
     *   exemption_statute:?string,
     *   exempt_part_minor_units:int
     * }
     */
    public function toTemplateData(): array
    {
        return [
            'label' => $this->label,
            'amount_minor_units' => $this->amountMinorUnits,
            'exemption_basis' => $this->exemptionBasis?->value,
            'exemption_statute' => $this->exemptionBasis?->statute(),
            'exempt_part_minor_units' => $this->exemptPartMinorUnits,
            ...($this->hourlyDetail === null ? [] : ['hourly_detail' => $this->hourlyDetail]),
        ];
    }
}
