<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

/**
 * Sdílené převody hodnot EET: částky jako přesné desetinné řetězce, časy v zóně
 * Europe/Prague (DATETIME sloupce drží místní čas, offset se ukládá zvlášť)
 * a identifikátory UUIDv4 (XSD EET UUIDv7 nepřijímá).
 */
final class EetValues
{
    public const TIMEZONE = 'Europe/Prague';

    /** Limit EET pro jednu tržbu: absolutně pod 100 milionů Kč. */
    public const MAX_ABS_CENTS = 9_999_999_999;

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone(self::TIMEZONE);
    }

    public static function local(\DateTimeImmutable $at): \DateTimeImmutable
    {
        return $at->setTimezone(self::zone());
    }

    /** Hodnota pro sloupec DATETIME(6) v místním čase. */
    public static function dbTime(\DateTimeImmutable $at): string
    {
        return self::local($at)->format('Y-m-d H:i:s.u');
    }

    /** Hodnota pro sloupec DATETIME (bez zlomků) v místním čase. */
    public static function dbSecond(\DateTimeImmutable $at): string
    {
        return self::local($at)->format('Y-m-d H:i:s');
    }

    public static function fromDb(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        return new \DateTimeImmutable($value, self::zone());
    }

    /** ISO 8601 s offsetem pro API. */
    public static function iso(?string $dbValue): ?string
    {
        $at = self::fromDb($dbValue);
        return $at?->format(DATE_ATOM);
    }

    /**
     * Částka jako řetězec se dvěma desetinnými místy. Přijímá řetězec (i s čárkou),
     * celé číslo nebo float; nic jiného.
     *
     * @throws EetCommandException
     */
    public static function amount(mixed $value, string $field = 'amount'): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            $value = number_format($value, 2, '.', '');
        }
        if (!is_string($value)) {
            throw EetCommandException::validation('Částka musí být číslo.', $field);
        }
        $value = str_replace([' ', ','], ['', '.'], trim($value));
        if (preg_match('/^-?\d{1,12}(\.\d{1,2})?$/D', $value) !== 1) {
            throw EetCommandException::validation('Částka musí mít nejvýše dvě desetinná místa.', $field);
        }
        return self::fromCents(self::toCents($value));
    }

    public static function toCents(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $cents = (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        return $negative ? -$cents : $cents;
    }

    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }

    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    public static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1;
    }

    /**
     * Čas zadaný klientem (ISO 8601) v toleranci −48 h … +5 min proti serveru.
     * Bez hodnoty vrací čas serveru.
     *
     * @throws EetCommandException
     */
    public static function clientTime(mixed $value, \DateTimeImmutable $now, string $field): \DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return self::local($now);
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw EetCommandException::validation('Čas musí být ve formátu ISO 8601 včetně časové zóny.', $field);
        }
        try {
            $at = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw EetCommandException::validation('Neplatný čas.', $field);
        }
        if ($at < $now->modify('-48 hours') || $at > $now->modify('+5 minutes')) {
            throw EetCommandException::validation('Čas platby musí ležet nejvýše 48 hodin v minulosti a nejvýše 5 minut v budoucnosti.', $field);
        }
        return self::local($at);
    }
}
