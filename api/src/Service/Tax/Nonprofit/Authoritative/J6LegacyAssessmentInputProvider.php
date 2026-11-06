<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use JsonMachine\Exception\PathNotFoundException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use JsonMachine\RecursiveItems;
use MyInvoice\Service\Anonymization\Streaming\ChunkedJsonColumnReader;
use MyInvoice\Service\Anonymization\Streaming\FinancialArchiveJsonColumn;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotHeaderV2;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotPageV2;

final readonly class J6LegacyAssessmentInputProvider
{
    public function __construct(
        private ChunkedJsonColumnReader $reader,
        private int $supplierId,
        private int $assessmentId,
        private int $periodId,
        private ?J6LegacyInputTransformer $transformer = null,
    ) {
        if ($supplierId <= 0 || $assessmentId <= 0 || $periodId <= 0) {
            throw new \InvalidArgumentException('Legacy J6 input identity is invalid.');
        }
    }

    public function supplierId(): int { return $this->supplierId; }
    public function assessmentId(): int { return $this->assessmentId; }
    public function periodId(): int { return $this->periodId; }

    public function storedInputHash(): string
    {
        $value = $this->scalar(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, '/input_hash');
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \UnexpectedValueException('Legacy J6 source input hash is invalid.');
        }
        return $value;
    }

    public function assertSupportedShape(): void
    {
        $this->assertRootKeys(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, [
            'supplier_id', 'period', 'frameworks', 'ruleset', 'assessment_support', 'sources', 'findings',
            'accounting_result_cents', 'ledger', 'dimensions', 'joint_projections', 'source_references',
            'neutral_line_ids', 'funding_history', 'funding_reference_sources', 'legal_profile',
            'svj_module_proof', 'source_fact_schemas', 'input_hash',
        ]);
        $this->assertRootKeys(FinancialArchiveJsonColumn::ASSESSMENT_FACTS, [
            'profile', 'method', 'activities', 'decisions', 'funding_sources', 'funding_costs', 'funding',
        ]);
        $this->assertRootKeys(FinancialArchiveJsonColumn::ASSESSMENT_RESULT, [
            'ruleset', 'profile', 'assessment_method', 'accounting_result_cents', 'excluded_revenue_cents',
            'cost_addbacks_cents', 'assessed_result_cents', 'scope', 'dppo_supported', 'activities', 'rows',
            'funding', 'funding_usage', 'external_adjustments',
        ]);
    }

    public function sourceHeader(): SnapshotHeaderV2
    {
        $period = $this->object(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, '/period');
        $frameworks = $this->object(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, '/frameworks');
        $ruleset = $this->scalar(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, '/ruleset');
        $data = ['schema' => SnapshotHeaderV2::SCHEMA, 'scope' => 'current-period-source',
            'supplier_id' => $this->supplierId, 'period_id' => $this->periodId, 'period' => $period,
            'frameworks' => array_values($frameworks), 'ruleset' => $ruleset,
            'funding_history_mode' => 'legacy_frozen_json_v1', 'source_fact_schemas' => []];
        foreach (['legal_profile', 'svj_module_proof', 'source_fact_schemas'] as $field) {
            $value = $this->optionalObject(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, '/' . $field);
            if ($value !== null) $data[$field] = $value;
        }
        return new SnapshotHeaderV2($this->transform('source_header', $data));
    }

    /** @return iterable<SnapshotPageV2> */
    public function sourcePages(int $limit = 500): iterable
    {
        self::limit($limit);
        $page = [];
        foreach ($this->items(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, '/sources') as $row) {
            if (!is_array($row)) throw new \UnexpectedValueException('Legacy J6 source row is invalid.');
            $page[] = $row;
            if (count($page) === $limit) {
                $page = $this->transformPage('source', $page);
                yield new SnapshotPageV2($page, [], null, null, null, count($page));
                $page = [];
            }
        }
        if ($page !== []) {
            $page = $this->transformPage('source', $page);
            yield new SnapshotPageV2($page, [], null, null, null, count($page));
        }
        $findings = [];
        foreach ($this->optionalItems(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, '/findings') as $finding) {
            if (!is_array($finding)) throw new \UnexpectedValueException('Legacy J6 source finding is invalid.');
            $findings[] = $finding;
            if (count($findings) === $limit) {
                yield new SnapshotPageV2([], $this->transformPage('finding', $findings), null, null, null, 0);
                $findings = [];
            }
        }
        if ($findings !== []) yield new SnapshotPageV2([], $this->transformPage('finding', $findings), null, null, null, 0);
    }

    public function decisionProvider(string $sourceInputHash): J6LegacyAssessmentDecisionProvider
    {
        return new J6LegacyAssessmentDecisionProvider($this, $sourceInputHash);
    }

    public function fundingProvider(int $sourceManifestId): J6LegacyAssessmentFundingProvider
    {
        return new J6LegacyAssessmentFundingProvider($this, $sourceManifestId);
    }

    public function decisionHeader(): array
    {
        $header = ['profile' => $this->object(FinancialArchiveJsonColumn::ASSESSMENT_FACTS, '/profile')];
        $method = $this->optionalObject(FinancialArchiveJsonColumn::ASSESSMENT_FACTS, '/method');
        if ($method !== null) $header['method'] = $method;
        return $this->transform('decision_header', $header);
    }

    public function activityPages(int $limit = 500): iterable
    {
        yield from $this->mappedObjectPages(FinancialArchiveJsonColumn::ASSESSMENT_FACTS, '/activities', $limit,
            fn (string $id, array $payload): array => ['activity_kind_id' => $id, 'payload' => $payload,
                'record_hash' => NonprofitTaxRules::hash(['domain' => 'J6LEGACYACTIVITY1', 'id' => $id, 'payload' => $payload])], 'activity');
    }

    public function decisionPages(int $limit = 500): iterable
    {
        yield from $this->mappedObjectPages(FinancialArchiveJsonColumn::ASSESSMENT_FACTS, '/decisions', $limit,
            fn (string $id, array $payload): array => ['source_id' => $id, 'payload' => $payload,
                'record_hash' => NonprofitTaxRules::hash(['domain' => 'J6LEGACYDECISION1', 'id' => $id, 'payload' => $payload])], 'decision');
    }

    public function fundingPoolPages(int $limit = 500): iterable
    {
        self::limit($limit);
        $page = [];
        foreach (['revenue' => '/funding_sources', 'expense' => '/funding_costs'] as $kind => $pointer) {
            foreach ($this->recursiveItems(FinancialArchiveJsonColumn::ASSESSMENT_FACTS, $pointer, true) as $pool) {
                if (!$pool instanceof RecursiveItems) throw new \UnexpectedValueException('Legacy J6 funding pool is invalid.');
                $payload = $this->materializeWithout($pool, 'members');
                $anchor = $payload['anchor'] ?? null;
                if (!is_string($anchor) || $anchor === '') throw new \UnexpectedValueException('Legacy J6 funding anchor is invalid.');
                $page[] = ['pool_kind' => $kind, 'anchor' => $anchor, 'payload' => $payload];
                if (count($page) === $limit) { yield $this->fundingPoolPage($page); $page = []; }
            }
        }
        if ($page !== []) yield $this->fundingPoolPage($page);
    }

    public function fundingMemberPages(int $limit = 500): iterable
    {
        self::limit($limit);
        $page = [];
        foreach (['revenue' => '/funding_sources', 'expense' => '/funding_costs'] as $kind => $pointer) {
            foreach ($this->recursiveItems(FinancialArchiveJsonColumn::ASSESSMENT_FACTS, $pointer, true) as $pool) {
                if (!$pool instanceof RecursiveItems) throw new \UnexpectedValueException('Legacy J6 funding pool is invalid.');
                $anchor = null;
                $members = fopen('php://temp/maxmemory:1048576', 'w+b');
                if (!is_resource($members)) throw new \RuntimeException('Legacy J6 member spool cannot be opened.');
                try {
                    foreach ($pool as $key => $value) {
                        if ($key === 'anchor') $anchor = $value;
                        if ($key !== 'members') continue;
                        if (!$value instanceof RecursiveItems) throw new \UnexpectedValueException('Legacy J6 funding members are invalid.');
                        foreach ($value as $member) {
                            if (!is_string($member) || $member === '') throw new \UnexpectedValueException('Legacy J6 funding member is invalid.');
                            fwrite($members, $member . "\n");
                        }
                    }
                    if (!is_string($anchor) || $anchor === '') throw new \UnexpectedValueException('Legacy J6 funding anchor is invalid.');
                    rewind($members);
                    while (($member = fgets($members)) !== false) {
                        $page[] = ['pool_kind' => $kind, 'anchor' => $anchor, 'member_id' => rtrim($member, "\r\n")];
                        if (count($page) === $limit) { yield $this->transformPage('funding_member', $page); $page = []; }
                    }
                } finally {
                    fclose($members);
                }
            }
        }
        if ($page !== []) yield $this->transformPage('funding_member', $page);
    }

    public function fundingLinkPages(int $limit = 500): iterable
    {
        self::limit($limit);
        $page = [];
        foreach ($this->optionalItems(FinancialArchiveJsonColumn::ASSESSMENT_FACTS, '/funding') as $payload) {
            if (!is_array($payload)) throw new \UnexpectedValueException('Legacy J6 funding link is invalid.');
            $page[] = ['source_id' => $payload['source_id'] ?? null, 'cost_id' => $payload['cost_id'] ?? null, 'payload' => $payload];
            if (count($page) === $limit) { yield $this->fundingLinkPage($page); $page = []; }
        }
        if ($page !== []) yield $this->fundingLinkPage($page);
    }

    public function fundingReferencePages(int $limit = 500): iterable
    {
        self::limit($limit);
        $page = [];
        foreach ($this->optionalItems(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, '/funding_reference_sources') as $row) {
            if (!is_array($row)) throw new \UnexpectedValueException('Legacy J6 funding reference is invalid.');
            $page[] = $row;
            if (count($page) === $limit) { yield $this->transformPage('funding_reference', $page); $page = []; }
        }
        if ($page !== []) yield $this->transformPage('funding_reference', $page);
    }

    public function fundingHistoryPages(int $limit = 500): iterable
    {
        self::limit($limit);
        $page = [];
        foreach ($this->recursiveItems(FinancialArchiveJsonColumn::ASSESSMENT_SNAPSHOT, '/funding_history', true) as $index => $revision) {
            if (!$revision instanceof RecursiveItems) throw new \UnexpectedValueException('Legacy J6 funding history revision is invalid.');
            $meta = [];
            $records = fopen('php://temp/maxmemory:1048576', 'w+b');
            if (!is_resource($records)) throw new \RuntimeException('Legacy J6 history spool cannot be opened.');
            try {
                foreach ($revision as $key => $value) {
                    if ($key === 'usage') {
                        if (!$value instanceof RecursiveItems) throw new \UnexpectedValueException('Legacy J6 funding usage is invalid.');
                        foreach ($value as $usage) $this->spoolUsage($records, $usage);
                    } else {
                        $meta[(string) $key] = $this->materialize($value);
                    }
                }
                rewind($records);
                while (($line = fgets($records)) !== false) {
                    $usage = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    $record = ['assessment_id' => (int) ($meta['assessment_id'] ?? ((int) $index + 1)),
                        'period_id' => (int) ($meta['period_id'] ?? ((int) $index + 1)),
                        'status' => (string) ($meta['status'] ?? ''), 'current' => ($meta['current'] ?? false) === true] + $usage;
                    $page[] = $record;
                    if (count($page) === $limit) { yield $this->transformPage('funding_history', $page); $page = []; }
                }
            } finally {
                fclose($records);
            }
        }
        if ($page !== []) yield $this->transformPage('funding_history', $page);
    }

    public function resultScalar(string $field): mixed
    {
        if (!in_array($field, ['ruleset', 'profile', 'assessment_method', 'accounting_result_cents',
            'excluded_revenue_cents', 'cost_addbacks_cents', 'assessed_result_cents', 'scope', 'dppo_supported'], true)) {
            throw new \InvalidArgumentException('Legacy J6 result scalar is not allowed.');
        }
        return $this->scalar(FinancialArchiveJsonColumn::ASSESSMENT_RESULT, '/' . $field);
    }

    public function resultRows(): iterable
    {
        $page = [];
        foreach ($this->items(FinancialArchiveJsonColumn::ASSESSMENT_RESULT, '/rows') as $row) {
            if (!is_array($row)) throw new \UnexpectedValueException('Legacy J6 result row is invalid.');
            $page[] = $row;
            if (count($page) === 500) {
                yield from $this->transformPage('result', $page);
                $page = [];
            }
        }
        if ($page !== []) yield from $this->transformPage('result', $page);
    }

    public function resultFundingUsagePages(int $limit = 500): iterable
    {
        self::limit($limit);
        $page = [];
        $records = fopen('php://temp/maxmemory:1048576', 'w+b');
        if (!is_resource($records)) throw new \RuntimeException('Legacy J6 result usage spool cannot be opened.');
        try {
            foreach ($this->recursiveItems(FinancialArchiveJsonColumn::ASSESSMENT_RESULT, '/funding_usage', true) as $usage) {
                $this->spoolUsage($records, $usage);
            }
            rewind($records);
            while (($line = fgets($records)) !== false) {
                $page[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (count($page) === $limit) { yield $this->transformPage('result_usage', $page); $page = []; }
            }
        } finally {
            fclose($records);
        }
        if ($page !== []) yield $this->transformPage('result_usage', $page);
    }

    private function mappedObjectPages(FinancialArchiveJsonColumn $column, string $pointer, int $limit,
        callable $map, string $kind): iterable
    {
        self::limit($limit);
        $page = [];
        foreach ($this->items($column, $pointer) as $id => $payload) {
            if (!is_array($payload)) throw new \UnexpectedValueException('Legacy J6 decision record is invalid.');
            $page[] = $map((string) $id, $payload);
            if (count($page) === $limit) { yield $this->decisionPage($kind, $page); $page = []; }
        }
        if ($page !== []) yield $this->decisionPage($kind, $page);
    }

    private function spoolUsage($records, mixed $usage): void
    {
        if (!$usage instanceof RecursiveItems) throw new \UnexpectedValueException('Legacy J6 funding usage row is invalid.');
        $meta = [];
        $members = fopen('php://temp/maxmemory:1048576', 'w+b');
        if (!is_resource($members)) throw new \RuntimeException('Legacy J6 usage member spool cannot be opened.');
        try {
            foreach ($usage as $key => $value) {
                if ($key === 'members') {
                    if (!$value instanceof RecursiveItems) throw new \UnexpectedValueException('Legacy J6 usage members are invalid.');
                    foreach ($value as $member) {
                        if (!is_string($member) || $member === '') throw new \UnexpectedValueException('Legacy J6 usage member is invalid.');
                        fwrite($members, $member . "\n");
                    }
                } else {
                    $meta[(string) $key] = $this->materialize($value);
                }
            }
            foreach (['anchor', 'net_source_cents', 'prior_drawdown_cents', 'drawdown_cents', 'remaining_cents'] as $field) {
                if (!array_key_exists($field, $meta)) throw new \UnexpectedValueException('Legacy J6 funding usage is incomplete.');
            }
            rewind($members);
            $count = 0;
            while (($member = fgets($members)) !== false) {
                ++$count;
                $row = ['anchor' => (string) $meta['anchor'], 'member_id' => rtrim($member, "\r\n"),
                    'net_source_cents' => (int) $meta['net_source_cents'],
                    'prior_drawdown_cents' => (int) $meta['prior_drawdown_cents'],
                    'drawdown_cents' => (int) $meta['drawdown_cents'], 'remaining_cents' => (int) $meta['remaining_cents']];
                fwrite($records, NonprofitTaxRules::canonicalJson($row) . "\n");
            }
            if ($count === 0) throw new \UnexpectedValueException('Legacy J6 funding usage has no members.');
        } finally {
            fclose($members);
        }
    }

    private function decisionPage(string $kind, array $records): array
    {
        $records = $this->transformPage($kind, $records);
        foreach ($records as &$record) {
            if ($kind === 'activity') $record['record_hash'] = NonprofitTaxRules::hash(['domain' => 'J6LEGACYACTIVITY1',
                'id' => $record['activity_kind_id'], 'payload' => $record['payload']]);
            if ($kind === 'decision') $record['record_hash'] = NonprofitTaxRules::hash(['domain' => 'J6LEGACYDECISION1',
                'id' => $record['source_id'], 'payload' => $record['payload']]);
        }
        unset($record);
        return $records;
    }

    private function fundingPoolPage(array $records): array
    {
        $records = $this->transformPage('funding_pool', $records);
        foreach ($records as &$record) $record['record_hash'] = NonprofitTaxRules::hash([
            'domain' => 'J6LEGACYPOOL1', 'kind' => $record['pool_kind'], 'payload' => $record['payload']]);
        unset($record);
        return $records;
    }

    private function fundingLinkPage(array $records): array
    {
        $records = $this->transformPage('funding_link', $records);
        foreach ($records as &$record) $record['record_hash'] = NonprofitTaxRules::hash([
            'domain' => 'J6LEGACYLINK1', 'payload' => $record['payload']]);
        unset($record);
        return $records;
    }

    private function transformPage(string $kind, array $records): array
    {
        if ($records === [] || count($records) > 500 || !array_is_list($records)) {
            throw new \InvalidArgumentException('Legacy J6 transform page is invalid.');
        }
        if ($this->transformer instanceof J6LegacyPageTransformer) {
            $transformed = $this->transformer->transformPage($kind, $records);
            if (!is_array($transformed) || !array_is_list($transformed) || count($transformed) !== count($records)) {
                throw new \UnexpectedValueException('Legacy J6 page transformer returned an invalid page.');
            }
            foreach ($records as $index => $record) {
                if (!is_array($transformed[$index])) throw new \UnexpectedValueException('Legacy J6 page transformer returned an invalid record.');
                $this->assertTransformPolicy($kind, $record, $transformed[$index]);
            }
            return $transformed;
        }
        return array_map(fn (array $record): array => $this->transform($kind, $record), $records);
    }

    private function transform(string $kind, array $record): array
    {
        $transformed = $this->transformer?->transform($kind, $record) ?? $record;
        if (!is_array($transformed)) throw new \UnexpectedValueException('Legacy J6 transformer returned an invalid record.');
        if ($this->transformer !== null) $this->assertTransformPolicy($kind, $record, $transformed);
        return $transformed;
    }

    private function assertTransformPolicy(string $kind, mixed $source, mixed $target, array $path = []): void
    {
        if (is_array($source) || is_array($target)) {
            if (!is_array($source) || !is_array($target) || array_keys($source) !== array_keys($target)) {
                throw new \UnexpectedValueException('Legacy J6 transform changed record structure.');
            }
            foreach ($source as $key => $value) $this->assertTransformPolicy($kind, $value, $target[$key], [...$path, (string) $key]);
            return;
        }
        if ($source === $target) return;
        $leaf = $path[array_key_last($path)] ?? '';
        if (in_array($leaf, ['evidence', 'reason', 'note', 'description', 'name'], true)
            && is_string($source) && is_string($target)) return;
        $financial = ['amount_cents', 'deductible_cents', 'net_source_cents', 'prior_drawdown_cents',
            'drawdown_cents', 'remaining_cents', 'date', 'accounting_date', 'operation', 'status',
            'tax_treatment', 'legal_basis', 'legal_basis_code', 'kind', 'side'];
        $identity = $leaf === 'id' || str_ends_with($leaf, '_id') || str_ends_with($leaf, '_hash')
            || in_array($leaf, ['type_id', 'value_id'], true);
        if (!in_array($leaf, $financial, true) && $identity
            && $this->transformer instanceof J6LegacyMappedIdentityTransformer
            && $this->transformer->permitsIdentityChange($kind, $path, $source, $target)) return;
        throw new \UnexpectedValueException('Legacy J6 transform changed a protected field.');
    }

    private function assertRootKeys(FinancialArchiveJsonColumn $column, array $allowed): void
    {
        $allowed = array_fill_keys($allowed, true);
        foreach ($this->recursiveItems($column, '') as $key => $value) {
            if (!isset($allowed[(string) $key])) throw new \UnexpectedValueException('Legacy J6 JSON contains an unsupported field.');
            $this->discard($value);
        }
    }

    private function discard(mixed $value): void
    {
        if (!$value instanceof RecursiveItems) return;
        foreach ($value as $nested) $this->discard($nested);
    }

    private function materializeWithout(RecursiveItems $items, string $excluded): array
    {
        $out = [];
        foreach ($items as $key => $value) {
            if ($key === $excluded) {
                if (!$value instanceof RecursiveItems) throw new \UnexpectedValueException('Legacy J6 excluded collection is invalid.');
                foreach ($value as $_) {}
                continue;
            }
            $out[(string) $key] = $this->materialize($value);
        }
        return $out;
    }

    private function materialize(mixed $value): mixed
    {
        return $value instanceof RecursiveItems ? $value->toArray() : $value;
    }

    private function object(FinancialArchiveJsonColumn $column, string $pointer): array
    {
        $out = iterator_to_array($this->items($column, $pointer));
        if (!is_array($out)) throw new \UnexpectedValueException('Legacy J6 object is invalid.');
        return $out;
    }

    private function optionalObject(FinancialArchiveJsonColumn $column, string $pointer): ?array
    {
        try { return $this->object($column, $pointer); }
        catch (PathNotFoundException) { return null; }
    }

    private function scalar(FinancialArchiveJsonColumn $column, string $pointer): mixed
    {
        $seen = false;
        $value = null;
        foreach ($this->items($column, $pointer) as $item) {
            if ($seen) throw new \UnexpectedValueException('Legacy J6 scalar is ambiguous.');
            $seen = true;
            $value = $item;
        }
        if (!$seen || is_array($value) || is_object($value)) throw new \UnexpectedValueException('Legacy J6 scalar is missing.');
        return $value;
    }

    private function items(FinancialArchiveJsonColumn $column, string $pointer): Items
    {
        return Items::fromIterable($this->reader->read($column, $this->supplierId, $this->assessmentId),
            ['pointer' => $pointer, 'decoder' => new ExtJsonDecoder(true, 512, 0)]);
    }

    private function optionalItems(FinancialArchiveJsonColumn $column, string $pointer): iterable
    {
        try { yield from $this->items($column, $pointer); }
        catch (PathNotFoundException) { return; }
    }

    private function recursiveItems(FinancialArchiveJsonColumn $column, string $pointer, bool $optional = false): iterable
    {
        try {
            yield from RecursiveItems::fromIterable($this->reader->read($column, $this->supplierId, $this->assessmentId),
                ['pointer' => $pointer, 'decoder' => new ExtJsonDecoder(true, 512, 0)]);
        } catch (PathNotFoundException $failure) {
            if (!$optional) throw $failure;
        }
    }

    private static function limit(int $limit): void
    {
        if ($limit < 1 || $limit > 500) throw new \InvalidArgumentException('Legacy J6 page limit is invalid.');
    }
}
