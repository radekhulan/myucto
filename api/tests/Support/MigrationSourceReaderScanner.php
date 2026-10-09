<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

/**
 * Statické čtení toho, na co čtečka převodu sahá ve zdrojových datech cizího programu:
 * tabulky, sloupce (u XML hlášení elementy) a kódy položek katalogu.
 *
 * Komentáře se před čtením zahodí, takže kód zmíněný jen v dokumentaci se za čtení
 * nepočítá. Zachycují se jen literály v místech, kde čtečka opravdu přistupuje ke zdroji
 * (volání přístupové metody, klíč řádku, seznam tabulek nebo sloupců); co je to za místo,
 * určuje styl zdroje ({@see self::STYLES}).
 *
 * Omezení: sloupec se nepřiřazuje k tabulce (stejné jméno sloupce mají desítky tabulek),
 * matice ho proto hlídá jako jméno. Nová tabulka nebo sloupec, který zdroj nezná, ale
 * matici neobejde: čtečka by ho musela napsat jako literál do takového místa.
 *
 * Používá {@see \MyInvoice\Tests\Architecture\MigrationSourceCoverageTest}.
 */
final class MigrationSourceReaderScanner
{
    /** Pomocná metoda čtečky nad řádkem se seznamem sloupců (`self::address($row, ['XULICE', …])`). */
    private const HELPER_COLUMN_LIST = '/(?:self|static)::\w+\(\s*\$\w+\s*,\s*(\[\s*\'[^\]]*\])/';

    /**
     * Styl zdroje => vzory. `tables`/`columns` jsou regulární výrazy nad kódem bez komentářů;
     * první skupina je výčet nebo jeden literál, z něj se berou všechny literály v apostrofech.
     * U `assoc_tables` je tabulkou klíč pole (`'Tabulka' => …`) a sloupcem hodnota.
     */
    public const STYLES = [
        // POHODA / PAMICA: export mdbExport, řádek tabulky = element, sloupec = podřízený element.
        'pohoda_xml' => [
            'tables' => [
                '/PohodaXml::(?:scan|records|count)\(\s*\$\w+\s*,\s*(\[[^\]]*\]|\'[^\']+\')/',
                '/const\s+\w*TABLES\w*\s*=\s*(\[[^;]*\]);/',
                '/\$(?:byId|items|tables)\s*=\s*(\[[^;]*\]);/',
            ],
            'columns' => [
                '/PohodaXml::(?:text|num|date|attr|get|all|attributes)\(\s*(?:[^,()]|\([^()]*\))+,\s*(\'[^\']+\'(?:\s*\.)?)/',
                '/\$\w+(?:\[[^\]]+\])*\[(\'[A-Z][A-Za-z0-9_]*\')\]/',
                '/const\s+\w*COLUMNS\w*\s*=\s*(\[[^;]*\]);/',
                '/array_intersect_key\(\s*\$\w+\s*,\s*(\[[^\]]*\])/',
                self::HELPER_COLUMN_LIST,
            ],
            'assoc_tables' => ['/const\s+\w*COLUMNS\w*\s*=\s*(\[[^;]*\]);/'],
            'column_shape' => '/^[A-Z][A-Za-z0-9_]*$/D',
        ],
        // PREMIER: záloha DBF, `$backup->rows('TABULKA')`, sloupce velkými písmeny.
        'premier_dbf' => [
            'tables' => [
                '/(?:\$backup|->backup)->(?:rows|hasRows|hasTable|table|all)\(\s*(\'[A-Za-z0-9_]+\')/',
                '/foreach\s*\(\s*(\[[^\]]*\])\s*as\s*\$table\b/',
                '/const\s+\w*TABLES\w*\s*=\s*(\[[^;]*\]);/',
            ],
            'columns' => [
                '/\$\w+(?:\[[^\]]+\])*\[(\'[A-Z][A-Z0-9_]*\')\]/',
                // Přístup přes lokální closure nad řádkem (`$num('MZ_HRUBA')`).
                '/\$(?:num|text|date|flag|int|bool|value)\(\s*(\'[A-Z][A-Z0-9_]*\')/',
                self::HELPER_COLUMN_LIST,
                '/const\s+\w*(?:COLUMNS|FIELDS)\w*\s*=\s*(\[[^;]*\]);/',
            ],
            'assoc_tables' => [],
            'column_shape' => '/^[A-Z][A-Z0-9_]*$/D',
        ],
        // Money S3: tabulky zálohy přes `Ms3Backup::table()`, sloupce v PascalCase.
        'ms3_rows' => [
            'tables' => [
                '/(?:->table|self::rows)\(\s*(?:\$\w+\s*,\s*)?(\'[A-Za-z0-9_]+\')/',
                '/self::documents\(\s*\$\w+\s*,\s*(\'[A-Za-z0-9_]+\')/',
            ],
            'columns' => [
                '/\$\w+(?:\[[^\]]+\])*\[(\'[A-Z][A-Za-z0-9_]*\')\]/',
                '/self::rows\([^;]*?,\s*(\[[^\]]*\])\)/',
            ],
            'assoc_tables' => [],
            'column_shape' => '/^[A-Z][A-Za-z0-9_]*$/D',
        ],
        // Hlášení JMHZ (XML): element = prefix `p:` (podání) nebo `f:` (formulář) v XPath.
        'jmhz_xpath' => [
            'tables' => [],
            // Elementy, které čtečka skládá do XPath až za běhu (`'f:' . $element`).
            'columns' => [
                '/const\s+\w*(?:_ELEMENTS|EXCLUDED_\w+|VARIANTS)\s*=\s*(\[[^;]*\]);/',
                '/foreach\s*\(\s*(\[[^\]]*\])\s*as\s*\$element\b/',
            ],
            'assoc_keys_only' => true,
            'assoc_tables' => [],
            'column_shape' => '/^[A-Za-z][A-Za-z0-9]*$/D',
            'xpath' => true,
        ],
    ];

    /**
     * @param list<string> $files absolutní cesty k PHP souborům čtečky
     * @return array{tables: array<string, list<string>>, columns: array<string, list<string>>, literals: array<string, list<string>>}
     *         jméno => soubory, kde se čte; `literals` = všechny literály kódu (pro kódy katalogu)
     */
    public static function scan(array $files, string $style): array
    {
        $profile = self::STYLES[$style] ?? throw new \InvalidArgumentException("Neznámý styl zdroje {$style}");
        $out = ['tables' => [], 'columns' => [], 'literals' => []];
        foreach ($files as $file) {
            $code = self::codeWithoutComments((string) file_get_contents($file));
            $name = basename($file, '.php');
            foreach ($profile['tables'] as $pattern) {
                foreach (self::captured($pattern, $code) as $chunk) {
                    foreach (self::literals($chunk) as $literal) {
                        $out['tables'][$literal][$name] = true;
                    }
                }
            }
            foreach ($profile['assoc_tables'] as $pattern) {
                foreach (self::captured($pattern, $code) as $chunk) {
                    preg_match_all('/\'([^\']+)\'\s*=>/', $chunk, $m);
                    foreach ($m[1] as $literal) {
                        $out['tables'][$literal][$name] = true;
                    }
                }
            }
            foreach ($profile['columns'] as $pattern) {
                foreach (self::captured($pattern, $code) as $chunk) {
                    $assoc = [];
                    if (in_array($pattern, $profile['assoc_tables'], true)) {
                        preg_match_all('/\'([^\']+)\'\s*=>\s*\[/', $chunk, $m);
                        $assoc = array_fill_keys($m[1], true);
                        preg_match_all('/\'([^\']+)\'\s*=>\s*\'/', $chunk, $m2);
                        $assoc += array_fill_keys($m2[1], true);
                    }
                    // Literál, ke kterému se jméno sloupce teprve přilepí (`'Hodin' . $den`), je předpona.
                    $prefix = preg_match('/\'\s*\.$/', $chunk) === 1;
                    $literals = self::literals($chunk);
                    if (($profile['assoc_keys_only'] ?? false) === true && preg_match('/\'\s*=>/', $chunk) === 1) {
                        preg_match_all('/\'([^\']+)\'\s*=>/', $chunk, $keys);
                        $literals = $keys[1];
                    }
                    foreach ($literals as $literal) {
                        if (isset($assoc[$literal])) {
                            continue;
                        }
                        foreach (explode('/', $literal) as $segment) {
                            if (preg_match($profile['column_shape'], $segment) === 1) {
                                $out['columns'][$segment . ($prefix ? '*' : '')][$name] = true;
                            }
                        }
                    }
                }
            }
            if (($profile['xpath'] ?? false) === true) {
                preg_match_all('/\'([^\']*\b[pf]:[A-Za-z][^\']*)\'/', $code, $m);
                // Celé hlášení je jedna „tabulka" (kořen `jmhz`), elementy jsou její sloupce.
                if ($m[1] !== []) {
                    $out['tables']['jmhz'][$name] = true;
                }
                foreach ($m[1] as $path) {
                    preg_match_all('/\b[pf]:([A-Za-z][A-Za-z0-9]*)/', $path, $e);
                    foreach ($e[1] as $element) {
                        $out['columns'][$element][$name] = true;
                    }
                }
            }
            foreach (self::literals($code) as $literal) {
                $out['literals'][$literal][$name] = true;
            }
        }
        foreach ($out as $key => $names) {
            ksort($names);
            $out[$key] = array_map(static fn (array $f): array => array_keys($f), $names);
        }

        return $out;
    }

    /**
     * PHP soubory čtečky podle cest z matice (adresář = všechny soubory v něm, rekurzivně).
     *
     * @param list<string> $paths cesty relativní ke kořeni repa
     * @return list<string>
     */
    public static function files(string $root, array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            $full = $root . '/' . $path;
            if (is_file($full)) {
                $files[] = $full;
                continue;
            }
            if (!is_dir($full)) {
                throw new \RuntimeException("Čtečka {$path} neexistuje");
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }
        sort($files);

        return array_values(array_unique($files));
    }

    private static function codeWithoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    /** @return list<string> */
    private static function captured(string $pattern, string $code): array
    {
        preg_match_all($pattern, $code, $m);

        return $m[1] ?? [];
    }

    /** @return list<string> */
    private static function literals(string $chunk): array
    {
        preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'/', $chunk, $m);

        return array_values(array_unique(array_map('stripslashes', $m[1])));
    }
}
