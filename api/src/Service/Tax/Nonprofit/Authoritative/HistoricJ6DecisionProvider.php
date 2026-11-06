<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Repository\NonprofitTaxCalculationManifestRepository;

final readonly class HistoricJ6DecisionProvider implements J6AssessmentDecisionProvider
{
    public function __construct(
        private NonprofitTaxCalculationManifestRepository $manifests,
        private J6CalculationPageHasher $hasher,
        private J6DecisionContext $context,
    ) {
        if ($context->mode === J6ReadMode::CURRENT) throw new \InvalidArgumentException('Historic J6 decision provider requires frozen context.');
    }

    public function context(): J6DecisionContext
    {
        return $this->context;
    }

    public function header(): array
    {
        $manifest = $this->manifest();
        $header = $manifest['header']['decision_header'] ?? null;
        if (!is_array($header)) throw new \UnexpectedValueException('Historic J6 decision header is missing.');
        return $header;
    }

    public function decisionPages(int $limit = 500): iterable
    {
        foreach ($this->records('source', $limit) as $page) yield array_map(static fn (array $row): array => [
            'source_id' => $row['source_id'], 'payload' => $row['payload'], 'record_hash' => $row['decision_record_hash'],
        ], $page);
    }

    public function activityPages(int $limit = 500): iterable
    {
        foreach ($this->records('activity', $limit) as $page) yield array_map(static fn (array $row): array => [
            'activity_kind_id' => $row['activity_kind_id'], 'payload' => $row['payload'], 'record_hash' => $row['decision_record_hash'],
        ], $page);
    }

    public function fundingPoolPages(int $limit = 500): iterable
    {
        foreach ($this->records('funding_pool', $limit) as $page) yield array_map(static fn (array $row): array => [
            'pool_kind' => $row['pool_kind'], 'anchor' => $row['anchor'], 'payload' => $row['payload'],
            'record_hash' => $row['decision_record_hash'],
        ], $page);
    }

    public function fundingMemberPages(int $limit = 500): iterable
    {
        foreach ($this->records('funding_member', $limit) as $page) yield array_map(static fn (array $row): array => [
            'pool_kind' => $row['pool_kind'], 'anchor' => $row['anchor'], 'member_id' => $row['member_id'],
        ], $page);
    }

    public function fundingLinkPages(int $limit = 500): iterable
    {
        foreach ($this->records('funding_link', $limit) as $page) yield array_map(static fn (array $row): array => [
            'source_id' => $row['source_id'], 'cost_id' => $row['cost_id'], 'payload' => $row['payload'],
            'record_hash' => $row['decision_record_hash'],
        ], $page);
    }

    private function records(string $kind, int $limit): iterable
    {
        if ($limit < 1 || $limit > 500) throw new \InvalidArgumentException('Historic J6 decision page limit is invalid.');
        $this->manifest();
        $ordinal = -1;
        $buffer = [];
        do {
            $chunks = $this->manifests->chunkPage($this->context->supplierId, (int) $this->context->decisionManifestId, $ordinal, 4);
            foreach ($chunks as $chunk) {
                if ($chunk['chunk_kind'] !== 'decision' || $chunk['schema_version'] !== J6CalculationPageHasher::SCHEMA
                    || !hash_equals((string) $chunk['link_hash'], (string) $chunk['chunk_hash'])) {
                    throw new \UnexpectedValueException('Historic J6 decision chunk binding is invalid.');
                }
                $payload = json_decode($chunk['payload_json'], true, flags: JSON_THROW_ON_ERROR);
                $this->hasher->verify($payload, (string) $chunk['chunk_hash']);
                $ordinal = (int) $chunk['ordinal'];
                foreach ($payload['rows'] as $row) {
                    if (($row['record_kind'] ?? null) !== $kind) continue;
                    $buffer[] = $row;
                    if (count($buffer) === $limit) {
                        yield $buffer;
                        $buffer = [];
                    }
                }
            }
        } while (count($chunks) === 4);
        if ($buffer !== []) yield $buffer;
    }

    private function manifest(): array
    {
        $manifest = $this->manifests->manifest($this->context->supplierId, (int) $this->context->decisionManifestId);
        if ($manifest['period_id'] !== $this->context->periodId || $manifest['chunk_kind'] !== 'decision'
            || ($manifest['header']['supplier_id'] ?? null) !== $this->context->supplierId
            || ($manifest['header']['period_id'] ?? null) !== $this->context->periodId
            || !hash_equals((string) ($manifest['header']['snapshot_input_hash'] ?? ''), $this->context->sourceInputHash)) {
            throw new \UnexpectedValueException('Historic J6 decision manifest belongs to another calculation.');
        }
        return $manifest;
    }
}
