<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;

/**
 * „Doplnit pravděpodobný výdělek ze sazby" pro celý mzdový měsíc.
 *
 * Vztahy bere stejným pravidlem členství jako mzdový běh
 * ({@see PayrollRunSnapshotBuilder::employmentRows()}), takže doplní právě
 * ty, kterým v běhu chybí průměr. Každý vztah pak jde přes
 * {@see AverageEarningBatchService::ensureProbableFromRate()}, které rozhodne,
 * zda se automatický záznam smí založit.
 */
final class ProbableAverageFromRateFiller
{
    public function __construct(
        private readonly PayrollRunSnapshotBuilder $snapshots,
        private readonly AverageEarningBatchService $averages,
    ) {}

    /**
     * @return array{created:list<array<string,mixed>>,candidates:int}
     */
    public function fill(int $supplierId, string $periodStart, ?int $officeId, ?int $userId): array
    {
        $period = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if ($period === false || $period->format('Y-m-d') !== $periodStart || $period->format('d') !== '01') {
            throw new \InvalidArgumentException('Mzdové období musí být první den měsíce ve formátu YYYY-MM-DD.');
        }
        $year = (int) $period->format('Y');
        $quarter = intdiv((int) $period->format('n') - 1, 3) + 1;
        $periodEnd = $period->modify('last day of this month')->format('Y-m-d');

        $candidates = [];
        foreach ($this->snapshots->employmentRows($supplierId, $periodStart, $periodEnd, $officeId) as $row) {
            if (($row['remuneration_type'] ?? null) === 'hourly'
                && (int) ($row['hourly_rate_minor'] ?? 0) > 0
                && ($row['average_earning_id'] ?? null) === null
            ) {
                $candidates[(int) $row['employment_id']] = true;
            }
        }
        $created = [];
        foreach (array_keys($candidates) as $employmentId) {
            $snapshot = $this->averages->ensureProbableFromRate($supplierId, $employmentId, $year, $quarter, $userId);
            if ($snapshot !== null) {
                $created[] = $snapshot;
            }
        }

        return ['created' => $created, 'candidates' => count($candidates)];
    }
}
