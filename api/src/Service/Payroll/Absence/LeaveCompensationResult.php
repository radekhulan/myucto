<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

final readonly class LeaveCompensationResult
{
    /**
     * @param array<string,int> $minutesByPeriod první den měsíce => minuty
     * @param array<string,int> $amountsByPeriod první den měsíce => haléře
     */
    public function __construct(
        public int $averageHourlyMinor,
        public array $minutesByPeriod,
        public array $amountsByPeriod,
    ) {}

    public function totalMinor(): int
    {
        return array_sum($this->amountsByPeriod);
    }

    /**
     * Stopa, kterou nese mzdový vstup. Hodiny, sazba i podklad průměru musí jít
     * doložit z mzdového listu (§ 142 odst. 5 ZP) — bez nich je na pásce jen
     * částka, ke které nikdo nedohledá, jak vznikla.
     *
     * @return array<string,mixed>
     */
    public function trace(string $period, int $absenceId, int $averageSnapshotId): array
    {
        return [
            'kind' => 'leave_compensation.v1',
            'absence_id' => $absenceId,
            'period_start' => $period,
            'minutes' => $this->minutesByPeriod[$period] ?? 0,
            'average_hourly_minor' => $this->averageHourlyMinor,
            'average_snapshot_id' => $averageSnapshotId,
            'amount_minor' => $this->amountsByPeriod[$period] ?? 0,
            'rounding' => 'ceil-to-czk-on-period-total',
            'rounding_basis' => 'zp-142-2-via-144',
            'entitlement_basis' => 'zp-222-1',
        ];
    }
}
