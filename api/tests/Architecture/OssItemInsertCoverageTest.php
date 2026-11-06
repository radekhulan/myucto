<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\PhpSourceRegions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kdo zakládá řádek faktury, musí u něj rozhodnout o MÍSTĚ PLNĚNÍ.
 *
 * `invoice_items.oss_applicable` má DEFAULT 0, takže cesta, která ho nezapíše, tiše
 * vyrobí TUZEMSKÝ řádek. To není chybějící údaj, ale nesprávný: polská daň skončí
 * na ř. 1 českého přiznání a v OSS podání nebude vidět vůbec. Nic to nezachytí —
 * doklad má správné součty, projde validací i účtováním, a rozdíl je vidět až
 * v přiznání, které už někdo odeslal.
 *
 * Přesně tak unikly DVĚ cesty naráz, přestože OSS bylo jinak hotové:
 *   - `FinalFromProformaCreator` — vyúčtovací faktura kopírovala položky proformy
 *     bez OSS sloupců, takže OSS řádek se na daňovém dokladu změnil na tuzemský;
 *   - `PaymentTaxDocumentCreator` — daňový doklad k přijaté platbě (§ 37a) vznikal
 *     bez OSS sloupců, takže záloha na OSS plnění spadla do tuzemského přiznání.
 *
 * Guard proto hlídá KAŽDÝ `INSERT INTO invoice_items` ve zdrojácích: metoda, která
 * ho obsahuje, musí o OSS prokazatelně vědět — buď sloupce vyjmenuje sama, nebo je
 * složí přes sdílený {@see \MyInvoice\Service\Oss\OssItemCarryOver}.
 *
 * ── Granularita výjimek ─────────────────────────────────────────────────────────────
 * Výjimka se uděluje POJMENOVANÉ METODĚ, ne souboru — poučení z
 * {@see DocumentBranchParityGuardsTest}: allowlist na úrovni souboru vypne kontrolu
 * i pro kód, který s výjimkou nesouvisí, a guard pak tvrdí, že hlídá, aniž by
 * kontroloval cokoli.
 *
 * Guard je statický (čte zdrojáky), takže degraduje při přejmenování metody —
 * to ale nahlásí {@see testAllowlistSymbolsExist()}. Běhový důkaz pro obě opravené
 * cesty je {@see \MyInvoice\Tests\Integration\Invoice\OssDerivedDocumentsTest}.
 */
#[Group('architecture')]
final class OssItemInsertCoverageTest extends TestCase
{
    private const NEEDLE = 'INSERT INTO invoice_items';

    /**
     * Důkaz, že metoda o místě plnění rozhoduje: buď zapisuje sloupec přímo, nebo
     * si sadu sloupců vyžádá od sdíleného přenašeče.
     */
    private const EVIDENCE = ['oss_applicable', 'OssItemCarryOver', 'ossCarry'];

    /**
     * Metody, kde je zápis BEZ OSS správně. Klíč = cesta relativní k `api/src`,
     * hodnota = mapa `jméno metody => důvod`.
     *
     * @var array<string, array<string, string>>
     */
    private const ALLOWED_WITHOUT_OSS = [
        // Ukázková data pro nový účet: ryze tuzemská česká firma s českými odběrateli,
        // žádné přeshraniční B2C plnění. OSS profil by tu byl fikce, ne údaj — a demo
        // doklady se nikdy nedostanou do skutečného přiznání.
        'Service/Sample/SampleDataGenerator.php' => [
            'generate' => 'ukázková data jsou tuzemská, OSS profil by byl vymyšlený',
        ],
        // Postupné čerpání zálohy odmítne daňový doklad k platbě s řádky OSS (taxDocumentGroups),
        // odpočet proto vždy ruší jen tuzemskou daň.
        'Service/Invoice/AdvancePartialDrawdown.php' => [
            'applyUnlocked' => 'čerpá jen zálohu s tuzemským daňovým dokladem, OSS odmítá',
        ],
    ];

    public function testEveryInvoiceItemInsertDecidesThePlaceOfSupply(): void
    {
        $srcDir = self::srcDir();
        $offenders = [];

        foreach (self::phpFiles($srcDir) as $path) {
            $raw = (string) file_get_contents($path);
            if (!str_contains($raw, self::NEEDLE)) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($path, strlen($srcDir) + 1));
            $exempt = self::ALLOWED_WITHOUT_OSS[$rel] ?? [];

            foreach (self::insertSymbols($raw) as $symbol => $region) {
                if (isset($exempt[$symbol]) || self::hasOssEvidence($region)) {
                    continue;
                }
                $offenders[] = $rel . '::' . $symbol;
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Zápis řádku faktury bez rozhodnutí o místě plnění:\n  %s\n\n"
                . "`oss_applicable` má DEFAULT 0, takže nezapsaný sloupec NENÍ chybějící údaj, ale\n"
                . "tiché zařazení do TUZEMSKA — cizí daň skončí na ř. 1 přiznání a v OSS podání\n"
                . "nebude vidět. Přenášíš-li řádky z jiného dokladu, použij\n"
                . "MyInvoice\\Service\\Oss\\OssItemCarryOver; zakládáš-li nové plnění, ptej se\n"
                . 'OssItemPlanneru. Je-li zápis bez OSS správně, přidej METODU do ALLOWED_WITHOUT_OSS.',
            implode("\n  ", $offenders),
        ));
    }

    /**
     * Allowlist se nesmí rozejít s kódem: záznam na přejmenovanou metodu nevyjímá nic,
     * ale tváří se, že výjimku kryje — a příště podle něj někdo usoudí, že zápis bez
     * OSS je na tom místě v pořádku.
     */
    public function testAllowlistSymbolsExist(): void
    {
        $srcDir = self::srcDir();
        $stale = [];

        foreach (self::ALLOWED_WITHOUT_OSS as $rel => $symbols) {
            $path = $srcDir . '/' . $rel;
            if (!is_file($path)) {
                $stale[] = $rel . ' — soubor neexistuje';
                continue;
            }
            $raw = (string) file_get_contents($path);
            foreach (PhpSourceRegions::missingSymbols($raw, array_keys($symbols)) as $missing) {
                $stale[] = $rel . '::' . $missing . ' — metoda neexistuje';
                continue;
            }
            // Nosnost: metoda, která už žádný `INSERT INTO invoice_items` neobsahuje
            // (nebo ho mezitím o OSS doplnila), výjimku nepotřebuje.
            foreach (array_keys($symbols) as $symbol) {
                $region = self::insertSymbols($raw)[$symbol] ?? null;
                if ($region === null || self::hasOssEvidence($region)) {
                    $stale[] = $rel . '::' . $symbol . ' — výjimka už není potřeba';
                }
            }
        }

        self::assertSame([], $stale, sprintf(
            "Zastaralý záznam v ALLOWED_WITHOUT_OSS:\n  %s\n\nSmaž ho, nebo oprav jméno metody. "
                . 'Nepotřebná výjimka kryje i budoucí regresi na téže metodě.',
            implode("\n  ", $stale),
        ));
    }

    /**
     * Metody obsahující `INSERT INTO invoice_items` → jejich zdrojový text.
     *
     * @return array<string, string> jméno metody => její kód
     */
    private static function insertSymbols(string $code): array
    {
        $lines = explode("\n", $code);
        $out = [];

        foreach ($lines as $index => $line) {
            if (!str_contains($line, self::NEEDLE)) {
                continue;
            }
            $symbol = PhpSourceRegions::symbolAtLine($code, $index + 1) ?? '(mimo metodu)';
            if (isset($out[$symbol])) {
                continue;
            }
            $out[$symbol] = self::symbolSource($code, $symbol) ?? $line;
        }

        return $out;
    }

    private static function symbolSource(string $code, string $name): ?string
    {
        $lines = explode("\n", $code);
        foreach (PhpSourceRegions::symbols($code) as $sym) {
            if ($sym['name'] !== $name) {
                continue;
            }
            return implode("\n", array_slice($lines, $sym['startLine'] - 1, $sym['endLine'] - $sym['startLine'] + 1));
        }

        return null;
    }

    private static function hasOssEvidence(string $region): bool
    {
        foreach (self::EVIDENCE as $needle) {
            if (str_contains($region, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function srcDir(): string
    {
        return dirname(__DIR__, 2) . '/src';
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f instanceof \SplFileInfo && $f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }
}
