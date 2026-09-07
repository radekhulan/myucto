<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Support\CompanyIdNormalizer;

/**
 * Levné rozpoznání „je tohle PDF doklad adresovaný NAŠÍ firmě?" nad textovou
 * vrstvou PDF — bez AI, bez sítě, bez zápisu.
 *
 * Slouží jako VSTUPNÍ FILTR pro přílohy e-mailů: schránka bankovních avíz dostává
 * i newslettery, potvrzení objednávek a cizí přílohy, a každé PDF poslané dál by
 * stálo AI call a založilo šum ve frontě příchozích dokladů.
 *
 * Musí projít OBĚ podmínky:
 *   1. TYP DOKLADU — v textu je slovo z rodiny faktura/daňový doklad/účtenka/invoice.
 *   2. IDENTITA — v textu je naše IČO (přesná shoda číslic, mezery uvnitř se
 *      ignorují), naše DIČ, nebo název naší firmy na ≥ 70 %.
 *
 * Filtr je ZÁMĚRNĚ jen předfiltr, ne autorita: skutečnou kontrolu adresáta dělá
 * až cross-tenant guard v {@see AiPdfExtractor} / {@see IsdocToPurchaseInvoiceMapper}
 * nad rozparsovanými daty. Tady jde o to nespálit AI call na leták.
 *
 * PDF s embedded ISDOC (PDF/A-3) se sem vůbec nemá dostat — strojový originál je
 * důkaz sám o sobě a rozhoduje {@see InvoiceExtractionRouter}.
 */
final class InvoiceDocumentRecognizer
{
    /** Minimální podobnost názvu firmy v procentech. */
    public const NAME_MATCH_THRESHOLD = 70.0;

    /**
     * Slova označující doklad. Hledá se v textu BEZ diakritiky a malými písmeny,
     * takže stačí ASCII varianty; `faktur` pokrývá fakturu i fakturační doklad,
     * `ucten` účtenku i účtenky.
     */
    private const DOCUMENT_KEYWORDS = [
        'faktur',           // faktura, faktury, fakturu, faktuře, fakturační
        'doklad',           // daňový / zjednodušený / opravný / pokladní doklad, „číslo dokladu"
        'dobropis',
        'vrubopis',
        'ucten',            // účtenka, účtenky
        'paragon',
        'prodejka',
        'vyuctovani',
        'potvrzeni o uhrade',
        'potvrzeni o platbe',
        'zalohovy list',
        'splatkovy kalendar',
        'invoice',
        'receipt',
        'bill to',
        'rechnung',
    ];

    /** Právní formy, které se z názvu firmy před porovnáním odstraní. */
    private const LEGAL_FORMS = [
        'spol s r o', 's r o', 'sro', 'a s', 'as', 'v o s', 'k s', 'z s',
        'o p s', 'p o', 'ltd', 'llc', 'gmbh', 'inc', 'plc', 'se',
    ];

    /**
     * @param string $text     Textová vrstva PDF (může být prázdná u skenu).
     * @param array{ic?:?string,dic?:?string,name?:?string} $identity Identita naší firmy.
     * @return array{
     *     is_invoice:bool,
     *     has_document_keyword:bool,
     *     matched_by:?string,
     *     match_score:?float,
     *     reason:string
     * }
     */
    public function recognize(string $text, array $identity): array
    {
        $normalized = self::normalize($text);
        if ($normalized === '') {
            return $this->result(false, false, null, null, 'PDF nemá textovou vrstvu (sken) — nelze rozpoznat bez OCR.');
        }

        $hasKeyword = $this->hasDocumentKeyword($normalized);
        $match = $this->matchIdentity($text, $normalized, $identity);

        if (!$hasKeyword && $match === null) {
            return $this->result(false, false, null, null, 'V textu není označení dokladu ani identita firmy.');
        }
        if (!$hasKeyword) {
            return $this->result(false, false, $match['by'], $match['score'], 'V textu chybí označení dokladu (faktura, daňový doklad, …).');
        }
        if ($match === null) {
            return $this->result(false, true, null, null, 'V textu není IČO, DIČ ani název naší firmy — doklad není adresovaný nám.');
        }

        return $this->result(
            true,
            true,
            $match['by'],
            $match['score'],
            'Rozpoznán doklad adresovaný naší firmě (shoda: ' . $match['by'] . ').',
        );
    }

    private function hasDocumentKeyword(string $normalized): bool
    {
        foreach (self::DOCUMENT_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{ic?:?string,dic?:?string,name?:?string} $identity
     * @return array{by:string,score:float}|null
     */
    private function matchIdentity(string $rawText, string $normalized, array $identity): ?array
    {
        $ic = CompanyIdNormalizer::ic($identity['ic'] ?? null);
        if ($ic !== null && $this->containsDigitSequence($rawText, $ic)) {
            return ['by' => 'ic', 'score' => 100.0];
        }

        $dic = CompanyIdNormalizer::dic($identity['dic'] ?? null);
        if ($dic !== null && $this->containsDic($rawText, $dic)) {
            return ['by' => 'dic', 'score' => 100.0];
        }

        $name = (string) ($identity['name'] ?? '');
        if (trim($name) !== '') {
            $score = $this->nameScore($normalized, $name);
            if ($score >= self::NAME_MATCH_THRESHOLD) {
                return ['by' => 'name', 'score' => round($score, 2)];
            }
        }

        return null;
    }

    /**
     * Shoda IČO na 100 % číslic. Mezery, tečky a nezlomitelné mezery uvnitř čísla
     * se ignorují („123 45 678" == „12345678"), ale okolní číslice ne — jinak by
     * IČO našel i uvnitř čísla účtu.
     *
     * Hledá se jak kanonický (8 číslic s vedoucími nulami), tak zkrácený tvar bez
     * vedoucích nul — v PDF bývá vytištěné obojí.
     */
    private function containsDigitSequence(string $text, string $digits): bool
    {
        foreach (array_unique([$digits, ltrim($digits, '0')]) as $variant) {
            if ($variant !== '' && $this->matchDigits($text, $variant)) {
                return true;
            }
        }
        return false;
    }

    private function matchDigits(string $text, string $digits): bool
    {
        $sep = '[\s\x{00A0}\x{202F}.]*';
        $parts = [];
        foreach (str_split($digits) as $digit) {
            $parts[] = preg_quote($digit, '/');
        }
        $pattern = '/(?<![0-9])' . implode($sep, $parts) . '(?![0-9])/u';
        return preg_match($pattern, $text) === 1;
    }

    /** DIČ porovnáváme na normalizovaném tvaru bez mezer a interpunkce. */
    private function containsDic(string $text, string $dic): bool
    {
        $compact = strtoupper((string) preg_replace('/[^A-Za-z0-9]/u', '', $text));
        return $compact !== '' && str_contains($compact, $dic);
    }

    /**
     * Podobnost názvu firmy v procentech.
     *
     * Dvě nezávislé míry, bere se lepší z nich:
     *   - pokrytí slov: kolik % významových slov názvu je v textu (řeší přeházené
     *     pořadí a vsunutou právní formu),
     *   - nejlepší `similar_text` proti oknu textu stejné délky jako název (řeší
     *     překlepy a rozdělené znaky z PDF).
     */
    private function nameScore(string $normalizedText, string $name): float
    {
        $needle = $this->stripLegalForms(self::normalize($name));
        if ($needle === '') {
            return 0.0;
        }
        if (str_contains($normalizedText, $needle)) {
            return 100.0;
        }

        $words = array_values(array_filter(
            explode(' ', $needle),
            static fn (string $w): bool => mb_strlen($w) >= 3,
        ));
        $coverage = 0.0;
        if ($words !== []) {
            $hits = 0;
            foreach ($words as $word) {
                if (str_contains($normalizedText, $word)) {
                    $hits++;
                }
            }
            $coverage = $hits / count($words) * 100.0;
        }

        return max($coverage, $this->bestWindowSimilarity($normalizedText, $needle));
    }

    /**
     * Nejlepší `similar_text` shoda názvu proti posuvnému oknu textu. Okno se
     * posouvá po slovech, ne po znacích — jinak by to na stostránkovém PDF běželo
     * neúnosně dlouho.
     */
    private function bestWindowSimilarity(string $haystack, string $needle): float
    {
        $len = strlen($needle);
        if ($len === 0) {
            return 0.0;
        }
        $best = 0.0;
        $offset = 0;
        $guard = 0;
        while ($offset < strlen($haystack) && $guard++ < 5000) {
            $window = substr($haystack, $offset, $len);
            similar_text($needle, $window, $percent);
            if ($percent > $best) {
                $best = $percent;
            }
            $next = strpos($haystack, ' ', $offset);
            if ($next === false) {
                break;
            }
            $offset = $next + 1;
        }
        return $best;
    }

    private function stripLegalForms(string $normalizedName): string
    {
        foreach (self::LEGAL_FORMS as $form) {
            $normalizedName = (string) preg_replace('/(^|\s)' . preg_quote($form, '/') . '(\s|$)/u', ' ', $normalizedName);
        }
        return trim((string) preg_replace('/\s+/u', ' ', $normalizedName));
    }

    /** Malá písmena, bez diakritiky, bez interpunkce, jednou mezerou oddělené. */
    public static function normalize(string $text): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = mb_strtolower(trim($text));
        $text = strtr($text, [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
            'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u',
            'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u',
            'ß' => 'ss', 'ł' => 'l', 'ą' => 'a', 'ę' => 'e', 'ś' => 's', 'ź' => 'z',
            'ż' => 'z', 'ć' => 'c', 'ń' => 'n', 'ô' => 'o', 'ĺ' => 'l', 'ŕ' => 'r',
        ]);
        $text = (string) preg_replace('/[^a-z0-9 ]+/u', ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return array{is_invoice:bool,has_document_keyword:bool,matched_by:?string,match_score:?float,reason:string}
     */
    private function result(bool $isInvoice, bool $hasKeyword, ?string $by, ?float $score, string $reason): array
    {
        return [
            'is_invoice' => $isInvoice,
            'has_document_keyword' => $hasKeyword,
            'matched_by' => $by,
            'match_score' => $score,
            'reason' => $reason,
        ];
    }
}
