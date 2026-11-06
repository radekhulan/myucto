<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Service\Property\PropertyInstant;

final class MembershipRecordedTime
{
    public static function nowSql(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    public static function requiredInstant(?string $sql): string
    {
        if ($sql === null) {
            throw new MembershipException('nonprofit_membership_recorded_time_unproven',
                'Historický čas záznamu členství nemá průkazný UTC základ.', 409);
        }
        return self::instant($sql);
    }

    public static function projection(?string $sql): array
    {
        return $sql === null
            ? ['recorded_at' => null, 'recorded_time_status' => 'unproven']
            : ['recorded_at' => self::instant($sql), 'recorded_time_status' => 'proven_utc'];
    }

    private static function instant(string $sql): string
    {
        return PropertyInstant::normalize(str_replace(' ', 'T', $sql) . 'Z');
    }
}
