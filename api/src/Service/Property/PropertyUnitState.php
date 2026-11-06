<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

final class PropertyUnitState
{
    public const KINDS = ['apartment', 'non_residential', 'garage', 'studio', 'other'];
    public const ROLE_SCOPES = ['communication', 'delivery', 'voting', 'payment_instruction', 'setoff', 'refund_instruction'];

    public static function normalize(array $input): array
    {
        PropertyInput::fields($input, ['unit_no', 'kind', 'active', 'attributes', 'ownerships', 'roles', 'identifiers', 'services']);
        $kind = $input['kind'] ?? null;
        if (!in_array($kind, self::KINDS, true)) PropertyInput::invalid('kind');
        if (!is_bool($input['active'] ?? null)) PropertyInput::invalid('active');
        $attributes = $input['attributes'] ?? null;
        if (!is_array($attributes) || array_is_list($attributes) || count($attributes) > 30) PropertyInput::invalid('attributes');
        foreach ($attributes as $key => &$fraction) {
            PropertyInput::code($key, 'attribute');
            if (!is_array($fraction)) PropertyInput::invalid('attributes.' . $key);
            $fraction = PropertyRational::normalize($fraction);
        }
        unset($fraction);
        if (!isset($attributes['share']) || $attributes['share']['num'] === '0' || PropertyRational::compare($attributes['share'], ['num' => '1', 'den' => '1']) > 0) PropertyInput::invalid('attributes.share');
        ksort($attributes, SORT_STRING);
        $roles = [];
        foreach (PropertyInput::list($input['roles'] ?? [], 'roles') as $role) {
            if (!is_array($role)) PropertyInput::invalid('roles');
            PropertyInput::fields($role, ['client_id', 'role', 'scopes', 'title_ref']);
            $client = PropertyInput::id($role['client_id'] ?? null, 'roles.client_id');
            if (!in_array($role['role'] ?? null, ['payer', 'representative', 'delivery'], true)) PropertyInput::invalid('roles.role');
            $scopes = PropertyInput::list($role['scopes'] ?? [], 'roles.scopes', 6);
            foreach ($scopes as $scope) if (!is_string($scope)) PropertyInput::invalid('roles.scopes');
            if (array_diff($scopes, self::ROLE_SCOPES) !== [] || count(array_unique($scopes)) !== count($scopes)
                || ($role['role'] !== 'representative' && $scopes !== [])) PropertyInput::invalid('roles.scopes');
            sort($scopes, SORT_STRING);
            $key = $client . ':' . $role['role'];
            if (isset($roles[$key])) PropertyInput::invalid('roles');
            $roles[$key] = ['client_id' => $client, 'role' => $role['role'], 'scopes' => $scopes,
                'title_ref' => PropertyInput::text($role['title_ref'] ?? null, 'roles.title_ref', 2000)];
        }
        ksort($roles, SORT_STRING);
        $identifiers = [];
        foreach (PropertyInput::list($input['identifiers'] ?? [], 'identifiers') as $identifier) {
            if (!is_array($identifier)) PropertyInput::invalid('identifiers');
            PropertyInput::fields($identifier, ['kind', 'value', 'payment_scope', 'source', 'title_ref']);
            $identifierKind = $identifier['kind'] ?? null;
            if (!in_array($identifierKind, ['vs', 'ss', 'account', 'text', 'sipo_link'], true)) PropertyInput::invalid('identifiers.kind');
            $value = PropertyInput::text($identifier['value'] ?? null, 'identifiers.value', 190);
            if (in_array($identifierKind, ['vs', 'ss'], true) && !preg_match('/^[0-9]{1,10}$/D', $value)) PropertyInput::invalid('identifiers.value');
            $scope = PropertyInput::code($identifier['payment_scope'] ?? 'supplier', 'identifiers.payment_scope');
            $source = $identifier['source'] ?? null;
            if (!in_array($source, ['manual', 'import'], true)) PropertyInput::invalid('identifiers.source');
            $key = $identifierKind . ':' . $scope . ':' . $value;
            if (isset($identifiers[$key])) PropertyInput::invalid('identifiers');
            $identifiers[$key] = ['kind' => $identifierKind, 'value' => $value, 'payment_scope' => $scope, 'source' => $source,
                'title_ref' => PropertyInput::text($identifier['title_ref'] ?? null, 'identifiers.title_ref', 2000)];
        }
        ksort($identifiers, SORT_STRING);
        $services = PropertyInput::list($input['services'] ?? [], 'services');
        foreach ($services as $id) PropertyInput::id($id, 'services');
        if (count(array_unique($services)) !== count($services)) PropertyInput::invalid('services');
        sort($services, SORT_NUMERIC);
        $ownerships = $input['ownerships'] ?? null;
        if (!is_array($ownerships)) PropertyInput::invalid('ownerships');
        return ['unit_no' => PropertyInput::text($input['unit_no'] ?? null, 'unit_no', 80), 'kind' => $kind, 'active' => $input['active'],
            'attributes' => $attributes, 'ownerships' => $ownerships === [] ? [] : PropertyOwnership::normalize($ownerships),
            'roles' => array_values($roles), 'identifiers' => array_values($identifiers), 'services' => $services];
    }
}
