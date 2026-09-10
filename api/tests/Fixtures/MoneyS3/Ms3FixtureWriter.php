<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Fixtures\MoneyS3;

/**
 * Zapisovač syntetických tabulek ve formátu `.DAT` Money S3 — protějšek čtečky
 * {@see \MyInvoice\Service\Migration\MoneyS3\Ms3Table}.
 *
 * Repo je veřejné, reálná záloha agendy do něj nesmí. Testy proto staví agendu samy
 * z vymyšlených dat ({@see SyntheticAgenda}) v tomtéž formátu, jaký čtečka zná z Money:
 * hlavička `\xD2BF13`, slovník polí od 0x28 po 26 B, záznamy pevné délky s každým
 * bajtem negovaným, texty v CP1250, částky v Borland Extended, data jako dny.
 *
 * Datum se zapisuje podle definice kalendáře Money „1 = 1. 1. 1900", odvozené od
 * 1. 1. 1900 — ne od konstanty čtečky. Kdyby čtečka epochu posunula, round-trip by to
 * přesto chytil jen napůl; nezávislou kotvu mají testy epochy v hodnotách napevno.
 */
final class Ms3FixtureWriter
{
    /**
     * @param list<array{0:string,1:string,2:int}> $fields [název, typ, délka]
     * @param list<array<string,mixed>> $rows
     * @param int $gap bajty „bloku indexů" mezi slovníkem a daty (čtečka je musí přeskočit)
     */
    public static function table(array $fields, array $rows, int $gap = 0): string
    {
        $offset = 4; // první 4 B záznamu jsou režie Money (link / příznaky)
        $dict = '';
        $layout = [];
        foreach ($fields as [$name, $type, $len]) {
            $size = self::size($type, $len);
            $dict .= chr(strlen($name)) . str_pad($name, 10, "\0") . $type . chr($len) . str_repeat("\0", 11) . pack('v', $offset);
            $layout[] = [$name, $type, $len, $offset];
            $offset += $size;
        }
        $recordSize = $offset;
        $header = "\xD2" . 'BF13' . "\0\0" . chr(count($fields));
        $header = str_pad($header, 0x28, "\0");

        $data = '';
        foreach ($rows as $row) {
            $rec = str_repeat("\0", $recordSize);
            foreach ($layout as [$name, $type, $len, $o]) {
                if (!array_key_exists($name, $row) || $row[$name] === null) {
                    continue;
                }
                $bytes = self::encode($type, $len, $row[$name]);
                $rec = substr_replace($rec, $bytes, $o, strlen($bytes));
            }
            $data .= ~$rec;
        }
        // Blok indexů: po negaci samé 0xFF = pro čtečku prázdná režie.
        return $header . $dict . str_repeat("\0", $gap) . $data;
    }

    /** Počet dnů podle kalendáře Money: 1. 1. 1900 = 1. */
    public static function days(string $date): int
    {
        return (int) (new \DateTimeImmutable('1900-01-01'))->diff(new \DateTimeImmutable($date))->days + 1;
    }

    private static function encode(string $type, int $len, mixed $value): string
    {
        return match ($type) {
            'C', 'S' => self::pascal((string) $value, $len),
            'L', 'O' => pack('l', (int) $value),
            'E' => self::ext80((float) $value),
            'B', 'V' => chr((int) $value & 0xFF),
            'D' => pack('v', self::days((string) $value)),
            default => throw new \InvalidArgumentException("Typ pole {$type} zapisovač nezná."),
        };
    }

    private static function pascal(string $text, int $len): string
    {
        $raw = (string) iconv('UTF-8', 'CP1250//TRANSLIT', $text);
        $raw = substr($raw, 0, $len);
        return chr(strlen($raw)) . $raw;
    }

    /** Borland Extended: 64bit mantisa s explicitním celým bitem, 15bit exponent (bias 16383), znaménko. */
    private static function ext80(float $value): string
    {
        if ($value === 0.0) {
            return str_repeat("\0", 10);
        }
        $sign = $value < 0 ? 0x8000 : 0;
        $m = abs($value);
        $e = (int) floor(log($m, 2));
        while (2.0 ** $e > $m) {
            $e--;
        }
        while (2.0 ** ($e + 1) <= $m) {
            $e++;
        }
        $scaled = ($m / (2.0 ** $e)) * 2147483648.0; // mantisa v [2^31, 2^32)
        $hi = (int) floor($scaled);
        $lo = (int) round(($scaled - $hi) * 4294967296.0);
        if ($lo >= 4294967296) {
            $lo -= 4294967296;
            $hi++;
        }
        if ($hi >= 4294967296) {
            $hi = 2147483648;
            $e++;
        }
        return pack('V', $lo) . pack('V', $hi) . pack('v', ($e + 16383) | $sign);
    }

    private static function size(string $type, int $len): int
    {
        return match ($type) {
            'C', 'S' => $len + 1,
            'L', 'O' => 4,
            'E' => 10,
            'B', 'V' => 1,
            'D' => 2,
            default => throw new \InvalidArgumentException("Typ pole {$type} zapisovač nezná."),
        };
    }
}
