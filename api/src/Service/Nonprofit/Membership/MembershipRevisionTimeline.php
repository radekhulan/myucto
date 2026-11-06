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

    public static function intervals(array $history): array
    {
        $ordered = array_values($history);
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
