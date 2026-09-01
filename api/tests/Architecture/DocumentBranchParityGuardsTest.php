<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\PhpSourceRegions;
use PHPUnit\Framework\TestCase;

/**
 * Parity guard mezi vydanou a přijatou větví dokladů.
 *
 * Systém vede dvě zrcadlové domény — `invoices.invoice_type` a
 * `purchase_invoices.document_kind`. Opakovaně vznikla chyba, kdy se pravidlo
 * implementovalo jen na jedné z nich:
 *
 *   - #200 / 23f4dfef — oprava rozdělení jména do EPO VetaP dopadla na DPH a KH,
 *     ale ne na souhrnné hlášení;
 *   - JournalIntegrityService:341 — výjimka „u tax_document je očekávaná částka
 *     total_vat" byla jen pro vydané faktury, přijaté DDKP hlásilo falešný nález.
 *
 * `AGENTS.md` to pravidlo obsahuje slovně už dlouho („Kontroluj symetrii filtrů:
 * obě strany evidence proti všem typům dokladů") a stejně bylo dvakrát porušeno —
 * proto tento spustitelný guard.
 *
 * Hlídá se SPECIÁLNÍ PŘÍPAD (`= 'tax_document'`, `<> 'tax_document'`, `=== …`),
 * ne členství v seznamu (`IN ('invoice','credit_note','tax_document')`) — to je
 * běžný filtr rozsahu, ne zrcadlené pravidlo.
 *
 * **Granularita výjimek je součástí návrhu guardu.** Do fáze F2 byl allowlist na
 * úrovni SOUBORU: jedna legitimní asymetrie v `CrmAggregationService` vyjmula ze
 * skenu celý soubor o 2 700 řádcích — a přesně v něm pak seděly dva nehlídané
 * závazkové dotazy. Guard tvrdil, že hlídá, a nekontroloval nic. Výjimka se proto
 * uděluje pojmenovanému symbolu (metodě/konstantě), zbytek souboru se skenuje dál.
 *
 * Guard je záměrně statický (čte zdrojáky). Degraduje při reformátování; pro
 * skutečné behaviorální pokrytí viz JournalIntegrityServiceTest a PostingService testy.
 */
final class DocumentBranchParityGuardsTest extends TestCase
{
    /**
     * Zrcadlené hodnoty: vydaná větev ↔ přijatá větev.
     *
     * @var array<string, array{0:string, 1:string}> hodnota => [issued kind, received kind]
     */
    private const MIRRORED = [
        'tax_document' => ['tax_document', 'tax_document'],
    ];

    /**
     * Symboly, kde je jednostranné ošetření SPRÁVNĚ.
     * Klíč = cesta relativní k api/src, hodnota = mapa `jméno metody/konstanty => důvod`.
     *
     * Nový záznam sem smí přibýt jen s věcným odůvodněním — pokud důvod neumíš
     * napsat, je to nejspíš chyba, ne výjimka. Vyjímá se VÝHRADNĚ uvedený symbol;
     * zbytek souboru guard skenuje dál.
     *
     * @var array<string, array<string, string>>
     */
    private const ALLOWED_ONE_SIDED = [
        // Vydaný DDKP JE v tržbách, přijatý DDKP NENÍ v nákladech — ověřená asymetrie,
        // ne opomenutí. Vydaný finál z proformy nese záporné odpočtové řádky § 37a
        // (FinalFromProformaCreator), takže DDKP + finál = původní základ. Přijatá
        // vyúčtovací faktura nese základ v plné výši, takže přijatý DDKP započítat nelze.
        // Vydaná větev proto tax_document drží ve whitelistu `IN (…)` (guard ho záměrně
        // nepočítá), přijatá ho explicitně vylučuje.
        'Action/Client/GetClientAction.php' => [
            '__invoke' => 'vydaný DDKP v tržbách × přijatý mimo náklady (§ 37a)',
        ],
        'Repository/TaxProfileRepository.php' => [
            'monthExpenses' => 'vydaný DDKP v tržbách × přijatý mimo náklady (§ 37a)',
        ],
        'Service/Crm/CrmAggregationService.php' => [
            'advanceCostExclude' => 'REV_TYPES drží vydaný DDKP v tržbách, tenhle helper přijatý vylučuje (§ 37a)',
        ],

        // DDKP není platební cíl na ANI JEDNÉ větvi, ale brání se to jinak: přijatý
        // explicitním filtrem, vydaný strukturálně — vydaný DDKP je vždy status='paid'
        // (sdílí platební řádek proformy přes invoice_payments.tax_document_invoice_id),
        // takže ho InvoicePaymentService::PAYABLE_TYPES ani nenabídne.
        'Service/Bank/StatementMatcher.php' => [
            'matchPurchase'             => 'vydaný DDKP je vždy paid → nikdy platební cíl',
            'matchPurchaseFuzzy'        => 'vydaný DDKP je vždy paid → nikdy platební cíl',
        ],
        'Service/Accounting/Bank/BankPostingService.php' => [
            'outgoingCounterAccount' => 'vydaný DDKP je vždy paid → protiúčet nedosažitelný',
        ],
        // Totéž v pokladně: přijatý DDKP se vylučuje explicitně (našeptávač + guard
        // ddkp_not_payable), vydaný strukturálně — InvoicePaymentService::PAYABLE_TYPES
        // ho neobsahuje a jeho amount_to_pay je vždy 0, takže ho picker nenabídne.
        'Service/Accounting/Cash/CashDocumentService.php' => [
            'searchUnpaid' => 'vydaný DDKP kryje PAYABLE_TYPES + amount_to_pay = 0',
        ],
        // Hotovostní vyrovnání z editoru faktury (migrace 1327) — táž asymetrie:
        // přijatý DDKP navázaný na zálohu se vylučuje explicitně, vydaný je vždy
        // uhrazený (paid_total == amount_to_pay), takže na něm nezbývá co inkasovat
        // a vyrovnání ho odmítne obecným 'nothing_to_settle'.
        'Service/Accounting/Cash/CashSettlementService.php' => [
            'purchaseBlockReason' => 'vydaný DDKP je vždy uhrazený → nezbývá co inkasovat',
        ],

        // Povinnost přiznat daň z přijaté úplaty (§ 20a/21) má DODAVATEL — chybějící
        // vydaný DDKP je riziko doměrku a kontroluje se. U poskytnuté zálohy nám chybějící
        // DDKP jen odpírá odpočet; doklad je dodavatelův a vynutit ho nelze.
        'Service/Report/VatCrossCheckService.php' => [
            'checkDraftAdvanceTaxDocuments' => 'kontrola chybějícího DDKP dává smysl jen na vydané větvi',
        ],

        // Saldo 324 vs. 314: vydaná větev čerpání zálohy hledá obecným
        // `child.parent_invoice_id IS NOT NULL`, které chytí DDKP i vyúčtovací fakturu
        // najednou. Přijatá to takhle udělat NEMŮŽE — vyúčtovací faktura se váže přes
        // advance_purchase_invoice_id (UNIQUE index), DDKP přes parent_purchase_invoice_id,
        // takže musí obě cesty vyjmenovat a DDKP odlišit podle document_kind.
        'Repository/SaldoRepository.php' => [
            'fetchPaidAdvances' => 'vydaná větev pokrývá DDKP obecným parent_invoice_id IS NOT NULL',
        ],
    ];

    public function testTaxDocumentExceptionIsMirroredOnBothBranches(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $offenders = [];

        foreach ($this->phpFiles($srcDir) as $path) {
            $raw = (string) file_get_contents($path);
            // Zajímají jen soubory, které se dotýkají OBOU domén — jinak jednostrannost
            // nic neznamená (soubor o vydaných fakturách přijaté neřeší).
            if (!str_contains($raw, 'invoice_type') || !str_contains($raw, 'document_kind')) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($path, strlen($srcDir) + 1));

            // Vyjme se JEN povolený symbol, ne celý soubor.
            $exempt = array_keys(self::ALLOWED_ONE_SIDED[$rel] ?? []);
            $code = PhpSourceRegions::withoutSymbols($raw, $exempt);

            foreach (self::MIRRORED as $label => [$issuedKind, $receivedKind]) {
                $issued   = $this->specialCases($code, 'invoice_type', $issuedKind);
                $received = $this->specialCases($code, 'document_kind', $receivedKind);
                if (($issued === []) === ($received === [])) {
                    continue;
                }
                $offenders[] = sprintf(
                    '%s — %s: vydaná větev %dx, přijatá větev %dx, v %s',
                    $rel,
                    $label,
                    count($issued),
                    count($received),
                    implode(', ', $this->enclosingSymbols($raw, array_merge($issued, $received))),
                );
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Speciální případ typu dokladu je ošetřen jen na jedné větvi:\n  %s\n\n"
                . "Buď doplň zrcadlo na druhou větev, nebo (je-li jednostrannost správná)\n"
                . "přidej DANÝ SYMBOL do ALLOWED_ONE_SIDED s věcným odůvodněním.\n"
                . 'Výjimku nikdy neuděluj celému souboru — tím se vypne kontrola i pro kód, '
                . 'který s ní nesouvisí.',
            implode("\n  ", $offenders),
        ));
    }

    /**
     * Allowlist se nesmí rozejít s kódem. Záznam na přejmenovaný nebo smazaný symbol
     * nic nevyjímá, ale tváří se, že kryje výjimku — a příště podle něj někdo usoudí,
     * že asymetrie na tom místě je v pořádku.
     */
    public function testAllowlistSymbolsExist(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $stale = [];

        foreach (self::ALLOWED_ONE_SIDED as $rel => $symbols) {
            $path = $srcDir . '/' . $rel;
            if (!is_file($path)) {
                $stale[] = $rel . ' — soubor neexistuje';
                continue;
            }
            foreach (PhpSourceRegions::missingSymbols((string) file_get_contents($path), array_keys($symbols)) as $missing) {
                $stale[] = $rel . '::' . $missing . ' — symbol neexistuje';
            }
        }

        self::assertSame([], $stale, sprintf(
            "Zastaralý záznam v ALLOWED_ONE_SIDED:\n  %s\n\nSmaž ho, nebo oprav jméno symbolu.",
            implode("\n  ", $stale),
        ));
    }

    /**
     * Každá výjimka musí být NOSNÁ — bez ní by guard na tom souboru padal. Výjimka,
     * kterou už kód nepotřebuje, se tímhle sama ohlásí k odstranění; jinak by
     * v allowlistu tiše ležela a kryla i budoucí regresi na témž symbolu.
     */
    public function testEveryAllowlistEntryIsLoadBearing(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $useless = [];

        foreach (self::ALLOWED_ONE_SIDED as $rel => $symbols) {
            $path = $srcDir . '/' . $rel;
            if (!is_file($path)) {
                continue; // hlásí testAllowlistSymbolsExist
            }
            $raw = (string) file_get_contents($path);

            foreach (array_keys($symbols) as $symbol) {
                // Vyjmi VŠECHNY ostatní výjimky téhož souboru a nech tuhle jedinou působit:
                // pak se pozná, jestli právě ona rozhoduje o nerovnováze.
                $others = array_values(array_diff(array_keys($symbols), [$symbol]));
                $without = PhpSourceRegions::withoutSymbols($raw, $others);
                $with    = PhpSourceRegions::withoutSymbols($raw, array_merge($others, [$symbol]));

                $unbalanced = fn (string $c): bool =>
                    ($this->specialCases($c, 'invoice_type', 'tax_document') === [])
                    !== ($this->specialCases($c, 'document_kind', 'tax_document') === []);

                if (!$unbalanced($without) || $unbalanced($with)) {
                    $useless[] = $rel . '::' . $symbol;
                }
            }
        }

        self::assertSame([], $useless, sprintf(
            "Výjimka v ALLOWED_ONE_SIDED už není potřeba — bez ní guard neselže:\n  %s\n\n"
                . 'Smaž ji. Nepotřebná výjimka kryje i budoucí regresi na témž symbolu.',
            implode("\n  ", $useless),
        ));
    }

    /**
     * Pozice SPECIÁLNÍCH PŘÍPADŮ `<sloupec> … <operátor> '<hodnota>'` na jednom řádku.
     * Vyžadovaný operátor (`=`, `<>`, `!=`, `===`) vyřazuje `IN ('a','b','tax_document')`,
     * kde mezi sloupcem a hodnotou žádný nestojí.
     *
     * @return list<int> čísla řádků
     */
    private function specialCases(string $code, string $column, string $value): array
    {
        $re = sprintf('/%s[^\n]{0,60}?(?:=|<>|!=)[^\n]{0,20}?\'%s\'/', preg_quote($column, '/'), preg_quote($value, '/'));
        if (preg_match_all($re, $code, $m, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $lines = [];
        foreach ($m[0] as [, $offset]) {
            $lines[] = substr_count(substr($code, 0, $offset), "\n") + 1;
        }
        return $lines;
    }

    /**
     * @param list<int> $lines
     * @return list<string>
     */
    private function enclosingSymbols(string $code, array $lines): array
    {
        $names = [];
        foreach ($lines as $line) {
            $names[] = PhpSourceRegions::symbolAtLine($code, $line) ?? '(mimo symbol)';
        }
        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
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
