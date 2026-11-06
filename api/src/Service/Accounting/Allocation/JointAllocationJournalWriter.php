<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Allocation;

interface JointAllocationJournalWriter
{
    public function insertCurrentAllocation(int $supplierId, int $batchId): int;

    public function reverseCurrentAllocation(int $supplierId, int $cancellationId): int;
}
