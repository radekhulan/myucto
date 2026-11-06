<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

final class PropertyState
{
    public static function normalize(string $kind, array $input): array
    {
        if ($kind === 'unit') return PropertyUnitState::normalize($input);
        if (!in_array($kind, ['house', 'service'], true)) PropertyInput::invalid('kind');
        PropertyInput::fields($input, $kind === 'house'
            ? ['name', 'active', 'address', 'declaration_ref', 'share_scope', 'registry_complete']
            : ['name', 'active', 'measure', 'measure_label']);
        if (!is_bool($input['active'] ?? null)) PropertyInput::invalid('active');
        $state = ['name' => PropertyInput::text($input['name'] ?? null, 'name'), 'active' => $input['active']];
        if ($kind === 'service') {
            if (!in_array($input['measure'] ?? null, ['person', 'unit', 'm2', 'm3', 'kwh', 'other'], true)) PropertyInput::invalid('measure');
            return $state + ['measure' => $input['measure'], 'measure_label' => PropertyInput::text($input['measure_label'] ?? '', 'measure_label', 80, $input['measure'] !== 'other')];
        }
        if (!is_bool($input['registry_complete'] ?? null) || !is_array($input['address'] ?? null)) PropertyInput::invalid('registry_complete');
        PropertyInput::fields($input['address'], ['street', 'city', 'postal_code', 'country']);
        $address = [];
        foreach (['street', 'city', 'postal_code', 'country'] as $field) $address[$field] = PropertyInput::text($input['address'][$field] ?? null, 'address.' . $field);
        if (!preg_match('/^[A-Z]{2}$/D', $address['country'])) PropertyInput::invalid('address.country');
        return $state + ['address' => $address, 'declaration_ref' => PropertyInput::text($input['declaration_ref'] ?? null, 'declaration_ref', 2000),
            'share_scope' => PropertyInput::code($input['share_scope'] ?? null, 'share_scope'), 'registry_complete' => $input['registry_complete']];
    }
}
