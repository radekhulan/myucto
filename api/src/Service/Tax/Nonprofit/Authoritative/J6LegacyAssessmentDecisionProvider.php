<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

final readonly class J6LegacyAssessmentDecisionProvider implements J6AssessmentDecisionProvider
{
    private J6DecisionContext $context;

    public function __construct(private J6LegacyAssessmentInputProvider $input, string $sourceInputHash)
    {
        $header = $input->sourceHeader()->data;
        $this->context = new J6DecisionContext($header['supplier_id'], $header['period_id'], J6ReadMode::LEGACY_CONVERSION,
            $sourceInputHash, assessmentId: $input->assessmentId());
    }

    public function context(): J6DecisionContext { return $this->context; }
    public function header(): array { return $this->input->decisionHeader(); }
    public function decisionPages(int $limit = 500): iterable { yield from $this->input->decisionPages($limit); }
    public function activityPages(int $limit = 500): iterable { yield from $this->input->activityPages($limit); }
    public function fundingPoolPages(int $limit = 500): iterable { yield from $this->input->fundingPoolPages($limit); }
    public function fundingMemberPages(int $limit = 500): iterable { yield from $this->input->fundingMemberPages($limit); }
    public function fundingLinkPages(int $limit = 500): iterable { yield from $this->input->fundingLinkPages($limit); }
}
