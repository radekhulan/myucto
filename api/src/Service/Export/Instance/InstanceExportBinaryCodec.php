<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export\Instance;

/** Reverzibilní reprezentace databázových BINARY hodnot uvnitř UTF-8 JSONL. */
final class InstanceExportBinaryCodec
{
    private const KEY = '__myucto_binary_base64_v1';

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function encodeRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_string($value) && preg_match('//u', $value) !== 1) {
                $row[$column] = [self::KEY => base64_encode($value)];
            }
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function decodeRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if (!is_array($value) || count($value) !== 1 || !array_key_exists(self::KEY, $value)
                || !is_string($value[self::KEY])) {
                continue;
            }
            $decoded = base64_decode($value[self::KEY], true);
            if ($decoded === false) {
                throw new InstanceExportException(
                    'restore_binary_invalid',
                    'Archiv obsahuje neplatnou binární hodnotu ve sloupci ' . $column . '.',
                );
            }
            $row[$column] = $decoded;
        }
        return $row;
    }
}
