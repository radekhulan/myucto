<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Import\InvoiceImportService;
use MyInvoice\Service\Oss\OssLedgerService;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\VatLedgerService;
use MyInvoice\Service\Vat\VatRateResolver;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Celý řetěz importu vydané faktury — od XML po daňové výkazy — pro doklady v režimu OSS.
 *
 * Regresní pojistka proti nejhorší chybě, jakou tahle vlna opravuje: naimportovaný
 * zahraniční B2C doklad se vykázal v ČESKÉM přiznání k DPH na ř. 1 jako česká daň
 * na výstupu. Sešly se na tom čtyři nezávislé vady, každá sama o sobě tichá:
 *
 *   - Pohoda parser ignoroval `<inv:percentVAT>` a `historyHigh` mapoval na 0 %,
 *     takže se sazba 23 % z dokladu úplně ztratila;
 *   - `matchVatRateId()` hledal NEJBLIŽŠÍ procento napříč celou tabulkou `vat_rates`
 *     bez ohledu na zemi, takže polských 23 % skončilo na české sazbě;
 *   - klasifikace se počítala BEZ země odběratele → kód '1' (tuzemsko, základní sazba);
 *   - import nezapisoval žádný OSS sloupec, takže `oss_applicable` zůstalo 0 a
 *     `VatLedgerService` řádek do přiznání pustil.
 *
 * Proto se tady netestuje jen obsah sloupců v databázi — přesně ty vypadaly nevinně.
 * Rozhodující tvrzení jde přes SKUTEČNÝ {@see VatLedgerService} a {@see DphPriznaniBuilder}
 * („doklad na ř. 1 není") a přes skutečný {@see OssLedgerService} („doklad je v OSS
 * podkladu v sekci PL se správným základem a daní").
 *
 * Data jsou syntetická (fiktivní firmy, rok 2096, kurzy nasazené do `exchange_rates`
 * i `ecb_exchange_rates`, ať test nesahá na feed ČNB ani ECB) a všechno běží v transakci,
 * která se v tearDown rollbackne.
 */
#[Group('integration')]
final class OssInvoiceImportTest extends TestCase
{
    use IsolatedSupplierTrait;

    /** Rok mimo dosah ostatních fixture (bootstrap 2095, OSS práh 2098/2099). */
    private const TAX_DATE    = '2096-05-15';
    private const YEAR        = 2096;
    private const MONTH       = 5;
    private const QUARTER     = 2;
    private const PERIOD_FROM = '2096-05-01';
    private const PERIOD_TO   = '2096-05-31';

    /** Poslední den kvartálu Q2/2096 — rozhodný den kurzu ECB pro celé OSS podání. */
    private const ECB_RATE_DATE = '2096-06-30';

    /** Kurzy ČNB k DUZP — 1 PLN = 6 Kč, 1 EUR = 25 Kč, tedy PLN→EUR přesně 0,24. */
    private const PLN_CZK = 6.0;
    private const EUR_CZK = 25.0;

    private const SUPPLIER_IC = '12345678';
    private const PL_CONSUMER = 'Testowy Odbiorca sp. z o.o.';
    private const CZ_CUSTOMER = 'Testovací odběratel s.r.o.';
    /** Spotřebitel ze státu, jehož základní sazba je shodná s českou (21 %). */
    private const NL_CONSUMER = 'Testverbruiker B.V.';

    private Connection $db;
    private InvoiceImportService $import;
    private InvoiceRepository $invoices;
    private OssLedgerService $oss;
    private VatLedgerService $vatLedger;
    private DphPriznaniBuilder $dph;
    private VatRateResolver $vatRates;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $czkId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db        = $c->get(Connection::class);
            $this->import    = $c->get(InvoiceImportService::class);
            $this->invoices  = $c->get(InvoiceRepository::class);
            $this->oss       = $c->get(OssLedgerService::class);
            $this->vatLedger = $c->get(VatLedgerService::class);
            $this->dph       = $c->get(DphPriznaniBuilder::class);
            $this->vatRates  = $c->get(VatRateResolver::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier / users).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);

        // Klon dědí VŠECHNY sloupce zdrojového dodavatele včetně `oss_enabled`. Zdroj
        // v testovací databázi sdílí víc sad a jeho stav se mění, takže výchozí bod
        // nastavuje test sám: OSS je vypnuté a kdo ho potřebuje, volá enableOss().
        $this->disableOss();

        // Izolovaný dodavatel je klon jen řádku `supplier` — měny jsou per tenant,
        // takže si je test musí založit sám (import bez nich měnu dokladu neuloží).
        $this->czkId = $this->currency('CZK', 'Kč', 'Koruna česká', 'Czech koruna', isDefault: true);
        $this->currency('PLN', 'zł', 'Polský zlotý', 'Polish zloty');

        // IČO dodavatele musí sedět s `ico` v XML, jinak import doklad odmítne
        // ještě před derivací (detectRoute). Klon ho dědí z bootstrap normalizace.
        $pdo->prepare('UPDATE supplier SET ic = ? WHERE id = ?')->execute([self::SUPPLIER_IC, $this->supplierId]);

        // Kurzy do cache ČNB klienta — bez nich by přepočet do měny podání sahal
        // na síť a test by na fiktivním roce 2096 selhal nebo se stal nedeterministickým.
        $this->exchangeRate('PLN', self::PLN_CZK);
        $this->exchangeRate('EUR', self::EUR_CZK);
        $this->ecbPeriodEndRates();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    /**
     * HLAVNÍ REGRESE — polský B2C doklad se sazbou 23 % musí skončit v OSS podkladu
     * a NIKDY v českém přiznání k DPH.
     */
    public function testForeignB2cInvoiceGoesToOssAndNeverToCzechVatReturn(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $client = $this->client(self::PL_CONSUMER, 'PL');
        $this->client(self::CZ_CUSTOMER, 'CZ', ic: '25596641');

        $result = $this->importOne('oss-pl.xml', $this->pohodaForeignB2c('26OSS0001'));

        // Kontrolní tuzemský doklad ve STEJNÉM období. Bez něj by tvrzení „polský doklad
        // v přiznání není" prošlo i tehdy, kdyby výkaz nevracel vůbec nic (špatný rozsah
        // období, jiný dodavatel) — tedy klasická falešná zelená.
        $control = $this->importOne('tuzemsko.xml', $this->pohodaDomestic('26FV0001'));
        self::assertSame('created', $control['status'], (string) ($control['reason'] ?? ''));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(1, $result['oss_items'], 'Doklad musí mít právě jeden OSS řádek.');
        $invoiceId = (int) $result['invoice_id'];
        self::assertSame($client, (int) $result['client_id'], 'Klient se má napárovat, ne založit nový.');

        // ── 1) Řádek nese OSS parametry a SKUTEČNOU sazbu 23 % ──────────────────
        $item = $this->itemRow($invoiceId);
        self::assertSame(1, (int) $item['oss_applicable']);
        self::assertSame('PL', (string) $item['oss_consumer_country']);
        self::assertSame('standard', (string) $item['oss_rate_type'], 'Typ sazby z číselníku členských států.');
        self::assertSame('goods', (string) $item['oss_supply_type'], 'Jednotka „kg" je signál zboží.');
        self::assertEqualsWithDelta(23.0, (float) $item['vat_rate_snapshot'], 0.001,
            'Snapshot musí být 23 % — ne 0 % (ztracený percentVAT), ani 21 % (česká přihrádka „high").');
        self::assertNull($item['vat_classification_code'],
            'OSS řádek nesmí nést tuzemský klasifikační kód — po zhasnutí oss_applicable by skočil na ř. 1.');

        // Sazba se musí párovat v zemi SPOTŘEBY, ne „nejbližším procentem" napříč tabulkou.
        self::assertSame('PL', (string) $item['rate_country'],
            'vat_rate_id ukazuje na sazbu jiné země — přesně nález A-4.');
        self::assertEqualsWithDelta(23.0, (float) $item['rate_percent'], 0.001);

        // ── 2) Doklad NENÍ v české evidenci DPH, kontrolní tuzemský ANO ─────────
        $saleIds = $this->saleInvoiceIdsInVatLedger();
        self::assertContains((int) $control['invoice_id'], $saleIds,
            'Kontrolní tuzemský doklad v evidenci chybí — pak netvrdí nic ani zbytek téhle sekce.');
        self::assertNotContains($invoiceId, $saleIds,
            'OSS doklad prošel do kanonické evidence DPH — odtud vede přímá cesta na ř. 1 přiznání i do KH.');

        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        $veta1 = (new \SimpleXMLElement($dph['xml']))->DPHDP3->Veta1;
        self::assertArrayHasKey('1', $dph['summary']['lines'], 'Kontrolní tuzemský doklad na ř. 1 chybí.');
        self::assertEqualsWithDelta(1000.0, $dph['summary']['lines']['1']['base'], 0.01,
            'Na ř. 1 patří jen tuzemských 1 000 Kč. 7 000 Kč znamená, že se přidal polský doklad '
                . '(1 000 PLN kurzem 6 Kč) — přesně chyba, kvůli které tenhle test existuje.');
        self::assertSame('1000', (string) $veta1['obrat23']);

        // ── 3) Doklad JE v OSS podkladu, sekce PL, přepočtený do EUR ────────────
        $preview = $this->oss->preview($this->supplierId, self::YEAR, self::QUARTER);
        $pl = $this->ossCountry($preview, 'PL');

        self::assertEqualsWithDelta(240.0, $pl['base'], 0.01, 'Základ 1 000 PLN kurzem 0,24 = 240 EUR.');
        self::assertEqualsWithDelta(55.2, $pl['vat'], 0.01, 'Daň 230 PLN kurzem 0,24 = 55,20 EUR.');
        self::assertSame(1, $preview['summary']['invoice_count']);
        self::assertEqualsWithDelta(55.2, $preview['summary']['total_payable'], 0.01);
        self::assertCount(1, $pl['rates']);
        self::assertEqualsWithDelta(23.0, $pl['rates'][0]['rate'], 0.001);
        self::assertSame('standard', $pl['rates'][0]['rate_type']);
        self::assertStringNotContainsString('nelze ověřit', implode("\n", $preview['warnings']),
            'PL 23 % číselník zná — hláška „nelze ověřit" znamená neproběhlou migraci 1152 (nález 4.3).');

        // ── 4) Report importu o zařazení do OSS mlčet nesmí ─────────────────────
        self::assertStringContainsString('Řádek zařazen do OSS (stát spotřeby PL',
            implode("\n", $result['notes']));
        self::assertSame(0, $result['oss_rate_type_unknown'], 'PL 23 % číselník potvrdil.');
        self::assertSame(0, $result['oss_manual_review']);
        self::assertSame(0, $result['oss_credit_note_pending_period']);
    }

    /**
     * ZÁKAZNÍKOVA KONFIGURACE, na které selhaly obě předchozí vlny — celý řetěz od XML
     * po výkazy.
     *
     * V globálním číselníku `vat_rates` má zákazník sazbu, jejíž KÓD říká „polská 23 %",
     * ale jejíž ZEMĚ říká CZ, protože formulář v Nastavení → Sazby DPH má CZ předvyplněnou.
     * Doklad je přitom úplný: polský spotřebitel bez DIČ, sazba 23 %, firma s platnou
     * registrací do OSS. Dokud se derivace ptala „zná ČR 23 %" nad `vat_rates`, dostala
     * ANO, řádek spadl mezi nejednoznačné, zůstal tuzemský, dostal klasifikaci '1'
     * a 1 000 PLN kurzem 6 Kč skončilo na ř. 1 českého přiznání jako česká daň na výstupu.
     *
     * Oprava nespočívá v jiné odpovědi, ale v jiném dotazovaném: tuzemskost sazby se ptá
     * VÝHRADNĚ číselníku členských států (`oss_member_state_rates`, migrace 1152), kam
     * uživatel nesahá. Ten o ČR k roku 2096 ví 21 % a 12 %, nikoli 23 %, takže je místo
     * plnění určené jednoznačně — bez příznaku k ručnímu posouzení.
     *
     * Tvrzení schválně nekončí u sloupců položky (ty vypadaly nevinně i u původní chyby),
     * ale jde přes SKUTEČNÝ {@see VatLedgerService}, {@see DphPriznaniBuilder}
     * a {@see OssLedgerService}.
     */
    public function testForeignRateStoredUnderCzInVatRatesStillGoesToOssNotToTheCzechReturn(): void
    {
        $this->enableOss();
        // Přesně to, co má zákazník v číselníku: kód „polská", země česká.
        $this->vatRate('CZ', 23.0, code: 'PL-23-vlastni');
        // A vedle toho správně vedená polská sazba, na kterou se OSS řádek napáruje.
        $this->vatRate('PL', 23.0);
        $this->client(self::PL_CONSUMER, 'PL');
        $this->client(self::CZ_CUSTOMER, 'CZ', ic: '25596641');

        // Předpoklad, bez kterého by zelená níže nedokazovala nic: tuzemské párování
        // sazby by tenhle řádek SKUTEČNĚ obsloužilo. Tenhle výraz byl doslova tím, čím
        // předchozí podoba počítala „sazba je tuzemská" — test tedy padá kvůli CHOVÁNÍ
        // rozhodování, ne kvůli chybějící fixtuře.
        self::assertTrue(
            $this->vatRates->resolve('CZ', 23.0, self::TAX_DATE)->found(),
            'Fixture musí obsahovat uživatelskou 23% sazbu se zemí CZ, jinak test nehlídá '
                . 'zákazníkovu konfiguraci, ale nějakou jinou.',
        );

        $result = $this->importOne('oss-pl-vlastni-sazba.xml', $this->pohodaForeignB2c('26OSS0011'));

        // Kontrolní tuzemský doklad ve stejném období — bez něj by „polský doklad
        // v přiznání není" prošlo i nad prázdným výkazem.
        $control = $this->importOne('tuzemsko.xml', $this->pohodaDomestic('26FV0011'));
        self::assertSame('created', $control['status'], (string) ($control['reason'] ?? ''));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(1, $result['oss_items'], 'Řádek musí být OSS, ne tuzemský.');
        self::assertSame(0, $result['oss_manual_review'],
            'Číselník členských států umí odpovědět obě strany, takže tu není co posuzovat ručně — '
                . 'příznak by znamenal, že rozhodnutí zase stojí na nejednoznačnosti.');
        $invoiceId = (int) $result['invoice_id'];

        $item = $this->itemRow($invoiceId);
        self::assertSame(1, (int) $item['oss_applicable']);
        self::assertSame('PL', (string) $item['oss_consumer_country']);
        self::assertSame('standard', (string) $item['oss_rate_type']);
        self::assertNull($item['vat_classification_code'],
            'Klasifikace 1 je ta hodnota, se kterou doklad odcházel na ř. 1 přiznání.');
        self::assertSame('PL', (string) $item['rate_country'],
            'Položka se napárovala na sazbu se zemí CZ — tedy na tu, kterou si zákazník založil '
                . 'pod kódem „PL-23", a odtud vede přímá cesta do tuzemského přiznání.');
        self::assertNotSame('PL-23-vlastni', (string) $item['rate_code']);

        // ── Doklad v české evidenci DPH být nesmí, kontrolní tuzemský ano ────────
        $saleIds = $this->saleInvoiceIdsInVatLedger();
        self::assertContains((int) $control['invoice_id'], $saleIds,
            'Kontrolní tuzemský doklad v evidenci chybí — pak netvrdí nic ani zbytek testu.');
        self::assertNotContains($invoiceId, $saleIds);

        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertArrayHasKey('1', $dph['summary']['lines']);
        self::assertEqualsWithDelta(1000.0, $dph['summary']['lines']['1']['base'], 0.01,
            'Na ř. 1 patří jen tuzemských 1 000 Kč. 7 000 Kč znamená, že se přidal polský doklad '
                . '(1 000 PLN kurzem 6 Kč) — přesně chyba, kterou zákazník nahlásil.');

        // ── A naopak: v OSS podkladu doklad být MÁ, v sekci PL a přepočtený do EUR ─
        $preview = $this->oss->preview($this->supplierId, self::YEAR, self::QUARTER);
        $pl = $this->ossCountry($preview, 'PL');
        self::assertEqualsWithDelta(240.0, $pl['base'], 0.01);
        self::assertEqualsWithDelta(55.2, $pl['vat'], 0.01);
        self::assertSame('standard', $pl['rates'][0]['rate_type']);
    }

    /**
     * Souhrn za celý běh (§ D5). Frontend na něj věší přehled nad seznamem dokladů —
     * u 850 dokladů je „17 řádků čeká na typ sazby" jediné číslo, které uživatel přečte.
     *
     * Souhrn se počítá na backendu schválně: kdyby si ho frontend sečetl z `results`,
     * rozejde se s tím, co se skutečně zapsalo, jakmile se změní kterákoli větev reportu.
     */
    public function testRunSummaryCountsOssRowsAndSubstitutedVarsymbols(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $this->client(self::PL_CONSUMER, 'PL');
        $this->client(self::CZ_CUSTOMER, 'CZ', ic: '25596641');

        $out = $this->import->importBundle(
            [
                ['name' => 'oss-pl.xml', 'content' => $this->pohodaForeignB2c('26OSS0010')],
                ['name' => 'tuzemsko.xml', 'content' => $this->pohodaDomestic('26FV0010', symVar: 'not-a-vs-@@@')],
            ],
            $this->supplierId,
            $this->userId,
            'issued',
        );

        self::assertSame(2, $out['summary']['created'], implode(' | ', array_column($out['results'], 'reason')));
        self::assertSame(1, $out['summary']['oss_items']);
        self::assertSame(0, $out['summary']['oss_rate_type_unknown']);
        self::assertSame(0, $out['summary']['oss_manual_review']);
        self::assertSame(0, $out['summary']['oss_credit_notes_pending_period']);
        self::assertSame(1, $out['summary']['varsymbol_substituted'],
            'Tuzemský doklad má v symVar nepoužitelnou hodnotu, VS se dosadil z čísla dokladu.');
    }

    /**
     * § D1b — sazba, kterou zná stát spotřeby I tuzemsko, místo plnění NEURČUJE. Řádek
     * jde do OSS a označí se K RUČNÍMU POSOUZENÍ.
     *
     * Tenhle jediný doklad stojí mezi dvěma chybami, mezi kterými nejde rozhodnout
     * automaticky (NL, BE, ES, LT i LV mají 21 % shodně s ČR):
     *  - vykázat ho v ČR znamená odvést do Česka daň, která patří do Nizozemska;
     *  - vykázat ho v OSS znamená vzít ř. 1 přiznání tuzemské plnění.
     *
     * Směr úlevy určuje ASYMETRIE VIDITELNOSTI CHYBY, ne opatrnost: chybný OSS řádek se
     * objeví v náhledu OSS podání, což je krátký seznam procházený před odesláním, kdežto
     * chybný tuzemský řádek zmizí mezi stovkami řádků přiznání k DPH a najde ho až výzva
     * správce daně. Dřívější podoba rozhodovala opačně a byla to chyba.
     *
     * Příznak se proto ukládá i K POLOŽCE (`oss_needs_manual_review`, migrace 1293) —
     * kategorie žijící jen v odpovědi importu je u 1 670 dokladů po zavření stránky
     * nedohledatelná a hromadná oprava vlny 2 by neměla nad čím běžet.
     */
    public function testRateValidInBothCountriesGoesToOssAndIsFlaggedForManualReview(): void
    {
        $this->enableOss();
        // Řádek je OSS, takže se `vat_rate_id` páruje ve státě SPOTŘEBY — sazbu pro NL
        // tedy musí mít uživatel založenou stejně jako pro PL (viz test níž).
        $this->vatRate('NL', 21.0);
        $this->client(self::NL_CONSUMER, 'NL');

        $out = $this->import->importBundle(
            [['name' => 'nl-21.xml', 'content' => $this->pohodaDomestic(
                '26FV0113',
                company: self::NL_CONSUMER,
                ico: '',
                countryIso2: 'NL',
            )]],
            $this->supplierId,
            $this->userId,
            'issued',
        );
        $result = $out['results'][0];

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(1, $result['oss_items'], 'nejednoznačnost se řeší ve prospěch OSS');
        self::assertSame(1, $result['oss_manual_review']);
        self::assertStringContainsString('K RUČNÍMU POSOUZENÍ', implode("\n", $result['warnings']));

        // Souhrn za běh: `oss_manual_review` a `oss_items` se PŘEKRÝVAJÍ (týž řádek je
        // v obou), nejsou to disjunktní kategorie. Kdyby se vylučovaly, uživatel by
        // z čísel usoudil, že jde o dva různé řádky.
        self::assertSame(1, $out['summary']['oss_manual_review']);
        self::assertSame(1, $out['summary']['oss_items']);
        self::assertSame(1, $out['summary']['with_warnings']);

        $invoiceId = (int) $result['invoice_id'];
        $item = $this->itemRow($invoiceId);
        self::assertSame(1, (int) $item['oss_applicable']);
        self::assertSame('NL', (string) $item['oss_consumer_country']);
        self::assertNull($item['vat_classification_code'],
            'OSS řádek nesmí nést tuzemský kód plnění — po zhasnutí oss_applicable by skočil na ř. 1.');
        self::assertSame('NL', (string) $item['rate_country'], 'sazba se páruje ve státě spotřeby');
        self::assertSame(1, (int) $item['oss_needs_manual_review'],
            'Příznak k ručnímu posouzení musí přežít zavření reportu — jinak ho po zavření '
                . 'stránky nikdo nedohledá a hromadná oprava nemá nad čím běžet.');

        // Druhá půlka tvrzení: řádek z tuzemského přiznání SKUTEČNĚ zmizel a objevil se
        // v OSS podkladu. Bez toho by test prošel i variantě „při nejistotě řádek zahodit".
        self::assertNotContains($invoiceId, $this->saleInvoiceIdsInVatLedger());
        $preview = $this->oss->preview($this->supplierId, self::YEAR, self::QUARTER);
        $nl = $this->ossCountry($preview, 'NL');
        self::assertEqualsWithDelta(40.0, $nl['base'], 0.01,
            'Doklad je v korunách, podání v EUR — 1 000 Kč kurzem ECB 25 Kč/EUR ke konci období = 40 EUR.');
    }

    /**
     * § D1b, druhá půlka — příznak „k ručnímu posouzení" musí být DOHLEDATELNÝ V DATECH,
     * ne jen v odpovědi importu.
     *
     * Report je jednorázová stránka: po jejím zavření se u migrace 1 670 dokladů kategorie
     * „tady jsme nedokázali určit místo plnění" ztratí a hromadná oprava vlny 2 nemá nad
     * čím běžet. Test proto nečte sloupec známého řádku, ale pouští DOTAZ, kterým se ty
     * řádky hledají — a ověřuje, že najde právě ten nejednoznačný a žádný jiný.
     */
    public function testManualReviewFlagIsFindableInTheDataAfterTheReportIsClosed(): void
    {
        self::assertTrue(
            $this->db->hasColumn('invoice_items', 'oss_needs_manual_review'),
            'Chybí migrace 1293 — příznak k ručnímu posouzení nemá kam přežít zavření reportu.',
        );

        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $this->vatRate('NL', 21.0);
        $this->client(self::PL_CONSUMER, 'PL');
        $this->client(self::NL_CONSUMER, 'NL');

        $out = $this->import->importBundle(
            [
                // Jednoznačný OSS řádek: 23 % v ČR podle číselníku členských států neplatí.
                ['name' => 'oss-pl.xml', 'content' => $this->pohodaForeignB2c('26OSS0031')],
                // Nejednoznačný: 21 % platí v NL i v ČR, místo plnění z procenta neplyne.
                ['name' => 'nl-21.xml', 'content' => $this->pohodaDomestic(
                    '26FV0131',
                    company: self::NL_CONSUMER,
                    ico: '',
                    countryIso2: 'NL',
                )],
            ],
            $this->supplierId,
            $this->userId,
            'issued',
        );
        self::assertSame(2, $out['summary']['created'], implode(' | ', array_column($out['results'], 'reason')));
        self::assertSame(2, $out['summary']['oss_items'], 'Oba řádky jsou OSS — příznak je jen podmnožina.');

        $stmt = $this->db->pdo()->prepare(
            'SELECT i.varsymbol, ii.oss_applicable
               FROM invoice_items ii
               JOIN invoices i ON i.id = ii.invoice_id
              WHERE i.supplier_id = ?
                AND ii.oss_needs_manual_review = 1
           ORDER BY i.varsymbol'
        );
        $stmt->execute([$this->supplierId]);
        $flagged = $stmt->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(1, $flagged, 'Dotaz vlny 2 musí najít právě nejednoznačný řádek.');
        self::assertSame('26FV0131', (string) $flagged[0]['varsymbol']);
        self::assertSame(1, (int) $flagged[0]['oss_applicable'],
            'Označený řádek JE OSS — příznak a OSS se překrývají, nevylučují.');
    }

    /**
     * Vedlejší dopad obrácení § D1b, který si zaslouží vlastní tvrzení: nejednoznačný
     * řádek je OSS, takže se sazba páruje ve státě SPOTŘEBY. Nemá-li ji uživatel
     * v `vat_rates` založenou, odmítne se CELÝ doklad.
     *
     * Je to poctivější než dřívější tiché sednutí na CZ-21, ale u migrace se to projeví
     * hned: dokud si uživatel sazby pro státy spotřeby nezaloží, spadne mu velká část
     * dokladů. Hláška proto musí říct, PRO KTEROU ZEMI sazbu založit — a nesmí ho svést
     * zpátky k české 21 %, což je přesně ta chyba, kvůli které tahle vlna vznikla.
     */
    public function testAmbiguousRowWithoutAConsumerCountryRateRejectsTheWholeDocument(): void
    {
        $pdo = $this->db->pdo();
        $existing = (int) $pdo->query(
            "SELECT COUNT(*) FROM vat_rates
              WHERE country = 'NL' AND ABS(rate_percent - 21) <= 0.005"
        )->fetchColumn();
        if ($existing > 0) {
            self::markTestSkipped('Instalace už nizozemskou sazbu 21 % má — test by netvrdil nic.');
        }

        $this->enableOss();
        $this->client(self::NL_CONSUMER, 'NL');

        $result = $this->importOne('nl-21-bez-sazby.xml', $this->pohodaDomestic(
            '26FV0114',
            company: self::NL_CONSUMER,
            ico: '',
            countryIso2: 'NL',
        ));

        self::assertSame('failed', $result['status']);
        $reason = (string) $result['reason'];
        self::assertStringContainsString('pro zemi NL', $reason);
        self::assertStringContainsString('NL-21', $reason);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$this->supplierId, '26FV0114']);
        self::assertSame(0, (int) $stmt->fetchColumn(),
            'Odmítnutý doklad nesmí po sobě nechat hlavičku bez položek.');
    }

    /**
     * INVARIANT PROTI ÚNIKU nad ZÁKAZNÍKOVOU KONFIGURACÍ — nejtvrdší regrese téhle vlny.
     *
     * Zákazník má v globálním číselníku `vat_rates` sazbu s kódem „PL-23", ale se zemí CZ,
     * protože formulář má CZ předvyplněnou. Doklad zároveň nenese zemi odběratele, takže
     * se derivace opře o uloženého klienta — a toho `ClientResolver` zakládá s fallbackem
     * na CZ. Před opravou se tyhle dvě věci sečetly do přesně původní chyby: řádek se
     * prohlásil za tuzemský, napároval se na „PL-23 (CZ)" a s klasifikací '1' odešel na
     * ř. 1 českého přiznání jako česká daň na výstupu.
     *
     * Zámek je jediný a plošný: sazba, kterou číselník ČLENSKÝCH STÁTŮ v zemi dodavatele
     * nezná, se nikdy nevykáže jako tuzemské plnění. Buď je řádek OSS, nebo se položka
     * odmítne — a hláška musí pojmenovat, co konkrétně doplnit, ne obecné „nelze zpracovat".
     */
    public function testForeignRateOnADocumentWithoutCustomerCountryIsRejectedInsteadOfBecomingCzechTax(): void
    {
        $this->enableOss();
        // Přesně zákazníkova konfigurace: kód říká „polská", země říká „česká".
        // Dotaz „zná ČR 23 %" nad `vat_rates` by tedy vrátil ANO.
        $this->vatRate('CZ', 23.0, code: 'PL-23-vlastni');
        // Uložený klient se zemí CZ — stav po dřívějším importu bez země.
        $this->client(self::PL_CONSUMER, 'CZ');

        // Předpoklad, bez kterého by tvrzení níž netvrdilo nic: tuzemské párování sazby
        // by tenhle řádek SKUTEČNĚ obsloužilo. Dokud se derivace ptala `vat_rates`, byl
        // tenhle výraz doslova tím, čím počítala „sazba je tuzemská" — doklad by se tedy
        // naimportoval a s klasifikací '1' skončil na ř. 1.
        self::assertTrue(
            $this->vatRates->resolve('CZ', 23.0, self::TAX_DATE)->found(),
            'Bez nalezitelné české 23 % by doklad spadl na nenapárovanou sazbu a test by '
                . 'zeleně tvrdil něco jiného, než co má hlídat.',
        );

        $result = $this->importOne(
            'oss-bez-zeme.xml',
            $this->pohodaForeignB2c('26OSS0007', omitCountry: true),
        );

        self::assertSame('failed', $result['status'],
            'Řádek s cizí sazbou a bez země odběratele se nesmí prohlásit za tuzemský — '
                . 'v `vat_rates` na něj čeká uživatelem založená česká 23 % a s ní ř. 1 přiznání.');
        $reason = (string) $result['reason'];
        self::assertStringContainsString('nemůže být tuzemské plnění', $reason);
        self::assertStringContainsString('zemi odběratele', $reason,
            'Hláška musí říct, CO doplnit — obecné „nelze zpracovat" je u 1 670 dokladů k ničemu.');

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$this->supplierId, '26OSS0007']);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'Odmítnutý doklad se nesmí založit.');

        // A nakonec to, o co tu celou dobu jde: v přiznání není nic.
        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertArrayNotHasKey('1', $dph['summary']['lines']);
    }

    /**
     * § D4 — sazbu pro stát spotřeby import NEZAKLÁDÁ, ani když ji potřebuje.
     *
     * `vat_rates` je globální tabulka bez `supplier_id`, takže řádek založený z importu
     * jednoho nájemníka mění číselník celé instalaci; `uq_vat_code` navíc koliduje s kódem,
     * který si mezitím uživatel založil ručně (typicky „PL-23" se zemí CZ, protože formulář
     * má CZ předvyplněnou). Doklad se proto odmítne a hláška musí říct, PRO KTEROU ZEMI
     * sazbu založit — rada bez země uživatele dovede zpátky k české 23 %, tedy k původní
     * chybě s ř. 1.
     */
    public function testMissingConsumerCountryRateIsRejectedAndFoundsNothing(): void
    {
        $pdo = $this->db->pdo();
        // Kód „PL-23" se hlídá zvlášť od dvojice (země, procento): existuje-li pod jinou
        // zemí, dostane uživatel jinou (a rovněž správnou) hlášku a tvrzení níže by mířila
        // vedle. Obojí je legitimní stav instalace, ale test má ověřit právě jeden z nich.
        $existing = (int) $pdo->query(
            "SELECT COUNT(*) FROM vat_rates
              WHERE code = 'PL-23'
                 OR (country = 'PL' AND ABS(rate_percent - 23) <= 0.005)"
        )->fetchColumn();
        if ($existing > 0) {
            self::markTestSkipped('Instalace už polskou sazbu 23 % má — test by netvrdil nic.');
        }

        $this->enableOss();
        $this->client(self::PL_CONSUMER, 'PL');
        $before = (int) $pdo->query('SELECT COUNT(*) FROM vat_rates')->fetchColumn();

        $result = $this->importOne('oss-pl-bez-sazby.xml', $this->pohodaForeignB2c('26OSS0099'));

        self::assertSame('failed', $result['status'], 'sazba se z importu zakládat nesmí, doklad se odmítá');
        $reason = (string) $result['reason'];
        self::assertStringContainsString('pro zemi PL', $reason);
        self::assertStringContainsString('PL-23', $reason);
        self::assertStringNotContainsString('CZ-23', $reason, 'návod nesmí vést k ČESKÉ sazbě 23 %');

        self::assertSame(
            $before,
            (int) $pdo->query('SELECT COUNT(*) FROM vat_rates')->fetchColumn(),
            'import do globálního číselníku sazeb nesmí přidat ani řádek',
        );
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$this->supplierId, '26OSS0099']);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'odmítnutý doklad nesmí nechat hlavičku bez položek');
    }

    /**
     * § D3 — země Z DOKLADU přebíjí uloženého klienta.
     *
     * `ClientResolver` ukládá neznámou zemi jako 'CZ' (a `countryIdFromIso2()` na neznámé
     * ISO odpovídá rovněž Českem), takže uložený klient umí tvrdit „tuzemsko" i u dokladu
     * ze zahraničí. Kdyby derivace četla jen jeho, polských 23 % by dostalo `oss_applicable = 0`
     * a odtud vede přímá cesta na ř. 1 českého přiznání.
     */
    public function testConsumerCountryComesFromTheDocumentEvenWhenStoredClientSaysCz(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        // Uložený klient TÉHOŽ jména, ale se zemí CZ — přesně stav po dřívějším importu
        // bez země, který ClientResolver domyslel na Česko.
        $this->client(self::PL_CONSUMER, 'CZ');

        $result = $this->importOne('oss-pl-mis-country.xml', $this->pohodaForeignB2c('26OSS0003'));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(1, $result['oss_items'],
            'Země z dokladu (PL) musí přebít uloženou CZ — jinak jde polská daň do českého přiznání.');

        $item = $this->itemRow((int) $result['invoice_id']);
        self::assertSame('PL', (string) $item['oss_consumer_country']);
        self::assertNotContains((int) $result['invoice_id'], $this->saleInvoiceIdsInVatLedger());
    }

    /**
     * § D6 — OSS dobropis se v reportu ozve dvakrát: chybí mu původní OSS období (import
     * ho nedoplňuje, protože nemá odkud) a jeho částky přišly kladné.
     *
     * Bez období se oprava vykáže do BĚŽNÉHO kvartálu místo do toho, kam patří — to zůstává
     * varováním, protože období nemá import odkud vzít.
     *
     * Kladné částky se naproti tomu OTOČÍ. Není to vada dat, ale jiná konvence: řada
     * systémů exportuje opravný doklad v absolutních hodnotách a znaménko nechává na typu
     * dokladu. MyÚčto vede dobropis se záporným množstvím a kladnou jednotkovou cenou —
     * tak ho zakládá `CancelInvoiceAction` a tak ho normalizuje i AI cesta, jejíž prompt si
     * absolutní hodnoty vyžádá právě proto, že znaménko dosadí importér. Ponechat kladné
     * částky by daň místo snížení ZDVOJILO: žádný výkaz na vydané straně znaménko podle
     * `invoice_type` neotáčí.
     */
    public function testOssCreditNoteAsksForOriginalPeriodAndFlipsPositiveAmounts(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $this->client(self::PL_CONSUMER, 'PL');

        $result = $this->importOne('oss-pl-dobropis.xml', $this->pohodaForeignB2c('26OSS0004', creditNote: true));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(1, $result['oss_items']);
        self::assertSame(1, $result['oss_credit_note_pending_period']);

        $warnings = implode("\n", $result['warnings']);
        self::assertStringContainsString('PŮVODNÍHO OSS období', $warnings);
        self::assertStringContainsString('otočili jsme u položek znaménko', $warnings,
            'Přeznačkování se nesmí udělat mlčky — uživatel musí vědět, že se doklad liší od zdroje.');

        $item = $this->itemRow((int) $result['invoice_id']);
        self::assertEqualsWithDelta(-1.0, (float) $item['quantity'], 0.001,
            'Znaménko nese MNOŽSTVÍ — stejně jako u dobropisu z CancelInvoiceAction.');
        self::assertEqualsWithDelta(1000.0, (float) $item['unit_price_without_vat'], 0.01,
            'Jednotková cena zůstává kladná: obojí záporné zakazuje InvoiceAmountPolicy '
                . 'a doklad by pak nešlo v editoru uložit.');
        self::assertEqualsWithDelta(-1000.0, (float) $item['total_without_vat'], 0.01);
        self::assertEqualsWithDelta(-230.0, (float) $item['total_vat'], 0.01,
            'Dobropis musí daň SNÍŽIT — kladných 230 by ji zvýšilo, tedy chyba o dvojnásobek.');

        // OSS podklad bere částky tak, jak jsou; po otočení tedy kvartál snižuje.
        $preview = $this->oss->preview($this->supplierId, self::YEAR, self::QUARTER);
        self::assertEqualsWithDelta(-55.2, $this->ossCountry($preview, 'PL')['vat'], 0.01);

        // Období se opravdu nedoplnilo (hádat ho je horší než ho nechat prázdné).
        self::assertNull($item['oss_original_period']);
    }

    /**
     * § D6, druhá zastávka — chybějící původní OSS období se nesmí ztratit mezi importem
     * a PODÁNÍM.
     *
     * Report importu na dobropis bez období upozorní, jenže ten se zavře a doklad zůstane.
     * V náhledu podání pak řádek tiše sníží daň v BĚŽNÉM kvartálu, ačkoli opravuje starší
     * období — a rozdíl se pozná až z výzvy státu spotřeby. Náhled proto varuje sám za sebe.
     *
     * Varování NEBLOKUJE, a to vědomě: oprava plnění ze STEJNÉHO kvartálu se opravdu jen
     * nettuje do VetaR a uživatel nemá čím ji potvrdit ({@see \MyInvoice\Service\Validation\InvoiceValidation}
     * přijme jen období PŘEDCHÁZEJÍCÍ dokladu). Tvrdá chyba by ten legitimní případ zavřela
     * do slepé uličky — na rozdíl od chybějícího typu sazby, kde by XML bylo nevalidní
     * a oprava je jednoznačná.
     */
    public function testOssCreditNoteWithoutOriginalPeriodWarnsInTheReturnPreview(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $this->client(self::PL_CONSUMER, 'PL');

        $plain = $this->importOne('oss-pl-radna.xml', $this->pohodaForeignB2c('26OSS0021'));
        self::assertSame('created', $plain['status'], (string) ($plain['reason'] ?? ''));
        $credit = $this->importOne('oss-pl-dobropis.xml', $this->pohodaForeignB2c('26OSS0022', creditNote: true));
        self::assertSame('created', $credit['status'], (string) ($credit['reason'] ?? ''));

        $preview = $this->oss->preview($this->supplierId, self::YEAR, self::QUARTER);
        $pending = array_values(array_filter(
            $preview['warnings'],
            static fn (string $w): bool => str_contains($w, 'opravný doklad bez původního OSS období'),
        ));

        self::assertCount(1, $pending,
            'Varovat má opravný doklad, ne každý OSS řádek kvartálu — jinak hláška zevšední '
                . 'a uživatel ji přestane číst.');
        self::assertStringContainsString('26OSS0022', $pending[0], 'Hláška musí doklad pojmenovat.');
        self::assertStringNotContainsString('26OSS0021', $pending[0]);
        self::assertStringContainsString('Q' . self::QUARTER . ' ' . self::YEAR, $pending[0],
            'a říct, kam se oprava započte');
        self::assertStringContainsString('RRRRQn', $pending[0], 'a čím se to dá napravit');

        // Že se oprava opravdu započetla TADY (a hláška tedy nelže): řádná faktura
        // a dobropis se v běžném kvartálu vynulují, mezi opravami (VetaO) není nic.
        self::assertSame(2, $preview['summary']['invoice_count']);
        self::assertSame(0, $preview['summary']['correction_row_count']);
        self::assertSame(0, $preview['summary']['invalid_correction_count'],
            'Prázdné období není neplatné období — podání se kvůli němu blokovat nesmí.');
        self::assertEqualsWithDelta(0.0, $this->ossCountry($preview, 'PL')['vat'], 0.01);
    }

    /**
     * Druhá půlka rozhodnutí o znaménku: dobropis, který přišel SPRÁVNĚ (záporné řádky),
     * se nesmí otočit zpátky. Přeznačkování je podmíněné kladným součtem dokladu, takže
     * riziko „otočíme i to, co bylo dobře" neexistuje — a tenhle test to drží.
     */
    public function testCorrectlySignedCreditNoteIsLeftAlone(): void
    {
        $this->client(self::CZ_CUSTOMER, 'CZ', ic: '25596641');

        $result = $this->importOne('dobropis-zaporny.xml', $this->pohodaDomesticCreditNote('26DO0001'));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame([], $result['warnings'], 'Správně zadaný dobropis nemá co hlásit.');

        $item = $this->itemRow((int) $result['invoice_id']);
        self::assertEqualsWithDelta(-1.0, (float) $item['quantity'], 0.001);
        self::assertEqualsWithDelta(-1000.0, (float) $item['total_without_vat'], 0.01);
    }

    /**
     * § D9 — shoda variabilního symbolu u dokladu s DOSAZENÝM symbolem není důkaz duplicity.
     *
     * Náhrada VS číslem dokladu umí vyrobit shodu se skutečným symbolem úplně jiné faktury.
     * Dosavadní hláška „Faktura s varsymbolem X již existuje" by takový případ schovala mezi
     * stovky legitimních přeskočení a doklad by se ztratil bez jediného náznaku.
     */
    public function testVarsymbolCollisionAfterSubstitutionIsReportedDifferentlyFromDuplicate(): void
    {
        $this->client(self::CZ_CUSTOMER, 'CZ', ic: '25596641');

        // Existující doklad, jehož SKUTEČNÝ variabilní symbol je '26FV0077'.
        $first = $this->importOne('prvni.xml', $this->pohodaDomestic('26FV0077'));
        self::assertSame('created', $first['status'], (string) ($first['reason'] ?? ''));

        // Jiný doklad s GUIDem v symVar, jehož ČÍSLO je shodou okolností '26FV0077'.
        $guid = '3f2a91c4-7b1d-4e52-9a08-2c6f5d0b1e77';
        $collision = $this->importOne('kolize.xml', $this->pohodaDomestic('26FV0077', symVar: $guid));

        self::assertSame('skipped', $collision['status']);
        self::assertTrue($collision['varsymbol_substituted']);
        $reason = (string) $collision['reason'];
        self::assertStringContainsString('NEMUSÍ jít o duplicitu', $reason);
        self::assertStringContainsString($guid, $reason, 'Hláška musí uvést symbol z původního systému.');
        self::assertStringContainsString((string) $first['invoice_id'], $reason,
            'Hláška musí pojmenovat doklad, se kterým se symbol potkal.');
        self::assertNotSame([], $collision['warnings'], 'Kolize patří mezi varování, ne mezi tichá přeskočení.');

        // Skutečná duplicita (týž doklad podruhé) zůstává obyčejným přeskočením.
        $duplicate = $this->importOne('duplicita.xml', $this->pohodaDomestic('26FV0077'));
        self::assertSame('skipped', $duplicate['status']);
        self::assertStringContainsString('již existuje', (string) $duplicate['reason']);
        self::assertStringNotContainsString('NEMUSÍ jít o duplicitu', (string) $duplicate['reason']);
        self::assertFalse($duplicate['varsymbol_substituted']);
    }

    /**
     * § D11, druhá půlka — nulová sazba pro EU spotřebitele BEZ DIČ nesmí dostat kód
     * '20'/'22'.
     *
     * Ty dva kódy plní SOUHRNNÉ HLÁŠENÍ, které se podává za plnění osobě registrované
     * k dani v jiném členském státě. U B2C spotřebitele bez DIČ by vznikl řádek výkazu
     * bez protistrany — a souhrnné hlášení se podává a kontroluje samostatně.
     *
     * Řádek zůstane BEZ klasifikace: nulová sazba se od auditu VAT klasifikací (H-1)
     * nepřeklápí na '3' (osvobozeno § 51, ř. 50), protože tuzemské osvobození to prokazatelně
     * není. Pojmenuje ho varování přiznání „neklasifikovaný řádek se sazbou 0 %".
     */
    public function testZeroRateForEuConsumerWithoutVatIdStaysOutOfTheEcSalesList(): void
    {
        $this->enableOss();
        $this->client(self::PL_CONSUMER, 'PL');

        $result = $this->importOne('oss-pl-nula.xml', $this->pohodaForeignB2c('26OSS0006', zeroRate: true));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(0, $result['oss_items'], 'Nulová sazba do OSS nepatří (§ 110a se týká zdaněných plnění).');

        $item = $this->itemRow((int) $result['invoice_id']);
        self::assertNull($item['vat_classification_code'],
            'Kódy 20/22 by doklad poslaly do souhrnného hlášení, ačkoli odběratel DIČ nemá.');

        $submissions = new \MyInvoice\Repository\TaxSubmissionRepository($this->db);
        $sh = (new \MyInvoice\Service\Report\SouhrnneHlaseniBuilder(
            $this->db,
            $this->vatLedger,
            $submissions,
            new \MyInvoice\Service\Report\SubmissionVariantGuard($submissions),
        ))
            ->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertSame(0, (int) $sh['summary']['rows_count'],
            'Souhrnné hlášení nesmí mít za tohle období jediný řádek.');
    }

    /**
     * Tentýž doklad u firmy BEZ zapnutého OSS. Derivace neproběhne, takže se sazba
     * hledá v tuzemské škále — a tam 23 % neexistuje.
     *
     * Doklad se proto odmítne CELÝ. Je to vědomá změna chování: dřív se tiše navázal
     * na CZ-21 s kódem '1' a polská daň se vykázala jako česká. Hlasité odmítnutí
     * s návodem je jediná varianta, která uživatele nenechá podat chybné přiznání
     * (uložit řádek bez `vat_rate_id` nejde — sloupec je NOT NULL s cizím klíčem).
     */
    public function testForeignRateWithoutOssRegistrationIsRejectedInsteadOfBecomingCzechTax(): void
    {
        $this->client(self::PL_CONSUMER, 'PL');
        $pdo = $this->db->pdo();
        self::assertSame(
            0,
            (int) $pdo->query("SELECT oss_enabled FROM supplier WHERE id = {$this->supplierId}")->fetchColumn(),
            'Předpoklad testu: firma OSS zapnutý nemá.',
        );

        $result = $this->importOne('oss-pl-bez-registrace.xml', $this->pohodaForeignB2c('26OSS0002'));

        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('23', (string) $result['reason']);
        self::assertStringContainsString('CZ', (string) $result['reason'],
            'Hláška musí pojmenovat, ve které zemi se sazba hledala.');

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$this->supplierId, '26OSS0002']);
        self::assertSame(0, (int) $stmt->fetchColumn(),
            'Odmítnutý doklad nesmí po sobě nechat hlavičku bez položek (import nejede v transakci).');

        // Nic nevzniklo → v přiznání nemůže nic být. Tvrzení tu drží i pro případ,
        // že by se odmítnutí v budoucnu změnilo na „importuj s náhradní sazbou".
        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertArrayNotHasKey('1', $dph['summary']['lines']);
    }

    /**
     * § H1 — JEDEN doklad ve DVOU přiznáních. Nejtišší z nálezů téhle vlny.
     *
     * Jeden Pohoda XML, jeden polský spotřebitel bez DIČ, rekapitulace sedící na položky
     * (takže ani `file_issues` nic nehlásí): první řádek 23 % → OSS sekce PL, druhý řádek
     * 12 % → ř. 1 českého přiznání. Obě rozhodnutí jsou z pohledu ŘÁDKU správná — 23 %
     * v ČR neplatí a 12 % v Polsku neplatí — a deriver o ostatních řádcích téhož dokladu
     * nic neví, takže doklad prošel se statusem `created` a NULOU varování.
     *
     * Smíšený doklad se neodmítá: plnění s místem plnění v tuzemsku a zásilka do jiného
     * členského státu se na jedné faktuře sejít můžou. Je to ale výjimka, ne běžný stav —
     * a nejčastěji je to špatná sazba na jednom z řádků.
     *
     * § H3 — příznak musí přežít zavření reportu, jinak je to jednorázová hláška. Poslední
     * obrazovka před odesláním podání (náhled OSS) proto o čekajících řádcích varuje.
     */
    public function testDocumentSplitBetweenOssAndCzechReturnIsFlaggedInsteadOfSilent(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $this->client(self::PL_CONSUMER, 'PL');
        if (!$this->vatRates->resolve($this->domesticCountry(), 12.0, self::TAX_DATE)->found()) {
            self::markTestSkipped('Instalace nemá tuzemskou sníženou sazbu 12 % — doklad by se odmítl na párování sazby.');
        }

        $result = $this->importOne('smiseny.xml', $this->pohodaMixedOssAndDomestic('26FV0150'));
        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));

        $warnings = implode("\n", $result['warnings']);
        self::assertStringContainsString('Doklad si protiřečí', $warnings,
            'Rozpor je vlastnost DOKLADU — per řádek ho nikdo nevidí, a doklad tak prošel úplně tiše.');
        self::assertStringContainsString('K RUČNÍMU POSOUZENÍ', $warnings);
        self::assertSame(1, $result['oss_items']);
        self::assertSame(2, $result['oss_manual_review'],
            'Označí se OBĚ strany rozporu: OSS řádek je vidět v náhledu podání, tuzemský je ten podezřelý.');

        $invoiceId = (int) $result['invoice_id'];
        $rows = $this->itemRows($invoiceId);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame(1, (int) $row['oss_needs_manual_review'],
                'Příznak musí být v datech, ne jen v reportu — po zavření stránky ho jinak nikdo nedohledá.');
        }
        self::assertSame(1, (int) $rows[0]['oss_applicable']);
        self::assertSame('PL', (string) $rows[0]['oss_consumer_country']);
        self::assertSame(0, (int) $rows[1]['oss_applicable']);
        self::assertSame('2', (string) $rows[1]['vat_classification_code'], 'tuzemsko, snížená sazba');

        // Doklad SKUTEČNĚ leží v obou výkazech — bez těchhle dvou tvrzení by test prošel
        // i variantě „smíšený doklad odmítneme", která je ale vědomě zamítnutá.
        self::assertContains($invoiceId, $this->saleInvoiceIdsInVatLedger());
        $preview = $this->oss->preview($this->supplierId, self::YEAR, self::QUARTER);
        self::assertEqualsWithDelta(240.0, $this->ossCountry($preview, 'PL')['base'], 0.01);

        // § H3: náhled podání příznak z položky čte a hlásí ho jedním varováním za období.
        self::assertStringContainsString('RUČNÍ POSOUZENÍ', implode("\n", $preview['warnings']),
            'Bez tohohle je příznak jen jednorázová hláška reportu importu.');
    }

    /**
     * § H2 — přeshraniční B2C plnění za TUZEMSKOU sazbu při AKTIVNÍ registraci do OSS.
     *
     * Polský spotřebitel bez DIČ, česká sazba 21 %, firma registrovaná do OSS pro dané
     * období. Kvadrant „tuzemské plnění" byl do téhle vlny úplně němý: `created`, nula
     * varování, nula poznámek, nula OSS řádků, kód '1' a ř. 1 přiznání.
     *
     * ROZHODNUTÍ se nemění — sazbu uvádí sám doklad, registrace do OSS je dobrovolná
     * a plnění pod prahem § 8/3 tuzemské opravdu být může. Mění se jen to, že o tom
     * uživatel ví. Test proto tvrdí OBOJÍ: řádek zůstává v českém přiznání A je označený.
     */
    public function testDomesticRateForEuConsumerWithActiveOssRegistrationIsFlagged(): void
    {
        $this->enableOss();
        $this->client(self::PL_CONSUMER, 'PL');

        $result = $this->importOne('pl-21.xml', $this->pohodaDomestic(
            '26FV0151',
            company: self::PL_CONSUMER,
            ico: '',
            countryIso2: 'PL',
        ));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(0, $result['oss_items'], 'Řádek zůstává tuzemský — nepřeznačkováváme ho.');
        self::assertSame(1, $result['oss_manual_review']);
        $warnings = implode("\n", $result['warnings']);
        self::assertStringContainsString('K RUČNÍMU POSOUZENÍ', $warnings);
        self::assertStringContainsString('TUZEMSKOU sazbou', $warnings);

        $invoiceId = (int) $result['invoice_id'];
        $item = $this->itemRow($invoiceId);
        self::assertSame(0, (int) $item['oss_applicable']);
        self::assertSame('1', (string) $item['vat_classification_code']);
        self::assertSame(1, (int) $item['oss_needs_manual_review'],
            'Označený tuzemský řádek musí jít dohledat i po zavření reportu.');

        self::assertContains($invoiceId, $this->saleInvoiceIdsInVatLedger(),
            'Varování NENÍ změna rozhodnutí — řádek se z přiznání k DPH nesmí ztratit.');
        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertArrayHasKey('1', $dph['summary']['lines']);
    }

    /**
     * Regrese běžného provozu: tuzemský doklad se zapnutým OSS se chová beze změny —
     * česká sazba, tuzemský kód plnění, ř. 1 přiznání, mimo OSS podklad.
     */
    public function testDomesticInvoiceStillEntersTheCzechVatReturn(): void
    {
        $this->enableOss();
        $this->client(self::CZ_CUSTOMER, 'CZ', ic: '25596641');

        $result = $this->importOne('tuzemsko.xml', $this->pohodaDomestic('26FV0001'));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(0, $result['oss_items']);
        self::assertSame([], $result['warnings'], 'Tuzemský doklad nemá co hlásit.');
        $invoiceId = (int) $result['invoice_id'];

        $item = $this->itemRow($invoiceId);
        self::assertSame(0, (int) $item['oss_applicable']);
        self::assertNull($item['oss_consumer_country']);
        self::assertSame('1', (string) $item['vat_classification_code'], 'Tuzemsko, základní sazba.');
        self::assertSame('CZ', (string) $item['rate_country']);
        self::assertEqualsWithDelta(21.0, (float) $item['vat_rate_snapshot'], 0.001);

        $saleRows = array_values(array_filter(
            $this->vatLedger->rows($this->supplierId, self::PERIOD_FROM, self::PERIOD_TO),
            static fn (array $r): bool => $r['source'] === 'sale' && (int) $r['invoice_id'] === $invoiceId,
        ));
        self::assertCount(1, $saleRows, 'Tuzemský doklad z evidence DPH vypadnout nesmí.');
        self::assertSame('1', (string) $saleRows[0]['code']);

        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertArrayHasKey('1', $dph['summary']['lines']);
        self::assertEqualsWithDelta(1000.0, $dph['summary']['lines']['1']['base'], 0.01);
        self::assertEqualsWithDelta(210.0, $dph['summary']['lines']['1']['vat'], 0.01);

        $preview = $this->oss->preview($this->supplierId, self::YEAR, self::QUARTER);
        self::assertSame([], $preview['countries'], 'Tuzemský doklad do OSS podkladu nepatří.');
    }

    /**
     * GUID v `<inv:symVar>` (1058 z 1670 dokladů zákazníkovy migrace) doklad neshodí —
     * naimportuje se pod číslem dokladu a report to výslovně uvede. Bez poznámky by
     * uživatel doklad pod původním symbolem marně hledal.
     */
    public function testGuidVarsymbolFallsBackToDocumentNumberAndIsReported(): void
    {
        $this->client(self::CZ_CUSTOMER, 'CZ', ic: '25596641');
        $guid = '3f2a91c4-7b1d-4e52-9a08-2c6f5d0b1e77';

        $result = $this->importOne('guid-vs.xml', $this->pohodaDomestic('26FV0042', symVar: $guid));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame('26FV0042', $result['varsymbol']);

        $stmt = $this->db->pdo()->prepare('SELECT varsymbol FROM invoices WHERE id = ?');
        $stmt->execute([(int) $result['invoice_id']]);
        self::assertSame('26FV0042', (string) $stmt->fetchColumn());

        $notes = implode("\n", $result['notes']);
        self::assertStringContainsString($guid, $notes, 'Report musí uvést zahozenou hodnotu.');
        self::assertStringContainsString('číslem dokladu', $notes);
    }

    /**
     * ISDOC varianta téhož zahraničního dokladu. Zákazník posílá obě cesty a musí
     * dopadnout stejně — parser je jiný, derivace OSS i párování sazby sdílené.
     */
    public function testIsdocForeignB2cInvoiceGivesTheSameResultAsPohodaXml(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $this->client(self::PL_CONSUMER, 'PL');

        $result = $this->importOne('oss-pl.isdoc', $this->isdocForeignB2c('26OSS0005'));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(1, $result['oss_items']);
        $invoiceId = (int) $result['invoice_id'];

        $item = $this->itemRow($invoiceId);
        self::assertSame(1, (int) $item['oss_applicable']);
        self::assertSame('PL', (string) $item['oss_consumer_country']);
        self::assertSame('standard', (string) $item['oss_rate_type']);
        self::assertSame('goods', (string) $item['oss_supply_type']);
        self::assertEqualsWithDelta(23.0, (float) $item['vat_rate_snapshot'], 0.001);
        self::assertNull($item['vat_classification_code']);
        self::assertSame('PL', (string) $item['rate_country']);
        self::assertEqualsWithDelta(1000.0, (float) $item['total_without_vat'], 0.01,
            'Cizoměnová cena se bere z LineExtensionAmountCurr, ne z UnitPrice v Kč.');

        self::assertNotContains($invoiceId, $this->saleInvoiceIdsInVatLedger(),
            'ISDOC doklad prošel do české evidence DPH, ačkoli Pohoda XML se stejným obsahem ne.');

        // Že doklad do období spadá (a tvrzení výše tedy není prázdné) dokazuje OSS
        // podklad za totéž období — obojí filtruje přes `effective_tax_date`.
        $preview = $this->oss->preview($this->supplierId, self::YEAR, self::QUARTER);
        $pl = $this->ossCountry($preview, 'PL');
        self::assertEqualsWithDelta(240.0, $pl['base'], 0.01);
        self::assertEqualsWithDelta(55.2, $pl['vat'], 0.01);
    }

    // ── vlna 1d: dva úniky, které reprodukovaly dvě nezávislé review ─────────

    /**
     * ÚNIK Č. 1 — NEVĚDOMOST SE DOMÝŠLELA NA TUZEMSKO.
     *
     * Reprodukovaný řetěz: číselník členských států neuměl o zemi dodavatele k datu plnění
     * odpovědět (odpověď „NEVÍM"), a protože se odmítalo jen na tvrdé „NEPLATÍ", prohlásil
     * se řádek za TUZEMSKÝ. Odtud už to jelo samo: `VatRateResolver::resolve('CZ', 23)`
     * našel uživatelovu sazbu „PL-23" vedenou pod zemí CZ, klasifikace vyšla '1'
     * (tuzemské plnění v základní sazbě), `oss_applicable` zůstalo 0 a polská daň se
     * vykázala na ř. 1 českého přiznání jako česká daň na výstupu.
     *
     * „NEVÍM" přitom NENÍ okrajový stav. Seed migrace 1152 znal ČR až od 1. 1. 2024, takže
     * každý starší doklad — a migrace 1 670 historických faktur je běžný vstup — spadal
     * přesně sem. Test proto stojí na dokladu z doby, kam číselník ani po doplnění historie
     * (migrace 1294) nesahá; obojí je ověřený PŘEDPOKLAD, ne domněnka o obsahu seedu.
     *
     * Po opravě je invariant TOTÁLNÍ: do tuzemské větve smí jen řádek, u kterého číselník
     * platnost sazby v zemi dodavatele POZITIVNĚ potvrdil. Každá jiná odpověď = odmítnutí
     * položky s hláškou, nikdy tiché tuzemské zařazení.
     */
    public function testUnverifiableSupplierCountryRateIsRejectedInsteadOfBecomingCzechTaxOnLine1(): void
    {
        $oldDate = '2009-06-15';
        $this->enableOss();
        // Zákazníkova konfigurace: kód říká „polská", země říká CZ. Platnost od roku 2000,
        // aby sazba byla k datu dokladu SKUTEČNĚ nalezitelná — jinak by doklad spadl na
        // nenapárované sazbě a test by hlídal něco jiného, než co má.
        $this->vatRate('CZ', 23.0, code: 'PL-23-vlastni', validFrom: '2000-01-01');
        $this->client(self::PL_CONSUMER, 'PL');
        $this->client(self::CZ_CUSTOMER, 'CZ', ic: '25596641');

        // ── Předpoklady, bez kterých by zelená níže nedokazovala nic ─────────────
        self::assertFalse($this->codebookKnowsDomesticRatesAt($oldDate),
            'Test potřebuje datum, ke kterému číselník o zemi dodavatele MLČÍ — jinak neměří '
                . 'nevědomost, ale běžný potvrzený případ.');
        self::assertTrue(
            $this->vatRates->resolve($this->domesticCountry(), 23.0, $oldDate)->found(),
            'Tuzemské párování sazby by tenhle řádek SKUTEČNĚ obsloužilo — právě tudy se cizí '
                . 'daň dostávala na ř. 1. Bez toho by test padal na jiné příčině.',
        );

        $out = $this->import->importBundle(
            [
                ['name' => 'oss-pl-stary.xml', 'content' => $this->pohodaForeignB2c(
                    '26OSS0201',
                    date: $oldDate,
                    dateTax: $oldDate,
                    dueDate: '2009-07-15',
                )],
                // Kontrolní tuzemský doklad: drží pre-flight číselníku v klidu (aspoň jedno
                // datum balíku je v pokrytém období) a zároveň dokazuje, že výkazy nejsou
                // prázdné — „na ř. 1 nic není" nad prázdným přiznáním neplatí vždycky.
                ['name' => 'tuzemsko.xml', 'content' => $this->pohodaDomestic('26FV0201')],
            ],
            $this->supplierId,
            $this->userId,
            'issued',
        );

        $rejected = $out['results'][0];
        self::assertSame('failed', $rejected['status'],
            'Řádek, u kterého číselník tuzemskost sazby NEPOTVRDIL, se nesmí prohlásit za tuzemský — '
                . 'v `vat_rates` na něj čeká uživatelova „PL-23" se zemí CZ a s ní ř. 1 přiznání.');
        $reason = (string) $rejected['reason'];
        self::assertStringContainsString('nemůže být tuzemské plnění', $reason);
        self::assertStringContainsString('číselník sazeb členských států', $reason,
            'Hláška musí pojmenovat, ČÍ odpověď chybí — jinak uživatel hledá chybu v datech dokladu.');

        self::assertSame(0, $this->storedInvoiceCount('26OSS0201'), 'Odmítnutý doklad se nesmí založit.');
        self::assertSame('created', $out['results'][1]['status'], (string) ($out['results'][1]['reason'] ?? ''));

        // ── A hlavně: v přiznání za období dokladu není NIC ──────────────────────
        $old = $this->dph->build($this->supplierId, 2009, 6, 'monthly');
        self::assertArrayNotHasKey('1', $old['summary']['lines'],
            'Na ř. 1 přiznání za období starého dokladu se objevila daň — přesně únik, kvůli '
                . 'kterému tenhle test existuje.');

        // Kontrolní doklad naopak na ř. 1 být MÁ, jinak by tvrzení výše platilo triviálně.
        $control = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertEqualsWithDelta(1000.0, $control['summary']['lines']['1']['base'], 0.01);
    }

    /**
     * ÚNIK Č. 2 — NEKANONICKÉ DATUM OBEŠLO INVARIANT. Doslovná reprodukce toho, co review
     * naměřila: `<inv:date>2096-5-15</inv:date>` bez vodicích nul a BEZ `<inv:dateTax>`.
     *
     * Původní chování: deriver na takovém datu neprošel `preg_match` a vrátil
     * `notApplicable(MissingTaxDate)` JEŠTĚ PŘED invariantem, takže se řádek propadl do
     * tuzemské větve, 23 % se napárovalo na uživatelovu sazbu se zemí CZ (MariaDB přijme
     * '2096-5-15' do sloupce `DATE` i do porovnání s `valid_from` bez hlesnutí) a přiznání
     * vrátilo ř. 1 = {base: 1 000, vat: 230}. `MissingTaxDate` navíc nebylo varování,
     * takže report o vadném datu mlčel úplně.
     *
     * Po opravě jsou přípustné jen dva konce, oba bezpečné: datum se zkanonizuje a řádek
     * jde do OSS, nebo se doklad odmítne. Ř. 1 se nesmí hnout ani v jednom případě.
     */
    public function testNonCanonicalTaxDateGoesToOssAndNeverToLine1OfTheCzechReturn(): void
    {
        $this->enableOss();
        // Obojí naráz, jak to má zákazník: „PL-23" pod zemí CZ (past tuzemské větve)
        // i korektně vedená polská sazba, na kterou se OSS řádek napáruje.
        $this->vatRate('CZ', 23.0, code: 'PL-23-vlastni');
        $this->vatRate('PL', 23.0);
        $this->client(self::PL_CONSUMER, 'PL');
        $this->client(self::CZ_CUSTOMER, 'CZ', ic: '25596641');

        self::assertTrue(
            $this->vatRates->resolve($this->domesticCountry(), 23.0, self::TAX_DATE)->found(),
            'Předpoklad: tuzemské párování sazby by řádek obsloužilo, takže únik je reálně otevřený.',
        );

        $out = $this->import->importBundle(
            [
                ['name' => 'oss-pl-datum.xml', 'content' => $this->pohodaForeignB2c(
                    '26OSS0202',
                    // BEZ vodicích nul a BEZ DUZP — přesně tvar z reprodukce.
                    date: '2096-5-15',
                    dateTax: null,
                )],
                ['name' => 'tuzemsko.xml', 'content' => $this->pohodaDomestic('26FV0202')],
            ],
            $this->supplierId,
            $this->userId,
            'issued',
        );
        $result = $out['results'][0];

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(1, $result['oss_items'], 'Vadný tvar data nesmí řádek vrátit do tuzemské větve.');
        $invoiceId = (int) $result['invoice_id'];

        // ── 1) Datum se zkanonizovalo na hranici, do DB nešel syrový tvar ────────
        $stmt = $this->db->pdo()->prepare('SELECT issue_date, tax_date FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame('2096-05-15', (string) $header['issue_date'],
            'Kanonizace není kosmetika: platnost sazby i registrace do OSS se porovnává jako '
                . 'ŘETĚZEC, takže „2096-5-15" odpovídá na jinou otázku, než jaká byla položena.');
        self::assertNull($header['tax_date'], 'Chybějící DUZP se nedomýšlí, jen se od data vystavení odvodí plnění.');

        // ── 2) Řádek je OSS, ne tuzemský ─────────────────────────────────────────
        $item = $this->itemRow($invoiceId);
        self::assertSame(1, (int) $item['oss_applicable']);
        self::assertSame('PL', (string) $item['oss_consumer_country']);
        self::assertNull($item['vat_classification_code'],
            'Klasifikace 1 je ta hodnota, se kterou doklad odcházel na ř. 1.');
        self::assertSame('PL', (string) $item['rate_country'],
            'Napárování na sazbu se zemí CZ znamená, že řádek prošel tuzemskou větví.');

        // ── 3) Ř. 1 nese POUZE kontrolní tuzemský doklad ─────────────────────────
        self::assertNotContains($invoiceId, $this->saleInvoiceIdsInVatLedger());
        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertEqualsWithDelta(1000.0, $dph['summary']['lines']['1']['base'], 0.01,
            'Na ř. 1 patří jen tuzemských 1 000 Kč — cokoli navíc je polský doklad, který '
                . 'obešel invariant nekanonickým datem.');
        self::assertEqualsWithDelta(210.0, $dph['summary']['lines']['1']['vat'], 0.01,
            'Review naměřila v tomhle scénáři ř. 1 = {base: 1 000, vat: 230}, tedy polskou daň '
                . 'vydávanou za českou.');

        // ── 4) A v OSS podkladu doklad naopak JE ─────────────────────────────────
        $pl = $this->ossCountry($this->oss->preview($this->supplierId, self::YEAR, self::QUARTER), 'PL');
        self::assertEqualsWithDelta(240.0, $pl['base'], 0.01, 'Základ 1 000 PLN kurzem 0,24 = 240 EUR.');
        self::assertEqualsWithDelta(55.2, $pl['vat'], 0.01);
    }

    /**
     * F2 — CHYBĚJÍCÍ ČÍSELNÍK JE CHYBA BĚHU, NE STAV K TOLEROVÁNÍ.
     *
     * Po zavedení totality by prázdný (nebo chybějící) číselník odmítl KAŽDOU položku se
     * sazbou vyšší než 0 %, takže by uživatel u migrace 1 670 dokladů dostal 1 670
     * nesrozumitelných hlášek a žádný doklad. Jedna hlasitá věta na začátku je
     * nesrovnatelně lepší — a hlavně akční: říká, co spustit.
     *
     * Kontrola proto běží nad CELÝM balíkem před prvním zápisem a musí platit obojí:
     * nesmí se uložit nic a hláška musí být jedna.
     */
    public function testMissingRateCodebookStopsTheWholeRunWithOneActionableMessage(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $this->client(self::PL_CONSUMER, 'PL');
        $this->emptyRateCodebookForSupplierCountry();

        try {
            $this->import->importBundle(
                [['name' => 'oss-pl.xml', 'content' => $this->pohodaForeignB2c('26OSS0203')]],
                $this->supplierId,
                $this->userId,
                'issued',
            );
            self::fail('Import se bez číselníku rozběhl — každý doklad by dopadl odmítnutím položky.');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            self::assertStringContainsString('zemi dodavatele (' . $this->domesticCountry() . ')', $message,
                'Hláška musí pojmenovat, ČÍ sazby v číselníku chybí.');
            self::assertStringContainsString('php api/bin/migrate.php', $message,
                'Bez konkrétního příkazu je hláška u tohohle nálezu k ničemu — zákazník z analýzy '
                    . 'hledal chybějící PL/HU/SK v datech právě proto, že mu to nikdo neřekl.');
            self::assertStringContainsString('Zatím se neuložilo nic', $message);
        }

        self::assertSame(0, $this->storedInvoiceCount('26OSS0203'), 'Zastavený běh nesmí nechat v datech doklad.');
    }

    /**
     * Táž brána pro firmu BEZ zapnutého OSS — schválně, ne opomenutím.
     *
     * Odmítnutí položky NENÍ OSS větev: řádek firmy s vypnutým OSS projde týmž
     * `domesticOrReject()` (s blokujícím důvodem „firma nemá zapnutý OSS") a bez číselníku
     * dopadne úplně stejně. Kontrola vázaná na přepínač `oss_enabled` by tedy nechala
     * největší skupinu instalací spadnout do 1 670 hlášek — tedy přesně do stavu,
     * kvůli kterému brána vznikla.
     */
    public function testMissingRateCodebookStopsTheRunEvenForACompanyWithoutOss(): void
    {
        $this->client(self::CZ_CUSTOMER, 'CZ', ic: '25596641');
        self::assertSame(
            0,
            (int) $this->db->pdo()->query("SELECT oss_enabled FROM supplier WHERE id = {$this->supplierId}")->fetchColumn(),
            'Předpoklad testu: firma OSS zapnutý nemá.',
        );
        $this->emptyRateCodebookForSupplierCountry();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/číseln[íi]k/iu');

        $this->import->importBundle(
            [['name' => 'tuzemsko.xml', 'content' => $this->pohodaDomestic('26FV0204')]],
            $this->supplierId,
            $this->userId,
            'issued',
        );
    }

    /**
     * PROTIPÓL téže brány: dokud číselník odpovídá, běžný tuzemský import firmy BEZ OSS
     * proběhne beze změny a bez jediné zmínky o OSS.
     *
     * Falešný poplach je u brány, která neimportuje NIC, dražší než u varování — proto se
     * tvrdí i to, že se nic nezastavilo, a ne jen že se něco zastavilo jinde.
     */
    public function testCompanyWithoutOssImportsNormallyWhileTheCodebookAnswers(): void
    {
        $this->client(self::CZ_CUSTOMER, 'CZ', ic: '25596641');

        $result = $this->importOne('tuzemsko.xml', $this->pohodaDomestic('26FV0205'));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(0, $result['oss_items']);
        self::assertSame([], $result['warnings'], 'Tuzemský doklad nemá co hlásit.');

        $item = $this->itemRow((int) $result['invoice_id']);
        self::assertSame('1', (string) $item['vat_classification_code']);
        self::assertSame(0, (int) $item['oss_applicable']);

        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertEqualsWithDelta(1000.0, $dph['summary']['lines']['1']['base'], 0.01);
        self::assertEqualsWithDelta(210.0, $dph['summary']['lines']['1']['vat'], 0.01);
    }

    /**
     * F6 — PARSER NESMÍ HÁDAT SAZBU, a import z nedosazené sazby nesmí udělat osvobozené
     * plnění.
     *
     * `historyHigh` bez `<inv:percentVAT>` znamená doslova „tahle sazba už neplatí, skutečné
     * procento je jinde". Dosazení AKTUÁLNÍ české sazby bylo hádání s tichým následkem:
     * přeshraniční plnění dostalo 21 %, prošlo kvadrantem „platí jen v tuzemsku" a skončilo
     * na ř. 1 bez varování. Druhá cesta téhož úniku vedla přes `(float) null === 0.0` —
     * z plnění by se stalo OSVOBOZENÉ, a nulová sazba je z invariantu vyňatá, takže by cizí
     * daň nezmizela do špatné země, ale úplně.
     *
     * Jediné bezpečné vyústění je odmítnutí dokladu s hláškou, co doplnit.
     */
    public function testHistoricRateEnumWithoutPercentRejectsTheDocumentInsteadOfGuessingTheRate(): void
    {
        $this->enableOss();
        $this->vatRate('PL', 23.0);
        $this->client(self::PL_CONSUMER, 'PL');
        $this->client(self::CZ_CUSTOMER, 'CZ', ic: '25596641');

        $out = $this->import->importBundle(
            [
                ['name' => 'oss-pl-bez-procenta.xml', 'content' => $this->pohodaForeignB2c(
                    '26OSS0206',
                    omitPercent: true,
                )],
                ['name' => 'tuzemsko.xml', 'content' => $this->pohodaDomestic('26FV0206')],
            ],
            $this->supplierId,
            $this->userId,
            'issued',
        );

        $rejected = $out['results'][0];
        self::assertSame('failed', $rejected['status'],
            'Doklad bez určené sazby se nesmí naimportovat — ani s dohadem 21 %, ani jako osvobozený.');
        self::assertStringContainsString('neurčuje sazbu DPH', (string) $rejected['reason']);
        self::assertSame(0, $this->storedInvoiceCount('26OSS0206'));

        // Doklad se nikam nevykázal: ani na ř. 1 (dohad 21 %), ani jako osvobozené plnění.
        $dph = $this->dph->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        self::assertEqualsWithDelta(1000.0, $dph['summary']['lines']['1']['base'], 0.01,
            'Na ř. 1 patří jen kontrolní tuzemský doklad. 7 000 Kč znamená, že se za neznámou '
                . 'sazbu dosadila česká 21 % a polské plnění se vykázalo jako tuzemské.');
        self::assertSame([], $this->oss->preview($this->supplierId, self::YEAR, self::QUARTER)['countries'],
            'Odmítnutý doklad nesmí skončit ani v OSS podkladu.');
    }

    /**
     * F5 — ČÍSELNÍK MUSÍ POKRÝT I STARŠÍ DOKLADY.
     *
     * Seed migrace 1152 znal ČR až od 1. 1. 2024, protože ho zajímal STÁT SPOTŘEBY. Země
     * dodavatele je ale druhá strana téhož dotazu a ptá se i na doklady z doby dávno před
     * OSS — a po zavedení totality by se každý český doklad s DUZP před rokem 2024 odmítl
     * s hláškou „číselník tuhle zemi k datu plnění nevede", ačkoli je na něm všechno
     * v pořádku. Historii doplňuje migrace 1294.
     *
     * Tenhle test je pojistka proti přehnané přísnosti: invariant se dá „splnit" i tak,
     * že spadne polovina legitimních dokladů, a to je jiná chyba, ne oprava.
     */
    public function testDocumentDatedBeforeTheOssSeedIsStillAcceptedThanksToTheHistoricRates(): void
    {
        $oldDate = '2023-05-15';
        $this->enableOss();
        $this->client(self::CZ_CUSTOMER, 'CZ', ic: '25596641');

        self::assertTrue($this->codebookKnowsDomesticRatesAt($oldDate),
            'Číselník nezná sazby země dodavatele k roku 2023 — chybí migrace 1294 a každý '
                . 'starší tuzemský doklad se odmítne.');

        $result = $this->importOne('tuzemsko-2023.xml', $this->pohodaDomestic(
            '26FV0207',
            date: $oldDate,
            dateTax: $oldDate,
            dueDate: '2023-06-15',
        ));

        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(0, $result['oss_items']);

        $item = $this->itemRow((int) $result['invoice_id']);
        self::assertSame('1', (string) $item['vat_classification_code'], 'Tuzemsko, základní sazba.');
        self::assertSame(0, (int) $item['oss_applicable']);

        $dph = $this->dph->build($this->supplierId, 2023, 5, 'monthly');
        self::assertArrayHasKey('1', $dph['summary']['lines'],
            'Starší tuzemský doklad z přiznání vypadnout nesmí.');
        self::assertEqualsWithDelta(1000.0, $dph['summary']['lines']['1']['base'], 0.01);
        self::assertEqualsWithDelta(210.0, $dph['summary']['lines']['1']['vat'], 0.01);
    }

    /**
     * F4 — PŘÍZNAK „K RUČNÍMU POSOUZENÍ" MUSÍ PŘEŽÍT ULOŽENÍ, a to na cestě, kterou uživatel
     * skutečně jde: import → otevření dokladu → uložení.
     *
     * Import příznak zapisoval, ale `replaceItems()` položky maže a zakládá znovu a
     * `ossItemParams()` ten sloupec neznal — první uložení faktury tedy celou kategorii
     * „nedokázali jsme určit místo plnění" zahodilo. Po zavření reportu (což je jednorázová
     * stránka) by po ní nezbyla stopa nikde a hromadná oprava vlny 2 by neměla nad čím běžet.
     *
     * Doplněk k `Tests\Integration\Invoice\OssManualReviewPersistenceTest`: ten testuje
     * repozitář nad ručně založeným konceptem, tady začíná řetěz u XML — tedy tam, kde
     * příznak vzniká.
     */
    public function testImportedManualReviewFlagSurvivesTheFirstSaveOfTheInvoice(): void
    {
        if (!$this->db->hasColumn('invoice_items', 'oss_needs_manual_review')) {
            self::markTestSkipped('Chybí migrace 1293.');
        }

        $this->enableOss();
        // 21 % platí v NL i v ČR, takže z procenta místo plnění neplyne — řádek jde do OSS
        // a označí se k ručnímu posouzení.
        $this->vatRate('NL', 21.0);
        $this->client(self::NL_CONSUMER, 'NL');

        $result = $this->importOne('nl-21.xml', $this->pohodaDomestic(
            '26FV0208',
            company: self::NL_CONSUMER,
            ico: '',
            countryIso2: 'NL',
        ));
        self::assertSame('created', $result['status'], (string) ($result['reason'] ?? ''));
        self::assertSame(1, $result['oss_manual_review']);

        $invoiceId = (int) $result['invoice_id'];
        self::assertSame(1, $this->storedManualReviewFlag($invoiceId), 'Import příznak vůbec nezapsal.');

        // Uživatel doklad otevře a uloží (třeba jen kvůli splatnosti): položky projdou
        // detailem a vrátí se do `replaceItems()`. Přesně tady se příznak ztrácel.
        $items = $this->invoices->find($invoiceId)['items'];
        self::assertTrue($items[0]['oss_needs_manual_review'] ?? null,
            'Detail dokladu příznak nevrací — editor by ho neměl co poslat zpět a uložení by ho zahodilo.');

        $this->invoices->replaceItems($invoiceId, $items);

        self::assertSame(1, $this->storedManualReviewFlag($invoiceId),
            'Uložení dokladu příznak zahodilo — kategorie „místo plnění k posouzení" tím po prvním '
                . 'doteku faktury mizí a hromadná oprava nemá nad čím běžet.');
    }

    // ── tvrzení ──────────────────────────────────────────────────────────────

    /**
     * ID vydaných dokladů, které období vidí v kanonické evidenci DPH. Vrací se SEZNAM,
     * ať se dá tvrdit i „něco tam být má" — tvrzení „chybí tam X" nad prázdným výkazem
     * platí vždycky a nedokazuje nic.
     *
     * @return list<int>
     */
    private function saleInvoiceIdsInVatLedger(): array
    {
        $ids = [];
        foreach ($this->vatLedger->rows($this->supplierId, self::PERIOD_FROM, self::PERIOD_TO) as $row) {
            if ($row['source'] === 'sale') {
                $ids[] = (int) $row['invoice_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Kolik dokladů daného varsymbolu po sobě běh nechal. Odmítnutí i zastavená brána musí
     * vracet NULU: import nejede v transakci, takže „doklad se nevytvořil" je tvrzení
     * o datech, ne o návratové hodnotě.
     */
    private function storedInvoiceCount(string $varsymbol): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$this->supplierId, $varsymbol]);

        return (int) $stmt->fetchColumn();
    }

    private function storedManualReviewFlag(int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT oss_needs_manual_review FROM invoice_items
              WHERE invoice_id = ? ORDER BY order_index, id LIMIT 1'
        );
        $stmt->execute([$invoiceId]);

        return (int) $stmt->fetch(PDO::FETCH_COLUMN);
    }

    /**
     * @param  array<string,mixed> $preview
     * @return array<string,mixed>
     */
    private function ossCountry(array $preview, string $iso2): array
    {
        foreach ($preview['countries'] as $country) {
            if ($country['country'] === $iso2) {
                return $country;
            }
        }
        self::fail('OSS podklad neobsahuje sekci ' . $iso2 . '.');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function importOne(string $name, string $content): array
    {
        $out = $this->import->importBundle(
            [['name' => $name, 'content' => $content]],
            $this->supplierId,
            $this->userId,
            'issued',
        );
        self::assertCount(1, $out['results'], 'Očekává se právě jeden výsledek na jeden doklad.');

        return $out['results'][0];
    }

    /** @return array<string,mixed> */
    private function itemRow(int $invoiceId): array
    {
        $rows = $this->itemRows($invoiceId);
        self::assertCount(1, $rows, 'Doklad má mít právě jednu položku.');

        return $rows[0];
    }

    /** @return list<array<string,mixed>> */
    private function itemRows(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ii.*, vr.code AS rate_code, vr.country AS rate_country, vr.rate_percent AS rate_percent
               FROM invoice_items ii
               JOIN vat_rates vr ON vr.id = ii.vat_rate_id
              WHERE ii.invoice_id = ?
           ORDER BY ii.order_index, ii.id'
        );
        $stmt->execute([$invoiceId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function disableOss(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET oss_enabled = 0,
                    oss_valid_from = NULL,
                    oss_valid_to = NULL
              WHERE id = ?"
        )->execute([$this->supplierId]);
    }

    private function enableOss(): void
    {
        $this->db->pdo()->prepare(
            "UPDATE supplier
                SET oss_enabled = 1,
                    oss_valid_from = '2096-01-01',
                    oss_valid_to = NULL,
                    oss_identification_country = 'CZ',
                    oss_return_currency = 'EUR'
              WHERE id = ?"
        )->execute([$this->supplierId]);
    }

    private function currency(string $code, string $symbol, string $cs, string $en, bool $isDefault = false): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, 1, ?)'
        )->execute([$this->supplierId, $code, $cs, $symbol, $cs, $en, $isDefault ? 1 : 0]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Sazba pro stát spotřeby v `vat_rates`. Zakládá ji TEST, ne import: `vat_rates` je
     * globální tabulka bez `supplier_id`, takže automatický zápis z importu jednoho
     * nájemníka mění číselník celé instalaci — a `uq_vat_code` navíc koliduje s kódem,
     * který si mezitím uživatel založil ručně. Uživatel sazbu zakládá v Nastavení →
     * Sazby DPH, test tedy dělá totéž.
     */
    private function vatRate(
        string $country,
        float $percent,
        ?string $code = null,
        string $validFrom = '2090-01-01',
    ): void {
        // `$code` se dá přebít schválně: zákazníkova instalace má sazbu s kódem „PL-23"
        // uloženou se zemí CZ, a právě na téhle rozešlosti stojí celý nález.
        $code ??= strtoupper($country) . '-' . rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
        // `$validFrom` je parametr kvůli testům nad HISTORICKÝMI doklady: párování sazby
        // respektuje platnost k datu, takže sazba platná až od roku 2090 by u dokladu
        // z roku 2009 nebyla nalezena — a test by pak zeleně tvrdil něco jiného
        // („sazba se nenapárovala") než co má hlídat („řádek se nesmí stát tuzemským").
        $this->db->pdo()->prepare(
            'INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, is_default,
                                    is_reverse_charge, valid_from, valid_to, display_order)
             VALUES (?, ?, ?, ?, ?, 0, 0, ?, NULL, 900)
             ON DUPLICATE KEY UPDATE rate_percent = VALUES(rate_percent), country = VALUES(country),
                                     valid_from = VALUES(valid_from), valid_to = VALUES(valid_to)'
        )->execute([
            $code,
            $percent,
            strtoupper($country),
            $code,
            $code,
            $validFrom,
        ]);
    }

    private function exchangeRate(string $code, float $rate, string $date = self::TAX_DATE): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO exchange_rates (rate_date, currency_code, rate) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)'
        )->execute([$date, $code, $rate]);
    }

    /**
     * Kurzy ECB pro POSLEDNÍ DEN kvartálu — jediný kurz, kterým se přepočítává OSS podání
     * (kurz ČNB k DUZP výše zůstává, protože z něj žije tuzemský základ daně na kontrolním
     * dokladu). ECB má opačnou orientaci než ČNB (jednotky měny za 1 EUR), takže poměry
     * 1 PLN = 6 Kč a 1 EUR = 25 Kč se sem přepíšou jako 25 Kč, resp. 25/6 zlotého za euro;
     * křížový kurz PLN→EUR pak vyjde na týchž 0,24 a tvrzení testů zůstávají beze změny.
     */
    private function ecbPeriodEndRates(): void
    {
        $pdo = $this->db->pdo();
        $rate = $pdo->prepare(
            'INSERT INTO ecb_exchange_rates (rate_date, currency_code, units_per_eur) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE units_per_eur = VALUES(units_per_eur)'
        );
        $rate->execute([self::ECB_RATE_DATE, 'CZK', self::EUR_CZK]);
        $rate->execute([self::ECB_RATE_DATE, 'PLN', self::EUR_CZK / self::PLN_CZK]);
        $pdo->prepare(
            'INSERT INTO ecb_exchange_rate_days (rate_date, published) VALUES (?, 1)
             ON DUPLICATE KEY UPDATE published = 1'
        )->execute([self::ECB_RATE_DATE]);
    }

    /**
     * Období, které číselník členských států pokrývá pro zemi dodavatele — a tedy jediné,
     * ve kterém umí odpovědět na otázku, na které od zavedení invariantu stojí zařazení
     * KAŽDÉ položky. Testy z něj staví PŘEDPOKLADY: „doklad z 2009 je mimo pokrytí" musí
     * být tvrzení ověřené, ne domněnka o obsahu seedu.
     */
    private function codebookKnowsDomesticRatesAt(string $date): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM oss_member_state_rates
              WHERE country = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)'
        );
        $stmt->execute([$this->domesticCountry(), $date, $date]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Tuzemsko izolovaného dodavatele — TÝŽ výraz, jakým ho čte
     * {@see \MyInvoice\Service\Oss\OssItemDeriver::domesticCountry()}. Vlastní konstanta
     * 'CZ' by testu dovolila mazat sazby jiné země, než na kterou se import ptá, a brána
     * F2 by pak „prošla" jen proto, že test mířil vedle.
     */
    private function domesticCountry(): string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(UPPER(TRIM(co.iso2)), '')
               FROM supplier s LEFT JOIN countries co ON co.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$this->supplierId]);

        return (string) $stmt->fetchColumn() ?: 'CZ';
    }

    /**
     * Vymaže z číselníku členských států sazby ZEMĚ DODAVATELE. Je to stav instalace,
     * na které neproběhla migrace 1152 (prázdná tabulka je z pohledu derivace totéž jako
     * chybějící — na každou zemi odpoví „nevím"), jen dosažitelný v transakci, kterou
     * tearDown vrátí zpět. DROP TABLE by udělal implicitní commit a zbytek sady by běžel
     * nad rozbitou databází.
     */
    private function emptyRateCodebookForSupplierCountry(): void
    {
        $this->db->pdo()->prepare('DELETE FROM oss_member_state_rates WHERE country = ?')
            ->execute([$this->domesticCountry()]);
    }

    /**
     * Klient se zakládá PŘEDEM: `ClientResolver` by pro neznámé české IČO sáhl na ARES
     * a pro cizí DIČ na VIES, takže by test závisel na dostupnosti sítě. Import je pak
     * dohledá — české podle IČ, polského spotřebitele (bez IČ i DIČ) podle názvu firmy.
     */
    private function client(string $name, string $iso2, ?string $ic = null, ?string $dic = null): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query(
            "SELECT id FROM countries WHERE UPPER(iso2) = '" . strtoupper($iso2) . "' LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát ' . $iso2 . ' není v číselníku zemí.');
        }

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, ic, dic, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, ?, ?, "Testovací 1", "Město", "11000", ?, "odberatel@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $name, $ic, $dic, $countryId, $this->czkId]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Pohoda XML tak, jak ho posílá SuperFaktura: doklad v PLN, sazba v `<inv:percentVAT>`
     * a `<inv:rateVAT>historyHigh</inv:rateVAT>` (schema Pohody zahraniční sazbu nezná).
     */
    private function pohodaForeignB2c(
        string $number,
        string $symVar = '',
        bool $creditNote = false,
        bool $zeroRate = false,
        bool $omitCountry = false,
        string $date = self::TAX_DATE,
        ?string $dateTax = self::TAX_DATE,
        string $dueDate = '2096-06-15',
        bool $omitPercent = false,
    ): string {
        $symVar = $symVar !== '' ? $symVar : $number;
        $docType = $creditNote ? 'issuedCreditNotice' : 'issuedInvoice';
        $rate = $zeroRate ? '0' : '23';
        $rateVat = $zeroRate ? 'none' : 'historyHigh';
        // Export bez země odběratele je běžná neúplnost — a zároveň vstup do nálezu,
        // kdy se derivace opře o uloženého klienta založeného s fallbackem 'CZ'.
        $countryXml = $omitCountry ? '' : '<typ:country><typ:ids>PL</typ:ids></typ:country>';
        // `null` = element chybí úplně (proforma i běžný export bez DUZP), takže se
        // efektivní datum plnění odvodí od data vystavení. V tom tvaru dorazil doklad,
        // na kterém se reprodukoval únik č. 2.
        $dateTaxXml = $dateTax !== null ? "<inv:dateTax>{$dateTax}</inv:dateTax>" : '';
        // Bez `percentVAT` nese doklad jen enum `historyHigh`, což znamená doslova „tahle
        // sazba už neplatí, skutečné procento je jinde" — sazba tedy ZNÁMÁ NENÍ (§ F6).
        $percentXml = $omitPercent ? '' : "<inv:percentVAT>{$rate}</inv:percentVAT>";

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
                          xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                          xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
                          version="2.0" ico="{$this->icoAttr()}">
              <dat:dataPackItem version="2.0">
                <inv:invoice version="2.0">
                  <inv:invoiceHeader>
                    <inv:invoiceType>{$docType}</inv:invoiceType>
                    <inv:number><typ:numberRequested>{$number}</typ:numberRequested></inv:number>
                    <inv:symVar>{$symVar}</inv:symVar>
                    <inv:date>{$date}</inv:date>
                    {$dateTaxXml}
                    <inv:dateDue>{$dueDate}</inv:dateDue>
                    <inv:classificationVAT><typ:ids>UD</typ:ids></inv:classificationVAT>
                    <inv:text>Prodej spotřebiteli do Polska</inv:text>
                    <inv:partnerIdentity>
                      <typ:address>
                        <typ:company>Testowy Odbiorca sp. z o.o.</typ:company>
                        <typ:street>Ulica 1</typ:street>
                        <typ:city>Warszawa</typ:city>
                        <typ:zip>00-001</typ:zip>
                        {$countryXml}
                      </typ:address>
                    </inv:partnerIdentity>
                  </inv:invoiceHeader>
                  <inv:invoiceDetail>
                    <inv:invoiceItem>
                      <inv:text>Zboží do Polska</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>kg</inv:unit>
                      <inv:rateVAT>{$rateVat}</inv:rateVAT>
                      {$percentXml}
                      <inv:foreignCurrency><typ:unitPrice>1000</typ:unitPrice></inv:foreignCurrency>
                    </inv:invoiceItem>
                  </inv:invoiceDetail>
                  <inv:invoiceSummary>
                    <inv:foreignCurrency>
                      <typ:currency><typ:ids>PLN</typ:ids></typ:currency>
                      <typ:rate>6</typ:rate>
                      <typ:amount>1</typ:amount>
                      <typ:priceHigh>1000</typ:priceHigh>
                      <typ:priceHighVAT rate="23">230</typ:priceHighVAT>
                    </inv:foreignCurrency>
                  </inv:invoiceSummary>
                </inv:invoice>
              </dat:dataPackItem>
            </dat:dataPack>
            XML;
    }

    /**
     * Doklad, který se rozpadne do OBOU výkazů (§ H1): jeden polský spotřebitel, dva
     * řádky — 23 % (v ČR neplatí → OSS sekce PL) a 12 % (v Polsku neplatí → ř. 1 českého
     * přiznání). Rekapitulace na položky SEDÍ, takže parser nemá co hlásit a doklad
     * projde bez jediného `file_issue`; rozpor je vidět až nad celým dokladem.
     */
    private function pohodaMixedOssAndDomestic(string $number): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
                          xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                          xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
                          version="2.0" ico="{$this->icoAttr()}">
              <dat:dataPackItem version="2.0">
                <inv:invoice version="2.0">
                  <inv:invoiceHeader>
                    <inv:invoiceType>issuedInvoice</inv:invoiceType>
                    <inv:number><typ:numberRequested>{$number}</typ:numberRequested></inv:number>
                    <inv:symVar>{$number}</inv:symVar>
                    <inv:date>2096-05-15</inv:date>
                    <inv:dateTax>2096-05-15</inv:dateTax>
                    <inv:dateDue>2096-06-15</inv:dateDue>
                    <inv:text>Prodej spotřebiteli do Polska</inv:text>
                    <inv:partnerIdentity>
                      <typ:address>
                        <typ:company>Testowy Odbiorca sp. z o.o.</typ:company>
                        <typ:street>Ulica 1</typ:street>
                        <typ:city>Warszawa</typ:city>
                        <typ:zip>00-001</typ:zip>
                        <typ:country><typ:ids>PL</typ:ids></typ:country>
                      </typ:address>
                    </inv:partnerIdentity>
                  </inv:invoiceHeader>
                  <inv:invoiceDetail>
                    <inv:invoiceItem>
                      <inv:text>Zboží do Polska</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>kg</inv:unit>
                      <inv:rateVAT>historyHigh</inv:rateVAT>
                      <inv:percentVAT>23</inv:percentVAT>
                      <inv:foreignCurrency><typ:unitPrice>1000</typ:unitPrice></inv:foreignCurrency>
                    </inv:invoiceItem>
                    <inv:invoiceItem>
                      <inv:text>Doprava</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>ks</inv:unit>
                      <inv:rateVAT>low</inv:rateVAT>
                      <inv:percentVAT>12</inv:percentVAT>
                      <inv:foreignCurrency><typ:unitPrice>1000</typ:unitPrice></inv:foreignCurrency>
                    </inv:invoiceItem>
                  </inv:invoiceDetail>
                  <inv:invoiceSummary>
                    <inv:foreignCurrency>
                      <typ:currency><typ:ids>PLN</typ:ids></typ:currency>
                      <typ:rate>6</typ:rate>
                      <typ:amount>1</typ:amount>
                      <typ:priceHigh>1000</typ:priceHigh>
                      <typ:priceHighVAT rate="23">230</typ:priceHighVAT>
                      <typ:priceLow>1000</typ:priceLow>
                      <typ:priceLowVAT rate="12">120</typ:priceLowVAT>
                    </inv:foreignCurrency>
                  </inv:invoiceSummary>
                </inv:invoice>
              </dat:dataPackItem>
            </dat:dataPack>
            XML;
    }

    /**
     * Běžný tuzemský doklad — kontrolní vzorek, že se oprava nedotkla normálního provozu.
     *
     * Parametry protistrany jsou volitelné, aby šel týž doklad poslat i na zahraničního
     * spotřebitele: doklad v korunách s českou sazbou 21 % je přesně ten tvar, u kterého
     * sazba místo plnění NEURČUJE (21 % platí v NL, BE, ES, LT i LV shodně s ČR).
     */
    private function pohodaDomestic(
        string $number,
        string $symVar = '',
        string $company = self::CZ_CUSTOMER,
        string $ico = '25596641',
        string $countryIso2 = 'CZ',
        string $date = self::TAX_DATE,
        string $dateTax = self::TAX_DATE,
        string $dueDate = '2096-06-15',
    ): string {
        $symVar = $symVar !== '' ? $symVar : $number;
        $icoXml = $ico !== '' ? "<typ:ico>{$ico}</typ:ico>" : '';

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
                          xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                          xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
                          version="2.0" ico="{$this->icoAttr()}">
              <dat:dataPackItem version="2.0">
                <inv:invoice version="2.0">
                  <inv:invoiceHeader>
                    <inv:invoiceType>issuedInvoice</inv:invoiceType>
                    <inv:number><typ:numberRequested>{$number}</typ:numberRequested></inv:number>
                    <inv:symVar>{$symVar}</inv:symVar>
                    <inv:date>{$date}</inv:date>
                    <inv:dateTax>{$dateTax}</inv:dateTax>
                    <inv:dateDue>{$dueDate}</inv:dateDue>
                    <inv:text>Tuzemské plnění</inv:text>
                    <inv:partnerIdentity>
                      <typ:address>
                        <typ:company>{$company}</typ:company>
                        {$icoXml}
                        <typ:street>Testovací 1</typ:street>
                        <typ:city>Praha</typ:city>
                        <typ:zip>11000</typ:zip>
                        <typ:country><typ:ids>{$countryIso2}</typ:ids></typ:country>
                      </typ:address>
                    </inv:partnerIdentity>
                  </inv:invoiceHeader>
                  <inv:invoiceDetail>
                    <inv:invoiceItem>
                      <inv:text>Konzultace</inv:text>
                      <inv:quantity>1</inv:quantity>
                      <inv:unit>ks</inv:unit>
                      <inv:rateVAT>high</inv:rateVAT>
                      <inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice></inv:homeCurrency>
                    </inv:invoiceItem>
                  </inv:invoiceDetail>
                  <inv:invoiceSummary>
                    <inv:homeCurrency>
                      <typ:priceHigh>1000</typ:priceHigh>
                      <typ:priceHighVAT rate="21">210</typ:priceHighVAT>
                    </inv:homeCurrency>
                  </inv:invoiceSummary>
                </inv:invoice>
              </dat:dataPackItem>
            </dat:dataPack>
            XML;
    }

    /**
     * Tuzemský dobropis vyexportovaný SPRÁVNĚ — záporné množství, kladná cena. Přesně
     * tvar, který systém vede sám ({@see \MyInvoice\Action\Invoice\CancelInvoiceAction}),
     * a který import nesmí přeznačkovat.
     */
    private function pohodaDomesticCreditNote(string $number): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
                          xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd"
                          xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
                          version="2.0" ico="{$this->icoAttr()}">
              <dat:dataPackItem version="2.0">
                <inv:invoice version="2.0">
                  <inv:invoiceHeader>
                    <inv:invoiceType>issuedCreditNotice</inv:invoiceType>
                    <inv:number><typ:numberRequested>{$number}</typ:numberRequested></inv:number>
                    <inv:symVar>{$number}</inv:symVar>
                    <inv:date>2096-05-15</inv:date>
                    <inv:dateTax>2096-05-15</inv:dateTax>
                    <inv:dateDue>2096-06-15</inv:dateDue>
                    <inv:text>Dobropis k tuzemskému plnění</inv:text>
                    <inv:partnerIdentity>
                      <typ:address>
                        <typ:company>Testovací odběratel s.r.o.</typ:company>
                        <typ:ico>25596641</typ:ico>
                        <typ:street>Testovací 1</typ:street>
                        <typ:city>Praha</typ:city>
                        <typ:zip>11000</typ:zip>
                        <typ:country><typ:ids>CZ</typ:ids></typ:country>
                      </typ:address>
                    </inv:partnerIdentity>
                  </inv:invoiceHeader>
                  <inv:invoiceDetail>
                    <inv:invoiceItem>
                      <inv:text>Vrácená konzultace</inv:text>
                      <inv:quantity>-1</inv:quantity>
                      <inv:unit>ks</inv:unit>
                      <inv:rateVAT>high</inv:rateVAT>
                      <inv:homeCurrency><typ:unitPrice>1000</typ:unitPrice></inv:homeCurrency>
                    </inv:invoiceItem>
                  </inv:invoiceDetail>
                  <inv:invoiceSummary>
                    <inv:homeCurrency>
                      <typ:priceHigh>-1000</typ:priceHigh>
                      <typ:priceHighVAT rate="21">-210</typ:priceHighVAT>
                    </inv:homeCurrency>
                  </inv:invoiceSummary>
                </inv:invoice>
              </dat:dataPackItem>
            </dat:dataPack>
            XML;
    }

    /**
     * Týž doklad v ISDOC 6.0.2. Procento tu nese `ClassifiedTaxCategory/Percent`,
     * cizoměnová částka `LineExtensionAmountCurr` (`UnitPrice` je dle ISDOC v Kč).
     */
    private function isdocForeignB2c(string $id): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <Invoice xmlns="http://isdoc.cz/namespace/2013" version="6.0.2">
              <DocumentType>1</DocumentType>
              <ID>{$id}</ID>
              <IssueDate>2096-05-15</IssueDate>
              <TaxPointDate>2096-05-15</TaxPointDate>
              <VATApplicable>true</VATApplicable>
              <LocalCurrencyCode>CZK</LocalCurrencyCode>
              <ForeignCurrencyCode>PLN</ForeignCurrencyCode>
              <CurrRate>6</CurrRate>
              <RefCurrRate>1</RefCurrRate>
              <AccountingSupplierParty>
                <Party>
                  <PartyIdentification><ID>{$this->icoAttr()}</ID></PartyIdentification>
                  <PartyName><Name>Testovací dodavatel s.r.o.</Name></PartyName>
                  <PostalAddress>
                    <StreetName>Testovací</StreetName>
                    <BuildingNumber>1</BuildingNumber>
                    <CityName>Praha</CityName>
                    <PostalZone>11000</PostalZone>
                    <Country><IdentificationCode>CZ</IdentificationCode><Name>Česká republika</Name></Country>
                  </PostalAddress>
                </Party>
              </AccountingSupplierParty>
              <AccountingCustomerParty>
                <Party>
                  <PartyName><Name>Testowy Odbiorca sp. z o.o.</Name></PartyName>
                  <PostalAddress>
                    <StreetName>Ulica</StreetName>
                    <BuildingNumber>1</BuildingNumber>
                    <CityName>Warszawa</CityName>
                    <PostalZone>00-001</PostalZone>
                    <Country><IdentificationCode>PL</IdentificationCode><Name>Polsko</Name></Country>
                  </PostalAddress>
                </Party>
              </AccountingCustomerParty>
              <InvoiceLines>
                <InvoiceLine>
                  <ID>1</ID>
                  <InvoicedQuantity unitCode="kg">1</InvoicedQuantity>
                  <LineExtensionAmount>6000</LineExtensionAmount>
                  <LineExtensionAmountCurr>1000</LineExtensionAmountCurr>
                  <UnitPrice>6000</UnitPrice>
                  <ClassifiedTaxCategory>
                    <Percent>23</Percent>
                    <VATApplicable>true</VATApplicable>
                  </ClassifiedTaxCategory>
                  <Item><Description>Zboží do Polska</Description></Item>
                </InvoiceLine>
              </InvoiceLines>
              <TaxTotal>
                <TaxSubTotal>
                  <TaxableAmount>6000</TaxableAmount>
                  <TaxAmount>1380</TaxAmount>
                  <TaxableAmountCurr>1000</TaxableAmountCurr>
                  <TaxAmountCurr>230</TaxAmountCurr>
                  <TaxCategory><Percent>23</Percent></TaxCategory>
                </TaxSubTotal>
              </TaxTotal>
              <LegalMonetaryTotal>
                <PayableAmount>7380</PayableAmount>
                <PayableAmountCurr>1230</PayableAmountCurr>
              </LegalMonetaryTotal>
              <PaymentMeans>
                <Payment>
                  <Details><PaymentDueDate>2096-06-15</PaymentDueDate></Details>
                </Payment>
              </PaymentMeans>
            </Invoice>
            XML;
    }

    private function icoAttr(): string
    {
        return self::SUPPLIER_IC;
    }
}
