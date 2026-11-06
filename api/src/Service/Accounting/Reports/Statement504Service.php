<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Repository\Statement504DataRepository;
use MyInvoice\Repository\StatementDefinitionRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionFilter;

final class Statement504Service
{
    public function __construct(
        private readonly Statement504DataRepository $data,
        private readonly StatementDefinitionRepository $definitions,
        private readonly StatementMapResolver $maps,
        private readonly Statement504CategoryCriteria $categoryCriteria,
        private readonly DeferredTax504Statement $deferredTax,
    ) {}

    public function build(string $type, int $supplierId, array $context, string $scope, ?DimensionFilter $dimension): array
    {
        return $this->deferredTax->read(fn (): array => $this->buildSnapshot($type, $supplierId, $context, $scope, $dimension));
    }

    private function buildSnapshot(string $type, int $supplierId, array $context, string $scope, ?DimensionFilter $dimension): array
    {
        if ($type === FinancialStatementService::TYPE_PURPOSE) {
            throw new ReportException('statement_purpose_unsupported', 'Vyhláška 504 nestanoví účelovou výsledovku.');
        }
        $period = $context['period'];
        $deferred = $this->deferredTax->context($supplierId, $context['as_of']);
        $builder = $this->builder($supplierId, $period, $context['as_of'], $deferred);
        $profit = $this->data->profitLines($supplierId, $period['starts_on'], $context['as_of'], $dimension);
        $balances = $type === 'balance_sheet' ? $this->data->balances($supplierId, $period['starts_on'], $context['as_of'], $dimension) : [];
        $out = $type === 'balance_sheet'
            ? $builder->balanceSheet($balances, $profit, $scope)
            : $builder->incomeStatement($profit, $scope);
        return $this->metadata($type, $context, $dimension) + ['scope' => $scope] + DeferredTax504Statement::decorate($out, $profit, $balances, $deferred);
    }

    public function criteria(int $supplierId, array $period, string $asOf): array
    {
        return $this->deferredTax->read(function () use ($supplierId, $period, $asOf): array {
            $builder = $period['starts_on'] < '2024-01-01' ? null : $this->builder($supplierId, $period, $asOf, $this->deferredTax->context($supplierId, $asOf));
            return $this->categoryCriteria->calculate($supplierId, $period, $asOf, $builder);
        });
    }

    public function accountView(int $supplierId, array $context, ?DimensionFilter $dimension): array
    {
        return $this->deferredTax->read(fn (): array => $this->accountSnapshot($supplierId, $context, $dimension));
    }

    private function accountSnapshot(int $supplierId, array $context, ?DimensionFilter $dimension): array
    {
        $period = $context['period'];
        $deferred = $this->deferredTax->context($supplierId, $context['as_of']);
        $builder = $this->builder($supplierId, $period, $context['as_of'], $deferred);
        $balances = $this->data->balances($supplierId, $period['starts_on'], $context['as_of'], $dimension);
        $profitLines = $this->data->profitLines($supplierId, $period['starts_on'], $context['as_of'], $dimension);
        $profit = DeferredTax504Statement::decorate($builder->incomeStatement($profitLines, 'full'), $profitLines, [], $deferred);
        $pending = 0;
        $total = 0;
        $out = [];
        foreach ($balances as $balance) {
            $total += $balance['closing_cents'];
            if (str_starts_with($balance['code'], '931')) $pending -= $balance['closing_cents'];
            $out[] = ['account_id' => (int) $balance['id'], 'code' => $balance['code'], 'name' => $balance['name'],
                'account_type' => $balance['account_type'], 'opening_debit_balance' => $balance['opening_cents'] / 100.0,
                'closing_debit_balance' => $balance['closing_cents'] / 100.0];
        }
        return $this->metadata('account_view', $context, $dimension) + array_intersect_key($profit, ['deferred_tax_method' => true]) + [
            'view_kind' => 'management', 'balances' => $out, 'profit_loss' => $profit['rows'],
            'checks' => ['unresolved_pending_result' => $pending / 100.0,
                'reconciliation_difference' => round($total / 100.0 - $profit['checks']['profit_current'], 2)],
        ];
    }

    private function builder(int $supplierId, array $period, string $asOf, ?array $deferred): Statement504Builder
    {
        $rows = [];
        $maps = [];
        foreach (['balance_sheet', 'income_statement'] as $type) {
            $version = $this->definitions->findVersion($type, $asOf, 'cz_504');
            if ($version === null || $version['version_code'] !== Statement504Catalog::VERSION) {
                throw new ReportException('statement_version_missing', 'Definice výkazu 504 chybí nebo zatím není podporovaná.');
            }
            foreach ($this->definitions->rows504((int) $version['id']) as $row) $rows[$row['sentence']][] = $row;
            $maps[$type] = $this->maps->accountMap($version, $supplierId, (int) $period['fiscal_year']);
            $allowed = [];
            foreach (Statement504Catalog::accountMap($type) as $map) {
                $allowed[$map['row_code']][substr($map['account_prefix'], 0, 2)][$map['sign']] = true;
            }
            foreach ($maps[$type] as $map) {
                if ($map['target'] !== 'gross' || !isset($allowed[$map['row_code']][substr($map['account_prefix'], 0, 2)][$map['sign']])) {
                    throw new ReportException('statement_mapping_invalid', 'Mapování účtu neodpovídá účtové skupině nebo řádku výkazu 504.');
                }
                if (($map['source'] ?? '') === 'override') {
                    $standard = StatementMapResolver::longestMatching(Statement504Catalog::accountMap($type), $map['account_prefix']);
                    if ($standard !== [] && !in_array($map['row_code'], array_column($standard, 'row_code'), true)) {
                        throw new ReportException('statement_mapping_invalid', 'Vlastní mapování účtu nesmí změnit předepsaný řádek výkazu 504.');
                    }
                }
            }
        }
        foreach (['UR', 'US', 'UT'] as $sentence) {
            if (array_column($rows[$sentence] ?? [], 'number') !== array_column(Statement504Catalog::rows($sentence), 'number')) {
                throw new ReportException('statement_version_missing', 'Definice výkazu 504 není úplná.');
            }
        }
        return new Statement504Builder($rows, DeferredTax504Statement::maps($maps, $deferred));
    }

    private function metadata(string $type, array $context, ?DimensionFilter $dimension): array
    {
        $period = $context['period'];
        return ['statement_type' => $type, 'accounting_framework' => 'cz_504', 'version_code' => Statement504Catalog::VERSION,
            'as_of' => $context['as_of'], 'period' => ['id' => (int) $period['id'], 'fiscal_year' => (int) $period['fiscal_year'],
                'starts_on' => $period['starts_on'], 'ends_on' => $period['ends_on']], 'dimension' => $dimension?->toArray()];
    }
}
