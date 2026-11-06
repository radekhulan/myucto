<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Allocation;

use MyInvoice\Repository\JournalEntryRepository;

final class JointAllocationJournalAdapter implements JointAllocationJournalWriter
{
    public function __construct(private readonly JournalEntryRepository $journal) {}

    public function insertCurrentAllocation(int $supplierId, int $batchId): int
    {
        return $this->journal->insertCurrentAllocation($supplierId, $batchId);
    }

    public function reverseCurrentAllocation(int $supplierId, int $cancellationId): int
    {
        return $this->journal->reverseCurrentAllocation($supplierId, $cancellationId);
    }
}
