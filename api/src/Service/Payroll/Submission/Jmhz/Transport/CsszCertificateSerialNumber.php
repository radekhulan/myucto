<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Transport;

final class CsszCertificateSerialNumber
{
    public static function normalizeRegisteredInput(string $value): ?string
    {
        $normalized = strtolower(trim($value));
        $normalized = (string) preg_replace('/^0x/', '', $normalized);
        $normalized = (string) preg_replace('/[\s:.\-_]/', '', $normalized);

        return $normalized !== '' && preg_match('/^[0-9a-f]+$/D', $normalized) === 1
            ? $normalized
            : null;
    }

    public static function matches(string $certificateHex, string $registered): bool
    {
        $certificate = self::normalizeRegisteredInput($certificateHex);
        $claimed = self::normalizeRegisteredInput($registered);
        if ($certificate === null || $claimed === null) {
            return false;
        }

        $certificateHexCanonical = self::stripLeadingZeros($certificate);
        if (hash_equals($certificateHexCanonical, self::stripLeadingZeros($claimed))) {
            return true;
        }

        return ctype_digit($claimed)
            && hash_equals(
                self::hexToDecimal($certificateHexCanonical),
                self::stripLeadingZeros($claimed),
            );
    }

    public static function canonicalCertificateHex(string $value): ?string
    {
        $normalized = self::normalizeRegisteredInput($value);

        return $normalized === null ? null : self::stripLeadingZeros($normalized);
    }

    public static function formatRegisteredForDisplay(string $value): ?string
    {
        $normalized = self::normalizeRegisteredInput($value);
        if ($normalized === null) {
            return null;
        }

        return preg_match('/[a-f]/', $normalized) === 1 && strlen($normalized) % 2 === 1
            ? '0' . $normalized
            : $normalized;
    }

    public static function hexToDecimal(string $hex): string
    {
        $normalized = self::normalizeRegisteredInput($hex);
        if ($normalized === null) {
            return '';
        }

        $decimal = '0';
        foreach (str_split($normalized) as $character) {
            $digit = strpos('0123456789abcdef', $character);
            if ($digit === false) {
                return '';
            }
            $decimal = self::addDecimal(
                self::multiplyDecimal($decimal, 16),
                (string) $digit,
            );
        }

        return self::stripLeadingZeros($decimal);
    }

    private static function stripLeadingZeros(string $value): string
    {
        $trimmed = ltrim($value, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }

    private static function multiplyDecimal(string $value, int $factor): string
    {
        $result = '';
        $carry = 0;
        for ($i = strlen($value) - 1; $i >= 0; $i--) {
            $product = ((int) $value[$i]) * $factor + $carry;
            $result = (string) ($product % 10) . $result;
            $carry = intdiv($product, 10);
        }
        while ($carry > 0) {
            $result = (string) ($carry % 10) . $result;
            $carry = intdiv($carry, 10);
        }

        return $result === '' ? '0' : $result;
    }

    private static function addDecimal(string $left, string $right): string
    {
        $result = '';
        $carry = 0;
        $i = strlen($left) - 1;
        $j = strlen($right) - 1;
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry
                + ($i >= 0 ? (int) $left[$i] : 0)
                + ($j >= 0 ? (int) $right[$j] : 0);
            $result = (string) ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
            $i--;
            $j--;
        }

        return $result === '' ? '0' : $result;
    }
}
