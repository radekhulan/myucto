<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\PhpSourceRegions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Kdo založí nebo zruší VYDANÝ doklad, musí umožnit přepočet cache seznamu klientů
 * (`client_revenue_cache`/`project_revenue_cache`, plní {@see \MyInvoice\Service\Stats\StatsRecomputer}).
 *
 * Bez přepočtu zůstane seznam klientů (Firma → Klienti: POČET FAKTUR, OBRAT, POSLEDNÍ
 * AKTIVITA) ukazovat pomlčky/stará čísla, i když detail klienta (počítaný živě z `invoices`)
 * je v pořádku. Nic to nerozbije ani nespadne — proto to bez tohohle guardu není vidět:
 * doklad se založí správně, jen o něm cache neví.
 *
 * Přesně tak uniklo ŠEST cest naráz (ověřeno reprodukcí uživatele — import naimportoval
 * faktury, klient v detailu měl 9 faktur, seznam klientů ukazoval pomlčky, dokud se
 * nespustil `recompute-stats.php` ručně):
 *   - `InvoiceImportService::processOne()` — hlavní import souborů (ISDOC/Pohoda/PDF)
 *   - `FakturoidImportService::createIssued()`
 *   - `IdokladImportService::createIssuedFromIdoklad()` + `importIssuedCorrections()`
 *   - `BulkReissueAction::cloneOne()` — hromadné přefakturování
 *   - `FinalFromProformaCreator::createUnlocked()` — finální faktura ze zálohové
 *   - `PaymentTaxDocumentCreator::createForPayment()` — daňový doklad k přijaté platbě
 *
 * ── Dva needly, ne jeden ─────────────────────────────────────────────────────────────
 * Přímý zápis do `invoices` se v kódu dělá DVĚMA způsoby: syrovým `INSERT INTO invoices`
 * (headery dokladu s vlastním SQL) a přes sdílený nízkoúrovňový zapisovač
 * `InvoiceRepository::createDraft()` — ten ale vždycky zapíše `status = 'draft'`
 * (mimo agregaci cache), takže přepočet je odpovědností VOLAJÍCÍHO, jenž fakturu
 * dál posouvá k reálnému stavu. Proto se hlídá jen volání tvaru `$this->invoices->createDraft(`
 * — konvence názvu vlastnosti, kterou v repu skutečně používají cesty vytvářející
 * VYDANÉ doklady (Fakturoid/iDoklad import). Cesty vytvářející přijaté faktury
 * (`$this->purchaseRepo->createDraft(` / `$this->repo->createDraft(` s PurchaseInvoiceRepository)
 * cache klientů netýkají — ta počítá jen `invoices`, ne `purchase_invoices`.
 *
 * ── Granularita výjimek ─────────────────────────────────────────────────────────────
 * Výjimka se uděluje POJMENOVANÉ METODĚ, ne souboru (poučení z {@see OssItemInsertCoverageTest}
 * / `DocumentBranchParityGuardsTest`) — allowlist na úrovni souboru by vypnul kontrolu
 * i pro kód, který s výjimkou nesouvisí.
 *
 * ── Kde se hledá důkaz ───────────────────────────────────────────────────────────────
 * Nejdřív v TĚLE metody, která zápis provádí. Nenajde-li se tam, hledá se v CELÉM
 * souboru — u importních orchestrátorů (Fakturoid/iDoklad) zakládá doklad jedna
 * privátní metoda (`createIssued()`), ale dávkový přepočet volá až obalující smyčka
 * (`importInvoices()`) po celém běhu (viz {@see \MyInvoice\Service\Stats\StatsRecomputer::recomputeMany()}
 * — dávkově, ne po každém dokladu). Důkazem je SKUTEČNÉ volání (`->recompute…(`), ne jen
 * import typu — použití `StatsRecomputer` bez zavolání by test obešlo naprázdno.
 */
#[Group('architecture')]
final class InvoiceCreationStatsRecomputeCoverageTest extends TestCase
{
    private const NEEDLE_RAW_INSERT = 'INSERT INTO invoices';
    private const NEEDLE_CREATE_DRAFT = '$this->invoices->createDraft(';

    /** Důkaz, že metoda (nebo aspoň soubor) skutečně VOLÁ přepočet cache, ne jen zná typ. */
    private const EVIDENCE = [
        '->recomputeMany(',
        '->recomputeForInvoiceId(',
        '->recomputeForIds(',
        '->recomputeClient(',
        '->recomputeProject(',
    ];

    /**
     * Metody, kde je zápis BEZ přepočtu správně. Klíč = cesta relativní k `api/src`,
     * hodnota = mapa `jméno metody => důvod`.
     *
     * @var array<string, array<string, string>>
     */
    private const ALLOWED_WITHOUT_RECOMPUTE = [
        // Generický nízkoúrovňový zapisovač draftu — vždy zapíše status='draft', tedy
        // mimo agregaci cache (ta počítá jen issued/sent/reminded/paid). Přepočet je
        // odpovědností VOLAJÍCÍHO, který doklad dál posouvá k reálnému stavu — sama
        // metoda kontext hromadné operace (dávkování) ani cílový stav nezná.
        'Repository/InvoiceRepository.php' => [
            'createDraft' => 'vždy status=draft (mimo agregaci cache) — přepočet patří volajícímu',
        ],
    ];

    public function testEveryInvoiceCreationAllowsStatsRecompute(): void
    {
        $srcDir = self::srcDir();
        $offenders = [];

        foreach (self::phpFiles($srcDir) as $path) {
            $raw = (string) file_get_contents($path);
            if (!str_contains($raw, self::NEEDLE_RAW_INSERT) && !str_contains($raw, self::NEEDLE_CREATE_DRAFT)) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($path, strlen($srcDir) + 1));
            $exempt = self::ALLOWED_WITHOUT_RECOMPUTE[$rel] ?? [];

            foreach (self::writeSymbols($raw) as $symbol => $region) {
                if (isset($exempt[$symbol])) {
                    continue;
                }
                if (self::hasEvidence($region) || self::hasEvidence($raw)) {
                    continue;
                }
                $offenders[] = $rel . '::' . $symbol;
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Založení/zrušení vydaného dokladu bez cesty k přepočtu cache seznamu klientů:\n  %s\n\n"
                . "Bez volání MyInvoice\\Service\\Stats\\StatsRecomputer (recomputeMany/recomputeForInvoiceId/\n"
                . "recomputeForIds/recomputeClient/recomputeProject) zůstane seznam klientů (POČET FAKTUR,\n"
                . "OBRAT, POSLEDNÍ AKTIVITA) ukazovat stará čísla, dokud se ručně nespustí\n"
                . "api/bin/recompute-stats.php. Hromadná cesta ať přepočte dávkově (recomputeMany) na konci\n"
                . "operace, jednodokladová hned po vytvoření. Je-li zápis bez přepočtu správně (např. vždy\n"
                . 'zapisuje jen status=draft), přidej METODU do ALLOWED_WITHOUT_RECOMPUTE s důvodem.',
            implode("\n  ", $offenders),
        ));
    }

    /**
     * Allowlist se nesmí rozejít s kódem — stejná pojistka jako u OSS guardu.
     */
    public function testAllowlistSymbolsExist(): void
    {
        $srcDir = self::srcDir();
        $stale = [];

        foreach (self::ALLOWED_WITHOUT_RECOMPUTE as $rel => $symbols) {
            $path = $srcDir . '/' . $rel;
            if (!is_file($path)) {
                $stale[] = $rel . ' — soubor neexistuje';
                continue;
            }
            $raw = (string) file_get_contents($path);
            foreach (PhpSourceRegions::missingSymbols($raw, array_keys($symbols)) as $missing) {
                $stale[] = $rel . '::' . $missing . ' — metoda neexistuje';
            }
            foreach (array_keys($symbols) as $symbol) {
                $writeSymbols = self::writeSymbols($raw);
                if (!isset($writeSymbols[$symbol])) {
                    $stale[] = $rel . '::' . $symbol . ' — výjimka už není potřeba (metoda už nezapisuje invoices)';
                }
            }
        }

        self::assertSame([], $stale, sprintf(
            "Zastaralý záznam v ALLOWED_WITHOUT_RECOMPUTE:\n  %s\n\nSmaž ho, nebo oprav jméno metody. "
                . 'Nepotřebná výjimka kryje i budoucí regresi na téže metodě.',
            implode("\n  ", $stale),
        ));
    }

    /**
     * Metody obsahující `INSERT INTO invoices` nebo `$this->invoices->createDraft(` →
     * jejich zdrojový text.
     *
     * @return array<string, string> jméno metody => její kód
     */
    private static function writeSymbols(string $code): array
    {
        $lines = explode("\n", $code);
        $out = [];

        foreach ($lines as $index => $line) {
            if (!str_contains($line, self::NEEDLE_RAW_INSERT) && !str_contains($line, self::NEEDLE_CREATE_DRAFT)) {
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

    private static function hasEvidence(string $region): bool
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
