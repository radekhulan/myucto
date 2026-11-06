<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

/** Převod časů z DB (lokální čas aplikace) na ISO 8601 s offsetem pro API EET. */
final class EetTime
{
    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (new \DateTimeImmutable((string) $value, new \DateTimeZone(date_default_timezone_get())))->format(DATE_ATOM);
    }
}
