<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotBatchReader;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotCursor;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotHeaderV2;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotPageV2;

final readonly class J6CurrentProviderFactory
{
    public function __construct(
        private NonprofitTaxSnapshotBatchReader $reader,
        private NonprofitTaxSnapshotHasher $hasher,
        private J6CurrentFundingEvidenceProvider $funding,
    ) {}

    public function create(int $supplierId, int $periodId, bool $currentRead = false): CurrentJ6SourceProvider
    {
        return new CurrentJ6SourceProvider($this->reader,
            new J6ReadContext($supplierId, $periodId, J6ReadMode::CURRENT, currentRead: $currentRead), $this->funding);
    }

    public function header(int $supplierId, int $periodId, bool $currentRead = false): SnapshotHeaderV2
    {
        return $this->reader->header($supplierId, $periodId, $currentRead);
    }

    public function page(int $supplierId, int $periodId, ?SnapshotCursor $after = null, int $limit = 500,
        bool $currentRead = false): SnapshotPageV2
    {
        return $this->reader->page($supplierId, $periodId, $after, $limit, $currentRead);
    }

    public function fingerprint(int $supplierId, int $periodId, bool $currentRead = false): J6CurrentSourceFingerprint
    {
        $provider = $this->create($supplierId, $periodId, $currentRead);
        $header = $provider->header();
        $root = $this->hasher->startRoot();
        $ordinal = $rows = $findings = 0;
        foreach ($provider->pages(null, 500) as $page) {
            if ($page->rows === [] && $page->findings === []) continue;
            $chunk = $this->hasher->chunk($ordinal, $page);
            $this->hasher->updateRoot($root, $chunk['descriptor']);
            $rows += $chunk['descriptor']['row_count'];
            $findings += $chunk['descriptor']['finding_count'];
            ++$ordinal;
        }
        $rootHash = $this->hasher->finishRoot($root, $ordinal);
        $inputHash = $this->hasher->input($header, $this->hasher->header($header), $rootHash, $rows, $findings, $ordinal);
        return new J6CurrentSourceFingerprint($header, $inputHash, $rootHash, $rows, $findings, $ordinal);
    }
}
