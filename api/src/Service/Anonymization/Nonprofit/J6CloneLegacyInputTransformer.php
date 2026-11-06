<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Nonprofit;

use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6LegacyMappedIdentityTransformer;
use MyInvoice\Service\Tax\Nonprofit\Authoritative\J6LegacyPageTransformer;
use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;
use MyInvoice\Service\Tax\Nonprofit\Snapshot\SnapshotHeaderV2;
use MyInvoice\Service\Tax\Nonprofit\SourceFacts\MembershipJ6SourceFacts;

final class J6CloneLegacyInputTransformer implements J6LegacyMappedIdentityTransformer, J6LegacyPageTransformer
{
    private const MEMBERSHIP_HASH_FIELDS = [
        'claim_record_hash', 'member_revision_hash', 'legal_profile_hash', 'operation_record_hash',
        'journal_receipt_hash', 'classification_hash',
    ];

    /** @var array<string,int> */
    private array $permittedIdentityChanges = [];

    public function __construct(private readonly J6CloneTypedTransformer $typed) {}

    public function transform(string $kind, array $record): array
    {
        $this->permittedIdentityChanges = [];
        $transformed = match ($kind) {
            'source_header' => $this->typed->header(new SnapshotHeaderV2($record))->data,
            'decision_header' => $this->typed->decisionHeader($record),
            default => $this->typed->legacyRecord($kind, $record),
        };
        $this->collectPermittedChanges($kind, $record, $transformed);
        return $transformed;
    }

    public function transformPage(string $kind, array $records): array
    {
        if ($records === [] || count($records) > 500 || !array_is_list($records)) {
            throw new \InvalidArgumentException('J6 clone legacy transform page is invalid.');
        }
        $this->permittedIdentityChanges = [];
        $transformed = $kind === 'source'
            ? $this->typed->legacySourcePage($records)
            : array_map(fn (array $record): array => $this->typed->legacyRecord($kind, $record), $records);
        foreach ($records as $index => $record) {
            $this->collectPermittedChanges($kind, $record, $transformed[$index]);
        }
        return $transformed;
    }

    public function permitsIdentityChange(string $kind, array $path, mixed $source, mixed $target): bool
    {
        $key = self::changeKey($kind, $path, $source, $target);
        $remaining = $this->permittedIdentityChanges[$key] ?? 0;
        if ($remaining < 1) return false;
        if ($remaining === 1) unset($this->permittedIdentityChanges[$key]);
        else $this->permittedIdentityChanges[$key] = $remaining - 1;
        return true;
    }

    private function collectPermittedChanges(string $kind, mixed $source, mixed $target, array $path = []): void
    {
        if (is_array($source) || is_array($target)) {
            if (!is_array($source) || !is_array($target) || array_keys($source) !== array_keys($target)) return;
            foreach ($source as $key => $value) {
                $this->collectPermittedChanges($kind, $value, $target[$key], [...$path, (string) $key]);
            }
            return;
        }
        if ($source === $target || !$this->isPermittedPath($kind, $path)) return;
        $key = self::changeKey($kind, $path, $source, $target);
        $this->permittedIdentityChanges[$key] = ($this->permittedIdentityChanges[$key] ?? 0) + 1;
    }

    private function isPermittedPath(string $kind, array $path): bool
    {
        $leaf = $path[array_key_last($path)] ?? '';
        if ($kind === 'source' && count($path) === 3 && $path[0] === 'module_facts'
            && $path[1] === MembershipJ6SourceFacts::SCHEMA
            && in_array($leaf, self::MEMBERSHIP_HASH_FIELDS, true)) {
            return true;
        }
        return match ($kind) {
            'funding_reference', 'result' => count($path) === 1 && $leaf === 'result_row_hash',
            'funding_history' => count($path) === 1 && $leaf === 'record_hash',
            'result_usage' => count($path) === 1 && $leaf === 'usage_record_hash',
            default => false,
        };
    }

    private static function changeKey(string $kind, array $path, mixed $source, mixed $target): string
    {
        return NonprofitTaxRules::hash(['kind' => $kind, 'path' => $path, 'source' => $source, 'target' => $target]);
    }
}
