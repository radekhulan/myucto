<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

final readonly class J6HistoricProviderSet
{
    public function __construct(
        public J6AuthoritativeSourceProvider $sources,
        public J6AssessmentDecisionProvider $decisions,
        public HistoricJ6CalculationProvider $calculation,
    ) {}
}
