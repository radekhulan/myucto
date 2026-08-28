<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Repository\Payroll\PayrollOperationalReconciliationRepository;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentReconciliationQueryService;
use MyInvoice\Service\Payroll\Posting\PayrollPostingReconciliationService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverview;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewReconciliationService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthPaymentOverviewService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;

final class PayrollOperationalReconciliationService
{
    private const STATUSES = [
        'match',
        'diff',
        'not_applicable',
        'not_materialized',
        'blocked',
    ];

    public function __construct(
        private readonly PayrollOperationalReconciliationRepository $repository,
        private readonly PayrollPostingReconciliationService $posting,
        private readonly PayrollPaymentReconciliationQueryService $payments,
        private readonly HealthPaymentOverviewService $healthOverviews,
        private readonly HealthPaymentOverviewReconciliationService $healthReconciliation,
    ) {}

    /** @return array<string,mixed> */
    public function evaluate(int $supplierId, string $period): array
    {
        $this->assertInput($supplierId, $period);
        $context = $this->repository->runContext($supplierId, $period);
        if ($context === null) {
            $axes = [$this->finding(
                'payroll:run',
                'posting',
                'run',
                'not_materialized',
                null,
                null,
                ['reason' => 'run_missing'],
            )];

            return $this->envelope($supplierId, $period, null, $axes);
        }

        $revisionId = $context['revision_id'];
        if ($revisionId === null
            || !in_array($context['revision_status'], ['approved', 'superseded'], true)
        ) {
            $axes = [$this->finding(
                'payroll:revision',
                'posting',
                'revision',
                'not_materialized',
                null,
                null,
                [
                    'run_id' => $context['run_id'],
                    'run_status' => $context['run_status'],
                    'revision_id' => $revisionId,
                    'revision_status' => $context['revision_status'],
                ],
            )];

            return $this->envelope($supplierId, $period, $context, $axes);
        }

        $axes = [];
        try {
            $posting = $this->posting->forPeriod($supplierId, $period);
            $axes = array_merge($axes, $this->postingAxes($posting));
        } catch (\Throwable $exception) {
            $axes[] = $this->blocked(
                'posting:source_integrity',
                'posting',
                'source_integrity',
                $exception,
                ['revision_id' => $revisionId],
            );
        }

        try {
            $axes = array_merge(
                $axes,
                $this->paymentAxes($this->payments->periodTotals($supplierId, $period)),
            );
        } catch (\Throwable $exception) {
            $axes[] = $this->blocked(
                'payment:ledger_integrity',
                'payment',
                'ledger_integrity',
                $exception,
                ['revision_id' => $revisionId],
            );
        }

        try {
            $overviews = $this->healthOverviews->overviews($supplierId, $revisionId);
            $axes = array_merge($axes, $this->healthAxes(
                $overviews,
                $this->healthReconciliation->forOverviews($overviews),
            ));
        } catch (\Throwable $exception) {
            $axes[] = $this->blocked(
                'health:overview_integrity',
                'health',
                'overview_integrity',
                $exception,
                ['revision_id' => $revisionId],
            );
        }

        try {
            $axes = array_merge($axes, $this->jmhzAxes(
                $revisionId,
                $this->repository->jmhzStates(
                    $supplierId,
                    $period,
                    JmhzSubmissionBridgeService::AGENDA_CODE,
                ),
            ));
        } catch (\Throwable $exception) {
            $axes[] = $this->blocked(
                'jmhz:state_integrity',
                'jmhz',
                'state_integrity',
                $exception,
                ['revision_id' => $revisionId],
            );
        }

        usort(
            $axes,
            static fn (array $left, array $right): int =>
                $left['key'] <=> $right['key'],
        );

        return $this->envelope($supplierId, $period, $context, $axes);
    }

    /** @return array<string,mixed> */
    public function sweep(int $supplierId, string $period): array
    {
        $result = $this->evaluate($supplierId, $period);
        $run = $result['run'];
        if (is_array($run)
            && is_int($run['id'] ?? null)
            && is_int($run['revision_id'] ?? null)
            && in_array($run['revision_status'] ?? null, ['approved', 'superseded'], true)
        ) {
            $this->repository->synchronize(
                $supplierId,
                $run['id'],
                $run['revision_id'],
                $period . '-01',
                $result['axes'],
            );
        }
        $result['issues'] = $this->repository->forPeriod($supplierId, $period);

        return $result;
    }

    /** @return array<string,mixed> */
    public function forPeriod(int $supplierId, string $period): array
    {
        $result = $this->evaluate($supplierId, $period);
        $result['issues'] = $this->repository->forPeriod($supplierId, $period);

        return $result;
    }

    /** @return array<string,mixed>|null */
    public function issue(int $supplierId, int $issueId): ?array
    {
        if ($supplierId <= 0 || $issueId <= 0) {
            throw new \InvalidArgumentException(
                'Firma i issue provozní reconciliation musí být kladné číslo.',
            );
        }

        return $this->repository->detail($supplierId, $issueId);
    }

    /** @param array<string,mixed> $posting @return list<array<string,mixed>> */
    private function postingAxes(array $posting): array
    {
        $result = [];
        foreach ($posting['categories'] ?? [] as $category) {
            if (!is_array($category) || !is_string($category['key'] ?? null)) {
                throw new \UnexpectedValueException(
                    'Reconciliation účetního můstku vrátila neplatnou kategorii.',
                );
            }
            $key = $category['key'];
            $payroll = $this->requiredInt($category, 'payroll_minor');
            $journal = $this->nullableInt($category, 'journal_minor');
            $journalState = $posting['journal_state'] ?? null;
            // Zaúčtované období bez deníkové strany kategorie = INFORMATIVNÍ
            // řádek (nepeněžní plnění bez účetního dopadu), ne rozdíl.
            // Porovnávat mzdovou částku s `null` by ho vždy prohlásilo za
            // rozdíl a provozní přehled by kvůli očekávanému stavu trvale
            // svítil.
            $journalStatus = $journalState === 'not_applicable'
                ? 'not_applicable'
                : ($journalState !== 'posted'
                    ? 'not_materialized'
                    : ($journal === null
                        ? 'not_applicable'
                        : ($payroll === $journal ? 'match' : 'diff')));
            $result[] = $this->finding(
                'posting:journal:' . $key,
                'posting',
                $key,
                $journalStatus,
                $journal === null ? null : $payroll,
                $journal,
                [
                    'journal_state' => $journalState,
                    'accounting_mode' => $posting['accounting_mode'] ?? null,
                    'revision_id' => $posting['revision']['id'] ?? null,
                ],
            );

            $liability = $this->nullableInt($category, 'payments_liability_minor');
            $paymentStatus = $liability === null
                ? (in_array($key, [
                    'gross_wages',
                    'employer_contributions',
                    // Nepeněžní plnění bez účetního dopadu se ani neplatí.
                    'non_monetary_neutral',
                ], true)
                    ? 'not_applicable'
                    : 'not_materialized')
                : ($payroll === $liability ? 'match' : 'diff');
            $result[] = $this->finding(
                'posting:liability:' . $key,
                'posting',
                $key,
                $paymentStatus,
                $liability === null ? null : $payroll,
                $liability,
                [
                    'payments_state' => $posting['payments_state'] ?? null,
                    'payments_paid_minor' => $category['payments_paid_minor'] ?? null,
                    'revision_id' => $posting['revision']['id'] ?? null,
                ],
            );
        }

        return $result;
    }

    /** @param array<string,mixed> $totals @return list<array<string,mixed>> */
    private function paymentAxes(array $totals): array
    {
        $result = [];
        foreach (['outgoing', 'incoming'] as $direction) {
            $row = $totals[$direction] ?? null;
            if (!is_array($row)) {
                throw new \UnexpectedValueException(
                    'Platební reconciliation nemá oba směry.',
                );
            }
            $count = $this->requiredInt($row, 'liability_count');
            $required = $this->requiredInt($row, 'required_minor');
            $settled = $this->requiredInt($row, 'settled_minor');
            $status = $count === 0
                ? ($direction === 'outgoing' ? 'not_materialized' : 'not_applicable')
                : ($required === $settled ? 'match' : 'diff');
            $result[] = $this->finding(
                'payment:settlement:' . $direction,
                'payment',
                $direction,
                $status,
                $count === 0 ? null : $required,
                $count === 0 ? null : $settled,
                [
                    'liability_count' => $count,
                    'remaining_minor' => $this->requiredInt($row, 'remaining_minor'),
                    'direction' => $direction,
                ],
            );
        }

        return $result;
    }

    /**
     * @param list<HealthPaymentOverview> $overviews
     * @param list<array<string,mixed>> $reconciliations
     * @return list<array<string,mixed>>
     */
    private function healthAxes(array $overviews, array $reconciliations): array
    {
        if (count($overviews) !== count($reconciliations)) {
            throw new \UnexpectedValueException(
                'Počet PPZ neodpovídá počtu reconciliation výsledků.',
            );
        }
        if ($overviews === []) {
            return [$this->finding(
                'health:overview',
                'health',
                'overview',
                'not_applicable',
                null,
                null,
                ['reason' => 'no_health_insurer_in_revision'],
            )];
        }
        $result = [];
        foreach ($overviews as $index => $overview) {
            $reconciliation = $reconciliations[$index];
            $expected = $this->requiredInt($reconciliation, 'expected_minor');
            $actual = $this->requiredInt($reconciliation, 'liability_minor');
            $category = 'i' . $overview->insurerCode;
            $liabilityIds = $reconciliation['liability_ids'] ?? null;
            if (!is_array($liabilityIds)) {
                throw new \UnexpectedValueException('PPZ reconciliation nemá závazky.');
            }
            $status = $liabilityIds === []
                ? 'not_materialized'
                : ($expected === $actual ? 'match' : 'diff');
            $result[] = $this->finding(
                'health:liability:' . $category,
                'health',
                $category,
                $status,
                $expected,
                $actual,
                [
                    'overview_hash' => $overview->sha256(),
                    'statutory_result_hash' => $overview->statutoryResultHash,
                    'liability_ids' => array_map('intval', $liabilityIds),
                    'blockers' => $reconciliation['blockers'] ?? [],
                ],
            );
            $outgoingRemaining = $this->requiredInt(
                $reconciliation,
                'outgoing_remaining_minor',
            );
            $incomingRemaining = $this->requiredInt(
                $reconciliation,
                'incoming_remaining_minor',
            );
            $paymentStatus = $liabilityIds === []
                ? 'not_materialized'
                : ($outgoingRemaining === 0 && $incomingRemaining === 0
                    ? 'match'
                    : 'diff');
            $result[] = $this->finding(
                'health:settlement:' . $category,
                'health',
                $category . ':settlement',
                $paymentStatus,
                null,
                null,
                [
                    'overview_hash' => $overview->sha256(),
                    'outgoing_remaining_minor' => $outgoingRemaining,
                    'incoming_remaining_minor' => $incomingRemaining,
                    'bank_settled_minor' => $this->requiredInt(
                        $reconciliation,
                        'bank_settled_minor',
                    ),
                ],
            );
        }

        return $result;
    }

    /** @param list<array<string,mixed>> $states @return list<array<string,mixed>> */
    private function jmhzAxes(int $revisionId, array $states): array
    {
        if ($states === []) {
            return [$this->finding(
                'jmhz:state',
                'jmhz',
                'state',
                'not_materialized',
                null,
                null,
                ['reason' => 'jmhz_obligation_missing'],
            )];
        }
        $result = [];
        foreach ($states as $state) {
            $environment = $state['environment'] ?? null;
            if (!in_array($environment, ['production', 'test'], true)) {
                throw new \UnexpectedValueException('JMHZ stav má neplatné prostředí.');
            }
            $submissionStatus = $state['submission_status'] ?? null;
            $sourceRevisionId = $state['source_revision_id'] ?? null;
            if ($state['submission_id'] === null) {
                $status = 'not_materialized';
            } elseif ($sourceRevisionId !== $revisionId) {
                $status = 'diff';
            } elseif (in_array($submissionStatus, ['accepted', 'partially_accepted'], true)) {
                $status = 'match';
            } elseif (in_array($submissionStatus, [
                'rejected', 'correction_required', 'waiting_for_identity',
            ], true)) {
                $status = 'blocked';
            } else {
                $status = 'not_materialized';
            }
            $result[] = $this->finding(
                'jmhz:' . $environment,
                'jmhz',
                $environment,
                $status,
                null,
                null,
                [
                    'obligation_id' => $state['obligation_id'] ?? null,
                    'obligation_status' => $state['obligation_status'] ?? null,
                    'submission_id' => $state['submission_id'] ?? null,
                    'submission_status' => $submissionStatus,
                    'submission_kind' => $state['submission_kind'] ?? null,
                    'source_revision_id' => $sourceRevisionId,
                    'source_snapshot_hash' => $state['source_snapshot_hash'] ?? null,
                    'current_revision_id' => $revisionId,
                ],
            );
        }

        return $result;
    }

    /** @param array<string,mixed>|null $context @param list<array<string,mixed>> $axes */
    private function envelope(
        int $supplierId,
        string $period,
        ?array $context,
        array $axes,
    ): array {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($axes as $axis) {
            ++$counts[$axis['status']];
        }
        $overall = $counts['blocked'] > 0
            ? 'blocked'
            : ($counts['diff'] > 0
                ? 'diff'
                : ($counts['not_materialized'] > 0
                    ? 'not_materialized'
                    : ($counts['match'] > 0 ? 'match' : 'not_applicable')));

        return [
            'schema_version' => 'payroll-operational-reconciliation.v1',
            'supplier_id' => $supplierId,
            'period' => $period,
            'run' => $context === null ? null : [
                'id' => $context['run_id'],
                'status' => $context['run_status'],
                'revision_id' => $context['revision_id'],
                'revision_no' => $context['revision_no'],
                'revision_status' => $context['revision_status'],
                'revision_kind' => $context['revision_kind'],
                'result_snapshot_hash' => $context['result_snapshot_hash'],
            ],
            'overall_status' => $overall,
            'counts' => $counts,
            'axes' => $axes,
        ];
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function finding(
        string $key,
        string $scope,
        string $category,
        string $status,
        ?int $expected,
        ?int $actual,
        array $source,
    ): array {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \LogicException('Neznámý stav provozní reconciliation.');
        }
        if (($expected === null) !== ($actual === null)) {
            throw new \LogicException('Reconciliation musí mít obě částky nebo žádnou.');
        }
        $difference = $expected === null ? null : $expected - $actual;
        $snapshot = [
            'schema' => 'payroll-operational-reconciliation-source.v1',
            'key' => $key,
            'scope' => $scope,
            'category' => $category,
            'status' => $status,
            'expected_minor' => $expected,
            'actual_minor' => $actual,
            'difference_minor' => $difference,
            'source' => $source,
        ];
        $json = CanonicalJson::encode($snapshot);

        return [
            'key' => $key,
            'scope' => $scope,
            'category' => $category,
            'status' => $status,
            'expected_minor' => $expected,
            'actual_minor' => $actual,
            'difference_minor' => $difference,
            'source_snapshot_json' => $json,
            'source_hash' => hash('sha256', $json),
        ];
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function blocked(
        string $key,
        string $scope,
        string $category,
        \Throwable $exception,
        array $source,
    ): array {
        return $this->finding(
            $key,
            $scope,
            $category,
            'blocked',
            null,
            null,
            [
                ...$source,
                'error_type' => $exception::class,
                'reason' => 'source_validation_failed',
            ],
        );
    }

    private function assertInput(int $supplierId, string $period): void
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma provozní reconciliation musí být kladné číslo.',
            );
        }
        if (preg_match('/^(20[0-9]{2}|21[0-9]{2})-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new \InvalidArgumentException('Mzdové období musí mít tvar RRRR-MM.');
        }
    }

    /** @param array<string,mixed> $row */
    private function requiredInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_int($row[$key])) {
            throw new \UnexpectedValueException(
                "Reconciliation nemá platné celočíselné pole {$key}.",
            );
        }

        return $row[$key];
    }

    /** @param array<string,mixed> $row */
    private function nullableInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)
            || ($row[$key] !== null && !is_int($row[$key]))
        ) {
            throw new \UnexpectedValueException(
                "Reconciliation nemá platné volitelné pole {$key}.",
            );
        }

        return $row[$key];
    }
}
