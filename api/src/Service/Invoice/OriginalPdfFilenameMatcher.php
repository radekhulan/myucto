<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use Transliterator;

/**
 * Párování nahraných PDF originálů s vydanými fakturami podle názvu souboru.
 *
 * Název i číslo dokladu se rozloží na tokeny (bez přípony a diakritiky, malými
 * písmeny, dělené na čemkoli kromě písmen a číslic). Faktura sedí, když se tokeny
 * jejího čísla (nebo platebního VS) objeví v názvu jako souvislý úsek celých tokenů:
 * „FV-2026-001.pdf" i „Faktura fv 2026 001.pdf" sedí na FV-2026-001, „FV-2026-0012.pdf"
 * ne. Když sedí víc faktur, vyhrává nejdelší číslo; při shodě délek je výsledek
 * nejednoznačný. Dva soubory na tutéž fakturu jsou nejednoznačné oba.
 */
final class OriginalPdfFilenameMatcher
{
    public const STATUS_MATCHED = 'matched';
    public const STATUS_UNMATCHED = 'unmatched';
    public const STATUS_AMBIGUOUS = 'ambiguous';

    /** Nejdelší úsek tokenů, ze kterého se skládá kandidátní klíč pro dotaz do DB. */
    private const MAX_KEY_TOKENS = 4;

    private static Transliterator|false|null $transliterator = null;

    /** @return list<string> */
    public static function tokens(string $text): array
    {
        $ascii = strtolower(self::stripDiacritics($text));
        $parts = preg_split('/[^a-z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY);
        return $parts === false ? [] : array_values($parts);
    }

    /** Tokeny názvu souboru bez poslední přípony. */
    public static function filenameTokens(string $filename): array
    {
        $base = basename(str_replace('\\', '/', $filename));
        $base = (string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $base);
        return self::tokens($base);
    }

    /**
     * Klíče pro předvýběr faktur přes indexované sloupce `varsymbol_match_key`
     * a `payment_variable_symbol_match_key` (číslice čísla bez vodicích nul).
     * Skládají se ze souvislých úseků tokenů názvu, takže pokryjí i číslo s oddělovači.
     *
     * @return list<string>
     */
    public static function candidateKeys(string $filename): array
    {
        $tokens = self::filenameTokens($filename);
        $keys = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $joined = '';
            for ($len = 1; $len <= self::MAX_KEY_TOKENS && $i + $len <= $count; $len++) {
                $joined .= $tokens[$i + $len - 1];
                $key = ltrim((string) preg_replace('/\D+/', '', $joined), '0');
                if ($key !== '' && strlen($key) <= 20) {
                    $keys[$key] = true;
                }
            }
        }
        return array_map('strval', array_keys($keys));
    }

    /**
     * @param list<string> $filenames
     * @param list<array{id:int|string, varsymbol?:?string, payment_variable_symbol?:?string}> $invoices
     * @return list<array{file:string, status:string, reason:?string, invoice_id:?int, candidate_ids:list<int>}>
     */
    public static function match(array $filenames, array $invoices): array
    {
        $numbers = [];
        foreach ($invoices as $inv) {
            $id = (int) $inv['id'];
            foreach ([$inv['varsymbol'] ?? null, $inv['payment_variable_symbol'] ?? null] as $number) {
                $tokens = self::tokens((string) $number);
                if ($tokens !== []) {
                    $numbers[] = [$id, ' ' . implode(' ', $tokens) . ' ', strlen(implode('', $tokens))];
                }
            }
        }

        $results = [];
        foreach ($filenames as $file) {
            $haystack = ' ' . implode(' ', self::filenameTokens($file)) . ' ';
            $scores = [];
            foreach ($numbers as [$id, $needle, $score]) {
                if (str_contains($haystack, $needle)) {
                    $scores[$id] = max($scores[$id] ?? 0, $score);
                }
            }
            if ($scores === []) {
                $results[] = ['file' => $file, 'status' => self::STATUS_UNMATCHED, 'reason' => null,
                    'invoice_id' => null, 'candidate_ids' => []];
                continue;
            }
            $best = max($scores);
            $winners = array_keys(array_filter($scores, static fn (int $s): bool => $s === $best));
            sort($winners);
            $results[] = count($winners) === 1
                ? ['file' => $file, 'status' => self::STATUS_MATCHED, 'reason' => null,
                    'invoice_id' => $winners[0], 'candidate_ids' => $winners]
                : ['file' => $file, 'status' => self::STATUS_AMBIGUOUS, 'reason' => 'multiple_invoices',
                    'invoice_id' => null, 'candidate_ids' => $winners];
        }

        $perInvoice = [];
        foreach ($results as $r) {
            if ($r['status'] === self::STATUS_MATCHED) {
                $perInvoice[$r['invoice_id']] = ($perInvoice[$r['invoice_id']] ?? 0) + 1;
            }
        }
        foreach ($results as &$r) {
            if ($r['status'] === self::STATUS_MATCHED && $perInvoice[$r['invoice_id']] > 1) {
                $r['status'] = self::STATUS_AMBIGUOUS;
                $r['reason'] = 'duplicate_file';
                $r['invoice_id'] = null;
            }
        }
        unset($r);

        return $results;
    }

    private static function stripDiacritics(string $s): string
    {
        if (self::$transliterator === null) {
            self::$transliterator = class_exists(Transliterator::class)
                ? (Transliterator::create('Any-Latin; Latin-ASCII') ?? false)
                : false;
        }
        if (self::$transliterator !== false) {
            $out = self::$transliterator->transliterate($s);
            if ($out !== false) {
                return $out;
            }
        }
        $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return $out === false ? $s : $out;
    }
}
