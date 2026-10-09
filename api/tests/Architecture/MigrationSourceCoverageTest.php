<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollCatalog;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollPeople;
use MyInvoice\Tests\Support\MigrationSourceReaderScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Brána G1: matice pokrytí zdroje pro převody mezd (api/resources/migration/<zdroj>.json).
 *
 * Každá tabulka, sloupec a položka katalogu cizího programu, kterou čtečka zná, je v matici
 * `mapped` (kam se převádí) nebo `ignored` (proč ne); položka katalogu může být i `warned`
 * (převod ji nezná a hlásí ji v protokolu varováním). Test selže, když
 *  - čtečka sáhne na tabulku, sloupec nebo kód položky, který v matici není (nebo je ignored),
 *  - matice tvrdí `mapped` u tabulky, sloupce nebo kódu, který čtečka nečte,
 *  - u katalogu s klasifikátorem se stav položky neshoduje s tím, co převod opravdu udělá.
 *
 * Matice vznikla z inventury reálných exportů (jen jména a kódy, žádná data). Nová verze
 * zdroje: znovu udělat inventuru a doplnit nové tabulky, sloupce a položky.
 */
final class MigrationSourceCoverageTest extends TestCase
{
    private const TABLE_STATUSES = ['mapped', 'ignored'];
    private const ITEM_STATUSES = ['mapped', 'ignored', 'warned'];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $matrices = null;

    /** @return array<string, array<string, mixed>> */
    private static function matrices(): array
    {
        if (self::$matrices !== null) {
            return self::$matrices;
        }
        $out = [];
        foreach (glob(self::root() . '/api/resources/migration/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            self::assertIsArray($data, 'Neplatný JSON: ' . basename($file));
            $out[basename($file, '.json')] = $data;
        }
        ksort($out);

        return self::$matrices = $out;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return iterable<string, array{string}> */
    public static function sources(): iterable
    {
        foreach (array_keys(self::matrices()) as $name) {
            yield $name => [$name];
        }
    }

    /** @return array{tables: array<string, list<string>>, columns: array<string, list<string>>, literals: array<string, list<string>>} */
    private static function scan(string $source): array
    {
        $meta = self::matrices()[$source]['meta'];

        return MigrationSourceReaderScanner::scan(
            MigrationSourceReaderScanner::files(self::root(), $meta['readers']),
            (string) $meta['style'],
        );
    }

    public function testEveryPayrollMigrationHasAMatrix(): void
    {
        foreach (['pamica', 'premier', 'jmhz', 'money-s3'] as $source) {
            self::assertArrayHasKey($source, self::matrices(), "Chybí api/resources/migration/{$source}.json");
        }
    }

    #[DataProvider('sources')]
    public function testMatrixShapeAndEveryIgnoredEntryHasAReason(string $source): void
    {
        $m = self::matrices()[$source];
        $errors = [];
        foreach (['source', 'program', 'style', 'readers', 'unknown_items', 'verified_against'] as $key) {
            if (($m['meta'][$key] ?? null) === null || ($m['meta'][$key] ?? null) === [] || ($m['meta'][$key] ?? null) === '') {
                $errors[] = "meta.{$key} chybí";
            }
        }
        if (!isset(MigrationSourceReaderScanner::STYLES[$m['meta']['style'] ?? ''])) {
            $errors[] = 'neznámý meta.style';
        }
        foreach ($m['tables'] ?? [] as $table => $t) {
            $status = $t['status'] ?? null;
            if (!in_array($status, self::TABLE_STATUSES, true)) {
                $errors[] = "{$table}: nepovolený status " . json_encode($status);
                continue;
            }
            if ($status === 'ignored') {
                if (!self::text($t['reason'] ?? null)) {
                    $errors[] = "{$table}: ignored bez důvodu";
                }
                if (isset($t['columns'])) {
                    $errors[] = "{$table}: ignorovaná tabulka nemá vypisovat sloupce";
                }
                continue;
            }
            if (!self::text($t['target'] ?? null)) {
                $errors[] = "{$table}: mapped bez cíle";
            }
            foreach ($t['columns'] ?? [] as $column => $c) {
                $cs = $c['status'] ?? null;
                if (!in_array($cs, self::TABLE_STATUSES, true)) {
                    $errors[] = "{$table}.{$column}: nepovolený status " . json_encode($cs);
                } elseif ($cs === 'mapped' && !self::text($c['target'] ?? null)) {
                    $errors[] = "{$table}.{$column}: mapped bez cíle";
                } elseif ($cs === 'ignored' && !self::text($c['reason'] ?? null) && !self::text($t['ignored_columns_reason'] ?? null)) {
                    $errors[] = "{$table}.{$column}: ignored bez důvodu (ani ignored_columns_reason tabulky)";
                }
            }
        }
        foreach ($m['catalogs'] ?? [] as $catalog => $c) {
            foreach ($c['items'] ?? [] as $code => $item) {
                $s = $item['status'] ?? null;
                if (!in_array($s, self::ITEM_STATUSES, true)) {
                    $errors[] = "{$catalog}/{$code}: nepovolený status " . json_encode($s);
                } elseif ($s === 'mapped' && !self::text($item['target'] ?? null)) {
                    $errors[] = "{$catalog}/{$code}: mapped bez cíle";
                } elseif ($s !== 'mapped' && !self::text($item['reason'] ?? null)) {
                    $errors[] = "{$catalog}/{$code}: {$s} bez důvodu";
                }
            }
        }
        self::assertSame([], array_slice($errors, 0, 30), implode("\n", array_slice($errors, 0, 30)));
    }

    #[DataProvider('sources')]
    public function testReaderTouchesOnlyMappedTablesAndColumns(string $source): void
    {
        $m = self::matrices()[$source];
        $scan = self::scan($source);
        $mappedTables = self::mappedTables($m);
        $mappedColumns = self::mappedColumns($m);
        $errors = [];
        foreach ($scan['tables'] as $table => $files) {
            if (!isset($mappedTables[self::key($table)])) {
                $state = isset(self::tableIndex($m)[self::key($table)]) ? 'je v matici ignored' : 'v matici není';
                $errors[] = "tabulka {$table} (" . implode(', ', $files) . ") {$state}";
            }
        }
        // Klíč, který čtečka do řádku sama doplní (ne sloupec zdroje); matice ho vyjmenuje s původem.
        $synthetic = $m['meta']['synthetic_columns'] ?? [];
        foreach ($scan['columns'] as $column => $files) {
            if (isset($synthetic[$column])) {
                continue;
            }
            if (str_ends_with($column, '*')) {
                $prefix = substr($column, 0, -1);
                $hit = array_filter(array_keys($mappedColumns), static fn (string $c): bool => str_starts_with($c, $prefix));
                if ($hit === []) {
                    $errors[] = "sloupce {$column} (" . implode(', ', $files) . ') nejsou v matici mapped';
                }
                continue;
            }
            if (!isset($mappedColumns[$column]) && !isset($mappedTables[self::key($column)])) {
                $errors[] = "sloupec {$column} (" . implode(', ', $files) . ') není v matici mapped u žádné tabulky';
            }
        }
        self::assertSame([], array_slice($errors, 0, 30), "{$source}:\n" . implode("\n", array_slice($errors, 0, 30)));
    }

    #[DataProvider('sources')]
    public function testEveryMappedEntryIsReallyRead(string $source): void
    {
        $m = self::matrices()[$source];
        $scan = self::scan($source);
        $readTables = array_fill_keys(array_map(self::key(...), array_keys($scan['tables'])), true);
        $readColumns = $scan['columns'];
        $prefixes = array_map(static fn (string $p): string => substr($p, 0, -1), array_filter(array_keys($readColumns), static fn (string $c): bool => str_ends_with($c, '*')));
        $errors = [];
        foreach ($m['tables'] as $table => $t) {
            if (($t['status'] ?? null) !== 'mapped') {
                continue;
            }
            if (!isset($readTables[self::key((string) $table)])) {
                $errors[] = "tabulka {$table} je mapped, ale čtečka ji nečte";
            }
            foreach ($t['columns'] ?? [] as $column => $c) {
                if (($c['status'] ?? null) !== 'mapped') {
                    continue;
                }
                $column = (string) $column;
                $byPrefix = array_filter($prefixes, static fn (string $p): bool => str_starts_with($column, $p));
                if (!isset($readColumns[$column]) && $byPrefix === []) {
                    $errors[] = "sloupec {$table}.{$column} je mapped, ale čtečka ho nečte";
                }
            }
        }
        self::assertSame([], array_slice($errors, 0, 30), "{$source}:\n" . implode("\n", array_slice($errors, 0, 30)));
    }

    /**
     * Kódy položek, které čtečka vyjmenovává (konstanty tříd a literály v kódu), musí být
     * v katalogu matice jako `mapped`. Obráceně položka `mapped` bez klasifikátoru musí mít
     * kód mezi nimi.
     */
    #[DataProvider('sources')]
    public function testCatalogCodesNamedByReaderAreMapped(string $source): void
    {
        $m = self::matrices()[$source];
        $errors = [];
        // Literály kódů v kódu čtečky (meta.code_literals) patří do kteréhokoli katalogu zdroje.
        $mappedAnywhere = [];
        foreach ($m['catalogs'] ?? [] as $c) {
            foreach ($c['items'] ?? [] as $code => $item) {
                if (($item['status'] ?? null) === 'mapped') {
                    $mappedAnywhere[strtoupper((string) $code)] = true;
                }
            }
        }
        foreach ($m['meta']['code_literals'] ?? [] as $spec) {
            $code = self::stripComments((string) file_get_contents(self::root() . '/' . $spec['file']));
            preg_match_all('/\'(' . $spec['pattern'] . ')\'/', $code, $hits);
            foreach (array_unique($hits[1]) as $literal) {
                if (!isset($mappedAnywhere[strtoupper($literal)])) {
                    $errors[] = "kód {$literal} ({$spec['file']}) není v žádném katalogu matice mapped";
                }
            }
        }
        foreach ($m['catalogs'] ?? [] as $catalog => $c) {
            $known = [];
            foreach ($c['code_sources'] ?? [] as $ref) {
                [$class, $const, $part] = array_pad(explode('::', (string) $ref), 3, 'values');
                $value = (new ReflectionClassConstant($class, $const))->getValue();
                self::assertIsArray($value, "{$ref} není pole");
                foreach ($part === 'keys' ? array_keys($value) : array_values($value) as $code) {
                    $known[strtoupper((string) $code)][] = $ref;
                }
            }
            $items = $c['items'] ?? [];
            foreach ($known as $code => $refs) {
                $status = $items[$code]['status'] ?? null;
                if ($status !== 'mapped') {
                    $errors[] = "{$catalog}/{$code} (" . implode(', ', array_unique($refs)) . ') ' . ($status === null ? 'v matici není' : "je v matici {$status}");
                }
            }
            if (($c['classifier'] ?? null) === null) {
                foreach ($items as $code => $item) {
                    if (($item['status'] ?? null) === 'mapped' && !isset($known[strtoupper((string) $code)])) {
                        $errors[] = "{$catalog}/{$code} je mapped, ale čtečka kód nezná";
                    }
                }
            }
        }
        self::assertSame([], array_slice($errors, 0, 30), "{$source}:\n" . implode("\n", array_slice($errors, 0, 30)));
    }

    /**
     * Stav položky katalogu PAMICA se přehraje skutečnou klasifikací převodu: `warned` musí
     * převod opravdu hlásit (význam unknown), `ignored` vědomě vynechat, `mapped` převést.
     */
    public function testPamicaCatalogStatusesMatchConverterClassification(): void
    {
        $catalogs = self::matrices()['pamica']['catalogs'] ?? [];
        $errors = [];
        foreach ($catalogs['sMZslozky']['items'] ?? [] as $code => $item) {
            $probe = $item['probe'] ?? [];
            $meaning = PohodaPayrollCatalog::component((string) $code, (string) ($probe['name'] ?? ''), false, $probe['catalog'] ?? [])['meaning'];
            // `ignore` = složka se nepřevádí jako složka (základní mzda jde sazbou do sloupce
            // měsíční mzdy); matice ji vede jako mapped s tím cílem, nebo ignored s důvodem.
            $expected = $meaning === 'unknown' ? ['warned'] : ($meaning === 'ignore' ? ['mapped', 'ignored'] : ['mapped']);
            if (!in_array($item['status'] ?? null, $expected, true)) {
                $errors[] = "sMZslozky/{$code}: matice {$item['status']}, převod {$meaning}";
            }
        }
        foreach ($catalogs['sMZneprit']['items'] ?? [] as $code => $item) {
            $known = PohodaPayrollCatalog::absence((string) $code, '')['meaning'] !== 'unknown'
                || PohodaPayrollPeople::absenceType((string) $code, null) !== null;
            if (($item['status'] ?? null) !== ($known ? 'mapped' : 'warned')) {
                $errors[] = "sMZneprit/{$code}: matice {$item['status']}, převod " . ($known ? 'zná' : 'nezná');
            }
        }
        self::assertNotSame([], $catalogs['sMZslozky']['items'] ?? [], 'Katalog sMZslozky je prázdný');
        self::assertSame([], array_slice($errors, 0, 30), implode("\n", array_slice($errors, 0, 30)));
    }

    /** @return array<string, true> */
    private static function mappedTables(array $m): array
    {
        $out = [];
        foreach ($m['tables'] as $table => $t) {
            if (($t['status'] ?? null) === 'mapped') {
                $out[self::key((string) $table)] = true;
            }
        }

        return $out;
    }

    /** @return array<string, true> */
    private static function tableIndex(array $m): array
    {
        $out = [];
        foreach (array_keys($m['tables']) as $table) {
            $out[self::key((string) $table)] = true;
        }

        return $out;
    }

    /** @return array<string, true> */
    private static function mappedColumns(array $m): array
    {
        $out = [];
        foreach ($m['tables'] as $t) {
            if (($t['status'] ?? null) !== 'mapped') {
                continue;
            }
            foreach ($t['columns'] ?? [] as $column => $c) {
                if (($c['status'] ?? null) === 'mapped') {
                    $out[(string) $column] = true;
                }
            }
        }

        return $out;
    }

    /** Tabulky Money a PREMIER nedodržují velikost písmen v názvech souborů. */
    private static function key(string $table): string
    {
        return strtoupper($table);
    }

    private static function text(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function stripComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }
}
