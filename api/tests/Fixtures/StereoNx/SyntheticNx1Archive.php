<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\StereoNx;

use ZipArchive;

/** Minimal NX!2 writer for synthetic tests only; exercises the real reader and ZIP path. */
final class SyntheticNx1Archive
{
    /** @param array<string,list<array<string,mixed>>> $tables */
    public static function write(string $path, array $tables, array $identity, ?string $password = null): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create synthetic archive.');
        }
        try {
            $entries = ['ObsahBck.txt' => "1.0.0\nSynthetic\nSynthetic\n0|Synthetic company|fixture\n",
                'Firma_0/firma.bin' => self::metadata($identity)];
            foreach ($tables as $name => $rows) $entries['Firma_0/' . $name . '.nx1'] = self::table($rows);
            foreach ($entries as $name => $bytes) {
                if (!$zip->addFromString($name, $bytes)
                    || ($password !== null && !$zip->setEncryptionName($name, ZipArchive::EM_AES_256, $password))) {
                    throw new \RuntimeException('Cannot write synthetic ZIP entry.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** @param list<array<string,mixed>> $rows */
    public static function table(array $rows): string
    {
        $names = [];
        foreach ($rows as $row) foreach (array_keys($row) as $name) $names[$name] = true;
        if ($names === []) $names['SyntheticEmpty'] = true;
        $fields = [];
        $offset = 0;
        foreach (array_keys($names) as $name) {
            $values = array_map(static fn (array $row): mixed => $row[$name] ?? null, $rows);
            $nonnull = array_values(array_filter($values, static fn (mixed $v): bool => $v !== null));
            if ($nonnull === [] || is_bool($nonnull[0])) {
                $type = 'nxtBoolean'; $size = 1;
            } elseif (is_int($nonnull[0]) && !array_any($nonnull, static fn (mixed $v): bool => is_float($v))) {
                $type = 'nxtInt32'; $size = 4;
            } elseif (is_int($nonnull[0]) || is_float($nonnull[0])) {
                $type = 'nxtDouble'; $size = 8;
            } else {
                $type = 'nxtShortString';
                $size = 1 + max(1, ...array_map(static fn (mixed $v): int => strlen(self::cp1250((string) $v)), $nonnull));
                if ($size > 256) throw new \LogicException('Synthetic short string exceeds 255 bytes.');
            }
            $fields[$name] = [$type, $offset, $size];
            $offset += $size;
        }
        $nullOffset = ($offset + 3) & ~3;
        $stride = $nullOffset + 4 * intdiv(count($fields) + 31, 32);
        $blockSize = 4096;
        while ($stride + 40 > $blockSize) $blockSize *= 2;
        if ($blockSize > 65536) throw new \LogicException('Synthetic record too large.');
        $capacity = intdiv($blockSize - 36, $stride);
        while (32 + intdiv($capacity + 7, 8) + $capacity * $stride + 4 > $blockSize) $capacity--;
        $dictionary = 'OriginalFieldCount=' . count($fields) . ';';
        foreach ($fields as $name => [$type, $at, $size]) {
            $dictionary .= self::short('TnxFieldDescriptor') . self::short($name) . self::short($name) . self::short($type)
                . self::compact($type === 'nxtShortString' ? $size - 1 : 0)
                . "\x02\x00\x08\x02\x03\x01\x00\x02\x0f\x06\x00\x02\x03"
                . self::compact($at) . self::compact($size);
        }
        $header = str_repeat("\0", $blockSize);
        self::put($header, 0, 'NX!2');
        self::put($header, 0x14, pack('v', $blockSize - 4));
        self::put($header, 0x48, 'nx1xDefault');
        $blocks = [$header];
        $chunks = str_split($dictionary, $blockSize - 36);
        foreach ($chunks as $index => $chunk) {
            $block = str_repeat("\0", $blockSize);
            self::put($block, 0, 'NXSH');
            self::put($block, 4, pack('V', $index + 1));
            self::put($block, 0x10, pack('V', $index + 1 === count($chunks) ? 0xffffffff : $index + 2));
            self::put($block, 0x14, 'DICT');
            self::put($block, 0x18, pack('V', $index === 0 ? strlen($dictionary) : 0));
            self::put($block, 0x20, $chunk);
            $blocks[] = $block;
        }
        foreach (array_chunk($rows, $capacity) as $chunk) {
            $block = str_repeat("\0", $blockSize);
            self::put($block, 0, 'NXDH');
            self::put($block, 4, pack('V', count($blocks)));
            self::put($block, 0x1c, pack('v', count($chunk) === $capacity ? 0 : count($chunk) + 1));
            self::put($block, 0x1e, pack('v', count($chunk)));
            $start = $blockSize - 4 - $capacity * $stride;
            for ($slot = 0; $slot < $capacity; $slot++) {
                if (!isset($chunk[$slot])) {
                    self::put($block, $start + $slot * $stride, pack('V', $slot + 1 === $capacity ? 0 : $slot + 2));
                    continue;
                }
                $byte = 0x20 + intdiv($slot, 8);
                $block[$byte] = chr(ord($block[$byte]) | (1 << ($slot % 8)));
                $fieldIndex = 0;
                foreach ($fields as $name => [$type, $at, $size]) {
                    $v = $chunk[$slot][$name] ?? null;
                    if ($v === null) {
                        $nullByte = $start + $slot * $stride + $nullOffset + intdiv($fieldIndex, 8);
                        $block[$nullByte] = chr(ord($block[$nullByte]) | (1 << ($fieldIndex % 8)));
                        $fieldIndex++;
                        continue;
                    }
                    $encoded = match ($type) {
                        'nxtBoolean' => $v ? "\xff" : "\0",
                        'nxtInt32' => pack('V', (int) $v),
                        'nxtDouble' => pack('e', (float) $v),
                        default => chr(strlen(self::cp1250((string) $v))) . self::cp1250((string) $v),
                    };
                    self::put($block, $start + $slot * $stride + $at, str_pad($encoded, $size, "\0"));
                    $fieldIndex++;
                }
            }
            $blocks[] = $block;
        }
        return implode('', $blocks);
    }

    private static function metadata(array $identity): string
    {
        $fields = ['ICO' => ['String', $identity['ico']], 'DIC' => ['String', $identity['dic']],
            'Nazev' => ['String', $identity['name']], 'PlatDPH' => ['Boolean', $identity['vat_payer']]];
        $schema = [];
        $row = [0];
        foreach ($fields as $name => [$type, $value]) {
            array_push($schema, $name, $type, 0, $name, '', 0, false, false, 'NONE', '');
            array_push($row, false, $value);
        }
        return 'TPF0' . self::value(251) . self::value($schema) . self::value([])
            . self::value([4, 1, 1, 1, 1]) . self::value([$row]);
    }

    private static function value(mixed $value): string
    {
        if (is_array($value)) return "\x01" . implode('', array_map(self::value(...), $value)) . "\0";
        if (is_bool($value)) return $value ? "\x09" : "\x08";
        if (is_int($value)) return "\x03" . pack('v', $value);
        return "\x14" . pack('V', strlen($value)) . $value;
    }

    private static function cp1250(string $text): string
    {
        $encoded = iconv('UTF-8', 'Windows-1250', $text);
        if ($encoded === false) throw new \LogicException('Invalid synthetic text.');
        return $encoded;
    }

    private static function short(string $text): string { return "\x06" . chr(strlen($text)) . $text; }
    private static function compact(int $value): string { return "\x04" . pack('V', $value); }
    private static function put(string &$buffer, int $offset, string $bytes): void
    {
        $buffer = substr_replace($buffer, $bytes, $offset, strlen($bytes));
    }
}
