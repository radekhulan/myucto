<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;

final class AccountingFrameworkImportGuard
{
    /** @param bool $allow504 převod umí i rámec 504 (zatím jen POHODA s osnovou pro neziskové organizace) */
    public function __construct(private readonly Connection $db, private readonly bool $allow504 = false) {}

    public function assertCz500Periods(int $supplierId, array $periods, bool $currentRead = false): void
    {
        if ($currentRead) {
            (new DimensionPeriodGuard($this->db))->lockPeriods($supplierId);
        }
        $frameworks = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
        foreach ($periods as $period) {
            if (isset($period['year'])) {
                $stmt = $this->db->pdo()->prepare('SELECT starts_on, ends_on FROM accounting_periods WHERE supplier_id = ? AND fiscal_year = ?' . ($currentRead ? ' FOR UPDATE' : ''));
                $stmt->execute([$supplierId, $period['year']]);
                $period = $stmt->fetch(\PDO::FETCH_ASSOC) ?: $period;
            }
            foreach ($frameworks->forInterval($supplierId, $period['starts_on'], $period['ends_on'], $currentRead) as $framework) {
                if ($framework !== AccountingFrameworkService::CZ_500
                    && !($this->allow504 && $framework === AccountingFrameworkService::CZ_504)) {
                    $this->unsupported();
                }
            }
        }
    }

    public function periodsForYears(int $supplierId, array $years): array
    {
        $calendar = (new AccountingPeriodRepository($this->db))->fiscalCalendar($supplierId);
        return array_map(static fn (int $year): array => ['year' => $year, 'starts_on' => $calendar->periodStart($year), 'ends_on' => $calendar->periodEnd($year)], array_values(array_unique(array_map('intval', $years))));
    }

    public function periodsForDates(int $supplierId, string $from, string $to): array
    {
        if ($from > $to) $this->unsupported();
        $calendar = (new AccountingPeriodRepository($this->db))->fiscalCalendar($supplierId);
        return $this->periodsForYears($supplierId, range($calendar->fiscalYearOfDate($from), $calendar->fiscalYearOfDate($to)));
    }

    public function writeCz500Periods(int $supplierId, array $periods, callable $write): mixed
    {
        if ($periods === []) $this->unsupported();
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $periods, $write): mixed {
            $this->assertCz500Periods($supplierId, $periods, true);
            return $write();
        });
    }

    private function unsupported(): never
    {
        throw new AccountingFrameworkException('accounting_framework_import_unsupported',
            'Převod účetnictví je podporován pouze pro období vedená celé v rámci vyhlášky 500.', 409);
    }
}
