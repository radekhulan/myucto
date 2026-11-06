<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Snapshot;

final readonly class SnapshotPageV2
{
    public function __construct(
        public array $rows,
        public array $findings,
        public ?SnapshotCursor $firstCursor,
        public ?SnapshotCursor $lastCursor,
        public ?SnapshotCursor $nextCursor,
        public int $scannedCount,
    ) {
        if ($scannedCount < 0 || $scannedCount > 500 || count($rows) > 500 || count($findings) > 4500) throw new \InvalidArgumentException('Snapshot page exceeds its bound.');
        if ($scannedCount === 0 && ($firstCursor !== null || $lastCursor !== null || $nextCursor !== null)) throw new \InvalidArgumentException('Empty snapshot page has a cursor.');
    }
}
