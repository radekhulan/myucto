<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Security;

/**
 * Komprese velkého snímku před šifrováním.
 *
 * Šifrovaný snímek přípravy měsíčního hlášení celé firmy se ukládá jedním
 * INSERTem. Kanonický JSON firmy s tisíci zaměstnanci má desítky MB a po
 * zašifrování a base64 ještě o třetinu víc, takže překročí výchozí
 * `max_allowed_packet` MariaDB (16 MB) a uložení spadne. JSON se gzipem
 * zmenší zhruba desetkrát.
 *
 * Komprimuje se PŘED šifrováním (zašifrovaná data se už nezmenší) a otisk
 * snímku se dál počítá z nekomprimovaného JSON. Čtení je zpětně kompatibilní:
 * kanonický JSON začíná vždy `{`, gzip hlavičkou 0x1f 0x8b, takže dřív
 * uložený nekomprimovaný snímek se přečte beze změny. Komprese před šifrováním
 * tu nic neprozrazuje: snímek nemíchá data útočníka s tajemstvím a jeho délku
 * nikdo nepozoruje opakovaně.
 *
 * Bez rozšíření zlib (nemělo by nastat, je v každém běžném sestavení PHP)
 * se snímek uloží nekomprimovaný jako dřív.
 */
final class PayrollSnapshotCompression
{
    private const GZIP_MAGIC = "\x1f\x8b";

    public static function pack(string $plaintext): string
    {
        if (!function_exists('gzencode')) {
            return $plaintext;
        }
        $packed = gzencode($plaintext, 6);
        if ($packed === false) {
            throw new \RuntimeException('Snímek se nepodařilo zkomprimovat.');
        }

        return $packed;
    }

    public static function unpack(string $stored): string
    {
        if (!str_starts_with($stored, self::GZIP_MAGIC)) {
            return $stored;
        }
        if (!function_exists('gzdecode')) {
            throw new \RuntimeException('Snímek je zkomprimovaný, ale PHP nemá rozšíření zlib.');
        }
        $plaintext = gzdecode($stored);
        if ($plaintext === false) {
            throw new \RuntimeException('Zkomprimovaný snímek nejde rozbalit.');
        }

        return $plaintext;
    }
}
