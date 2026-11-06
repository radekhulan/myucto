<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Repository\NonprofitTaxCalculationManifestRepository;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final readonly class HistoricJ6CalculationProvider
{
    public function __construct(
        private NonprofitTaxCalculationManifestRepository $manifests,
        private J6CalculationPageHasher $hasher,
        private J6HistoricAssessmentBinding $binding,
    ) {}

    public function pages(string $kind, int $limit = 500): iterable
    {
        J6CalculationPageHasher::kind($kind);
        if ($limit < 1 || $limit > 500) throw new \InvalidArgumentException('Historic J6 calculation page limit is invalid.');
        $manifest = $this->manifest($kind);
        $ordinal = -1;
        $buffer = [];
        do {
            $chunks = $this->manifests->chunkPage($this->binding->supplierId, $manifest['id'], $ordinal, 4);
            foreach ($chunks as $chunk) {
                if ($chunk['chunk_kind'] !== $kind || $chunk['schema_version'] !== J6CalculationPageHasher::SCHEMA
                    || !hash_equals((string) $chunk['link_hash'], (string) $chunk['chunk_hash'])) {
                    throw new \UnexpectedValueException('Historic J6 calculation chunk binding is invalid.');
                }
                $payload = json_decode($chunk['payload_json'], true, flags: JSON_THROW_ON_ERROR);
                $this->hasher->verify($payload, (string) $chunk['chunk_hash']);
                $ordinal = (int) $chunk['ordinal'];
                foreach ($payload['rows'] as $row) {
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

    public function page(string $kind, int $afterOrdinal = -1): array
    {
        $manifest = $this->manifest($kind);
        $chunks = $this->manifests->chunkPage($this->binding->supplierId, $manifest['id'], $afterOrdinal, 1);
        if ($chunks === []) return ['rows' => [], 'next_cursor' => null];
        $chunk = $chunks[0];
        if ($chunk['chunk_kind'] !== $kind || $chunk['schema_version'] !== J6CalculationPageHasher::SCHEMA
            || !hash_equals((string) $chunk['link_hash'], (string) $chunk['chunk_hash'])) {
            throw new \UnexpectedValueException('Historic J6 calculation chunk binding is invalid.');
        }
        $payload = json_decode($chunk['payload_json'], true, flags: JSON_THROW_ON_ERROR);
        $this->hasher->verify($payload, (string) $chunk['chunk_hash']);
        $ordinal = (int) $chunk['ordinal'];
        return ['rows' => $payload['rows'], 'next_cursor' => $ordinal + 1 < $manifest['chunk_count'] ? $ordinal : null];
    }

    public function summary(): array
    {
        $summary = null;
        foreach (['decision', 'result', 'coverage', 'usage'] as $kind) {
            $candidate = $this->manifest($kind)['summary'];
            if ($summary !== null && !hash_equals(NonprofitTaxRules::canonicalJson($summary), NonprofitTaxRules::canonicalJson($candidate))) {
                throw new \UnexpectedValueException('Historic J6 calculation summaries do not match.');
            }
            $summary = $candidate;
        }
        return $summary ?? [];
    }

    public function manifest(string $kind): array
    {
        J6CalculationPageHasher::kind($kind);
        $id = $this->binding->manifestIds()[$kind];
        $manifest = $this->manifests->manifest($this->binding->supplierId, $id);
        if ($manifest['period_id'] !== $this->binding->periodId || $manifest['chunk_kind'] !== $kind
            || !hash_equals((string) $manifest['calculation_input_hash'], $this->binding->calculationInputHash)
            || !hash_equals((string) $manifest['root_hash'], $this->binding->rootHashes[$kind])
            || ($manifest['header']['supplier_id'] ?? null) !== $this->binding->supplierId
            || ($manifest['header']['period_id'] ?? null) !== $this->binding->periodId
            || !hash_equals((string) ($manifest['header']['snapshot_input_hash'] ?? ''), $this->binding->snapshotInputHash)
            || !hash_equals((string) ($manifest['header']['source_input_hash'] ?? ''), $this->binding->sourceInputHash)) {
            throw new \UnexpectedValueException('Historic J6 calculation manifest belongs to another assessment.');
        }
        $boundSourceManifest = $manifest['header']['source_manifest_id'] ?? null;
        if ($boundSourceManifest !== null && $boundSourceManifest !== $this->binding->sourceManifestId) {
            throw new \UnexpectedValueException('Historic J6 calculation source manifest binding changed.');
        }
        return $manifest;
    }
}
