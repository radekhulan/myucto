<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Report;

use MyInvoice\Repository\Payroll\PayrollDimensionCostReportRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\Payment\PayrollAccidentInsuranceCostAllocation;
use MyInvoice\Service\Payroll\Payment\PayrollAccidentInsuranceLiabilityMaterializer;
use MyInvoice\Service\Payroll\Posting\PayrollPostingLineBuilder;

/**
 * Náklady na zaměstnance po dimenzi (středisko, firemní dimenze).
 *
 * Deník nese mzdový předpis za firmu, ne po zaměstnanci — analytika po
 * osobách by z deníku udělala mzdový list. Náklad osoby proto vzniká nad
 * cílovými alokacemi účetního můstku (`payroll_posting_allocations`): každá
 * nákladová alokace nese pracovní vztah v klíči a středisko i firemní dimenze
 * ve vlastních sloupcích. Součet reportu tak sedí na nákladové řádky deníku.
 *
 * Pojistné zaměstnavatele se bez dimenzí účtuje jednou firemní částkou, protože
 * zákonná částka vzniká ze součtu základů. Report ho rozloží na vztahy tímtéž
 * rozdělením, které můstek použije pro střediska
 * ({@see PayrollPostingLineBuilder::employerInsuranceCostShares()}), a podíl
 * vztahu promítne do jeho dimenzí. Do řádku „nerozděleno" jde jen to, co
 * rozdělit nejde: revize, která si neuložila vyměřovací základy vztahů.
 *
 * Zákonné pojištění odpovědnosti (548) se v alokacích můstku nevyskytuje, má
 * vlastní čtvrtletní předpis. Report vezme čistou částku čtvrtletí (předpis
 * s opravami) a rozloží ji klíčem, kterým se předpis zaúčtuje
 * ({@see PayrollAccidentInsuranceCostAllocation}); patří do sloupce pojistného.
 *
 * Report se ukazuje jen firmě, která dimenze používá (firemní dimenze zapnuté,
 * nebo aspoň jedna mzdová dimenze); jinak vrací `enabled: false`.
 */
final class PayrollDimensionCostReportService
{
    private const STATUTORY_KINDS = ['social_insurance', 'health_insurance', 'income_tax', 'net_pay'];

    private const EMPLOYER_INSURANCE_KEYS = [
        'employer-insurance:social:debit' => 'social',
        'employer-insurance:health:debit' => 'health',
    ];

    public const REASON_EMPLOYER_INSURANCE = 'employer_insurance_not_allocatable';
    public const REASON_FIRM_LEVEL = 'firm_level_cost';

    public function __construct(
        private readonly PayrollDimensionCostReportRepository $repository,
        private readonly PayrollStatutoryResultRepository $statutoryResults,
        private readonly PayrollPostingLineBuilder $lineBuilder,
        private readonly PayrollAccidentInsuranceLiabilityMaterializer $accidentInsurance,
        private readonly PayrollAccidentInsuranceCostAllocation $accidentInsuranceCosts,
    ) {}

    /**
     * @return array{
     *   year:int,
     *   enabled:bool,
     *   rows:list<array{
     *     employment_id:?int,
     *     employee_id:?int,
     *     employee_name:?string,
     *     employment_code:?string,
     *     cost_center:?string,
     *     dimensions:list<array{type_id:int,type_name:string,value_id:int,code:string,name:string}>,
     *     wages_minor:int,
     *     insurance_minor:int,
     *     other_minor:int,
     *     total_minor:int,
     *     unallocated_reason:?string
     *   }>,
     *   by_dimension:list<array{type_id:?int,type_name:?string,value_id:?int,code:string,name:string,total_minor:int}>,
     *   totals:array{wages_minor:int,insurance_minor:int,other_minor:int,total_minor:int}
     * }
     */
    public function report(int $supplierId, int $year): array
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musí být zvolená.');
        }
        if ($year < 2000 || $year > 2200) {
            throw new \InvalidArgumentException('Mzdový rok musí být v rozsahu 2000 až 2200.');
        }

        $totals = ['wages_minor' => 0, 'insurance_minor' => 0, 'other_minor' => 0, 'total_minor' => 0];
        if (!$this->repository->dimensionsInUse($supplierId)) {
            return [
                'year' => $year,
                'enabled' => false,
                'rows' => [],
                'by_dimension' => [],
                'totals' => $totals,
            ];
        }

        $groups = [];
        $employmentIds = [];
        $valueIds = [];
        foreach ($this->allocations($supplierId, $year) as $allocation) {
            $employmentId = $allocation['employment_id'];
            $dimensions = $allocation['dimensions'];
            $key = ($employmentId ?? 0) . '|' . ($allocation['cost_center'] ?? '') . '|'
                . json_encode($dimensions, JSON_THROW_ON_ERROR);
            $groups[$key] ??= [
                'employment_id' => $employmentId,
                'cost_center' => $allocation['cost_center'],
                'dimension_ids' => $dimensions,
                'wages_minor' => 0,
                'insurance_minor' => 0,
                'other_minor' => 0,
                'unallocated_reason' => null,
            ];
            $bucket = match (true) {
                str_starts_with($allocation['allocation_key'], 'gross:') => 'wages_minor',
                str_starts_with($allocation['allocation_key'], 'employer-insurance:'),
                str_starts_with($allocation['allocation_key'], 'accident-insurance:') => 'insurance_minor',
                default => 'other_minor',
            };
            $groups[$key][$bucket] += $allocation['signed_minor'];
            if ($employmentId !== null) {
                $employmentIds[] = $employmentId;
            } elseif ($allocation['signed_minor'] !== 0) {
                $groups[$key]['unallocated_reason'] = $bucket === 'insurance_minor'
                    ? self::REASON_EMPLOYER_INSURANCE
                    : ($groups[$key]['unallocated_reason'] ?? self::REASON_FIRM_LEVEL);
            }
            foreach ($dimensions as $valueId) {
                $valueIds[] = $valueId;
            }
        }

        $employments = $this->repository->employments($supplierId, $employmentIds);
        $values = $this->repository->dimensionValues($supplierId, $valueIds);

        $rows = [];
        $byDimension = [];
        foreach ($groups as $group) {
            $employment = $group['employment_id'] === null ? null : ($employments[$group['employment_id']] ?? null);
            $dimensionRows = [];
            foreach ($group['dimension_ids'] as $typeId => $valueId) {
                $value = $values[$valueId] ?? null;
                if ($value === null) {
                    continue;
                }
                $dimensionRows[] = [
                    'type_id' => $typeId,
                    'type_name' => $value['type_name'],
                    'value_id' => $valueId,
                    'code' => $value['code'],
                    'name' => $value['name'],
                ];
            }
            $total = $group['wages_minor'] + $group['insurance_minor'] + $group['other_minor'];
            $rows[] = [
                'employment_id' => $group['employment_id'],
                'employee_id' => $employment['employee_id'] ?? null,
                'employee_name' => $employment['employee_name'] ?? null,
                'employment_code' => $employment['employment_code'] ?? null,
                'cost_center' => $group['cost_center'],
                'dimensions' => $dimensionRows,
                'wages_minor' => $group['wages_minor'],
                'insurance_minor' => $group['insurance_minor'],
                'other_minor' => $group['other_minor'],
                'total_minor' => $total,
                'unallocated_reason' => $group['employment_id'] === null ? $group['unallocated_reason'] : null,
            ];
            foreach (['wages_minor', 'insurance_minor', 'other_minor'] as $field) {
                $totals[$field] += $group[$field];
            }
            $totals['total_minor'] += $total;

            // Souhrn po hodnotě: firemní dimenze, jinak textové středisko,
            // jinak „bez dimenze". Alokace s víc typy se započte u každého.
            $summaryKeys = [];
            foreach ($dimensionRows as $dimension) {
                $summaryKeys['v' . $dimension['value_id']] = [
                    'type_id' => $dimension['type_id'],
                    'type_name' => $dimension['type_name'],
                    'value_id' => $dimension['value_id'],
                    'code' => $dimension['code'],
                    'name' => $dimension['name'],
                ];
            }
            if ($summaryKeys === []) {
                $code = $group['cost_center'] ?? '';
                $summaryKeys['c' . $code] = [
                    'type_id' => null,
                    'type_name' => null,
                    'value_id' => null,
                    'code' => $code,
                    'name' => $code,
                ];
            }
            foreach ($summaryKeys as $summaryKey => $summary) {
                $byDimension[$summaryKey] ??= [...$summary, 'total_minor' => 0];
                $byDimension[$summaryKey]['total_minor'] += $total;
            }
        }

        usort($rows, static fn (array $left, array $right): int =>
            [($left['employee_name'] ?? "\u{10FFFF}"), $left['employment_id'] ?? 0, $left['cost_center'] ?? '']
            <=> [($right['employee_name'] ?? "\u{10FFFF}"), $right['employment_id'] ?? 0, $right['cost_center'] ?? '']);
        $byDimension = array_values($byDimension);
        usort($byDimension, static fn (array $left, array $right): int =>
            [$left['type_name'] ?? "\u{10FFFF}", $left['code']] <=> [$right['type_name'] ?? "\u{10FFFF}", $right['code']]);

        return [
            'year' => $year,
            'enabled' => true,
            'rows' => $rows,
            'by_dimension' => $byDimension,
            'totals' => $totals,
        ];
    }

    /**
     * Nákladové alokace roku; firemní pojistné zaměstnavatele rozložené na vztahy.
     *
     * @return list<array{allocation_key:string,employment_id:?int,cost_center:?string,dimensions:array<int,int>,signed_minor:int}>
     */
    private function allocations(int $supplierId, int $year): array
    {
        $result = [];
        /** @var array<int,array{social:?list<array{allocation_key:string,employment_id:int,cost_center:?string,dimensions:array<int,int>,signed_minor:int}>,health:?list<array{allocation_key:string,employment_id:int,cost_center:?string,dimensions:array<int,int>,signed_minor:int}>}|null> $sharesByRevision */
        $sharesByRevision = [];
        foreach ($this->repository->costAllocations($supplierId, $year) as $allocation) {
            $kind = self::EMPLOYER_INSURANCE_KEYS[$allocation['allocation_key']] ?? null;
            if ($kind !== null) {
                $revisionId = $allocation['revision_id'];
                if (!array_key_exists($revisionId, $sharesByRevision)) {
                    $sharesByRevision[$revisionId] = $this->employerInsuranceShares($supplierId, $revisionId);
                }
                $shares = $sharesByRevision[$revisionId][$kind] ?? null;
                if ($shares !== null
                    && array_sum(array_column($shares, 'signed_minor')) === $allocation['signed_minor']
                ) {
                    foreach ($shares as $share) {
                        $result[] = $share;
                    }
                    continue;
                }
            }
            $result[] = [
                'allocation_key' => $allocation['allocation_key'],
                'employment_id' => preg_match('/(?:^|:)employment:(\d+)(?::|$)/', $allocation['allocation_key'], $match) === 1
                    ? (int) $match[1]
                    : null,
                'cost_center' => $allocation['cost_center'],
                'dimensions' => self::dimensions($allocation['dimensions']),
                'signed_minor' => $allocation['signed_minor'],
            ];
        }

        foreach ($this->repository->accidentInsuranceQuarters($supplierId, $year) as $quarter) {
            if ($quarter['signed_minor'] <= 0) {
                continue;
            }
            $allocationKey = 'accident-insurance:quarter:' . substr($quarter['quarter_end_period'], 0, 7) . ':debit';
            $key = $this->accidentInsurance->quarterCostKey($supplierId, $quarter['quarter_end_period']);
            $parts = $key === null
                ? [['employment_id' => null, 'cost_center' => null, 'dimensions' => [], 'amount_minor' => $quarter['signed_minor']]]
                : $this->accidentInsuranceCosts->allocate($supplierId, $key, $quarter['signed_minor']);
            foreach ($parts as $part) {
                $result[] = [
                    'allocation_key' => $allocationKey,
                    'employment_id' => $part['employment_id'],
                    'cost_center' => $part['cost_center'],
                    'dimensions' => $part['dimensions'],
                    'signed_minor' => $part['amount_minor'],
                ];
            }
        }

        return $result;
    }

    /**
     * @return array{social:?list<array{allocation_key:string,employment_id:int,cost_center:?string,dimensions:array<int,int>,signed_minor:int}>,health:?list<array{allocation_key:string,employment_id:int,cost_center:?string,dimensions:array<int,int>,signed_minor:int}>}|null
     */
    private function employerInsuranceShares(int $supplierId, int $revisionId): ?array
    {
        $snapshot = $this->repository->revisionInputSnapshot($supplierId, $revisionId);
        if ($snapshot === null) {
            return null;
        }
        $sets = [];
        foreach (self::STATUTORY_KINDS as $kind) {
            $set = $this->statutoryResults->find($supplierId, $revisionId, $kind);
            if ($set === null) {
                return null;
            }
            $sets[$kind] = $set;
        }
        try {
            return $this->lineBuilder->employerInsuranceCostShares($snapshot, $sets);
        } catch (\DomainException|\UnexpectedValueException|\InvalidArgumentException) {
            // Revize, jejíž podklady rozpad neunesou, zůstane v řádku
            // „nerozděleno", report se kvůli ní nesmí zastavit.
            return null;
        }
    }

    /** @return array<int,int> typ → hodnota */
    private static function dimensions(?string $json): array
    {
        if ($json === null) {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('Alokace mzdového můstku má neplatné dimenze.');
        }
        $result = [];
        foreach ($decoded as $typeId => $valueId) {
            $result[(int) $typeId] = (int) $valueId;
        }
        ksort($result, SORT_NUMERIC);

        return $result;
    }
}
