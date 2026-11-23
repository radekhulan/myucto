<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Report;

use MyInvoice\Repository\Payroll\PayrollPostingDetailReportRepository;
use MyInvoice\Service\Payroll\Posting\PayrollPostingEmployees;
use MyInvoice\Service\Payroll\Posting\PayrollPostingLineBuilder;

/**
 * Sestava podrobného zaúčtování mezd za měsíc: nahoře souhrn za firmu po druzích
 * mezd a dvojicích účtů, pod ním rozpad po zaměstnancích a druzích mezd.
 *
 * Zdrojem jsou cílové alokace účinné dávky každého běhu měsíce rozdělené na
 * dvojice stejným párovačem jako zaúčtování ve formě single
 * ({@see PayrollPostingLineBuilder::reportPairs()}). Nezáleží tedy na tom, zda
 * firma účtuje souhrnně nebo po zaměstnancích ani na formě zápisu: zůstatek
 * dvojic po účtech je zůstatek mzdových zápisů období v deníku.
 *
 * Druh mzdy je u hrubé mzdy název mzdové složky zmrazený v revizi, jinde popis
 * alokace (pojistné, daň, srážka …). Hodiny se uvádějí jen u hodinové mzdy, kde
 * množství vstupu znamená odpracované hodiny; jinde je katalog složek nevede.
 * Jméno se bere ze zmrazené revize, osobní číslo je kód hlavního vztahu osoby.
 */
final class PayrollPostingDetailReportService
{
    private const HOURLY_KINDS = ['hourly_wage'];

    public function __construct(
        private readonly PayrollPostingDetailReportRepository $repository,
        private readonly PayrollPostingLineBuilder $builder,
    ) {}

    /**
     * @return array{
     *   period:string,
     *   runs:list<array{run_id:int,revision_no:int,journal_entry_id:?int}>,
     *   summary:list<array{label:string,debit_account:?string,credit_account:?string,hours:?float,amount_minor:int}>,
     *   rows:list<array{employee_id:?int,personal_number:?string,name:?string,label:string,component_code:?string,debit_account:?string,credit_account:?string,cost_center:?string,hours:?float,amount_minor:int}>,
     *   totals:array{amount_minor:int,hours:?float}
     * }
     */
    public function report(int $supplierId, string $period): array
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m', $period);
        if ($date === false || $date->format('Y-m') !== $period) {
            throw new \InvalidArgumentException('Období musí být měsíc ve tvaru YYYY-MM.');
        }
        $batches = $this->repository->effectiveBatches($supplierId, $date->format('Y-m-01'));
        $allocations = $this->repository->allocations($supplierId, array_column($batches, 'batch_id'));
        $snapshots = $this->repository->inputSnapshots($supplierId, array_column($batches, 'revision_id'));

        /** @var array<string,array<string,mixed>> $rows */
        $rows = [];
        foreach ($batches as $batch) {
            $snapshot = $snapshots[$batch['revision_id']] ?? [];
            $employees = PayrollPostingEmployees::fromSnapshot($snapshot);
            $codes = $employees->codes();
            $names = self::names($snapshot);
            $inputs = self::inputs($snapshot);
            $pairs = $this->builder->reportPairs($allocations[$batch['batch_id']] ?? [], $employees);
            $unitTotals = [];
            foreach ($pairs as $pair) {
                $unitTotals[$pair['unit']] = ($unitTotals[$pair['unit']] ?? 0) + $pair['amount_minor'];
            }
            foreach ($pairs as $pair) {
                $side = $pair['debit'] ?? $pair['credit'];
                $employeeId = $pair['debit']['employee']['id'] ?? $pair['credit']['employee']['id'] ?? null;
                $input = preg_match('/employment:\d+:input:(\d+)/', $pair['unit'], $match) === 1
                    && str_starts_with($pair['unit'], 'gross:')
                    ? ($inputs[(int) $match[1]] ?? null)
                    : null;
                $label = $input['name'] ?? (string) ($side['allocation_description'] ?? '');
                $hours = null;
                if ($input !== null && $input['hours'] !== null && $unitTotals[$pair['unit']] !== 0) {
                    $hours = round($input['hours'] * $pair['amount_minor'] / $unitTotals[$pair['unit']], 2);
                }
                $debitAccount = $pair['debit']['account_code'] ?? null;
                $creditAccount = $pair['credit']['account_code'] ?? null;
                $costCenter = $pair['debit']['cost_center'] ?? $pair['credit']['cost_center'] ?? null;
                $key = implode("\0", [
                    $employeeId === null ? "\x7f" : sprintf('%010d', $employeeId),
                    $debitAccount ?? '',
                    $label,
                    $input['code'] ?? '',
                    $creditAccount ?? '',
                    $costCenter ?? '',
                ]);
                if (!isset($rows[$key])) {
                    $rows[$key] = [
                        'employee_id' => $employeeId,
                        'personal_number' => $employeeId === null ? null : ($codes[$employeeId] ?? (string) $employeeId),
                        'name' => $employeeId === null ? null : ($names[$employeeId] ?? null),
                        'label' => $label,
                        'component_code' => $input['code'] ?? null,
                        'debit_account' => $debitAccount,
                        'credit_account' => $creditAccount,
                        'cost_center' => $costCenter,
                        'hours' => null,
                        'amount_minor' => 0,
                    ];
                }
                $rows[$key]['amount_minor'] += $pair['amount_minor'];
                if ($hours !== null) {
                    $rows[$key]['hours'] = round(($rows[$key]['hours'] ?? 0.0) + $hours, 2);
                }
            }
        }
        ksort($rows, SORT_STRING);
        $rows = array_values($rows);

        $summary = [];
        $totalMinor = 0;
        $totalHours = null;
        foreach ($rows as $row) {
            $key = implode("\0", [$row['debit_account'] ?? '', $row['credit_account'] ?? '', $row['label']]);
            $summary[$key] ??= [
                'label' => $row['label'],
                'debit_account' => $row['debit_account'],
                'credit_account' => $row['credit_account'],
                'hours' => null,
                'amount_minor' => 0,
            ];
            $summary[$key]['amount_minor'] += $row['amount_minor'];
            if ($row['hours'] !== null) {
                $summary[$key]['hours'] = round(($summary[$key]['hours'] ?? 0.0) + $row['hours'], 2);
                $totalHours = round(($totalHours ?? 0.0) + $row['hours'], 2);
            }
            $totalMinor += $row['amount_minor'];
        }
        ksort($summary, SORT_STRING);

        return [
            'period' => $period,
            'runs' => array_map(static fn (array $batch): array => [
                'run_id' => $batch['run_id'],
                'revision_no' => $batch['revision_no'],
                'journal_entry_id' => $batch['journal_entry_id'],
            ], $batches),
            'summary' => array_values($summary),
            'rows' => $rows,
            'totals' => ['amount_minor' => $totalMinor, 'hours' => $totalHours],
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<int,string> employee_id → jméno ze zmrazené revize
     */
    private static function names(array $snapshot): array
    {
        $names = [];
        foreach (is_array($snapshot['people'] ?? null) ? $snapshot['people'] : [] as $person) {
            $id = $person['employee']['id'] ?? null;
            $name = $person['employee']['full_name'] ?? null;
            if (is_int($id) && is_string($name) && $name !== '') {
                $names[$id] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<int,array{name:string,code:?string,hours:?float}> input_id → druh mzdy
     */
    private static function inputs(array $snapshot): array
    {
        $inputs = [];
        foreach (is_array($snapshot['people'] ?? null) ? $snapshot['people'] : [] as $person) {
            foreach (is_array($person['employments'] ?? null) ? $person['employments'] : [] as $employment) {
                foreach (is_array($employment['inputs'] ?? null) ? $employment['inputs'] : [] as $input) {
                    $id = $input['id'] ?? null;
                    $component = is_array($input['component'] ?? null) ? $input['component'] : [];
                    if (!is_int($id)) {
                        continue;
                    }
                    $milli = $input['quantity_milliunits'] ?? null;
                    $inputs[$id] = [
                        'name' => is_string($component['name'] ?? null) && $component['name'] !== ''
                            ? $component['name']
                            : (string) ($component['code'] ?? ''),
                        'code' => is_string($component['code'] ?? null) ? $component['code'] : null,
                        'hours' => is_int($milli) && in_array($component['component_kind'] ?? null, self::HOURLY_KINDS, true)
                            ? $milli / 1000
                            : null,
                    ];
                }
            }
        }

        return $inputs;
    }
}
