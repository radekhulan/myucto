<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\SourceCorpus;
use PHPUnit\Framework\TestCase;

/**
 * Přepočet cizí měny v SQL — kurz se smí použít jen s pojistkou na CZK.
 *
 * Vzorec `částka * COALESCE(IF(cur.code = 'CZK', 1, x.exchange_rate), 1)` je v kódu
 * asi na 45 místech. Tři ho měly bez CZK větve (`COALESCE(exchange_rate, 1)`), takže
 * korunový doklad se zbloudilým kurzem by se kurzem vynásobil. `PostingService`
 * i `CashJournalRepository` se proti přesně tomuhle stavu brání komentářem (audit C-4).
 *
 * Na ostrých datech byla expozice v době zavedení guardu **nulová** (0 korunových
 * dokladů s kurzem), takže nešlo o špatná čísla, ale o nekonzistenci, která na to čeká.
 * Guard ji drží zavřenou.
 *
 * Guard NEŘEŠÍ druhou, hlubší vadu: cizoměnový doklad s NULL kurzem se všude tiše
 * ocení kurzem 1 (~25× podhodnoceně). `VatLedgerService` ten stav umí označit
 * (`exchange_rate_missing`, #238) a `PostingService::docRate()` na něj padá výjimkou,
 * ale agregace ne. Viz private/checks/SSOT-REGISTR.md — úkol pro F2.
 */
final class ExchangeRateGuardTest extends TestCase
{
    /**
     * Výrazy, které kurz použijí BEZ testu na CZK. `[^)]*` schválně nepřipouští
     * vnořenou závorku — `COALESCE(IF(cur.code = 'CZK', …), 1)` tedy neoznačí.
     */
    private const BARE_RATE = '/COALESCE\(\s*[a-z_]*\.?exchange_rate\s*,\s*1\s*\)/i';

    /**
     * Soubory, kde je holý tvar v pořádku. Klíč = cesta pod api/src, hodnota = důvod.
     * Nový záznam smí přibýt jen s věcným odůvodněním.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        // Jen komentář popisující, proč se ExchangeRateApplier volá dřív.
        'Service/Invoice/PaymentTaxDocumentCreator.php' => 'výskyt je v komentáři, ne v SQL',
    ];

    public function testNoBareExchangeRateWithoutCzkGuard(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $offenders = [];

        foreach ($this->phpFiles($srcDir) as $path) {
            $rel = str_replace('\\', '/', substr($path, strlen($srcDir) + 1));
            if (isset(self::ALLOWED[$rel])) {
                continue;
            }
            $lines = explode("\n", SourceCorpus::read($path));
            foreach ($lines as $i => $line) {
                if (preg_match(self::BARE_RATE, $line) !== 1) {
                    continue;
                }
                // Komentářové řádky ven — vysvětlivka o vzorci není vzorec.
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                // Hledá se POROVNÁNÍ s 'CZK', ne pouhý výskyt řetězce — alias jako
                // `AS size_czk` by jinak řádek vyřadil a guard by mlčel.
                if (preg_match("/'CZK'/i", $line) === 1) {
                    continue;
                }
                $offenders[] = sprintf('%s:%d — %s', $rel, $i + 1, trim($line));
            }
        }

        self::assertSame([], $offenders, sprintf(
            "Kurz použitý bez pojistky na CZK — korunový doklad se zbloudilým kurzem\n"
                . "by se kurzem vynásobil. Použij COALESCE(IF(cur.code = 'CZK', 1, x.exchange_rate), 1):\n  %s",
            implode("\n  ", $offenders),
        ));
    }

    /**
     * Přiřazení literálu do `exchange_rate_source` — hledá `'exchange_rate_source' => '…'`
     * i `exchange_rate_source = '…'`. Zajímá nás jen hodnota, guard ji vytáhne do skupiny.
     */
    private const RATE_SOURCE_LITERAL = "/exchange_rate_source'?\s*(?:=>|=)\s*'([a-z_]+)'/i";

    /**
     * 'manual' u zdroje kurzu je DEPRECATED = „neznámý / historický zápis" (migrace 1303).
     * Nově ho nesmí zapsat nikdo: importy píšou 'import', formulář 'user', automatika
     * 'cnb'/'fixed'. Kdyby ho nový kód zapsal, smíchal by se s legacy daty a informace
     * o původu kurzu by se znovu ztratila — a už by nešla rekonstruovat.
     */
    public function testManualIsNeverAssignedAsExchangeRateSource(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $offenders = [];

        foreach ($this->phpFiles($srcDir) as $path) {
            $rel = str_replace('\\', '/', substr($path, strlen($srcDir) + 1));
            $lines = explode("\n", SourceCorpus::read($path));
            foreach ($lines as $i => $line) {
                if (preg_match(self::RATE_SOURCE_LITERAL, $line, $m) !== 1) {
                    continue;
                }
                if (strtolower($m[1]) !== 'manual') {
                    continue;
                }
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                $offenders[] = sprintf('%s:%d — %s', $rel, $i + 1, trim($line));
            }
        }

        self::assertSame([], $offenders, sprintf(
            "'manual' jako exchange_rate_source je DEPRECATED (migrace 1303) — znamená\n"
                . "„neznámý / historický zápis\" a nový kód ho nesmí zapsat. Použij 'import'\n"
                . "(cizí systém / doklad dodavatele), 'user' (člověk ve formuláři) nebo\n"
                . "'cnb'/'fixed' (odvozeno z data) — viz MyInvoice\\Support\\ExchangeRateSources:\n  %s",
            implode("\n  ", $offenders),
        ));
    }

    /**
     * Pojistka proti tichému guardu: regex výš musí chytat kanonický tvar přiřazení
     * a SSOT musí 'manual' dál znát jako DB default. Bez toho by guard hlídal vzorec,
     * který nikdo nepíše, a zezelenal by i nad kódem, který pravidlo porušuje.
     */
    public function testManualLiteralGuardMatchesTheCanonicalForm(): void
    {
        $ssot = dirname(__DIR__, 2) . '/src/Support/ExchangeRateSources.php';
        self::assertFileExists($ssot);
        self::assertMatchesRegularExpression(
            self::RATE_SOURCE_LITERAL,
            "exchange_rate_source' => 'manual'",
            'Regex guardu přestal chytat kanonický tvar přiřazení.',
        );
        self::assertStringContainsString(
            "const DEFAULT = 'manual'",
            SourceCorpus::read($ssot),
            'SSOT už DEFAULT nedeklaruje jako manual — aktualizuj guard i migraci 1303.',
        );
    }

    /**
     * Pojistka proti tomu, že guard zezelená kvůli vadnému regexu: kanonický tvar
     * musí v kódu existovat, jinak se hlídá vzorec, který nikdo nepoužívá.
     */
    public function testCanonicalFormIsActuallyUsed(): void
    {
        $found = 0;
        foreach ($this->phpFiles(dirname(__DIR__, 2) . '/src') as $path) {
            $found += preg_match_all(
                "/IF\(\s*[a-z_]*\.?code\s*=\s*'CZK'/i",
                SourceCorpus::read($path),
            ) ?: 0;
        }

        self::assertGreaterThan(20, $found, 'Kanonický kurzový výraz zmizel — aktualizuj guard.');
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        return SourceCorpus::files($dir);
    }
}
