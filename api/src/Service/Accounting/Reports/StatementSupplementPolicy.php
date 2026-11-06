<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

final class StatementSupplementPolicy
{
    public function __construct(
        private readonly StatementFrameworkResolver $frameworks,
        private readonly \MyInvoice\Repository\AccountingPeriodRepository $periods,
    ) {}

    public function framework(int $supplierId, int $periodId): string
    {
        $period = $this->periods->findById($supplierId, $periodId);
        return $this->frameworks->context($supplierId, $periodId, $period['ends_on'] ?? null)['framework'];
    }

    public function assert500Supplement(int $supplierId, int $periodId): void
    {
        if ($this->framework($supplierId, $periodId) === 'cz_504') {
            throw new ReportException('statement_supplement_unsupported', 'Přehled peněžních toků a změn vlastního kapitálu podle vyhlášky 500 není dostupný pro účetní rámec 504.');
        }
    }
}
