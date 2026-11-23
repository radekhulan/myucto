<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Společná pravidla hromadných úloh účtování (`import_jobs` s `params.kind`
 * dry_run / apply / rollback): kdy jde běh nanečisto provést, kdy ostrý běh vrátit
 * a jak úloha vypadá pro obrazovku. Sdílí je hromadné přeúčtování i bankovní pohyby
 * podle filtru, aby se pravidlo „jeden ostrý běh na běh nanečisto, jedno vrácení na
 * ostrý běh" nerozešlo mezi dvěma kopiemi.
 */
final class AccountingBulkJobs
{
    /**
     * @param array<string,mixed> $job
     * @param list<array<string,mixed>> $all ostatní běhy téže úlohy ve firmě
     */
    public static function canApply(array $job, array $all): bool
    {
        if (self::kind($job) !== 'dry_run'
            || !in_array($job['status'], ['completed', 'completed_with_warnings'], true)
            || (int) $job['created_count'] === 0) {
            return false;
        }
        return self::childOf($job, $all, 'apply', 'dry_run_job_id') === null;
    }

    /**
     * @param array<string,mixed> $job
     * @param list<array<string,mixed>> $all
     */
    public static function canRollback(array $job, array $all): bool
    {
        if (self::kind($job) !== 'apply'
            || !in_array($job['status'], ['completed', 'completed_with_warnings', 'cancelled'], true)
            || (int) $job['created_count'] === 0) {
            return false;
        }
        return self::childOf($job, $all, 'rollback', 'rollback_of_job_id') === null;
    }

    /**
     * Navazující běh (ostrý k běhu nanečisto, vrácení k ostrému). Neúspěšný se nepočítá,
     * takže po pádu jde krok zopakovat.
     *
     * @param array<string,mixed> $job
     * @param list<array<string,mixed>> $all
     * @return ?array<string,mixed>
     */
    public static function childOf(array $job, array $all, string $kind, string $key): ?array
    {
        foreach ($all as $other) {
            if (self::kind($other) === $kind
                && (int) (($other['params'] ?? [])[$key] ?? 0) === (int) $job['id']
                && $other['status'] !== 'failed') {
                return $other;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $j
     * @param list<array<string,mixed>> $all
     * @param list<string> $paramKeys parametry filtru, které obrazovka zobrazuje
     * @return array<string,mixed>
     */
    public static function view(array $j, array $all, bool $canRun, array $paramKeys): array
    {
        $params = (array) ($j['params'] ?? []);
        $applied = self::childOf($j, $all, 'apply', 'dry_run_job_id');
        $rolledBack = self::childOf($j, $all, 'rollback', 'rollback_of_job_id');

        return [
            'id'                    => (int) $j['id'],
            'kind'                  => self::kind($j),
            'status'                => (string) $j['status'],
            'total_items'           => $j['total_items'] !== null ? (int) $j['total_items'] : null,
            'processed'             => (int) $j['processed'],
            'changed_count'         => (int) $j['created_count'],
            'skipped_count'         => (int) $j['skipped_count'],
            'failed_count'          => (int) $j['failed_count'],
            'current_step'          => $j['current_step'] ?? null,
            'last_error'            => $j['last_error'] ?? null,
            'created_at'            => $j['created_at'] ?? null,
            'finished_at'           => $j['finished_at'] ?? null,
            'params'                => array_intersect_key($params, array_flip($paramKeys)),
            'dry_run_job_id'        => isset($params['dry_run_job_id']) ? (int) $params['dry_run_job_id'] : null,
            'rollback_of_job_id'    => isset($params['rollback_of_job_id']) ? (int) $params['rollback_of_job_id'] : null,
            'applied_by_job_id'     => $applied === null ? null : (int) $applied['id'],
            'rolled_back_by_job_id' => $rolledBack === null ? null : (int) $rolledBack['id'],
            'can_apply'             => $canRun && self::canApply($j, $all),
            'can_rollback'          => $canRun && self::canRollback($j, $all),
        ];
    }

    /** @param array<string,mixed> $job */
    private static function kind(array $job): string
    {
        return (string) (($job['params'] ?? [])['kind'] ?? 'dry_run');
    }
}
