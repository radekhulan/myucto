<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property;

final class PropertyTimeline
{
    public static function at(array $revisions, string $at, ?string $knownAt = null): array
    {
        $at = PropertyInstant::normalize($at);
        $knownAt = $knownAt === null ? null : PropertyInstant::normalize($knownAt);
        usort($revisions, static fn (array $a, array $b): int => $b['revision'] <=> $a['revision']);
        $confirmed = null;
        $pending = [];
        $resolved = [];
        foreach ($revisions as $revision) {
            if ($knownAt !== null && PropertyInstant::normalize($revision['recorded_at']) > $knownAt) continue;
            if (isset($revision['resolves_revision_id'])) $resolved[(int) $revision['resolves_revision_id']] = true;
            if (!PropertyInstant::contains($revision['effective_from'], $revision['effective_to'], $at)) continue;
            if ($revision['status'] === 'confirmed' && $confirmed === null) $confirmed = $revision;
            if ($revision['status'] === 'pending' && !isset($resolved[(int) $revision['id']])) $pending[] = (int) $revision['id'];
        }
        sort($pending, SORT_NUMERIC);
        return ['as_of' => $at, 'known_at' => $knownAt, 'revision' => $confirmed, 'pending_revision_ids' => $pending,
            'ownership_review_required' => $pending !== [] || $confirmed === null || ($confirmed['state']['ownerships'] ?? []) === []];
    }

    public static function boundaries(array $revisions): array
    {
        $out = [];
        foreach ($revisions as $revision) {
            $out[] = PropertyInstant::normalize($revision['effective_from']);
            if ($revision['effective_to'] !== null) $out[] = PropertyInstant::normalize($revision['effective_to']);
        }
        $out = array_values(array_unique($out));
        sort($out, SORT_STRING);
        return $out;
    }
}
