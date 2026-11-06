<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Repository\NonprofitTaxDraftRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Tax\Nonprofit\J6AuthoritativeCalculator;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxAssessmentException;

final readonly class J6AssessmentPreviewService
{
    public function __construct(
        private DimensionPeriodGuard $periods,
        private NonprofitTaxDraftRepository $drafts,
        private J6CurrentProviderFactory $sources,
        private J6AuthoritativeCalculator $calculator,
        private J6PreviewCache $cache,
    ) {}

    public function prepare(J6DraftContext $context): J6CalculationReceipt
    {
        return $this->periods->transaction(function () use ($context): J6CalculationReceipt {
            $statuses = $this->periods->lockPeriods($context->supplierId);
            if (!isset($statuses[$context->periodId])) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_period_not_found', 'Účetní období nebylo nalezeno.', 404);
            }
            $draft = $this->drafts->find($context->supplierId, $context->draftId, true);
            if ($draft['period_id'] !== $context->periodId || $draft['created_by'] !== $context->actor) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_not_found', 'Koncept daňového posouzení nebyl nalezen.', 404);
            }
            if ($draft['revision_no'] !== $context->revision
                || !hash_equals($draft['source_input_hash'], $context->sourceInputHash)) {
                throw new NonprofitTaxAssessmentException('nonprofit_tax_draft_changed', 'Koncept posouzení nebo jeho účetní podklad se změnil.', 409);
            }
            $identity = new J6PreviewIdentity($context->actor, $context->supplierId, $context->periodId,
                $context->draftId, $context->revision, $context->sourceInputHash);
            return $this->calculator->calculate($this->sources->create($context->supplierId, $context->periodId, true),
                new DraftJ6DecisionProvider($this->drafts, $context), $this->cache->createSink($identity));
        });
    }

    public function page(string $token, J6DraftContext $context, string $kind, int $page): array
    {
        return $this->cache->read($token, new J6PreviewIdentity($context->actor, $context->supplierId,
            $context->periodId, $context->draftId, $context->revision, $context->sourceInputHash), $kind, $page);
    }
}
