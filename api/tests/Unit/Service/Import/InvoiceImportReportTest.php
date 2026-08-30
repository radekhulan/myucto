<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\Oss\OssClientContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tři čisté funkce importu, na kterých stojí vysvětlitelnost celého běhu: klasifikace
 * plnění vůči zemi odběratele, hlášky za doklad a souhrnné čítače za běh.
 *
 * Testují se bez databáze schválně. Jejich chyby jsou tiché — nesprávný klasifikační kód
 * pošle plnění do souhrnného hlášení, chybějící poznámka nechá uživatele hledat chybu
 * v přepočtených částkách a špatně sečtený souhrn ukáže u 850 dokladů nulu tam, kde má
 * být sedmnáct. Integrační test tyhle případy pokrýt neumí v rozumném počtu doklad
 * (potřeboval by pro každou kombinaci vlastní XML a vlastní stav DB).
 *
 * Metody jsou private, protože nejsou součástí veřejného rozhraní služby; volají se přes
 * reflexi nad instancí BEZ konstruktoru — žádná z nich nesahá na závislosti, čte jen své
 * argumenty. Stejný vzor (reflexe kvůli izolaci od DB) používá i `OssItemDeriverTest`.
 */
final class InvoiceImportReportTest extends TestCase
{
    private InvoiceImportService $service;

    protected function setUp(): void
    {
        $this->service = (new \ReflectionClass(InvoiceImportService::class))->newInstanceWithoutConstructor();
    }

    private function call(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod(InvoiceImportService::class, $method))
            ->invokeArgs($this->service, $args);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Země odběratele v klasifikaci plnění
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Co skutečně drží cizí daň mimo ř. 1 přiznání. Docblock importu tvrdí, že u NENULOVÉ
     * sazby je předání země bez účinku a jediný zámek je `oss_applicable`; kdyby to tvrdil
     * špatně, opravovalo by se při dalším nálezu zase to nesprávné místo.
     *
     * @return list<array{0:?string}>
     */
    public static function nonZeroRateCountries(): array
    {
        return [
            'bez země' => [null],
            'tuzemsko' => ['CZ'],
            'stát EU' => ['PL'],
            'třetí země' => ['US'],
        ];
    }

    #[DataProvider('nonZeroRateCountries')]
    public function testNonZeroRateIgnoresTheCustomerCountryEntirely(?string $country): void
    {
        self::assertSame(
            '1',
            InvoiceRepository::defaultSaleClassificationCode(23.0, false, $country, 'kg', 21.0),
            'polských 23 % dostane tuzemský kód „1" se zemí i bez ní — kód místo plnění neřeší',
        );
    }

    /**
     * § D11, druhá půlka. U NULOVÉ sazby země rozhoduje o všem: zahraniční odběratel
     * překlopí kód z prázdna na '20'/'22', a ty dva kódy plní SOUHRNNÉ HLÁŠENÍ. To se podává
     * za plnění osobě REGISTROVANÉ k dani v jiném členském státě — u spotřebitele bez DIČ
     * by vznikl řádek výkazu bez protistrany.
     */
    public function testZeroRateEuConsumerWithoutVatIdStaysOutOfTheEcSalesList(): void
    {
        $consumer = new OssClientContext('PL', true, null);

        $country = $this->call('classificationCountry', $consumer, 0.0);

        self::assertNull($country, 'B2C spotřebitel bez DIČ zemi do klasifikace nedostane');
        self::assertNull(
            InvoiceRepository::defaultSaleClassificationCode(0.0, false, $country, 'kg', 21.0),
            'kód „20" by doklad poslal do souhrnného hlášení, ačkoli odběratel DIČ nemá',
        );
    }

    /**
     * Protipól téhož pravidla: odběratel S DIČ do souhrnného hlášení PATŘÍ. Kdyby se § D11
     * „opravil" tak, že se země nepředá nikdy, zmizí z výkazu skutečná B2B plnění do JČS —
     * chyba opačným směrem, ale stejně tichá.
     *
     * @return list<array{0:string, 1:string}>
     */
    public static function ecSalesListUnits(): array
    {
        return [
            'dodání zboží' => ['kg', '20'],
            'poskytnutí služby' => ['h', '22'],
        ];
    }

    #[DataProvider('ecSalesListUnits')]
    public function testZeroRateEuBusinessWithVatIdEntersTheEcSalesList(string $unit, string $expected): void
    {
        $business = new OssClientContext('PL', true, 'PL1234567890');

        $country = $this->call('classificationCountry', $business, 0.0);

        self::assertSame('PL', $country);
        self::assertSame($expected, InvoiceRepository::defaultSaleClassificationCode(0.0, false, $country, $unit, 21.0));
    }

    /**
     * Plnění do třetí země se souhrnného hlášení netýká, takže omezení na DIČ tam nemá co
     * dělat — bez země by zůstalo úplně bez klasifikace. Povahu plnění pod zemí rozliší
     * měrná jednotka: vývoz zboží '26' (ř. 22), služba '26s' (ř. 26) — audit H-3.
     */
    public function testZeroRateOutsideEuAlwaysGetsAThirdCountryCode(): void
    {
        $country = $this->call('classificationCountry', new OssClientContext('US', false, null), 0.0);

        self::assertSame('US', $country);
        self::assertSame('26', InvoiceRepository::defaultSaleClassificationCode(0.0, false, $country, 'kg', 21.0));
        self::assertSame('26s', InvoiceRepository::defaultSaleClassificationCode(0.0, false, $country, 'h', 21.0));
        self::assertSame('26s', InvoiceRepository::defaultSaleClassificationCode(0.0, false, $country, 'ks', 21.0));
    }

    /** Neznámá země se nedomýšlí ani tady — `defaultSaleClassificationCode` má vlastní default. */
    public function testUnknownCountryIsNeverInvented(): void
    {
        self::assertNull($this->call('classificationCountry', new OssClientContext(null, false, null), 0.0));
        self::assertNull($this->call('classificationCountry', new OssClientContext(null, false, 'PL123'), 23.0));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Hlášky za celý doklad
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed> $inv
     * @return array{notes:list<string>, warnings:list<string>}
     */
    private function headerReport(array $inv): array
    {
        /** @var array{notes:list<string>, warnings:list<string>} $out */
        $out = $this->call('headerReport', $inv);

        return $out;
    }

    /**
     * § D8 na straně reportu. Kurz u HUF (a JPY) je v souboru kótovaný na 100 jednotek;
     * parser ho dělí na jednu, takže se uložené číslo LIŠÍ od toho v souboru. Bez poznámky
     * to vypadá jako chyba importu a uživatel „opraví" správný kurz na stonásobný.
     */
    public function testExchangeRateQuotedPerHundredUnitsIsExplained(): void
    {
        $report = $this->headerReport([
            'currency' => 'HUF',
            'exchange_rate' => 0.0635,
            'exchange_rate_amount' => 100,
            'items' => [],
        ]);

        self::assertSame([], $report['warnings']);
        self::assertCount(1, $report['notes']);
        self::assertStringContainsString('100 jednotek', $report['notes'][0]);
    }

    /** Kurz na jednu jednotku je běžný stav — mlčí se. */
    public function testExchangeRatePerSingleUnitIsNotReported(): void
    {
        $report = $this->headerReport([
            'currency' => 'EUR',
            'exchange_rate' => 25.0,
            'exchange_rate_amount' => 1,
            'items' => [],
        ]);

        self::assertSame([], $report['notes']);
        self::assertSame([], $report['warnings']);
    }

    /**
     * Cizoměnový doklad bez kurzu. Parser vrací nově `null` tam, kde dřív propadla `0.0`;
     * nula se v přepočtech tvářila jako platný kurz a základ daně v korunách vyšel nulový.
     */
    public function testForeignCurrencyWithoutUsableRateIsAWarning(): void
    {
        $report = $this->headerReport(['currency' => 'PLN', 'exchange_rate' => null, 'items' => []]);

        self::assertCount(1, $report['warnings']);
        self::assertStringContainsString('PLN', $report['warnings'][0]);
        self::assertStringContainsString('kurz', $report['warnings'][0]);
    }

    /** Korunový doklad kurz nepotřebuje — varování by chodilo úplně u všech dokladů. */
    public function testDomesticCurrencyWithoutRateIsSilent(): void
    {
        foreach ([['currency' => 'CZK'], []] as $inv) {
            $report = $this->headerReport($inv + ['exchange_rate' => null, 'items' => []]);
            self::assertSame([], $report['warnings'], json_encode($inv));
        }
    }

    /**
     * § D7 na straně reportu. Doklad v cenách VČETNĚ DPH se ukládá přepočtený na základ
     * daně, takže se jednotková cena v systému liší od částky na původním PDF. Bez téhle
     * věty to vypadá, že import čísla zkomolil.
     */
    public function testGrossPricedDocumentIsExplainedExactlyOnce(): void
    {
        $report = $this->headerReport([
            'currency' => 'CZK',
            'exchange_rate' => null,
            'items' => [
                ['prices_included_vat' => true],
                ['prices_included_vat' => true],
                ['prices_included_vat' => true],
            ],
        ]);

        self::assertCount(1, $report['notes'], 'u dvacetipoložkové faktury nesmí věta chodit dvacetkrát');
        self::assertStringContainsString('VČETNĚ DPH', $report['notes'][0]);
    }

    /**
     * ISDOC cesta žádný z nových klíčů neemituje. Doklad, který je nese jen zčásti nebo
     * vůbec, nesmí spadnout ani vyrobit prázdnou hlášku.
     */
    public function testIsdocShapedDocumentWithoutTheNewKeysReportsNothing(): void
    {
        $report = $this->headerReport(['currency' => 'CZK', 'exchange_rate' => 1.0]);

        self::assertSame(['notes' => [], 'warnings' => []], $report);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Souhrn za celý běh
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string,int> */
    private function emptyTotals(): array
    {
        return [
            'oss_items' => 0,
            'oss_rate_type_unknown' => 0,
            'oss_manual_review' => 0,
            'oss_credit_notes_pending_period' => 0,
            'varsymbol_substituted' => 0,
            'with_warnings' => 0,
        ];
    }

    /**
     * @param  list<array<string,mixed>> $results
     * @return array<string,int>
     */
    private function accumulateAll(array $results): array
    {
        $totals = $this->emptyTotals();
        $method = new \ReflectionMethod(InvoiceImportService::class, 'accumulate');
        foreach ($results as $result) {
            $args = [&$totals, $result];
            $method->invokeArgs($this->service, $args);
        }

        return $totals;
    }

    /**
     * § D1b a § D5. Nejednoznačný řádek NENÍ OSS, ale musí být v souhrnu vidět zvlášť —
     * jinak se u 850 dokladů schová mezi zelená „vytvořeno" a nikdo se k němu nevrátí.
     * `oss_manual_review` se proto počítá odděleně od `oss_items`.
     */
    public function testManualReviewRowsAreCountedSeparatelyFromOssRows(): void
    {
        $totals = $this->accumulateAll([
            ['status' => 'created', 'oss_items' => 1, 'oss_manual_review' => 0, 'warnings' => []],
            ['status' => 'created', 'oss_items' => 0, 'oss_manual_review' => 2, 'warnings' => ['nejednoznačná sazba']],
        ]);

        self::assertSame(1, $totals['oss_items']);
        self::assertSame(2, $totals['oss_manual_review']);
        self::assertSame(1, $totals['with_warnings']);
    }

    /**
     * Dobropisy se počítají po DOKLADECH, ne po řádcích: uživatel doplňuje původní období
     * na dokladu, takže „12 dobropisů" je číslo, podle kterého si naplánuje práci.
     */
    public function testCreditNotesPendingPeriodAreCountedPerDocument(): void
    {
        $totals = $this->accumulateAll([
            ['status' => 'created', 'oss_items' => 3, 'oss_credit_note_pending_period' => 3, 'warnings' => ['x']],
            ['status' => 'created', 'oss_items' => 1, 'oss_credit_note_pending_period' => 1, 'warnings' => ['x']],
            ['status' => 'created', 'oss_items' => 1, 'oss_credit_note_pending_period' => 0, 'warnings' => []],
        ]);

        self::assertSame(5, $totals['oss_items']);
        self::assertSame(2, $totals['oss_credit_notes_pending_period']);
    }

    /**
     * U odmítnutého ani přeskočeného dokladu se nic nezapsalo, takže OSS čítače o něm
     * lhát nesmí. Kolize variabilního symbolu (§ D9) je naopak nejdůležitější právě
     * u přeskočených — tam se doklad ztrácí.
     */
    public function testOssCountersIgnoreNonCreatedDocumentsButVarsymbolAndWarningsDoNot(): void
    {
        $totals = $this->accumulateAll([
            [
                'status' => 'skipped',
                'oss_items' => 9,
                'oss_manual_review' => 9,
                'varsymbol_substituted' => true,
                'warnings' => ['variabilní symbol jsme dosadili, NEMUSÍ jít o duplicitu'],
            ],
            ['status' => 'failed', 'oss_items' => 4, 'varsymbol_substituted' => true, 'warnings' => []],
        ]);

        self::assertSame(0, $totals['oss_items'], 'u nevytvořeného dokladu se žádný OSS řádek nezapsal');
        self::assertSame(0, $totals['oss_manual_review']);
        self::assertSame(2, $totals['varsymbol_substituted']);
        self::assertSame(1, $totals['with_warnings']);
    }

    /** Doklad rozstřelený před plánováním řádků nemá ani jeden z klíčů — souhrn to snese. */
    public function testDegradedResultWithoutAnyReportKeysIsSafe(): void
    {
        $totals = $this->accumulateAll([
            ['file' => 'rozbite.xml', 'status' => 'failed', 'reason' => 'Kořenový element není dataPack.'],
        ]);

        self::assertSame($this->emptyTotals(), $totals);
    }
}
