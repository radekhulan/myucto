<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Tax\BadDebt\Section46Service;
use MyInvoice\Service\Tax\BadDebt\Section74bService;

/**
 * Builder XML pro Kontrolní hlášení (DPHKH1) — EPO portál MFČR.
 *
 * Verze EPO: 03.01 (platná 2025-2026).
 *
 * Periodicita (§ 101e zákona 235/2004 Sb.):
 *   - **PO** (právnická osoba) — VŽDY měsíčně (odst. 1).
 *   - **FO** (fyzická osoba/OSVČ) — ve lhůtě přiznání k DPH; pro kvartální plátce
 *     lze podávat kvartálně (odst. 2).
 *
 * Sekce KH:
 *   - **A.1** Plnění v režimu přenesené daňové povinnosti (dodavatel)
 *   - **A.2** Přijatá plnění od osoby neusazené v tuzemsku, kde daň přiznává příjemce
 *     (pořízení zboží § 25, služba § 24/§ 9/1, zboží s instalací). Patří sem
 *     i dodavatel bez EU DIČ (3. země, neplátce z EU) — jen s prázdnou identifikací,
 *     viz komentář u emisního bloku VetaA2.
 *   - **A.3** Plnění uskutečněná § 92a/b (dodání investičního zlata)
 *   - **A.4** Tuzemská plnění s DPH nad 10 000 Kč (vystavené)
 *   - **A.5** Tuzemská plnění s DPH **do** 10 000 Kč (sumace)
 *   - **B.1** Plnění v režimu přenesené daňové povinnosti (odběratel)
 *   - **B.2** Tuzemská přijatá plnění s DPH nad 10 000 Kč
 *   - **B.3** Tuzemská přijatá plnění s DPH **do** 10 000 Kč (sumace)
 *
 * ⚠️ Vygenerované XML je POUZE POMŮCKA. Před odesláním vždy ověřit s účetní.
 */
final class KontrolniHlaseniBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly VatLedgerService $ledger,
        // Limit A.4/A.5 a B.2/B.3 (10 000 Kč) + práh základní/snížená sazba — per
        // rok období z číselníku daňových konstant (admin override), ne natvrdo.
        private readonly TaxConstantsRepository $taxConstants,
        // § 74b ZDPH — evidované korekce odpočtu dlužníka (vykazují se v B.2, zdph_44='P').
        private readonly Section74bService $section74b,
        // § 46 ZDPH — evidované věřitelské opravy u nedobytné pohledávky (A.4, zdph_44='P').
        private readonly Section46Service $section46,
        // Archiv podání — základna následného KH (první podání za období je vždy řádné).
        private readonly TaxSubmissionRepository $submissions,
    ) {}

    /**
     * Mapa UI variant → EPO `khdph_forma`. B=řádné, O=řádné/opravné (§101f/1, PŘED lhůtou),
     * N=následné (§101f/2, PO lhůtě), E=následné/opravné. Na rozdíl od DPHDP3 se následné
     * KH NEpodává jako rozdíl, ale ZNOVU — úplné se všemi údaji za období (XSD anotace) —
     * proto je N/E jen jiný atribut nad plným hlášením, bez diffu.
     */
    private const VARIANT_FORMA = [
        'radne'             => 'B',
        'opravne'           => 'O',
        'nasledne'          => 'N',
        'nasledne_opravne'  => 'E',
        // Rychlá odpověď na výzvu (§ 101g) — viz VARIANT_VYZVA_ODP.
        'vyzva_nulove'      => 'B',
        'vyzva_potvrzeni'   => 'N',
    ];

    /**
     * RYCHLÁ ODPOVĚĎ NA VÝZVU správce daně (XSD atribut `vyzva_odp`):
     *   B = nemám povinnost podat KH za období (nulové KH) — je to PRVNÍ podání za období,
     *       proto khdph_forma = B (řádné),
     *   P = potvrzuji správnost naposledy podaného KH (nemění se žádná data) — reaguje na
     *       už podané hlášení, proto khdph_forma = N (následné).
     *
     * XSD: „V případě použití této volby nesmějí být vyplněny řádky v oddílech A, B i C" —
     * odpověď proto neprochází sběrem sekcí vůbec ({@see buildQuickReply()}), a č.j. výzvy
     * je pro zpracování nezbytné.
     *
     * Bez téhle větve zbývala účetní jediná cesta: poslat prázdné řádné KH bez `vyzva_odp`,
     * což se za odpověď na výzvu nepovažuje (§ 101h odst. 1 písm. b — pokuta 30 000 Kč).
     */
    private const VARIANT_VYZVA_ODP = [
        'vyzva_nulove'    => 'B',
        'vyzva_potvrzeni' => 'P',
    ];

    /**
     * Kódy států pro VetaA2.k_stat — tabulka „Daňová identifikační čísla členských států
     * EU“ z dphkh1.xsd. Řecko má DPH kód EL (ne ISO GR), XI je Severní Irsko.
     *
     * GB v seznamu ZÁMĚRNĚ není, přestože ho tabulka v XSD pořád nese: po Brexitu už
     * britské DIČ není DIČ registrace k DPH v členském státě. Dodavatel z GB tak skoří
     * na prázdnou identifikaci, což je pro plnění ze 3. země správně.
     */
    private const KH_MEMBER_STATE_CODES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI',
    ];

    /**
     * @param string $variant 'radne'|'opravne'|'nasledne'|'nasledne_opravne' (C7').
     * @param ?string $dZjist  datum zjištění důvodů (Y-m-d) — pro N/E (§101f/2).
     * @param ?string $cJedVyzvy č.j. výzvy správce daně — jen když N/E reaguje na výzvu.
     * @return array{xml: string, summary: array<string,mixed>, warnings: list<string>, missing_rates: list<array<string,mixed>>}
     */
    public function build(
        int $supplierId,
        int $year,
        int $month,
        string $period = 'monthly',
        string $variant = 'radne',
        ?string $dZjist = null,
        ?string $cJedVyzvy = null,
    ): array {
        $forma = self::VARIANT_FORMA[$variant] ?? null;
        if ($forma === null) {
            throw new PostingException('kh_variant_invalid', "Neznámý typ kontrolního hlášení: {$variant}.", 422);
        }
        $vyzvaOdp   = self::VARIANT_VYZVA_ODP[$variant] ?? null;
        $isFollowUp = $forma === 'N' || $forma === 'E'; // následné — d_zjist / č.j. výzvy
        $cJedVyzvy  = ($isFollowUp || $vyzvaOdp !== null) ? $this->normalizeVyzva($cJedVyzvy) : null;
        $dZjist     = $isFollowUp && $vyzvaOdp === null ? $this->requireValidDate($dZjist) : null;

        [$start, $end, $quarter, $endMonth] = self::periodBounds($year, $month, $period);

        // § 101e odst. 1: právnická osoba podává KH VŽDY měsíčně. UI kvartální přepínač
        // pro PO nezobrazí, ale API se volalo i přímo — a KH s `ctvrt` od PO EPO odmítne.
        // Tvrdá brzda místo dosavadního varování.
        $supplierForPeriod = $this->loadSupplier($supplierId, $end);
        if ($period === 'quarterly' && (string) ($supplierForPeriod['taxpayer_type'] ?? '') === 'po') {
            throw new PostingException(
                'kh_quarterly_not_allowed',
                'Právnická osoba podává kontrolní hlášení vždy měsíčně (§ 101e odst. 1) — '
                    . 'kvartální kontrolní hlášení nelze sestavit.',
                422,
            );
        }

        // Datum zjištění nelze zjistit dřív, než období skončilo, ani v budoucnu.
        if ($dZjist !== null) {
            if ($dZjist < $end) {
                throw new PostingException(
                    'kh_d_zjist_before_period',
                    sprintf(
                        'Datum zjištění důvodů (%s) předchází konci opravovaného období (%s).',
                        $dZjist,
                        $end,
                    ),
                    422,
                );
            }
            // Viz DphPriznaniBuilder: u období, které ještě neskončilo, by kontrola
            // porovnávala dvě budoucí data a jen překážela.
            $today = date('Y-m-d');
            if ($end <= $today && $dZjist > $today) {
                throw new PostingException('kh_d_zjist_future', 'Datum zjištění důvodů nemůže být v budoucnosti.', 422);
            }
        }

        // ── Rychlá odpověď na výzvu (§ 101g) — bez oddílů A/B/C, jen záhlaví + plátce ──
        if ($vyzvaOdp !== null) {
            if ($cJedVyzvy === null) {
                throw new PostingException(
                    'kh_vyzva_ref_required',
                    'Rychlá odpověď na výzvu vyžaduje č.j. výzvy správce daně (ve tvaru '
                        . '99999999/99/9999-99999-999999).',
                    422,
                );
            }
            return $this->buildQuickReply(
                $supplierId, $year, $month, $period, $variant, $forma, $vyzvaOdp,
                $cJedVyzvy, $quarter, $endMonth, $end,
            );
        }

        // Následné KH navazuje na už podané hlášení — první podání za období je VŽDY řádné,
        // i po termínu (XSD anotace khdph_forma). Následné bez základny by správce daně
        // neměl s čím spárovat. Stejná brána, jakou má dodatečné přiznání k DPH.
        if ($isFollowUp) {
            $prior = $this->submissions->findLatestForPeriod(
                $supplierId,
                'dphkh1',
                $year,
                $period === 'quarterly' ? null : $month,
                $period === 'quarterly' ? $quarter : null,
                ['B', 'O'],
            );
            if ($prior === null) {
                throw new PostingException(
                    'kh_no_prior_submission',
                    'Za dané období není evidováno podané řádné kontrolní hlášení. První podání '
                        . 'za období je vždy ŘÁDNÉ, i když je po termínu (§ 101e). Pokud jste řádné '
                        . 'KH podali, označte jeho snapshot v Archivu podání jako podaný.',
                    422,
                );
            }
        }

        // Rozhodný stav plátcovství = POSLEDNÍ DEN období výkazu, ne dnešek (EPIC VH-04)
        // — firma odregistrovaná dnes musí projít validací KH za období, kdy plátcem byla.
        // Zrušení registrace UPROSTŘED období: KH za poslední období plátcovství se pořád
        // podává → warning „není plátce" jen když nebyla plátcem ani jediný den období.
        $supplier = $supplierForPeriod;
        $payerDuring = !empty($supplier['is_vat_payer'])
            || \MyInvoice\Service\Vat\VatStatusService::payerDuring($this->db->pdo(), $supplierId, $start, $end);
        $warnings = $this->validateSupplier($supplier, $period, $payerDuring);
        if ($isFollowUp && $dZjist === null && $cJedVyzvy === null) {
            // XSD anotace d_zjist: u následného KH MUSÍ být vyplněno buď datum zjištění, nebo
            // č.j. výzvy. Dřív to bylo jen varování — jenže download endpoint varování vůbec
            // nevrací (streamuje XML), takže při stažení bez náhledu nebyla žádná zpětná
            // vazba a ven šlo vadné podání. Tvrdá chyba, stejně jako u dodatečného DPH.
            throw new PostingException(
                'kh_d_zjist_required',
                'Následné kontrolní hlášení vyžaduje datum zjištění důvodů, nebo č.j. výzvy '
                    . 'správce daně (§ 101f odst. 2).',
                422,
            );
        }

        // Všechny sekce z jedné projekce kanonických řádků (VatLedgerService).
        ['a1' => $a1, 'a2' => $a2, 'a4' => $a4, 'a5' => $a5, 'b1' => $b1, 'b2' => $b2, 'b3' => $b3,
         'missing_rates' => $missingRates]
            = $this->collectSections($supplierId, $start, $end);
        // #238: doklady v cizí měně bez kurzu — akce je při stažení doplní z ČNB.
        if ($missingRates !== []) {
            $warnings[] = 'Chybí kurz u dokladů v cizí měně: '
                . implode(', ', VatLedgerService::missingExchangeRateLabels($missingRates))
                . '. Při stažení XML se doplní z ČNB.';
        }
        $a1 = $this->filterReverseChargeRowsWithDic($a1, 'A.1', $warnings);
        $b1 = $this->filterReverseChargeRowsWithDic($b1, 'B.1', $warnings);
        // A.2 — doplnění identifikace dodavatele. Řádek se nevyřazuje ani bez ní; musí
        // předcházet rekapitulaci VetaC, ať celk_zd_a2 sedí s tím, co odešlo.
        $a2 = $this->resolveA2Identification($a2, $warnings);
        $a4 = $this->filterKhAttributeConflicts($a4, 'A.4', $warnings);
        $b2 = $this->filterKhAttributeConflicts($b2, 'B.2', $warnings);
        // § 74b korekce odpočtu dlužníka — do B.2 se zdph_44='P' VŽDY (i pod 10 000 Kč);
        // musí předcházet rekapitulaci VetaC, ať pln23/pln5 sedí s DPHDP3 ř. 40/41.
        $this->appendSection74bCorrections($b2, $supplierId, $year, $month, $period, $warnings);
        // § 46 věřitelská oprava u nedobytné pohledávky — zrcadlo výše na vydané straně:
        // do A.4 se zdph_44='P' VŽDY (i pod 10 000 Kč), před rekapitulací VetaC.
        $this->appendSection46Corrections($a4, $supplierId, $year, $month, $period, $warnings);

        [$dom, $dphkh] = EpoEnvelope::create('DPHKH1', '03.01');

        // VetaD — identifikační údaje (mesic pro měsíční, ctvrt pro kvartální)
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('dokument', 'KH1');
        $vetaD->setAttribute('k_uladis', 'DPH');
        if ($period === 'quarterly' && $quarter !== null) {
            $vetaD->setAttribute('ctvrt', (string) $quarter);
        } else {
            $vetaD->setAttribute('mesic', (string) $month);
        }
        $vetaD->setAttribute('rok', (string) $year);
        $vetaD->setAttribute('d_poddp', date('d.m.Y')); // datum podání (dnes)
        $vetaD->setAttribute('khdph_forma', $forma); // B=řádné, O=řádné/opravné, N=následné, E=následné/opravné
        if ($dZjist !== null) {
            // § 101f/2: den zjištění nesprávných/neúplných údajů (DD.MM.YYYY).
            $vetaD->setAttribute('d_zjist', (new \DateTimeImmutable($dZjist))->format('d.m.Y'));
        }
        if ($cJedVyzvy !== null) {
            $vetaD->setAttribute('c_jed_vyzvy', $cJedVyzvy);
        }
        $dphkh->appendChild($vetaD);

        // VetaP — identifikace plátce (sdíleno s DPHDP3 přes EpoSupplierBlockBuilder)
        $vetaP = $dom->createElement('VetaP');
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $supplier);
        $dphkh->appendChild($vetaP);

        // VetaA1 — Přenesená daňová povinnost (dodavatel).
        // XSD vyžaduje: dic_odb, c_evid_dd, duzp (NE "dppd"), zakl_dane1, kod_pred_pl.
        // kod_pred_pl '5' = obecný tuzemský reverse charge (defaultní hodnota, MFČR
        // číselník Kód předmětů plnění; ideálně by mělo přicházet z vat_classification_code).
        $rowNum = 0;
        foreach ($a1 as $r) {
            $cleanDic = self::cleanDic($r['counterparty_dic'] ?? '');
            $rowNum++;
            $v = $dom->createElement('VetaA1');
            $v->setAttribute('c_radku', (string) $rowNum);
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dic_odb', $cleanDic);
            $v->setAttribute('duzp', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base']));
            $v->setAttribute('kod_pred_pl', $this->resolveKodPredPl($r['kod_pred_pl'] ?? null, $warnings));
            $dphkh->appendChild($v);
        }

        // VetaA2 — přijatá plnění s místem plnění v tuzemsku, u kterých daň přiznává příjemce
        // (§ 108): pořízení zboží z JČS § 25, přijetí služby § 24 / § 9 odst. 1, zboží
        // s instalací. Per XSD: k_stat (kód člen. státu), vatid_dod (VAT ID bez prefixu
        // země), c_evid_dd, dppd (required), zakl_dane1/dan1 (21%), zakl_dane2/dan2 (12%).
        // Plnění je z definice samovyměřené (vendor fakturuje bez DPH, my si daň přiznáme
        // sami) — `dan1`/`dan2` = base × sazba/100, ne pii.total_vat (které je 0 pro RC).
        //
        // Dodavatel BEZ EU DIČ (3. země, neplátce z EU) sem PATŘÍ, jen s prázdnou
        // identifikací. Metodická informace GFŘ k vyplnění KH i dokumentace `vatid_dod`
        // v dphkh1.xsd shodně říkají, že u dodavatele bez VAT ID (včetně „identifikace
        // zahraniční osoby povinné k dani“) pole „Identifikace dodavatele“ ZŮSTÁVÁ PRÁZDNÉ;
        // obě položky jsou v XSD `use="optional"` s `minLength="0"`. EPO na takový řádek
        // vypíše chyby č. 58 (kód státu) a č. 60 (VAT ID), ty jsou ale PROPUSTNÉ a podání
        // nebrání. Vyřazování těchto řádků rozbíjelo křížovou kontrolu `celk_zd_a2` proti
        // ř. 5/6/12/13 přiznání (tolerance ±1000 Kč) — viz issue #53.
        //
        // Daňový dopad = 0: DPHDP3 se řídí `dphdp3_line`, ne `kh_section`, takže
        // samovyměření i zrcadlový odpočet jsou naplněné nezávisle na téhle sekci.
        // Identifikaci doplňuje {@see resolveA2Identification()} / {@see a2Identification()}.
        $rowNum = 0;
        $celkA2 = 0.0;
        foreach ($a2 as $r) {
            $rowNum++;
            $v = $dom->createElement('VetaA2');
            $v->setAttribute('c_radku', (string) $rowNum);
            // Prázdné řetězce jsou záměr, ne chybějící data — viz komentář výše.
            $v->setAttribute('k_stat', (string) ($r['k_stat'] ?? ''));
            $v->setAttribute('vatid_dod', (string) ($r['vatid_dod'] ?? ''));
            $celkA2 += (float) $r['base21'] + (float) $r['base12'];
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dppd', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1',       $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2',       $this->formatAmount($r['vat12']));
            $dphkh->appendChild($v);
        }

        // VetaA4 — tuzemská plnění nad 10 000 Kč (vystavené). Bez `dic_odb` řádek neexistuje
        // (stejná past jako A.2) — směrování do A.4 proto vyžaduje DIČ už v buildSections()
        // a tenhle guard je pojistka. Aby vyřazený řádek nemohl zůstat v rekapitulaci,
        // sčítá se obrat23/obrat5 z REÁLNĚ emitovaných vět, ne z kandidátů.
        $rowNum = 0;
        $obrat23 = 0.0; $obrat5 = 0.0;
        foreach ($a4 as $r) {
            $cleanDic = self::cleanDic($r['counterparty_dic'] ?? '');
            if ($cleanDic === '') continue;
            $obrat23 += (float) $r['base21']; $obrat5 += (float) $r['base12'];
            $rowNum++;
            $taxDate = $this->formatDate($r['tax_date']);
            $v = $dom->createElement('VetaA4');
            $v->setAttribute('c_radku', (string) $rowNum);
            $v->setAttribute('dic_odb', $cleanDic);
            $v->setAttribute('c_evid_dd', (string) $r['varsymbol']);
            $v->setAttribute('dppd', $taxDate);
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1', $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2', $this->formatAmount($r['vat12']));
            $v->setAttribute('kod_rezim_pl', (string) ($r['kh_regime_code'] ?? '0'));
            $v->setAttribute('zdph_44', (string) ($r['kh_bad_debt'] ?? 'N'));
            $dphkh->appendChild($v);
        }

        // VetaA5 — tuzemská plnění do 10 000 Kč (sumace, 1 řádek)
        if ($a5['count'] > 0) {
            $v = $dom->createElement('VetaA5');
            $v->setAttribute('zakl_dane1', $this->formatAmount($a5['base21']));
            $v->setAttribute('dan1', $this->formatAmount($a5['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($a5['base12']));
            $v->setAttribute('dan2', $this->formatAmount($a5['vat12']));
            $dphkh->appendChild($v);
        }

        // VetaB1 — Přenesená daňová povinnost (odběratel)
        $rowNum = 0;
        foreach ($b1 as $r) {
            $cleanDic = self::cleanDic($r['counterparty_dic'] ?? '');
            $rowNum++;
            $v = $dom->createElement('VetaB1');
            $v->setAttribute('c_radku', (string) $rowNum);
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dic_dod', $cleanDic);
            // XSD VetaB1 zná atribut 'duzp' (NE 'dppd' jako A.2/B.2) — odběratel v režimu
            // přenesení přiznává daň ke DUZP. Zároveň B.1 vykazuje SAMOVYMĚŘENOU daň
            // (dan1/dan2), ne jen základ — příjemce si daň sám přiznává (a odečítá).
            $v->setAttribute('duzp', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1', $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2', $this->formatAmount($r['vat12']));
            $v->setAttribute('kod_pred_pl', $this->resolveKodPredPl($r['kod_pred_pl'] ?? null, $warnings));
            $dphkh->appendChild($v);
        }

        // VetaB2 — přijatá tuzemská nad 10 000 Kč.
        // XSD vyžaduje: pomer (A/N — poměrný odpočet podle §75) a zdph_44
        // (N = běžné, P = oprava nedobytné pohledávky podle §74b, A = §44 do 31.3.2019).
        // Default: oba 'N' (běžný odpočet, žádná oprava). Bez `dic_dod` řádek neexistuje —
        // pln23/pln5 se proto (jako u A.4) sčítá z reálně emitovaných vět.
        $rowNum = 0;
        $pln23 = 0.0; $pln5 = 0.0;
        foreach ($b2 as $r) {
            $cleanDic = self::cleanDic($r['counterparty_dic'] ?? '');
            if ($cleanDic === '') continue;
            $pln23 += (float) $r['base21']; $pln5 += (float) $r['base12'];
            $rowNum++;
            $v = $dom->createElement('VetaB2');
            $v->setAttribute('c_radku', (string) $rowNum);
            $v->setAttribute('dic_dod', $cleanDic);
            $v->setAttribute('c_evid_dd', (string) $r['vendor_invoice_number']);
            $v->setAttribute('dppd', $this->formatDate($r['tax_date']));
            $v->setAttribute('zakl_dane1', $this->formatAmount($r['base21']));
            $v->setAttribute('dan1', $this->formatAmount($r['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($r['base12']));
            $v->setAttribute('dan2', $this->formatAmount($r['vat12']));
            // Obranná pojistka (AI extrakce, task #7): přijatý dobropis vedený pod ČÍSLEM
            // OPRAVOVANÉ faktury místo pod vlastním evidenčním číslem opravného dokladu.
            // Poznáme to podle shody vendor_invoice_number s číslem navázaného rodiče. Řádek
            // z KH NEODstraňujeme ani XML neměníme — jen upozorníme na možný špatný c_evid_dd.
            if (($r['document_kind'] ?? null) === 'credit_note'
                && ($r['parent_vendor_invoice_number'] ?? null) !== null
                && (string) $r['vendor_invoice_number'] === (string) $r['parent_vendor_invoice_number']) {
                $warnings[] = 'Dobropis ' . (string) $r['vendor_invoice_number'] . ' je v KH veden pod číslem '
                    . 'opravované faktury — ověřte evidenční číslo dokladu (Opravný daňový doklad).';
            }
            // pomer = A když byl uplatněn poměrný odpočet §75 (částky jsou už zkrácené ve VatLedgerService).
            $v->setAttribute('pomer', !empty($r['is_pomer']) ? 'A' : 'N');
            $v->setAttribute('zdph_44', (string) ($r['kh_bad_debt'] ?? 'N'));
            $dphkh->appendChild($v);
        }

        // VetaB3 — přijatá tuzemská do 10 000 Kč (sumace)
        if ($b3['count'] > 0) {
            $v = $dom->createElement('VetaB3');
            $v->setAttribute('zakl_dane1', $this->formatAmount($b3['base21']));
            $v->setAttribute('dan1', $this->formatAmount($b3['vat21']));
            $v->setAttribute('zakl_dane2', $this->formatAmount($b3['base12']));
            $v->setAttribute('dan2', $this->formatAmount($b3['vat12']));
            $dphkh->appendChild($v);
        }

        // VetaC — rekapitulace plnění za období (obrat = uskutečněná, pln = přijatá).
        // Sumace všech sekcí: A4+A5 (sales), B2+B3 (purchases), A1 (RC sales),
        // B1 (RC purchases), A2 (samovyměření od plátce z JČS → celk_zd_a2).
        //
        // Pravidlo: rekapitulace smí obsahovat JEN to, co reálně odešlo jako věta. Sekce
        // s povinným identifikátorem (A.1/B.1 dic, A.4/B.2 dic_odb/dic_dod, A.2 k_stat +
        // vatid_dod) proto sčítají v emisních blocích výše; sumační A.5/B.3 nemají co
        // vyřadit a přičítají se tady.
        $obrat23 += (float) ($a5['base21'] ?? 0); $obrat5 += (float) ($a5['base12'] ?? 0);
        $pln23 += (float) ($b3['base21'] ?? 0); $pln5 += (float) ($b3['base12'] ?? 0);
        $plnRezPren = 0.0; foreach ($a1 as $r) { $plnRezPren += (float) $r['base']; }
        $rezPren23 = 0.0; $rezPren5 = 0.0;
        foreach ($b1 as $r) {
            $rezPren23 += (float) $r['base21'];
            $rezPren5 += (float) $r['base12'];
        }
        $vetaC = $dom->createElement('VetaC');
        $vetaC->setAttribute('obrat23',      $this->formatAmount($obrat23));
        $vetaC->setAttribute('obrat5',       $this->formatAmount($obrat5));
        $vetaC->setAttribute('pln23',        $this->formatAmount($pln23));
        $vetaC->setAttribute('pln5',         $this->formatAmount($pln5));
        $vetaC->setAttribute('pln_rez_pren', $this->formatAmount($plnRezPren));
        $vetaC->setAttribute('rez_pren23',   $this->formatAmount($rezPren23));
        $vetaC->setAttribute('rez_pren5',    $this->formatAmount($rezPren5));
        // celk_zd_a2 = celkový základ sekce A.2. Sčítá se z REÁLNĚ EMITOVANÝCH vět
        // VetaA2 (viz emisní blok výše), ne z kandidátů — plnění, které se do A.2
        // nevejde (dodavatel bez EU registrace k DPH), nesmí zůstat v rekapitulaci.
        // Za období, kde je jen samovyměření ze 3. země, tedy vyjde 0.
        $vetaC->setAttribute('celk_zd_a2',   $this->formatAmount($celkA2));
        $dphkh->appendChild($vetaC);

        // Termín podání = 25. dne měsíce následujícího po konci období.
        // U kvartálního podání je rozhodující konec kvartálu ($endMonth), NE předaný
        // $month (jinak build(..., 4, 'quarterly') = Q2 vrátí termín 25.05. místo 25.07.).
        $deadlineMonth = $endMonth + 1;
        $deadlineYear = $year;
        if ($deadlineMonth > 12) { $deadlineMonth -= 12; $deadlineYear++; }
        // § 33/4 DŘ: termín padající na víkend/svátek se posouvá na další pracovní den.
        $deadline = CzechWorkingDays::deadline($deadlineYear, $deadlineMonth);
        $regularDeadline = $deadline;

        // Následné KH má vlastní lhůtu: 5 pracovních dnů ode dne zjištění (§ 101f odst. 2).
        // Termín řádného hlášení je u něj bezpředmětný — UI ho ukazovalo jako „po termínu,
        // −N dní", což je u následného vždycky a nic to neříká.
        if ($isFollowUp && $dZjist !== null) {
            $deadline = self::addWorkingDays($dZjist, 5);
        } elseif ($isFollowUp && $cJedVyzvy !== null) {
            $warnings[] = 'Následné kontrolní hlášení na výzvu se podává do 17 dnů od dodání výzvy '
                . 'do datové schránky (nebo do 5 pracovních dnů při jiném způsobu oznámení) — '
                . 'termín podle data dodání výzvy si ohlídejte sami, aplikace ho nezná.';
        }

        // § 101f: opravné (O) jen PŘED lhůtou pro řádné, následné (N/E) až PO ní. Volba
        // proti lhůtě je tichá chyba — EPO takové podání může přijmout, ale správce daně
        // k opravnému po lhůtě nepřihlíží a původní chybné KH zůstane v platnosti.
        // Varování, ne blok: lhůtu lze individuálně prodloužit a tvrdá brzda těsně před
        // termínem by byla horší než falešný poplach.
        $today = date('Y-m-d');
        if ($forma === 'O' && $today > $regularDeadline) {
            $warnings[] = sprintf(
                'Lhůta pro řádné kontrolní hlášení (%s) už uplynula — opravné hlášení (§ 101f '
                    . 'odst. 1) lze podat jen před ní. Po lhůtě se podává NÁSLEDNÉ kontrolní '
                    . 'hlášení; zkontrolujte typ podání.',
                $regularDeadline,
            );
        }
        if ($isFollowUp && $today <= $regularDeadline) {
            $warnings[] = sprintf(
                'Lhůta pro řádné kontrolní hlášení (%s) ještě běží — do jejího uplynutí se '
                    . 'chyba opravuje OPRAVNÝM hlášením (§ 101f odst. 1), ne následným. '
                    . 'Zkontrolujte typ podání.',
                $regularDeadline,
            );
        }

        return [
            'xml'      => $dom->saveXML() ?: '',
            'summary'  => [
                'period'              => $period === 'quarterly' && $quarter !== null
                    ? sprintf('%04d-Q%d', $year, $quarter)
                    : sprintf('%04d-%02d', $year, $month),
                'a1_count'            => count($a1),
                'a2_count'            => count($a2),
                'a4_count'            => count($a4),
                'a5_count_aggregated' => $a5['count'],
                'b1_count'            => count($b1),
                'b2_count'            => count($b2),
                'b3_count_aggregated' => $b3['count'],
                'submission_deadline' => $deadline,
                // C7' — typ podání (řádné/opravné/následné).
                'variant'             => $variant,
                'khdph_forma'         => $forma,
                'is_follow_up'        => $isFollowUp,
                'd_zjist'             => $dZjist,
                'c_jed_vyzvy'         => $cJedVyzvy,
            ],
            'warnings' => $warnings,
            'missing_rates' => $missingRates,
        ];
    }

    /**
     * Přidá evidované korekce §74b (dlužník) do sekce B.2 jako řádky opravy nedobytné
     * pohledávky (zdph_44='P') — bez ohledu na částku (i pod 10 000 Kč nejde do B.3).
     * Základ i daň nesou znaménko dle pohybu (snížení záporně, obnova kladně), shodně
     * s DPHDP3 ř. 40/41. Doklad bez platného DIČ dodavatele nelze v KH uvést → warning.
     *
     * @param list<array<string,mixed>> $b2 by-ref
     * @param list<string> $warnings by-ref
     */
    private function appendSection74bCorrections(array &$b2, int $supplierId, int $year, int $month, string $period, array &$warnings): void
    {
        $s74b = $this->section74b->periodCorrectionLines($supplierId, $year, $month, $period);
        foreach ($s74b['invoices'] as $row) {
            if (self::cleanDic($row['vendor_dic'] ?? '') === '') {
                $warnings[] = 'Oprava §74b u dokladu ' . (($row['vendor_invoice_number'] ?? '') ?: 'bez čísla')
                    . ' nelze uvést v KH B.2: chybí platné DIČ dodavatele.';
                continue;
            }
            $b2[] = [
                'vendor_invoice_number'        => $row['vendor_invoice_number'],
                'tax_date'                     => $row['tax_date'],
                'counterparty_dic'             => $row['vendor_dic'],
                'base21' => $row['base21'], 'vat21' => $row['vat21'],
                'base12' => $row['base12'], 'vat12' => $row['vat12'],
                'is_pomer'                     => false,
                'document_kind'                => null,
                'parent_vendor_invoice_number' => null,
                'kh_bad_debt'                  => 'P',
                'kh_attribute_conflict'        => false,
            ];
        }
    }

    /**
     * Přidá evidované věřitelské opravy § 46 do sekce A.4 jako řádky opravy nedobytné
     * pohledávky (zdph_44='P') — bez ohledu na částku (i pod 10 000 Kč nejde do A.5).
     * Základ i daň nesou znaménko dle pohybu (oprava záporně, obnova kladně), shodně
     * s DPHDP3 ř. 1/2. Doklad bez platného DIČ odběratele nelze v KH uvést → warning.
     *
     * @param list<array<string,mixed>> $a4 by-ref
     * @param list<string> $warnings by-ref
     */
    private function appendSection46Corrections(array &$a4, int $supplierId, int $year, int $month, string $period, array &$warnings): void
    {
        $s46 = $this->section46->periodCorrectionLines($supplierId, $year, $month, $period);
        foreach ($s46['invoices'] as $row) {
            if (self::cleanDic($row['client_dic'] ?? '') === '') {
                $warnings[] = 'Oprava §46 u dokladu ' . (($row['varsymbol'] ?? '') ?: 'bez čísla')
                    . ' nelze uvést v KH A.4: chybí platné DIČ odběratele.';
                continue;
            }
            $a4[] = [
                'varsymbol'             => $row['varsymbol'],
                'tax_date'              => $row['tax_date'],
                'counterparty_dic'      => $row['client_dic'],
                'base21' => $row['base21'], 'vat21' => $row['vat21'],
                'base12' => $row['base12'], 'vat12' => $row['vat12'],
                'kh_regime_code'        => '0',
                'kh_bad_debt'           => 'P',
                'kh_attribute_conflict' => false,
            ];
        }
    }

    /** Normalizace vstupního data (Y-m-d) — null pokud prázdné/neplatné. */
    private function normalizeDate(?string $date): ?string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($date))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Datum, které MUSÍ být platné. `normalizeDate()` vrací u nesmyslu null, takže zadané
     * datum tiše zmizelo z XML a uživatel se to nedozvěděl — u data, kterým běží lhůta,
     * je to nepřijatelné. Prázdný vstup → null (povinnost řeší volající).
     */
    private function requireValidDate(?string $date): ?string
    {
        if (trim((string) $date) === '') {
            return null;
        }
        $normalized = $this->normalizeDate($date);
        if ($normalized === null) {
            throw new PostingException('kh_d_zjist_invalid', 'Neplatné datum zjištění důvodů.', 422);
        }
        return $normalized;
    }

    /**
     * Č.j. výzvy správce daně (XSD c_jed_vyzvy, maxLength 32). Prázdné → null.
     *
     * Tvar 99999999/99/9999-99999-999999 hlídáme sami: XSD kontroluje jen délku, a dřívější
     * `mb_substr(..., 0, 32)` překlep tiše zkomolil a poslal do XML — správce daně pak
     * odpověď nespáruje s výzvou. Oddělovače uživatel často vynechá nebo nahradí mezerami,
     * proto se nejdřív zkusí složit kanonický tvar z holých číslic.
     */
    private function normalizeVyzva(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $canonical = '/^\d{8}\/\d{2}\/\d{4}-\d{5}-\d{6}$/';
        if (preg_match($canonical, $value) === 1) {
            return $value;
        }
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 25) {
            $rebuilt = sprintf(
                '%s/%s/%s-%s-%s',
                substr($digits, 0, 8),
                substr($digits, 8, 2),
                substr($digits, 10, 4),
                substr($digits, 14, 5),
                substr($digits, 19, 6),
            );
            if (preg_match($canonical, $rebuilt) === 1) {
                return $rebuilt;
            }
        }
        throw new PostingException(
            'kh_vyzva_ref_invalid',
            'Č.j. výzvy musí být ve tvaru 99999999/99/9999-99999-999999.',
            422,
        );
    }

    /**
     * Datum + N pracovních dnů (§ 101f odst. 2 — lhůta následného KH). Víkendy a svátky
     * se nepočítají; výsledek je vždy pracovní den.
     */
    private static function addWorkingDays(string $from, int $days): string
    {
        $d = new \DateTimeImmutable($from);
        $added = 0;
        while ($added < $days) {
            $d = $d->modify('+1 day');
            if (CzechWorkingDays::isWorkingDay($d)) {
                $added++;
            }
        }
        return $d->format('Y-m-d');
    }

    /**
     * RYCHLÁ ODPOVĚĎ NA VÝZVU (§ 101g) — KH bez jediného řádku oddílů A/B/C.
     *
     * XSD to vyžaduje doslova: „V případě použití této volby nesmějí být vyplněny řádky
     * v oddílech A, B i C… vyplnění údajů o Plátci a Záhlaví je pro další zpracování
     * nezbytné." Proto se tahle větev vůbec nedotýká sběru sekcí — ne že by je odfiltrovala,
     * ona je nesbírá.
     *
     * @return array{xml: string, summary: array<string,mixed>, warnings: list<string>, missing_rates: list<array<string,mixed>>}
     */
    private function buildQuickReply(
        int $supplierId,
        int $year,
        int $month,
        string $period,
        string $variant,
        string $forma,
        string $vyzvaOdp,
        string $cJedVyzvy,
        ?int $quarter,
        int $endMonth,
        string $end,
    ): array {
        $supplier = $this->loadSupplier($supplierId, $end);
        $warnings = $this->validateSupplier($supplier, $period, true);
        $warnings[] = $vyzvaOdp === 'B'
            ? 'Rychlá odpověď na výzvu: „Nemám povinnost podat kontrolní hlášení za období." '
                . 'Hlášení je bez oddílů A/B/C — ověřte, že za období opravdu nevznikla povinnost.'
            : 'Rychlá odpověď na výzvu: „Potvrzuji správnost naposledy podaného kontrolního '
                . 'hlášení." Podáním se nemění žádná data.';
        $warnings[] = 'Odpověď na výzvu se podává do 5 pracovních dnů od oznámení výzvy '
            . '(resp. do 17 dnů od dodání do datové schránky) — termín si ohlídejte, aplikace '
            . 'datum dodání výzvy nezná.';

        [$dom, $dphkh] = EpoEnvelope::create('DPHKH1', '03.01');

        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('dokument', 'KH1');
        $vetaD->setAttribute('k_uladis', 'DPH');
        if ($period === 'quarterly' && $quarter !== null) {
            $vetaD->setAttribute('ctvrt', (string) $quarter);
        } else {
            $vetaD->setAttribute('mesic', (string) $month);
        }
        $vetaD->setAttribute('rok', (string) $year);
        $vetaD->setAttribute('d_poddp', date('d.m.Y'));
        $vetaD->setAttribute('khdph_forma', $forma);
        $vetaD->setAttribute('c_jed_vyzvy', $cJedVyzvy);
        $vetaD->setAttribute('vyzva_odp', $vyzvaOdp);
        $dphkh->appendChild($vetaD);

        $vetaP = $dom->createElement('VetaP');
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $supplier);
        $dphkh->appendChild($vetaP);

        $deadlineMonth = $endMonth + 1;
        $deadlineYear = $year;
        if ($deadlineMonth > 12) { $deadlineMonth -= 12; $deadlineYear++; }

        return [
            'xml'      => $dom->saveXML() ?: '',
            'summary'  => [
                'period'              => $period === 'quarterly' && $quarter !== null
                    ? sprintf('%04d-Q%d', $year, $quarter)
                    : sprintf('%04d-%02d', $year, $month),
                'a1_count'            => 0,
                'a2_count'            => 0,
                'a4_count'            => 0,
                'a5_count_aggregated' => 0,
                'b1_count'            => 0,
                'b2_count'            => 0,
                'b3_count_aggregated' => 0,
                'submission_deadline' => CzechWorkingDays::deadline($deadlineYear, $deadlineMonth),
                'variant'             => $variant,
                'khdph_forma'         => $forma,
                'is_follow_up'        => $forma === 'N' || $forma === 'E',
                'is_vyzva_odpoved'    => true,
                'vyzva_odp'           => $vyzvaOdp,
                'd_zjist'             => null,
                'c_jed_vyzvy'         => $cJedVyzvy,
            ],
            'warnings' => $warnings,
            'missing_rates' => [],
        ];
    }

    /**
     * Hranice období výkazu (start, end, kvartál) — sdílené build() i invoiceSections().
     * Kvartál končí posledním dnem měsíce quarter*3 NEZÁVISLE na předaném $month (jinak
     * build(..., 4, 'quarterly') utne období na duben a zahodí květen+červen). Stejná
     * logika jako DphBookBuilder::build().
     *
     * @return array{0:string, 1:string, 2:?int, 3:int} [start, end, quarter|null, endMonth]
     */
    private static function periodBounds(int $year, int $month, string $period): array
    {
        if ($period === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $startMonth = ($quarter - 1) * 3 + 1;
            $endMonth = $quarter * 3;
            $start = sprintf('%04d-%02d-01', $year, $startMonth);
        } else {
            $quarter = null;
            $endMonth = $month;
            $start = sprintf('%04d-%02d-01', $year, $month);
        }
        $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endMonth)))
            ->modify('last day of this month')->format('Y-m-d');
        return [$start, $end, $quarter, $endMonth];
    }

    /**
     * Per-doklad zařazení do sekcí KH (audit 2026-07 C8' — křížová kontrola DPHDP3↔KH).
     * Vrací TÉTOŽ směrování jako build() (sdílené {@see buildSections()}), jen keyed per
     * faktura + s efektivní sekcí, ať se reconciliation nerozejde s reálně podaným
     * hlášením. Read-only, bez XML. section=null = doklad se do KH nevykazuje (osvobozené,
     * dovoz ze 3. země, plnění bez nároku).
     *
     * @return list<array{invoice_id:int, source:string, doc_number:?string, section:?string,
     *                     base_total:float, base21:float, base12:float}>
     */
    public function invoiceSections(int $supplierId, int $year, int $month, string $period = 'monthly'): array
    {
        [$start, $end] = self::periodBounds($year, $month, $period);
        return $this->buildSections($supplierId, $start, $end)['invoices'];
    }

    /**
     * Projekce kanonických řádků (VatLedgerService) na sekce KH. Nahrazuje 5 původních
     * SQL kolektorů + loadInvoiceVatBreakdown. Per faktura agregujeme základ/daň po
     * sazbách + příznaky sekce, pak směrujeme:
     *   - A.1 = vystavený reverse charge
     *   - A.2 = pořízení zboží z JČS (kh_section A.2; samovyměřená daň ze služby)
     *   - A.4/A.5 = vystavená tuzemská zdanitelná (nad/do limitu + DIČ)
     *   - B.1 = přijatý tuzemský RC (ne A.2)
     *   - B.2/B.3 = přijatá tuzemská zdanitelná
     * Práh `abs()`, plnění bez DIČ → sumace, bez zdanitelného základu → vyloučeno.
     *
     * @return array{a1:list<array<string,mixed>>, a2:list<array<string,mixed>>,
     *   a4:list<array<string,mixed>>, a5:array<string,mixed>, b1:list<array<string,mixed>>,
     *   b2:list<array<string,mixed>>, b3:array<string,mixed>, missing_rates:list<array<string,mixed>>}
     */
    private function collectSections(int $supplierId, string $start, string $end): array
    {
        $r = $this->buildSections($supplierId, $start, $end);
        return $r['sections'] + ['missing_rates' => $r['missing_rates']];
    }

    /**
     * Jádro směrování dokladů do sekcí KH — postaví jak agregované sekce (pro build()),
     * tak per-doklad tagy s efektivní sekcí (pro invoiceSections() / křížovou kontrolu C8')
     * z JEDNÉ projekce kanonických řádků, aby se KH výkaz a jeho reconciliation nikdy
     * nerozešly.
     *
     * @return array{sections: array<string,mixed>, invoices: list<array<string,mixed>>}
     */
    private function buildSections(int $supplierId, string $start, string $end): array
    {
        // Konstanty pro rok OBDOBÍ výkazu (ne aktuální) — zpětně generované KH za
        // staré období musí použít tehdejší limit/sazby.
        $periodYear = (int) substr($start, 0, 4);
        $itemThreshold = $this->taxConstants->khItemThreshold($periodYear);
        $bucket = $this->taxConstants->vatBucketThreshold($periodYear);

        // Agregace kanonických řádků per (zdroj, faktura).
        $ledgerRows = $this->ledger->rows($supplierId, $start, $end, includeDrafts: false);
        // Daňová pojistka (issue #238): non-CZK doklad bez kurzu by se do KH dostal
        // s náhradním kurzem 1.0 (cizí měna jako CZK). NEházíme chybu — vrátíme doklady
        // bez kurzu, akce je při stažení doplní z ČNB (náhled jen varuje).
        $missingRates = VatLedgerService::missingExchangeRateRows($ledgerRows);
        $inv = [];
        foreach ($ledgerRows as $r) {
            $key = $r['source'] . ':' . ($r['document_kind'] ?? '') . ':' . $r['invoice_id'];
            if (!isset($inv[$key])) {
                $inv[$key] = [
                    'source'                => $r['source'],
                    'invoice_id'            => (int) $r['invoice_id'],
                    'varsymbol'             => $r['doc_number'],
                    'vendor_invoice_number' => $r['vendor_invoice_number'],
                    'document_kind'         => $r['document_kind'] ?? null,
                    // Číslo opravované faktury (rodiče dobropisu) pro obrannou pojistku KH.
                    'parent_vendor_invoice_number' => $r['parent_vendor_invoice_number'] ?? null,
                    'tax_date'              => $r['tax_date'],
                    'dic'                   => self::cleanDic($r['counterparty_dic']),
                    'dic_raw'               => $r['counterparty_dic'], // syrové VAT ID pro A.2 (EU alfanum.)
                    'country_iso2'          => $r['country_iso2'],
                    'country_is_eu'         => $r['country_is_eu'],
                    'total_czk'             => (float) $r['total_with_vat_czk'],
                    // KH kód předmětu plnění (RC) z klasifikace. Doklad může nést VÍC režimů
                    // § 92 najednou (stavební práce + odpad), a XSD chce větu A.1/B.1 per kód —
                    // proto se základ i daň sčítají PER KÓD, ne do jednoho čísla za doklad.
                    // Dřív tu byl skalár, který přepsala poslední neprázdná hodnota, takže
                    // celý doklad odešel pod jedním (často cizím) kódem.
                    'a1_by_code'            => [],
                    'b1_by_code'            => [],
                    'is_rc' => false, 'has_a1' => false, 'has_a2' => false, 'has_b1' => false, 'is_pomer' => false,
                    'dom_base21' => 0.0, 'dom_vat21' => 0.0, 'dom_base12' => 0.0, 'dom_vat12' => 0.0,
                    'a2_base21' => 0.0, 'a2_vat21' => 0.0, 'a2_base12' => 0.0, 'a2_vat12' => 0.0,
                    'kh_regime_codes' => [], 'kh_bad_debt_codes' => [],
                ];
            }
            $g = &$inv[$key];
            if ($r['is_reverse_charge']) $g['is_rc'] = true;
            if ($r['kh_section'] === 'A.1') $g['has_a1'] = true;
            if ($r['kh_section'] === 'A.2') $g['has_a2'] = true;
            if ($r['kh_section'] === 'B.1') $g['has_b1'] = true;
            if (!empty($r['vat_deduction_partial'])) $g['is_pomer'] = true;
            $rowKodPredPl = (string) ($r['kod_pred_pl'] ?? '');
            $base = (float) $r['base_czk'];
            $vat  = (float) $r['vat_czk'];
            $is21 = $r['vat_rate'] >= $bucket;
            // Rozřazení základu/daně do KH kbelíků PODLE SEKCE klasifikace — každá položka
            // přispěje jen do JEDNÉ sekce. Tím se mixed faktura (např. §92 RC řádek +
            // běžný 21% řádek) rozdělí správně (RC část do A.1/B.1, zdanitelná do A.4/B.2),
            // místo aby celý součet spadl do jedné sekce (issue — audit KH/DPH 2026-07).
            // khEligible: vystavené vždy, přijaté jen s nárokem na odpočet (dphdp3_line != NULL);
            // přijaté bez nároku (kód 42, dphdp3_line=NULL) do KH nepatří, DPHDP3 je taky vynechává.
            $khEligible = $r['source'] === 'sale' || $r['dphdp3_line'] !== null;
            switch ($r['kh_section']) {
                case 'A.1': // tuzemský §92 dodavatel — jen základ (VetaA1 nemá sazbové sloupce)
                    $g['a1_by_code'][$rowKodPredPl] = ($g['a1_by_code'][$rowKodPredPl] ?? 0.0) + $base;
                    break;
                case 'A.2': // přeshraniční samovyměřené (§ 24 služby, § 25 pořízení zboží z JČS)
                    if ($is21) { $g['a2_base21'] += $base; $g['a2_vat21'] += $vat; }
                    elseif ($r['vat_rate'] > 0) { $g['a2_base12'] += $base; $g['a2_vat12'] += $vat; }
                    break;
                case 'B.1': // tuzemský §92 příjemce — samovyměřená daň (vat z rcSelfAssess)
                    $b1 = $g['b1_by_code'][$rowKodPredPl]
                        ?? ['base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0];
                    if ($is21) { $b1['base21'] += $base; $b1['vat21'] += $vat; }
                    elseif ($r['vat_rate'] > 0) { $b1['base12'] += $base; $b1['vat12'] += $vat; }
                    $g['b1_by_code'][$rowKodPredPl] = $b1;
                    break;
                default:
                    // Tuzemská zdanitelná plnění (A.4/A.5, B.2/B.3). RC bez KH sekce — dovoz
                    // zboží ze 3. země (kód 25), dodání/služba do EU (kód 20/22) — se sem
                    // NESMÍ dostat (do KH nepatří, jen DPHDP3/SHV) → guard !is_reverse_charge.
                    if ($khEligible && !$r['is_reverse_charge']) {
                        $g['kh_regime_codes'][(string) ($r['kh_regime_code'] ?? '0')] = true;
                        $g['kh_bad_debt_codes'][(string) ($r['kh_bad_debt'] ?? 'N')] = true;
                        if ($is21) { $g['dom_base21'] += $base; $g['dom_vat21'] += $vat; }
                        elseif ($r['vat_rate'] > 0) { $g['dom_base12'] += $base; $g['dom_vat12'] += $vat; }
                    }
            }
            unset($g);
        }

        $a1 = []; $a2 = []; $a4 = []; $b1 = []; $b2 = [];
        $a5 = ['count' => 0, 'base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0];
        $b3 = ['count' => 0, 'base21' => 0.0, 'vat21' => 0.0, 'base12' => 0.0, 'vat12' => 0.0];
        $invoices = [];

        // Per-doklad tag pro křížovou kontrolu C8' (VatCrossCheckService::invoiceSections).
        // Mixed doklad přispívá do VÍCE sekcí (RC část do A.1/B.1 + tuzemská do A.4/A.5/B.2/B.3),
        // proto emitujeme řádek za KAŽDOU sekci se základem, který do ní reálně spadl. Kontrola 1
        // (A.4/A.5) čte base21+base12, kontrola 2 (B.1) čte base_total (= B.1 základ) → base_total
        // = base21+base12 dané sekce. Dřív se tagovala jen jedna sekce z celo-dokladového základu.
        $tag = function (array $g, string $section, float $base21, float $base12) use (&$invoices): void {
            $invoices[] = [
                'invoice_id' => $g['invoice_id'],
                'source'     => $g['source'],
                'doc_number' => $g['source'] === 'sale' ? $g['varsymbol'] : $g['vendor_invoice_number'],
                'section'    => $section,
                'base_total' => $base21 + $base12,
                'base21'     => $base21,
                'base12'     => $base12,
            ];
        };

        foreach ($inv as $g) {
            $hasDic = $g['dic'] !== '';
            // § 101e: „nad 10 000 Kč" = OSTŘE více → přesně 10 000 patří do sumace
            // A.5/B.3, ne do jednotlivé A.4/B.2. Proto '>' (ne '>=').
            $overLimit = abs($g['total_czk']) > $itemThreshold;
            // Tuzemská zdanitelná část faktury (může být 0 u čistě RC/osvobozeného dokladu).
            // Faktura může přispět SOUČASNĚ do RC sekce (A.1/B.1/A.2) i do A.4/A.5/B.2/B.3
            // (mixed doklad) — proto žádný `continue`, sekce se vyhodnocují nezávisle.
            $domZero = abs($g['dom_base21']) < 0.005 && abs($g['dom_base12']) < 0.005;

            if ($g['source'] === 'sale') {
                // A.1 — tuzemský režim přenesení (§ 92a–92e, kód 25s). Jen položky sekce A.1.
                if ($g['has_a1']) {
                    // Věta per kód předmětu plnění (XSD), rekapitulace VetaC jednou za doklad.
                    $a1Total = 0.0;
                    foreach ($g['a1_by_code'] as $code => $codeBase) {
                        // PHP číselný klíč pole automaticky přetypuje na int — zpět na string.
                        $code = (string) $code;
                        if (abs($codeBase) < 0.005) {
                            continue;
                        }
                        $a1[] = ['counterparty_dic' => $g['dic_raw'], 'vendor_invoice_number' => $g['varsymbol'],
                                 'tax_date' => $g['tax_date'], 'base' => $codeBase,
                                 'kod_pred_pl' => $code !== '' ? $code : null];
                        $a1Total += $codeBase;
                    }
                    if (abs($a1Total) >= 0.005) {
                        $tag($g, 'A.1', $a1Total, 0.0);
                    }
                }
                // A.4/A.5 — tuzemská zdanitelná část (RC/osvobozené/EU dodání/vývoz nepřispěly).
                if (!$domZero) {
                    $regimeCodes = array_keys($g['kh_regime_codes']);
                    $badDebtCodes = array_keys($g['kh_bad_debt_codes']);
                    $row = ['varsymbol' => $g['varsymbol'], 'tax_date' => $g['tax_date'], 'counterparty_dic' => $g['dic'],
                            'base21' => $g['dom_base21'], 'vat21' => $g['dom_vat21'],
                            'base12' => $g['dom_base12'], 'vat12' => $g['dom_vat12'],
                            'kh_regime_code' => count($regimeCodes) === 1 ? $regimeCodes[0] : null,
                            'kh_bad_debt' => count($badDebtCodes) === 1 ? $badDebtCodes[0] : null,
                            'kh_attribute_conflict' => count($regimeCodes) > 1 || count($badDebtCodes) > 1];
                    if (($overLimit || $row['kh_bad_debt'] === 'P') && $hasDic) {
                        $a4[] = $row;
                        $tag($g, 'A.4', $g['dom_base21'], $g['dom_base12']);
                    } else {
                        $a5['count']++; $a5['base21'] += $g['dom_base21']; $a5['vat21'] += $g['dom_vat21'];
                        $a5['base12'] += $g['dom_base12']; $a5['vat12'] += $g['dom_vat12'];
                        $tag($g, 'A.5', $g['dom_base21'], $g['dom_base12']);
                    }
                }
            } else { // purchase
                // A.2 — přijatá plnění od OSOBY NEUSAZENÉ V TUZEMSKU, kde daň přiznává
                // příjemce (§ 108): pořízení zboží z JČS (§ 25), přijetí služby (§ 24 /
                // § 9 odst. 1), zboží s instalací. Rozhoduje režim plnění, ne sídlo
                // dodavatele — samovyměření od dodavatele ze 3. země sem patří stejně
                // jako od dodavatele z EU, jen bez identifikace ({@see a2Identification()},
                // issue #53). Historie: migrace 0129 to z A.2 vyřadila, 0130 vrátila zpět;
                // pravidlo proto drží KÓD, ne řádek v číselníku — seed `vat_classifications`
                // u kódu 24 drží `kh_section = 'A.2'` a novou přepínací migraci nepřidávat.
                if ($g['has_a2']) {
                    $a2[] = ['vendor_invoice_number' => $g['vendor_invoice_number'], 'tax_date' => $g['tax_date'],
                             'counterparty_dic' => $g['dic_raw'], 'country_iso2' => $g['country_iso2'],
                             'country_is_eu' => $g['country_is_eu'],
                             'base21' => $g['a2_base21'], 'vat21' => $g['a2_vat21'],
                             'base12' => $g['a2_base12'], 'vat12' => $g['a2_vat12']];
                }
                // B.1 — tuzemský režim přenesení (§ 92a–92e) příjemce. Per-sazbové agregáty
                // nesou i samovyměřenou daň (vat z rcSelfAssess) — B.1 ji vykazuje, ne jen základ.
                if ($g['has_b1']) {
                    // Věta per kód předmětu plnění (XSD), rekapitulace VetaC jednou za doklad.
                    $b1Base21 = 0.0;
                    $b1Base12 = 0.0;
                    foreach ($g['b1_by_code'] as $code => $sums) {
                        // PHP číselný klíč pole automaticky přetypuje na int — zpět na string.
                        $code = (string) $code;
                        $b1[] = ['counterparty_dic' => $g['dic_raw'], 'vendor_invoice_number' => $g['vendor_invoice_number'],
                                 'tax_date' => $g['tax_date'], 'base' => $sums['base21'] + $sums['base12'],
                                 'base21' => $sums['base21'], 'vat21' => $sums['vat21'],
                                 'base12' => $sums['base12'], 'vat12' => $sums['vat12'],
                                 'kod_pred_pl' => $code !== '' ? $code : null];
                        $b1Base21 += $sums['base21'];
                        $b1Base12 += $sums['base12'];
                    }
                    $tag($g, 'B.1', $b1Base21, $b1Base12);
                }
                // B.2/B.3 — tuzemská přijatá zdanitelná (s nárokem). RC bez KH sekce (dovoz
                // ze 3. země kód 25) a plnění bez nároku (kód 42) do dom_* nepřispěly.
                if (!$domZero) {
                    $badDebtCodes = array_keys($g['kh_bad_debt_codes']);
                    $row = ['vendor_invoice_number' => $g['vendor_invoice_number'], 'tax_date' => $g['tax_date'],
                            'counterparty_dic' => $g['dic'], 'base21' => $g['dom_base21'], 'vat21' => $g['dom_vat21'],
                            'base12' => $g['dom_base12'], 'vat12' => $g['dom_vat12'], 'is_pomer' => $g['is_pomer'],
                            'document_kind' => $g['document_kind'],
                            'parent_vendor_invoice_number' => $g['parent_vendor_invoice_number'],
                            'kh_bad_debt' => count($badDebtCodes) === 1 ? $badDebtCodes[0] : null,
                            'kh_attribute_conflict' => count($badDebtCodes) > 1];
                    if (($overLimit || $row['kh_bad_debt'] === 'P') && $hasDic) {
                        $b2[] = $row;
                        $tag($g, 'B.2', $g['dom_base21'], $g['dom_base12']);
                    } else {
                        $b3['count']++; $b3['base21'] += $g['dom_base21']; $b3['vat21'] += $g['dom_vat21'];
                        $b3['base12'] += $g['dom_base12']; $b3['vat12'] += $g['dom_vat12'];
                        $tag($g, 'B.3', $g['dom_base21'], $g['dom_base12']);
                    }
                }
            }
        }

        return [
            'sections' => ['a1' => $a1, 'a2' => $a2, 'a4' => $a4, 'a5' => $a5, 'b1' => $b1, 'b2' => $b2, 'b3' => $b3],
            'invoices' => $invoices,
            'missing_rates' => $missingRates,
        ];
    }

    /** @return list<string> warnings — is_vat_payer v $s je stav k poslednímu dni období (viz build) */
    private function validateSupplier(array $s, string $period = 'monthly', ?bool $payerDuringPeriod = null): array
    {
        $w = [];
        if (!$s['is_vat_payer'] && !($payerDuringPeriod ?? false)) {
            // Identifikovaná osoba (§ 6g–6l, issue #94) KH nepodává NIKDY (§ 101c
            // jen plátci) — přeshraniční povinnosti pokrývá DPHDP3 typ I + SHV.
            $w[] = !empty($s['is_identified'])
                ? 'Identifikovaná osoba kontrolní hlášení nepodává (§ 101c — jen plátci DPH). Přeshraniční plnění patří do přiznání DPH (typ I) a souhrnného hlášení.'
                : 'Tenant nebyl v průběhu období plátcem DPH — KH nemusí být relevantní.';
        }
        if ($period === 'quarterly' && ($s['taxpayer_type'] ?? '') === 'po') {
            $w[] = 'Právnické osoby podávají kontrolní hlášení VŽDY měsíčně (§ 101e odst. 1 zákona 235/2004 Sb.). Kvartální podání je povoleno pouze fyzickým osobám.';
        }
        if (empty($s['financial_office_code'])) $w[] = 'Chybí kód finančního úřadu.';
        if (empty($s['dic'])) $w[] = 'Chybí DIČ.';
        return $w;
    }

    private function loadSupplier(int $supplierId, ?string $statusDate = null): array
    {
        return EpoSupplierBlockBuilder::loadSupplier($this->db->pdo(), $supplierId, $statusDate);
    }


    /**
     * KH „Kód předmětu plnění" (VetaA1/VetaB1.kod_pred_pl) z klasifikace. Není-li na
     * klasifikaci vyplněn, spadne na '5' (odpad/šrot §92c) + jednorázový warning —
     * nejčastější tuzemský RC jsou ale stavební/montážní práce (§92e, kód '4').
     *
     * @param list<string> $warnings by-ref
     */
    private function resolveKodPredPl(?string $value, array &$warnings): string
    {
        if ($value !== null && $value !== '') {
            return $value;
        }
        $w = 'U některých plnění v přenesené povinnosti není na klasifikaci vyplněn kód '
           . 'předmětu plnění — použit default „5" (odpad/šrot §92c). Ověřte správný kód '
           . '(stavební/montážní práce = „4").';
        if (!in_array($w, $warnings, true)) {
            $warnings[] = $w;
        }
        return '5';
    }

    /**
     * Tuzemské RC vyžaduje číselnou kmenovou část DIČ. Neplatný řádek nesmí zůstat
     * v rekapitulaci VetaC, když jej nelze emitovat do A.1/B.1.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $warnings
     * @return list<array<string,mixed>>
     */
    private function filterReverseChargeRowsWithDic(array $rows, string $section, array &$warnings): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($section, &$warnings): bool {
            if (self::isValidCzechDic($row['counterparty_dic'] ?? '')) {
                return true;
            }
            $number = (string) ($row['vendor_invoice_number'] ?? 'bez čísla');
            $warnings[] = "Doklad {$number} nelze uvést v KH {$section}: chybí platné české DIČ protistrany. Doplňte DIČ před podáním.";
            return false;
        }));
    }

    /**
     * Doplní řádkům A.2 identifikaci dodavatele (`k_stat` + `vatid_dod`).
     * Řádek se NEVYŘAZUJE ani tehdy, když identifikaci sestavit nejde — prázdné
     * „Identifikace dodavatele“ je podle metodiky GFŘ správný stav, ne vada
     * ({@see a2Identification()} a komentář u emisního bloku VetaA2).
     *
     * Varování dostane jen dodavatel SE SÍDLEM V EU bez použitelného VAT ID: tam jde
     * skoro vždy o nekompletní údaj na kontaktu, který lze před podáním doplnit. U 3. země
     * je prázdná identifikace běžný stav a uživatel nemá co doplnit — varování na každý
     * zahraniční SaaS doklad by bylo jen šum.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $warnings by-ref
     * @return list<array<string,mixed>>
     */
    private function resolveA2Identification(array $rows, array &$warnings): array
    {
        $out = [];
        foreach ($rows as $row) {
            $ident = self::a2Identification(
                $row['country_iso2'] ?? null,
                !empty($row['country_is_eu']),
                $row['counterparty_dic'] ?? null,
            );
            if ($ident['vatid_dod'] === '' && !empty($row['country_is_eu'])) {
                $number = (string) ($row['vendor_invoice_number'] ?? '') ?: 'bez čísla';
                $warnings[] = "Doklad {$number} půjde do KH A.2 bez identifikace dodavatele: "
                    . 'u dodavatele z EU chybí DIČ registrace k DPH (VAT ID). Doplňte ho na '
                    . 'kontaktu před podáním; EPO jinak vypíše propustné chyby č. 58 a č. 60.';
            }
            $out[] = $row + $ident;
        }
        return $out;
    }

    /**
     * Identifikace dodavatele pro KH oddíl A.2 (VetaA2.k_stat + vatid_dod), s prázdnými
     * řetězci tam, kde ji sestavit nejde.
     *
     * NEVRACÍ `null` a není to filtr: plnění od dodavatele bez EU DIČ do A.2 PATŘÍ —
     * dokumentace `vatid_dod` v dphkh1.xsd i metodická informace GFŘ k vyplnění KH
     * shodně uvádějí, že včetně „identifikace zahraniční osoby povinné k dani“ pole
     * „Identifikace dodavatele“ zůstává prázdné, a EPO na to reaguje jen PROPUSTNýMI
     * chybami č. 58 / č. 60 (issue #53).
     *
     * Rozhoduje EXISTENCE DIČ registrace k DPH v členském státě, ne sídlo dodavatele:
     * osoba se sídlem ve 3. zemi může být registrovaná k DPH v EU (a pak identifikaci má),
     * neplátce se sídlem v EU registraci nemá (a pak ji nemá). U sídla mimo EU se proto
     * kód státu bere z prefixu samotného VAT ID, ne ze země kontaktu.
     *
     * Public static: stejné pravidlo potřebuje i Kniha DPH (sloupec „KH“), ať se výkaz
     * a Kniha nerozejdou.
     *
     * @return array{k_stat: string, vatid_dod: string} prázdné řetězce = bez identifikace
     */
    public static function a2Identification(?string $countryIso2, bool $countryIsEu, ?string $vatId): array
    {
        $none = ['k_stat' => '', 'vatid_dod' => ''];
        // Prefix se stráhá podle TÉHOŽ hodnoty, která jde do `k_stat`, jinak by v čísle
        // zůstal cizi prefix (Řecko: ISO GR vs. DPH kód EL).
        $seat = $countryIsEu ? self::khCountryCode($countryIso2) : '';
        if ($seat !== '') {
            $clean = self::cleanEuVatId($vatId, $countryIso2);
            return $clean === '' ? $none : ['k_stat' => $seat, 'vatid_dod' => $clean];
        }
        // Sídlo mimo EU (nebo neznámé): jediná použitelná identifikace je registrace
        // v členském státě, kterou nese prefix samotného VAT ID.
        $kStat = self::euVatIdPrefix($vatId);
        if ($kStat === '') {
            return $none;
        }
        $clean = self::cleanEuVatId($vatId, $kStat);
        return $clean === '' ? $none : ['k_stat' => $kStat, 'vatid_dod' => $clean];
    }

    /**
     * Kód členského státu z prefixu VAT ID, nebo '' když prefix není kód členského státu.
     * Slouží dodavateli se sídlem mimo EU, který je přesto registrovaný k DPH v některém
     * členském státě — `k_stat` je „kód státu, který přidělil DIČ registrace k DPH“,
     * takže se řídí registrací, ne sídlem.
     */
    private static function euVatIdPrefix(?string $vatId): string
    {
        $s = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $vatId))) ?? '';
        $prefix = substr($s, 0, 2);
        return strlen($s) > 2 && in_array($prefix, self::KH_MEMBER_STATE_CODES, true) ? $prefix : '';
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $warnings
     * @return list<array<string,mixed>>
     */
    private function filterKhAttributeConflicts(array $rows, string $section, array &$warnings): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($section, &$warnings): bool {
            if (empty($row['kh_attribute_conflict'])) {
                return true;
            }
            $number = (string) ($row['varsymbol'] ?? $row['vendor_invoice_number'] ?? 'bez čísla');
            $warnings[] = "Doklad {$number} nelze uvést v KH {$section}: položky mají rozdílný režim plnění nebo příznak opravy nedobytné pohledávky. Sjednoťte klasifikaci před podáním.";
            return false;
        }));
    }

    /**
     * Tvar českého DIČ, jak ho KH přijme do vět s protistranou. Public záměrně:
     * kdo plní `counterparty_dic`, musí umět zkontrolovat totéž ještě před podáním
     * (M-9 — pokladní prodej s cizím VAT ID by se do A.4 dostal jako české DIČ
     * osekané na číslice). Jediná definice, žádná kopie regexu.
     */
    public static function isValidCzechDic(?string $dic): bool
    {
        $value = strtoupper(trim((string) $dic));
        return preg_match('/^(?:CZ)?[0-9]{1,10}$/', $value) === 1;
    }

    /** DIČ pro KH XML — odstraní CZ prefix, jen číslice. */
    /** Public static: stejnou normalizaci DIČ používá DphBookBuilder pro efektivní KH sekci. */
    public static function cleanDic(?string $dic): string
    {
        // SSOT je EpoSupplierBlockBuilder::normalizeDic — tahle metoda je jen zavedený
        // vstupní bod pro KH a zůstává kvůli volajícím.
        return EpoSupplierBlockBuilder::normalizeDic($dic);
    }

    /**
     * VAT ID dodavatele z jiného členského státu pro VetaA2.vatid_dod (KH oddíl A.2).
     *
     * Na rozdíl od českého DIČ (jen číslice, viz cleanDic) je EU VAT ID alfanumerické a
     * u řady států obsahuje písmena (IE 1234567X, AT U12345678, NL 123456789B01, …).
     * XSD vyžaduje formát BEZ kódu členského státu — odstraníme prefix země, mezery a
     * oddělovače, zachováme alfanumerickou kmenovou část.
     *
     * Strháváme JEN prefix odpovídající kódu země — buď ISO 3166 (country_iso2), nebo
     * VIES/DPH kód (u Řecka se liší: ISO "GR" vs VIES "EL", takže akceptujeme obojí).
     * NEstrháváme libovolná 2 písmena — některá DIČ mají alfanumerickou vnitrostátní
     * část (FR: „FRAB123456789" → „AB123456789", ne „123456789"). Issue #238.
     */
    public static function cleanEuVatId(?string $vatId, ?string $countryIso2): string
    {
        if (!$vatId) return '';
        $s = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($vatId))) ?? '';
        $iso  = strtoupper(trim((string) $countryIso2)); // ISO 3166 (GR)
        $vies = self::khCountryCode($countryIso2);        // VIES/DPH kód (EL pro Řecko)
        foreach ([$vies, $iso] as $prefix) {
            if ($prefix !== '' && str_starts_with($s, $prefix)) {
                return substr($s, strlen($prefix));
            }
        }
        return $s;
    }

    /**
     * Kód státu pro KH (VetaA2.k_stat / prefix VAT ID). Vychází z ISO 3166-1 alpha-2,
     * ale s odchylkami EU registru DPH: Řecko má ISO "GR", ale DPH kód "EL".
     */
    public static function khCountryCode(?string $iso2): string
    {
        $c = strtoupper(trim((string) $iso2));
        return $c === 'GR' ? 'EL' : $c;
    }

    /** Date pro KH XML — convert YYYY-MM-DD na DD.MM.YYYY (EPO datum format). */
    private function formatDate(?string $isoDate): string
    {
        if (!$isoDate) return '';
        try {
            return (new \DateTimeImmutable($isoDate))->format('d.m.Y');
        } catch (\Throwable) {
            return '';
        }
    }

    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
