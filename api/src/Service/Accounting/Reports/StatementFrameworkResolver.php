<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;

final class StatementFrameworkResolver
{
    public function __construct(private readonly AccountingPeriodRepository $periods, private readonly AccountingFrameworkService $frameworks) {}

    public function context(int $supplierId, int $periodId, ?string $asOf): array
    {
        $context = $this->accountingContext($supplierId, $periodId, $asOf);
        if ($context['framework'] === AccountingFrameworkService::CZ_504 && $context['period']['starts_on'] < '2024-01-01') {
            throw new ReportException('statement_version_missing', 'Výkazy podle vyhlášky 504 před rokem 2024 zatím nejsou podporované.');
        }
        return $context;
    }

    public function accountingContext(int $supplierId, int $periodId, ?string $asOf): array
    {
        $period = $this->periods->findById($supplierId, $periodId);
        if ($period === null) throw new ReportException('period_not_found', 'Účetní období neexistuje.', 404);
        $defaultDate = $asOf === null || $asOf === '';
        $asOf = $defaultDate ? min($period['ends_on'], date('Y-m-d')) : $asOf;
        $framework = $this->frameworks->forDate($supplierId, (string) $period['starts_on']);
        $history = $this->frameworks->forInterval($supplierId, (string) $period['starts_on'], (string) $period['ends_on']);
        if (count(array_unique($history)) !== 1) {
            throw new ReportException('statement_framework_period_conflict', 'Účetní rámec se mění uvnitř vykazovaného období.');
        }
        if ($framework === AccountingFrameworkService::CZ_504) {
            if ($defaultDate) $asOf = max($period['starts_on'], $asOf);
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $asOf);
            if ($parsed === false || $parsed->format('Y-m-d') !== $asOf || $asOf < $period['starts_on'] || $asOf > $period['ends_on']) {
                throw new ReportException('invalid_statement_date', 'Datum výkazu leží mimo účetní období.');
            }
        }
        return ['period' => $period, 'as_of' => $asOf, 'framework' => $framework];
    }
}
