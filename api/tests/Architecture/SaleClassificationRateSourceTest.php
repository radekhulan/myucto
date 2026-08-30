<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Repository\InvoiceRepository;
use PHPUnit\Framework\TestCase;

/**
 * Základní sazba DPH v auto-klasifikaci plnění musí pocházet z ČÍSELNÍKU daňových
 * konstant podle ROKU DOKLADU, ne z čísla v kódu.
 *
 * `InvoiceRepository::defaultSaleClassificationCode()` (vystavené) a
 * `PurchaseInvoiceRepository::defaultClassificationCode()` (přijaté) rozhodují podle
 * `$standardRate`, kdy sazba na řádku znamená „tuzemská základní" (ř. 1 / ř. 40) a kdy
 * sníženou (ř. 2 / ř. 41). Parametr míval default 21.0 a čtyři produkční volání na
 * vystavené straně ho nepředávala vůbec — dokud jsou sazby 21/12, dopadne to správně,
 * po novele § 47 ZDPH by ale doklady tiše sedly na špatném řádku přiznání a v KH.
 * Nespadlo by nic, jen by nesouhlasila daň.
 *
 * Guard proto hlídá dvě věci:
 *   1. `defaultSaleClassificationCode()` NEMÁ pro sazbu výchozí hodnotu — volající musí
 *      kontext roku dodat, jinak kód ani nezkompiluje (silnější než tento test; ten hlídá,
 *      ať se default nevrátí). Přijatá strana default zatím drží: má za sazbou další
 *      volitelné parametry (`publicAuthorityFee`, `tenantIsVatPayer`), takže jeho zrušení
 *      by je vynutilo taky. Tam invariant drží bod 2.
 *   2. žádné volání ve `src/` ani `bin/` nepředává jako sazbu ČÍSELNÝ LITERÁL — hodnota
 *      musí přijít z `TaxConstantsRepository::vatRateStandard()` (přímo nebo přes proměnnou).
 *
 * Testy literál 21.0 předávat SMĚJÍ: ověřují chování funkce, ne obsah číselníku.
 */
final class SaleClassificationRateSourceTest extends TestCase
{
    /** Adresáře s produkčním kódem (relativně k api/). */
    private const PRODUCTION_DIRS = ['src', 'bin'];

    /** Hlídané metody → index argumentu se základní sazbou (0-based). */
    private const METHODS = [
        'defaultSaleClassificationCode' => 4,
        'defaultClassificationCode'     => 3,
    ];

    public function testStandardRateParameterHasNoDefault(): void
    {
        $param = (new \ReflectionMethod(InvoiceRepository::class, 'defaultSaleClassificationCode'))
            ->getParameters()[4] ?? null;

        self::assertNotNull($param, 'Signatura se změnila — 5. parametr (základní sazba) chybí.');
        self::assertSame('standardRate', $param->getName());
        self::assertFalse(
            $param->isDefaultValueAvailable(),
            'Základní sazba nesmí mít výchozí hodnotu: volající, který nepředá rok dokladu, '
                . 'by dostal tiše správnou odpověď jen do nejbližší změny sazby.',
        );
    }

    public function testNoProductionCallPassesLiteralRate(): void
    {
        $calls = $this->productionCalls();
        self::assertNotSame([], $calls, 'Nenašlo se žádné produkční volání — guard by mlčel.');

        $offenders = [];
        foreach ($calls as [$file, $line, $method, $args]) {
            $rate = $args[self::METHODS[$method]] ?? null;
            if ($rate === null) {
                $offenders[] = "$file:$line ($method) — volání nepředává základní sazbu vůbec";
                continue;
            }
            if (preg_match('/^-?\d+(\.\d+)?$/', $rate) === 1) {
                $offenders[] = "$file:$line ($method) — základní sazba je literál `$rate`";
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Základní sazba musí přijít z TaxConstantsRepository::vatRateStandard(rok dokladu):\n%s",
            implode("\n", $offenders),
        ));
    }

    /**
     * Statická volání `defaultSaleClassificationCode(...)` v produkčním kódu.
     *
     * @return list<array{0:string,1:int,2:string,3:list<string>}>
     */
    private function productionCalls(): array
    {
        $out = [];
        foreach (self::PRODUCTION_DIRS as $dir) {
            $root = dirname(__DIR__, 2) . '/' . $dir;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                $wanted = array_filter(
                    array_keys(self::METHODS),
                    static fn (string $m): bool => str_contains($source, $m),
                );
                if ($wanted === []) {
                    continue;
                }
                $relative = $dir . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                foreach ($this->callsIn($source) as [$line, $method, $args]) {
                    $out[] = [$relative, $line, $method, $args];
                }
            }
        }
        return $out;
    }

    /**
     * Volání ve zdrojáku, rozsekaná na top-level čárkách. Jede přes `token_get_all()`,
     * ne přes regex/strpos: čárky se běžně vyskytují uvnitř komentářů i řetězců
     * ({@see \MyInvoice\Service\Import\InvoiceImportService}) a naivní parser by na
     * nich argument rozdělil — a hodnotu sazby by pak četl ze špatné pozice, tedy mlčel.
     *
     * @return list<array{0:int,1:string,2:list<string>}>
     */
    private function callsIn(string $source): array
    {
        $tokens = token_get_all($source);
        $calls = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STRING || !isset(self::METHODS[$t[1]])) {
                continue;
            }
            // Jen statické volání `Něco::metoda(` — definice metody ani `{@see}`
            // v docblocku (ten je jeden T_DOC_COMMENT token) sem nedosáhnou.
            $prev = $tokens[$i - 1] ?? null;
            if (!is_array($prev) || $prev[0] !== T_DOUBLE_COLON) {
                continue;
            }
            $j = $this->skipWhitespace($tokens, $i + 1);
            if (($tokens[$j] ?? null) !== '(') {
                continue;
            }

            $depth = 0;
            $args = [];
            $current = '';
            for ($k = $j; $k < $count; $k++) {
                $tk = $tokens[$k];
                $text = is_array($tk) ? $tk[1] : $tk;
                if (is_array($tk) && in_array($tk[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($tk === '(' || $tk === '[') {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif ($tk === ')' || $tk === ']') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($tk === ',' && $depth === 1) {
                    $args[] = trim($current);
                    $current = '';
                    continue;
                }
                $current .= $text;
            }
            if (trim($current) !== '') {
                $args[] = trim($current);
            }
            $calls[] = [$t[2], $t[1], $args];
        }

        return $calls;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private function skipWhitespace(array $tokens, int $i): int
    {
        while (is_array($tokens[$i] ?? null) && $tokens[$i][0] === T_WHITESPACE) {
            $i++;
        }
        return $i;
    }
}
