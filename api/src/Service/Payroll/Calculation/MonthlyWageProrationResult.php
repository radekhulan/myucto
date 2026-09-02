<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Calculation;

final readonly class MonthlyWageProrationResult
{
    /** @param array<string,int> $replacedMinutesByTitle */
    public function __construct(
        public int $monthlyGrossMinor,
        public int $fundMinutes,
        public int $retainedMinutes,
        public int $replacedMinutes,
        public array $replacedMinutesByTitle,
        public int $amountMinor,
    ) {}

    public function isProrated(): bool
    {
        return $this->replacedMinutes > 0;
    }

    /**
     * Auditní stopa krácení. Beze změny se ukládá do mzdového vstupu, takže
     * z pásky jde doložit, KTERÝ fond a KTERÉ nahrazené hodiny částku určily.
     *
     * @return array<string,mixed>
     */
    public function trace(): array
    {
        return [
            'kind' => 'monthly_wage_proration.v1',
            'monthly_gross_minor' => $this->monthlyGrossMinor,
            'fund_minutes' => $this->fundMinutes,
            'retained_minutes' => $this->retainedMinutes,
            'replaced_minutes' => $this->replacedMinutes,
            'replaced_minutes_by_title' => $this->replacedMinutesByTitle,
            'amount_minor' => $this->amountMinor,
            'rounding' => 'ceil-to-czk',
            'rounding_basis' => 'zp-142-2',
        ];
    }
}
