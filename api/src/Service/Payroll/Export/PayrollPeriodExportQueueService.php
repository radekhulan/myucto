<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Export;

use MyInvoice\Repository\Payroll\PayrollPeriodExportJobRepository;

final class PayrollPeriodExportQueueService
{
    public function __construct(
        private readonly PayrollPeriodExportJobRepository $jobs,
        private readonly PayrollPeriodExportService $exports,
    ) {}

    /** @return array<string,mixed> */
    public function enqueueMonthly(int $supplierId, string $period, int $userId): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new \InvalidArgumentException('Měsíční export vyžaduje období ve tvaru RRRR-MM.');
        }
        return $this->enqueue(
            $supplierId,
            PayrollPeriodExportScope::Monthly,
            $period . '-01',
            (new \DateTimeImmutable($period . '-01'))->modify('last day of this month')->format('Y-m-d'),
            $userId,
        );
    }

    /** @return array<string,mixed> */
    public function enqueueAnnual(int $supplierId, int $year, int $userId): array
    {
        if ($year < 2000 || $year > 2199) {
            throw new \InvalidArgumentException('Rok exportu mezd není platný.');
        }
        return $this->enqueue(
            $supplierId,
            PayrollPeriodExportScope::Annual,
            sprintf('%04d-01-01', $year),
            sprintf('%04d-12-31', $year),
            $userId,
        );
    }

    /** @return array<string,mixed>|null */
    public function detail(int $supplierId, int $jobId): ?array
    {
        return $this->jobs->detail($supplierId, $jobId);
    }

    /** @return array{processed:bool,succeeded:bool|null,job_id:?int} */
    public function processOne(): array
    {
        $claim = $this->jobs->claimNext();
        if ($claim === null) {
            return ['processed' => false, 'succeeded' => null, 'job_id' => null];
        }
        try {
            $scope = PayrollPeriodExportScope::from((string) $claim['export_scope']);
            $export = $scope === PayrollPeriodExportScope::Monthly
                ? $this->exports->createMonthly(
                    (int) $claim['supplier_id'],
                    substr((string) $claim['period_start'], 0, 7),
                    $this->requestedBy($claim),
                )
                : $this->exports->createAnnual(
                    (int) $claim['supplier_id'],
                    (int) substr((string) $claim['period_start'], 0, 4),
                    $this->requestedBy($claim),
                );
            $this->jobs->complete($claim, (int) $export['id']);
            $succeeded = true;
        } catch (\Throwable $exception) {
            $this->jobs->fail($claim, self::errorCode($exception), $exception->getMessage());
            $succeeded = false;
        }

        return ['processed' => true, 'succeeded' => $succeeded, 'job_id' => (int) $claim['id']];
    }

    /** @return array{processed:int,succeeded:int,failed:int} */
    public function processAvailable(int $limit = 1): array
    {
        $result = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];
        for ($index = 0; $index < max(1, min(20, $limit)); ++$index) {
            $item = $this->processOne();
            if (!$item['processed']) {
                break;
            }
            ++$result['processed'];
            $item['succeeded'] === true ? ++$result['succeeded'] : ++$result['failed'];
        }
        return $result;
    }

    /** @param array<string,mixed> $claim */
    private function requestedBy(array $claim): ?int
    {
        if (!array_key_exists('requested_by', $claim)) {
            throw new \UnexpectedValueException('Job exportu mezd nemá autora.');
        }
        $requestedBy = $claim['requested_by'];
        if ($requestedBy === null) {
            return null;
        }
        if (!is_int($requestedBy) && !is_string($requestedBy)) {
            throw new \UnexpectedValueException('Job exportu mezd nemá autora.');
        }
        $userId = filter_var($requestedBy, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($userId)) {
            throw new \UnexpectedValueException('Job exportu mezd nemá platného autora.');
        }
        return $userId;
    }

    /** @return array<string,mixed> */
    private function enqueue(
        int $supplierId,
        PayrollPeriodExportScope $scope,
        string $periodStart,
        string $periodEnd,
        int $userId,
    ): array {
        if ($supplierId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('Firma a uživatel exportu musí být kladná čísla.');
        }
        return $this->jobs->enqueue($supplierId, $scope->value, $periodStart, $periodEnd, $userId);
    }

    private static function errorCode(\Throwable $exception): string
    {
        $name = (new \ReflectionClass($exception))->getShortName();
        $normalized = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
        return substr('export_' . $normalized, 0, 64);
    }
}
