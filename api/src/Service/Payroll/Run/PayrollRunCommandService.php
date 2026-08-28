<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRunConflictException;
use MyInvoice\Repository\Payroll\PayrollRunDeletionException;
use MyInvoice\Repository\Payroll\PayrollRunIdempotencyException;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Service\Payroll\PayrollModuleActivationService;
use MyInvoice\Service\Payroll\PayrollPeriodOwnershipService;
use MyInvoice\Service\Payroll\PayrollYearCloseGuard;
use MyInvoice\Service\Payroll\Document\ApprovedRevisionPayslipBatchService;
use MyInvoice\Service\Payroll\Document\PayrollDocumentBatchQueueService;
use MyInvoice\Service\Payroll\ControlTotals\PayrollControlTotalsService;
use MyInvoice\Service\Payroll\Posting\PayrollApprovedRevisionPostingService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

final class PayrollRunCommandService
{
    private const COMMAND_SAVEPOINT = 'payroll_run_command';
    private const DELETE_SAVEPOINT = 'payroll_run_delete';
    private readonly PayrollYearCloseGuard $yearClose;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRunRepository $runs,
        private readonly PayrollRunSnapshotBuilder $snapshotBuilder,
        private readonly PayrollRunCalculationPipeline $calculationPipeline,
        private readonly PayrollRunWorkflow $workflow,
        private readonly PayrollPeriodOwnershipService $ownership,
        private readonly ?PayrollApprovedRevisionPostingService
            $approvedPosting = null,
        ?ApprovedRevisionPayslipBatchService $approvedPayslips = null,
        private readonly ?PayrollControlTotalsService
            $controlTotals = null,
        private readonly ?PayrollRunPaymentPreparationService
            $paymentPreparation = null,
        private readonly ?PayrollRunPaymentSettlementService
            $paymentSettlement = null,
        private readonly ?PayrollModuleActivationService
            $moduleActivation = null,
        private readonly ?PayrollDocumentBatchQueueService
            $documentQueue = null,
    ) {
        $this->yearClose = new PayrollYearCloseGuard($db);
    }

    /** @return array<string,mixed> */
    public function createRun(
        int $supplierId,
        string $periodStart,
        string $paymentDate,
        ?int $officeId,
        int $actorUserId,
    ): array {
        $this->assertActor($actorUserId);
        $period = $this->period($periodStart);
        $this->paymentDate($paymentDate, $period);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->assertModuleAvailable($supplierId, $periodStart);
            $this->assertYearOpen($supplierId, $periodStart);
            $run = $this->runs->createOrGet(
                $supplierId,
                $periodStart,
                $paymentDate,
                $officeId,
                $actorUserId,
            );
            $this->ownership->claimPayroll(
                $supplierId,
                (int) $period->format('Y'),
                (int) $period->format('m'),
                'payroll_run',
                (int) $run['id'],
                $actorUserId,
            );
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $run;
        } catch (\Throwable $e) {
            $this->rollbackOwnedTransaction($pdo, $ownsTransaction);
            throw $e;
        }
    }

    public function lockInputs(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::LOCK_INPUTS,
            $idempotencyKey,
            $actorUserId,
        );
    }

    public function calculate(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::CALCULATE,
            $idempotencyKey,
            $actorUserId,
        );
    }

    public function review(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::REVIEW,
            $idempotencyKey,
            $actorUserId,
        );
    }

    public function approve(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::APPROVE,
            $idempotencyKey,
            $actorUserId,
        );
    }

    public function post(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::POST,
            $idempotencyKey,
            $actorUserId,
        );
    }

    public function preparePayments(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::PREPARE_PAYMENTS,
            $idempotencyKey,
            $actorUserId,
        );
    }

    public function markPaid(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::MARK_PAID,
            $idempotencyKey,
            $actorUserId,
        );
    }

    public function requestCorrection(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
        string $reason,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::REQUEST_CORRECTION,
            $idempotencyKey,
            $actorUserId,
            $reason,
        );
    }

    public function reopen(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
        string $reason,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::REOPEN,
            $idempotencyKey,
            $actorUserId,
            $reason,
        );
    }

    public function cancel(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
        string $reason,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::CANCEL,
            $idempotencyKey,
            $actorUserId,
            $reason,
        );
    }

    public function close(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
    ): PayrollRunCommandResult {
        return $this->execute(
            $supplierId,
            $runId,
            $expectedVersion,
            PayrollRunCommand::CLOSE,
            $idempotencyKey,
            $actorUserId,
        );
    }

    public function deleteRun(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        int $actorUserId,
    ): void {
        $this->assertActor($actorUserId);
        if ($supplierId <= 0 || $runId <= 0 || $expectedVersion <= 0) {
            throw new \InvalidArgumentException(
                'Identifikace mazaného mzdového běhu není platná.',
            );
        }

        $pdo = $this->db->pdo();
        $nestedTransaction = $pdo->inTransaction();
        if ($nestedTransaction) {
            $pdo->exec('SAVEPOINT ' . self::DELETE_SAVEPOINT);
        } else {
            $pdo->beginTransaction();
        }
        try {
            $run = $this->runs->lock($supplierId, $runId);
            if ($run === null) {
                throw new \OutOfBoundsException('Mzdový běh nebyl nalezen.');
            }
            $this->assertModuleAvailable(
                $supplierId,
                (string) $run['period_start'],
            );
            $this->assertYearOpen($supplierId, (string) $run['period_start']);
            $currentVersion = (int) $run['row_version'];
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollRunConflictException($currentVersion);
            }

            $decision = $this->runs->canDelete($supplierId, $runId);
            if ($decision === null) {
                throw new \OutOfBoundsException('Mzdový běh nebyl nalezen.');
            }
            if (!$decision->canDelete) {
                throw new PayrollRunDeletionException(
                    (string) $decision->blockerCode,
                    (string) $decision->blockerMessage,
                );
            }
            $eventId = $decision->createdEventId
                ?? throw new \LogicException('Chybí počáteční audit mzdového běhu.');

            $this->runs->enableEmptyRunDeleteGuard(
                $supplierId,
                $runId,
                $expectedVersion,
                $eventId,
                $decision->cancelEventId,
                $decision->cancelCommandId,
            );
            try {
                if ($decision->cancelEventId !== null
                    && $decision->cancelCommandId !== null
                ) {
                    if (!$this->runs->deleteCanonicalCancelEvent(
                        $supplierId,
                        $runId,
                        $expectedVersion,
                        $eventId,
                        $decision->cancelEventId,
                        $decision->cancelCommandId,
                    )) {
                        throw new PayrollRunDeletionException(
                            'payroll_run_delete_conflict',
                            'Událost zrušení mzdového běhu se nepodařilo bezpečně odstranit.',
                        );
                    }
                    if (!$this->runs->deleteCanonicalCancelCommand(
                        $supplierId,
                        $runId,
                        $expectedVersion,
                        $eventId,
                        $decision->cancelCommandId,
                    )) {
                        throw new PayrollRunDeletionException(
                            'payroll_run_delete_conflict',
                            'Příkaz zrušení mzdového běhu se nepodařilo bezpečně odstranit.',
                        );
                    }
                }
                if (!$this->runs->deleteInitialEvent(
                    $supplierId,
                    $runId,
                    $expectedVersion,
                    $eventId,
                )) {
                    throw new PayrollRunDeletionException(
                        'payroll_run_delete_conflict',
                        'Mzdový běh se nepodařilo bezpečně připravit ke smazání.',
                    );
                }
                if ($decision->ownsPeriod) {
                    $this->runs->transferOrReleasePeriodOwnership(
                        $supplierId,
                        (string) $run['period_start'],
                        $runId,
                        $decision->replacementOwnerRunId,
                    );
                }
                if (!$this->runs->deleteEmptyRunRow(
                    $supplierId,
                    $runId,
                    $expectedVersion,
                )) {
                    throw new PayrollRunDeletionException(
                        'payroll_run_delete_conflict',
                        'Mzdový běh se nepodařilo bezpečně smazat.',
                    );
                }
            } finally {
                $this->runs->clearEmptyRunDeleteGuard();
            }

            $this->finishDeleteTransaction($pdo, $nestedTransaction);
        } catch (\Throwable $e) {
            $this->rollbackDeleteTransaction($pdo, $nestedTransaction);
            throw $e;
        }
    }

    private function execute(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        PayrollRunCommand $command,
        string $idempotencyKey,
        int $actorUserId,
        ?string $reason = null,
    ): PayrollRunCommandResult {
        $this->assertActor($actorUserId);
        if ($supplierId <= 0 || $runId <= 0 || $expectedVersion <= 0) {
            throw new \InvalidArgumentException('Identifikace mzdového příkazu není platná.');
        }
        $normalizedKey = trim($idempotencyKey);
        if (mb_strlen($normalizedKey) < 8 || mb_strlen($normalizedKey) > 190) {
            throw new \InvalidArgumentException(
                'Idempotency key musí mít 8 až 190 znaků.',
            );
        }
        $reason = $reason === null ? null : trim($reason);
        $keyHashBinary = hash('sha256', $normalizedKey, true);
        $keyHashHex = hash('sha256', $normalizedKey);
        $requestHash = hash('sha256', CanonicalJson::encode([
            'actor_user_id' => $actorUserId,
            'command' => $command->value,
            'expected_row_version' => $expectedVersion,
            'reason' => $reason,
            'run_id' => $runId,
            'supplier_id' => $supplierId,
        ]));

        $pdo = $this->db->pdo();
        $nestedTransaction = $pdo->inTransaction();
        if ($nestedTransaction) {
            $pdo->exec('SAVEPOINT ' . self::COMMAND_SAVEPOINT);
        } else {
            $pdo->beginTransaction();
        }
        try {
            $run = $this->runs->lock($supplierId, $runId);
            if ($run === null) {
                throw new \OutOfBoundsException('Mzdový běh nebyl nalezen.');
            }

            $receipt = $this->runs->commandReceipt($supplierId, $keyHashBinary);
            if ($receipt !== null) {
                $result = $this->replay(
                    $supplierId,
                    $runId,
                    $command,
                    $requestHash,
                    $receipt,
                );
                $this->finishCommandTransaction($pdo, $nestedTransaction);
                return $result;
            }

            $this->assertModuleAvailable(
                $supplierId,
                (string) $run['period_start'],
            );
            $this->assertYearOpen($supplierId, (string) $run['period_start']);
            $currentVersion = (int) $run['row_version'];
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollRunConflictException($currentVersion);
            }
            $from = PayrollRunStatus::from((string) $run['status']);
            $approvedBaseline = $command === PayrollRunCommand::REOPEN
                && $from === PayrollRunStatus::CANCELLED
                    ? $this->runs->latestApprovedRevision($supplierId, $runId)
                    : null;
            $reopenAsCorrection = $command === PayrollRunCommand::REOPEN
                && ($from === PayrollRunStatus::CORRECTION_PENDING
                    || $approvedBaseline !== null);
            $revision = $this->runs->currentRevision($supplierId, $runId);
            $snapshot = null;
            if (in_array($command, [
                PayrollRunCommand::LOCK_INPUTS,
                PayrollRunCommand::REOPEN,
            ], true)) {
                $snapshot = $this->snapshotBuilder->build(
                    $supplierId,
                    (string) $run['period_start'],
                    (string) $run['payment_date'],
                    $run['office_id'] === null ? null : (int) $run['office_id'],
                );
                if ($reopenAsCorrection) {
                    $snapshot = $this->calculationPipeline
                        ->prepareCorrectionSnapshot(
                            $supplierId,
                            $runId,
                            $snapshot,
                        );
                }
            }
            $counts = $revision === null
                ? ['blockers' => 0, 'unresolved_overrides' => 0]
                : $this->runs->validationCounts(
                    $supplierId,
                    (int) $revision['id'],
                );
            // Účetní a platební brána se vyhodnocuje ze skutečnosti v databázi,
            // ne z přání volajícího. `post` navíc účetní dávku sám vytvoří,
            // takže musí proběhnout dřív, než workflow ověří svoji podmínku —
            // jinak by ruční zaúčtování nikdy neprošlo. Side effect pouštíme
            // až po ověření, že je příkaz v tomto stavu vůbec dostupný.
            $outcome = null;
            $commandAvailable = in_array(
                $command,
                $this->workflow->availableCommands($from),
                true,
            );
            if ($commandAvailable && $command === PayrollRunCommand::POST) {
                $outcome = $this->applyPosting(
                    $supplierId,
                    $revision,
                    $actorUserId,
                );
            }
            if ($commandAvailable && $command === PayrollRunCommand::MARK_PAID) {
                $outcome = $this->assertPaymentsSettled($supplierId, $revision);
            }
            $context = new PayrollRunTransitionContext(
                actorUserId: $actorUserId,
                calculatedBy: $revision['calculated_by'] ?? null,
                reviewedBy: $revision['reviewed_by'] ?? null,
                blockerCount: $counts['blockers'],
                unresolvedOverrideCount: $counts['unresolved_overrides'],
                hasImmutableSnapshot: $snapshot !== null || $revision !== null,
                hasCalculatedResult:
                    $revision !== null && $revision['result_snapshot_json'] !== null,
                hasPostingBatch: $outcome !== null && in_array(
                    $outcome->outcome,
                    [
                        PayrollRunCommandOutcome::POSTED,
                        PayrollRunCommandOutcome::ALREADY_POSTED,
                        // Daňová evidence účetní můstek nepoužívá. Podmínka je
                        // splněná tím, že zaúčtování na firmu nedopadá — běh
                        // se musí dostat dál, jen se nesmí tvářit zaúčtovaně.
                        PayrollRunCommandOutcome::POSTING_NOT_APPLICABLE,
                    ],
                    true,
                ),
                hasPaymentBatch: $outcome !== null && in_array(
                    $outcome->outcome,
                    [
                        PayrollRunCommandOutcome::PAYMENTS_SETTLED,
                        // Běh, kde není co platit (celá čistá mzda je zápočet
                        // na účet společníka), platební dávku nikdy mít nebude.
                        PayrollRunCommandOutcome::PAYMENTS_NOT_APPLICABLE,
                    ],
                    true,
                ),
                reason: $reason,
            );
            $transition = $this->workflow->transition($from, $command, $context);

            if ($command === PayrollRunCommand::LOCK_INPUTS
                || $command === PayrollRunCommand::REOPEN
            ) {
                $revisionNo = (int) $run['current_revision_no'] + 1;
                $previousRevisionId = $approvedBaseline !== null
                    ? (int) $approvedBaseline['id']
                    : ($revision === null ? null : (int) $revision['id']);
                $revisionId = $this->runs->insertRevision(
                    $supplierId,
                    $runId,
                    $revisionNo,
                    $previousRevisionId,
                    $reopenAsCorrection ? 'correction' : 'regular',
                    $snapshot,
                    $keyHashBinary,
                );
                $this->runs->insertSnapshotGraph(
                    $supplierId,
                    $revisionId,
                    $snapshot,
                );
                $this->runs->lockApprovedInputs(
                    $supplierId,
                    $revisionId,
                    (string) $run['period_start'],
                    $run['office_id'] === null ? null : (int) $run['office_id'],
                );
                $revision = $this->runs->revision($supplierId, $revisionId);
                $run = $this->runs->updateRun(
                    $supplierId,
                    $runId,
                    $expectedVersion,
                    $transition->to->value,
                    $revisionNo,
                    $actorUserId,
                );
            } elseif ($command === PayrollRunCommand::CALCULATE) {
                if ($revision === null
                    || !is_array($revision['input_snapshot'] ?? null)
                ) {
                    throw new \DomainException('Mzdový běh nemá vstupní snapshot.');
                }
                $calculation = $this->calculationPipeline->calculate(
                    $revision['input_snapshot'],
                    $supplierId,
                    (int) $revision['id'],
                    $actorUserId,
                );
                $this->runs->replaceEnforcementValidations(
                    $supplierId,
                    (int) $revision['id'],
                    $calculation,
                );
                $this->runs->replaceStatutoryValidations(
                    $supplierId,
                    (int) $revision['id'],
                    $calculation,
                );
                $this->runs->saveCalculation(
                    $supplierId,
                    (int) $revision['id'],
                    $calculation,
                    $actorUserId,
                );
                $revision = $this->runs->revision(
                    $supplierId,
                    (int) $revision['id'],
                );
                $run = $this->runs->updateRun(
                    $supplierId,
                    $runId,
                    $expectedVersion,
                    $transition->to->value,
                    null,
                    $actorUserId,
                );
            } elseif ($command === PayrollRunCommand::REVIEW) {
                if ($revision === null) {
                    throw new \DomainException('Mzdový běh nemá revizi.');
                }
                $this->runs->markRevisionReviewed(
                    $supplierId,
                    (int) $revision['id'],
                    $actorUserId,
                );
                $revision = $this->runs->revision(
                    $supplierId,
                    (int) $revision['id'],
                );
                $run = $this->runs->updateRun(
                    $supplierId,
                    $runId,
                    $expectedVersion,
                    $transition->to->value,
                    null,
                    $actorUserId,
                );
            } elseif ($command === PayrollRunCommand::APPROVE) {
                if ($revision === null) {
                    throw new \DomainException('Mzdový běh nemá revizi.');
                }
                // Kontrola bez pravidla čtyř očí je součást schválení, ne krok
                // před ním. Odklikávat ji zvlášť nemusí nikdo, ale STOPA po ní
                // zůstat musí: revize dostane `reviewed_by` i `reviewed_at` a
                // do historie běhu jde vlastní událost `review`, označená jako
                // implicitní. Kdo krok projde ručně, sem se vůbec nedostane.
                if (($revision['reviewed_by'] ?? null) === null) {
                    $this->runs->markRevisionReviewed(
                        $supplierId,
                        (int) $revision['id'],
                        $actorUserId,
                    );
                    $this->runs->insertEvent(
                        $supplierId,
                        $runId,
                        (int) $revision['id'],
                        PayrollRunCommand::REVIEW->value,
                        $transition->from->value,
                        PayrollRunStatus::REVIEWED->value,
                        $actorUserId,
                        null,
                        ['implicit' => true, 'reason' => 'approve_without_four_eyes'],
                    );
                    $revision = $this->runs->revision(
                        $supplierId,
                        (int) $revision['id'],
                    );
                }
                $resultSnapshot = self::snapshotObject(
                    $revision['result_snapshot'] ?? null,
                    'výsledný',
                );
                $inputSnapshot = self::snapshotObject(
                    $revision['input_snapshot'] ?? null,
                    'vstupní',
                );
                $this->calculationPipeline->storeApproved(
                    $supplierId,
                    (int) $revision['id'],
                    $resultSnapshot,
                );
                $this->runs->markRevisionApproved(
                    $supplierId,
                    (int) $revision['id'],
                    $actorUserId,
                );
                $this->controlTotals?->forApprovedRevision(
                    $supplierId,
                    (int) $revision['id'],
                );
                $this->calculationPipeline
                    ->storeApprovedStatutoryAccumulators(
                        $supplierId,
                        (int) $revision['id'],
                        $actorUserId,
                    );
                $this->calculationPipeline->storeApprovedDeductions(
                    $supplierId,
                    (int) $revision['id'],
                    $actorUserId,
                );
                $this->approvedPosting?->post(
                    $supplierId,
                    (int) $revision['id'],
                    $inputSnapshot,
                    $resultSnapshot,
                    $actorUserId,
                );
                $this->documentQueue?->enqueueApprovedRevision(
                    $supplierId,
                    $runId,
                    (int) $revision['id'],
                    $actorUserId,
                );
                // Druhá spoušť aktivace modulu: schválený mzdový běh je důkaz,
                // že nastavení je fakticky hotové. Idempotentní — druhé
                // schválení už stav nemění.
                $this->moduleActivation?->activateAfterApprovedRun(
                    $supplierId,
                    $actorUserId,
                );
                $revision = $this->runs->revision(
                    $supplierId,
                    (int) $revision['id'],
                );
                $run = $this->runs->updateRun(
                    $supplierId,
                    $runId,
                    $expectedVersion,
                    $transition->to->value,
                    null,
                    $actorUserId,
                );
            } elseif ($command === PayrollRunCommand::PREPARE_PAYMENTS) {
                if ($revision === null) {
                    throw new \DomainException('Mzdový běh nemá revizi.');
                }
                if ($this->paymentPreparation === null) {
                    throw new \DomainException(
                        'Příprava mzdových plateb není v této instalaci dostupná.',
                    );
                }
                $prepared = $this->paymentPreparation->prepare(
                    $supplierId,
                    (int) $revision['id'],
                    $actorUserId,
                    self::snapshotObject(
                        $revision['input_snapshot'] ?? null,
                        'vstupní',
                    ),
                );
                $outcome = new PayrollRunCommandOutcome(
                    $prepared['liability_ids'] === []
                        ? PayrollRunCommandOutcome::PAYMENTS_NOT_APPLICABLE
                        : PayrollRunCommandOutcome::PAYMENTS_PREPARED,
                    [
                        'created_count' => $prepared['created_count'],
                        'liability_count' => count($prepared['liability_ids']),
                    ],
                );
                $run = $this->runs->updateRun(
                    $supplierId,
                    $runId,
                    $expectedVersion,
                    $transition->to->value,
                    null,
                    $actorUserId,
                );
            } else {
                $run = $this->runs->updateRun(
                    $supplierId,
                    $runId,
                    $expectedVersion,
                    $transition->to->value,
                    null,
                    $actorUserId,
                );
            }

            $revisionId = $revision === null ? null : (int) $revision['id'];
            $resultPayload = [
                'run_id' => $runId,
                'revision_id' => $revisionId,
                'from_status' => $transition->from->value,
                'to_status' => $transition->to->value,
                'row_version' => (int) $run['row_version'],
                'outcome' => $outcome?->toArray(),
            ];
            $this->runs->insertEvent(
                $supplierId,
                $runId,
                $revisionId,
                $command->value,
                $transition->from->value,
                $transition->to->value,
                $actorUserId,
                $reason,
                [
                    'idempotency_key_hash' => $keyHashHex,
                    'request_hash' => $requestHash,
                    'row_version' => (int) $run['row_version'],
                ],
            );
            $this->runs->insertCommandReceipt(
                $supplierId,
                $runId,
                $revisionId,
                $command->value,
                $keyHashBinary,
                $requestHash,
                $expectedVersion,
                $transition->from->value,
                $transition->to->value,
                $resultPayload,
                $actorUserId,
            );
            $this->finishCommandTransaction($pdo, $nestedTransaction);
            return new PayrollRunCommandResult(
                $command,
                $transition->from,
                $transition->to,
                $run,
                $revision,
                false,
                $outcome,
            );
        } catch (\Throwable $e) {
            $this->rollbackCommandTransaction($pdo, $nestedTransaction);
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $receipt
     */
    private function replay(
        int $supplierId,
        int $runId,
        PayrollRunCommand $command,
        string $requestHash,
        array $receipt,
    ): PayrollRunCommandResult {
        if ((int) $receipt['run_id'] !== $runId
            || (string) $receipt['command_name'] !== $command->value
            || !hash_equals((string) $receipt['request_hash'], $requestHash)
        ) {
            throw new PayrollRunIdempotencyException();
        }
        $run = $this->runs->find($supplierId, $runId)
            ?? throw new \OutOfBoundsException('Mzdový běh nebyl nalezen.');
        $revision = $receipt['revision_id'] === null
            ? null
            : $this->runs->revision($supplierId, (int) $receipt['revision_id']);
        // Replay musí vrátit i to, CO se při původním příkazu stalo — jinak by
        // se opakované zaúčtování tvářilo jinak než to původní. Druhý účetní
        // zápis tady vzniknout nemůže: potvrzenka nás vrací dřív, než se
        // účetní můstek vůbec zavolá.
        $storedOutcome = $receipt['result']['outcome'] ?? null;

        return new PayrollRunCommandResult(
            $command,
            PayrollRunStatus::from((string) $receipt['from_status']),
            PayrollRunStatus::from((string) $receipt['to_status']),
            $run,
            $revision,
            true,
            is_array($storedOutcome) && !array_is_list($storedOutcome)
                ? PayrollRunCommandOutcome::fromArray($storedOutcome)
                : null,
        );
    }

    /**
     * Zaúčtování jako samostatný krok běhu.
     *
     * Zaúčtování dosud běželo jen jako vedlejší efekt schválení a jen tehdy,
     * když ho zmrazená zaměstnavatelská politika povolila. Příkaz `post` je
     * ruční cesta: dávku, která už existuje, jen potvrdí (idempotence),
     * chybějící vytvoří, a u firmy v daňové evidenci řekne nahlas, že se
     * účetní můstek nepoužívá.
     *
     * @param array<string,mixed>|null $revision
     */
    private function applyPosting(
        int $supplierId,
        ?array $revision,
        int $actorUserId,
    ): PayrollRunCommandOutcome {
        if ($revision === null) {
            throw new \DomainException('Mzdový běh nemá revizi k zaúčtování.');
        }
        $revisionId = (int) $revision['id'];
        $existing = $this->postingBatch($supplierId, $revisionId);
        if ($existing !== null) {
            return new PayrollRunCommandOutcome(
                PayrollRunCommandOutcome::ALREADY_POSTED,
                $existing,
            );
        }
        if ($this->approvedPosting === null) {
            throw new \DomainException(
                'Účetní můstek mezd není v této instalaci dostupný.',
            );
        }
        $posted = $this->approvedPosting->postManually(
            $supplierId,
            $revisionId,
            self::snapshotObject($revision['input_snapshot'] ?? null, 'vstupní'),
            self::snapshotObject($revision['result_snapshot'] ?? null, 'výsledný'),
            $actorUserId,
        );
        if ($posted === null) {
            return new PayrollRunCommandOutcome(
                PayrollRunCommandOutcome::POSTING_NOT_APPLICABLE,
                ['reason' => 'tax_evidence'],
            );
        }

        return new PayrollRunCommandOutcome(
            PayrollRunCommandOutcome::POSTED,
            [
                'batch_id' => $posted['batch_id'],
                'journal_entry_id' => $posted['journal_entry_id'],
                'posting_status' => $posted['status'],
            ],
        );
    }

    /**
     * @param array<string,mixed>|null $revision
     */
    private function assertPaymentsSettled(
        int $supplierId,
        ?array $revision,
    ): PayrollRunCommandOutcome {
        if ($revision === null) {
            throw new \DomainException('Mzdový běh nemá revizi.');
        }
        if ($this->paymentSettlement === null) {
            throw new \DomainException(
                'Kontrola úhrad mezd není v této instalaci dostupná.',
            );
        }
        $coverage = $this->paymentSettlement->inspect(
            $supplierId,
            (int) $revision['id'],
        );
        if ($coverage['liability_count'] === 0) {
            return new PayrollRunCommandOutcome(
                PayrollRunCommandOutcome::PAYMENTS_NOT_APPLICABLE,
                ['reason' => 'nothing_to_pay'],
            );
        }
        if ($coverage['uncovered'] !== []) {
            throw new PayrollRunPaymentsUnsettledException(
                $coverage,
                $this->paymentSettlement->blockingReason($coverage),
            );
        }

        return new PayrollRunCommandOutcome(
            PayrollRunCommandOutcome::PAYMENTS_SETTLED,
            [
                'liability_count' => $coverage['liability_count'],
                'batch_count' => $coverage['batch_count'],
                'settled_minor' => $coverage['settled_minor'],
            ],
        );
    }

    /** @return array{batch_id:int,journal_entry_id:?int,posting_status:string}|null */
    private function postingBatch(int $supplierId, int $revisionId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, journal_entry_id, status
               FROM payroll_posting_batches
              WHERE supplier_id = ? AND revision_id = ?
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $revisionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'batch_id' => (int) $row['id'],
            'journal_entry_id' => $row['journal_entry_id'] === null
                ? null
                : (int) $row['journal_entry_id'],
            'posting_status' => (string) $row['status'],
        ];
    }

    private function assertModuleAvailable(int $supplierId, string $periodStart): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT supplier.payroll_enabled,
                    state.status AS module_status,
                    state.start_period
               FROM supplier
          LEFT JOIN payroll_module_state state ON state.supplier_id = supplier.id
              WHERE supplier.id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \OutOfBoundsException('Firma nebyla nalezena.');
        }
        if (!(bool) $row['payroll_enabled']) {
            throw new \DomainException('Firma nemá vedení mezd zapnuté.');
        }
        if ($row['module_status'] === null
            || in_array($row['module_status'], ['disabled', 'suspended'], true)
        ) {
            throw new \DomainException('Plný mzdový modul firmy není aktivní.');
        }
        if ($row['start_period'] !== null
            && (string) $row['start_period'] > $periodStart
        ) {
            throw new \DomainException('Období předchází aktivaci plného mzdového modulu.');
        }
    }

    private function assertYearOpen(int $supplierId, string $periodStart): void
    {
        $this->yearClose->assertOpenForDateRange($supplierId, $periodStart, $periodStart);
    }

    private function period(string $periodStart): \DateTimeImmutable
    {
        $period = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if ($period === false
            || $period->format('Y-m-d') !== $periodStart
            || $period->format('d') !== '01'
        ) {
            throw new \InvalidArgumentException(
                'Mzdové období musí být první den měsíce.',
            );
        }
        return $period;
    }

    /** @return array<string,mixed> */
    private static function snapshotObject(mixed $value, string $label): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException(
                "Mzdový běh nemá uložený {$label} snapshot.",
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \DomainException(
                    "Mzdový {$label}ní snapshot nemá platné klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function paymentDate(
        string $paymentDate,
        \DateTimeImmutable $period,
    ): \DateTimeImmutable {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
        if ($date === false || $date->format('Y-m-d') !== $paymentDate) {
            throw new \InvalidArgumentException(
                'Datum výplaty musí být platné datum ve formátu YYYY-MM-DD.',
            );
        }
        if ($date < $period) {
            throw new \InvalidArgumentException(
                'Datum výplaty nesmí předcházet mzdovému období.',
            );
        }
        return $date;
    }

    private function assertActor(int $actorUserId): void
    {
        if ($actorUserId <= 0) {
            throw new \InvalidArgumentException('Uživatel příkazu není platný.');
        }
    }

    private function rollbackOwnedTransaction(PDO $pdo, bool $ownsTransaction): void
    {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    private function finishCommandTransaction(
        PDO $pdo,
        bool $nestedTransaction,
    ): void {
        if ($nestedTransaction) {
            $pdo->exec('RELEASE SAVEPOINT ' . self::COMMAND_SAVEPOINT);
        } else {
            $pdo->commit();
        }
    }

    private function rollbackCommandTransaction(
        PDO $pdo,
        bool $nestedTransaction,
    ): void {
        if ($nestedTransaction) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::COMMAND_SAVEPOINT);
            $pdo->exec('RELEASE SAVEPOINT ' . self::COMMAND_SAVEPOINT);
        } elseif ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    private function finishDeleteTransaction(
        PDO $pdo,
        bool $nestedTransaction,
    ): void {
        if ($nestedTransaction) {
            $pdo->exec('RELEASE SAVEPOINT ' . self::DELETE_SAVEPOINT);
        } else {
            $pdo->commit();
        }
    }

    private function rollbackDeleteTransaction(
        PDO $pdo,
        bool $nestedTransaction,
    ): void {
        if ($nestedTransaction) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::DELETE_SAVEPOINT);
            $pdo->exec('RELEASE SAVEPOINT ' . self::DELETE_SAVEPOINT);
        } elseif ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
