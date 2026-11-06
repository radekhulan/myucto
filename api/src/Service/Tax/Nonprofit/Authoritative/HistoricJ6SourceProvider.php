<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Repository\NonprofitTaxSnapshotManifestRepository;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\NonprofitTaxSnapshotHasher;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotCursor;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotHeaderV2;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotPageV2;

final readonly class HistoricJ6SourceProvider implements J6AuthoritativeSourceProvider
{
    public function __construct(
        private NonprofitTaxSnapshotManifestRepository $manifests,
        private NonprofitTaxSnapshotHasher $hasher,
        private J6ReadContext $context,
        private ?J6FundingEvidenceProvider $funding = null,
    ) {
        if ($context->mode === J6ReadMode::CURRENT) throw new \InvalidArgumentException('Historic J6 provider requires frozen context.');
    }

    public function context(): J6ReadContext
    {
        return $this->context;
    }

    public function header(): SnapshotHeaderV2
    {
        $manifest = $this->manifests->manifest($this->context->supplierId, (int) $this->context->sourceManifestId, $this->context->currentRead);
        if ($manifest['period_id'] !== $this->context->periodId) throw new \UnexpectedValueException('Historic J6 manifest belongs to another period.');
        return new SnapshotHeaderV2($manifest['header']);
    }

    public function pages(?SnapshotCursor $after = null, int $limit = 500): iterable
    {
        if ($after !== null) throw new \InvalidArgumentException('Historic J6 pages use immutable chunk ordinals, not live cursors.');
        $ordinal = -1;
        $batch = min($limit, 4);
        do {
            $stored = $this->manifests->chunkPage($this->context->supplierId, (int) $this->context->sourceManifestId, $ordinal, $batch, false);
            foreach ($stored as $chunk) {
                $payload = json_decode($chunk['payload_json'], true, flags: JSON_THROW_ON_ERROR);
                $this->hasher->verifyChunk($payload, $chunk['chunk_hash']);
                $ordinal = (int) $chunk['ordinal'];
                yield new SnapshotPageV2(
                    $payload['rows'],
                    $payload['findings'],
                    SnapshotCursor::fromArray($payload['first_cursor'] ?? null),
                    SnapshotCursor::fromArray($payload['last_cursor'] ?? null),
                    null,
                    count($payload['rows']),
                );
            }
        } while (count($stored) === $batch);
    }

    public function fundingReferencePages(int $limit = 500): iterable
    {
        if ($this->funding === null) throw new \LogicException('Historic J6 funding evidence provider is missing.');
        yield from $this->funding->fundingReferencePages($this->context, $limit);
    }

    public function fundingHistoryPages(int $limit = 500): iterable
    {
        if ($this->funding === null) throw new \LogicException('Historic J6 funding evidence provider is missing.');
        yield from $this->funding->fundingHistoryPages($this->context, $limit);
    }
}
