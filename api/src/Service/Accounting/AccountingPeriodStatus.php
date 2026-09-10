<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

final class AccountingPeriodStatus
{
    public const CLOSED = ['closed', 'reviewed', 'approved'];

    public static function isClosed(string $status): bool
    {
        return in_array($status, self::CLOSED, true);
    }

    public static function closedSqlList(): string
    {
        return "'" . implode("', '", self::CLOSED) . "'";
    }
}
