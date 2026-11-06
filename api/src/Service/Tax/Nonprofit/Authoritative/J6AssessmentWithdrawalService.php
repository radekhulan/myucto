<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Repository\NonprofitTaxPagedAssessmentRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final readonly class J6AssessmentWithdrawalService
{
    public function __construct(
        private DimensionPeriodGuard $periods,
        private NonprofitTaxPagedAssessmentRepository $assessments,
        private J6FundingDependencyGuard $funding,
    ) {}

    public function withdraw(int $supplierId, int $periodId, int $expectedAssessmentId, string $reason,
        string $idempotencyKey, int $actor): J6AssessmentCompletion
    {
        if ($supplierId <= 0 || $periodId <= 0 || $expectedAssessmentId <= 0 || $actor <= 0
            || $reason === '' || $idempotencyKey === '' || strlen($idempotencyKey) > 80) {
            throw new \InvalidArgumentException('J6 withdrawal request is invalid.');
        }
        $requestHash = NonprofitTaxRules::hash(['domain' => 'J6WITHDRAW1', 'supplier_id' => $supplierId,
            'period_id' => $periodId, 'expected_assessment_id' => $expectedAssessmentId,
            'reason' => $reason, 'idempotency_key' => $idempotencyKey, 'actor_id' => $actor]);
        return $this->periods->transaction(function () use ($supplierId, $periodId, $expectedAssessmentId, $reason,
            $idempotencyKey, $actor, $requestHash): J6AssessmentCompletion {
            $statuses = $this->periods->lockPeriods($supplierId);
            if (!isset($statuses[$periodId])) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_period_not_found', 'Účetní období nebylo nalezeno.', 404);
            }
            if (!in_array($statuses[$periodId], ['open', 'closing'], true)) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_period_locked',
                    'V uzamčeném období nelze odvolat daňové posouzení.', 409);
            }
            $existing = $this->assessments->byIdempotency($supplierId, $idempotencyKey, true);
            if ($existing !== null) {
                if (!hash_equals($existing['request_hash'], $requestHash) || $existing['status'] !== 'withdrawn'
                    || !($existing['graph_receipt'] instanceof J6AssessmentGraphReceipt)) {
                    throw new NonprofitTaxAssessmentException('nonprofit_tax_idempotency_conflict',
                        'Idempotentní klíč už patří jinému odvolání.', 409);
                }
                return $this->completion($existing);
            }
            $current = $this->assessments->latest($supplierId, $periodId, true);
            if (($current['id'] ?? null) !== $expectedAssessmentId || ($current['status'] ?? null) !== 'confirmed') {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_assessment_changed',
                    'Potvrzené posouzení se změnilo nebo již bylo odvoláno.', 409);
            }
            $this->funding->assertNoLaterUsage($supplierId, $periodId, $current);
            return $this->completion($this->assessments->appendWithdrawn($supplierId, $periodId, $current,
                $reason, $idempotencyKey, $requestHash, $actor));
        });
    }

    private function completion(array $assessment): J6AssessmentCompletion
    {
        return new J6AssessmentCompletion($assessment['id'], $assessment['supplier_id'], $assessment['period_id'],
            $assessment['revision_no'], $assessment['status'], $assessment['request_hash'], $assessment['graph_receipt']);
    }
}
