<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\PropertyReadRepository;

final class PropertySummaryService
{
    public function __construct(private readonly Connection $db) {}

    public function at(int $supplierId, string $at, ?string $knownAt = null, bool $currentRead = false): array
    {
        $at = PropertyInstant::normalize($at);
        $repo = new PropertyReadRepository($this->db);
        $objects = $repo->objects($supplierId, null, $currentRead);
        $histories = $repo->histories($supplierId, array_column($objects, 'id'), $currentRead);
        $houses = [];
        $groups = [];
        foreach ($objects as $object) {
            if ($object['kind'] !== 'house') continue;
            $state = PropertyTimeline::at($histories[$object['id']] ?? [], $at, $knownAt)['revision']['state'] ?? null;
            if (!($state['active'] ?? false)) continue;
            $scope = $state['share_scope'];
            $houses[$object['id']] = $scope;
            $groups[$scope] ??= ['scope' => $scope, 'houses' => [], 'units' => 0, 'ownership_review_required' => 0, 'shares' => [], 'declared_complete' => true];
            $groups[$scope]['houses'][] = ['id' => $object['id'], 'name' => $state['name']];
            $groups[$scope]['declared_complete'] = $groups[$scope]['declared_complete'] && $state['registry_complete'];
        }
        foreach ($objects as $object) {
            if ($object['kind'] !== 'unit' || !isset($houses[$object['parent_id']])) continue;
            $snapshot = PropertyTimeline::at($histories[$object['id']] ?? [], $at, $knownAt);
            $state = $snapshot['revision']['state'] ?? null;
            if (!($state['active'] ?? false)) continue;
            $scope = $houses[$object['parent_id']];
            ++$groups[$scope]['units'];
            $groups[$scope]['ownership_review_required'] += $snapshot['ownership_review_required'] ? 1 : 0;
            $groups[$scope]['shares'][] = $state['attributes']['share'];
        }
        foreach ($groups as &$group) {
            $group['total_share'] = PropertyRational::sum($group['shares']);
            $group['share_complete'] = $group['total_share'] === ['num' => '1', 'den' => '1'];
            unset($group['shares']);
        }
        unset($group);
        ksort($groups, SORT_STRING);
        return ['as_of' => $at, 'known_at' => $knownAt, 'scopes' => array_values($groups)];
    }
}
