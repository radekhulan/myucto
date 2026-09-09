<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Architecture;

use MyInvoice\Tests\Support\PhpSourceRegions;
use PHPUnit\Framework\Attributes\Group;
use MyInvoice\Tests\Support\SourceCorpus;
use PHPUnit\Framework\TestCase;

/**
 * Kdo ukládá řádky přes `InvoiceRepository::replaceItems()`, musí u nich rozhodnout
 * o MÍSTĚ PLNĚNÍ — jinak je to uložení SMAŽE.
 *
 * ── Proč to není totéž, co hlídá OssItemInsertCoverageTest ──────────────────────────
 * {@see OssItemInsertCoverageTest} kontroluje kód, který `INSERT INTO invoice_items`
 * píše SÁM. Tenhle guard míří na druhou polovinu problému: volající, který vlastní SQL
 * nemá a řádky předává kanonickému seamu. Ten sloupce vyjmenované má, takže statická
 * kontrola INSERTu na něm nic nenajde — a přesto tudy uniká úplně stejná chyba.
 *
 * `replaceItems()` je totiž DELETE + INSERT nad celou sadou řádků. Položka, která
 * `oss_*` klíče v poli NEMÁ, se proto neuloží „beze změny": uloží se jako TUZEMSKÁ
 * (`oss_applicable` má DEFAULT 0). Payload integrátora bez OSS klíčů tedy OSS na
 * dokladu tiše SMAŽE, i když ho tam předtím někdo správně nastavil.
 *
 * ── Naměřený únik, kvůli kterému guard vznikl ───────────────────────────────────────
 * `POST /api/invoices` odvozený OSS doplňoval, `PUT /api/invoices/{id}` ne. Integrátor,
 * který doklad založí a pak ho PUTem opraví, dostal zpátky doklad bez OSS. Změřeno
 * testem: „uložení dokladu bez OSS klíčů zhaslo oss_applicable při sazbě 23,00 %".
 * Opraveno sdíleným traitem {@see \MyInvoice\Action\Invoice\DerivesMissingOssColumns},
 * který dnes používají obě akce.
 *
 * ── Proč to neuhlídá validace ───────────────────────────────────────────────────────
 * Guard „Zahraniční sazbu lze použít jen na řádku v režimu OSS" stojí na
 * `vat_rates.country`. U zákazníka, kterému se to stalo, jsou cizí sazby vedené pod
 * zemí CZ, takže validace mlčí — ověřeno testem, který PROŠEL nad payloadem bez OSS
 * klíčů. Runtime kontrola tuhle třídu chyb nezachytí ani teoreticky.
 *
 * Výjimka se uděluje POJMENOVANÉ METODĚ, ne souboru (allowlist na úrovni souboru vypne
 * kontrolu i pro nesouvisející kód), a KAŽDÁ musí mít důvod — jinak je whitelist jen
 * způsob, jak guard umlčet. Že výjimka pořád odpovídá kódu, hlídá
 * {@see testAllowlistEntriesAreStillNeeded()}.
 */
#[Group('architecture')]
final class OssReplaceItemsSeamTest extends TestCase
{
    /**
     * Důkaz, že volající o místě plnění rozhodl. Buď klíč sám dosazuje, nebo se zeptal
     * sdílené autority.
     *
     * Pozor na to, co v seznamu ZÁMĚRNĚ NENÍ: `domesticCountry()` ani `clientContext()`.
     * Ptají se sice téhož objektu, ale o řádku nerozhodují — a přesně na tomhle rozdílu
     * stál únik v `UpdateInvoiceAction`: deriver injektovaný měl, používal ho ale jen na
     * zemi dodavatele kvůli kontrole soudržnosti dokladu.
     *
     * @see \MyInvoice\Service\Oss\OssItemPlanner::planIssuedItem()
     * @see \MyInvoice\Service\Oss\OssItemDeriver::derive()
     * @see \MyInvoice\Action\Invoice\DerivesMissingOssColumns::deriveMissingOssColumns()
     */
    private const EVIDENCE_PATTERN = '/\boss_applicable\b|->(?:planIssuedItems?|derive|deriveMissingOssColumns|ossColumnsFor)\s*\(/';

    /**
     * Metody, kde je uložení bez OSS derivace správně.
     * Klíč = cesta relativní k `api/src`, hodnota = mapa `jméno metody => důvod`.
     *
     * @var array<string, array<string, string>>
     */
    private const ALLOWED_WITHOUT_OSS = [
        // Penalizační faktura (§ 1970 OZ / NV č. 351/2013 Sb.): jediná položka je zákonný
        // úrok z prodlení. Ten NENÍ plnění — je mimo předmět DPH a sazba se dosazuje
        // natvrdo z `zeroVatRateId()`. OSS je režim pro dodání zboží nebo služby
        // spotřebiteli v jiném členském státě; úrok z prodlení jím být nemůže ani
        // u zahraničního odběratele. Řádek navíc nevzniká z payloadu, ale ze služby
        // samotné, takže tu není co „nedoplnit".
        'Service/Penalty/PenaltyInvoiceService.php' => [
            'create' => 'úrok z prodlení je mimo předmět DPH, OSS plnění to nikdy není',
        ],
    ];

    public function testEveryReplaceItemsCallerDecidesThePlaceOfSupply(): void
    {
        $seen = 0;
        $offenders = $this->offenders($seen);

        // Pojistka proti tiché nule: kdyby se vzor volání změnil (jiné jméno metody, jiná
        // vrstva, jiný typ repozitáře), scanner by nenašel NIC a guard by svítil zeleně,
        // aniž by kontroloval cokoli. Zelená bez tohohle kroku není důkaz (AGENTS.md).
        self::assertGreaterThanOrEqual(
            5,
            $seen,
            'Scanner nenašel volání InvoiceRepository::replaceItems() — guard nic nehlídá, oprav detekci.',
        );

        self::assertSame([], $offenders, sprintf(
            "Uložení řádků přes replaceItems() bez rozhodnutí o místě plnění:\n  %s\n\n"
                . "Seam je DELETE + INSERT, takže položka bez `oss_*` klíčů se NEZACHOVÁ —\n"
                . "uloží se jako TUZEMSKÁ a cizí daň skončí na ř. 1 přiznání.\n"
                . "Doplň derivaci (trait MyInvoice\\Action\\Invoice\\DerivesMissingOssColumns nebo\n"
                . 'OssItemPlanner), nebo přidej METODU s důvodem do ALLOWED_WITHOUT_OSS.',
            implode("\n  ", $offenders),
        ));
    }

    /**
     * Výjimka na přejmenovanou nebo už opravenou metodu nevyjímá nic, ale tváří se, že
     * něco kryje — a příště podle ní někdo usoudí, že uložení bez derivace je na tom
     * místě v pořádku.
     */
    public function testAllowlistEntriesAreStillNeeded(): void
    {
        $seen = 0;
        $offenders = $this->offenders($seen, applyAllowlist: false);
        $stale = [];

        foreach (self::ALLOWED_WITHOUT_OSS as $rel => $symbols) {
            $path = self::srcDir() . '/' . $rel;
            if (!is_file($path)) {
                $stale[] = $rel . ' — soubor neexistuje';
                continue;
            }
            $raw = SourceCorpus::read($path);
            foreach (PhpSourceRegions::missingSymbols($raw, array_keys($symbols)) as $missing) {
                $stale[] = $rel . '::' . $missing . ' — metoda neexistuje';
            }
            foreach ($symbols as $symbol => $reason) {
                if (strlen(trim($reason)) < 20) {
                    $stale[] = $rel . '::' . $symbol . ' — chybí vysvětlený důvod';
                }
                if (!in_array($rel . '::' . $symbol, $offenders, true)) {
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
     * Metody, které volají `replaceItems()` na `InvoiceRepository` a o OSS nerozhodují.
     *
     * Vlastníkem kontroly je TYP, ne jméno property: seznam property se čte z deklarací
     * (`InvoiceRepository $x`), takže guard přežije přejmenování. Přijatá strana
     * (`PurchaseInvoiceRepository`, `RecurringTemplateRepository`) sem nepatří — OSS je
     * režim pro plnění, které POSKYTUJEME, na vstupu neexistuje.
     *
     * Důkaz se hledá per SOUBOR, ne per metoda: derivace bydlí ve vlastní metodě (dnes
     * ve sdíleném traitu), zatímco `replaceItems()` volá `__invoke()`. Výjimky zůstávají
     * per metoda — udělují se symbolu, ne celému souboru.
     *
     * @param  int          $seen out: kolik volání seamu scanner vůbec našel
     * @return list<string> "cesta/Soubor.php::metoda"
     */
    private function offenders(int &$seen = 0, bool $applyAllowlist = true): array
    {
        $srcDir = self::srcDir();
        $seen = 0;
        $out = [];

        foreach (self::phpFiles($srcDir) as $path) {
            $rel = str_replace('\\', '/', substr($path, strlen($srcDir) + 1));
            if ($rel === 'Repository/InvoiceRepository.php') {
                continue; // sám seam; jeho zápis hlídá OssItemInsertCoverageTest
            }
            $raw = SourceCorpus::read($path);
            if (!str_contains($raw, '->replaceItems(')) {
                continue;
            }
            $code = self::stripComments($raw);

            // `PurchaseInvoiceRepository` se nechytí (před jménem stojí písmeno),
            // plně kvalifikovaný zápis `\MyInvoice\Repository\InvoiceRepository` ano.
            if (preg_match_all('/(?<![\w])InvoiceRepository\s+\$(\w+)/', $code, $m) < 1) {
                continue;
            }
            $callPattern = '/->(?:' . implode('|', array_map(
                static fn (string $p): string => preg_quote($p, '/'),
                array_unique($m[1]),
            )) . ')->replaceItems\s*\(/';

            $fileDecidesOss = preg_match(self::EVIDENCE_PATTERN, $code) === 1;
            $exempt = self::ALLOWED_WITHOUT_OSS[$rel] ?? [];

            foreach (self::methodBodies($code) as $name => $body) {
                if (preg_match($callPattern, $body) !== 1) {
                    continue;
                }
                $seen++;
                if ($applyAllowlist && isset($exempt[$name])) {
                    continue;
                }
                if ($fileDecidesOss) {
                    continue;
                }
                $out[] = $rel . '::' . $name;
            }
        }

        return $out;
    }

    /**
     * Odstraní komentáře — docblock, který OSS jen POPISUJE (`{@see OssItemPlanner}`),
     * nesmí projít za důkaz. Přesně tahle záměna (guard hledal řetězec vyskytující se
     * i v komentáři) tady už jednou vyrobila kontrolu, která nekontrolovala nic.
     * Newlines z komentářů zůstávají, ať sedí čísla řádků.
     */
    private static function stripComments(string $code): string
    {
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                [$id, $text] = $token;
                $out .= ($id === T_COMMENT || $id === T_DOC_COMMENT)
                    ? str_repeat("\n", substr_count($text, "\n"))
                    : $text;
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /**
     * Těla metod přes `PhpSourceRegions` (tokenizer) — regex by na vnořených složených
     * závorkách a na závorkách uvnitř řetězců selhal.
     *
     * @return array<string, string> jméno metody => její kód
     */
    private static function methodBodies(string $code): array
    {
        $lines = explode("\n", $code);
        $out = [];
        foreach (PhpSourceRegions::symbols($code) as $sym) {
            $out[$sym['name']] = implode(
                "\n",
                array_slice($lines, $sym['startLine'] - 1, $sym['endLine'] - $sym['startLine'] + 1),
            );
        }

        return $out;
    }

    private static function srcDir(): string
    {
        return dirname(__DIR__, 2) . '/src';
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        return SourceCorpus::files($dir);
    }
}
