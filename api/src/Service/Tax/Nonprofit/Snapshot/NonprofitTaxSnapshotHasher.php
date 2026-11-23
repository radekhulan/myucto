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

    /**
     * Obsah podkladů pro otisk {@see contentInput()}: zvlášť proud řádků a proud zjištění
     * v pořadí, v jakém je čtečka vydá, bez hranic stránek a kurzorů. Stránky jdou přes
     * všechny řádky deníku období, takže hranice i kurzory posouval i zápis, který do
     * posouzení nevstupuje (daň 591/341, úhrada, uzavření knih), a potvrzené posouzení
     * zastaralo (#397). Integritu uložených stránek dál drží kořen {@see startRoot()}.
     *
     * @return array{rows:\HashContext, findings:\HashContext}
     */
    public function startContent(): array
    {
        $rows = hash_init('sha256');
        hash_update($rows, "J6ROWS3\0" . SnapshotHeaderV2::SCHEMA . "\0");
        $findings = hash_init('sha256');
        hash_update($findings, "J6FINDINGS3\0" . SnapshotHeaderV2::SCHEMA . "\0");
        return ['rows' => $rows, 'findings' => $findings];
    }

    /** @param array{rows:\HashContext, findings:\HashContext} $content */
    public function updateContent(array $content, array $rows, array $findings): void
    {
        foreach ($rows as $row) hash_update($content['rows'], $this->row($row));
        foreach ($findings as $finding) {
            $json = NonprofitTaxRules::canonicalJson($finding);
            hash_update($content['findings'], pack('N', strlen($json)) . $json);
        }
    }

    /** @param array{rows:\HashContext, findings:\HashContext} $content */
    public function finishContent(array $content): string
    {
        return hash('sha256', "J6CONTENT3\0" . hash_final($content['rows']) . "\0" . hash_final($content['findings']));
    }

    /**
     * Otisk podkladů posouzení (`input_hash`): hlavička a obsah ({@see finishContent()}),
     * nezávisle na tom, jak jsou podklady rozdělené do stránek.
     */
    public function contentInput(SnapshotHeaderV2 $header, string $headerHash, string $contentHash, int $rowCount, int $findingCount): string
    {
        foreach ([$headerHash, $contentHash] as $hash) if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) throw new \InvalidArgumentException('Snapshot input hash material is invalid.');
        return NonprofitTaxRules::hash(['domain' => 'J6INPUT3', 'schema' => SnapshotHeaderV2::SCHEMA,
            'header_hash' => $headerHash, 'content_hash' => $contentHash, 'row_count' => $rowCount,
            'finding_count' => $findingCount, 'ruleset' => $header->data['ruleset'],
            'source_fact_schemas' => $header->data['source_fact_schemas']]);
    }

    /**
     * Původní otisk podkladů nad kořenem stránek. Jen pro ověření manifestů uložených před
     * {@see contentInput()}; nové otisky se jím nepočítají.
     */
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
