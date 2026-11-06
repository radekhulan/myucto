<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Export\PohodaXmlExporter;
use PHPUnit\Framework\TestCase;

/**
 * Validuje výstup PohodaXmlExporter::buildXml() proti oficiálnímu Stormware XSD
 * (api/xsd/pohoda/invoice.xsd, ze stormware.cz/xml/schema/all_schema_ver2.zip).
 *
 * Validuje se vnitřní `<inv:invoice>` element (invoice.xsd deklaruje globální element
 * `invoice`), vytažený z `<dat:dataPack>`. Obálku dataPack/dataPackItem ověřujeme
 * strukturálně (žádný data.xsd — ten by si vynutil celý graf ~75 agend; pro fakturu
 * stačí uzávěr invoice.xsd: type/documentresponse/print/filter).
 *
 * Pokrývá: vydané i přijaté faktury (směr přes cfg['direction']), invoiceType
 * mapping (received* vs issued*), partnerIdentity = protistrana (odběratel vs
 * dodavatel), summary currency bloky (homeCurrency bez priceSum, round jako
 * typeRound choice; foreignCurrency jen currency/rate/amount/priceSum) a regresní
 * pojistku proti zanořenému dataPacku v bulk balíčku.
 */
final class PohodaExporterSchemaTest extends TestCase
{
    private const XSD = __DIR__ . '/../../../../xsd/pohoda/invoice.xsd';

    private const NS_DAT = 'http://www.stormware.cz/schema/version_2/data.xsd';
    private const NS_INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
    private const NS_TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';

    private PohodaXmlExporter $exporter;

    protected function setUp(): void
    {
        if (!is_file(self::XSD)) {
            self::markTestSkipped('Pohoda XSD chybí — rozbal all_schema_ver2.zip do api/xsd/pohoda.');
        }

        $tax = $this->createStub(TaxConstantsRepository::class);
        $tax->method('vatBucketThreshold')->willReturn(20.5);

        // buildXml() pracuje čistě nad polem; resolve*/dataResolver sahá na DB jen
        // pro supplier_id/client_id > 0. Faktury níže nesou jen snapshoty, takže
        // stuby repo/db nejsou nikdy zavolané.
        $this->exporter = new PohodaXmlExporter(
            $this->createStub(InvoiceRepository::class),
            $this->createStub(Connection::class),
            $tax,
        );
    }

    // ─── Vydané faktury (issued) ───

    public function testIssuedCzkInvoiceIsSchemaValid(): void
    {
        $this->assertValidPohoda($this->exporter->buildXml([$this->issuedInvoice()], $this->issuedCfg()));
    }

    public function testIssuedInvoiceTypeAndPartnerIsClient(): void
    {
        $xml = $this->exporter->buildXml([$this->issuedInvoice()], $this->issuedCfg());

        self::assertSame('issuedInvoice', $this->xpathOne($xml, '//inv:invoiceHeader/inv:invoiceType'));
        // partnerIdentity vydané faktury nese ODBĚRATELE.
        self::assertSame('Odběratel a.s.', $this->xpathOne(
            $xml, '//inv:invoiceHeader/inv:partnerIdentity/typ:address/typ:company'));
    }

    /**
     * Zjednodušený daňový doklad je v myúčtu běžná faktura (invoice_type = invoice) s příznakem
     * is_simplified a vlastní číselnou řadou. Exportér ho nerozlišuje: vyjde jako issuedInvoice
     * s číslem z vlastní řady. Dokladům bez odběratele (nebo s prázdným snapshotem) vyjde
     * partnerIdentity s prázdnými poli, což XSD akceptuje.
     */
    public function testSimplifiedDocumentWithOwnSeriesIsSchemaValid(): void
    {
        $inv = $this->issuedInvoice(['is_simplified' => true, 'hide_customer_on_pdf' => true, 'varsymbol' => 'ZD2026-0007']);
        $xml = $this->exporter->buildXml([$inv], $this->issuedCfg());
        $this->assertValidPohoda($xml);
        self::assertSame('issuedInvoice', $this->xpathOne($xml, '//inv:invoiceHeader/inv:invoiceType'));
        self::assertSame('ZD2026-0007', $this->xpathOne($xml, '//inv:invoiceHeader/inv:number/typ:numberRequested'));
    }

    public function testSimplifiedDocumentWithoutCustomerIsSchemaValid(): void
    {
        $inv = $this->issuedInvoice(['is_simplified' => true, 'varsymbol' => 'ZD2026-0008', 'client_snapshot' => []]);
        $xml = $this->exporter->buildXml([$inv], $this->issuedCfg());
        $this->assertValidPohoda($xml);
        self::assertSame('ZD2026-0008', $this->xpathOne($xml, '//inv:invoiceHeader/inv:number/typ:numberRequested'));
    }

    public function testIssuedCreditNoteAndProformaTypesAreValid(): void
    {
        $credit = $this->exporter->buildXml([$this->issuedInvoice(['invoice_type' => 'credit_note'])], $this->issuedCfg());
        $this->assertValidPohoda($credit);
        self::assertSame('issuedCreditNotice', $this->xpathOne($credit, '//inv:invoiceType'));

        $proforma = $this->exporter->buildXml([$this->issuedInvoice(['invoice_type' => 'proforma'])], $this->issuedCfg());
        $this->assertValidPohoda($proforma);
        self::assertSame('issuedAdvanceInvoice', $this->xpathOne($proforma, '//inv:invoiceType'));
    }

    public function testIssuedTaxDocumentMapsToIssuedInvoiceNotInvalidEnum(): void
    {
        // „issuedTaxDocument" v invoiceTypeType NEEXISTUJE — daňový doklad k přijaté
        // platbě se exportuje jako issuedInvoice a musí projít XSD.
        $xml = $this->exporter->buildXml([$this->issuedInvoice(['invoice_type' => 'tax_document'])], $this->issuedCfg());
        $this->assertValidPohoda($xml);
        self::assertSame('issuedInvoice', $this->xpathOne($xml, '//inv:invoiceType'));
    }

    public function testIssuedForeignCurrencyInvoiceIsSchemaValid(): void
    {
        // foreignCurrency blok smí nést jen currency/rate/amount/priceSum (žádné per-sazbové buckety).
        $xml = $this->exporter->buildXml([$this->issuedInvoice([
            'currency'      => 'EUR',
            'exchange_rate' => 24.36,
        ])], $this->issuedCfg());

        $this->assertValidPohoda($xml);
        self::assertSame('EUR', $this->xpathOne($xml, '//inv:invoiceSummary/inv:foreignCurrency/typ:currency/typ:ids'));
        self::assertNull($this->xpathOne($xml, '//inv:foreignCurrency/typ:priceHigh'), 'foreignCurrency nesmí nést buckety');
    }

    public function testMinuteDurationKeepsHourlyUnitAndPreciseRate(): void
    {
        $invoice = $this->issuedInvoice([
            'items' => [$this->item([
                'duration_minutes' => 1,
                'quantity' => 0.017,
                'unit' => 'h',
                'unit_price_without_vat' => 1000.123456,
                'total_without_vat' => 16.67,
                'total_vat' => 3.50,
                'total_with_vat' => 20.17,
            ])],
            'vat_breakdown' => [['rate' => 21.0, 'base' => 16.67, 'vat' => 3.50]],
            'totals' => ['without_vat' => 16.67, 'with_vat' => 20.17, 'rounding' => 0.0],
        ]);
        $xml = $this->exporter->buildXml([$invoice], $this->issuedCfg());

        $this->assertValidPohoda($xml);
        self::assertSame('0.016666666667', $this->xpathOne($xml, '//inv:invoiceItem/inv:quantity'));
        self::assertSame('h', $this->xpathOne($xml, '//inv:invoiceItem/inv:unit'));
        self::assertSame('1000.123456', $this->xpathOne($xml, '//inv:invoiceItem/inv:homeCurrency/typ:unitPrice'));
        self::assertSame('16.67', $this->xpathOne($xml, '//inv:invoiceItem/inv:homeCurrency/typ:price'));
    }

    public function testRoundingEmitsTypeRoundChoiceNotBareValue(): void
    {
        // homeCurrency nemá priceSum; round je typ:typeRound (choice) → musí obalit priceRound.
        $xml = $this->exporter->buildXml([$this->issuedInvoice([
            'totals' => ['without_vat' => 2520.0, 'with_vat' => 3049.0, 'rounding' => -0.2],
        ])], $this->issuedCfg());

        $this->assertValidPohoda($xml);
        self::assertSame('-0.20', $this->xpathOne($xml, '//inv:invoiceSummary/inv:homeCurrency/typ:round/typ:priceRound'));
        // POZOR: //inv:homeCurrency by chytlo i položkový blok (ten priceSum MÁ) — scope na summary.
        self::assertNull($this->xpathOne($xml, '//inv:invoiceSummary/inv:homeCurrency/typ:priceSum'),
            'summary homeCurrency nemá priceSum');
    }

    public function testItemPercentVatIsSchemaValidAndSitsRightAfterRateVat(): void
    {
        // invoice.xsd má u položky sekvenci payVAT → rateVAT → percentVAT →
        // discountPercentage; při jiném pořadí je doklad nevalidní, i když jsou
        // všechny elementy přítomné.
        $xml = $this->exporter->buildXml([$this->issuedInvoice()], $this->issuedCfg());

        $this->assertValidPohoda($xml);
        self::assertSame('high', $this->xpathOne($xml, '//inv:invoiceItem/inv:rateVAT'));
        self::assertSame('21.00', $this->xpathOne($xml, '//inv:invoiceItem/inv:percentVAT'));
    }

    public function testThirdRateItemIsSchemaValidBecauseLow2IsNotInTheEnum(): void
    {
        // `typ:vatRateEnum` = {none, high, low, third, historyHigh, historyLow,
        // historyThird}. Dřív se pro 10 % emitovalo `low2` — na tom Pohoda odmítne
        // CELÝ dataPack, a žádný test to nechytal, protože 10% položku nikdo neexportoval.
        $xml = $this->exporter->buildXml([$this->issuedInvoice([
            'issue_date'    => '2023-05-04',
            'tax_date'      => '2023-05-04',
            'items'         => [$this->item([
                'vat_rate_snapshot' => 10.0,
                'total_vat'         => 252.0,
                'total_with_vat'    => 2772.0,
            ])],
            'vat_breakdown' => [['rate' => 10.0, 'base' => 2520.0, 'vat' => 252.0]],
            'totals'        => ['without_vat' => 2520.0, 'with_vat' => 2772.0, 'rounding' => 0.0],
        ])], $this->issuedCfg());

        $this->assertValidPohoda($xml);
        self::assertSame('third', $this->xpathOne($xml, '//inv:invoiceItem/inv:rateVAT'));
        self::assertSame('10.00', $this->xpathOne($xml, '//inv:invoiceItem/inv:percentVAT'));
        // Daň 3. sazby se dostane do rekapitulace (dřív tam byly natvrdo nuly).
        self::assertSame('252.00', $this->xpathOne(
            $xml, '//inv:invoiceSummary/inv:homeCurrency/typ:price3VAT'));
        // classificationVAT bez `typ:ids` musí zůstat validní vůči XSD.
        self::assertSame('inland', $this->xpathOne(
            $xml, '//inv:invoiceHeader/inv:classificationVAT/typ:classificationVATType'));
    }

    public function testInlandClassificationLetsPohodaDecideA4WhenCustomerHasCzechVatId(): void
    {
        // `UDA5` je v Pohodě „tuzemské plnění BEZ OHLEDU NA LIMIT 10 000 Kč" s natvrdo
        // předvyplněnou sekcí A.5. Posílat ho plátci znamená, že doklad nad limit skončí
        // v A.5 místo A.4 — a Pohoda si na tom nestěžuje, takže to nikdo nechytí.
        // U `UD` si sekci určí Pohoda sama podle výše dokladu.
        $xml = $this->exporter->buildXml([$this->issuedInvoice()], $this->issuedCfg());

        $this->assertValidPohoda($xml);
        self::assertSame('UD', $this->xpathOne($xml, '//inv:invoiceHeader/inv:classificationVAT/typ:ids'));

        // Totéž pro sníženou sazbu: rozhoduje DIČ, ne sazba (UDA5_12 by opět vnutil A.5).
        $low = $this->exporter->buildXml([$this->issuedInvoice([
            'items'         => [$this->item(['vat_rate_snapshot' => 12.0, 'total_vat' => 302.4, 'total_with_vat' => 2822.4])],
            'vat_breakdown' => [['rate' => 12.0, 'base' => 2520.0, 'vat' => 302.4]],
            'totals'        => ['without_vat' => 2520.0, 'with_vat' => 2822.4, 'rounding' => 0.0],
        ])], $this->issuedCfg());
        self::assertSame('UD', $this->xpathOne($low, '//inv:invoiceHeader/inv:classificationVAT/typ:ids'));
    }

    public function testInlandClassificationForcesA5OnlyWithoutCzechVatId(): void
    {
        // Bez českého DIČ plnění do A.5 skutečně patří bez ohledu na limit — tam UDA5
        // (resp. UDA5_12 u snížené sazby) zůstává správně.
        $noDic = $this->issuedInvoice();
        $noDic['client_snapshot']['dic'] = null;
        $xml = $this->exporter->buildXml([$noDic], $this->issuedCfg());

        $this->assertValidPohoda($xml);
        self::assertSame('UDA5', $this->xpathOne($xml, '//inv:invoiceHeader/inv:classificationVAT/typ:ids'));

        // Slovenské DIČ není české DIČ, i když je klient „firma s DIČ".
        $foreign = $this->issuedInvoice();
        $foreign['client_snapshot']['dic'] = 'SK2020123456';
        $foreign['client_snapshot']['country_iso2'] = 'SK';
        self::assertSame('UDA5', $this->xpathOne(
            $this->exporter->buildXml([$foreign], $this->issuedCfg()),
            '//inv:invoiceHeader/inv:classificationVAT/typ:ids',
        ));

        // Snížená sazba bez DIČ → UDA5_12.
        $lowNoDic = $this->issuedInvoice([
            'items'         => [$this->item(['vat_rate_snapshot' => 12.0, 'total_vat' => 302.4, 'total_with_vat' => 2822.4])],
            'vat_breakdown' => [['rate' => 12.0, 'base' => 2520.0, 'vat' => 302.4]],
            'totals'        => ['without_vat' => 2520.0, 'with_vat' => 2822.4, 'rounding' => 0.0],
        ]);
        $lowNoDic['client_snapshot']['dic'] = null;
        self::assertSame('UDA5_12', $this->xpathOne(
            $this->exporter->buildXml([$lowNoDic], $this->issuedCfg()),
            '//inv:invoiceHeader/inv:classificationVAT/typ:ids',
        ));
    }

    public function testAccountingCodeIsExportedAsPredkontace(): void
    {
        // Bez `<inv:accounting>` si Pohoda po importu dosadí předkontaci z nastavení cílové
        // instalace — pronájem i služby naskočí jako prodej zboží. Element patří v sekvenci
        // hlavičky PŘED classificationVAT, jinak dataPack neprojde XSD.
        $xml = $this->exporter->buildXml(
            [$this->issuedInvoice()],
            array_merge($this->issuedCfg(), ['pohoda_accounting_code' => '300']),
        );

        $this->assertValidPohoda($xml);
        self::assertSame('300', $this->xpathOne($xml, '//inv:invoiceHeader/inv:accounting/typ:ids'));

        // Nevyplněná předkontace element neposílá (původní chování = default Pohody).
        self::assertNull($this->xpathOne(
            $this->exporter->buildXml([$this->issuedInvoice()], $this->issuedCfg()),
            '//inv:invoiceHeader/inv:accounting',
        ));
    }

    public function testAccountingCodeIsExportedForProformaAndReceivedInvoiceToo(): void
    {
        // Proforma nemá classificationVAT, předkontaci ale potřebuje stejně jako ostatní
        // doklady — a přijatá faktura obzvlášť (u ní účetní přepisovala zaúčtování taky).
        $proforma = $this->exporter->buildXml(
            [$this->issuedInvoice(['invoice_type' => 'proforma'])],
            array_merge($this->issuedCfg(), ['pohoda_accounting_code' => '300']),
        );
        $this->assertValidPohoda($proforma);
        self::assertSame('300', $this->xpathOne($proforma, '//inv:invoiceHeader/inv:accounting/typ:ids'));

        $received = $this->exporter->buildXml(
            [$this->receivedInvoice()],
            array_merge($this->purchaseCfg(), ['pohoda_accounting_code' => '5xx']),
        );
        $this->assertValidPohoda($received);
        self::assertSame('5xx', $this->xpathOne($received, '//inv:invoiceHeader/inv:accounting/typ:ids'));
    }

    // ─── Přijaté faktury (purchase) ───

    public function testReceivedInvoiceIsSchemaValid(): void
    {
        $this->assertValidPohoda($this->exporter->buildXml([$this->receivedInvoice()], $this->purchaseCfg()));
    }

    public function testReceivedInvoiceTypeIsReceivedAndPartnerIsVendor(): void
    {
        $xml = $this->exporter->buildXml([$this->receivedInvoice()], $this->purchaseCfg());

        // BUG 1: invoiceType musí být receivedInvoice (ne issuedInvoice).
        self::assertSame('receivedInvoice', $this->xpathOne($xml, '//inv:invoiceHeader/inv:invoiceType'));
        // BUG 2: partnerIdentity musí nést DODAVATELE (ne nás/příjemce).
        self::assertSame('Dodavatel s.r.o.', $this->xpathOne(
            $xml, '//inv:invoiceHeader/inv:partnerIdentity/typ:address/typ:company'));
        self::assertSame('11122233', $this->xpathOne(
            $xml, '//inv:invoiceHeader/inv:partnerIdentity/typ:address/typ:ico'));
    }

    public function testPartnerAddressFieldsAreTruncatedForIssuedAndReceivedExports(): void
    {
        $partner = [
            'company_name' => str_repeat('Ž', 260),
            'street' => str_repeat('Ř', 70),
            'city' => str_repeat('Č', 50),
            'zip' => str_repeat('1', 20),
            'ic' => str_repeat('2', 20),
            'dic' => 'CZ' . str_repeat('3', 20),
            'country_iso2' => 'CZ',
            'main_email' => str_repeat('e', 100) . '@example.test',
            'phone' => str_repeat('4', 45),
        ];

        $issued = $this->issuedInvoice();
        $issued['client_snapshot'] = $partner;
        $issued['project_number'] = str_repeat('P', 40);
        $issued['items'] = [$this->item(['unit' => str_repeat('U', 15)])];
        $received = $this->receivedInvoice();
        $received['supplier_snapshot'] = $partner;
        $received['project_number'] = str_repeat('P', 40);
        $received['items'] = [$this->item(['unit' => str_repeat('U', 15)])];
        $longCfg = [
            'ic' => str_repeat('9', 20),
            'pohoda_account_code' => str_repeat('A', 25),
            'pohoda_centre_code' => str_repeat('C', 25),
            'pohoda_activity_code' => str_repeat('T', 25),
            'pohoda_contract_code' => str_repeat('K', 25),
            'pohoda_accounting_code' => str_repeat('P', 25),
        ];

        foreach ([
            $this->exporter->buildXml([$issued], array_merge($this->issuedCfg(), $longCfg)),
            $this->exporter->buildXml([$received], array_merge($this->purchaseCfg(), $longCfg)),
        ] as $xml) {
            $this->assertValidPohoda($xml);
            $fields = [
                'company' => 255,
                'street' => 64,
                'city' => 45,
                'zip' => 15,
                'ico' => 15,
                'dic' => 18,
                'email' => 98,
                'phone' => 40,
            ];
            foreach ($fields as $element => $maxLength) {
                $value = $this->xpathOne(
                    $xml,
                    "//inv:invoiceHeader/inv:partnerIdentity/typ:address/typ:{$element}",
                );
                self::assertNotNull($value);
                self::assertSame($maxLength, mb_strlen($value), "typ:{$element} musí být oříznut na limit Pohoda XSD");
            }
            self::assertSame(32, mb_strlen((string) $this->xpathOne($xml, '//inv:invoiceHeader/inv:numberOrder')));
            self::assertSame(10, mb_strlen((string) $this->xpathOne($xml, '//inv:invoiceItem/inv:unit')));
            foreach (['account', 'centre', 'activity', 'contract', 'accounting'] as $reference) {
                self::assertSame(19, mb_strlen((string) $this->xpathOne(
                    $xml,
                    "//inv:invoiceHeader/inv:{$reference}/typ:ids",
                )));
            }
        }
    }

    public function testReceivedClassificationVatHasNoOutputSideCodeButCorrectType(): void
    {
        // U přijaté faktury NEposíláme výstupní členění (UDA5…) — to je špatný směr
        // a instalace-specifický kód. Posíláme jen typ; pro 21 % tuzemsky = inland
        // (NE UNX/nonSubsume, což byl dřív příznak rozbité rekapitulace s maxRate=0).
        $xml = $this->exporter->buildXml([$this->receivedInvoice()], $this->purchaseCfg());

        self::assertNull($this->xpathOne($xml, '//inv:invoiceHeader/inv:classificationVAT/typ:ids'),
            'přijatá faktura nemá nést výstupní členění DPH (typ:ids)');
        self::assertSame('inland', $this->xpathOne(
            $xml, '//inv:invoiceHeader/inv:classificationVAT/typ:classificationVATType'));
    }

    public function testReceivedInvoiceOmitsOurDocumentNumberAndHasNumericSymVar(): void
    {
        // U přijaté faktury nevnucujeme číslo dodavatele do naší číselné řady (numberRequested),
        // a symVar je čistě číselný platební VS (max 10).
        $xml = $this->exporter->buildXml(
            [$this->receivedInvoice(['varsymbol' => 'PF-2026-0007'])], $this->purchaseCfg());

        $this->assertValidPohoda($xml);
        self::assertNull($this->xpathOne($xml, '//inv:invoiceHeader/inv:number'),
            'přijatá faktura nemá nést naše evidenční číslo (numberRequested)');
        self::assertSame('20260007', $this->xpathOne($xml, '//inv:invoiceHeader/inv:symVar'));
    }

    public function testReceivedAdvanceOmitsClassificationVat(): void
    {
        // classificationVAT se dle schématu nepoužívá u zálohové/proforma faktury.
        $xml = $this->exporter->buildXml(
            [$this->receivedInvoice(['invoice_type' => 'proforma'])], $this->purchaseCfg());

        $this->assertValidPohoda($xml);
        self::assertSame('receivedAdvanceInvoice', $this->xpathOne($xml, '//inv:invoiceType'));
        self::assertNull($this->xpathOne($xml, '//inv:invoiceHeader/inv:classificationVAT'),
            'zálohová/proforma faktura nemá nést classificationVAT');
    }

    public function testIssuedInvoiceKeepsRequestedNumberAndNumericSymVar(): void
    {
        // Vydaná faktura: NAŠE číslo do číselné řady zůstává; symVar je číselný.
        $xml = $this->exporter->buildXml(
            [$this->issuedInvoice(['varsymbol' => '2026-00042'])], $this->issuedCfg());

        self::assertSame('2026-00042', $this->xpathOne($xml, '//inv:invoiceHeader/inv:number/typ:numberRequested'));
        self::assertSame('202600042', $this->xpathOne($xml, '//inv:invoiceHeader/inv:symVar'));
    }

    public function testReceivedCreditNoteAndAdvanceTypes(): void
    {
        $credit = $this->exporter->buildXml([$this->receivedInvoice(['invoice_type' => 'credit_note'])], $this->purchaseCfg());
        $this->assertValidPohoda($credit);
        self::assertSame('receivedCreditNotice', $this->xpathOne($credit, '//inv:invoiceType'));

        $advance = $this->exporter->buildXml([$this->receivedInvoice(['invoice_type' => 'proforma'])], $this->purchaseCfg());
        $this->assertValidPohoda($advance);
        self::assertSame('receivedAdvanceInvoice', $this->xpathOne($advance, '//inv:invoiceType'));
    }

    public function testReceivedInvoiceSummaryCarriesRealTotalsNotZero(): void
    {
        // Regrese: shape přijaté faktury musí nést vat_breakdown/totals, jinak by byl
        // summary nulový. 21% z 2520 = 529,20 → priceHigh 2520, priceHighVAT 529,20.
        $xml = $this->exporter->buildXml([$this->receivedInvoice()], $this->purchaseCfg());
        self::assertSame('2520.00', $this->xpathOne($xml, '//inv:invoiceSummary/inv:homeCurrency/typ:priceHigh'));
        self::assertSame('529.20', $this->xpathOne($xml, '//inv:invoiceSummary/inv:homeCurrency/typ:priceHighVAT'));
    }

    public function testReceivedForeignInvoiceEmitsForeignBlockAndCzkHomeCurrency(): void
    {
        // Cizoměnová přijatá faktura: foreignCurrency nese měnu + kurz + celkem v EUR;
        // homeCurrency je přepočtená na CZK (kurz 25), ne cizoměnové hodnoty.
        $xml = $this->exporter->buildXml([$this->receivedInvoice([
            'currency'      => 'EUR',
            'exchange_rate' => 25.0,
        ])], $this->purchaseCfg());

        $this->assertValidPohoda($xml);
        // foreignCurrency: měna + celková částka v EUR.
        self::assertSame('EUR', $this->xpathOne(
            $xml, '//inv:invoiceSummary/inv:foreignCurrency/typ:currency/typ:ids'));
        self::assertSame('3049.20', $this->xpathOne(
            $xml, '//inv:invoiceSummary/inv:foreignCurrency/typ:priceSum'));
        // homeCurrency je v CZK = EUR × 25 (ne cizoměnových 2520/529,20).
        self::assertSame('63000.00', $this->xpathOne(
            $xml, '//inv:invoiceSummary/inv:homeCurrency/typ:priceHigh'));
        self::assertSame('13230.00', $this->xpathOne(
            $xml, '//inv:invoiceSummary/inv:homeCurrency/typ:priceHighVAT'));
    }

    // ─── Obálka dataPack (regrese: žádný zanořený dataPack) ───

    public function testBulkDataPackIsFlatWithoutNestedDataPack(): void
    {
        $xml = $this->exporter->buildXml(
            [$this->receivedInvoice(['id' => 1]), $this->receivedInvoice(['id' => 2, 'varsymbol' => 'F2'])],
            $this->purchaseCfg(),
        );
        $this->assertValidPohoda($xml);

        self::assertSame(1, $this->xpathCount($xml, '/dat:dataPack'));
        self::assertSame(2, $this->xpathCount($xml, '/dat:dataPack/dat:dataPackItem'));
        self::assertSame(2, $this->xpathCount($xml, '/dat:dataPack/dat:dataPackItem/inv:invoice'));
        // BUG 3: žádný dataPack nesmí být zanořený uvnitř dataPackItem.
        self::assertSame(0, $this->xpathCount($xml, '//dat:dataPackItem//dat:dataPack'));
    }

    // ─── Helpers ───

    /**
     * Textový řádek jde do Pohody jako textová položka: jen <inv:text>, bez množství,
     * sazby a cen. Delší text než 90 znaků (limit invoice.xsd) se rozdělí na víc
     * textových položek, aby se neztratil.
     */
    public function testTextRowIsExportedAsTextOnlyItem(): void
    {
        $long = str_repeat('Montáž na stavbě ', 7); // 119 znaků
        $xml = $this->exporter->buildXml([$this->issuedInvoice(['items' => [
            $this->item(),
            $this->item(['item_kind' => 'text', 'description' => trim($long), 'quantity' => 0.0, 'unit' => '',
                'unit_price_without_vat' => 0.0, 'vat_rate_snapshot' => 12.0,
                'total_without_vat' => 0.0, 'total_vat' => 0.0, 'total_with_vat' => 0.0]),
        ]])], $this->issuedCfg());

        $this->assertValidPohoda($xml);
        self::assertSame(3, $this->xpathCount($xml, '//inv:invoiceDetail/inv:invoiceItem'));
        self::assertSame(2, $this->xpathCount($xml, '//inv:invoiceItem[not(inv:quantity)]'));
        self::assertSame(0, $this->xpathCount($xml, '//inv:invoiceItem[not(inv:quantity)]/*[local-name() != "text"]'));
        $parts = [];
        foreach ($this->xpath($xml)->query('//inv:invoiceItem[not(inv:quantity)]/inv:text') ?: [] as $node) {
            self::assertLessThanOrEqual(90, mb_strlen($node->textContent));
            $parts[] = $node->textContent;
        }
        self::assertSame(trim($long), trim(implode(' ', array_map('trim', $parts))));
    }

    private function assertValidPohoda(string $dataPackXml): void
    {
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($dataPackXml), 'Export není well-formed XML.');

        $invoices = $dom->getElementsByTagNameNS(self::NS_INV, 'invoice');
        self::assertGreaterThan(0, $invoices->length, 'dataPack neobsahuje žádnou <inv:invoice>.');

        foreach ($invoices as $node) {
            $single = new \DOMDocument('1.0', 'UTF-8');
            $single->appendChild($single->importNode($node, true));
            $xml = (string) $single->saveXML();

            $check = new \DOMDocument();
            self::assertTrue($check->loadXML($xml));

            $prev = libxml_use_internal_errors(true);
            libxml_clear_errors();
            $ok = $check->schemaValidate(self::XSD);
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($prev);

            if (!$ok) {
                $lines = array_map(
                    static fn (\LibXMLError $e): string => sprintf('  [ř. %d] %s', $e->line, trim($e->message)),
                    $errors,
                );
                self::fail("Pohoda <inv:invoice> není validní vůči invoice.xsd:\n"
                    . implode("\n", $lines) . "\n\nXML:\n" . $xml);
            }
            self::assertTrue($ok);
        }
    }

    private function xpathOne(string $xml, string $expr): ?string
    {
        return $this->xpath($xml)->query($expr)->item(0)?->textContent;
    }

    private function xpathCount(string $xml, string $expr): int
    {
        return $this->xpath($xml)->query($expr)->length;
    }

    private function xpath(string $xml): \DOMXPath
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('dat', self::NS_DAT);
        $xp->registerNamespace('inv', self::NS_INV);
        $xp->registerNamespace('typ', self::NS_TYP);
        return $xp;
    }

    /** @return array<string,mixed> */
    private function issuedCfg(): array
    {
        return ['ic' => '01698401'];
    }

    /** @return array<string,mixed> */
    private function purchaseCfg(): array
    {
        return ['ic' => '01698401', 'direction' => 'purchase'];
    }

    /**
     * Vydaná faktura — náš tenant = dodavatel (supplier_snapshot), klient = odběratel.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function issuedInvoice(array $overrides = []): array
    {
        $base = [
            'id'                => 1,
            'invoice_type'      => 'invoice',
            'varsymbol'         => '2026001',
            'issue_date'        => '2026-05-04',
            'tax_date'          => '2026-05-04',
            'due_date'          => '2026-05-18',
            'currency'          => 'CZK',
            'exchange_rate'     => null,
            'reverse_charge'    => false,
            'project_number'    => null,
            'note_above_items'  => null,
            'supplier_snapshot' => [
                'ic' => '01698401', 'dic' => 'CZ01698401', 'company_name' => 'Dodavatel s.r.o.',
                'street' => 'Zkušební 123/4', 'city' => 'Vzorov', 'zip' => '10000',
                'country_iso2' => 'CZ', 'main_email' => 'fakturace@dodavatel.cz',
            ],
            'client_snapshot'   => [
                'ic' => '27140130', 'dic' => 'CZ27140130', 'company_name' => 'Odběratel a.s.',
                'street' => 'Václavské náměstí 1', 'city' => 'Praha 1', 'zip' => '11000', 'country_iso2' => 'CZ',
            ],
            'items'             => [$this->item()],
            'vat_breakdown'     => [['rate' => 21.0, 'base' => 2520.0, 'vat' => 529.2]],
            'totals'            => ['without_vat' => 2520.0, 'with_vat' => 3049.2, 'rounding' => 0.0],
        ];

        return array_merge($base, $overrides);
    }

    /**
     * Přijatá faktura — invertované role (jak je staví PurchaseInvoiceExportService::buildInvoiceShape):
     * supplier_snapshot = DODAVATEL (vendor), client_snapshot = MY (příjemce).
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function receivedInvoice(array $overrides = []): array
    {
        $base = [
            'id'                 => 1,
            'invoice_type'       => 'invoice',
            'varsymbol'          => '2026-VF-1',
            'issue_date'         => '2026-06-03',
            'tax_date'           => '2026-06-03',
            'due_date'           => '2026-06-17',
            'currency'           => 'CZK',
            'exchange_rate'      => null,
            'reverse_charge'     => false,
            'prices_include_vat' => false,
            'note_above_items'   => null,
            // supplier_snapshot = dodavatel (protistrana), client_snapshot = náš tenant
            'supplier_snapshot'  => [
                'ic' => '11122233', 'dic' => 'CZ11122233', 'company_name' => 'Dodavatel s.r.o.',
                'street' => 'Dlouhá 5', 'city' => 'Brno', 'zip' => '60200',
                'country_iso2' => 'CZ', 'main_email' => 'fakturace@dodavatel.cz',
            ],
            'client_snapshot'    => [
                'ic' => '01698401', 'dic' => 'CZ01698401', 'company_name' => 'Naše firma s.r.o.',
                'street' => 'Zkušební 123/4', 'city' => 'Vzorov', 'zip' => '10000', 'country_iso2' => 'CZ',
            ],
            'items'              => [$this->item()],
            'vat_breakdown'      => [['rate' => 21.0, 'base' => 2520.0, 'vat' => 529.2]],
            'totals'             => ['without_vat' => 2520.0, 'with_vat' => 3049.2, 'rounding' => 0.0],
            '_direction'         => 'purchase',
        ];

        return array_merge($base, $overrides);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'description'            => 'Vývoj systému',
            'quantity'               => 1.0,
            'unit'                   => 'ks',
            'unit_price_without_vat' => 2520.0,
            'vat_rate_snapshot'      => 21.0,
            'total_without_vat'      => 2520.0,
            'total_vat'              => 529.2,
            'total_with_vat'         => 3049.2,
        ], $overrides);
    }
}
