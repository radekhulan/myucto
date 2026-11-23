<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;

/**
 * Je údaj, na kterém se hlášení zastavilo, MEZITÍM opravený?
 *
 * ── Proč to tady je ─────────────────────────────────────────────────────────
 * Nález čtený ze schváleného běhu ({@see \MyInvoice\Service\Payroll\Run\PayrollRunReadinessImpact::jmhzBlockerNeedsRevision()})
 * trvá i po opravě údaje: hlášení čte zmrazený snímek revize. Účetní údaj
 * opraví, příprava vrátí tutéž hlášku a nejde poznat, jestli se oprava
 * neuložila, nebo jen chybí opravná revize.
 *
 * Tahle služba to rozliší: postaví nasucho snímek vstupů z AKTUÁLNÍCH údajů
 * (tentýž, který by vznikl při opravě běhu) a zeptá se na nálezy stejným
 * pravidlem jako kontrola před během ({@see JmhzPreparationSnapshotService::probeIssues()}).
 * Nález, který schválený běh nese, ale aktuální údaje už ne, je „opraveno,
 * zbývá opravná revize".
 *
 * Odpovídá jen u kódů, které pravidlo kontroly před během umí posoudit
 * ({@see JmhzPreparationSnapshotService::probedFrozenCodes()}); u ostatních
 * vrací `null` (neví) a nic netvrdí. Nic nezapisuje a selhání nesmí zastavit
 * hlášku o blokaci: pak také vrací `null`.
 */
final readonly class JmhzFrozenSourceAdvisor
{
    public function __construct(
        private Connection $db,
        private PayrollRunSnapshotBuilder $snapshots,
        private JmhzPreparationSnapshotService $preparations,
    ) {}

    /**
     * Doplní k nálezům `fixed_in_source`: `true` = aktuální údaje jsou už
     * v pořádku a stačí opravná revize, `false` = údaj je potřeba nejdřív
     * opravit, `null` = nejde posoudit nebo se nálezu netýká.
     *
     * @param list<array<string,mixed>> $blockers nálezy s `needs_revision`
     *        ({@see JmhzBlockerRemediationPath::payload()})
     * @return list<array<string,mixed>>
     */
    public function annotate(int $supplierId, int $runId, array $blockers): array
    {
        $live = null;
        foreach ($blockers as $blocker) {
            if (($blocker['needs_revision'] ?? false) === true) {
                $live = $this->liveIssues($supplierId, $runId);
                break;
            }
        }

        return array_map(
            static function (array $blocker) use ($live): array {
                $fixed = null;
                $code = (string) ($blocker['code'] ?? '');
                if ($live !== null
                    && ($blocker['needs_revision'] ?? false) === true
                    && is_int($blocker['entity_id'] ?? null)
                    && in_array($code, $live['codes'], true)
                ) {
                    $fixed = !isset($live['issues'][self::key(
                        $code,
                        (string) ($blocker['entity_type'] ?? ''),
                        $blocker['entity_id'],
                    )]);
                }

                return $blocker + ['fixed_in_source' => $fixed];
            },
            $blockers,
        );
    }

    /**
     * @return array{codes:list<string>,issues:array<string,true>}|null
     */
    private function liveIssues(int $supplierId, int $runId): ?array
    {
        try {
            $statement = $this->db->pdo()->prepare(
                'SELECT period_start, payment_date, office_id
                   FROM payroll_runs
                  WHERE supplier_id = ? AND id = ?',
            );
            $statement->execute([$supplierId, $runId]);
            $run = $statement->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($run)) {
                return null;
            }
            $periodStart = (string) $run['period_start'];
            $snapshot = $this->snapshots->build(
                $supplierId,
                $periodStart,
                (string) $run['payment_date'],
                $run['office_id'] === null ? null : (int) $run['office_id'],
            );
            $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
            $issues = [];
            foreach ($this->preparations->probeIssues($supplierId, 'production', $periodEnd, $snapshot->data, true) as $issue) {
                if (is_int($issue['entity_id'])) {
                    $issues[self::key($issue['code'], $issue['entity_type'], $issue['entity_id'])] = true;
                }
            }

            return [
                'codes' => JmhzPreparationSnapshotService::probedFrozenCodes($periodStart),
                'issues' => $issues,
            ];
        } catch (\Throwable) {
            // Rada navíc nesmí shodit hlášku o blokaci; bez ní zůstává obecná věta.
            return null;
        }
    }

    private static function key(string $code, string $entityType, int $entityId): string
    {
        // Sestavení hlášení váže nález na osobu jako `person`, snímek vstupů jako `employee`.
        return $code . '|' . ($entityType === 'employee' ? 'person' : $entityType) . '|' . $entityId;
    }
}
