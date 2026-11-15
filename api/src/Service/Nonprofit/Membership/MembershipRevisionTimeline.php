<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

final class MembershipRevisionTimeline
{
    public static function at(array $history, string $date): ?array
    {
        self::date($date);
        $match = null;
        foreach (self::intervals($history) as $row) {
            if ($row['effective_from'] <= $date && ($row['effective_to'] === null || $date < $row['effective_to'])) {
                $match = $row;
            }
        }
        return $match;
    }

    /**
     * Platné revize v pořadí řetězu: bez rušících článků a bez revizí, které zrušily.
     * Rušící článek smí zrušit jen poslední platnou revizi a nese její datum i stav.
     * Historie omezená časem záznamu (knownAt) později zapsaná zrušení neobsahuje, takže je správně nevidí.
     */
    public static function effective(array $history): array
    {
        $chain = array_values($history);
        usort($chain, static fn (array $a, array $b): int => [$a['revision_no'] ?? 0, $a['id'] ?? 0] <=> [$b['revision_no'] ?? 0, $b['id'] ?? 0]);
        $stack = [];
        foreach ($chain as $row) {
            $voids = $row['voids_revision_id'] ?? null;
            if ($voids === null) {
                // Nová revize začíná po poslední platné (MembershipMemberService::appendRevision, klon stejně);
                // unikátní index data kvůli opravám (zrušená revize datum drží) zmizel, hlídá to až čtení.
                $last = $stack === [] ? null : $stack[array_key_last($stack)];
                if ($last !== null && (string) ($row['effective_from'] ?? '') <= (string) $last['effective_from']) {
                    throw new MembershipException('nonprofit_membership_revision_corrupt', 'Historie členství není průkazná.', 409);
                }
                $stack[] = $row;
                continue;
            }
            $top = $stack === [] ? null : $stack[array_key_last($stack)];
            if (!is_int($voids) || $top === null || ($top['id'] ?? null) !== $voids
                || ($row['effective_from'] ?? null) !== $top['effective_from'] || ($row['status'] ?? null) !== $top['status']) {
                throw new MembershipException('nonprofit_membership_revision_corrupt', 'Historie členství není průkazná.', 409);
            }
            array_pop($stack);
        }
        return $stack;
    }

    /** Mapa zrušená revize → rušící článek. */
    public static function voided(array $history): array
    {
        $out = [];
        foreach ($history as $row) if (($row['voids_revision_id'] ?? null) !== null) $out[(int) $row['voids_revision_id']] = (int) $row['id'];
        return $out;
    }

    public static function intervals(array $history): array
    {
        $ordered = self::effective($history);
        usort($ordered, static fn (array $a, array $b): int => [$a['effective_from'], $a['id']] <=> [$b['effective_from'], $b['id']]);
        $seen = [];
        foreach ($ordered as $index => &$row) {
            if (!is_int($row['id'] ?? null) || $row['id'] <= 0 || !is_int($row['revision_no'] ?? null) || $row['revision_no'] <= 0
                || !in_array($row['status'] ?? null, ['active', 'suspended', 'ended'], true)) {
                throw new MembershipException('nonprofit_membership_revision_corrupt', 'Historie členství není průkazná.', 409);
            }
            self::date((string) ($row['effective_from'] ?? ''));
            if (isset($seen[$row['effective_from']])) {
                throw new MembershipException('nonprofit_membership_effective_conflict', 'Ke stejnému dni už existuje jiná revize členství.', 409);
            }
            $seen[$row['effective_from']] = true;
            $row['effective_to'] = $ordered[$index + 1]['effective_from'] ?? null;
        }
        unset($row);
        return $ordered;
    }

    public static function date(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $date) {
            throw new MembershipException('nonprofit_membership_date_invalid', 'Datum členství není platné.');
        }
        return $date;
    }
}
