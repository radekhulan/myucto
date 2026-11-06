<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;

/** Jedno dávkové načtení účinných hodinových minim pro úseky náhrady. */
final class PayrollHourlyAverageSegments
{
    public function __construct(private readonly Connection $db) {}

    /** @param list<array{local_date:string}> $segments @return array{averages:array<string,int>,proofs:array<string,list<array<string,mixed>>>} */
    public function forEmployment(int $supplierId, int $employmentId, array $segments, int $average): array
    {
        if ($segments === []) return ['averages' => [], 'proofs' => []];
        $dates = array_values(array_unique(array_map(static fn(array $row): string => (string) $row['local_date'], $segments)));
        sort($dates);
        $intervals = (new PayrollHourlyAverageFloorResolver($this->db))->forMany($supplierId, [$employmentId], $dates[0], $dates[count($dates) - 1])[$employmentId] ?? [];
        if ($intervals === []) return ['averages' => [], 'proofs' => []];
        $averages = [];
        $proofs = [];
        foreach ($dates as $date) {
            $resolved = PayrollHourlyAverageFloorResolver::apply($intervals, $date, $average);
            if ($resolved['hourly_proof'] === null) continue;
            $averages[$date] = $resolved['hourly_minor'];
            $proofs[substr($date, 0, 7) . '-01'][] = $resolved['hourly_proof'];
        }
        return ['averages' => $averages, 'proofs' => $proofs];
    }
}
