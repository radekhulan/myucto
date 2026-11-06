<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotCursor;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotHeaderV2;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotPageV2;

interface J6AuthoritativeSourceProvider
{
    public function context(): J6ReadContext;

    public function header(): SnapshotHeaderV2;

    /** @return iterable<SnapshotPageV2> */
    public function pages(?SnapshotCursor $after = null, int $limit = 500): iterable;

    public function fundingReferencePages(int $limit = 500): iterable;

    public function fundingHistoryPages(int $limit = 500): iterable;
}
