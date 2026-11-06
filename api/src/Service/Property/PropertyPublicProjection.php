<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

final class PropertyPublicProjection
{
    public static function profile(?array $profile): ?array
    {
        return $profile === null ? null : array_intersect_key($profile, array_flip([
            'revision_no', 'enabled', 'effective_from', 'effective_to', 'recorded_at',
        ]));
    }

    public static function object(array $object): array
    {
        $fields = match ($object['kind']) {
            'house' => ['name', 'active', 'share_scope', 'registry_complete'],
            'unit' => ['unit_no', 'kind', 'active', 'attributes', 'services'],
            'service' => ['name', 'active', 'measure', 'measure_label'],
            default => throw new PropertyException('property_history_corrupt', 'Historický záznam registru neodpovídá svému kontrolnímu otisku.', 409),
        };
        $out = array_intersect_key($object, array_flip(['id', 'kind', 'code', 'parent_id', 'dimension_value_id']));
        $revision = $object['revision'] ?? null;
        $out['revision'] = $revision === null ? null : array_intersect_key($revision, array_flip([
            'id', 'revision', 'status', 'effective_from', 'effective_to', 'recorded_at',
        ])) + ['state' => array_intersect_key($revision['state'], array_flip($fields))];
        $out['pending_revisions'] = count($object['pending_revision_ids'] ?? []);
        $out['review_required'] = $revision === null || $out['pending_revisions'] > 0 || ($object['ownership_review_required'] ?? false);
        return $out;
    }
}
