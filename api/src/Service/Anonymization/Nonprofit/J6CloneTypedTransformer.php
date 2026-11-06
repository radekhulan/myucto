<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Nonprofit;

use MyInvoice\Service\Anonymization\TextScrubber;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotHeaderV2;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotPageV2;
use MyInvoice\Service\Tax\Nonprofit\SourceFacts\MembershipJ6SourceFacts;

final readonly class J6CloneTypedTransformer
{
    private const SOURCE_FIELDS = [
        'id', 'line_id', 'entry_id', 'line_no', 'account_id', 'account_code', 'date', 'kind', 'amount_cents',
        'activity', 'activity_kind_id', 'tax_regime', 'mandatory_non_deductible', 'neutral', 'axes', 'provenance',
        'origin', 'source_type', 'source_id', 'allocation', 'module_facts',
    ];

    private const HEADER_FIELDS = [
        'schema', 'scope', 'supplier_id', 'period_id', 'period', 'frameworks', 'ruleset', 'funding_history_mode',
        'source_fact_schemas', 'legal_profile', 'svj_module_proof', 'entity_header_receipt', 'legal_profile_receipt',
        'source_manifest_id', 'source_root_hash',
    ];

    private const FREE_TEXT = [
        'evidence', 'reason', 'note', 'description', 'display_name', 'income_attribution_evidence',
        'election_evidence', 'prior_period_review',
    ];

    private const PRESERVED_STRINGS = [
        'schema', 'scope', 'ruleset', 'status', 'kind', 'type', 'source_type', 'activity', 'tax_regime', 'semantic_key',
        'assignment_origin', 'assignment_origin_ref', 'account_code', 'currency_code', 'fx_rate', 'role', 'operation',
        'title', 'cohort', 'legal_basis', 'regime', 'method', 'entity_type', 'entity_kind', 'organisation_form',
        'relationship', 'tax_treatment', 'pool_kind', 'anchor', 'member_id', 'source_id', 'cost_id', 'id', 'date',
        'starts_on', 'ends_on', 'effective_from', 'valid_from', 'valid_to', 'recorded_at', 'recorded_at_utc', 'group',
        'accounting_date', 'entry_date', 'mode', 'profile', 'reduction_band', 'income_treatment', 'legal_basis_code',
    ];

    private const EXTERNAL_RECEIPTS = [
        'external_provenance', 'external_receipt', 'install_receipt', 'transport_receipt',
    ];

    public function __construct(
        private TextScrubber $scrubber,
        private ?J6MembershipModuleFactRebaser $membershipFacts = null,
    ) {}

    public function header(SnapshotHeaderV2 $header): SnapshotHeaderV2
    {
        $data = $header->data;
        $this->assertKnownFields($data, self::HEADER_FIELDS, 'header');
        foreach (['legal_profile', 'svj_module_proof', 'entity_header_receipt', 'legal_profile_receipt'] as $field) {
            if (isset($data[$field])) $data[$field] = $this->semantic($data[$field], 'header.' . $field);
        }
        return new SnapshotHeaderV2($data);
    }

    public function sourcePage(SnapshotPageV2 $page): SnapshotPageV2
    {
        $rows = array_map($this->sourceRow(...), $page->rows);
        $rows = $this->rebaseMembershipFacts($rows);

        foreach ($page->findings as $finding) {
            if (!is_array($finding) || !is_int($finding['line_id'] ?? null) || !is_string($finding['code'] ?? null)) {
                throw new \UnexpectedValueException('J6 clone source finding is not typed.');
            }
            $this->assertKnownFields($finding, ['line_id', 'code'], 'finding');
        }
        return new SnapshotPageV2($rows, $page->findings, $page->firstCursor, $page->lastCursor,
            $page->nextCursor, $page->scannedCount);
    }

    public function legacySourcePage(array $rows): array
    {
        if ($rows === [] || count($rows) > 500 || !array_is_list($rows)) {
            throw new \InvalidArgumentException('J6 clone legacy source page is invalid.');
        }
        $transformed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new \UnexpectedValueException('J6 clone legacy source row is not typed.');
            $this->assertKnownFields($row, self::SOURCE_FIELDS, 'legacy source');
            foreach (['id', 'line_id', 'kind', 'amount_cents', 'activity', 'activity_kind_id', 'tax_regime',
                'mandatory_non_deductible', 'module_facts'] as $field) {
                if (!array_key_exists($field, $row)) throw new \UnexpectedValueException('J6 clone legacy source row is incomplete.');
            }
            if (!is_array($row['module_facts'])) throw new \UnexpectedValueException('J6 clone legacy module facts are invalid.');
            if (array_key_exists('axes', $row)) {
                if (!is_array($row['axes'])) throw new \UnexpectedValueException('J6 clone legacy source axes are invalid.');
                foreach ($row['axes'] as $axis => $value) {
                    if (!in_array($axis, ['activity', 'activity_kind', 'tax_regime'], true) || !is_array($value)) {
                        throw new \UnexpectedValueException('J6 clone legacy source axis is unsupported.');
                    }
                    $this->assertKnownFields($value, ['type_id', 'value_id', 'semantic_key', 'code', 'name',
                        'assignment_origin', 'assignment_origin_ref'], 'legacy axis.' . $axis);
                    if (isset($value['name'])) $row['axes'][$axis]['name'] = $this->scrubber->scrub((string) $value['name']);
                }
            }
            foreach (['provenance', 'origin', 'allocation'] as $field) {
                if (array_key_exists($field, $row)) $row[$field] = $this->semantic($row[$field], 'legacy.source.' . $field);
            }
            foreach ($row['module_facts'] as $schema => $fact) {
                if ($schema !== MembershipJ6SourceFacts::SCHEMA || !is_array($fact)) {
                    throw new \UnexpectedValueException('J6 clone legacy module fact schema is unsupported.');
                }
            }
            $transformed[] = $row;
        }
        return $this->rebaseMembershipFacts($transformed);
    }

    public function legacyRecord(string $kind, array $record): array
    {
        return match ($kind) {
            'finding' => $this->legacyFinding($record),
            'activity', 'decision', 'funding_pool', 'funding_member', 'funding_link' => $this->legacyDecisionRecord($kind, $record),
            'funding_reference' => $this->fundingReference($record),
            'funding_history' => $this->legacyHashedRecord($record, 'record_hash', 'J6FUNDINGHISTORY2'),
            'result' => $this->legacyHashedRecord($record, 'result_row_hash', 'J6RESULT2'),
            'result_usage' => $this->legacyHashedRecord($record, 'usage_record_hash', 'J6FUNDINGUSAGE2'),
            default => throw new \UnexpectedValueException('J6 clone legacy record kind is unsupported.'),
        };
    }

    public function decisionHeader(array $header): array
    {
        foreach (['activities', 'decisions', 'funding', 'funding_sources', 'funding_costs'] as $rows) {
            if (array_key_exists($rows, $header)) throw new \UnexpectedValueException('J6 clone decision header contains annual rows.');
        }
        return $this->semantic($header, 'decision.header');
    }

    public function decisionRecord(string $kind, array $record): array
    {
        $keys = match ($kind) {
            'source' => ['source_id'],
            'activity' => ['activity_kind_id'],
            'funding_pool' => ['pool_kind', 'anchor'],
            'funding_member' => ['pool_kind', 'anchor', 'member_id'],
            'funding_link' => ['source_id', 'cost_id'],
            default => throw new \UnexpectedValueException('J6 clone decision record kind is unsupported.'),
        };
        $allowed = [...$keys, ...($kind === 'funding_member' ? [] : ['payload', 'record_hash'])];
        $this->assertKnownFields($record, $allowed, 'decision.' . $kind);
        foreach ($keys as $key) {
            if (!is_string($record[$key] ?? null) && !is_int($record[$key] ?? null)) {
                throw new \UnexpectedValueException('J6 clone decision identity is invalid.');
            }
        }
        if ($kind === 'funding_member') return $record;
        if (!is_array($record['payload'] ?? null)) throw new \UnexpectedValueException('J6 clone decision payload is invalid.');
        $record['payload'] = $this->semantic($record['payload'], 'decision.' . $kind . '.payload');
        $identity = array_map(static fn (string $key): string => (string) $record[$key], $keys);
        $record['record_hash'] = NonprofitTaxRules::hash([
            'domain' => 'J6DRAFT.' . $kind,
            'keys' => $identity,
            'payload' => $record['payload'],
        ]);
        return $record;
    }

    public function fundingReference(array $row): array
    {
        $row = $this->semantic($row, 'funding.reference');
        if (isset($row['result_row_hash'])) {
            unset($row['result_row_hash']);
            $row['result_row_hash'] = NonprofitTaxRules::hash(['domain' => 'J6RESULT2', 'row' => $row]);
        }
        return $row;
    }

    public function fundingHistory(array $row): array
    {
        $row = $this->semantic($row, 'funding.history');
        unset($row['record_hash']);
        return $row;
    }

    private function legacyFinding(array $record): array
    {
        $this->assertKnownFields($record, ['line_id', 'code'], 'legacy finding');
        if (!is_int($record['line_id'] ?? null) || !is_string($record['code'] ?? null)) {
            throw new \UnexpectedValueException('J6 clone legacy finding is not typed.');
        }
        return $record;
    }

    private function legacyDecisionRecord(string $kind, array $record): array
    {
        $typedKind = $kind === 'decision' ? 'source' : $kind;
        $keys = match ($typedKind) {
            'source' => ['source_id'],
            'activity' => ['activity_kind_id'],
            'funding_pool' => ['pool_kind', 'anchor'],
            'funding_member' => ['pool_kind', 'anchor', 'member_id'],
            'funding_link' => ['source_id', 'cost_id'],
            default => throw new \UnexpectedValueException('J6 clone legacy decision record kind is unsupported.'),
        };
        $allowed = [...$keys, ...($typedKind === 'funding_member' ? [] : ['payload', 'record_hash'])];
        $this->assertKnownFields($record, $allowed, 'legacy decision.' . $kind);
        foreach ($keys as $key) {
            if (!is_string($record[$key] ?? null) && !is_int($record[$key] ?? null)) {
                throw new \UnexpectedValueException('J6 clone legacy decision identity is invalid.');
            }
        }
        if ($typedKind === 'funding_member') return $record;
        if (!is_array($record['payload'] ?? null) || !is_string($record['record_hash'] ?? null)) {
            throw new \UnexpectedValueException('J6 clone legacy decision payload is invalid.');
        }
        $record['payload'] = $this->semantic($record['payload'], 'legacy.decision.' . $kind . '.payload');
        return $record;
    }

    private function legacyHashedRecord(array $record, string $hashField, string $domain): array
    {
        $record = $this->semantic($record, 'legacy.record');
        if (array_key_exists($hashField, $record)) {
            if (!is_string($record[$hashField]) || preg_match('/^[a-f0-9]{64}$/D', $record[$hashField]) !== 1) {
                throw new \UnexpectedValueException('J6 clone legacy derived hash is invalid.');
            }
            unset($record[$hashField]);
            $record[$hashField] = NonprofitTaxRules::hash(['domain' => $domain, 'row' => $record]);
        }
        return $record;
    }

    private function rebaseMembershipFacts(array $rows): array
    {
        $hasMembershipFacts = false;
        foreach ($rows as $row) {
            if (isset($row['module_facts'][MembershipJ6SourceFacts::SCHEMA])) $hasMembershipFacts = true;
        }
        if ($hasMembershipFacts && $this->membershipFacts === null) {
            throw new \LogicException('J6 clone membership facts require an exact scrub hash map.');
        }
        return $hasMembershipFacts ? $this->membershipFacts->rebaseRows($rows) : $rows;
    }

    private function sourceRow(array $row): array
    {
        $this->assertKnownFields($row, self::SOURCE_FIELDS, 'source');
        foreach (self::SOURCE_FIELDS as $field) {
            if (!array_key_exists($field, $row)) throw new \UnexpectedValueException('J6 clone source row is incomplete.');
        }
        if (!is_array($row['axes']) || !is_array($row['origin']) || !is_array($row['module_facts'])) {
            throw new \UnexpectedValueException('J6 clone source row has invalid typed structures.');
        }
        $axes = [];
        foreach ($row['axes'] as $axis => $value) {
            if (!in_array($axis, ['activity', 'activity_kind', 'tax_regime'], true) || !is_array($value)) {
                throw new \UnexpectedValueException('J6 clone source axis is unsupported.');
            }
            $this->assertKnownFields($value, ['type_id', 'value_id', 'semantic_key', 'code', 'name',
                'assignment_origin', 'assignment_origin_ref'], 'axis.' . $axis);
            foreach (['type_id', 'value_id', 'semantic_key', 'code', 'name', 'assignment_origin', 'assignment_origin_ref'] as $field) {
                if (!array_key_exists($field, $value)) throw new \UnexpectedValueException('J6 clone source axis is incomplete.');
            }
            // Kód osy je typovaný identifikátor historické klasifikace, nese ho i otisk
            // archivu; zůstává bajtově stejný. Anonymizuje se jen zobrazovaný název.
            $value['name'] = $this->scrubber->scrub((string) $value['name']);
            $axes[$axis] = $value;
        }
        $row['axes'] = $axes;
        $row['provenance'] = $this->semantic($row['provenance'], 'source.provenance');
        $row['allocation'] = $this->semantic($row['allocation'], 'source.allocation');
        $row['origin'] = $this->semantic($row['origin'], 'source.origin');
        $facts = [];
        foreach ($row['module_facts'] as $schema => $fact) {
            if ($schema !== MembershipJ6SourceFacts::SCHEMA || !is_array($fact)) {
                throw new \UnexpectedValueException('J6 clone module fact schema is unsupported.');
            }
            $facts[$schema] = $fact;
        }
        $row['module_facts'] = $facts;
        return $row;
    }

    private function semantic(mixed $node, string $path): mixed
    {
        if (!is_array($node)) return $node;
        $out = [];
        foreach ($node as $key => $value) {
            if (!is_int($key) && preg_match('/^[a-z][a-z0-9_]*$/D', $key) !== 1) {
                throw new \UnexpectedValueException('J6 clone typed object contains an invalid field.');
            }
            if (is_string($key) && in_array($key, self::EXTERNAL_RECEIPTS, true)) {
                if (!is_array($value)) throw new \UnexpectedValueException('J6 clone external receipt is not structured.');
                $out[$key] = $value;
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->semantic($value, $path . '.' . $key);
                continue;
            }
            if (!is_string($value)) {
                $out[$key] = $value;
                continue;
            }
            $field = (string) $key;
            if (in_array($field, self::FREE_TEXT, true) || str_ends_with($field, '_evidence')) {
                $out[$key] = $this->scrubber->scrub($value);
                continue;
            }
            if (in_array($field, self::PRESERVED_STRINGS, true) || str_ends_with($field, '_hash')
                || str_ends_with($field, '_id') || str_ends_with($field, '_at') || str_ends_with($field, '_on')) {
                $out[$key] = $value;
                continue;
            }
            throw new \UnexpectedValueException('J6 clone typed string field is unsupported: ' . $path . '.' . $field);
        }
        return $out;
    }

    private function assertKnownFields(array $value, array $allowed, string $scope): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) throw new \UnexpectedValueException('J6 clone ' . $scope . ' has unsupported fields.');
    }
}
