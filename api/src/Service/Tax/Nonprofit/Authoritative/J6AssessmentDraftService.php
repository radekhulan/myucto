<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Repository\NonprofitTaxDraftRepository;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotMaterializer;

final readonly class J6AssessmentDraftService
{
    public function __construct(
        private NonprofitTaxDraftRepository $drafts,
        private DimensionPeriodGuard $periods,
        private NonprofitTaxSnapshotMaterializer $materializer,
        private NonprofitTaxSnapshotManifestRepository $snapshots,
    ) {}

    public function openCurrent(int $supplierId, int $periodId, array $profile, ?array $method, int $actor): array
    {
        if ($actor <= 0) throw new \InvalidArgumentException('Draft actor is invalid.');
        return $this->periods->transaction(function () use ($supplierId, $periodId, $profile, $method, $actor): array {
            $this->openPeriod($supplierId, $periodId);
            [$sourceManifestId, $sourceInputHash, $header] = $this->capture($supplierId, $periodId, $profile, $method);
            return $this->drafts->open($supplierId, $periodId, $sourceInputHash, $header, $actor, $sourceManifestId);
        });
    }

    public function resetCurrent(int $supplierId, int $periodId, int $draftId, int $expectedRevision,
        array $profile, ?array $method, int $actor): array
    {
        if ($actor <= 0 || $draftId <= 0) throw new \InvalidArgumentException('Draft mutation identity is invalid.');
        return $this->periods->transaction(function () use ($supplierId, $periodId, $draftId, $expectedRevision, $profile, $method, $actor): array {
            $this->openPeriod($supplierId, $periodId);
            $draft = $this->drafts->find($supplierId, $draftId, true);
            if ($draft['period_id'] !== $periodId || $draft['created_by'] !== $actor) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_not_found', 'Koncept daňového posouzení nebyl nalezen.', 404);
            }
            [$sourceManifestId, $sourceInputHash, $header] = $this->capture($supplierId, $periodId, $profile, $method);
            return $this->drafts->reset($supplierId, $draftId, $expectedRevision, $sourceInputHash, $header, $sourceManifestId);
        });
    }

    public function open(int $supplierId, int $periodId, string $sourceInputHash, array $header, int $actor): array
    {
        $this->validate($sourceInputHash, $header, $actor);
        return $this->periods->transaction(function () use ($supplierId, $periodId, $sourceInputHash, $header, $actor): array {
            $this->openPeriod($supplierId, $periodId);
            return $this->drafts->open($supplierId, $periodId, $sourceInputHash, $header, $actor);
        });
    }

    public function reset(int $supplierId, int $periodId, int $draftId, int $expectedRevision,
        string $sourceInputHash, array $header, int $actor): array
    {
        $this->validate($sourceInputHash, $header, $actor);
        return $this->mutate($supplierId, $periodId, $draftId, $actor,
            fn (): array => $this->drafts->reset($supplierId, $draftId, $expectedRevision, $sourceInputHash, $header));
    }

    public function patchDecisions(int $supplierId, int $periodId, int $draftId, int $expectedRevision,
        array $upserts, array $deletes, int $actor): array
    {
        return $this->patch($supplierId, $periodId, $draftId, $expectedRevision, 'decision', $upserts, $deletes, $actor);
    }

    public function patchActivities(int $supplierId, int $periodId, int $draftId, int $expectedRevision,
        array $upserts, array $deletes, int $actor): array
    {
        return $this->patch($supplierId, $periodId, $draftId, $expectedRevision, 'activity', $upserts, $deletes, $actor);
    }

    public function patchFundingPools(int $supplierId, int $periodId, int $draftId, int $expectedRevision,
        array $upserts, array $deletes, int $actor): array
    {
        return $this->patch($supplierId, $periodId, $draftId, $expectedRevision, 'pool', $upserts, $deletes, $actor);
    }

    public function patchFundingLinks(int $supplierId, int $periodId, int $draftId, int $expectedRevision,
        array $upserts, array $deletes, int $actor): array
    {
        return $this->patch($supplierId, $periodId, $draftId, $expectedRevision, 'link', $upserts, $deletes, $actor);
    }

    public function patchFundingMembers(int $supplierId, int $periodId, int $draftId, int $expectedRevision,
        string $poolKind, string $anchor, array $upserts, array $deletes, int $actor): array
    {
        return $this->mutate($supplierId, $periodId, $draftId, $actor,
            fn (): array => $this->drafts->patchMembers($supplierId, $draftId, $expectedRevision, $poolKind, $anchor, $upserts, $deletes));
    }

    private function capture(int $supplierId, int $periodId, array $profile, ?array $method): array
    {
        if ($this->materializer === null || $this->snapshots === null) throw new \LogicException('Current J6 source capture is not configured.');
        $source = $this->materializer->materializeCurrent($supplierId, $periodId);
        $manifest = $this->snapshots->manifest($supplierId, $source->id, true);
        $period = (array) ($manifest['header']['period'] ?? []);
        $header = ['profile' => $profile];
        if ($method !== null) $header['method'] = $method;
        NonprofitTaxRules::assessment($header);
        $method === null ? NonprofitTaxRules::profile($profile, $period) : NonprofitTaxRules::documentedProfile($header, $period);
        return [$source->id, $source->inputHash, $header];
    }

    private function patch(int $supplierId, int $periodId, int $draftId, int $expectedRevision,
        string $kind, array $upserts, array $deletes, int $actor): array
    {
        return $this->mutate($supplierId, $periodId, $draftId, $actor,
            fn (): array => $this->drafts->patchRecords($supplierId, $draftId, $expectedRevision, $kind, $upserts, $deletes));
    }

    private function mutate(int $supplierId, int $periodId, int $draftId, int $actor, callable $mutation): array
    {
        if ($actor <= 0 || $draftId <= 0) throw new \InvalidArgumentException('Draft mutation identity is invalid.');
        return $this->periods->transaction(function () use ($supplierId, $periodId, $draftId, $actor, $mutation): array {
            $this->openPeriod($supplierId, $periodId);
            $draft = $this->drafts->find($supplierId, $draftId, true);
            if ($draft['period_id'] !== $periodId || $draft['created_by'] !== $actor) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_not_found', 'Koncept daňového posouzení nebyl nalezen.', 404);
            }
            return $mutation();
        });
    }

    private function openPeriod(int $supplierId, int $periodId): void
    {
        $statuses = $this->periods->lockPeriods($supplierId);
        if (!isset($statuses[$periodId])) throw new NonprofitTaxAssessmentException('nonprofit_tax_period_not_found', 'Účetní období nebylo nalezeno.', 404);
        if (!in_array($statuses[$periodId], ['open', 'closing'], true)) {
            throw new NonprofitTaxAssessmentException('nonprofit_tax_period_locked', 'V uzamčeném období nelze upravovat koncept daňového posouzení.', 409);
        }
    }

    private function validate(string $sourceInputHash, array $header, int $actor): void
    {
        if ($actor <= 0 || preg_match('/^[a-f0-9]{64}$/D', $sourceInputHash) !== 1
            || !is_array($header['profile'] ?? null) || (isset($header['method']) && !is_array($header['method']))) {
            throw new \InvalidArgumentException('Draft header is invalid.');
        }
    }
}

