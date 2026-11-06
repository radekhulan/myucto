<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use Symfony\Component\String\UnicodeString;
use Transliterator;

/**
 * Normalizace bankovní zprávy pro fragmentové párování pravidel (§4.1). Pure,
 * jednotkově testovatelné, bez DB.
 *
 * Pipeline: mb_strtolower → odstranění diakritiky (Transliterator, fallback
 * iconv //TRANSLIT) → odstranění číslic (aby „záloha OSSZ 05/2026" ≈ „…06/2026")
 * → non-alnum na mezeru → collapse whitespace + trim.
 *
 * Fragment `message_contains` se ukládá i porovnává už normalizovaný (normalizace
 * na vstupu v Action i v matcheru), takže je porovnání symetrické.
 */
final class BankMessageNormalizer
{
    public static function normalize(string $msg): string
    {
        $lower = mb_strtolower($msg, 'UTF-8');
        $ascii = self::stripDiacritics($lower);
        // odstranění číslic (05/2026 vs 06/2026 → shodné)
        $noDigits = preg_replace('/\d+/', '', $ascii) ?? '';
        // non-alnum → mezera
        $spaced = preg_replace('/[^a-z0-9]+/', ' ', $noDigits) ?? '';
        // collapse whitespace + trim
        return trim((string) preg_replace('/\s+/', ' ', $spaced));
    }

    public static function normalizeKeepDigits(string $msg): string
    {
        $ascii = self::stripDiacritics(mb_strtolower($msg, 'UTF-8'));
        $spaced = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '';
        return trim((string) preg_replace('/\s+/', ' ', $spaced));
    }

    /** Sestavení transliterátoru je drahé (ICU), převod sám stav nemá: jedna instance na proces. */
    private static Transliterator|false|null $transliterator = null;

    private static function stripDiacritics(string $s): string
    {
        if (class_exists(Transliterator::class)) {
            if (self::$transliterator === null) {
                self::$transliterator = Transliterator::create('Any-Latin; Latin-ASCII; Lower()') ?? false;
            }
            $tr = self::$transliterator;
            if ($tr !== false) {
                $out = $tr->transliterate($s);
                if ($out !== false) {
                    return $out;
                }
            }
        }
        if (class_exists(UnicodeString::class)) {
            return (string) (new UnicodeString($s))->ascii();
        }
        $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return $out === false ? $s : $out;
    }
}
