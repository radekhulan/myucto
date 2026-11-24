<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission;

use MyInvoice\Repository\Payroll\PayrollSubmissionConflictException;
use MyInvoice\Repository\Payroll\PayrollSubmissionInboxRepository;
use MyInvoice\Service\Payroll\PayrollHistoricalPeriodService;
use MyInvoice\Service\Payroll\PayrollPredecessorObligationScope;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use Psr\Clock\ClockInterface;

/**
 * MZ-19-W09 — odvozený tenantový inbox nad povinnostmi a podáními.
 *
 * Nikdy nemění stav `payroll_obligations` ani `payroll_submissions` — pouze
 * čte jejich aktuální stav a udržuje vlastní read-model řádky. Eskalace
 * `due_soon -> due_today -> overdue` je monotónní: jednou dosažená úroveň
 * se už nikdy nesníží, i kdyby se aktuálně vyhodnocená fáze zdála mírnější
 * (obranný guard proti chybě hodin nebo souběhu). Jakmile je položka
 * `resolved`, je to konečný stav a další derivace ji už nemění.
 */
final class PayrollSubmissionInboxService
{
    private const ENVIRONMENTS = ['production', 'test'];

    /** @var array<string,int> */
    private const PROBLEM_RANK = [
        'due_soon' => 1,
        'due_today' => 2,
        'overdue' => 3,
        'rejected' => 3,
        'waiting_for_identity' => 3,
        'manual_review' => 3,
    ];

    /** @var array<int,string> */
    private const LEVEL_FOR_RANK = [
        1 => 'due_soon',
        2 => 'due_today',
        3 => 'overdue',
    ];

    private const RESOLVING_PHASES = [
        'fulfilled',
        'cancelled',
        'awaiting_result',
    ];

    public function __construct(
        private readonly PayrollSubmissionInboxRepository $repository,
        private readonly PayrollDeadlineAssessmentService $deadlines,
        private readonly ClockInterface $clock,
        // Začátek vedení mezd pro pravidlo „vyřízeno předchozím programem".
        private readonly ?PayrollHistoricalPeriodService $historicalPeriods = null,
    ) {}

    /**
     * Přepočítá read model z aktuálního stavu povinností a podání. Volá se
     * bezprostředně před každým čtením inboxu — je to jediné místo, kde
     * vznikají nebo se mění položky.
     */
    public function sync(int $supplierId, string $environment = 'production'): void
    {
        $this->assertPositive($supplierId, 'Firma inboxu');
        $this->assertAllowed($environment, self::ENVIRONMENTS, 'Prostředí inboxu');

        $this->repository->transaction(function () use ($supplierId, $environment): void {
            if (!$this->repository->lockSupplier($supplierId)) {
                throw new \DomainException('Firma inboxu nebyla nalezena.');
            }
            $now = $this->now();
            $candidates = $this->repository->findSyncCandidates(
                $supplierId,
                $environment,
            );
            $startPeriod = $this->historicalPeriods?->startPeriod($supplierId);
            foreach ($candidates as $candidate) {
                $this->syncCandidate($supplierId, $environment, $candidate, $now, $startPeriod);
            }
        });
    }

    /**
     * @param array{
     *   obligation_id:int,obligation_status:string,agenda_code:string,
     *   subject_type:string,subject_reference:string,period_start:string,
     *   period_end:string,earliest_submission_on:string,due_on:string,
     *   submission_id:?int,submission_status:?string,
     *   inbox_id:?int,inbox_problem_kind:?string,
     *   inbox_escalation_level:?string,inbox_status:?string,
     *   inbox_row_version:?int,inbox_snoozed_until:?string
     * } $candidate
     */
    private function syncCandidate(
        int $supplierId,
        string $environment,
        array $candidate,
        string $now,
        ?string $startPeriod = null,
    ): void {
        $inboxId = $candidate['inbox_id'];
        $inboxStatus = $candidate['inbox_status'];
        $inboxRowVersion = $candidate['inbox_row_version'];

        if ($inboxId !== null
            && $inboxStatus === 'snoozed'
            && $inboxRowVersion !== null
            && $candidate['inbox_snoozed_until'] !== null
            && $candidate['inbox_snoozed_until'] <= $now
        ) {
            $this->repository->reopenExpiredSnooze(
                $supplierId,
                $inboxId,
                $inboxRowVersion,
            );
            $inboxStatus = 'open';
            ++$inboxRowVersion;
        }

        $assessment = $this->deadlines->assess(
            $candidate['earliest_submission_on'],
            $candidate['due_on'],
            $candidate['obligation_status'],
            $candidate['submission_status'],
        );
        $newKind = $this->problemKind(
            $assessment->phase,
            $candidate['submission_status'],
        );
        /*
         * Událost vyřízená předchozím programem (přihláška či odhláška ČSSZ,
         * oznámení pojišťovně před začátkem vedení mezd), ke které z MyÚčta nic
         * neodešlo, není problém MyÚčta. Totéž pravidlo jako hlídač termínů
         * a Měsíční přehled ({@see PayrollPredecessorObligationScope::storedObligationHandledByPredecessor()});
         * bez něj tu zůstala otevřená položka „po termínu" za lhůtu z doby,
         * kdy mzdy vedl někdo jiný. Položka se nezakládá a existující se vyřeší.
         */
        $handledByPredecessor = PayrollPredecessorObligationScope::storedObligationHandledByPredecessor(
            $startPeriod,
            $candidate['source_event_type'] ?? '',
            $candidate['period_start'],
            $candidate['due_on'],
            $candidate['submission_status'],
        );
        if ($handledByPredecessor) {
            $newKind = null;
        }
        $resolving = $handledByPredecessor || in_array(
            $assessment->phase,
            self::RESOLVING_PHASES,
            true,
        );

        if ($inboxId === null) {
            if ($newKind !== null) {
                $this->repository->insertItem(
                    $supplierId,
                    $environment,
                    $candidate['obligation_id'],
                    $candidate['submission_id'],
                    $this->sourceKeyHash(
                        $supplierId,
                        $environment,
                        $candidate['obligation_id'],
                    ),
                    $newKind,
                    self::LEVEL_FOR_RANK[self::PROBLEM_RANK[$newKind]],
                );
            }

            return;
        }

        if ($inboxStatus === 'resolved' || $inboxRowVersion === null) {
            return;
        }

        if ($resolving) {
            $this->repository->resolveItem(
                $supplierId,
                $inboxId,
                $inboxRowVersion,
                $now,
            );

            return;
        }

        if ($newKind === null) {
            // Aktuální fáze (not_open/open) žádný problém nehlásí, ale
            // položka už existuje z dřívějška — guard proti regresi ji
            // nechá beze změny.
            return;
        }

        $oldRank = self::PROBLEM_RANK[$candidate['inbox_problem_kind']] ?? 0;
        $newRank = self::PROBLEM_RANK[$newKind];
        if ($newRank < $oldRank) {
            // Monotónní eskalace: nikdy nesnížit už dosaženou úroveň.
            return;
        }

        $this->repository->updateProblem(
            $supplierId,
            $inboxId,
            $inboxRowVersion,
            $candidate['submission_id'],
            $newKind,
            self::LEVEL_FOR_RANK[$newRank],
        );
    }

    /**
     * @return list<array{
     *   id:int,obligation_id:int,submission_id:?int,agenda_code:string,
     *   subject_type:string,subject_reference:string,period_start:string,
     *   period_end:string,due_on:string,problem_kind:string,
     *   escalation_level:string,status:string,snoozed_until:?string,
     *   snooze_reason:?string,acknowledged_at:?string,resolved_at:?string,
     *   row_version:int,created_at:string,updated_at:string
     * }>
     */
    public function list(
        int $supplierId,
        string $environment = 'production',
    ): array {
        $this->sync($supplierId, $environment);

        return $this->repository->listItems($supplierId, $environment);
    }

    /**
     * @return array{id:int,status:string,row_version:int}
     */
    public function acknowledge(
        int $supplierId,
        int $itemId,
        int $expectedRowVersion,
        int $userId,
    ): array {
        $this->assertPositive($supplierId, 'Firma inboxu');
        $this->assertPositive($itemId, 'Položka inboxu');
        $this->assertPositive($expectedRowVersion, 'Verze položky');
        $this->assertPositive($userId, 'Uživatel');

        return $this->repository->transaction(function () use (
            $supplierId,
            $itemId,
            $expectedRowVersion,
            $userId,
        ): array {
            $item = $this->lockedExpectedItem(
                $supplierId,
                $itemId,
                $expectedRowVersion,
            );
            if ($item['status'] === 'resolved') {
                throw new \DomainException(
                    'Vyřešenou položku inboxu už nelze potvrdit.',
                );
            }
            if ($item['status'] === 'acknowledged') {
                return [
                    'id' => $item['id'],
                    'status' => $item['status'],
                    'row_version' => $item['row_version'],
                ];
            }
            $this->repository->acknowledgeItem(
                $supplierId,
                $itemId,
                $expectedRowVersion,
                $userId,
                $this->now(),
            );

            return [
                'id' => $itemId,
                'status' => 'acknowledged',
                'row_version' => $expectedRowVersion + 1,
            ];
        });
    }

    /**
     * @return array{id:int,status:string,row_version:int,snoozed_until:string}
     */
    public function snooze(
        int $supplierId,
        int $itemId,
        int $expectedRowVersion,
        string $snoozedUntil,
        string $reason,
        int $userId,
    ): array {
        $this->assertPositive($supplierId, 'Firma inboxu');
        $this->assertPositive($itemId, 'Položka inboxu');
        $this->assertPositive($expectedRowVersion, 'Verze položky');
        $this->assertPositive($userId, 'Uživatel');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason, 'UTF-8') > 500) {
            throw new \InvalidArgumentException(
                'Důvod odložení musí mít 1 až 500 znaků.',
            );
        }
        $normalizedUntil = $this->assertFutureDateTime($snoozedUntil);

        return $this->repository->transaction(function () use (
            $supplierId,
            $itemId,
            $expectedRowVersion,
            $normalizedUntil,
            $reason,
            $userId,
        ): array {
            $item = $this->lockedExpectedItem(
                $supplierId,
                $itemId,
                $expectedRowVersion,
            );
            if ($item['status'] === 'resolved') {
                throw new \DomainException(
                    'Vyřešenou položku inboxu už nelze odložit.',
                );
            }
            $this->repository->snoozeItem(
                $supplierId,
                $itemId,
                $expectedRowVersion,
                $normalizedUntil,
                $reason,
                $userId,
            );

            return [
                'id' => $itemId,
                'status' => 'snoozed',
                'row_version' => $expectedRowVersion + 1,
                'snoozed_until' => $normalizedUntil,
            ];
        });
    }

    /**
     * @return array{
     *   id:int,supplier_id:int,environment:string,obligation_id:int,
     *   submission_id:?int,problem_kind:string,escalation_level:string,
     *   status:string,row_version:int
     * }
     */
    private function lockedExpectedItem(
        int $supplierId,
        int $itemId,
        int $expectedRowVersion,
    ): array {
        $item = $this->repository->lockItem($supplierId, $itemId);
        if ($item === null) {
            throw new \DomainException(
                'Položka inboxu nebyla nalezena ve stejné firmě.',
            );
        }
        if ($item['row_version'] !== $expectedRowVersion) {
            throw new PayrollSubmissionConflictException(
                'Položka inboxu se mezitím změnila.',
            );
        }

        return $item;
    }

    private function problemKind(
        string $phase,
        ?string $submissionStatus,
    ): ?string {
        return match ($phase) {
            'due_soon' => 'due_soon',
            'due_today' => 'due_today',
            'overdue' => 'overdue',
            'action_required' => match ($submissionStatus) {
                'rejected' => 'rejected',
                'waiting_for_identity' => 'waiting_for_identity',
                default => 'manual_review',
            },
            default => null,
        };
    }

    private function sourceKeyHash(
        int $supplierId,
        string $environment,
        int $obligationId,
    ): string {
        return hash(
            'sha256',
            CanonicalJson::encode([
                'schema_reference' => 'payroll-submission-inbox-item.v1',
                'supplier_id' => $supplierId,
                'environment' => $environment,
                'obligation_id' => $obligationId,
            ]),
        );
    }

    private function now(): string
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function assertFutureDateTime(string $value): string
    {
        try {
            $parsed = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new \InvalidArgumentException(
                'Datum a čas odložení nejsou platné.',
            );
        }
        $normalized = $parsed->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        if ($normalized <= $this->now()) {
            throw new \InvalidArgumentException(
                'Odložení musí mít termín v budoucnosti.',
            );
        }

        return $normalized;
    }

    private function assertPositive(int $value, string $field): void
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException(
                "{$field} musí být kladné číslo.",
            );
        }
    }

    /** @param list<string> $allowed */
    private function assertAllowed(
        string $value,
        array $allowed,
        string $field,
    ): void {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(
                "{$field} není podporované.",
            );
        }
    }
}
