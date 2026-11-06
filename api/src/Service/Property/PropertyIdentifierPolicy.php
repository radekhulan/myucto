<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

use MyInvoice\Service\Bank\VariableSymbolNormalizer;

final class PropertyIdentifierPolicy
{
    public static function assertUnique(int $objectId, array $candidate, array $histories): void
    {
        if ($candidate['status'] !== 'confirmed') return;
        $histories[$objectId][] = $candidate;
        foreach ($histories as $otherId => $history) {
            if ($otherId === $objectId) continue;
            $points = array_unique([$candidate['effective_from'], ...PropertyTimeline::boundaries($history), ...PropertyTimeline::boundaries($histories[$objectId])]);
            foreach ($points as $point) {
                if (!PropertyInstant::contains($candidate['effective_from'], $candidate['effective_to'], $point)) continue;
                $left = PropertyTimeline::at($histories[$objectId], $point)['revision']['state'] ?? null;
                $right = PropertyTimeline::at($history, $point)['revision']['state'] ?? null;
                if (!($left['active'] ?? false) || !($right['active'] ?? false)) continue;
                foreach ($left['identifiers'] ?? [] as $a) foreach ($right['identifiers'] ?? [] as $b) {
                    if ($a['kind'] === 'vs'
                        && $a['kind'] === $b['kind'] && strcasecmp($a['payment_scope'], $b['payment_scope']) === 0
                        && VariableSymbolNormalizer::forMatching($a['value']) === VariableSymbolNormalizer::forMatching($b['value'])) {
                        throw new PropertyException('property_identifier_conflict', 'Platební identifikátor již ve stejném období používá jiná jednotka.', 409,
                            ['other_unit_id' => $otherId, 'kind' => $a['kind'], 'payment_scope' => $a['payment_scope'], 'effective_at' => $point]);
                    }
                }
            }
        }
    }
}
