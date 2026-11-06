<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

final class PropertyRevisionRequest
{
    public static function normalize(string $kind, array $input, bool $create): array
    {
        PropertyInput::fields($input, ['code', 'parent_id', 'expected_revision', 'effective_from', 'effective_to', 'status', 'state', 'reason', 'title_ref', 'idempotency_key']);
        $expected = $input['expected_revision'] ?? null;
        if (!is_int($expected) || $expected < 0 || ($create && $expected !== 0)) PropertyInput::invalid('expected_revision');
        if (!in_array($input['status'] ?? null, ['pending', 'confirmed'], true)) PropertyInput::invalid('status');
        if (!is_array($input['state'] ?? null)) PropertyInput::invalid('state');
        [$from, $to] = PropertyInstant::interval($input['effective_from'] ?? null, $input['effective_to'] ?? null);
        $out = ['expected_revision' => $expected, 'effective_from' => $from, 'effective_to' => $to, 'status' => $input['status'],
            'state' => PropertyState::normalize($kind, $input['state']), 'reason' => PropertyInput::text($input['reason'] ?? null, 'reason', 4000),
            'title_ref' => PropertyInput::text($input['title_ref'] ?? null, 'title_ref', 2000), 'idempotency_key' => PropertyInput::code($input['idempotency_key'] ?? null, 'idempotency_key')];
        if ($create) {
            $out['code'] = PropertyInput::code($input['code'] ?? null, 'code');
            $out['parent_id'] = $kind === 'unit' ? PropertyInput::id($input['parent_id'] ?? null, 'parent_id') : null;
            if ($kind !== 'unit' && ($input['parent_id'] ?? null) !== null) PropertyInput::invalid('parent_id');
        } elseif (array_key_exists('code', $input) || array_key_exists('parent_id', $input)) PropertyInput::invalid('code');
        return $out;
    }
}
