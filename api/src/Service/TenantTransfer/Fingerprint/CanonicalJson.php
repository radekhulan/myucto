<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Fingerprint;

/** Kanonický JSON pro stabilní fingerprinty registrů, migrací a schématu. */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    public static function sha256(mixed $value): string
    {
        return 'sha256:' . hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (is_string($value) || is_int($value) || is_bool($value) || $value === null) {
                return $value;
            }
            throw new \InvalidArgumentException('Kanonický JSON podporuje pouze string, int, bool, null a pole.');
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Klíče kanonického JSON objektu musí být řetězce.');
            }
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }
        return $value;
    }
}
