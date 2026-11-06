<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Service\Accounting\Allocation\JointAllocationKeyVersion;
use MyInvoice\Service\Accounting\Dimension\DimensionStamper;

final class AssetReserveSourceDecision
{
    public static function normalize(mixed $raw, bool $requireHash = true): array
    {
        if (!is_array($raw) || !is_int($raw['asset_id'] ?? null) || $raw['asset_id'] <= 0
            || !in_array($raw['mode'] ?? null, ['capitalization', 'documented_association'], true)
            || ($raw['allocation_method'] ?? null) !== 'capitalization_joint_proportional') throw self::invalid();
        $out = array_intersect_key($raw, array_flip(['asset_id', 'mode', 'allocation_method']));
        foreach (['continued_use_evidence'] as $key) $out[$key] = self::text($raw[$key] ?? null);
        if ($requireHash) {
            if (!is_string($raw['source_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $raw['source_hash'])) throw self::invalid();
            $out['source_hash'] = $raw['source_hash'];
        }
        if ($out['mode'] === 'documented_association') {
            $association = $raw['association'] ?? null;
            if (!is_array($association) || !is_array($association['contributions'] ?? null)
                || $association['contributions'] === [] || count($association['contributions']) > 200) throw self::invalid();
            $out['association'] = ['reference' => self::text($association['reference'] ?? null),
                'evidence' => self::text($association['evidence'] ?? null), 'contributions' => []];
            $seen = [];
            foreach ($association['contributions'] as $cell) {
                if (!is_array($cell) || !is_int($cell['line_id'] ?? null) || $cell['line_id'] <= 0
                    || !is_int($cell['contribution_cents'] ?? null) || $cell['contribution_cents'] <= 0 || isset($seen[$cell['line_id']])) throw self::invalid();
                $seen[$cell['line_id']] = true;
                $out['association']['contributions'][] = ['line_id' => $cell['line_id'], 'contribution_cents' => $cell['contribution_cents']];
            }
            usort($out['association']['contributions'], static fn (array $a, array $b): int => $a['line_id'] <=> $b['line_id']);
        }
        return $out;
    }

    public static function seal(array $receipt): array
    {
        unset($receipt['source_hash']);
        return $receipt + ['source_hash' => JointAllocationKeyVersion::hash($receipt)];
    }

    public static function verify(array $receipt): array
    {
        if (($receipt['version'] ?? null) !== 'asset-reserve-source-v1' || !is_string($receipt['source_hash'] ?? null)
            || !hash_equals(self::seal($receipt)['source_hash'], $receipt['source_hash'])) throw self::changed();
        return $receipt;
    }

    public static function sealRevision(array $revision): array
    {
        unset($revision['revision_hash']);
        return $revision + ['revision_hash' => JointAllocationKeyVersion::hash($revision)];
    }

    public static function verifyRevision(array $revision): array
    {
        if (!in_array($revision['version'] ?? null, ['asset-reserve-revision-v1', 'asset-reserve-revision-v2'], true) || !is_string($revision['revision_hash'] ?? null)
            || !hash_equals(self::sealRevision($revision)['revision_hash'], $revision['revision_hash'])
            || !is_int($revision['revision_number'] ?? null) || $revision['revision_number'] <= 0) throw self::changed();
        self::text($revision['reason'] ?? null);
        self::text($revision['continued_use_evidence'] ?? null);
        $previous = self::verify($revision['previous_source_evidence'] ?? []);
        $next = self::verify($revision['source_evidence'] ?? []);
        if ($previous['asset_id'] !== $next['asset_id'] || $previous['supplier_id'] !== $next['supplier_id']
            || ($revision['previous_hash'] ?? null) !== $previous['source_hash'] || $previous['source_hash'] === $next['source_hash']) throw self::changed();
        if ($revision['version'] === 'asset-reserve-revision-v2') {
            self::purpose($revision['original_purpose'] ?? null);
            if (!is_array($revision['transfers'] ?? null) || !is_array($revision['pinned_entries'] ?? null)
                || !is_int($revision['sequence_entry_id'] ?? null) || !array_key_exists('entry_id', $revision)) throw self::changed();
            AssetReserveCorrection504Builder::lines($revision['transfers'], $next);
        }
        return $revision;
    }

    public static function purpose(mixed $raw): array
    {
        if (!is_array($raw) || ($raw['same_original_asset_and_purpose'] ?? null) !== true
            || !is_string($raw['document_date'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw['document_date'])) throw self::invalid();
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw['document_date']);
        if ($date === false || $date->format('Y-m-d') !== $raw['document_date']) throw self::invalid();
        return ['same_original_asset_and_purpose' => true, 'document_date' => $raw['document_date'],
            'reference' => self::text($raw['reference'] ?? null), 'evidence' => self::text($raw['evidence'] ?? null)];
    }

    public static function correction(array $body): array
    {
        if (!is_string($body['previous_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $body['previous_hash'])) throw self::invalid();
        return ['previous_hash' => $body['previous_hash'], 'reason' => self::text($body['reason'] ?? null),
            'reserve_source' => self::normalize($body['reserve_source'] ?? null, false)];
    }

    public static function allocations(array $receipt, int $budgetCents): array
    {
        self::verify($receipt);
        if ($budgetCents <= 0 || ($receipt['cells'] ?? []) === []) throw self::invalid();
        $cells = $receipt['cells'];
        $amounts = DimensionStamper::distributeCents($budgetCents, array_column($cells, 'weight_cents'));
        $out = [];
        foreach ($cells as $index => $cell) {
            if ($amounts[$index] === 0) continue;
            unset($cell['weight_cents']);
            $out[] = $cell + ['amount' => self::decimal($amounts[$index])];
        }
        return $out;
    }

    public static function decimal(int $cents): string
    {
        return bcdiv((string) $cents, '100', 2);
    }

    public static function invalid(): ClosingException
    {
        return new ClosingException('asset_reserve_source_required', 'Doplňte doložený zdroj majetku a potvrďte společné rozdělení rozpočtu opravy.', 422);
    }

    public static function changed(): ClosingException
    {
        return new ClosingException('asset_reserve_source_changed', 'Doložený zdroj majetku nebo jeho přiřazení se změnily. Obnovte podklady.', 409);
    }

    private static function text(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 2000) throw self::invalid();
        return trim($value);
    }
}
