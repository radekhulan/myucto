<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use MyInvoice\Service\Accounting\Closing\AssetReserveSourceDecision;

final class AssetReserveSourceCloneScrubber
{
    public static function payload(string $json, TextScrubber $scrubber): string
    {
        $original = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($original)) return $scrubber->scrubJson($json);
        $receipts = [];
        $references = [];
        $collect = static function (array $node) use (&$collect, &$receipts, &$references, $scrubber): void {
            if (in_array($node['version'] ?? null, ['asset-reserve-revision-v1', 'asset-reserve-revision-v2'], true)) AssetReserveSourceDecision::verifyRevision($node);
            if (isset($node['previous_hash'])) $references[] = $node['previous_hash'];
            if (isset($node['asset_source_evidence']) && ($node['legal_decision']['reserve_source']['source_hash'] ?? null) !== ($node['asset_source_evidence']['source_hash'] ?? null)) throw AssetReserveSourceDecision::changed();
            foreach ($node as $key => $value) {
                if (!is_array($value)) continue;
                if ($key === 'reserve_source') $references[] = $value['source_hash'] ?? '';
                if (in_array($key, ['asset_source_evidence', 'source_evidence', 'previous_source_evidence'], true)) {
                    AssetReserveSourceDecision::verify($value);
                    $receipts[$value['source_hash']] = AssetReserveSourceDecision::seal(self::receiptText($value, $scrubber));
                } else $collect($value);
            }
        };
        $collect($original);
        foreach ($references as $hash) if (!isset($receipts[$hash])) throw AssetReserveSourceDecision::changed();
        if ($receipts === []) return $scrubber->scrubJson($json);
        $result = json_decode($scrubber->scrubJson($json), true, flags: JSON_THROW_ON_ERROR);
        $restore = static function (array $before, array $after) use (&$restore, $receipts, $scrubber): array {
            if (in_array($before['version'] ?? null, ['asset-reserve-revision-v1', 'asset-reserve-revision-v2'], true)) {
                $revision = self::receiptText($before, $scrubber);
                $revision['reason'] = $scrubber->scrub($before['reason']);
                $revision['continued_use_evidence'] = $scrubber->scrub($before['continued_use_evidence']);
                $revision['previous_source_evidence'] = $receipts[$before['previous_source_evidence']['source_hash']];
                $revision['source_evidence'] = $receipts[$before['source_evidence']['source_hash']];
                $revision['previous_hash'] = $revision['previous_source_evidence']['source_hash'];
                return AssetReserveSourceDecision::sealRevision($revision);
            }
            foreach ($before as $key => $value) {
                if ($key === 'asset_source_evidence') {
                    $after[$key] = $receipts[$value['source_hash']];
                } elseif ($key === 'previous_hash') {
                    if (!isset($receipts[$value])) throw AssetReserveSourceDecision::changed();
                    $after[$key] = $receipts[$value]['source_hash'];
                } elseif ($key === 'reserve_source' && is_array($value)) {
                    $hash = $value['source_hash'] ?? '';
                    if (!isset($receipts[$hash])) throw AssetReserveSourceDecision::changed();
                    $after[$key] = $restore($value, $after[$key]);
                    $after[$key]['source_hash'] = $receipts[$hash]['source_hash'];
                    if (isset($value['association'])) $after[$key]['association'] = $receipts[$hash]['association'];
                } elseif (is_array($value)) {
                    $after[$key] = $restore($value, $after[$key]);
                } elseif (!is_string($value) || in_array($key, ['amount', 'amount_foreign', 'legal_amount', 'account_code', 'side',
                    'currency_code', 'legal_basis', 'version', 'mode', 'allocation_method', 'entry_date', 'document_date'], true)
                    || str_ends_with((string) $key, '_cents') || str_ends_with((string) $key, '_id')) {
                    $after[$key] = $value;
                }
            }
            return $after;
        };
        return json_encode($restore($original, $result), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function receiptText(array $receipt, TextScrubber $scrubber): array
    {
        foreach ($receipt as $key => $value) {
            if ($key === 'cost_center' && is_string($value)) $receipt[$key] = $scrubber->byStrategy('cost_center', $value);
            elseif (is_array($value)) $receipt[$key] = self::receiptText($value, $scrubber);
            elseif (is_string($value) && in_array($key, ['reference', 'evidence', 'cost_center'], true)) $receipt[$key] = $scrubber->scrub($value);
        }
        return $receipt;
    }
}
