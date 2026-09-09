<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Support\Sql\PayablePredicate;
use MyInvoice\Tests\Support\PhpSourceRegions;
use MyInvoice\Tests\Support\SourceCorpus;
use PHPUnit\Framework\TestCase;

/**
 * B-13: DDKP (§ 28 ZDPH) nesmí nikde vystupovat jako nezaplacený závazek.
 *
 * Daňový doklad k poskytnuté záloze závazek na 321 nemá — peníze odešly už na
 * zálohové faktuře a doklad účtuje jen odpočet DPH (343/314). Jeho `amount_to_pay`
 * je ale GENERATED sloupec `total_with_vat − advance_paid_amount`, takže nese plné
 * brutto už zaplacené zálohy. Ve stavu `received`/`booked` se tedy bez filtru vykáže
 * jako závazek v plné výši, který nikdo nedluží.
 *
 * Nález nebyl v jednom dotazu, ale v DEVÍTI (7 + 2). Bodová oprava by tedy nic
 * nezaručila a bodový test taky ne — regrese vznikne přidáním DESÁTÉHO dotazu.
 *
 * **A přesně to se stalo.** První verze guardu měla pevný seznam DVOU souborů
 * (`PurchaseSummaryAction`, `SummaryAction`), takže čtyři závazkové dotazy
 * v `CrmAggregationService` neviděla vůbec — aging, cashflow predikce a dvě větve
 * „Zaplať dodavatelům". Guard tvrdil, že pokrývá celou třídu, a kontroloval dva
 * soubory z devíti. Proto dnes skenuje CELÝ `src/` a výjimka se uděluje pojmenované
 * metodě, ne souboru.
 *
 * Dotaz projde, pokud DDKP vylučuje kterýmkoli z těchto způsobů:
 *   a) explicitně — {@see PayablePredicate} nebo `document_kind … <> 'tax_document'`;
 *   b) strukturálně — pozitivním filtrem na jiné druhy (`document_kind = 'invoice'`,
 *      `document_kind IN ('invoice','advance')`), kam se DDKP nevejde.
 *
 * Záloha ('advance') se ZÁMĚRNĚ nevylučuje — nezaplacená zálohová faktura je reálný
 * závazek. Tenhle rozdíl proti nákladovému predikátu hlídá {@see AdvanceCostPredicateParityTest}.
 */
final class PayablePredicateCoverageTest extends TestCase
{
    /**
     * Marker závazkového dotazu: stavy nezaplaceného dokladu BEZ `paid`.
     * Se `'paid'` v seznamu už nejde o „co dlužím", ale o „co není draft/storno"
     * (exporty, backfill, párování úhrad) — tam DDKP patřit může.
     */
    private const UNPAID_STATUS_MARKERS = [
        "status IN ('received','booked')",
        "status IN ('received', 'booked')",
        'self::UNPAID_STATUSES',
    ];

    /** Kolik řádků kolem markeru se ještě počítá jako tentýž WHERE. */
    private const WINDOW_BEFORE = 6;
    private const WINDOW_AFTER  = 6;

    /**
     * Závazkové dotazy, které DDKP vyloučit NESMÍ nebo NEMUSÍ.
     * Klíč = cesta relativní k api/src, hodnota = mapa `jméno metody => důvod`.
     *
     * Výjimka se uděluje METODĚ, nikdy souboru — viz historie v docblocku výše.
     *
     * @var array<string, array<string, string>>
     */
    private const ALLOWED_WITHOUT_PREDICATE = [
        // Filtry `unpaid_only` / `overdue` jsou uživatelská navigace nad evidencí
        // přijatých dokladů, ne součet závazku: DDKP ve stavu `received` je doklad,
        // který v evidenci fyzicky je, a schovat ho před účetní by bylo horší než ho
        // ukázat. MĚSÍČNÍ SOUČET v téže metodě DDKP vylučuje — dělá to v PHP
        // (`$excludedVatDoc`, oprava B-10), takže na SQL predikátu nezávisí.
        // Příkaz k úhradě (= kde by fantomový závazek znamenal reálné peníze) má
        // vlastní filtr v paymentCandidatesWhere (N-008).
        'Repository/PurchaseInvoiceRepository.php' => [
            'listGroupedByMonth' => 'seznam k prohlížení; měsíční součet vylučuje DDKP v PHP (B-10), platební cíl řeší paymentCandidatesWhere (N-008)',
        ],

        // UPDATE jednoho dokladu podle id (dorovnání haléřového rozdílu → status='paid').
        // Není to výběr závazků: id přichází z už existující vazby a žádná suma nevzniká.
        'Service/Accounting/Bank/BankPostingService.php' => [
            'normalizeRoundingFullPurchase' => 'UPDATE podle id, ne výběr závazků',
        ],
    ];

    public function testEveryPayableQueryExcludesTaxDocument(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $offenders = [];

        foreach ($this->phpFiles($srcDir) as $path) {
            $code = SourceCorpus::read($path);
            if (!str_contains($code, 'purchase_invoices')) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($path, strlen($srcDir) + 1));
            $allowed = self::ALLOWED_WITHOUT_PREDICATE[$rel] ?? [];
            $lines = explode("\n", $code);

            foreach ($lines as $i => $line) {
                if (!$this->isUnpaidStatusMarker($line)) {
                    continue;
                }
                $symbol = PhpSourceRegions::symbolAtLine($code, $i + 1) ?? '(mimo symbol)';
                if (isset($allowed[$symbol])) {
                    continue;
                }
                if ($this->guardedByDocumentKind($lines, $i)) {
                    continue;
                }
                $offenders[] = sprintf('%s:%d (%s) — %s', $rel, $i + 1, $symbol, trim($line));
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Závazkový dotaz bez vyloučení DDKP (fantomový závazek v plné výši zálohy):\n  %s\n\n"
                . "Přidej PayablePredicate::excludeAdvanceVatDocument(), nebo (jde-li o dotaz,\n"
                . "kam DDKP patří) zapiš DANOU METODU do ALLOWED_WITHOUT_PREDICATE s důvodem.\n"
                . 'Výjimku nikdy neuděluj celému souboru — tím se vypne kontrola i pro dotazy, '
                . 'které s ní nesouvisí.',
            implode("\n  ", $offenders),
        ));
    }

    /**
     * Guard musí mít co hlídat. Kdyby se marker přejmenoval, sken by našel nula míst
     * a zůstal zelený — tvrdil by, že je hlídané něco, co hlídané není.
     */
    public function testMarkersStillMatchRealQueries(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $found = 0;
        foreach ($this->phpFiles($srcDir) as $path) {
            foreach (explode("\n", SourceCorpus::read($path)) as $line) {
                if ($this->isUnpaidStatusMarker($line)) {
                    $found++;
                }
            }
        }

        self::assertGreaterThanOrEqual(10, $found, sprintf(
            'Sken našel jen %d závazkových dotazů — markery se rozešly s kódem a guard '
                . 'by tiše nekontroloval nic. Aktualizuj UNPAID_STATUS_MARKERS.',
            $found,
        ));
    }

    /**
     * Výjimky se nesmí rozejít s kódem — záznam na přejmenovanou metodu nic nekryje,
     * ale tváří se, že ano.
     */
    public function testAllowlistSymbolsExist(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $stale = [];

        foreach (self::ALLOWED_WITHOUT_PREDICATE as $rel => $symbols) {
            $path = $srcDir . '/' . $rel;
            if (!is_file($path)) {
                $stale[] = $rel . ' — soubor neexistuje';
                continue;
            }
            foreach (PhpSourceRegions::missingSymbols(SourceCorpus::read($path), array_keys($symbols)) as $missing) {
                $stale[] = $rel . '::' . $missing . ' — metoda neexistuje';
            }
        }

        self::assertSame([], $stale, sprintf(
            "Zastaralý záznam v ALLOWED_WITHOUT_PREDICATE:\n  %s",
            implode("\n  ", $stale),
        ));
    }

    /** SSOT musí zůstat volatelný a vracet očekávaný tvar — jinak ho volající zase opíšou. */
    public function testPredicateShape(): void
    {
        self::assertSame(
            " AND COALESCE(pi.document_kind, '') <> 'tax_document'",
            PayablePredicate::excludeAdvanceVatDocument(),
        );
        self::assertSame(
            " AND COALESCE(document_kind, '') <> 'tax_document'",
            PayablePredicate::excludeAdvanceVatDocument(''),
        );
    }

    private function isUnpaidStatusMarker(string $line): bool
    {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
            return false;
        }
        if (str_contains($line, "'paid'")) {
            return false; // rozsah „není draft/storno", ne „co dlužím"
        }
        foreach (self::UNPAID_STATUS_MARKERS as $marker) {
            if (str_contains($line, $marker)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Je dotaz kolem řádku `$i` ošetřen proti DDKP — explicitně, nebo strukturálně
     * pozitivním filtrem na jiné druhy dokladu?
     *
     * @param list<string> $lines
     */
    private function guardedByDocumentKind(array $lines, int $i): bool
    {
        // Komentáře ven NEJDŘÍV, teprve pak výřez. Opačné pořadí by okno projedlo
        // vysvětlivkou: `CashDocumentService::searchUnpaid` má nad filtrem šestiřádkový
        // komentář, takže by predikát o dva řádky pod oknem propadl jako chybějící.
        // Vysvětlivku zároveň nesmíme počítat jako důkaz — predikát se v ní zmiňuje
        // jménem, takže by guard zezelenal i nad dotazem, ze kterého ho někdo smazal.
        $code = [];
        $markerPos = null;
        foreach ($lines as $idx => $line) {
            $t = ltrim($line);
            if (str_starts_with($t, '--') || str_starts_with($t, '//')
                || str_starts_with($t, '*') || str_starts_with($t, '/*')) {
                continue;
            }
            if ($idx === $i) {
                $markerPos = count($code);
            }
            $code[] = $line;
        }
        if ($markerPos === null) {
            return false;
        }

        $from = max(0, $markerPos - self::WINDOW_BEFORE);
        $window = implode("\n", array_slice($code, $from, self::WINDOW_BEFORE + self::WINDOW_AFTER + 1));

        if (str_contains($window, 'excludeAdvanceVatDocument')
            || str_contains($window, 'advanceVatDocumentCondition')
            || str_contains($window, 'payableDocKindExclude')
            || str_contains($window, "<> 'tax_document'")
            || str_contains($window, "!= 'tax_document'")) {
            return true;
        }

        // Strukturální ochrana: pozitivní filtr na druh, kam se DDKP nevejde.
        if (preg_match("/document_kind\s*=\s*'([a-z_]+)'/", $window, $m) === 1
            && $m[1] !== PayablePredicate::NON_PAYABLE_DOCUMENT_KIND) {
            return true;
        }
        if (preg_match("/document_kind\s+IN\s*\(([^)]*)\)/i", $window, $m) === 1
            && !str_contains($m[1], PayablePredicate::NON_PAYABLE_DOCUMENT_KIND)) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        return SourceCorpus::files($dir);
    }
}
