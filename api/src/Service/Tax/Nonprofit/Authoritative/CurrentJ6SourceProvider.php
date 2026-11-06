<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotBatchReader;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotCursor;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotHeaderV2;

final readonly class CurrentJ6SourceProvider implements J6AuthoritativeSourceProvider
{
    public function __construct(
        private NonprofitTaxSnapshotBatchReader $reader,
        private J6ReadContext $context,
        private ?J6FundingEvidenceProvider $funding = null,
    ) {
        if ($context->mode !== J6ReadMode::CURRENT) throw new \InvalidArgumentException('Current J6 provider requires current context.');
    }

    public function context(): J6ReadContext
    {
        return $this->context;
    }

    public function header(): SnapshotHeaderV2
    {
        return $this->reader->header($this->context->supplierId, $this->context->periodId, $this->context->currentRead);
    }

    public function pages(?SnapshotCursor $after = null, int $limit = 500): iterable
    {
        do {
            $page = $this->reader->page($this->context->supplierId, $this->context->periodId, $after, $limit, $this->context->currentRead);
            yield $page;
            $after = $page->nextCursor;
        } while ($after !== null);
    }

    public function fundingReferencePages(int $limit = 500): iterable
    {
        if ($this->funding === null) throw new \LogicException('Current J6 funding evidence provider is missing.');
        yield from $this->funding->fundingReferencePages($this->context, $limit);
    }

    public function fundingHistoryPages(int $limit = 500): iterable
    {
        if ($this->funding === null) throw new \LogicException('Current J6 funding evidence provider is missing.');
        yield from $this->funding->fundingHistoryPages($this->context, $limit);
    }
}
