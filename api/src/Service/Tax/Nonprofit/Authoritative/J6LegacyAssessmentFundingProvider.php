<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

final readonly class J6LegacyAssessmentFundingProvider implements J6FundingEvidenceProvider
{
    public function __construct(private J6LegacyAssessmentInputProvider $input, private int $sourceManifestId)
    {
        if ($sourceManifestId <= 0) throw new \InvalidArgumentException('Legacy J6 source manifest is invalid.');
    }

    public function fundingReferencePages(J6ReadContext $context, int $limit = 500): iterable
    {
        $this->assertContext($context);
        yield from $this->input->fundingReferencePages($limit);
    }

    public function fundingHistoryPages(J6ReadContext $context, int $limit = 500): iterable
    {
        $this->assertContext($context);
        yield from $this->input->fundingHistoryPages($limit);
    }

    private function assertContext(J6ReadContext $context): void
    {
        if ($context->mode !== J6ReadMode::REBASED_HISTORIC || $context->supplierId !== $this->input->supplierId()
            || $context->periodId !== $this->input->periodId() || $context->assessmentId !== $this->input->assessmentId()
            || $context->sourceManifestId !== $this->sourceManifestId
            || $context->currentRead) {
            throw new \UnexpectedValueException('Legacy J6 funding context changed.');
        }
    }
}
