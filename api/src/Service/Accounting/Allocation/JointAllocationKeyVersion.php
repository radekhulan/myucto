<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Allocation;

final class JointAllocationKeyVersion
{
    public const BASES = ['hours', 'area', 'revenue', 'fixed'];

    public static function normalize(array $input): array
    {
        $from = self::date($input['valid_from'] ?? null);
        $to = self::date($input['valid_to'] ?? null);
        if ($from >= $to) {
            throw new JointAllocationException('allocation_invalid_interval', 'Konec účinnosti musí následovat po začátku.');
        }
        $basis = $input['basis'] ?? null;
        $explanation = is_string($input['explanation'] ?? null) ? trim($input['explanation']) : '';
        if (!in_array($basis, self::BASES, true) || $explanation === '' || mb_strlen($explanation) > 4000) {
            throw new JointAllocationException('allocation_invalid_basis', 'Zvolte doložený podklad rozdělení a vysvětlete použité váhy.');
        }
        $version = [
            'valid_from' => $from,
            'valid_to' => $to,
            'basis' => $basis,
            'explanation' => $explanation,
            'rounding_policy' => JointAllocationCalculator::ROUNDING_POLICY,
            'cells' => JointAllocationCalculator::cells($input['cells'] ?? []),
        ];
        $version['allocation_mode'] = $input['allocation_mode'] ?? 'weighted';
        if (!in_array($version['allocation_mode'], ['weighted', 'explicit_amounts'], true)) {
            throw new JointAllocationException('allocation_invalid_mode', 'Zvolte vážené rozdělení nebo doložené přesné částky.');
        }
        return $version + ['content_hash' => self::hash($version)];
    }

    public static function decodeCells(array $row): array
    {
        try {
            $stored = json_decode($row['cells_json'], true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::corrupt();
        }
        if (!is_array($stored)) self::corrupt();
        $legacy = array_is_list($stored);
        if (!$legacy && (!is_array($stored['cells'] ?? null) || !is_string($stored['allocation_mode'] ?? null))) self::corrupt();
        $row['cells'] = $legacy ? $stored : $stored['cells'];
        $row['allocation_mode'] = $legacy ? 'weighted' : $stored['allocation_mode'];
        try {
            $normalized = self::normalize($row);
        } catch (JointAllocationException) {
            self::corrupt();
        }
        if (($row['rounding_policy'] ?? null) !== $normalized['rounding_policy']) self::corrupt();
        $valid = is_string($row['content_hash'] ?? null) && hash_equals($row['content_hash'], $normalized['content_hash']);
        if (!$valid && $legacy) {
            unset($normalized['content_hash'], $normalized['allocation_mode']);
            $valid = is_string($row['content_hash'] ?? null) && hash_equals($row['content_hash'], self::hash($normalized));
        }
        if (!$valid) self::corrupt();
        unset($row['cells_json']);
        return $row;
    }

    public static function decodeSnapshot(array $batch): array
    {
        try {
            $snapshot = json_decode($batch['snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::corrupt();
        }
        if (!is_array($snapshot) || !is_string($batch['preview_hash'] ?? null) || !is_string($snapshot['preview_hash'] ?? null)
            || !hash_equals($batch['preview_hash'], $snapshot['preview_hash'])) self::corrupt();
        $canonical = $snapshot;
        unset($canonical['preview_hash']);
        if (!hash_equals($batch['preview_hash'], self::hash($canonical))
            || (int) ($snapshot['supplier_id'] ?? 0) !== (int) $batch['supplier_id']
            || (int) ($snapshot['key_version_id'] ?? 0) !== (int) $batch['key_version_id']
            || ($snapshot['target_date'] ?? null) !== $batch['target_date']
            || (int) ($snapshot['key']['id'] ?? 0) !== (int) $batch['key_version_id']) self::corrupt();
        return $snapshot;
    }

    private static function corrupt(): never
    {
        throw new JointAllocationException('allocation_changed', 'Podklady alokace neodpovídají uloženému otisku.', 409);
    }

    public static function encodeCells(array $version): string
    {
        return self::encodeJson(['format' => 1, 'allocation_mode' => $version['allocation_mode'], 'cells' => $version['cells']]);
    }

    public static function assertDate(array $version, string $date): void
    {
        self::date($date);
        if ($date < $version['valid_from'] || $date >= $version['valid_to']) {
            throw new JointAllocationException('allocation_key_not_effective', 'Verze klíče není k datu přeúčtování účinná.', 409);
        }
    }

    public static function assertNoOverlap(array $candidate, array $versions): void
    {
        foreach ($versions as $version) {
            if ($candidate['valid_from'] < $version['valid_to'] && $version['valid_from'] < $candidate['valid_to']) {
                throw new JointAllocationException('allocation_key_overlap', 'Účinnost verzí téhož klíče se nesmí překrývat.', 409);
            }
        }
    }

    public static function date(mixed $date): string
    {
        if (!is_string($date) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)) {
            throw new JointAllocationException('allocation_invalid_date', 'Zadejte platné datum ve formátu RRRR-MM-DD.');
        }
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        if ($year < 1900 || !checkdate($month, $day, $year)) {
            throw new JointAllocationException('allocation_invalid_date', 'Zadejte platné účetní datum.');
        }
        return $date;
    }

    public static function hash(array $value): string
    {
        $canonical = static function (mixed $node) use (&$canonical): mixed {
            if (!is_array($node)) {
                return $node;
            }
            if (!array_is_list($node)) {
                ksort($node, SORT_STRING);
            }
            return array_map($canonical, $node);
        };
        return hash('sha256', self::encodeJson($canonical($value)));
    }

    public static function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
