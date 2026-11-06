<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Snapshot;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final class NonprofitTaxSnapshotHasher
{
    public function row(array $row): string
    {
        return NonprofitTaxRules::hash(['domain' => 'J6ROW2', 'schema' => SnapshotHeaderV2::SCHEMA, 'row' => $row]);
    }

    /** @return array{payload:array<string,mixed>,descriptor:array<string,mixed>} */
    public function chunk(int $ordinal, SnapshotPageV2 $page): array
    {
        if ($ordinal < 0 || ($page->rows === [] && $page->findings === [])) throw new \InvalidArgumentException('Snapshot chunk is empty or has an invalid ordinal.');
        $rowHashes = [];
        foreach ($page->rows as $row) $rowHashes[] = $this->row($row);
        $first = $page->firstCursor?->toArray();
        $last = $page->lastCursor?->toArray();
        $hashInput = ['domain' => 'J6CHUNK2', 'schema' => SnapshotHeaderV2::SCHEMA, 'ordinal' => $ordinal,
            'first_cursor' => $first, 'last_cursor' => $last, 'row_count' => count($page->rows),
            'row_hashes' => $rowHashes, 'findings' => $page->findings];
        $chunkHash = NonprofitTaxRules::hash($hashInput);
        $payload = ['schema' => SnapshotHeaderV2::SCHEMA, 'ordinal' => $ordinal, 'first_cursor' => $first,
            'last_cursor' => $last, 'rows' => $page->rows, 'findings' => $page->findings, 'row_hashes' => $rowHashes];
        $descriptor = ['ordinal' => $ordinal, 'chunk_hash' => $chunkHash, 'row_count' => count($page->rows),
            'finding_count' => count($page->findings), 'first_cursor' => $first, 'last_cursor' => $last];
        return ['payload' => $payload, 'descriptor' => $descriptor];
    }

    public function startRoot(): \HashContext
    {
        $context = hash_init('sha256');
        hash_update($context, "J6ROOT2\0" . SnapshotHeaderV2::SCHEMA . "\0");
        return $context;
    }

    public function updateRoot(\HashContext $context, array $descriptor): void
    {
        $json = NonprofitTaxRules::canonicalJson($descriptor);
        hash_update($context, pack('N', strlen($json)) . $json);
    }

    public function finishRoot(\HashContext $context, int $chunkCount): string
    {
        if ($chunkCount < 0) throw new \InvalidArgumentException('Snapshot chunk count is invalid.');
        hash_update($context, "\0COUNT\0" . pack('N', $chunkCount));
        return hash_final($context);
    }

    public function header(SnapshotHeaderV2 $header): string
    {
        return NonprofitTaxRules::hash(['domain' => 'J6HEADER2', 'schema' => SnapshotHeaderV2::SCHEMA, 'header' => $header->data]);
    }

    public function input(SnapshotHeaderV2 $header, string $headerHash, string $rootHash, int $rowCount, int $findingCount, int $chunkCount): string
    {
        foreach ([$headerHash, $rootHash] as $hash) if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) throw new \InvalidArgumentException('Snapshot input hash material is invalid.');
        return NonprofitTaxRules::hash(['domain' => 'J6INPUT2', 'schema' => SnapshotHeaderV2::SCHEMA,
            'header_hash' => $headerHash, 'root_hash' => $rootHash, 'row_count' => $rowCount,
            'finding_count' => $findingCount, 'chunk_count' => $chunkCount, 'ruleset' => $header->data['ruleset'],
            'source_fact_schemas' => $header->data['source_fact_schemas']]);
    }

    public function verifyChunk(array $payload, string $expectedHash): array
    {
        if (($payload['schema'] ?? null) !== SnapshotHeaderV2::SCHEMA || !is_int($payload['ordinal'] ?? null)
            || !is_array($payload['rows'] ?? null) || !is_array($payload['findings'] ?? null) || !is_array($payload['row_hashes'] ?? null)) {
            throw new \UnexpectedValueException('Snapshot chunk payload is invalid.');
        }
        $rowHashes = [];
        foreach ($payload['rows'] as $row) $rowHashes[] = $this->row($row);
        if ($rowHashes !== $payload['row_hashes']) throw new \UnexpectedValueException('Snapshot row hash mismatch.');
        $page = new SnapshotPageV2($payload['rows'], $payload['findings'], SnapshotCursor::fromArray($payload['first_cursor'] ?? null),
            SnapshotCursor::fromArray($payload['last_cursor'] ?? null), null, count($payload['rows']));
        $chunk = $this->chunk($payload['ordinal'], $page);
        if (!hash_equals($expectedHash, $chunk['descriptor']['chunk_hash'])) throw new \UnexpectedValueException('Snapshot chunk hash mismatch.');
        return $chunk['descriptor'];
    }
}
