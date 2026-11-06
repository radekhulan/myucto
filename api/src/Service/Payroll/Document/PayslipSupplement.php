<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

/**
 * Údaje výplatní pásky, které zaměstnance zajímají a které zmrazený snapshot
 * pásky nenese: docházka, dovolená, průměr, rozpad složek, zdanitelný příjem,
 * rozpis slev a účet výplaty.
 *
 * Čte se ze zmrazené vstupní revize téhož běhu a z knihy dovolené ke konci
 * období. Na otisk pásky nemá vliv — páska je dál kotvená výsledkem osoby;
 * doplněk jen rozšiřuje, co se o téže mzdě vytiskne. Starší pásky bez doplňku
 * údaje nevypisují (žádné nuly za neznámé hodnoty).
 */
final readonly class PayslipSupplement
{
    /** Popisky slev podle § 35ba v pořadí tisku. */
    private const CREDIT_LABELS = [
        'taxpayer' => 'Sleva na poplatníka',
        'disability-basic' => 'Sleva na invaliditu',
        'disability-extended' => 'Sleva na invaliditu',
        'ztp-p' => 'Sleva na držitele ZTP/P',
        'student' => 'Sleva na studenta',
        'student-doctoral' => 'Sleva na studenta',
    ];

    /**
     * @param array<string,int> $creditBreakdown druh slevy => nárok v haléřích
     */
    public function __construct(
        public PayrollFrozenMonthFacts $facts,
        public ?int $remainingVacationMinutes = null,
        public ?int $taxableIncomeMinorUnits = null,
        public array $creditBreakdown = [],
        public ?int $appliedCreditsMinorUnits = null,
    ) {}

    /**
     * @param array<string,mixed> $inputPerson
     * @param array<string,mixed> $resultPerson
     */
    public static function fromFrozen(
        array $inputPerson,
        array $resultPerson,
        ?int $remainingVacationMinutes,
    ): self {
        $statutory = is_array($resultPerson['statutory'] ?? null) ? $resultPerson['statutory'] : [];
        $tax = is_array($statutory['income_tax'] ?? null) ? $statutory['income_tax'] : [];
        $advance = is_array($tax['advance_tax'] ?? null) ? $tax['advance_tax'] : [];
        $breakdown = [];
        if (is_array($tax['claimed_non_refundable_credit_breakdown'] ?? null)) {
            foreach ($tax['claimed_non_refundable_credit_breakdown'] as $kind => $amount) {
                if (is_string($kind) && is_int($amount) && $amount >= 0) {
                    $breakdown[$kind] = $amount;
                }
            }
        }

        return new self(
            PayrollFrozenMonthFacts::fromFrozen($inputPerson, $resultPerson),
            $remainingVacationMinutes,
            is_int($advance['taxable_income_minor_units'] ?? null)
                ? $advance['taxable_income_minor_units']
                : null,
            $breakdown,
            is_int($tax['applied_non_refundable_credits_minor_units'] ?? null)
                ? $tax['applied_non_refundable_credits_minor_units']
                : null,
        );
    }

    /**
     * Rozpis slev se tiskne jen tehdy, když se uplatnil celý nárok. Jinak by
     * jednotlivé řádky tvrdily vyšší slevu, než jaká se poskytla, a tiskne se
     * jen úhrn uplatněných slev.
     *
     * @return list<array{label:string,amount_minor_units:int}>
     */
    public function creditLines(): array
    {
        $claimed = array_sum($this->creditBreakdown);
        if ($this->appliedCreditsMinorUnits === null || $claimed !== $this->appliedCreditsMinorUnits) {
            return [];
        }
        $lines = [];
        foreach ($this->creditBreakdown as $kind => $amount) {
            $label = self::CREDIT_LABELS[$kind] ?? 'Ostatní slevy';
            $lines[$label] = ($lines[$label] ?? 0) + $amount;
        }
        $result = [];
        foreach ($lines as $label => $amount) {
            $result[] = ['label' => $label, 'amount_minor_units' => $amount];
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function toTemplateData(): array
    {
        $groups = $this->facts->groupTotals();

        return [
            'time' => $this->facts->time,
            'has_time' => $this->facts->hasTime(),
            'average_hourly_minor_units' => $this->facts->averageHourlyMinorUnits,
            'groups' => $groups,
            'components' => $this->facts->componentsByLabel(),
            'personal_number' => $this->facts->employmentCodes === []
                ? null
                : implode(', ', $this->facts->employmentCodes),
            'payout_account' => $this->facts->payoutAccountMasked,
            'remaining_vacation_minutes' => $this->remainingVacationMinutes,
            'taxable_income_minor_units' => $this->taxableIncomeMinorUnits,
            'credit_lines' => $this->creditLines(),
        ];
    }
}
