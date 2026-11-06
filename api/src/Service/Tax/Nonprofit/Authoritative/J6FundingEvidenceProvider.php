<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

interface J6FundingEvidenceProvider
{
    public function fundingReferencePages(J6ReadContext $context, int $limit = 500): iterable;

    public function fundingHistoryPages(J6ReadContext $context, int $limit = 500): iterable;
}
