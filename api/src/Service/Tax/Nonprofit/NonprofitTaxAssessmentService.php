<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Repository\NonprofitTaxAssessmentRepository;
use MyInvoice\Repository\NonprofitTaxSourceRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Nonprofit\Funding\FundingEligibilityGuard;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6FundingDependencyGuard;

final class NonprofitTaxAssessmentService
{
    public function __construct(
        private readonly NonprofitTaxSourceRepository $sources,
        private readonly NonprofitTaxAssessmentRepository $assessments,
        private readonly NonprofitTaxCalculator $calculator,
        private readonly DimensionPeriodGuard $periodGuard,
        private readonly J6FundingCapacity $fundingCapacity,
        private readonly FundingEligibilityGuard $fundingEligibility,
        private readonly J6FundingDependencyGuard $laterFunding,
        private readonly J6FundingProjection $funding,
    ) {}

    public function read(int $supplierId, int $periodId): array
    {
        return ['snapshot' => $this->sources->snapshot($supplierId, $periodId),
            'latest' => $this->assessments->latest($supplierId, $periodId)];
    }

    public function preview(int $supplierId, int $periodId, array $assessment): array
    {
        $snapshot = $this->sources->snapshot($supplierId, $periodId);
        return ['snapshot' => $snapshot, 'result' => $this->calculate($snapshot, $assessment),
            'previous_assessment_id' => $this->assessments->latest($supplierId, $periodId)['id'] ?? null];
    }

    public function history(int $supplierId, int $periodId): array
    {
        $this->sources->period($supplierId, $periodId);
        return $this->assessments->history($supplierId, $periodId);
    }

    public function confirm(int $supplierId, int $periodId, array $assessment, string $inputHash,
        string $reason, string $key, int $actor, ?int $expectedAssessmentId = null): array
    {
        $this->validateMutation($reason, $key, $actor);
        if (!preg_match('/^[a-f0-9]{64}$/D', $inputHash)) NonprofitTaxRules::invalid('Chybí platný otisk zkontrolovaných podkladů.');
        $requestHash = NonprofitTaxRules::hash(['action' => 'confirm', 'period_id' => $periodId, 'assessment' => $assessment,
            'input_hash' => $inputHash, 'reason' => $reason, 'actor' => $actor, 'expected_assessment_id' => $expectedAssessmentId]);
        return $this->periodGuard->transaction(function () use ($supplierId, $periodId, $assessment, $inputHash, $reason, $key, $actor, $requestHash, $expectedAssessmentId): array {
            $this->lockOpenPeriod($supplierId, $periodId);
            if ($existing = $this->retry($supplierId, $key, $requestHash)) return $existing;
            $snapshot = $this->sources->snapshot($supplierId, $periodId, true);
            if (!hash_equals($snapshot['input_hash'], $inputHash)) throw new NonprofitTaxAssessmentException('nonprofit_tax_sources_changed', 'Účetní podklady se změnily. Je nutné nové posouzení.', 409);
            $result = $this->calculate($snapshot, $assessment, true);
            $history = $this->assessments->history($supplierId, $periodId, true);
            $previous = $history === [] ? null : $history[array_key_last($history)];
            if (($previous['id'] ?? null) !== $expectedAssessmentId) throw new NonprofitTaxAssessmentException('nonprofit_tax_assessment_changed', 'Mezitím vzniklo jiné posouzení. Je nutné zkontrolovat aktuální revizi.', 409);
            $this->assertGiftElections($this->assessments->confirmedHistory($supplierId, true), $snapshot, $assessment);
            $this->laterFunding->assertRevisionsUnused($supplierId, $periodId, array_values(array_filter([$previous, ['result' => $result]])));
            return $this->assessments->append($supplierId, $periodId, $previous,
                'confirmed', $reason, $key, $requestHash, $snapshot, $assessment, $result, $actor);
        });
    }

    public function withdraw(int $supplierId, int $periodId, int $expectedId, string $reason, string $key, int $actor): array
    {
        $this->validateMutation($reason, $key, $actor);
        $requestHash = NonprofitTaxRules::hash(['action' => 'withdraw', 'period_id' => $periodId, 'expected_id' => $expectedId, 'reason' => $reason, 'actor' => $actor]);
        return $this->periodGuard->transaction(function () use ($supplierId, $periodId, $expectedId, $reason, $key, $actor, $requestHash): array {
            $this->lockOpenPeriod($supplierId, $periodId);
            if ($existing = $this->retry($supplierId, $key, $requestHash)) return $existing;
            $previous = $this->assessments->latest($supplierId, $periodId, true);
            if ($previous === null || $previous['id'] !== $expectedId || $previous['status'] !== 'confirmed') {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_assessment_changed', 'Potvrzené posouzení se změnilo nebo již bylo odvoláno.', 409);
            }
            $this->laterFunding->assertRevisionsUnused($supplierId, $periodId, [$previous]);
            return $this->assessments->append($supplierId, $periodId, $previous, 'withdrawn', $reason, $key, $requestHash,
                $previous['snapshot'], $previous['assessment'], $previous['result'], $actor);
        });
    }

    private function calculate(array $snapshot, array $assessment, bool $currentRead = false): array
    {
        if ($snapshot['findings'] !== []) throw new NonprofitTaxAssessmentException('nonprofit_tax_sources_incomplete', 'Chybí průkazné sémantické členění nebo společná alokace účetních podkladů.');
        $result = $this->calculator->calculate($snapshot, $assessment);
        if ($result['accounting_result_cents'] !== $snapshot['accounting_result_cents']) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_reconciliation_failed', 'Posouzení nesouhlasí s výsledkem účetního deníku.');
        }
        $this->fundingEligibility->assertAssessment((int) $snapshot['supplier_id'], $snapshot, $assessment, $result, $currentRead);
        $this->fundingCapacity->assertAssessment((int) $snapshot['supplier_id'], $snapshot, $assessment, $result);
        return $result;
    }

    private function lockOpenPeriod(int $supplierId, int $periodId): void
    {
        $statuses = $this->periodGuard->lockPeriods($supplierId);
        if (!isset($statuses[$periodId])) throw new NonprofitTaxAssessmentException('nonprofit_tax_period_not_found', 'Účetní období nebylo nalezeno.', 404);
        if (!in_array($statuses[$periodId], ['open', 'closing'], true)) throw new NonprofitTaxAssessmentException('nonprofit_tax_period_locked', 'V uzamčeném období nelze měnit potvrzení daňového posouzení.', 409);
    }

    private function retry(int $supplierId, string $key, string $hash): ?array
    {
        $row = $this->assessments->byIdempotency($supplierId, $key);
        if ($row !== null && !hash_equals($row['request_hash'], $hash)) throw new NonprofitTaxAssessmentException('nonprofit_tax_idempotency_conflict', 'Klíč požadavku již patří jinému rozhodnutí.', 409);
        return $row;
    }

    private function validateMutation(string $reason, string $key, int $actor): void
    {
        NonprofitTaxRules::evidence($reason);
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,80}$/D', $key) || $actor <= 0) NonprofitTaxRules::invalid('Potvrzení vyžaduje identitu uživatele a jednoznačný klíč požadavku.');
    }

    private function assertGiftElections(array $history, array $snapshot, array $assessment): void
    {
        $elections = [];
        foreach ($history as $revision) {
            if ($revision['status'] !== 'confirmed') continue;
            // Stránkovaná revize nemá řádky v JSON; volbu z ní přečte stejná projekce jako financování.
            foreach ($this->funding->resultPages($revision) as $rows) {
                foreach ($rows as $row) {
                    if (($row['decision']['title'] ?? '') !== 'gift') continue;
                    $origin = (int) $row['origin']['line_id'];
                    $choice = $row['decision']['facts']['claim_exemption'];
                    if (isset($elections[$origin]) && $elections[$origin] !== $choice) $this->giftElectionLocked();
                    $elections[$origin] = $choice;
                }
            }
        }
        $current = [];
        foreach ($snapshot['sources'] as $source) {
            $decision = $assessment['decisions'][$source['id']];
            $origin = (int) $source['origin']['line_id'];
            if (isset($elections[$origin]) && (($decision['title'] ?? '') !== 'gift'
                || ($decision['facts']['claim_exemption'] ?? null) !== $elections[$origin])) $this->giftElectionLocked();
            if (($decision['title'] ?? '') !== 'gift') continue;
            $choice = $decision['facts']['claim_exemption'];
            if (isset($current[$origin]) && $current[$origin] !== $choice) $this->inconsistentGiftElection();
            $current[$origin] = $choice;
        }
        foreach ($snapshot['sources'] as $source) {
            if (isset($current[(int) $source['origin']['line_id']]) && $assessment['decisions'][$source['id']]['title'] !== 'gift') $this->inconsistentGiftElection();
        }
    }

    private function giftElectionLocked(): never
    {
        throw new NonprofitTaxAssessmentException('nonprofit_tax_gift_election_locked', 'Potvrzenou volbu osvobození daru nelze zpětně změnit.', 409);
    }

    private function inconsistentGiftElection(): never
    {
        NonprofitTaxRules::invalid('Části téhož daru musí mít shodnou volbu osvobození a právní titul.');
    }
}
