<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\PhpSourceRegions;
use PHPUnit\Framework\TestCase;

/**
 * MZ-02-W09 — „posun kalendáře o rok".
 *
 * Mzdový modul měl rok 2026 zadrátovaný jako bránu na čtyřech místech (daň,
 * absence, čtvrtletní průměry, nárok na dovolenou, matice podpory). 1. 1. 2027
 * by přestal počítat i s existujícím rulesetem pro 2027. Podporovaný rok se
 * proto odvozuje z účinného rulesetu a tenhle guard hlídá, že se literál roku
 * nevrátí zpátky jako podmínka.
 *
 * Guard hledá letopočet, který je OPERANDEM POROVNÁNÍ nebo argumentem
 * prefixového / množinového testu — ne letopočet jako takový. Účinnosti
 * rulesetů, ID („cz-payroll-2026…"), verze tiskopisů a klíče katalogů jsou
 * legitimní data, ne brány.
 *
 * Výjimky se udělují SYMBOLU (metodě), nikdy souboru: allowlist na úrovni
 * souboru by vypnul kontrolu i pro kód, který s výjimkou nesouvisí.
 */
final class PayrollCalendarYearPinGuardTest extends TestCase
{
    /**
     * Metody, kde je letopočet skutečně zákonná jednorázovka, ne brána modulu.
     *
     * @var array<string, list<string>> relativní cesta => jména symbolů
     */
    private const ALLOWED_SYMBOLS = [
        // Totéž přechodné ustanovení z druhé strany: od 1. 1. 2026 sestavuje
        // evidenční list ČSSZ z měsíčního hlášení a samostatný list zůstává
        // zaměstnavateli jen za starší roky, při skončení účasti před
        // 1. 4. 2026 a na výzvu. Jsou to data z přechodného ustanovení, ne rok
        // podpory modulu — rozsah podporovaných let evidenčního listu se
        // neptá na letopočet, ale na existenci zmrazené revize.
        'Service/Payroll/Submission/Eldp/EldpDeadlinePolicy.php' =>
            ['standaloneStatementAllowed'],
        // Rozsah 2000–2099 je typ `rokHlaseni` z jednotného XSD zdravotních
        // pojišťoven, tedy kontrola formátu datové věty — ne rok podpory
        // modulu. Kdyby se neuplatnila tady, spadne stejně na schématu;
        // rozdíl je jen v tom, jestli uživatel dostane srozumitelnou větu,
        // nebo hlášku libxml.
        'Service/Payroll/Submission/HealthInsurance/HealthPaymentOverviewPayload.php' =>
            ['assertValid'],
    ];

    public function testNoCalendarYearLiteralGatesPayrollCalculations(): void
    {
        $findings = [];
        foreach ($this->payrollSourceFiles() as $relative => $file) {
            $code = (string) file_get_contents($file);
            $allowed = self::ALLOWED_SYMBOLS[$relative] ?? [];
            self::assertSame(
                [],
                PhpSourceRegions::missingSymbols($code, $allowed),
                "Allowlist guardu se rozešel s kódem v {$relative}.",
            );
            $scanned = PhpSourceRegions::withoutSymbols(self::withoutComments($code), $allowed);
            foreach (self::yearGates($scanned) as $line => $snippet) {
                $findings[] = "{$relative}:{$line}  {$snippet}";
            }
        }

        self::assertSame(
            [],
            $findings,
            "Letopočet nesmí být bránou mzdového výpočtu — podporovaný rok se odvozuje\n"
            . "z účinného rulesetu (PayrollRulesetYearCoverage). Nálezy:\n"
            . implode("\n", $findings),
        );
    }

    public function testGuardDetectsAYearLiteralUsedAsAGate(): void
    {
        $sample = <<<'PHP'
            <?php
            final class Sample
            {
                public function gate(int $year, string $date): void
                {
                    // 2026 v komentáři guard nezajímá
                    if ($year !== 2026) {
                        throw new \RuntimeException('ne');
                    }
                    if (!str_starts_with($date, '2026-')) {
                        throw new \RuntimeException('ne');
                    }
                    if (!in_array($year, [2024, 2025, 2026], true)) {
                        throw new \RuntimeException('ne');
                    }
                    $ok = substr($date, 0, 4) === '2026';
                }
            }
            PHP;

        $gates = self::yearGates(self::withoutComments($sample));

        self::assertCount(4, $gates, 'Guard musí najít všechny čtyři podoby brány.');
    }

    public function testGuardIgnoresYearsThatAreDataNotGates(): void
    {
        $sample = <<<'PHP'
            <?php
            final class Sample
            {
                private const FORMS = [2026 => ['form' => '25 5460']];

                public function data(): array
                {
                    return [
                        'id' => 'cz-payroll-2026.income-tax.v1',
                        'effective_from' => '2026-01-01',
                        'valid_to' => sprintf('%04d-12-31', 2026),
                    ];
                }
            }
            PHP;

        self::assertSame([], self::yearGates(self::withoutComments($sample)));
    }

    /**
     * Letopočet v roli brány: operand rovnosti, argument prefixového či
     * množinového testu, nebo ručně udržovaný výčet let.
     *
     * Rozsahové porovnání se hlídá jen pro roky současné mzdové éry
     * (2020–2099). `$year < 1900`, `> 2199` nebo `>= 1954` jsou kontroly
     * formátu a historické platnosti, ne brány podporovaného roku — a guard,
     * který by je hlásil, by se rychle vypnul celý.
     *
     * @return array<int,string> číslo řádku => úryvek
     */
    private static function yearGates(string $code): array
    {
        $year = "(?:19|20)[0-9]{2}";
        $currentEra = "20[2-9][0-9]";
        $equality = "(?:===|!==|==(?!>)|!=)";
        $ordering = "(?:<=>|<=|>=|(?<![=<>])<|(?<![=!<>])>)";
        $patterns = [
            "/{$equality}\\s*'?{$year}'?[-\\s;),]/",
            "/'?{$year}'?\\s*{$equality}/",
            "/{$ordering}\\s*'?{$currentEra}'?[-\\s;),]/",
            "/'?{$currentEra}'?\\s*{$ordering}/",
            "/\\bstr_(?:starts_with|ends_with|contains)\\s*\\([^;]*?'{$year}/",
            "/\\bin_array\\s*\\([^;]*?\\b{$year}\\b/",
            "/\\[\\s*{$year}\\s*,\\s*{$year}/",
        ];

        $findings = [];
        foreach (explode("\n", $code) as $index => $line) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $findings[$index + 1] = trim($line);
                    break;
                }
            }
        }

        return $findings;
    }

    /**
     * Komentáře se vyprazdňují, ne mažou — čísla řádků musí zůstat platná
     * a guard nesmí hlásit nález, který žije jen v komentáři.
     */
    private static function withoutComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * Sken pokrývá i HTTP vrstvu: brána na rok stejně často sedí v akci jako
     * ve službě (`PayrollAbsenceAction` pinnul rok dovolené vedle validátoru).
     *
     * @return array<string,string> relativní cesta => absolutní cesta
     */
    private function payrollSourceFiles(): array
    {
        $src = str_replace('\\', '/', dirname(__DIR__, 2) . '/src');
        $files = [];
        foreach (['Service/Payroll', 'Action/Payroll'] as $module) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $src . '/' . $module,
                    \FilesystemIterator::SKIP_DOTS,
                ),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = str_replace('\\', '/', $file->getPathname());
                $files[substr($path, strlen($src) + 1)] = $path;
            }
        }
        self::assertNotEmpty($files);
        ksort($files);

        return $files;
    }
}
