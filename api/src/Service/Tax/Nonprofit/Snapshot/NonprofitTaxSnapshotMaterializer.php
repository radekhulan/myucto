<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Snapshot;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;

final class NonprofitTaxSnapshotMaterializer
{
    public function __construct(
        private readonly Connection $db,
        private readonly NonprofitTaxSnapshotBatchReader $reader,
        private readonly NonprofitTaxSnapshotHasher $hasher,
        private readonly NonprofitTaxSnapshotManifestRepository $manifests,
    ) {}

    public function materializeCurrent(int $supplierId, int $periodId): SnapshotManifestReference
    {
        if ($supplierId <= 0 || $periodId <= 0) throw new \InvalidArgumentException('Snapshot materialization identity is invalid.');
        $guard = new DimensionPeriodGuard($this->db);
        return $guard->transaction(function () use ($guard, $supplierId, $periodId): SnapshotManifestReference {
            $guard->lockPeriods($supplierId);
            $header = $this->reader->header($supplierId, $periodId, true);
            $pages = (function () use ($supplierId, $periodId): iterable {
                $cursor = null;
                do {
                    $page = $this->reader->page($supplierId, $periodId, $cursor, 500, true);
                    yield $page;
                    $next = $page->nextCursor;
                    if ($next !== null && $cursor !== null && $next->toArray() === $cursor->toArray()) throw new \UnexpectedValueException('Snapshot page cursor did not advance.');
                    $cursor = $next;
                } while ($cursor !== null);
            })();
            return (new J6SourceManifestMaterializer($this->db, $this->hasher, $this->manifests))
                ->materialize($header, $pages, true);
        });
    }
}
