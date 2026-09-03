<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

/**
 * Parser Pohoda XML data package — extrahuje faktury do normalizovaného array.
 *
 * Vrací {supplier_ic, invoices[]} — supplier IČO je z root@ico, faktury z dataPackItem.
 *
 * Podporuje dva tvary souboru:
 *   1. import data package — root `<dat:dataPack>`, faktura element `<inv:invoice>`
 *      (to, co PohodaXmlExporter zapisuje).
 *   2. export response package — root `<rsp:responsePack>` s `<lst:listInvoice>` a
 *      fakturami v `<lst:invoice>` (uživatelský export z Pohody, např. VydFaktury.xml).
 *      Hlavička/detail/summary uvnitř jsou v `inv:` namespace stejně jako u importu.
 *
 * Mapuje zpět to, co PohodaXmlExporter zapisuje. Robustní vůči chybějícím elementům.
 *
 * Output shape per invoice:
 *   [
 *     'invoice_type'          => 'invoice'|'proforma'|'credit_note',
 *     'direction'             => 'issued'|'purchase'|null,  // SMĚR určený souborem

 *     'varsymbol'             => string,
 *     'varsymbol_source'      => 'symVar'|'number'|'number_sanitized',
 *     'varsymbol_original'    => string|null,
 *     'varsymbol_substituted' => bool,
 *     'document_number'       => string|null,   // z inv:number/typ:numberRequested
 *     'original_document_number' => string|null, // číslo OPRAVOVANÉHO dokladu (opravný daň. doklad)
 *     'issue_date'            => 'Y-m-d',
 *     'tax_date'              => 'Y-m-d'|null,
 *     'due_date'              => 'Y-m-d',
 *     'currency'              => 'CZK'|'EUR'|...,
 *     'exchange_rate'         => float|null,    // VŽDY za 1 jednotku měny
 *     'exchange_rate_amount'  => int|null,      // deklarovaný <typ:amount>, jen pro report
 *     'reverse_charge'        => bool,
 *     'note_above'            => string|null,
 *     'project_number'        => string|null,   // z inv:numberOrder
 *     'client'                => [company_name, ic, dic, street, city, zip, country_iso2, email, phone],
 *     'supplier'              => tentýž tvar — DODAVATEL dokladu
 *     'items'                 => [[description, quantity, unit, unit_price_without_vat,
 *                                  unit_price_with_vat, vat_rate, vat_rate_source, vat_rate_enum,
 *                                  vat_rate_level, prices_included_vat], ...],
 *     'items_source'          => 'detail'|'summary_recap',
 *     'vat_recap'             => ['21.00' => ['base' => float, 'vat' => float], ...],
 *     'file_issues'           => list<string>,
 *   ]
 *
 * `direction` je SMĚR, který určuje sám soubor přes `<inv:invoiceType>` — viz
 * {@see self::DOCUMENT_TYPES}. Pohoda vyváží přijaté i vydané doklady do TÉHOŽ tvaru
 * a `root@ico` je u obou IČO exportující firmy, takže bez tohohle klíče nejde přijatou
 * fakturu od vydané rozeznat jinak než dohadem. `null` = soubor směr neurčuje (neznámý
 * nebo chybějící typ) a rozhodnout musí až importní vrstva podle IČO stran.
 *
 * `client` je vždy ODBĚRATEL a `supplier` vždy DODAVATEL, bez ohledu na směr; do těchhle
 * rolí se `<inv:partnerIdentity>` (protistrana) a `<inv:myIdentity>` (exportující firma)
 * rozdělí právě podle `direction`.
 *
 * `items_source` = `'summary_recap'` znamená, že doklad v souboru NEMĚL rozpis položek
 * a řádky jsou dopočtené z jeho vlastní rekapitulace ({@see self::itemsFromSummary()}).
 * Částky sedí, ale POPIS řádku je odvozený z hlavičky dokladu — volající to má uvést
 * v reportu, protože rozpis se od originálu liší.
 *
 * `varsymbol_source` říká, odkud varsymbol pochází, a `varsymbol_original` nese
 * původní `<inv:symVar>`, pokud se musel zahodit kvůli tvaru (viz
 * {@see self::VARSYMBOL_PATTERN}). Jiný zdroj než `symVar` znamená, že import
 * má náhradu uvést v reportu — doklad se totiž pod původním symbolem už nenajde.
 * `varsymbol_original` je `null`, když nebylo co nahradit (prázdný `symVar`).
 *
 * `original_document_number` nese číslo dokladu, který tenhle doklad OPRAVUJE — bez něj
 * by naimportovaný dobropis visel v systému bez vazby na opravovanou fakturu. Čte se
 * z `<inv:correctiveDocument>/<typ:sourceDocument>/<typ:number>` s fallbackem na
 * `<inv:originalDocumentNumber>`. Podobně pojmenovaný `<inv:originalDocument>` se
 * ZÁMĚRNĚ nečte: u řádné faktury do něj SuperFaktura zapisuje její VLASTNÍ číslo,
 * takže by z každé faktury udělal odkaz sám na sebe.
 *
 * `varsymbol_substituted` = varsymbol NEPOCHÁZÍ z `<inv:symVar>`, dosadili jsme ho my
 * z čísla dokladu. Pro kontrolu duplicity v importu je to tvrdý rozdíl: shoda VS
 * u NEdosazeného symbolu znamená „tenhle doklad už v DB je", kdežto u dosazeného
 * může jít o CIZÍ doklad, jehož skutečný VS se náhodou shoduje s číslem dokladu,
 * které jsme právě dosadili za GUID. Import proto dosazený VS nesmí zahodit jako
 * prostou duplicitu — musí to odlišit hláškou a nabídnout `varsymbol_original`
 * a `document_number` k dohledání. `varsymbol_substituted` je `true` i tam, kde je
 * `varsymbol_original` `null` (prázdný `symVar` — nahrazovat nebylo co, dosazovat ano).
 *
 * `exchange_rate` je vždy přepočtený na JEDNU jednotku měny; `<typ:rate>` ze souboru
 * platí pro `<typ:amount>` jednotek (viz {@see self::parseForeignCurrency()}).
 *
 * `vat_rate` položky i klíče `vat_recap` jsou SKUTEČNÁ procenta, ne české sazbové
 * úrovně Pohody — u dokladu v režimu OSS tam tedy může být třeba 23.00.
 *
 * ── Procento se NEVYMÝŠLÍ, ale dá se SPOČÍTAT ───────────────────────────────────────
 * `vat_rate` nese jedině sazbu, kterou SOUBOR SÁM URČUJE, a jinak `null`. Pořadí zdrojů
 * pravdy ({@see self::itemVatRate()}):
 *   1. `<inv:percentVAT>` na položce — skutečné procento, nejsilnější;
 *   2. `rateVAT/@value` — exportní atribut se skutečným procentem;
 *   3. DOPOČET Z REKAPITULACE — odpovídající přihrádka `<invoiceSummary>` nese základ
 *      i daň, takže procento = daň / základ. To není hádání, ale aritmetika ze STEJNÉHO
 *      souboru, a pokrývá běžný tuzemský export z Pohody, který `percentVAT` nepíše;
 *   4. enum `none`/`nonSubsume` a chybějící `<inv:rateVAT>` — prohlášení o plnění BEZ
 *      daně, tedy 0.0.
 * Cokoli dalšího — `high`/`low`/`low2`/`third` bez procenta i bez použitelné přihrádky,
 * `history*`, neznámý kód — je `null`. Enum je totiž jen ČESKÁ SAZBOVÁ ÚROVEŇ, ne
 * procento: dosadit za `high` aktuálních 21 % je dohad, který přeshraniční plnění se
 * sazbou 23 % pošle přes kvadrant „platí jen v tuzemsku" na ř. 1 českého přiznání, a to
 * bez jediného varování. Zbylé dva vstupy pro dosazení — zemi dodavatele a číselník
 * sazeb členských států k datu plnění — parser NEMÁ, takže rozhodnout musí až importní
 * vrstva; `vat_rate_enum` a `vat_rate_level` jí k tomu dávají syrový podklad.
 *
 * Volající MUSÍ `null` ošetřit jako tvrdou chybu dokladu, dokud sazbu nedoplní z vlastní
 * znalosti; tichý přetyp `(float) null` = 0.0 je tentýž únik jinou cestou — jen z cizí
 * daně udělá osvobozené plnění, které invariant proti úniku vůbec neprověřuje.
 *
 * `vat_rate_source` říká, ODKUD sazba je (`percent`, `rate_attribute`, `summary_recap`,
 * `exempt_enum`, `no_rate_element`, `unresolved`), `vat_rate_enum` nese syrový
 * `<inv:rateVAT>` a `vat_rate_level` jeho normalizovanou úroveň (`standard`, `reduced`,
 * `second_reduced`) — schválně TÝMIŽ hodnotami, jaké má `oss_member_state_rates.rate_type`,
 * aby se úroveň dala rovnou položit číselníku jako dotaz „jaké procento má tahle úroveň
 * v zemi dodavatele k datu plnění".
 *
 * `unit_price_with_vat` není `null` jen tehdy, když byl doklad v cenách S DPH a netto
 * cenu nešlo vzít z `typ:price`, takže se musela dělit koeficientem sazby — kterou ovšem
 * soubor neurčuje. `unit_price_without_vat` je pak PROVIZORNÍ a volající ho musí po
 * rozhodnutí o sazbě přepočítat jako `unit_price_with_vat / (1 + sazba/100)`.
 *
 * `file_issues` nese vady, kde si soubor ODPORUJE SÁM SE SEBOU (sazby a základy na
 * položkách vs. rekapitulace dokladu). Tichý průchod sebeodporujícího souboru je vada
 * sama o sobě — volající je má vypsat do reportu jako varování dokladu.
 *
 * Chybějící faktura se v listu objeví jako `['__error' => string]`.
 */
final class PohodaXmlParser
{
    private const NS_DAT = 'http://www.stormware.cz/schema/version_2/data.xsd';
    private const NS_INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';
    private const NS_TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';
    private const NS_LST = 'http://www.stormware.cz/schema/version_2/list.xsd';

    /**
     * Tvar variabilního symbolu, který import přijme: A–Z, a–z, 0–9, `_`, `-`,
     * max 20 znaků (= limit DB sloupce). Omezení je bezpečnostní — varsymbol
     * protéká do e-mailových šablon, názvů PDF/ZIP a buněk CSV.
     *
     * Jediné místo, kde pravidlo žije. Parser podle něj pozná, kdy sáhnout po
     * náhradě, a `InvoiceImportService::processOne()` podle něj validuje —
     * VŽDY přes {@see self::isAcceptableVarsymbol()}, nikdy vlastní kopií
     * regexu: dvě kopie by se rozešly a parser by nabízel náhradu za hodnoty,
     * které import ve skutečnosti bere (nebo naopak).
     */
    public const VARSYMBOL_PATTERN = '/^[A-Za-z0-9_-]{1,20}$/';

    /** Odchylka, do které se dopočtené procento sazby považuje za shodné (haléřové zaokrouhlení). */
    private const RATE_MATCH_TOLERANCE = 0.3;

    /** Odchylka, do které se dvě částky považují za tutéž (haléřové zaokrouhlení producenta). */
    private const PRICE_MATCH_TOLERANCE = 0.02;

    /**
     * Odchylka křížové kontroly položek proti rekapitulaci: absolutní podlaha a relativní
     * složka. Relativní část je tu kvůli slevám a přepočtu z cen s DPH, které do součtu
     * zanášejí haléře na každém řádku — bez ní by dvacetipoložková faktura hlásila rozpor
     * pokaždé. Sebeodporující soubor se přitom liší o celé procentní body, ne o haléře.
     */
    private const RECAP_ABS_TOLERANCE = 0.05;
    private const RECAP_REL_TOLERANCE = 0.005;

    /**
     * Enum `typ:vatRateEnum` → sazbová ÚROVEŇ. Hodnoty jsou schválně tytéž jako
     * `oss_member_state_rates.rate_type` ({@see \MyInvoice\Service\Oss\OssItemDecision::RATE_TYPES}),
     * aby se úroveň dala číselníku položit rovnou jako dotaz na procento v zemi dodavatele
     * k datu plnění. `low2` do XSD enumu nepatří, ale zapisuje ho náš vlastní
     * PohodaXmlExporter (round-trip vlastního souboru); `third` je dle XSD slovenská
     * 3. sazba, která v českých souborech odpovídá 3. přihrádce rekapitulace.
     *
     * `history*` tu ZÁMĚRNĚ nejsou: znamenají doslova „tahle sazba už neplatí", takže
     * úroveň neurčují ani k datu plnění.
     */
    private const ENUM_LEVELS = [
        'high'  => 'standard',
        'low'   => 'reduced',
        'low2'  => 'second_reduced',
        'third' => 'second_reduced',
    ];

    /** Sazbová úroveň → přihrádka rekapitulace, ze které se dá procento dopočítat. */
    private const LEVEL_BUCKETS = [
        'standard'       => 'High',
        'reduced'        => 'Low',
        'second_reduced' => '3',
    ];

    /** Přihrádky rekapitulace a jejich VÝCHOZÍ české procento (jen jako kotva a fallback). */
    private const RECAP_BUCKETS = ['High' => 21.0, 'Low' => 12.0, '3' => 10.0];

    /**
     * `<inv:invoiceType>` → [SMĚR dokladu, náš druh dokladu]. Jediná tabulka, ze které se
     * čte obojí — směr i druh jsou na téže hodnotě a dvě tabulky by se rozešly.
     *
     * SMĚR je tu to podstatné a dřív úplně chyběl. Pohoda vyváží přijaté i vydané doklady
     * do TÉHOŽ tvaru souboru a rozlišuje je JEN tímhle elementem: `root@ico` je v obou
     * případech IČO firmy, která export pořídila, a `<inv:partnerIdentity>` je v obou
     * případech PROTISTRANA. Dokud se směr nečetl, mířila přijatá faktura do importu jako
     * vydaná (protistrana v roli odběratele) — buď spadla na cross-tenant guardu
     * („patří jinému plátci"), nebo se při `kind=auto` TICHE založila jako vydaná faktura,
     * což je horší: obrátí stranu evidence DPH a přiznání.
     *
     * `issuedCorrectiveTax` / `receivedCorrectiveTax` je OPRAVNÝ DAŇOVÝ DOKLAD podle § 42
     * ZDPH — to, čemu se běžně říká dobropis, a to, co pod tímhle typem vyváží SuperFaktura
     * i sama Pohoda. `*CreditNotice` je jen jeho nedaňová varianta. Dokud padaly do
     * `default`, přišel opravný doklad do systému jako ŘÁDNÁ faktura: kladná daň místo
     * záporné, jiná sekce kontrolního hlášení a mimo veškerou mechaniku oprav (vč. VetaO
     * v OSS podání). U migrace se 99 dobropisy je to rozdíl v celé jedné straně přiznání.
     *
     * Vrubopis (`*DebitNote`) zůstává fakturou schválně — zvyšuje závazek, znaménka se mu
     * nesmějí otáčet.
     *
     * `receivable` / `penalty` / `commitment` jsou agendy ostatních pohledávek a závazků,
     * ne faktury. V exportu faktur se neobjeví, ale směr u nich uvádíme, aby ani omylem
     * nahraný soubor neskončil v opačné evidenci — druh dokladu jim zůstává `invoice`,
     * protože nic bližšího v systému nemají.
     *
     * Neznámá hodnota (i prázdná) dává směr `null` = SOUBOR SMĚR NEURČUJE. Volající pak
     * musí rozhodnout sám podle IČO, jak to dělal dosud; vymyslet tady `issued` by z každého
     * nečitelného typu udělalo vydaný doklad.
     *
     * @var array<string,array{0:string,1:string}>
     */
    private const DOCUMENT_TYPES = [
        'issuedInvoice'           => ['issued',   'invoice'],
        'issuedCreditNotice'      => ['issued',   'credit_note'],
        'issuedCorrectiveTax'     => ['issued',   'credit_note'],
        'issuedDebitNote'         => ['issued',   'invoice'],
        'issuedAdvanceInvoice'    => ['issued',   'proforma'],
        'issuedProformaInvoice'   => ['issued',   'proforma'],
        'receivable'              => ['issued',   'invoice'],
        'penalty'                 => ['issued',   'invoice'],
        'receivedInvoice'         => ['purchase', 'invoice'],
        'receivedCreditNotice'    => ['purchase', 'credit_note'],
        'receivedCorrectiveTax'   => ['purchase', 'credit_note'],
        'receivedDebitNote'       => ['purchase', 'invoice'],
        'receivedAdvanceInvoice'  => ['purchase', 'proforma'],
        'receivedProformaInvoice' => ['purchase', 'proforma'],
        'commitment'              => ['purchase', 'invoice'],
    ];

    public static function isAcceptableVarsymbol(string $value): bool
    {
        return preg_match(self::VARSYMBOL_PATTERN, $value) === 1;
    }

    /**
     * @return array{supplier_ic:?string, invoices:list<array<string,mixed>>}
     */
    public function parse(string $xml): array
    {
        // XXE / billion-laughs hardening — viz IsdocParser.
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            throw new \RuntimeException('Pohoda XML obsahuje DOCTYPE, což není povoleno.');
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded || $dom->documentElement === null) {
            throw new \RuntimeException('Nelze parsovat Pohoda XML.');
        }

        $root = $dom->documentElement;
        // dataPack = import balík (faktura <inv:invoice>); responsePack = export
        // z Pohody (faktury v <lst:invoice>). Oba nesou IČO v root@ico.
        if ($root->localName !== 'dataPack' && $root->localName !== 'responsePack') {
            throw new \RuntimeException('Není Pohoda XML — root není dataPack ani responsePack.');
        }

        $supplierIc = $root->getAttribute('ico') ?: null;

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('dat', self::NS_DAT);
        $xpath->registerNamespace('inv', self::NS_INV);
        $xpath->registerNamespace('typ', self::NS_TYP);
        $xpath->registerNamespace('lst', self::NS_LST);

        $invoices = [];
        // `inv:invoice` (dataPack) i `lst:invoice` (responsePack/listInvoice) — jen
        // jeden z nich kdy matchne; vnitřní hlavička je v obou shodně `inv:`.
        /** @var \DOMElement $invEl */
        foreach ($xpath->query('//inv:invoice | //lst:invoice') ?: [] as $invEl) {
            try {
                $parsedInvoice = $this->parseInvoice($invEl, $xpath);
                // Zdrojový úsek JEN tohoto dokladu. Export z Pohody nese celou agendu
                // v jednom souboru, takže bez tohohle by se ke každé ze stovek faktur
                // archivoval celý soubor se všemi ostatními — nafouklé úložiště a
                // „důkaz", ve kterém doklad musí čtenář teprve najít.
                $parsedInvoice['__source_xml'] = self::documentFragment($dom, $invEl);
                $invoices[] = $parsedInvoice;
            } catch (\Throwable $e) {
                // Skip individual broken invoices — vyšší vrstva to dostane jako null v listu, řeší až InvoiceImportService.
                $invoices[] = ['__error' => $e->getMessage()];
            }
        }

        return ['supplier_ic' => $supplierIc, 'invoices' => $invoices];
    }

    /**
     * Samostatně platné XML jednoho dokladu: původní kořen (`dataPack`/`responsePack`)
     * i s atributy, uvnitř jen tenhle jeden `invoice`.
     *
     * Kořen se zachovává schválně — nese `ico` exportující firmy, bez kterého by úsek
     * nešel znovu naimportovat a přišel by o údaj, podle kterého se pozná směr dokladu.
     */
    private static function documentFragment(\DOMDocument $dom, \DOMElement $invEl): string
    {
        $out = new \DOMDocument('1.0', 'UTF-8');
        $out->formatOutput = false;

        $root = $dom->documentElement;
        $newRoot = $out->importNode($root->cloneNode(false), true);
        $out->appendChild($newRoot);
        $newRoot->appendChild($out->importNode($invEl, true));

        return (string) $out->saveXML();
    }

    /**
     * @return array<string,mixed>
     */
    private function parseInvoice(\DOMElement $invEl, \DOMXPath $xpath): array
    {
        $hdr = $xpath->query('inv:invoiceHeader', $invEl)->item(0);
        if (!$hdr instanceof \DOMElement) {
            throw new \RuntimeException('Chybí invoiceHeader.');
        }

        // `<inv:invoiceType>` říká DVĚ věci najednou — druh dokladu i jeho SMĚR — a obojí
        // se čte z jediné tabulky {@see self::DOCUMENT_TYPES}, protože rozdvojit je
        // znamená mít doklad, který je dobropis podle jedné větve a faktura podle druhé.
        $typeRaw = $this->text($xpath, 'inv:invoiceType', $hdr);
        [$direction, $invoiceType] = self::DOCUMENT_TYPES[$typeRaw] ?? [null, 'invoice'];

        $documentNumber = $this->text($xpath, 'inv:number/typ:numberRequested', $hdr);
        // Odkaz na OPRAVOVANÝ doklad — viz docblock třídy (`inv:originalDocument` je past).
        // `correctiveDocument` visí na `<inv:invoice>`, ne na hlavičce.
        $originalDocumentNumber = $this->text($xpath, 'inv:correctiveDocument/typ:sourceDocument/typ:number', $invEl)
            ?: $this->text($xpath, 'inv:originalDocumentNumber', $hdr);
        [$varsymbol, $varsymbolSource, $varsymbolOriginal] = $this->resolveVarsymbol(
            $this->text($xpath, 'inv:symVar', $hdr),
            $documentNumber,
        );
        if ($varsymbol === '') {
            throw new \RuntimeException('Chybí varsymbol (symVar / number).');
        }

        $issueDate = $this->text($xpath, 'inv:date', $hdr);
        $taxDate   = $this->text($xpath, 'inv:dateTax', $hdr) ?: null;
        $dueDate   = $this->text($xpath, 'inv:dateDue', $hdr) ?: $issueDate;

        // Režim přenesené daňové povinnosti / samovyměření podle klasifikace DPH.
        //
        // Čte se VÝHRADNĚ `typ:ids`, ne textContent celého elementu: ten obsahuje i vnořené
        // `<typ:id>`, takže z „PDslRegEU" vyjde „216PDslRegEU" a jakékoli porovnání prefixu
        // minulo. `<inv:isExecuted>` je posting příznak („zlikvidováno"), NE reverse charge
        // (issue #41).
        //
        // Kódy se porovnávají PROTI SEZNAMU, ne podle prefixu. Prefix `PN` znamená
        // v Pohodě „Přijaté Nezdanitelné plnění" — běžný nákup od neplátce, kterých je
        // v reálném exportu většina — a označit je za přenesenou povinnost by vyrobilo
        // samovyměření k plnění, u kterého žádné není. Samovyměření zakládá teprve původ
        // plnění (služba/zboží z EU, ze třetí země, dovoz) nebo tuzemský § 92a.
        $vatClass = strtoupper(trim($this->text($xpath, 'inv:classificationVAT/typ:ids', $hdr)));
        $reverseCharge = $vatClass !== '' && (
            str_contains($vatClass, 'REGEU')     // služba/zboží z jiného členského státu
            || str_contains($vatClass, '3ZEME')  // plnění ze třetí země
            || str_contains($vatClass, 'DOVOZ')  // dovoz zboží
            || str_contains($vatClass, 'PDP')    // tuzemský § 92a (Pohoda: *PDP*)
        );
        $noteAbove = $this->text($xpath, 'inv:text', $hdr) ?: null;
        // Pohoda může mít inv:numberOrder (číslo objednávky odběratele) nebo inv:contract/typ:ids
        $projectNumber = $this->text($xpath, 'inv:numberOrder', $hdr) ?: null;

        // Strany dokladu. `<inv:partnerIdentity>` je PROTISTRANA a `<inv:myIdentity>` je
        // firma, která export pořídila — obojí BEZ OHLEDU na směr. Do rolí je proto rozdělí
        // až směr: u vydané faktury je protistrana odběratel, u přijaté dodavatel.
        //
        // `client` znamená v celém výstupu ODBĚRATELE a `supplier` DODAVATELE, ať doklad míří
        // kamkoli — na téhle dvojici stojí cross-tenant guard importu („je tenant tou stranou,
        // za kterou se doklad vydává?") i dohledání dodavatele u přijaté faktury.
        $partner = $this->identity($xpath, $hdr, 'inv:partnerIdentity');
        $mine    = $this->identity($xpath, $hdr, 'inv:myIdentity');
        [$client, $supplier] = $direction === 'purchase' ? [$mine, $partner] : [$partner, $mine];

        // Currency — z první foreignCurrency v summary (pokud existuje), jinak CZK
        $currency = 'CZK';
        $rate = null;
        $rateAmount = null;
        $foreignCur = $xpath->query('inv:invoiceSummary/inv:foreignCurrency', $invEl)->item(0);
        if ($foreignCur instanceof \DOMElement) {
            [$currency, $rate, $rateAmount] = $this->parseForeignCurrency($xpath, $foreignCur);
        }

        // Rekapitulace se čte PŘED položkami: přihrádka nese základ i daň, takže z ní jde
        // procento dopočítat u položky, která ho sama neuvádí (běžný tuzemský export
        // z Pohody `percentVAT` nepíše). Bez tohohle pořadí by položce zbyl jen enum,
        // ze kterého se procento dosadit nesmí.
        $buckets = $this->summaryBuckets($xpath, $invEl, $currency);

        // Items
        $items = [];
        foreach ($xpath->query('inv:invoiceDetail/inv:invoiceItem', $invEl) ?: [] as $itemEl) {
            if (!$itemEl instanceof \DOMElement) continue;
            $items[] = $this->parseItem($xpath, $itemEl, $currency !== 'CZK', $buckets);
        }
        $recap = self::recapFromBuckets($buckets);

        // Doklad bez rozpisu položek, ale s vyplněnou rekapitulací — viz
        // {@see self::itemsFromSummary()}. `items_source` říká volajícímu, že řádky
        // nejsou ze souboru, ale z jeho vlastní rekapitulace.
        $itemsSource = 'detail';
        if ($items === []) {
            $synthesized = $this->itemsFromSummary($xpath, $invEl, $currency, $buckets, $noteAbove, $documentNumber);
            if ($synthesized !== []) {
                $items = $synthesized;
                $itemsSource = 'summary_recap';
            }
        }

        return [
            'invoice_type'          => $invoiceType,
            // 'issued' | 'purchase' | null — směr, který určuje SOUBOR (viz DOCUMENT_TYPES).
            'direction'             => $direction,
            'varsymbol'             => $varsymbol,
            'varsymbol_source'      => $varsymbolSource,
            'varsymbol_original'    => $varsymbolOriginal,
            // Dosazený VS může trefit skutečný VS cizího dokladu — import podle toho
            // rozliší duplicitu od kolize (viz docblock třídy).
            'varsymbol_substituted' => $varsymbolSource !== 'symVar',
            'document_number'       => $documentNumber !== '' ? $documentNumber : null,
            'original_document_number' => $originalDocumentNumber !== '' ? $originalDocumentNumber : null,
            'issue_date'            => $issueDate,
            'tax_date'              => $taxDate,
            'due_date'              => $dueDate,
            'currency'              => $currency,
            'exchange_rate'         => $rate,
            'exchange_rate_amount'  => $rateAmount,
            'reverse_charge'        => $reverseCharge,
            'note_above'            => $noteAbove,
            'project_number'        => $projectNumber,
            'client'                => $client,
            'supplier'              => $supplier,
            'items'                 => $items,
            // 'detail' = řádky ze souboru, 'summary_recap' = dopočtené z rekapitulace.
            'items_source'          => $itemsSource,
            // Rekapitulace DPH po sazbách z <invoiceSummary> — pro seed override.
            'vat_recap'             => $recap,
            // Rozpory MEZI položkami a rekapitulací TÉHOŽ souboru (§ G2). U dopočtených
            // řádků se kontrola vynechává — položky Z rekapitulace jí odpovídají z definice,
            // takže by neověřila nic a jen předstírala kontrolu.
            'file_issues'           => $itemsSource === 'detail' ? self::recapConflicts($items, $recap) : [],
        ];
    }

    /**
     * Variabilní symbol pro import — `<inv:symVar>` s fallbackem na číslo dokladu.
     *
     * SuperFaktura zapisuje do `symVar` svůj interní GUID (36 znaků s pomlčkami),
     * který {@see self::VARSYMBOL_PATTERN} neprojde, a import celý doklad odmítne.
     * Fallback proto neřeší jen prázdný `symVar`, ale i neplatný tvar. Samotné odvození
     * z čísla dokladu žije v {@see self::varsymbolFromDocumentNumber()}.
     *
     * Když se nabídnout nedá nic, vrací se původní `symVar` beze změny: report
     * importu tak ukáže konkrétní vadnou hodnotu místo obecné chyby parseru.
     *
     * @return array{0:string,1:string,2:?string} [varsymbol, source, nahrazený symVar]
     */
    private function resolveVarsymbol(string $symVar, string $docNumber): array
    {
        if (self::isAcceptableVarsymbol($symVar)) {
            return [$symVar, 'symVar', null];
        }

        // Prázdný symVar není náhrada — nebylo co nahradit, nemá smysl to hlásit.
        $replaced = $symVar !== '' ? $symVar : null;

        $derived = self::varsymbolFromDocumentNumber($docNumber);
        if ($derived !== null) {
            return [$derived[0], $derived[1], $replaced];
        }

        return [$symVar, 'symVar', null];
    }

    /**
     * Variabilní symbol ODVOZENÝ z čísla dokladu — jediná implementace téhle náhrady.
     *
     * Volají ji dvě různé příčiny se stejným řešením, a proto tu ta metoda je veřejná:
     *   1. {@see self::resolveVarsymbol()} — symbol ze souboru nemá použitelný TVAR
     *      (typicky interní GUID ze SuperFaktury);
     *   2. {@see \MyInvoice\Service\Import\InvoiceImportService::processOne()} — symbol
     *      tvar má, ale je u téhož dodavatele OBSAZENÝ dokladem JINÉHO DRUHU (opravný
     *      daňový doklad běžně nese symbol opravované faktury, aby vratka došla na týž
     *      symbol; unikátní index `uq_inv_supplier_varsymbol` ale dva takové doklady
     *      neuloží).
     * Druhá kopie tohohle pravidla by se od první rozešla a náhrada by v jedné cestě
     * vyráběla symboly, které druhá cesta odmítá.
     *
     * Sanitizovaný tvar je poslední záchrana — čísla jako `2026/0123` by jinak neprošla.
     * Odlišuje se ve zdroji, protože po ořezu na 20 znaků teoreticky může kolidovat
     * s jiným dokladem; volající to má vypsat do reportu.
     *
     * @return array{0:string,1:string}|null [varsymbol, zdroj] nebo `null`, když se
     *         z čísla dokladu nedá vyrobit nic použitelného
     */
    public static function varsymbolFromDocumentNumber(string $docNumber): ?array
    {
        if (self::isAcceptableVarsymbol($docNumber)) {
            return [$docNumber, 'number'];
        }

        $sanitized = self::sanitizeVarsymbol($docNumber);

        return $sanitized !== '' ? [$sanitized, 'number_sanitized'] : null;
    }

    /**
     * Měna a kurz z `<inv:invoiceSummary>/<inv:foreignCurrency>`.
     *
     * `<typ:rate>` NENÍ kurz za jednu jednotku měny. `type.xsd` (`typeCurrencyForeign`)
     * ho popisuje jako „Kurs použitý pro výpočet částek v cizí měně" a v témže bloku
     * má `<typ:amount>` = „Množství cizí měny pro kursový přepočet". U měn, které se
     * kotují na 100 jednotek (HUF, JPY), tedy chodí `rate=63.50` + `amount=100`, což
     * je 0,635 CZK za forint — bez dělení by doklad odešel do evidence se stonásobným
     * kurzem a stonásobným základem daně v CZK.
     *
     * Chybějící `amount` bereme jako 1. Je to bezpečné, protože XSD mu žádný default
     * nedává, takže jiný výklad neexistuje ani pro Pohodu samotnou, a náš vlastní
     * PohodaXmlExporter zapisuje `<typ:amount>1</typ:amount>` vždy. Nula a záporná
     * hodnota nedávají smysl (a shodily by dělení), berou se rovněž jako 1.
     *
     * Nepoužitelný kurz (nečíselný, nulový, záporný) vrací `null`, ne `0.0` — nula by
     * se v přepočtech tvářila jako platný kurz a vynulovala celý doklad.
     *
     * @return array{0:string,1:?float,2:?int} [měna, kurz za 1 jednotku, deklarovaný amount]
     */
    private function parseForeignCurrency(\DOMXPath $xpath, \DOMElement $fc): array
    {
        // Bez kódu měny není co přepínat — blok je prázdný a doklad zůstává korunový.
        $ids = $this->text($xpath, 'typ:currency/typ:ids', $fc);
        if ($ids === '') {
            return ['CZK', null, null];
        }
        $currency = strtoupper($ids);

        $rateRaw = $this->text($xpath, 'typ:rate', $fc);
        if (!is_numeric($rateRaw) || (float) $rateRaw <= 0.0) {
            return [$currency, null, null];
        }

        $amountRaw = $this->text($xpath, 'typ:amount', $fc);
        $declared  = is_numeric($amountRaw) ? (int) $amountRaw : null;
        $divisor   = ($declared !== null && $declared > 0) ? $declared : 1;

        return [$currency, (float) $rateRaw / $divisor, $declared];
    }

    private static function sanitizeVarsymbol(string $value): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $value), '-');
        return trim(substr($clean, 0, 20), '-');
    }

    /**
     * Rekapitulace DPH po sazbách z `<invoiceSummary>/<homeCurrency|foreignCurrency>`.
     *
     * Pohoda nese základ + DPH per sazbová „přihrádka" (High/Low/3), jenže název
     * přihrádky NENÍ procento: u dokladu se zahraniční sazbou leží polských 23 %
     * v přihrádce `High`, kterou české čtení vydává za 21 %. Procento proto
     * určujeme z dat, ne z názvu přihrádky:
     *
     *   1. atribut `@rate` na `price*VAT` — XSD `typ:currencyVAT` ho zavádí přesně
     *      pro tenhle účel („Hodnota sazby DPH (jen pro export)");
     *   2. dopočet `vat / base` z částek v přihrádce;
     *   3. teprve když ani jedno nejde, české výchozí procento přihrádky — a to
     *      POUZE u korunového dokladu.
     *
     * U cizoměnového dokladu se v kroku 3 přihrádka NEVRACÍ. `vat_recap` slouží
     * jako override součtů, takže vymyšlených českých 21 % nad zahraničním
     * plněním by se propsalo do evidence DPH; chybějící přihrádka jen znamená,
     * že se součty dopočtou z položek, kde už skutečnou sazbu máme.
     *
     * Dopočet z kroku 2 nese haléřové zaokrouhlení, proto se přichytí ke kotvě
     * (deklarované nebo výchozí procento) v rámci {@see self::RATE_MATCH_TOLERANCE};
     * to zároveň hlídá krok 1 — producent umí deklarovat `@rate="21"` nad částkami
     * spočtenými z 23 %, a v takovém sporu vyhrávají částky.
     *
     * ČESKÁ KOTVA JEN NA KORUNOVÉM DOKLADU. Deklarované `@rate` je z TOHOTO souboru,
     * takže se dá použít vždycky; české výchozí procento přihrádky (21/12/10) je ale
     * NÁŠ předpoklad o tom, co přihrádka znamená — a nad cizoměnovým dokladem nemá co
     * dělat. Kdyby tam kotvilo, přepsalo by dopočtených 20,90 % na rovných českých
     * 21 % a z haléřového šumu by se stalo POZITIVNÍ tvrzení „tohle je česká sazba",
     * které pak invariant proti úniku ({@see \MyInvoice\Service\Oss\OssItemDeriver})
     * přečte jako potvrzení tuzemského plnění. Tolerance existuje kvůli haléřům, a ty
     * na cizoměnovém dokladu pohltí kotva `@rate` ze souboru — česká kotva je tam
     * navíc a jen tiše mění zemi, které daň patří. Krok 3 (fallback na výchozí
     * procento) je z téhož důvodu omezený na korunový doklad už dřív.
     *
     * Vrací přihrádku pod jejím NÁZVEM (High/Low/3), protože podle názvu se na ni ptá
     * položka ({@see self::itemVatRate()}), a vedle výsledné `rate` nese i `stated`:
     * procento, které soubor skutečně UVÁDÍ (dopočtené nebo deklarované), bez českého
     * výchozího procenta z kroku 3. Rozdíl je podstatný — `rate` smí být dohad o součtu
     * korunového dokladu, kdežto sazbu POLOŽKY smí určit jedině to, co soubor uvádí.
     *
     * @return array<string,array{base:float,vat:float,rate:?float,stated:?float}>
     */
    private function summaryBuckets(\DOMXPath $xpath, \DOMElement $invEl, string $currency): array
    {
        $isHome = $currency === 'CZK';
        $block = $isHome ? 'inv:homeCurrency' : 'inv:foreignCurrency';
        $sum = $xpath->query("inv:invoiceSummary/$block", $invEl)->item(0);
        if (!$sum instanceof \DOMElement) {
            return [];
        }
        $out = [];
        foreach (self::RECAP_BUCKETS as $suffix => $defaultRate) {
            $base  = $this->text($xpath, "typ:price{$suffix}", $sum);
            $vatEl = $xpath->query("typ:price{$suffix}VAT", $sum)->item(0);
            $vat   = $vatEl instanceof \DOMElement ? trim($vatEl->textContent) : '';
            if ($base === '' && $vat === '') {
                continue;
            }
            $baseF = abs((float) ($base !== '' ? $base : '0'));
            $vatF  = abs((float) ($vat !== '' ? $vat : '0'));
            if ($baseF <= 0.0 && $vatF <= 0.0) {
                continue;
            }

            $declared = $vatEl instanceof \DOMElement
                ? self::percentOrNull($vatEl->getAttribute('rate'))
                : null;
            $derived = ($baseF > 0.0 && $vatF > 0.0)
                ? round($vatF / $baseF * 100.0, 2)
                : null;

            $stated = null;
            if ($derived !== null) {
                $stated = $derived;
                // Česká kotva se na cizoměnovém dokladu vůbec nenabídne (viz docblock).
                foreach ([$declared, $isHome ? $defaultRate : null] as $anchor) {
                    if ($anchor !== null && abs($derived - $anchor) <= self::RATE_MATCH_TOLERANCE) {
                        $stated = $anchor;
                        break;
                    }
                }
            } elseif ($declared !== null) {
                $stated = $declared;
            }

            $out[$suffix] = [
                'base'   => $baseF,
                'vat'    => $vatF,
                'rate'   => $stated ?? ($isHome ? $defaultRate : null),
                'stated' => $stated,
            ];
        }

        return $out;
    }

    /**
     * Přihrádky sloučené na procenta — `vat_recap` slouží jako override součtů, takže
     * přihrádky, které vyjdou na stejné procento, se sečtou. Přihrádka bez určeného
     * procenta (cizoměnový doklad bez daně v přihrádce) se vynechá: vymyšlených českých
     * 21 % nad zahraničním plněním by se propsalo do evidence DPH, kdežto chybějící
     * přihrádka jen znamená, že se součty dopočtou z položek.
     *
     * @param  array<string,array{base:float,vat:float,rate:?float,stated:?float}> $buckets
     * @return array<string,array{base:float,vat:float}>
     */
    private static function recapFromBuckets(array $buckets): array
    {
        $out = [];
        foreach ($buckets as $bucket) {
            if ($bucket['rate'] === null) {
                continue;
            }
            $key = self::rateKey($bucket['rate']);
            $out[$key] = [
                'base' => ($out[$key]['base'] ?? 0.0) + $bucket['base'],
                'vat'  => ($out[$key]['vat'] ?? 0.0) + $bucket['vat'],
            ];
        }

        return $out;
    }

    /**
     * Rozpory mezi POLOŽKAMI a REKAPITULACÍ téhož souboru (§ G2).
     *
     * Soubor si umí odporovat: položka nese enum `high` bez procenta, ale rekapitulace
     * v témže souboru deklaruje `<typ:priceHighVAT rate="23">230</typ:priceHighVAT>` na
     * základ 1 000. Dokud si toho nikdo nevšiml, prošel takový doklad tiše — a to je vada
     * sama o sobě, protože obě čísla nemůžou platit zároveň a systém si mlčky vybírá.
     * Importují se částky a sazby z POLOŽEK (výkazy sumují řádky), takže rozpor nezastaví
     * import, ale musí se dostat uživateli před oči.
     *
     * Kontrola se ZÁMĚRNĚ vypne, jakmile má kterákoli položka neurčenou sazbu: doklad je
     * pak stejně k odmítnutí s konkrétnější hláškou a rozpor „položky mají sazby X,
     * rekapitulace Y" by ji jen přehlušil. Nulová sazba se nepočítá — rekapitulace pro ni
     * přihrádku nemá (čteme jen High/Low/3), takže by chyběla vždycky.
     *
     * @param  list<array<string,mixed>>              $items
     * @param  array<string,array{base:float,vat:float}> $recap
     * @return list<string>
     */
    private static function recapConflicts(array $items, array $recap): array
    {
        if ($items === [] || $recap === []) {
            return [];
        }

        $itemBases = [];
        foreach ($items as $item) {
            if (($item['vat_rate'] ?? null) === null) {
                return [];
            }
            $rate = (float) $item['vat_rate'];
            if ($rate <= 0.0) {
                continue;
            }
            $key = self::rateKey($rate);
            // Sčítá se SE ZNAMÉNKEM, absolutní hodnota až ze součtu — viz tentýž komentář
            // v {@see IsdocParser::recapConflicts()}. Per-řádkové `abs()` dělalo ze
            // slevového kupónu přírůstek a hlásilo rozpor, který v souboru není.
            $itemBases[$key] = ($itemBases[$key] ?? 0.0)
                + (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price_without_vat'] ?? 0);
        }
        $itemBases = array_map('abs', $itemBases);
        if ($itemBases === []) {
            return [];
        }

        $issues = [];
        $itemRates = array_keys($itemBases);
        $recapRates = array_keys($recap);
        sort($itemRates);
        sort($recapRates);
        if ($itemRates !== $recapRates) {
            $issues[] = sprintf(
                'Doklad si v souboru odporuje: položky nesou sazby %s, ale rekapitulace dokladu '
                    . '(invoiceSummary) uvádí %s. Importují se sazby z položek — zkontrolujte '
                    . 'zdrojový soubor, obě čísla platit zároveň nemůžou.',
                self::fmtRateList($itemRates),
                self::fmtRateList($recapRates),
            );
        }

        foreach ($itemBases as $key => $base) {
            if (!isset($recap[$key])) {
                continue;
            }
            $recapBase = $recap[$key]['base'];
            $tolerance = max(self::RECAP_ABS_TOLERANCE, self::RECAP_REL_TOLERANCE * max($base, $recapBase));
            if (abs($base - $recapBase) <= $tolerance) {
                continue;
            }
            $issues[] = sprintf(
                'Doklad si v souboru odporuje: součet položek se sazbou %s %% je %s, ale '
                    . 'rekapitulace dokladu uvádí základ %s. Importují se částky z položek.',
                self::fmtRate((float) $key),
                self::fmtAmount($base),
                self::fmtAmount($recapBase),
            );
        }

        return $issues;
    }

    /** Klíč sazby v `vat_recap` — jediné místo, kde se procento překlápí na řetězec. */
    private static function rateKey(float $rate): string
    {
        return number_format($rate, 2, '.', '');
    }

    /** @param list<string> $keys */
    private static function fmtRateList(array $keys): string
    {
        return $keys === []
            ? 'žádnou'
            : implode(', ', array_map(static fn (string $k): string => self::fmtRate((float) $k) . ' %', $keys));
    }

    /** Procento bez zbytečných nul — shodně s hláškami `OssItemDeriver` a `VatRateResolver`. */
    private static function fmtRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, ',', ' '), '0'), ',');
    }

    /** Částka VŽDY na dvě desetinná místa — u peněz je useknutá nula matoucí. */
    private static function fmtAmount(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }

    /**
     * @return array<string,?string>
     */
    /**
     * Adresa jedné strany dokladu (`inv:partnerIdentity` / `inv:myIdentity`).
     *
     * Chybějící element vrací prázdné pole, ne `null` — volající se strany ptá indexem
     * (`$party['ic'] ?? ''`) a druhý tvar by ho nutil rozlišovat „strana chybí" od
     * „strana nemá IČO", což pro něj nemá jiný následek.
     *
     * @return array<string,mixed>
     */
    private function identity(\DOMXPath $xpath, \DOMElement $hdr, string $element): array
    {
        $addr = $xpath->query($element . '/typ:address', $hdr)->item(0);

        return $addr instanceof \DOMElement ? $this->parseAddress($xpath, $addr) : [];
    }

    /**
     * Řádky DOPOČTENÉ z `<inv:invoiceSummary>` u dokladu, který žádné `<inv:invoiceItem>`
     * nemá — jeden řádek na přihrádku rekapitulace.
     *
     * Pohoda takový doklad běžně vyváží: faktura zadaná jen textem a částkou (u daňových
     * poradců a nájmů většina dokladů) nemá rozpis položek, ale rekapitulaci má úplnou.
     * Import ji ovšem odmítá — součty vydané faktury se počítají výhradně z řádků, takže
     * bez nich by vznikl doklad na nulu ({@see \MyInvoice\Service\Import\InvoiceImportService::processOne()}).
     * V reálné migraci 3 144 faktur takhle propadlo 458 dokladů se základem 2 034 236 Kč
     * a daní 427 190 Kč, a rozdíl proti původnímu systému nešel vysvětlit jinak než ručním
     * porovnáním po měsících.
     *
     * NEJDE o dohad: základ i daň jsou v TOMTÉŽ souboru a procento se z nich spočítá
     * (`daň / základ`) — táž aritmetika, jakou už pro sazbu POLOŽKY dělá
     * {@see self::itemVatRate()} pod zdrojem `summary_recap`. Vymýšlí se jedině POPIS
     * řádku, a ten na žádný výkaz nemá vliv.
     *
     * Sazba se bere z `stated`, tedy z toho, co soubor UVÁDÍ nebo z čeho jde spočítat.
     * Přihrádka bez určeného procenta (typicky cizoměnový doklad bez daně v přihrádce)
     * dopočet CELÉHO dokladu ruší a doklad propadne na původní odmítnutí: dosadit tam
     * české procento by z něj udělalo pozitivní tvrzení „tohle je tuzemská sazba", které
     * pak invariant proti úniku přečte jako potvrzené tuzemské plnění. Radši nic než
     * vymyšlená sazba — u částí dokladu to platí dvojnásob, protože chybějící přihrádka
     * by ho uložila neúplný a tvářil by se přitom kompletně.
     *
     * `priceNone` (plnění bez daně — osvobozené, mimo předmět, přenesená povinnost) je
     * v rekapitulaci samostatně a {@see self::RECAP_BUCKETS} ho nemá, protože z něj sazbu
     * dopočítat nejde. Tady ale sazbu dopočítávat netřeba: nulová daň k nulovému základu
     * daně je to, co ta přihrádka ZNAMENÁ. Bez ní by z přijatých faktur od neplátců
     * (v testovaném souboru 172 ze 409) nezbylo vůbec nic.
     *
     * Zaokrouhlení dokladu (`typ:round/typ:priceRound`) dostane vlastní řádek s nulovou
     * sazbou — jinak by se ztratilo a doklad by nesouhlasil s originálem o koruny.
     *
     * @param  array<string,array{base:float,vat:float,rate:?float,stated:?float}> $buckets
     * @return list<array<string,mixed>>
     */
    private function itemsFromSummary(
        \DOMXPath $xpath,
        \DOMElement $invEl,
        string $currency,
        array $buckets,
        ?string $headerText,
        string $documentNumber,
    ): array {
        $block = $currency === 'CZK' ? 'inv:homeCurrency' : 'inv:foreignCurrency';
        $sum = $xpath->query("inv:invoiceSummary/$block", $invEl)->item(0);
        if (!$sum instanceof \DOMElement) {
            return [];
        }

        $description = trim((string) $headerText);
        if ($description === '') {
            $description = $documentNumber !== ''
                ? 'Fakturováno dle dokladu ' . $documentNumber
                : 'Fakturovaná částka';
        }

        // Znaménko, ve kterém je rekapitulace NAPSANÁ. Dobropis Pohoda vyváží zápornými
        // částkami, kdežto {@see self::summaryBuckets()} vrací přihrádky v absolutní
        // hodnotě. Ostatní částky se proto vyjadřují VŮČI orientaci dokladu, ne absolutně
        // ani syrově: celý dopočtený doklad tak vyjde ve stejné orientaci jako přihrádky
        // (u dobropisu kladný) a otočení dobropisu v importní vrstvě
        // ({@see \MyInvoice\Service\Import\InvoiceImportService::planItems()}) ho pak
        // otočí celý najednou. Syrové znaménko by dobropisu odečetlo, co mu má přičíst.
        $orientation = $this->summaryOrientation($xpath, $sum);

        /** @var list<array{0:float,1:float,2:?string}> $lines  [základ, sazba v %, vlastní popis] */
        $lines = [];

        $taxed = [];
        foreach (self::RECAP_BUCKETS as $suffix => $_default) {
            $bucket = $buckets[$suffix] ?? null;
            if ($bucket === null || $bucket['base'] <= 0.0) {
                continue;
            }
            if ($bucket['stated'] === null) {
                // Přihrádka s částkou, ale bez určitelné sazby → celý doklad zpět na
                // odmítnutí. Uložit ho bez ní znamená uložit ho neúplný.
                return [];
            }
            $taxed[] = [$bucket['base'], $bucket['stated'], null];
        }

        // Plnění BEZ DANĚ. Nulová sazba je význam téhle přihrádky, ne dosazený dohad —
        // a bez ní by z přijatých faktur od neplátců nezbylo vůbec nic.
        //
        // Znaménko si přihrádka NECHÁVÁ (vůči orientaci dokladu, viz výše). Pohoda do ní
        // totiž parkuje i HALÉŘOVÉ VYROVNÁNÍ při zaokrouhlení dokladu (`roundingDocument`
        // = `math2one`): faktura na 35 520 Kč základu má `priceNone = -0,20`, kdežto
        // `typ:round/typ:priceRound` zůstane nulové. Absolutní hodnota by z těch dvaceti
        // haléřů udělala plus a doklad by proti originálu přebil o čtyřicet.
        $none = $this->text($xpath, 'typ:priceNone', $sum);
        if (is_numeric($none) && abs((float) $none) > 0.0) {
            $noneRelative = (float) $none * $orientation;
            // Záporný zbytek vedle zdaněného plnění není osvobozené plnění, ale právě to
            // haléřové vyrovnání — popis hlavičky by u něj byl matoucí.
            $isRounding = $noneRelative < 0.0 && $taxed !== [];
            $lines[] = [$noneRelative, 0.0, $isRounding ? 'Zaokrouhlení' : null];
        }

        foreach ($taxed as $line) {
            $lines[] = $line;
        }

        if ($lines === []) {
            // Cizoměnový doklad bez rozpisu položek. Pohoda píše rozpad podle sazeb JEN do
            // `homeCurrency`; `foreignCurrency` nese pouhý součet (`typ:priceSum`), takže se
            // tady nenajde ani jedna přihrádka a doklad by vznikl NULOVÝ. V testovaném
            // exportu se to týkalo všech přijatých služeb z EU (licence, reklama) —
            // zmizel náklad i podklad pro samovyměření.
            //
            // Dopočte se z korunové rekapitulace a přepočte kurzem dokladu. Poslední řádek
            // se dorovná na `priceSum`, aby se součet dokladu trefil na haléř i po dělení.
            return $this->itemsFromHomeCurrency($xpath, $invEl, $description);
        }

        // Zaokrouhlení si svoje znaménko NESE (umí doklad snížit i zvýšit), ale musí být
        // vyjádřené vůči orientaci dokladu, ne absolutně — na dobropisu psaném zápornými
        // částkami znamená `-0,40` navýšení jeho velikosti, tedy relativně `+0,40`.
        $round = $this->text($xpath, 'typ:round/typ:priceRound', $sum);
        if (is_numeric($round) && abs((float) $round) > 0.0) {
            $lines[] = [(float) $round * $orientation, 0.0];
        }

        $items = [];
        foreach ($lines as [$base, $rate, $ownDescription]) {
            $items[] = [
                'description'            => $ownDescription ?? $description,
                'quantity'               => 1.0,
                'unit'                   => 'ks',
                'unit_price_without_vat' => $base,
                // Základ z rekapitulace je NETTO, přepočítávat se nebude.
                'unit_price_with_vat'    => null,
                'vat_rate'               => $rate,
                'vat_rate_source'        => 'summary_recap',
                'vat_rate_enum'          => null,
                'vat_rate_level'         => null,
                'prices_included_vat'    => false,
            ];
        }

        return $items;
    }

    /**
     * Položky cizoměnového dokladu dopočtené z KORUNOVÉ rekapitulace.
     *
     * Poslední záchrana pro doklad, který nemá rozpis položek a jehož `foreignCurrency`
     * blok nese jen součet. Korunový rozpad se přepočte kurzem dokladu
     * (`typ:rate` / `typ:amount`) a poslední řádek se dorovná na `typ:priceSum`, takže
     * doklad sedí na haléř bez ohledu na zaokrouhlení jednotlivých řádků.
     *
     * Vrací `[]`, když kurz ani součet nejsou k dispozici — dopočet naslepo by vyrobil
     * doklad s vymyšlenou částkou, což je horší než doklad odmítnutý s hláškou.
     *
     * @return list<array<string,mixed>>
     */
    private function itemsFromHomeCurrency(\DOMXPath $xpath, \DOMElement $invEl, string $description): array
    {
        $home = $xpath->query('inv:invoiceSummary/inv:homeCurrency', $invEl)->item(0);
        $foreign = $xpath->query('inv:invoiceSummary/inv:foreignCurrency', $invEl)->item(0);
        if (!$home instanceof \DOMElement || !$foreign instanceof \DOMElement) {
            return [];
        }

        $rate = (float) $this->text($xpath, 'typ:rate', $foreign);
        $amount = (float) ($this->text($xpath, 'typ:amount', $foreign) ?: '1');
        $priceSum = $this->text($xpath, 'typ:priceSum', $foreign);
        if ($rate <= 0.0 || $amount <= 0.0 || !is_numeric($priceSum)) {
            return [];
        }
        $perUnit = $rate / $amount;
        $orientation = $this->summaryOrientation($xpath, $home);

        /** @var list<array{0:float,1:float}> $lines [základ v měně dokladu, sazba] */
        $lines = [];
        $none = $this->text($xpath, 'typ:priceNone', $home);
        if (is_numeric($none) && abs((float) $none) > 0.0) {
            $lines[] = [round(((float) $none * $orientation) / $perUnit, 2), 0.0];
        }
        foreach (self::RECAP_BUCKETS as $suffix => $defaultRate) {
            $base = $this->text($xpath, 'typ:price' . $suffix, $home);
            if (!is_numeric($base) || abs((float) $base) <= 0.0) {
                continue;
            }
            $vatEl = $xpath->query('typ:price' . $suffix . 'VAT', $home)->item(0);
            $stated = $vatEl instanceof \DOMElement && $vatEl->hasAttribute('rate')
                ? (float) str_replace(',', '.', $vatEl->getAttribute('rate'))
                : $defaultRate;
            if ($stated === null) {
                // Přihrádka s částkou, ale bez určitelné sazby — stejné pravidlo jako
                // u korunového dokladu: radši nic než vymyšlená sazba.
                return [];
            }
            $lines[] = [round(((float) $base * $orientation) / $perUnit, 2), (float) $stated];
        }
        if ($lines === []) {
            return [];
        }

        // Dorovnání na součet dokladu: dělení kurzem po řádcích se od `priceSum` liší
        // o haléře a doklad by proti originálu neseděl.
        $target = round((float) $priceSum * $orientation, 2);
        $sum = 0.0;
        foreach ($lines as [$base, $_]) $sum += $base;
        $diff = round($target - $sum, 2);
        if (abs($diff) > 0.0 && abs($diff) < 1.0) {
            $lastIndex = count($lines) - 1;
            $lines[$lastIndex][0] = round($lines[$lastIndex][0] + $diff, 2);
        }

        $items = [];
        foreach ($lines as [$base, $vatRate]) {
            $items[] = [
                'description'            => $description,
                'quantity'               => 1.0,
                'unit'                   => 'ks',
                'unit_price_without_vat' => $base,
                'unit_price_with_vat'    => null,
                'vat_rate'               => $vatRate,
                'vat_rate_source'        => 'summary_recap_home',
                'vat_rate_enum'          => null,
                'vat_rate_level'         => null,
                'prices_included_vat'    => false,
            ];
        }

        return $items;
    }

    /**
     * Znaménko, ve kterém je rekapitulace napsaná: `-1.0` u dokladu se zápornými částkami
     * (tak Pohoda vyváží dobropis), jinak `1.0`.
     *
     * Rozhoduje přihrádka s NEJVĚTŠÍ absolutní částkou. První nenulová přihrádka by
     * nestačila: Pohoda parkuje haléřové vyrovnání dokladu do `priceNone` jako záporných
     * dvacet haléřů vedle kladného základu 35 520 Kč, takže by se řádná faktura označila
     * za doklad psaný záporně a vyrovnání by se otočilo na plus. Součet přihrádek by
     * naopak selhal tam, kde se navzájem ruší.
     *
     * Prázdná rekapitulace dává `1.0` — není co otáčet.
     */
    private function summaryOrientation(\DOMXPath $xpath, \DOMElement $sum): float
    {
        $dominant = 0.0;
        foreach (['typ:priceNone', 'typ:priceHigh', 'typ:priceLow', 'typ:price3'] as $path) {
            $raw = $this->text($xpath, $path, $sum);
            if (is_numeric($raw) && abs((float) $raw) > abs($dominant)) {
                $dominant = (float) $raw;
            }
        }

        return $dominant < 0.0 ? -1.0 : 1.0;
    }

    private function parseAddress(\DOMXPath $xpath, \DOMElement $addr): array
    {
        return [
            'company_name' => $this->text($xpath, 'typ:company', $addr) ?: null,
            'ic'           => $this->text($xpath, 'typ:ico',     $addr) ?: null,
            'dic'          => $this->text($xpath, 'typ:dic',     $addr) ?: null,
            'street'       => $this->text($xpath, 'typ:street',  $addr) ?: null,
            'city'         => $this->text($xpath, 'typ:city',    $addr) ?: null,
            'zip'          => $this->text($xpath, 'typ:zip',     $addr) ?: null,
            'country_iso2' => strtoupper($this->text($xpath, 'typ:country/typ:ids', $addr)) ?: null,
            'email'        => $this->text($xpath, 'typ:email',   $addr) ?: null,
            'phone'        => $this->text($xpath, 'typ:phone',   $addr) ?: null,
        ];
    }

    /**
     * Položka dokladu — jednotková cena se vrací VŽDY bez DPH.
     *
     * `<inv:payVAT>` říká, v jakých cenách je položka uvedená (invoice.xsd:
     * „Ceny jsou uvedeny: bez DPH, včetně DPH"). Při `true` je `typ:unitPrice`
     * BRUTTO; vydávat ho za netto nadhodnotí základ i daň o celou sazbu — u 23 %
     * tedy o 23 % na každém řádku. Přepočet dělá {@see self::netUnitPrice()}.
     *
     * Chybějící element bereme jako `false`, tedy ceny bez DPH: invoice.xsd mu dává
     * `default="false"` a stejnou hodnotu zapisuje i náš PohodaXmlExporter. XSD sice
     * dodává, že u SKLADOVÉ položky rozhoduje nastavení konkrétní instalace Pohody,
     * jenže to ze souboru nezjistíme — a `false` je jediná volba, která u nejčastějšího
     * tvaru (samotný `unitPrice`) nechá čísla beze změny místo aby je naslepo dělila
     * sazbou. Uhodnout tady `true` by rozbilo doklady, které jsou dnes v pořádku.
     *
     * @param  array<string,array{base:float,vat:float,rate:?float,stated:?float}> $buckets
     * @return array<string,mixed>
     */
    private function parseItem(\DOMXPath $xpath, \DOMElement $itemEl, bool $foreign, array $buckets): array
    {
        $blockName = $foreign ? 'inv:foreignCurrency' : 'inv:homeCurrency';

        $quantity = (float) ($this->text($xpath, 'inv:quantity', $itemEl) ?: '1');

        $rateEl = $xpath->query('inv:rateVAT', $itemEl)->item(0);
        $enum = $rateEl instanceof \DOMElement ? trim($rateEl->textContent) : null;
        $level = $enum !== null ? (self::ENUM_LEVELS[$enum] ?? null) : null;
        // `null` = sazba není známá. Nesmí se cestou nikam „dopočítat" na nulu ani na
        // aktuální českou sazbu — viz docblock třídy i {@see self::itemVatRate()}.
        [$vatRate, $rateSource] = $this->itemVatRate($xpath, $itemEl, $rateEl, $level, $buckets);

        $grossUnitPrice = (float) ($this->text($xpath, "$blockName/typ:unitPrice", $itemEl) ?: '0');
        $unitPrice = $grossUnitPrice;
        $pendingGross = null;

        $grossPricing = self::isXmlTrue($this->text($xpath, 'inv:payVAT', $itemEl));
        if ($grossPricing) {
            // Koeficient k „odečtení" DPH z brutto ceny je čistě CENOVÁ operace, ne tvrzení
            // o dani: sazbu, kterou soubor neurčuje, tu proto smíme provizorně nahradit
            // aktuální českou úrovní enumu, aby doklad nešel dál s brutto cenou vydávanou
            // za základ daně. Ven to jako `vat_rate` NEJDE — a když byla cena skutečně jen
            // vydělená provizorním koeficientem, vrací se brutto v `unit_price_with_vat`,
            // aby ji importní vrstva po rozhodnutí o sazbě přepočítala přesně.
            [$unitPrice, $fromLineTotal] = $this->netUnitPrice(
                $xpath, $itemEl, $blockName, $grossUnitPrice, $quantity,
                $vatRate ?? self::currentCzechScaleRate($level),
            );
            if ($vatRate === null && !$fromLineTotal) {
                $pendingGross = $grossUnitPrice;
            }
        }

        return [
            'description'            => $this->text($xpath, 'inv:text', $itemEl),
            'quantity'               => $quantity,
            'unit'                   => $this->text($xpath, 'inv:unit', $itemEl) ?: 'ks',
            'unit_price_without_vat' => $unitPrice,
            // Brutto k PŘESNÉMU přepočtu; `null` = netto cena je konečná (viz docblock třídy).
            'unit_price_with_vat'    => $pendingGross,
            'vat_rate'               => $vatRate,
            'vat_rate_source'        => $rateSource,
            // Syrový enum a jeho úroveň — podklad pro dosazení sazby v importní vrstvě,
            // která má zemi dodavatele i číselník sazeb členských států k datu plnění.
            'vat_rate_enum'          => $enum,
            'vat_rate_level'         => $level,
            // Doklad byl v souboru vedený v cenách s DPH a cena už je přepočtená —
            // import to má uvést v reportu, ať je haléřový rozdíl proti originálu vysvětlený.
            'prices_included_vat'    => $grossPricing,
        ];
    }

    /**
     * Netto jednotková cena z brutto (`payVAT=true`).
     *
     * Přednost má `typ:price`: XSD ho popisuje jako „Cena položky bez DPH", takže je
     * netto v OBOU režimech `payVAT`, a navíc už v sobě nese řádkovou slevu
     * (`inv:discountPercentage`), o které jednotková cena neví. Zahodíme ho jen ve
     * dvou případech — když je nula (producentův placeholder; dělením by z řádku
     * zmizela částka) a když se rovná brutto řádku (producent do něj zapsal totéž
     * brutto, netto z něj tedy nedostaneme).
     *
     * Fallback je dělení koeficientem sazby. Zaokrouhlení tu záměrně neděláme —
     * cílový sloupec je DECIMAL(12,2) a zaokrouhlí se až při zápisu, kdežto zaokrouhlení
     * jednotkové ceny předem by se u větších množství propsalo do řádkového součtu.
     *
     * Neznámý koeficient (`$vatRate === null` — soubor neurčuje sazbu a enum nedává ani
     * českou úroveň) fallback vypíná: dělit koeficientem, který neznáme, nejde. Brutto
     * cena projde beze změny a volající ji dostane i v `unit_price_with_vat`.
     *
     * Druhá návratová hodnota říká, jestli netto cena pochází z `typ:price` (= je
     * KONEČNÁ, na sazbě nezávisí), nebo z dělení koeficientem (= při jiné výsledné sazbě
     * se musí přepočítat).
     *
     * @return array{0:float,1:bool} [netto jednotková cena, pochází z typ:price]
     */
    private function netUnitPrice(
        \DOMXPath $xpath,
        \DOMElement $itemEl,
        string $blockName,
        float $gross,
        float $quantity,
        ?float $vatRate,
    ): array {
        $lineRaw = $this->text($xpath, "$blockName/typ:price", $itemEl);
        if (is_numeric($lineRaw) && $quantity != 0.0) {
            $line = (float) $lineRaw;
            if ($line != 0.0 && abs($line - $gross * $quantity) > self::PRICE_MATCH_TOLERANCE) {
                return [$line / $quantity, true];
            }
        }

        return $vatRate !== null && $vatRate > 0.0
            ? [$gross / (1.0 + $vatRate / 100.0), false]
            : [$gross, false];
    }

    /** `typ:boolean` je enum „true"/„false"; producenti posílají i 1/0. */
    private static function isXmlTrue(string $raw): bool
    {
        return in_array(strtolower(trim($raw)), ['true', '1'], true);
    }

    /**
     * Skutečná sazba DPH položky v procentech, nebo `null` = SAZBA NENÍ ZNÁMÁ.
     *
     * `<inv:rateVAT>` je jen ENUM českých sazbových ÚROVNÍ (`typ:vatRateEnum`) —
     * zahraniční procento se do něj nevejde, takže producenti (SuperFaktura)
     * posílají `<inv:rateVAT>historyHigh</inv:rateVAT>` a skutečných 23 %
     * v `<inv:percentVAT>`. Pořadí zdrojů pravdy:
     *   1. `<inv:percentVAT>` — invoice.xsd: „Historická sazba v procentech";
     *   2. `rateVAT/@value` — XSD `typ:vatRateType`: „Hodnota sazby DPH (pouze export)";
     *   3. DOPOČET z odpovídající přihrádky rekapitulace — daň / základ. Přihrádka je
     *      v TOMTÉŽ souboru a nese obě čísla, takže tohle není dohad, ale aritmetika;
     *      pokrývá běžný tuzemský export z Pohody, který procento u položky neuvádí,
     *      a stejně tak zahraniční sazbu schovanou v české přihrádce `High`.
     *
     * Nula z kroků 1 a 2 se ignoruje: `percentVAT=0` u položky označené `high` je
     * artefakt producenta, ne osvobozené plnění, a nula by tiše smazala daň
     * z celého dokladu. Skutečné osvobození přijde enumem `none`.
     *
     * Chybějící `<inv:rateVAT>` je nula: element je nepovinný a jeho absence je
     * v Pohoda souborech běžný tvar plnění bez daně, takže to není dohad, ale výchozí
     * stav schématu (a `PohodaXmlExporter` ho u osvobozeného řádku vynechává taky).
     * Nulu vrací i `none` a `nonSubsume` — `nonSubsume` sice patří do
     * `classificationVATType`, ale producenti ho sem občas zapíšou a znamená
     * „nezahrnovat do DPH", tedy plnění bez daně.
     *
     * Všechno ostatní je `null`. `history*` znamená doslova „tahle sazba UŽ NEPLATÍ,
     * skutečné procento je v percentVAT" a `high`/`low`/`low2`/`third` jsou jen ÚROVNĚ:
     * dosadit za ně aktuální české procento je hádání, kterým přeshraniční plnění se
     * sazbou 23 % dostane 21 %, projde kvadrantem „platí jen v tuzemsku" a skončí na
     * ř. 1 českého přiznání bez jediného varování. Nerozpoznaný kód je `null` ze
     * symetrického důvodu — není to prohlášení o osvobození, a nula by z něj osvobozené
     * plnění tiše udělala. Dosadit procento smí až vrstva, která má zemi dodavatele
     * a číselník sazeb členských států k datu plnění; parser jí k tomu vrací
     * `vat_rate_enum` a `vat_rate_level`.
     *
     * @param  array<string,array{base:float,vat:float,rate:?float,stated:?float}> $buckets
     * @return array{0:?float,1:string} [procento, zdroj]
     */
    private function itemVatRate(
        \DOMXPath $xpath,
        \DOMElement $itemEl,
        ?\DOMElement $rateEl,
        ?string $level,
        array $buckets,
    ): array {
        $percent = self::percentOrNull($this->text($xpath, 'inv:percentVAT', $itemEl));
        if ($percent !== null) {
            return [$percent, 'percent'];
        }

        if ($rateEl === null) {
            return [0.0, 'no_rate_element'];
        }

        $attribute = self::percentOrNull($rateEl->getAttribute('value'));
        if ($attribute !== null) {
            return [$attribute, 'rate_attribute'];
        }

        $bucket = $level !== null ? (self::LEVEL_BUCKETS[$level] ?? null) : null;
        $stated = $bucket !== null ? ($buckets[$bucket]['stated'] ?? null) : null;
        if ($stated !== null && $stated > 0.0) {
            return [$stated, 'summary_recap'];
        }

        $code = trim($rateEl->textContent);
        if ($code === 'none' || $code === 'nonSubsume') {
            return [0.0, 'exempt_enum'];
        }

        return [null, 'unresolved'];
    }

    /**
     * Aktuální české procento sazbové úrovně — VÝHRADNĚ jako koeficient pro převod brutto
     * ceny na netto ({@see self::parseItem()}), nikdy jako odpověď na otázku „jaká je
     * sazba tohohle řádku".
     *
     * Rozdíl není formální. Cena je vlastnost dokladu a chyba v ní je vidět na první
     * pohled (součet nesedí na originál), kdežto sazba rozhoduje o tom, KOMU se daň
     * odvádí — a tam se chyba pozná až z výzvy správce daně. Provizorní koeficient proto
     * nesmí opustit parser: položka, u které se použil, si nese brutto cenu
     * v `unit_price_with_vat` a importní vrstva ji po rozhodnutí o sazbě přepočítá.
     */
    private static function currentCzechScaleRate(?string $level): ?float
    {
        return match ($level) {
            'standard'       => 21.0,
            'reduced'        => 12.0,
            'second_reduced' => 10.0,
            default          => null,
        };
    }

    /** Procento sazby z textu/atributu — jen kladná hodnota v rozsahu (0;100] dává smysl. */
    private static function percentOrNull(string $raw): ?float
    {
        if (!is_numeric($raw)) {
            return null;
        }
        $val = (float) $raw;
        return ($val > 0.0 && $val <= 100.0) ? $val : null;
    }

    private function text(\DOMXPath $xpath, string $expr, \DOMNode $context): string
    {
        $node = $xpath->query($expr, $context)->item(0);
        return $node ? trim($node->textContent) : '';
    }
}
