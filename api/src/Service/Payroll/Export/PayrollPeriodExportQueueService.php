<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Export;

use MyInvoice\Repository\Payroll\PayrollPeriodExportJobRepository;
use MyInvoice\Repository\Payroll\PayrollPeriodExportPlanChangedException;

final class PayrollPeriodExportQueueService
{
    /** Stropy doběhnutí jednoho spuštění workeru — pojistka proti zacyklení. */
    public const DRAIN_MAX_ITERATIONS = 200;
    public const DRAIN_MAX_SECONDS = 240;

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

    /** @return array{planned:bool,total:?int,completed:int,failed:int,pending:int,current_part_kind:?string} */
    public function progress(int $supplierId, int $jobId): array
    {
        return $this->jobs->progress($supplierId, $jobId);
    }

    /**
     * Doběhne frontu do konce, ne jen jednu část.
     *
     * `processOne()` udělá vždy JEDNU část, takže archiv za měsíc se přes
     * `processAvailable(1)` skládal tempem jedné části za cron tick. Spuštění
     * z aplikace proto frontu drainuje — se stropem na počet iterací i na dobu
     * běhu, aby se worker nemohl zacyklit na jobu, který se opakovaně vrací do
     * fronty. S cílovým jobem se navíc končí, jakmile doběhne on sám; zbytek
     * fronty patří cronu.
     *
     * @return array{processed:int,succeeded:int,failed:int,iterations:int,stopped:string}
     */
    public function drain(
        ?int $supplierId = null,
        ?int $jobId = null,
        int $maxIterations = self::DRAIN_MAX_ITERATIONS,
        int $maxSeconds = self::DRAIN_MAX_SECONDS,
    ): array {
        $result = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'iterations' => 0, 'stopped' => 'idle'];
        $iterationCap = max(1, min(1000, $maxIterations));
        $deadline = microtime(true) + (float) max(1, min(900, $maxSeconds));
        $targeted = $supplierId !== null && $jobId !== null;
        while (true) {
            if ($result['iterations'] >= $iterationCap) {
                $result['stopped'] = 'iteration_limit';
                break;
            }
            if (microtime(true) >= $deadline) {
                $result['stopped'] = 'time_limit';
                break;
            }
            $item = $this->processOne();
            ++$result['iterations'];
            if (!$item['processed']) {
                $result['stopped'] = 'idle';
                break;
            }
            ++$result['processed'];
            $item['succeeded'] === true ? ++$result['succeeded'] : ++$result['failed'];
            if (!$targeted) {
                continue;
            }
            $detail = $this->jobs->detail($supplierId, $jobId);
            if ($detail === null
                || in_array((string) $detail['status'], ['completed', 'failed'], true)
            ) {
                $result['stopped'] = 'job_finished';
                break;
            }
        }

        return $result;
    }

    /** @return array{processed:bool,succeeded:bool|null,job_id:?int} */
    public function processOne(): array
    {
        $claim = $this->jobs->claimNext();
        if ($claim === null) {
            return ['processed' => false, 'succeeded' => null, 'job_id' => null];
        }
        $part = null;
        try {
            $scope = PayrollPeriodExportScope::from((string) $claim['export_scope']);
            $this->jobs->ensureParts($claim, $this->exports->partPlan(
                (int) $claim['supplier_id'],
                $scope,
                (string) $claim['period_start'],
                (string) $claim['period_end'],
            ));
            $part = $this->jobs->claimPart($claim);
            if ($part === null) {
                $detail = $this->jobs->detail((int) $claim['supplier_id'], (int) $claim['id']);
                if (($detail['status'] ?? null) === 'failed') {
                    return ['processed' => true, 'succeeded' => false, 'job_id' => (int) $claim['id']];
                }
                throw new \RuntimeException('Export mezd nemá dostupnou část k dokončení.');
            }
            if ((string) $part['part_kind'] === 'archive') {
                $export = $this->exports->createFromCompletedParts(
                    (int) $claim['supplier_id'],
                    $scope,
                    (string) $claim['period_start'],
                    (string) $claim['period_end'],
                    $this->requestedBy($claim),
                    $this->jobs->completedParts($claim),
                );
                $this->jobs->completeArchivePartAndJob($claim, $part, (int) $export['id']);
            } else {
                $storageKey = $this->exports->materializePart(
                    (int) $claim['supplier_id'],
                    $scope,
                    (string) $claim['period_start'],
                    (string) $claim['period_end'],
                    $part,
                );
                if (!is_string($storageKey)) {
                    throw new \UnexpectedValueException('Binární část exportu nemá uložený otisk.');
                }
                $this->jobs->completePartAndRelease($claim, $part, $storageKey);
            }
            $succeeded = true;
        } catch (\Throwable $exception) {
            if (is_array($part)) {
                $failed = $this->jobs->failPartAndRelease(
                    $claim,
                    $part,
                    self::errorCode($exception),
                    $exception->getMessage(),
                );
                $succeeded = $failed === null;
            } else {
                // Zmeneny plan se opakovanim nespravi - obsah obdobi je jiny,
                // nez ze ktereho se export zacal skladat. Job se proto zavira
                // rovnou, at uzivatel neceka na tri marne pokusy.
                $this->jobs->fail(
                    $claim,
                    self::errorCode($exception),
                    $exception->getMessage(),
                    !$exception instanceof PayrollPeriodExportPlanChangedException,
                );
                $succeeded = false;
            }
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
