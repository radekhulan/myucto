<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Snapshot;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Service\Tax\Nonprofit\SourceFacts\MembershipJ6SourceFacts;

final readonly class J6SourceManifestMaterializer
{
    public function __construct(
        private Connection $db,
        private NonprofitTaxSnapshotHasher $hasher,
        private NonprofitTaxSnapshotManifestRepository $manifests,
    ) {}

    public function materialize(SnapshotHeaderV2 $header, iterable $pages, bool $currentRead = true): SnapshotManifestReference
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('J6 source manifest publication requires an owner transaction.');
        $supplierId = $header->data['supplier_id'];
        $periodId = $header->data['period_id'];
        $spool = SnapshotChunkSpool::create();
        try {
            $root = $this->hasher->startRoot();
            $ordinal = $rowCount = $findingCount = $membershipFactCount = 0;
            foreach ($pages as $page) {
                if (!$page instanceof SnapshotPageV2 || count($page->rows) > 500 || count($page->findings) > 4500) {
                    throw new \InvalidArgumentException('J6 source manifest page is invalid.');
                }
                if ($page->rows === [] && $page->findings === []) continue;
                $chunk = $this->hasher->chunk($ordinal, $page);
                $chunkId = $this->manifests->putChunk($supplierId, $chunk);
                $this->hasher->updateRoot($root, $chunk['descriptor']);
                $spool->append($chunkId, $chunk['descriptor']);
                $rowCount += $chunk['descriptor']['row_count'];
                $findingCount += $chunk['descriptor']['finding_count'];
                foreach ($page->rows as $row) if (isset($row['module_facts'][MembershipJ6SourceFacts::SCHEMA])) ++$membershipFactCount;
                ++$ordinal;
            }
            $rootHash = $this->hasher->finishRoot($root, $ordinal);
            $headerHash = $this->hasher->header($header);
            $inputHash = $this->hasher->input($header, $headerHash, $rootHash, $rowCount, $findingCount, $ordinal);
            $existing = $this->manifests->findByInputHash($supplierId, $inputHash, $currentRead);
            if ($existing !== null) return $this->manifests->verify($supplierId, $existing->id, $currentRead);
            $reference = $this->manifests->appendManifest(['supplier_id' => $supplierId, 'period_id' => $periodId,
                'ruleset' => $header->data['ruleset'], 'header' => $header->data, 'header_hash' => $headerHash,
                'root_hash' => $rootHash, 'input_hash' => $inputHash, 'row_count' => $rowCount,
                'finding_count' => $findingCount, 'chunk_count' => $ordinal,
                'membership_fact_count' => $membershipFactCount], $spool->records());
            $verified = $this->manifests->verify($supplierId, $reference->id, $currentRead);
            if ($verified != $reference) throw new \UnexpectedValueException('Stored J6 source manifest changed during verification.');
            return $reference;
        } finally {
            $spool->closeAndDelete();
        }
    }
}
