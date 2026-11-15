<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai\Assistant;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\RuntimePaths;

/**
 * Vyhledávání v uživatelském manuálu (`manual/*.md`) pro AI asistenta.
 *
 * Manuál se rozdělí na oddíly podle nadpisů (úrovně 2 až 4) a hledá se v nich
 * skórováním BM25 nad slovy nadpisu (váha 3), názvu kapitoly a textu oddílu. Slova se
 * převedou bez diakritiky na malá písmena a zkrátí o běžné české koncovky, aby se
 * „faktura“, „faktury“ i „fakturu“ potkaly. Bez externích služeb a bez databáze;
 * index se drží v `storage/cache/ai-assistant` a přestaví se po změně manuálu.
 *
 * Odkaz na oddíl má stejný tvar jako v generovaném HTML manuálu
 * (`tools/generateManualHtml.php`): `/manual?ch=<soubor>#<kotva>`.
 */
final class ManualSearchService
{
    public const MAX_RESULTS = 8;
    public const MAX_SECTION_CHARS = 9000;
    private const SNIPPET_CHARS = 280;
    private const TITLE_WEIGHT = 3;
    private const K1 = 1.2;
    private const B = 0.75;
    private const CACHE_VERSION = 1;

    private const STOPWORDS = [
        'a', 'aby', 'ale', 'ani', 'az', 'bez', 'byt', 'by', 'co', 'do', 'i', 'jak', 'jako', 'je', 'jen', 'jeho',
        'jsou', 'k', 'kde', 'kdy', 'ke', 'ktera', 'ktere', 'ktery', 'mi', 'mu', 'na', 'nad', 'neni', 'nebo', 'o',
        'od', 'po', 'pod', 'pro', 'pri', 's', 'se', 'si', 'so', 'ta', 'tak', 'take', 'te', 'to', 'tu', 'u', 'v',
        've', 'z', 'za', 'ze', 'zde', 'the', 'and', 'of', 'mam', 'muzu', 'jde', 'nas', 'vas', 'svuj', 'jeji',
    ];
    private const ENDINGS = [
        'ovani', 'ovat', 'ach', 'ami', 'ech', 'emi', 'ich', 'ych', 'ymi', 'imi', 'ove', 'ovi', 'ova',
        'eho', 'emu', 'ou', 'em', 'um', 'ho', 'mu', 'a', 'e', 'i', 'o', 'u', 'y',
    ];
    private const ACCENTS = [
        'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n', 'ó' => 'o',
        'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z', 'ä' => 'a',
        'ö' => 'o', 'ü' => 'u', 'ľ' => 'l', 'ĺ' => 'l', 'ŕ' => 'r', 'ô' => 'o',
    ];

    /** @var array{sections:list<array<string,mixed>>, postings:array<string,array<int,int>>, avgdl:float}|null */
    private ?array $index = null;

    public function __construct(
        private readonly ?string $manualDir = null,
        private readonly ?string $cacheDir = null,
    ) {}

    /**
     * @return list<array{section_id:string, chapter:string, title:string, url:string, snippet:string, score:float}>
     */
    public function search(string $query, int $limit = 5): array
    {
        $terms = array_values(array_unique(self::tokens($query)));
        $index = $this->index();
        $n = count($index['sections']);
        if ($terms === [] || $n === 0) {
            return [];
        }
        $scores = [];
        foreach ($terms as $term) {
            $postings = $index['postings'][$term] ?? [];
            if ($postings === []) continue;
            $idf = log(1 + ($n - count($postings) + 0.5) / (count($postings) + 0.5));
            foreach ($postings as $doc => $tf) {
                $len = (int) $index['sections'][$doc]['len'];
                $scores[$doc] = ($scores[$doc] ?? 0.0)
                    + $idf * ($tf * (self::K1 + 1)) / ($tf + self::K1 * (1 - self::B + self::B * $len / max(1.0, $index['avgdl'])));
            }
        }
        arsort($scores);
        $out = [];
        foreach (array_slice($scores, 0, max(1, min(self::MAX_RESULTS, $limit)), true) as $doc => $score) {
            $s = $index['sections'][$doc];
            $out[] = [
                'section_id' => $s['id'],
                'chapter' => $s['chapter'],
                'title' => $s['title'],
                'url' => self::url($s['file'], $s['slug']),
                'snippet' => $s['snippet'],
                'score' => round($score, 3),
            ];
        }
        return $out;
    }

    /**
     * Text oddílu včetně jeho pododdílů, s odkazy přepsanými na URL manuálu.
     *
     * @return array{section_id:string, chapter:string, title:string, url:string, text:string, truncated:bool}|null
     */
    public function section(string $sectionId): ?array
    {
        foreach ($this->index()['sections'] as $s) {
            if ($s['id'] !== $sectionId) continue;
            $lines = preg_split('/\r\n|\r|\n/', (string) @file_get_contents($this->dir() . '/' . $s['file'] . '.md')) ?: [];
            $chunk = [];
            $inFence = false;
            for ($i = (int) $s['line']; $i < count($lines); $i++) {
                $line = $lines[$i];
                if (preg_match('/^\s*(```|~~~)/', $line) === 1) $inFence = !$inFence;
                if ($i > (int) $s['line'] && !$inFence && preg_match('/^(#{1,6})\s+/', $line, $m) === 1
                    && strlen($m[1]) <= (int) $s['level']) {
                    break;
                }
                $chunk[] = $line;
            }
            $text = $this->cleanMarkdown(implode("\n", $chunk), (string) $s['file']);
            $truncated = mb_strlen($text) > self::MAX_SECTION_CHARS;
            return [
                'section_id' => $s['id'],
                'chapter' => $s['chapter'],
                'title' => $s['title'],
                'url' => self::url($s['file'], $s['slug']),
                'text' => $truncated ? mb_substr($text, 0, self::MAX_SECTION_CHARS) . "\n[… oddíl pokračuje v manuálu …]" : $text,
                'truncated' => $truncated,
            ];
        }
        return null;
    }

    public static function url(string $file, string $slug): string
    {
        return '/manual?ch=' . rawurlencode($file) . ($slug !== '' ? '#' . $slug : '');
    }

    /** Kotva nadpisu, shodná s `mdSlug()` v `tools/generateManualHtml.php`. */
    public static function slug(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        if (function_exists('iconv')) {
            $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($tr !== false) $s = $tr;
        }
        $s = (string) preg_replace('/[^a-z0-9\s-]/', '', $s);
        $s = (string) preg_replace('/[\s_]+/', '-', $s);
        $s = (string) preg_replace('/-+/', '-', $s);
        return trim($s, '-');
    }

    /** @return list<string> */
    public static function tokens(string $text): array
    {
        $text = strtr(mb_strtolower($text, 'UTF-8'), self::ACCENTS);
        $out = [];
        foreach (preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (strlen($word) < 2 || in_array($word, self::STOPWORDS, true)) continue;
            $out[] = self::stem($word);
        }
        return $out;
    }

    private static function stem(string $word): string
    {
        if (ctype_digit($word)) return $word;
        foreach (self::ENDINGS as $ending) {
            if (str_ends_with($word, $ending) && strlen($word) - strlen($ending) >= 4) {
                $word = substr($word, 0, -strlen($ending));
                break;
            }
        }
        return substr($word, 0, 7);
    }

    private function dir(): string
    {
        return $this->manualDir ?? Bootstrap::rootDir() . '/manual';
    }

    /** @return array{sections:list<array<string,mixed>>, postings:array<string,array<int,int>>, avgdl:float} */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $files = glob($this->dir() . '/*.md') ?: [];
        $files = array_values(array_filter($files, static fn (string $f): bool => preg_match('/^\d+[a-z]?_/i', basename($f)) === 1));
        sort($files, SORT_NATURAL);
        $signature = self::CACHE_VERSION . '|';
        foreach ($files as $f) {
            $signature .= basename($f) . ':' . (int) @filemtime($f) . ':' . (int) @filesize($f) . ';';
        }
        $cacheFile = ($this->cacheDir ?? RuntimePaths::storage('cache/ai-assistant')) . '/manual-index-' . sha1($signature) . '.ser';
        if (is_file($cacheFile)) {
            $cached = @unserialize((string) file_get_contents($cacheFile), ['allowed_classes' => false]);
            if (is_array($cached) && isset($cached['sections'], $cached['postings'], $cached['avgdl'])) {
                return $this->index = $cached;
            }
        }
        $this->index = $this->build($files);
        $dir = dirname($cacheFile);
        if (is_dir($dir) || @mkdir($dir, 0775, true)) {
            foreach (glob($dir . '/manual-index-*.ser') ?: [] as $old) {
                @unlink($old);
            }
            $tmp = $cacheFile . '.' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, serialize($this->index)) !== false) {
                @rename($tmp, $cacheFile);
            }
        }
        return $this->index;
    }

    /**
     * @param list<string> $files
     * @return array{sections:list<array<string,mixed>>, postings:array<string,array<int,int>>, avgdl:float}
     */
    private function build(array $files): array
    {
        $sections = [];
        $postings = [];
        $total = 0;
        foreach ($files as $path) {
            $file = pathinfo($path, PATHINFO_FILENAME);
            $lines = preg_split('/\r\n|\r|\n/', (string) file_get_contents($path)) ?: [];
            $chapter = $file;
            $current = null;
            $body = [];
            $inFence = false;
            $flush = function () use (&$current, &$body, &$sections, &$postings, &$total, &$chapter, $file): void {
                if ($current === null) return;
                $text = $this->cleanMarkdown(implode("\n", $body), $file);
                $tf = [];
                foreach (self::tokens($current['title']) as $t) $tf[$t] = ($tf[$t] ?? 0) + self::TITLE_WEIGHT;
                foreach (self::tokens($chapter) as $t) $tf[$t] = ($tf[$t] ?? 0) + 1;
                foreach (self::tokens($text) as $t) $tf[$t] = ($tf[$t] ?? 0) + 1;
                $doc = count($sections);
                $len = array_sum($tf);
                $total += $len;
                foreach ($tf as $t => $n) $postings[$t][$doc] = $n;
                $snippet = trim((string) preg_replace('/\s+/u', ' ', $text));
                $sections[] = $current + [
                    'chapter' => $chapter,
                    'len' => $len,
                    'snippet' => mb_strlen($snippet) > self::SNIPPET_CHARS ? mb_substr($snippet, 0, self::SNIPPET_CHARS) . '…' : $snippet,
                ];
                $current = null;
                $body = [];
            };
            foreach ($lines as $no => $line) {
                if (preg_match('/^\s*(```|~~~)/', $line) === 1) $inFence = !$inFence;
                if (!$inFence && preg_match('/^(#{1,6})\s+(.*)$/', trim($line), $m) === 1) {
                    $level = strlen($m[1]);
                    if ($level === 1) {
                        $flush();
                        $chapter = trim($m[2]);
                        continue;
                    }
                    if ($level <= 4) {
                        $flush();
                        $title = trim($m[2]);
                        $slug = self::slug($title);
                        $current = ['id' => $file . '#' . $slug, 'file' => $file, 'slug' => $slug,
                            'title' => trim(str_replace('`', '', $title)), 'level' => $level, 'line' => $no];
                        continue;
                    }
                }
                if ($current !== null) $body[] = $line;
            }
            $flush();
        }
        return ['sections' => $sections, 'postings' => $postings, 'avgdl' => $sections === [] ? 0.0 : $total / count($sections)];
    }

    /** Odstraní obrázky a komentáře a přepíše odkazy na kapitoly na URL manuálu. */
    private function cleanMarkdown(string $md, string $file): string
    {
        $md = (string) preg_replace('/<!--.*?-->/s', '', $md);
        $md = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $md);
        $md = (string) preg_replace_callback('/\]\(([^)\s]+)\)/', static function (array $m) use ($file): string {
            $href = $m[1];
            if (preg_match('~^([0-9]{2,3}[a-z]?_[^/#]+)\.md(#.+)?$~i', $href, $hm) === 1) {
                $href = '/manual?ch=' . $hm[1] . ($hm[2] ?? '');
            } elseif (str_starts_with($href, '#')) {
                $href = '/manual?ch=' . $file . $href;
            }
            return '](' . $href . ')';
        }, $md);
        return trim((string) preg_replace("/\n{3,}/", "\n\n", $md));
    }
}
