<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRunRepository;

/**
 * Překlopení mzdového běhu do `paid` ze SKUTEČNOSTI, ne z prohlášení.
 *
 * Úhrada není rozhodnutí účetní — buď peníze odešly, nebo ne. Tlačítko
 * „Označit za uhrazené" bylo ceremonie: účetní musela potvrdit něco, co
 * aplikace zjistí sama z platebního ledgeru, a dokud to ručně nespárovala,
 * držel `mark_paid` i uzávěrku běhu. Platební příkaz odchází hned, ABO výpis
 * dorazí o měsíc později — čekání na kliknutí mezitím blokovalo `close`.
 *
 * Tahle služba se proto pouští VŽDY, když se platebním ledgerem něco pohne
 * (spárování úhrady, spárování příchozí vratky, materializace závazků). Když
 * je pokrytí úplné, sama zavolá `mark_paid`. Když úplné není, neudělá nic —
 * `mark_paid` zůstává fail-closed a nikdo ho neobchází.
 *
 * Nikdy neshodí volajícího: párování úhrady je platební skutečnost a musí se
 * uložit i tehdy, když překlopení stavu narazí na souběh nebo na uzavřený rok.
 * Neúspěch se vrátí ve výsledku a příští pohyb v ledgeru ho zkusí znovu.
 */
final class PayrollRunAutoSettlementService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRunRepository $runs,
        private readonly PayrollRunPaymentSettlementService $settlement,
        private readonly PayrollRunCommandService $commands,
    ) {}

    /**
     * Běh, do kterého závazek patří. Volá se po spárování úhrady, kde je
     * po ruce jen `liability_id`.
     */
    public function settleForLiability(
        int $supplierId,
        int $liabilityId,
        int $actorUserId,
    ): PayrollRunAutoSettlementResult {
        $runId = $this->runIdForLiability($supplierId, $liabilityId);
        if ($runId === null) {
            return PayrollRunAutoSettlementResult::skipped('liability_without_run');
        }

        return $this->settleRun($supplierId, $runId, $actorUserId);
    }

    /**
     * Běh, do kterého alokace platební dávky patří. Volá se po spárování
     * odchozí úhrady, kde je po ruce jen `allocation_id`.
     */
    public function settleForAllocation(
        int $supplierId,
        int $allocationId,
        int $actorUserId,
    ): PayrollRunAutoSettlementResult {
        $runId = $this->runIdForAllocation($supplierId, $allocationId);
        if ($runId === null) {
            return PayrollRunAutoSettlementResult::skipped('allocation_without_run');
        }

        return $this->settleRun($supplierId, $runId, $actorUserId);
    }

    /**
     * Konkrétní běh. Idempotentní: druhé volání nad už uhrazeným během
     * jen vrátí `already_paid`.
     */
    public function settleRun(
        int $supplierId,
        int $runId,
        int $actorUserId,
    ): PayrollRunAutoSettlementResult {
        if ($supplierId <= 0 || $runId <= 0) {
            return PayrollRunAutoSettlementResult::skipped('invalid_reference');
        }
        $run = $this->runs->find($supplierId, $runId);
        if ($run === null) {
            return PayrollRunAutoSettlementResult::skipped('run_not_found');
        }
        $status = (string) ($run['status'] ?? '');
        if ($status === PayrollRunStatus::PAID->value
            || $status === PayrollRunStatus::CLOSED->value
        ) {
            return PayrollRunAutoSettlementResult::skipped('already_paid');
        }
        if ($status !== PayrollRunStatus::PAYMENT_READY->value) {
            // Před `prepare_payments` není co pokrývat a po `close` už se
            // stav běhu nemění. Ledger se plní dál, jen bez přechodu.
            return PayrollRunAutoSettlementResult::skipped('not_payment_ready');
        }
        $revision = $this->runs->currentRevision($supplierId, $runId);
        if ($revision === null) {
            return PayrollRunAutoSettlementResult::skipped('revision_missing');
        }

        try {
            $coverage = $this->settlement->inspect(
                $supplierId,
                (int) $revision['id'],
            );
        } catch (\Throwable) {
            return PayrollRunAutoSettlementResult::skipped('coverage_unavailable');
        }
        if ($coverage['uncovered'] !== []) {
            return PayrollRunAutoSettlementResult::pending($coverage);
        }

        $rowVersion = (int) ($run['row_version'] ?? 0);
        if ($rowVersion <= 0) {
            return PayrollRunAutoSettlementResult::skipped('row_version_missing');
        }
        try {
            $this->commands->markPaid(
                $supplierId,
                $runId,
                $rowVersion,
                self::idempotencyKey($supplierId, $runId, (int) $revision['id']),
                $actorUserId,
            );
        } catch (\Throwable) {
            /*
             * Souběh (jiný uživatel mezitím běh posunul), uzavřený rok nebo
             * nedostupná platební vrstva. Spárování se tím NERUŠÍ — jen se
             * neposunul stav. Další pohyb v ledgeru nebo ruční `mark_paid`
             * přes API to dožene.
             */
            return PayrollRunAutoSettlementResult::failed($coverage);
        }

        return PayrollRunAutoSettlementResult::settled($coverage);
    }

    /**
     * Přehled pokrytí pro obrazovku běhu. Čtecí, nic neposouvá — účetní
     * má vidět, kolik závazků je doložených a kolik čeká na výpis.
     *
     * @return array{
     *   liability_count:int,
     *   settled_count:int,
     *   uncovered_count:int,
     *   required_minor:int,
     *   settled_minor:int,
     *   uncovered_minor:int,
     *   incoming_unsettled_count:int
     * }|null
     */
    public function coverageSummary(int $supplierId, ?int $revisionId): ?array
    {
        if ($supplierId <= 0 || $revisionId === null || $revisionId <= 0) {
            return null;
        }
        try {
            $coverage = $this->settlement->inspect($supplierId, $revisionId);
        } catch (\Throwable) {
            return null;
        }
        if ($coverage['liability_count'] === 0) {
            return null;
        }
        $uncoveredCount = count($coverage['uncovered']);

        return [
            'liability_count' => $coverage['liability_count'],
            'settled_count' => $coverage['liability_count'] - $uncoveredCount,
            'uncovered_count' => $uncoveredCount,
            'required_minor' => $coverage['required_minor'],
            'settled_minor' => $coverage['settled_minor'],
            'uncovered_minor' => $coverage['required_minor']
                - $coverage['settled_minor'],
            'incoming_unsettled_count' => $coverage['incoming_unsettled_count'],
        ];
    }

    private function runIdForAllocation(int $supplierId, int $allocationId): ?int
    {
        if ($supplierId <= 0 || $allocationId <= 0) {
            return null;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.run_id
               FROM payroll_payment_allocations allocation
               JOIN payroll_payment_liabilities liability
                 ON liability.supplier_id = allocation.supplier_id
                AND liability.id = allocation.liability_id
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
              WHERE allocation.supplier_id = ? AND allocation.id = ?'
        );
        $statement->execute([$supplierId, $allocationId]);
        $runId = $statement->fetchColumn();

        return is_numeric($runId) && (int) $runId > 0 ? (int) $runId : null;
    }

    private function runIdForLiability(int $supplierId, int $liabilityId): ?int
    {
        if ($supplierId <= 0 || $liabilityId <= 0) {
            return null;
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.run_id
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
              WHERE liability.supplier_id = ? AND liability.id = ?'
        );
        $statement->execute([$supplierId, $liabilityId]);
        $runId = $statement->fetchColumn();

        return is_numeric($runId) && (int) $runId > 0 ? (int) $runId : null;
    }

    /**
     * Klíč je odvozený od revize, ne od času: opakované pokusy o totéž
     * překlopení se v `payroll_run_command_receipts` potkají a druhý z nich
     * je replay, ne druhý přechod.
     */
    private static function idempotencyKey(
        int $supplierId,
        int $runId,
        int $revisionId,
    ): string {
        return sprintf(
            'payroll-auto-settle:%d:%d:%d',
            $supplierId,
            $runId,
            $revisionId,
        );
    }
}
