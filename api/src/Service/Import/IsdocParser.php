<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Invoice\TimeBilling;
use MyInvoice\Service\Xml\SafeXmlLoader;
use MyInvoice\Service\Xml\UnsafeXmlException;

/**
 * Parser ISDOC 5.x a 6.x — extrahuje fakturu do normalizovaného array.
 *
 * Vrací {supplier_ic, invoices[]} (pro konzistenci se single-file API; ISDOC typicky 1 invoice / file).
 *
 * Reverzní mapování k IsdocExporter — DocumentType, ID, Issue/TaxDate, parties, lines, totals.
 *
 * Output shape per invoice — viz PohodaXmlParser.
 *
 * ── Chybějící procento NENÍ nula ────────────────────────────────────────────────────
 * `vat_rate` je `null`, když řádek sazbu NEUVÁDÍ (chybí `ClassifiedTaxCategory/Percent`
 * nebo v něm není číslo). Dřív se tiše přetypoval na 0.0, takže ze zdaněného řádku bylo
 * osvobozené plnění — tentýž únik cizí daně jako u Pohody, jen jinou větví: invariant
 * proti úniku se na nulovou sazbu ZÁMĚRNĚ neuplatňuje (u plnění bez daně není co unikat),
 * takže by cizí daň nezmizela do špatné země, ale z dokladu úplně.
 *
 * Od chybějícího procenta se odlišuje LEGITIMNÍ nula: doklad nebo řádek s
 * `<VATApplicable>false</VATApplicable>` je nedaňový (ISDOC 4.1.5) a nula je tam
 * prohlášení, ne mlčení. Stejně tak explicitní `<Percent>0</Percent>`.
 *
 * `vat_rate_source` říká, odkud sazba je: `percent` (řádek ji uvádí), `non_tax_document`
 * / `non_tax_line` (nedaňové plnění, nula je prohlášení), `unresolved` (řádek sazbu
 * neuvádí — volající to MUSÍ ošetřit jako vadu dokladu, ne jako nulu).
 *
 * `file_issues` nese rozpory mezi ŘÁDKY a REKAPITULACÍ téhož souboru — viz
 * {@see self::recapConflicts()}.
 */
final class IsdocParser
{
    /** ISDOC 6.x namespace (náš exporter, iDoklad, Fakturoid…). */
    private const NS_2013 = 'http://isdoc.cz/namespace/2013';
    /** Strop vstupu; nad limity uploadu (20 MiB), skutečnou cenu hlídá počet uzlů. */
    private const MAX_XML_BYTES = 32 * 1024 * 1024;
    /** Starší ISDOC 5.x namespace — struktura čtených elementů je kompatibilní. */
    private const NS_LEGACY = 'http://isdoc.cz/namespace/invoice';
    /** Namespace, které parser umí — prefix `i:` se navazuje na ten z kořene dokladu. */
    private const SUPPORTED_NS = [self::NS_2013, self::NS_LEGACY];

    /**
     * Odchylka křížové kontroly řádků proti rekapitulaci — shodná s
     * {@see PohodaXmlParser}. Relativní složka je tu kvůli slevám rozpočítaným do řádků,
     * které do součtu zanášejí haléře; sebeodporující soubor se liší o víc.
     */
    private const RECAP_ABS_TOLERANCE = 0.05;
    private const RECAP_REL_TOLERANCE = 0.005;

    /**
     * @return array{supplier_ic:?string, invoices:list<array<string,mixed>>}
     */
    public function parse(string $xml): array
    {
        // XXE / billion-laughs i paměťová amplifikace DOM: DOCTYPE (v jakémkoli
        // kódování) a nadměrný počet uzlů odmítne sdílený loader dřív, než se
        // postaví strom. Nepoužíváme NOENT, síť je zakázaná.
        try {
            $dom = SafeXmlLoader::load($xml, self::MAX_XML_BYTES);
        } catch (UnsafeXmlException $e) {
            throw new \RuntimeException(match ($e->reason) {
                UnsafeXmlException::DOCTYPE        => 'ISDOC XML obsahuje DOCTYPE, což není povoleno.',
                UnsafeXmlException::INVALID        => 'Nelze parsovat ISDOC XML.',
                default                            => 'ISDOC XML je příliš rozsáhlé na zpracování.',
            });
        }

        $root = $dom->documentElement;
        if ($root->localName !== 'Invoice') {
            throw new \RuntimeException('Není ISDOC — root není Invoice.');
        }

        // Namespace bereme z kořene dokladu, ať funguje ISDOC 6.x (…/2013) i starší
        // 5.x (…/invoice) — struktura čtených elementů je shodná, liší se jen NS.
        // Dřív byl NS natvrdo 2013, takže 5.2 doklad nenašel ani <ID> a spadl na
        // zavádějící „Chybí ISDOC ID" (issue #208).
        $ns = (string) $root->namespaceURI;
        if (!in_array($ns, self::SUPPORTED_NS, true)) {
            throw new \RuntimeException(sprintf(
                'Nepodporovaný ISDOC namespace „%s". Podporováno je ISDOC 6.x (%s) a 5.x (%s).',
                $ns !== '' ? $ns : '(žádný)',
                self::NS_2013,
                self::NS_LEGACY,
            ));
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('i', $ns);
        $xpath->registerNamespace('myi', IsdocExporter::MYINVOICE_EXTENSION_NS);

        try {
            $parsed = $this->parseInvoice($root, $xpath);
        } catch (\Throwable $e) {
            return ['supplier_ic' => null, 'invoices' => [['__error' => $e->getMessage()]]];
        }

        // Top-level supplier_ic — zachováno pro BC s issued invoice import flow.
        // Plus per-invoice `supplier` party data (pro purchase invoice mapper —
        // vendor identifikace včetně adresy, DIČ, kontaktu).
        $supplierIc = $this->text($xpath, 'i:AccountingSupplierParty/i:Party/i:PartyIdentification/i:ID', $root) ?: null;

        return ['supplier_ic' => $supplierIc, 'invoices' => [$parsed]];
    }

    /**
     * @return array<string,mixed>
     */
    private function parseInvoice(\DOMElement $root, \DOMXPath $xpath): array
    {
        // DocumentType dle ISDOC 6.0.2 číselníku DocumentTypeType:
        //   1 = faktura, 2 = dobropis, 3 = vrubopis, 4 = zálohová faktura (nedaňová),
        //   5 = daňový zálohový list, 6 = dobropis DZL, 7 = zjednodušený daň. doklad.
        // Dobropis i jeho zálohová varianta → credit_note; nedaňová záloha → proforma.
        //
        // Daňový zálohový list (5) je DAŇOVÝ doklad k přijaté platbě, tedy náš
        // `tax_document` — přesně pod tímhle kódem ho zapisuje IsdocExporter. Dřív padal
        // do `proforma`, takže vlastní vyexportovaný DDPP (a DDPP ze Shoptetu) se vracel
        // jako nedaňová záloha a jeho DPH z evidence zmizela. Přijatá strana si původní
        // chování drží v IsdocToPurchaseInvoiceMapper::mapDocumentKind().
        $docType = (int) ($this->text($xpath, 'i:DocumentType', $root) ?: '1');
        $invoiceType = match ($docType) {
            2, 6    => 'credit_note',
            4       => 'proforma',
            5       => 'tax_document',
            default => 'invoice',
        };

        $varsymbol = $this->text($xpath, 'i:ID', $root);
        if ($varsymbol === '') {
            throw new \RuntimeException('Chybí ISDOC ID (varsymbol).');
        }

        $issueDate = $this->text($xpath, 'i:IssueDate', $root);
        $taxDate   = $this->text($xpath, 'i:TaxPointDate', $root) ?: null;
        $dueDate   = $this->text($xpath, 'i:PaymentMeans/i:Payment/i:Details/i:PaymentDueDate', $root) ?: $issueDate;

        $localCur = strtoupper($this->text($xpath, 'i:LocalCurrencyCode', $root) ?: 'CZK');
        // Schema-validní ISDOC 6.0.2 používá <ForeignCurrencyCode>; starší soubory
        // (i náš vlastní exporter před v3.6.2) používaly <CurrencyCode>. Čteme oboje
        // pro kompatibilitu s exporty od jiných systémů i s naším round-tripem.
        $foreignCur = strtoupper(
            $this->text($xpath, 'i:ForeignCurrencyCode', $root)
            ?: $this->text($xpath, 'i:CurrencyCode', $root)
            ?: ''
        );
        $currency = $foreignCur !== '' ? $foreignCur : $localCur;
        $rate = null;
        if ($currency !== $localCur) {
            $rate = self::exchangeRate(
                $this->text($xpath, 'i:CurrRate', $root),
                $this->text($xpath, 'i:RefCurrRate', $root),
            );
        }

        // Reverse charge (přenesená daň. povinnost) = ISDOC <LocalReverseChargeFlag>true</…>
        // na úrovni TaxTotal/TaxSubTotal/TaxCategory. POZOR: <VATApplicable>false</…>
        // NEZNAMENÁ reverse charge — to je neplátce DPH / plnění mimo DPH (např. faktura
        // z iDokladu od neplátce, issue #41). RC proto čteme výhradně z LocalReverseChargeFlag.
        $reverseCharge = false;
        foreach ($xpath->query('.//i:LocalReverseChargeFlag', $root) ?: [] as $flagEl) {
            if (strtolower(trim($flagEl->textContent)) === 'true') {
                $reverseCharge = true;
                break;
            }
        }

        // VATApplicable na úrovni dokladu: false = nedaňový doklad (neplátce DPH /
        // plnění mimo DPH / nedaňový zálohový list, DocumentType 4). Dle ISDOC 4.1.5
        // jsou pak nedaňové i všechny řádky → sazbu i rekapitulaci importujeme jako 0.
        // Chybějící element = daňový doklad (legacy, default true).
        $isTaxDocument = strtolower($this->text($xpath, 'i:VATApplicable', $root)) !== 'false';

        // Klient: AccountingCustomerParty/Party
        $partyEl = $xpath->query('i:AccountingCustomerParty/i:Party', $root)->item(0);
        $client = $partyEl instanceof \DOMElement ? $this->parseParty($xpath, $partyEl) : [];

        // Dodavatel: AccountingSupplierParty/Party — pro purchase invoice mapper,
        // kde my jsme zákazník a potřebujeme vytvořit vendor záznam z těchto dat.
        $supplierPartyEl = $xpath->query('i:AccountingSupplierParty/i:Party', $root)->item(0);
        $supplier = $supplierPartyEl instanceof \DOMElement ? $this->parseParty($xpath, $supplierPartyEl) : [];

        // Project number — schema-validní ISDOC 6.0.2 obaluje reference do wrapper
        // kolekce (<OrderReferences>/<OrderReference>/<SalesOrderID>) a v contract
        // referenci je @id atribut + <ID> element. Starší / non-conforming exporty
        // používaly přímý <OrderReference>/<ID>. Čteme nové cesty jako primární
        // a staré jako fallback pro kompat s ISDOC od jiných systémů.
        $projectNumber = $this->text($xpath, 'i:OrderReferences/i:OrderReference/i:SalesOrderID', $root)
            ?: $this->text($xpath, 'i:OrderReference/i:SalesOrderID', $root)
            ?: $this->text($xpath, 'i:OrderReference/i:ID', $root)
            ?: $this->text($xpath, 'i:ContractReferences/i:ContractReference/i:ID', $root)
            ?: $this->text($xpath, 'i:ContractReference/i:ID', $root)
            ?: null;

        // Items
        $hasForeignCurrency = $currency !== $localCur;
        $durationMinutesByLineId = $this->timeBillingDurations($xpath, $root);
        $items = [];
        foreach ($xpath->query('i:InvoiceLines/i:InvoiceLine', $root) ?: [] as $lineEl) {
            if (!$lineEl instanceof \DOMElement) continue;
            $lineId = $this->text($xpath, 'i:ID', $lineEl);
            $explicitDurationMinutes = $lineId !== '' && array_key_exists($lineId, $durationMinutesByLineId)
                ? $durationMinutesByLineId[$lineId]
                : null;
            $items[] = $this->parseLine(
                $xpath,
                $lineEl,
                $hasForeignCurrency,
                $isTaxDocument,
                $rate,
                $explicitDurationMinutes,
            );
        }
        // Nedaňový doklad (VATApplicable=false) DPH nepřiznává → prázdná rekapitulace.
        $recap = $isTaxDocument ? $this->parseTaxRecap($xpath, $root) : [];

        return [
            'invoice_type'   => $invoiceType,
            'document_type_code' => $docType,
            'varsymbol'      => $varsymbol,
            'issue_date'     => $issueDate,
            'tax_date'       => $taxDate,
            'due_date'       => $dueDate,
            'currency'       => $currency,
            'exchange_rate'  => $rate,
            'reverse_charge' => $reverseCharge,
            'note_above'     => null,
            'project_number' => $projectNumber,
            'client'         => $client,    // AccountingCustomerParty (zákazník)
            'supplier'       => $supplier,  // AccountingSupplierParty (dodavatel — pro purchase invoice mapper)
            // Platební účet dodavatele z <PaymentMeans> — pro „Zaplatit pomocí QR"
            // u přijatých faktur (číslo účtu / IBAN / VS).
            'payment'        => $this->parsePayment($xpath, $root),
            'items'          => $items,
            // Částka „k úhradě" z <LegalMonetaryTotal>/<PayableAmount> — už zahrnuje
            // <PayableRoundingAmount> (haléřové zaokrouhlení dodavatele). Mapper z ní
            // dopočítá rounding offset proti součtu položek (viz IsdocToPurchaseInvoiceMapper).
            // U cizoměnového dokladu preferuje *Curr (v měně faktury, jako řádkové totály).
            'payable_amount' => $this->parsePayableAmount($xpath, $root, $hasForeignCurrency),
            // Částky dokladu v MĚNĚ DOKLADU pro kontrolu převzetí vydaného dokladu
            // ({@see ImportedIssuedDocumentPolicy}). Striktně: u cizoměnového dokladu jen
            // *Curr, bez náhrady částkou v Kč — ta by se s řádky v měně dokladu nikdy nepotkala.
            'monetary'       => [
                'total'           => $this->monetaryAmount($xpath, $root, 'TaxInclusiveAmount', $hasForeignCurrency),
                'payable'         => $this->monetaryAmount($xpath, $root, 'PayableAmount', $hasForeignCurrency),
                'paid_deposits'   => $this->monetaryAmount($xpath, $root, 'PaidDepositsAmount', $hasForeignCurrency),
                'already_claimed' => $this->monetaryAmount($xpath, $root, 'AlreadyClaimedTaxInclusiveAmount', $hasForeignCurrency),
                'rounding'        => $this->monetaryAmount($xpath, $root, 'PayableRoundingAmount', $hasForeignCurrency),
            ],
            // Zdaněné zálohy, které doklad odečítá (ISDOC `TaxedDeposits/TaxedDeposit`).
            'taxed_deposits' => $this->parseTaxedDeposits($xpath, $root),
            // Rekapitulace DPH po sazbách z <TaxTotal>/<TaxSubTotal> — pro seed
            // override (PurchaseVatRecapSeeder), aby naše evidence seděla na doklad.
            'vat_recap'      => $recap,
            // Rozpory MEZI řádky a rekapitulací TÉHOŽ souboru (§ G2).
            'file_issues'    => self::recapConflicts($items, $recap),
        ];
    }

    /**
     * Kurz za JEDNU jednotku cizí měny z `<CurrRate>` a `<RefCurrRate>`.
     *
     * ISDOC vede kurz jako ZLOMEK: `CurrRate` je částka v lokální měně, `RefCurrRate`
     * množství cizí měny, kterému odpovídá. U měn kotovaných po stovkách (HUF, JPY)
     * tedy chodí `CurrRate=6.86` + `RefCurrRate=100`; SuperFaktura zapisuje týž poměr
     * obráceně jako `CurrRate=1` + `RefCurrRate=14.5688`. Obojí je totéž číslo a obojí
     * musí projít dělením.
     *
     * Čtení samotného `CurrRate` bylo tiché a drahé: forintový doklad dostal kurz 1,00,
     * takže se 13 520 HUF zaúčtovalo jako 13 520 Kč místo 844 Kč — u dokladu před
     * registrací do OSS rovnou na ř. 1 přiznání k DPH, tedy čtrnáctinásobný základ.
     *
     * Chybějící, nečíselný nebo nekladný `RefCurrRate` znamená 1 — XSD mu default
     * nedává a nula by dělila nulou. Nekladný `CurrRate` vrací `null` (kurz neznáme),
     * ať se dosadí kurz ČNB k DUZP místo nesmyslné nuly.
     */
    private static function exchangeRate(string $rateRaw, string $refRaw): ?float
    {
        if ($rateRaw === '' || !is_numeric($rateRaw)) {
            return null;
        }
        $rate = (float) $rateRaw;
        if ($rate <= 0.0) {
            return null;
        }
        $ref = is_numeric($refRaw) ? (float) $refRaw : 1.0;
        if ($ref <= 0.0) {
            $ref = 1.0;
        }

        return $rate / $ref;
    }

    /**
     * Rozpory mezi ŘÁDKY a REKAPITULACÍ téhož souboru.
     *
     * Táž otázka a tytéž hlášky jako {@see PohodaXmlParser::recapConflicts()} — jen nad
     * jiným tvarem souboru (`TaxTotal/TaxSubTotal` místo `invoiceSummary`). Sjednotit obě
     * do jednoho místa by znamenalo sdílenou třídu nad oběma parsery; dokud nevznikne,
     * platí, že se pravidlo mění na OBOU místech naráz — rozejít se smějí nanejvýš
     * v tom, odkud čísla berou.
     *
     * Importují se částky a sazby z ŘÁDKŮ (výkazy sumují řádky), takže rozpor import
     * nezastaví, ale musí se dostat uživateli před oči. Řádek s neurčenou sazbou
     * kontrolu vypíná — doklad je pak stejně k odmítnutí s konkrétnější hláškou.
     *
     * @param  list<array<string,mixed>>                  $items
     * @param  array<string,array{base:float,vat:float}>  $recap
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
                // Nulovou sazbu rekapitulace nevede (`parseTaxRecap()` ji přeskakuje),
                // takže by v ní chyběla vždycky.
                continue;
            }
            $key = number_format($rate, 2, '.', '');
            // Sčítá se SE ZNAMÉNKEM, absolutní hodnota se bere až ze součtu. Řádek se
            // záporným součtem (slevový kupón, dobropisovaný kus, storno položky) je
            // v e-shopových exportech běžný a per-řádkové `abs()` ho přičítalo místo
            // odečítalo: doklad 1 195 + 74 − 165 + 65 vycházel na 1 500 proti rekapitulaci
            // 1 169 a uživatel dostal hlášku o rozporu, který v souboru není. Rekapitulace
            // je z `parseTaxRecap()` kladná i u dobropisu, proto se srovnává až `abs()`
            // celého součtu — jinak by falešný poplach jen přeskočil na opravné doklady.
            $itemBases[$key] = ($itemBases[$key] ?? 0.0)
                + TimeBilling::invoiceAmountInput($item);
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
                'Doklad si v souboru odporuje: řádky nesou sazby %s, ale rekapitulace dokladu '
                    . '(TaxTotal) uvádí %s. Importují se sazby z řádků — zkontrolujte zdrojový '
                    . 'soubor, obě čísla platit zároveň nemůžou.',
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
                'Doklad si v souboru odporuje: součet řádků se sazbou %s %% je %s, ale '
                    . 'rekapitulace dokladu uvádí základ %s. Importují se částky z řádků.',
                self::fmtRate((float) $key),
                self::fmtAmount($base),
                self::fmtAmount($recapBase),
            );
        }

        return $issues;
    }

    /** @param list<string> $keys */
    private static function fmtRateList(array $keys): string
    {
        return $keys === []
            ? 'žádnou'
            : implode(', ', array_map(static fn (string $k): string => self::fmtRate((float) $k) . ' %', $keys));
    }

    private static function fmtRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, ',', ' '), '0'), ',');
    }

    private static function fmtAmount(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }

    /**
     * Rekapitulace DPH po sazbách z `<TaxTotal>/<TaxSubTotal>`.
     *
     * Vrací rateKey (`number_format(rate,2,'.','')`) => kladné `{base, vat}`.
     * U cizoměnových dokladů preferuje `*Curr` varianty (TaxableAmountCurr/
     * TaxAmountCurr), protože ty jsou v měně faktury — stejně jako naše řádkové
     * totály; `TaxableAmount`/`TaxAmount` jsou dle ISDOC vždy v lokální měně (CZK).
     * Sazba 0 % se vynechá (není co srovnávat).
     *
     * @return array<string,array{base:float,vat:float}>
     */
    private function parseTaxRecap(\DOMXPath $xpath, \DOMElement $root): array
    {
        $out = [];
        foreach ($xpath->query('i:TaxTotal/i:TaxSubTotal', $root) ?: [] as $sub) {
            if (!$sub instanceof \DOMElement) {
                continue;
            }
            $percent = $this->text($xpath, 'i:TaxCategory/i:Percent', $sub);
            if ($percent === '') {
                continue;
            }
            $rate = (float) $percent;
            if ($rate <= 0.0) {
                continue;
            }
            $baseCurr = $this->text($xpath, 'i:TaxableAmountCurr', $sub);
            $vatCurr  = $this->text($xpath, 'i:TaxAmountCurr', $sub);
            $base = (float) (($baseCurr !== '' ? $baseCurr : $this->text($xpath, 'i:TaxableAmount', $sub)) ?: '0');
            $vat  = (float) (($vatCurr !== '' ? $vatCurr : $this->text($xpath, 'i:TaxAmount', $sub)) ?: '0');
            $key = number_format($rate, 2, '.', '');
            if (!isset($out[$key])) {
                $out[$key] = ['base' => 0.0, 'vat' => 0.0];
            }
            $out[$key]['base'] += abs($base);
            $out[$key]['vat']  += abs($vat);
        }
        return $out;
    }

    /**
     * Platební údaje dodavatele z `<PaymentMeans>/<Payment>/<Details>`.
     * Schema generuje BankAccount group jako INLINE elementy (žádný <BankAccount>
     * wrapper): ID = číslo účtu, BankCode, IBAN, BIC; plus VariableSymbol.
     * Slouží pro QR platbu u přijatých faktur (zdroj `isdoc`).
     *
     * @return array{account_number:?string,bank_code:?string,iban:?string,bic:?string,variable_symbol:?string}
     */
    private function parsePayment(\DOMXPath $xpath, \DOMElement $root): array
    {
        $base = 'i:PaymentMeans/i:Payment/i:Details/';
        $account = $this->text($xpath, $base . 'i:ID', $root);
        $bank    = $this->text($xpath, $base . 'i:BankCode', $root);
        $iban    = strtoupper((string) preg_replace('/\s+/', '', $this->text($xpath, $base . 'i:IBAN', $root)));
        $bic     = strtoupper((string) preg_replace('/\s+/', '', $this->text($xpath, $base . 'i:BIC', $root)));
        $vs      = $this->text($xpath, $base . 'i:VariableSymbol', $root);

        return [
            'account_number'  => $account !== '' ? $account : null,
            'bank_code'       => $bank !== '' ? $bank : null,
            'iban'            => $iban !== '' ? $iban : null,
            'bic'             => $bic !== '' ? $bic : null,
            'variable_symbol' => $vs !== '' ? $vs : null,
        ];
    }

    /**
     * @return array<string,?string>
     */
    private function parseParty(\DOMXPath $xpath, \DOMElement $party): array
    {
        // Schema rozděluje adresu na <StreetName> + <BuildingNumber>; pro náš
        // model držíme jednu jednolitou hodnotu `street`, takže je při čtení
        // zase slijeme. Pokud BuildingNumber chybí (legacy exporty), použijeme
        // jen StreetName beze změny.
        $streetName = $this->text($xpath, 'i:PostalAddress/i:StreetName', $party);
        $buildingNumber = $this->text($xpath, 'i:PostalAddress/i:BuildingNumber', $party);
        $street = trim($streetName . ($buildingNumber !== '' ? ' ' . $buildingNumber : ''));

        return [
            'company_name' => $this->text($xpath, 'i:PartyName/i:Name', $party) ?: null,
            'ic'           => $this->text($xpath, 'i:PartyIdentification/i:ID', $party) ?: null,
            'dic'          => $this->text($xpath, 'i:PartyTaxScheme/i:CompanyID', $party) ?: null,
            'street'       => $street !== '' ? $street : null,
            'city'         => $this->text($xpath, 'i:PostalAddress/i:CityName', $party) ?: null,
            'zip'          => $this->text($xpath, 'i:PostalAddress/i:PostalZone', $party) ?: null,
            'country_iso2' => strtoupper($this->text($xpath, 'i:PostalAddress/i:Country/i:IdentificationCode', $party)) ?: null,
            'email'        => $this->text($xpath, 'i:Contact/i:ElectronicMail', $party) ?: null,
            'phone'        => $this->text($xpath, 'i:Contact/i:Telephone', $party) ?: null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function parseLine(
        \DOMXPath $xpath,
        \DOMElement $line,
        bool $hasForeignCurrency = false,
        bool $isTaxDocument = true,
        ?float $exchangeRate = null,
        ?int $explicitDurationMinutes = null,
    ): array
    {
        $qtyEl = $xpath->query('i:InvoicedQuantity', $line)->item(0);
        $quantity = $qtyEl instanceof \DOMElement ? (float) $qtyEl->textContent : 1.0;
        $unit = $qtyEl instanceof \DOMElement ? ($qtyEl->getAttribute('unitCode') ?: 'ks') : 'ks';
        $durationMinutes = $this->validExplicitDurationMinutes($explicitDurationMinutes, $quantity, $unit)
            ?? TimeBilling::inferDurationMinutes($quantity, $unit);

        $unitPriceLocalRaw = $this->text($xpath, 'i:UnitPrice', $line) ?: '0';
        $unitPriceLocal = (float) $unitPriceLocalRaw;
        $preciseUnitPrice = $durationMinutes !== null || self::hasSubcentPrecision($unitPriceLocalRaw);

        // <LineExtensionAmount> = celková částka řádku bez DPH PO slevě. ISDOC 6.0.x
        // nemá na řádce dedikovaný discount element — sleva se promítá jen tím, že
        // LineExtensionAmount < UnitPrice × množství. iDoklad takhle exportuje i
        // dokladovou slevu (DiscountType=OnDocument), kterou rozpočítá do řádků.
        // Když efektivní jednotková cena (LineExtensionAmount / qty) nesedí na
        // <UnitPrice>, je v řádce sleva a musíme importovat sníženou cenu — jinak
        // se faktura naimportuje za plnou (před-slevovou) cenu a součet je chybný
        // (issue #48: import z iDokladu přes PDF s embedded ISDOC ignoroval slevu).
        if ($hasForeignCurrency) {
            // <UnitPrice> je dle ISDOC vždy v lokální měně (CZK), ne v měně faktury.
            // Legacy cizoměnovou jednotkovou cenu po slevě odvodíme z
            // <LineExtensionAmountCurr> / qty. Řádek s přesnou cenou může mít Curr total
            // zaokrouhlený na haléře, proto u něj obnovíme sazbu z lokální UnitPrice
            // a kurzu, ale jen pokud z ní vyjde stejný řádkový total bez slevy.
            // Fallback na <UnitPrice> drží pro nekonformní exporty cizích systémů,
            // které *Curr vynechají a cizí hodnotu zapíšou rovnou do <UnitPrice>.
            $lineAmountCurr = $this->text($xpath, 'i:LineExtensionAmountCurr', $line);
            $unitPrice = ($lineAmountCurr !== '' && $quantity > 0.0)
                ? (float) $lineAmountCurr / $quantity
                : $unitPriceLocal;
            if ($preciseUnitPrice && $lineAmountCurr !== '' && $quantity != 0.0
                && $exchangeRate !== null && $exchangeRate > 0.0
            ) {
                $rateFromLocalUnitPrice = round($unitPriceLocal / $exchangeRate, 6);
                $expectedLineAmount = TimeBilling::invoiceAmount([
                    'quantity' => $quantity,
                    'duration_minutes' => $durationMinutes,
                    'unit_price_without_vat' => $rateFromLocalUnitPrice,
                ]);
                if (abs((float) $lineAmountCurr - $expectedLineAmount) <= 0.005) {
                    $unitPrice = $rateFromLocalUnitPrice;
                }
            }
        } else {
            $unitPrice = $unitPriceLocal;
            $lineAmount = $this->text($xpath, 'i:LineExtensionAmount', $line);
            if ($lineAmount !== '' && $quantity > 0.0) {
                $effective = (float) $lineAmount / $quantity;
                // Legacy toleranci porovnání jednotkových cen držíme beze změny.
                // Jen řádek s přesnou cenou (čas, cena pod haléř) smí místo ní porovnat
                // zaokrouhlený total, protože dělení haléřové částky zničí přesnou cenu.
                $isDiscounted = abs($effective - $unitPriceLocal) > 0.005;
                if ($preciseUnitPrice) {
                    $expectedLineAmount = TimeBilling::invoiceAmount([
                        'quantity' => $quantity,
                        'duration_minutes' => $durationMinutes,
                        'unit_price_without_vat' => $unitPriceLocal,
                    ]);
                    $isDiscounted = abs((float) $lineAmount - $expectedLineAmount) > 0.005;
                }
                if ($isDiscounted) {
                    $unitPrice = $effective;
                }
            }
        }

        // ISDOC 4.1.5: nedaňová položka nepodléhá DPH bez ohledu na Percent. Řádek je
        // nedaňový, je-li nedaňový celý doklad (VATApplicable=false) nebo má-li vlastní
        // <ClassifiedTaxCategory><VATApplicable>false</VATApplicable></…>. To je
        // PROHLÁŠENÍ o plnění bez daně, takže nula je tam správně.
        $lineVatApplicable = strtolower($this->text($xpath, 'i:ClassifiedTaxCategory/i:VATApplicable', $line));
        if (!$isTaxDocument) {
            [$vatRate, $rateSource] = [0.0, 'non_tax_document'];
        } elseif ($lineVatApplicable === 'false') {
            [$vatRate, $rateSource] = [0.0, 'non_tax_line'];
        } else {
            // Chybějící <Percent> naproti tomu prohlášení NENÍ — je to mlčení. Přetyp na
            // 0.0 z něj dělal osvobozené plnění, které invariant proti úniku cizí daně
            // vůbec neprověřuje, takže daň z dokladu tiše zmizela celá.
            $percent = $this->text($xpath, 'i:ClassifiedTaxCategory/i:Percent', $line);
            [$vatRate, $rateSource] = is_numeric($percent)
                ? [(float) $percent, 'percent']
                : [null, 'unresolved'];
        }

        return [
            'description'            => $this->text($xpath, 'i:Item/i:Description', $line),
            // Kód položky prodávajícího: podle něj páruje pravidla výnosů vydaných faktur.
            'seller_item_code'       => $this->text($xpath, 'i:Item/i:SellersItemIdentification/i:ID', $line) ?: null,
            'quantity'               => $quantity,
            'duration_minutes'       => $durationMinutes,
            'unit'                   => $unit,
            'unit_price_without_vat' => $unitPrice,
            'vat_rate'               => $vatRate,
            'vat_rate_source'        => $rateSource,
        ];
    }

    /** @return array<string,int> */
    private function timeBillingDurations(\DOMXPath $xpath, \DOMElement $root): array
    {
        $durations = [];
        $duplicates = [];
        foreach ($xpath->query('i:Extensions/myi:TimeBilling/myi:Line', $root) ?: [] as $line) {
            if (!$line instanceof \DOMElement) {
                continue;
            }
            $id = trim($line->getAttribute('id'));
            $rawMinutes = trim($line->getAttribute('durationMinutes'));
            if ($id === '' || isset($duplicates[$id])) {
                continue;
            }
            try {
                $normalized = TimeBilling::normalizeInvoiceItem([
                    'quantity' => 0,
                    'duration_minutes' => $rawMinutes,
                    'unit' => 'h',
                    'unit_price_without_vat' => 0,
                ]);
                $minutes = $normalized['duration_minutes'];
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($minutes === null) {
                continue;
            }
            if (array_key_exists($id, $durations)) {
                unset($durations[$id]);
                $duplicates[$id] = true;
                continue;
            }
            $durations[$id] = $minutes;
        }

        return $durations;
    }

    private function validExplicitDurationMinutes(?int $minutes, float $quantity, string $unit): ?int
    {
        if ($minutes === null || !TimeBilling::isHourUnit($unit)) {
            return null;
        }

        return abs($quantity - $minutes / 60) <= 0.000000001 ? $minutes : null;
    }

    private static function hasSubcentPrecision(string $value): bool
    {
        if (!is_numeric($value)) {
            return false;
        }
        $number = (float) $value;
        return is_finite($number) && abs($number - round($number, 2)) > 0.000000001;
    }

    /**
     * Částka „k úhradě" z <LegalMonetaryTotal>/<PayableAmount>. Už zahrnuje
     * <PayableRoundingAmount> (zaokrouhlení) i <PaidDepositsAmount> (zálohy).
     * U cizoměnového dokladu preferuje *Curr (v měně faktury, jako řádkové totály).
     * Vrací null, když doklad částku k úhradě neuvádí.
     */
    private function parsePayableAmount(\DOMXPath $xpath, \DOMElement $root, bool $foreignCurrency): ?float
    {
        $curr = $this->text($xpath, 'i:LegalMonetaryTotal/i:PayableAmountCurr', $root);
        $loc  = $this->text($xpath, 'i:LegalMonetaryTotal/i:PayableAmount', $root);
        $val  = ($foreignCurrency && $curr !== '') ? $curr : $loc;
        return $val !== '' ? (float) $val : null;
    }

    /**
     * Částka z `<LegalMonetaryTotal>` v měně dokladu. U cizoměnového dokladu jen `*Curr`
     * varianta, jinak `null` (částka v Kč by se s řádky v měně dokladu nepotkala).
     */
    private function monetaryAmount(\DOMXPath $xpath, \DOMElement $root, string $name, bool $foreignCurrency): ?float
    {
        $val = $this->text($xpath, 'i:LegalMonetaryTotal/i:' . $name . ($foreignCurrency ? 'Curr' : ''), $root);

        return is_numeric($val) ? (float) $val : null;
    }

    /**
     * Zdaněné zálohy odečítané dokladem (`<TaxedDeposits>/<TaxedDeposit>`).
     *
     * @return list<array{id:?string, varsymbol:?string}>
     */
    private function parseTaxedDeposits(\DOMXPath $xpath, \DOMElement $root): array
    {
        $out = [];
        foreach ($xpath->query('i:TaxedDeposits/i:TaxedDeposit', $root) ?: [] as $el) {
            if (!$el instanceof \DOMElement) {
                continue;
            }
            $id = $this->text($xpath, 'i:ID', $el);
            $vs = $this->text($xpath, 'i:VariableSymbol', $el);
            $out[] = ['id' => $id !== '' ? $id : null, 'varsymbol' => $vs !== '' ? $vs : null];
        }

        return $out;
    }

    private function text(\DOMXPath $xpath, string $expr, \DOMNode $context): string
    {
        $node = $xpath->query($expr, $context)->item(0);
        return $node ? trim($node->textContent) : '';
    }
}
