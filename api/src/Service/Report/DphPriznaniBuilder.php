<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxSubmissionRepository;
use MyInvoice\Repository\VatCoefficientRepository;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Tax\BadDebt\Section46Service;
use MyInvoice\Service\Tax\BadDebt\Section74bService;

/**
 * Builder XML pro DPH přiznání (DPHDP3) — EPO portál MFČR.
 *
 * Verze EPO: 03.01 (platná 2025-2026).
 *
 * ⚠️ Vygenerované XML je POUZE POMŮCKA. Před odesláním vždy ověřit s účetní
 *    nebo daňovým poradcem. Aplikace nezaručuje regulatorní správnost.
 *
 * Schema: https://adisspr.mfcr.cz/dpr/adis/idpr_pub/dpr_info/schemas.faces
 */
final class DphPriznaniBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly VatClassificationMapper $mapper,
        private readonly VatLedgerService $ledger,
        private readonly VatCoefficientRepository $coefficients,
        private readonly TaxSubmissionRepository $submissions,
        private readonly TaxConstantsRepository $constants,
        private readonly Section74bService $section74b,
        private readonly Section46Service $section46,
        private readonly VatDeductionAdjustmentService $deductionAdjustments,
        private readonly Section79Service $section79,
        private readonly \MyInvoice\Service\Tax\Vat\Section43Service $section43,
    ) {}

    /**
     * Mapa UI variant → EPO `dapdph_forma`. B=řádné, O=opravné (§138, PŘED lhůtou —
     * plný restatement), D=dodatečné (§141, PO lhůtě — pouze rozdíly), E=dodatečné/opravné.
     * O je full jako B; D/E se počítají jako DELTA proti poslední známé dani.
     */
    private const VARIANT_FORMA = [
        'radne'              => 'B',
        'opravne'            => 'O',
        'dodatecne'          => 'D',
        'dodatecne_opravne'  => 'E',
    ];

    /**
     * Atributy Veta1-5, které NEJSOU peněžní částky (koeficient §76 v %) — v dodatečném
     * přiznání se NEdiffují (procento popisuje období, ne rozdíl), přebírá se nová hodnota.
     */
    private const NON_DIFF_ATTRS = ['koef_p20_nov' => true, 'koef_p20_vypor' => true];

    /**
     * Řádky přiznání, které smí uživatel nastavit na klasifikaci DPH
     * (`vat_classifications.dphdp3_line` / `dphdp3_line_secondary`).
     *
     * Zdroj pravdy pro validaci v {@see \MyInvoice\Action\Codebook\VatClassificationsAction}
     * — drž to v syncu s `$lineMap` v {@see build()}. Řádek mimo tenhle seznam builder
     * nevykreslí a do XML se nedostane (s varováním), takže ho nemá smysl povolit.
     *
     * ZÁMĚRNĚ VYNECHÁNO, ačkoli v $lineMap jsou:
     *   - '34' (opr_dluz, § 74b) — slot `base` u něj nese DAŇ, ne základ; plní ho interní
     *     injekce applySection74bCorrections(). Uživatelem nastavená '34' by tam poslala
     *     ZÁKLAD a daň zahodila → tiše CHYBNÁ hodnota v podaném XML, což je horší než
     *     chybějící řádek.
     *   - '33' (opr_verit, § 46) — stejná past jako '34', jen na věřitelské straně: slot
     *     `base` nese DAŇ a plní ho interní injekce applySection46Corrections(). Oprava
     *     u nedobytné pohledávky se zadává přes {@see Section46Service}, ne klasifikací.
     *   - '40k'/'41k'/'42k' — krácený odpočet § 76; klíče si tvoří VatLedgerService sám
     *     podle vat_deduction='reduced', nenastavují se ručně.
     *
     * @var list<string>
     */
    public const USER_SELECTABLE_LINES = [
        '1', '2', '3', '4', '5', '6', '7', '8', '10', '11', '12', '13',
        '20', '21', '22', '23', '24', '25', '26',
        '30', '31',
        '40', '41', '42', '43', '44', '47',
        '50', '51', '51b',
    ];

    /**
     * Sestaví XML pro DPH přiznání za daný měsíc/kvartál.
     *
     * @param string $period 'monthly' (default) nebo 'quarterly' (sumuje celý kvartál)
     * @param string $variant 'radne'|'opravne'|'dodatecne'|'dodatecne_opravne' (C7').
     *        radne/opravne = plné přiznání (B/O), dodatecne/dodatecne_opravne = ROZDÍL
     *        proti poslední známé dani (D/E, § 141 daňového řádu).
     * @param ?string $dZjist datum zjištění důvodů pro dodatečné (Y-m-d) — povinné pro D/E.
     * @param ?string $reason důvody pro podání dodatečného přiznání (§ 141 odst. 5 DŘ) —
     *        jdou do textové přílohy VetaR (kod_sekce='D'); prázdné = doplní se obecný text.
     * @return array{xml: string, summary: array<string, mixed>, warnings: list<string>, missing_rates: list<array<string,mixed>>}
     */
    public function build(
        int $supplierId,
        int $year,
        int $month,
        ?string $period = null,
        string $variant = 'radne',
        ?string $dZjist = null,
        ?string $reason = null,
    ): array {
        $forma = self::VARIANT_FORMA[$variant] ?? null;
        if ($forma === null) {
            throw new PostingException('vat_variant_invalid', "Neznámý typ přiznání: {$variant}.", 422);
        }
        $isAmendment = $forma === 'D' || $forma === 'E'; // dodatečné — vykazuje pouze rozdíly
        // d_zjist (§141 DŘ) je pro dodatečné přiznání nezbytné — bez tichého defaultu.
        if ($isAmendment) {
            $dZjist = $this->normalizeDate($dZjist);
            if ($dZjist === null) {
                throw new PostingException(
                    'vat_d_zjist_required',
                    'Dodatečné přiznání vyžaduje datum zjištění důvodů (§ 141 daňového řádu).',
                    422,
                );
            }
        } else {
            $dZjist = null;
        }
        $supplier = $this->loadSupplier($supplierId);
        // Default period z supplier.vat_period, fallback 'monthly'
        if ($period === null) {
            $period = (string) ($supplier['vat_period'] ?? 'monthly');
        }
        if (!in_array($period, ['monthly', 'quarterly'], true)) {
            $period = 'monthly';
        }
        // Rozhodný stav plátcovství = POSLEDNÍ DEN období výkazu, ne dnešek (EPIC VH-04).
        // První load výše slouží jen pro default periodicity; typ P/I, warning neplátce
        // i gates korekcí §74b/§46/§43 níže se řídí supplierem se stavem k období
        // (oba flagy z historie — migrace 1181 historizuje i identifikovanou osobu).
        [$rangeStart, $statusDate] = $this->periodRange($year, $month, $period);
        // §141 DŘ: důvody pro dodatečné přiznání nelze zjistit dřív, než období skončilo,
        // ani v budoucnu. Dřív se sem propsalo cokoli, co spolkl DateTimeImmutable —
        // a přitom právě tímhle datem běží lhůta pro podání.
        if ($isAmendment && $dZjist !== null) {
            if ($dZjist < $statusDate) {
                throw new PostingException(
                    'vat_d_zjist_before_period',
                    sprintf(
                        'Datum zjištění důvodů (%s) předchází konci opravovaného období (%s). '
                            . 'Dodatečné přiznání se podává až po skončení období.',
                        $dZjist,
                        $statusDate,
                    ),
                    422,
                );
            }
            // „Nesmí být v budoucnu" má smysl jen u období, které skutečně skončilo — tedy
            // u každého reálného dodatečného přiznání. U období, jehož konec teprve přijde,
            // by kontrola porovnávala dvě budoucí data a jen překážela.
            $today = date('Y-m-d');
            if ($statusDate <= $today && $dZjist > $today) {
                throw new PostingException(
                    'vat_d_zjist_future',
                    'Datum zjištění důvodů nemůže být v budoucnosti.',
                    422,
                );
            }
        }
        $supplier = $this->loadSupplier($supplierId, $statusDate);
        // Identifikovaná osoba (§ 6g–6l ZDPH, issue #94): přiznání typu 'I' —
        // vyplňuje JEN samovyměření z přeshraničních přijatých plnění (ř. 3-6
        // pořízení zboží / služby z EU, ř. 12-13 služby ze 3. zemí), vždy měsíčně,
        // a jen za měsíce, kdy povinnost vznikla. BEZ nároku na odpočet (žádná
        // Veta4 vč. zrcadlového ř. 43), bez tuzemských výstupů (ř. 1/2) i oddílu C
        // (ř. 20-26 — služby do EU vykazuje jen v souhrnném hlášení).
        $isIdentified = !$supplier['is_vat_payer'] && !empty($supplier['is_identified']);

        $warnings = [];
        if ($isIdentified) {
            $warnings[] = 'Přiznání identifikované osoby (typ I): jen samovyměření z přeshraničních plnění, bez nároku na odpočet. Podává se pouze za měsíce, kdy povinnost vznikla (do 25. dne následujícího měsíce).';
        } elseif (!$supplier['is_vat_payer']
            && !\MyInvoice\Service\Vat\VatStatusService::payerDuring($this->db->pdo(), $supplierId, $rangeStart, $statusDate)
        ) {
            // Plátcovství byť po část období = přiznání za období se podává (zrušení
            // registrace uprostřed období poslední přiznání neruší) — warning patří
            // jen firmě, která nebyla plátcem ani jediný den období.
            $warnings[] = 'Tenant nebyl v průběhu období evidovaný jako plátce DPH — výkaz nemusí být relevantní.';
        }
        if (empty($supplier['financial_office_code'])) {
            $warnings[] = 'Chybí kód finančního úřadu — XML nemusí projít validací EPO.';
        }
        if (empty($supplier['ic'])) {
            $warnings[] = 'Chybí IČO tenanta.';
        }
        if (empty($supplier['dic'])) {
            $warnings[] = 'Chybí DIČ tenanta.';
        }

        if ($isIdentified && $period !== 'monthly') {
            // IO má zdaňovací období VŽDY kalendářní měsíc (§ 99 se na ni nevztahuje,
            // povinnost vzniká per měsíc dle § 101 odst. 5).
            $period = 'monthly';
            $warnings[] = 'Identifikovaná osoba podává vždy za kalendářní měsíc — kvartální volba ignorována.';
        }

        [$vatStart, $vatEnd] = $this->periodRange($year, $month, $period);
        $vatRows = $this->ledger->rows($supplierId, $vatStart, $vatEnd, includeDrafts: false);
        $lines = $this->mapper->projectDphLines($vatRows);
        // #238: doklady v cizí měně bez kurzu — NEházíme chybu, vrátíme je v
        // `missing_rates` a akce je při stažení doplní z ČNB (náhled jen varuje).
        $missingRates = VatLedgerService::missingExchangeRateRows($vatRows);
        if ($missingRates !== []) {
            $warnings[] = 'Chybí kurz u dokladů v cizí měně: '
                . implode(', ', VatLedgerService::missingExchangeRateLabels($missingRates))
                . '. Při stažení XML se doplní z ČNB.';
        }
        $this->appendSalesDataWarnings($supplierId, $year, $month, $period, $warnings);
        if ($isIdentified) {
            $lines = $this->filterLinesForIdentified($lines, $warnings);
        }
        $quarter = $period === 'quarterly' ? (int) ceil($month / 3) : null;

        // § 74b ZDPH — evidované korekce odpočtu dlužníka za období (audit §2.5). Snížení
        // (odst. 1/3): ř. 40/41 základ i daň ZÁPORNĚ + ř. 34 opr_dluz KLADNĚ; obnova
        // (odst. 2/4): opačně. Řádek 34 je informativní (vat=null → mimo rekapitulaci ř.62/63),
        // skutečný daňový efekt nese ř. 40/41 (vstupuje do ř. 46/63). Jen skuteční plátci —
        // identifikovaná osoba odpočet nemá. Korekce se vždy míří do sloupce „V plné výši".
        if (!$isIdentified && !empty($supplier['is_vat_payer'])) {
            $this->applySection74bCorrections($lines, $supplierId, $year, $month, $period);
        }

        // § 46 ZDPH — evidované věřitelské opravy u nedobytné pohledávky. Zrcadlo § 74b
        // s prohozenými stranami: oprava snižuje daň na VÝSTUPU, tedy ř. 1/2 základ i daň
        // ZÁPORNĚ + informativní ř. 33 opr_verit KLADNĚ; obnova po úhradě (§ 46e) opačně.
        // Identifikovaná osoba tuzemská zdanitelná plnění neuskutečňuje, takže se jí netýká.
        if (!$isIdentified && !empty($supplier['is_vat_payer'])) {
            $this->applySection46Corrections($lines, $supplierId, $year, $month, $period);

            // § 43 ZDPH — oprava VÝŠE daně (chybná sazba, špatný výpočet). Na rozdíl od
            // § 42 patří ZPĚTNĚ do období PŮVODNÍHO plnění, proto se načítá podle období,
            // které se právě staví, a ne podle data opravného dokladu.
            $s43 = $this->section43->periodCorrectionLines($supplierId, $year, $month, $period);
            $this->addToLine($lines, '1', $s43['basic']['base'], $s43['basic']['vat'], 'Oprava výše daně §43 (21 %)');
            $this->addToLine($lines, '2', $s43['reduced']['base'], $s43['reduced']['vat'], 'Oprava výše daně §43 (12 %)');

            // § 43 odst. 1 opravu výslovně směruje do DODATEČNÉHO přiznání. Když se za
            // totéž období staví řádné, oprava sice sedí věcně, ale podá se špatným
            // typem podání — a to je vada, kterou by jinak nikdo nezachytil.
            if (!$isAmendment && ($s43['basic']['vat'] != 0.0 || $s43['reduced']['vat'] != 0.0)) {
                $warnings[] = 'Za toto období jsou evidované opravy výše daně podle § 43 ZDPH. '
                    . 'Ty se podávají v DODATEČNÉM daňovém přiznání za období původního plnění '
                    . '(§ 43 odst. 1) — zkontroluj typ podání.';
            }
        }

        // <Pisemnost nazevSW verzeSW> > <DPHDP3 verzePis="03.01">
        [$dom, $dphdp3] = EpoEnvelope::create('DPHDP3', '03.01');

        // ── VetaD: identifikační údaje (typ podání + perioda) ─────────
        // Per EPO XSD: typ_platce je v VetaD, typ_ds v VetaP.
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'DPH');
        $vetaD->setAttribute('rok', (string) $year);
        if ($quarter !== null) {
            $vetaD->setAttribute('ctvrt', (string) $quarter);
        } else {
            $vetaD->setAttribute('mesic', (string) $month);
        }
        $vetaD->setAttribute('dapdph_forma', $forma); // B=řádné, O=opravné, D=dodatečné, E=dodatečné/opravné
        if ($dZjist !== null) {
            // § 141 DŘ: den zjištění důvodů pro podání dodatečného přiznání (D/E) — DD.MM.YYYY.
            $vetaD->setAttribute('d_zjist', (new \DateTimeImmutable($dZjist))->format('d.m.Y'));
        }
        $vetaD->setAttribute('dokument', 'DP3');   // identifikace typu výkazu
        // P = plátce DPH (default), I = identifikovaná osoba (S = skupina, N = neplátce)
        $vetaD->setAttribute('typ_platce', $isIdentified ? 'I' : 'P');
        // CZ-NACE klasifikace (hlavní ekonomická činnost, 6-digit) — vyplňuje se
        // z `supplier.cz_nace_code`. Hodnotu očekávanou EPO ověřuje uživatel
        // proti číselníku https://mojedane.gov.cz/pmd/dokumentace/ciselniky/ukazka/okec.
        $okec = EpoSupplierBlockBuilder::normalizeOkec((string) ($supplier['cz_nace_code'] ?? ''));
        if ($okec !== null) {
            $vetaD->setAttribute('c_okec', $okec);
        }
        $okecWarning = EpoSupplierBlockBuilder::okecWarning((string) ($supplier['cz_nace_code'] ?? ''));
        if ($okecWarning !== null) {
            $warnings[] = $okecWarning;
        }
        $vetaD->setAttribute('d_poddp', date('d.m.Y')); // datum podání (dnes)
        // trans: „existují údaje pro oddíl C?" — spočteme níže, až budou
        // Veta1-5 sestavené, a setneme přes setAttribute.
        $dphdp3->appendChild($vetaD);

        // ── VetaP: identifikace daňového subjektu ─────────────────────
        $vetaP = $dom->createElement('VetaP');
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $supplier);
        $dphdp3->appendChild($vetaP);

        // ── Veta1 / Veta4: namapování řádků 1-13 (Veta1) a 40-47 (Veta4) ──
        //
        // Mapping odpovídá EPO XSD (api/xsd/dphdp3.xsd), reálně podanému
        // přiznání a oficiálnímu MFČR DPHDP3 formuláři (verze 03.01):
        //
        // Veta1 — DPH na výstupu (vč. samovyměřené u RC):
        //   ř.1  obrat23/dan23           = sale 21 %
        //   ř.2  obrat5/dan5             = sale 12 %
        //   ř.3  p_zb23/dan_pzb23        = pořízení zboží z JČS 21 % (EU)
        //   ř.4  p_zb5/dan_pzb5          = pořízení zboží z JČS 12 % (EU)
        //   ř.5  p_sl23_e/dan_psl23_e    = přijetí služby z EU 21 %
        //   ř.6  p_sl5_e/dan_psl5_e      = přijetí služby z EU 12 %
        //   ř.7  dov_zb23/dan_dzb23      = dovoz zboží 21 %
        //   ř.8  dov_zb5/dan_dzb5        = dovoz zboží 12 %
        //   ř.10 rez_pren23/dan_rpren23  = tuzemský reverse charge 21 %
        //   ř.11 rez_pren5/dan_rpren5    = tuzemský reverse charge 12 %
        //   ř.12 p_sl23_z/dan_psl23_z    = přijetí služby ze 3. země 21 %
        //   ř.13 p_sl5_z/dan_psl5_z      = přijetí služby ze 3. země 12 %
        //
        // Veta4 — Nárok na odpočet daně:
        //   ř.40 pln23/odp_tuz23_nar     = tuzemsko 21 %
        //   ř.41 pln5/odp_tuz5_nar       = tuzemsko 12 %
        //   ř.42 dov_cu/odp_cu_nar       = dovoz CÚ
        //   ř.43 nar_zdp23/od_zdp23      = odpočet ze samovyměřených plnění (ř. 3-13),
        //                                  sloupec „V plné výši", ZÁKLADNÍ sazba (21 %).
        //   ř.44 nar_zdp5/od_zdp5        = totéž ve SNÍŽENÉ sazbě (12 %) — RC řádek s 12%
        //                                  sazbou se sem remapuje ve VatLedgerService (S3).
        //                                  POZOR: odp_rezim/odp_rez_nar je ř.45 (korekce
        //                                  odpočtu dle §75/§77/§79), NE ř.44.
        //   ř.45 odp_rez_nar             = korekce odpočtu při registraci a zrušení
        //                                  registrace (§ 79/§ 79a) — plní se z vlastní
        //                                  evidence přes Section79Service, NE z klasifikací
        //                                  dokladů: nejde o vlastnost dokladu, ale
        //                                  o jednorázovou událost registrace. Klasifikace
        //                                  mířící na ř.45 se proto pořád nevykreslí ani
        //                                  nezapočte (viz guard níže). Zbytek ř.45
        //                                  (§ 75 odst. 4, § 77, § 77a) mimo rozsah.
        //   ř.46 odp_sum_nar             = součtový řádek odpočtu (ř.40-45, „V plné výši")
        //   ř.47 nar_maj/—               = hodnota pořízeného majetku (doplňující údaj).
        //                                  POZOR: XSD atributy od_maj/odkr_maj (daň v plné
        //                                  i krácené výši) EXISTUJÍ — generátor je zatím
        //                                  neplní, není to omezení formuláře.
        $lineMap = [
            // Veta1 (výstup)
            '1'  => ['veta' => 1, 'base' => 'obrat23',    'vat' => 'dan23'],
            '2'  => ['veta' => 1, 'base' => 'obrat5',     'vat' => 'dan5'],
            '3'  => ['veta' => 1, 'base' => 'p_zb23',     'vat' => 'dan_pzb23'],
            '4'  => ['veta' => 1, 'base' => 'p_zb5',      'vat' => 'dan_pzb5'],
            '5'  => ['veta' => 1, 'base' => 'p_sl23_e',   'vat' => 'dan_psl23_e'],
            '6'  => ['veta' => 1, 'base' => 'p_sl5_e',    'vat' => 'dan_psl5_e'],
            '7'  => ['veta' => 1, 'base' => 'dov_zb23',   'vat' => 'dan_dzb23'],
            '8'  => ['veta' => 1, 'base' => 'dov_zb5',    'vat' => 'dan_dzb5'],
            '10' => ['veta' => 1, 'base' => 'rez_pren23', 'vat' => 'dan_rpren23'],
            '11' => ['veta' => 1, 'base' => 'rez_pren5',  'vat' => 'dan_rpren5'],
            '12' => ['veta' => 1, 'base' => 'p_sl23_z',   'vat' => 'dan_psl23_z'],
            '13' => ['veta' => 1, 'base' => 'p_sl5_z',    'vat' => 'dan_psl5_z'],
            // Veta2 (oddíl C — ostatní plnění s nárokem na odpočet; jen základ, bez daně):
            //   ř.20 dodání zboží do JČS · ř.21 služby do JČS (§9/1) · ř.22 vývoz (§66)
            //   ř.23 dodání nového dopr. prostředku neregistrované osobě · ř.24 zasílání zboží
            //   ř.25 RC dodavatel (§92a) · ř.26 ostatní plnění s nárokem na odpočet
            '20' => ['veta' => 2, 'base' => 'dod_zb',      'vat' => null],
            '21' => ['veta' => 2, 'base' => 'pln_sluzby',  'vat' => null],
            '22' => ['veta' => 2, 'base' => 'pln_vyvoz',   'vat' => null],
            '23' => ['veta' => 2, 'base' => 'dod_dop_nrg', 'vat' => null],
            '24' => ['veta' => 2, 'base' => 'pln_zaslani', 'vat' => null],
            '25' => ['veta' => 2, 'base' => 'pln_rez_pren','vat' => null],
            '26' => ['veta' => 2, 'base' => 'pln_ost',     'vat' => null],
            // Veta3 (oddíl C — doplňující údaje; jen základ, bez daně):
            //   ř.30 pořízení zboží prostřední osobou · ř.31 dodání zboží prostřední osobou
            //   (třístranný obchod § 17). Hodnota z ř.31 jde do souhrnného hlášení s kódem 2.
            '30' => ['veta' => 3, 'base' => 'tri_pozb',   'vat' => null],
            '31' => ['veta' => 3, 'base' => 'tri_dozb',   'vat' => null],
            // ř.33 opr_verit (§46, věřitel) — INFORMATIVNÍ oprava výše daně u nedobytné
            // pohledávky: oprava KLADNĚ, obnova po úhradě (§46e) ZÁPORNĚ (anotace XSD).
            // Nese jen DPH (base slot), vat=null → mimo rekapitulaci ř.62/63; skutečný
            // daňový efekt je na ř.1/2, kam ho míří applySection46Corrections().
            '33' => ['veta' => 3, 'base' => 'opr_verit',  'vat' => null],
            // ř.34 opr_dluz (§74b, dlužník) — INFORMATIVNÍ oprava odpočtu daně: snížení
            // (odst. 1/3) KLADNĚ, obnova (odst. 2/4) ZÁPORNĚ. Nese jen DPH (base slot),
            // vat=null → do rekapitulace ř.62/63 NEvstupuje (skutečný efekt je na ř.40/41).
            '34' => ['veta' => 3, 'base' => 'opr_dluz',   'vat' => null],
            // Veta5 (oddíl B — krácení nároku na odpočet §76):
            //   ř.50 plnosv_kf = plnění osvobozená od daně bez nároku na odpočet (§51),
            //   sloupec „S nárokem na odpočet" vstupující do koeficientu §76. Jen základ,
            //   bez daně (osvobozené plnění daň nenese). Plný koeficient (koef_p20_*,
            //   ř.52/53) se needituje — mimo rozsah, řeší účetní.
            '50' => ['veta' => 5, 'base' => 'plnosv_kf',  'vat' => null],
            // ř.51 — plnění s nárokem na odpočet vyloučená z koeficientu (§ 76/4),
            // typicky prodej dlouhodobého majetku. Jde o doplňující údaj: stejné plnění
            // zůstává současně na primárním ř.1/2 přes secondary mapping klasifikace.
            '51' => ['veta' => 5, 'base' => 'pln_nkf',    'vat' => null],
            '51b' => ['veta' => 5, 'base' => 'plnosv_nkf', 'vat' => null],
            // Veta4 (odpočet)
            '40' => ['veta' => 4, 'base' => 'pln23',      'vat' => 'odp_tuz23_nar'],
            '41' => ['veta' => 4, 'base' => 'pln5',       'vat' => 'odp_tuz5_nar'],
            '42' => ['veta' => 4, 'base' => 'dov_cu',     'vat' => 'odp_cu_nar'],
            '43' => ['veta' => 4, 'base' => 'nar_zdp23',  'vat' => 'od_zdp23'],
            '44' => ['veta' => 4, 'base' => 'nar_zdp5',   'vat' => 'od_zdp5'],
            '47' => ['veta' => 4, 'base' => 'nar_maj',    'vat' => null],
            // Veta4 — krácený odpočet § 76 (sloupec „Krácený odpočet", ř.40-42). Klíče
            // 40k/41k/42k vytváří VatLedgerService pro vat_deduction='reduced'. Základ jde
            // do TÉHOŽ atributu jako plná verze (pln23/pln5/dov_cu — jediný sdílený sloupec
            // základu obou variant), daň do krácených atributů odp_tuz23/odp_tuz5/odp_cu
            // (bez _nar). ř.43 (RC mirror) a ř.45 tu krácenou variantu nemají — ne proto, že
            // by v XSD chyběla (odkr_zdp23/odkr_zdp5 tam JSOU), ale protože ji generátor
            // nepodporuje: VatLedgerService kombinaci RC + 'reduced' tvrdě odmítne chybou
            // reduced_deduction_unsupported_line, takže se sem takový řádek nedostane.
            '40k' => ['veta' => 4, 'base' => 'pln23',    'vat' => 'odp_tuz23'],
            '41k' => ['veta' => 4, 'base' => 'pln5',     'vat' => 'odp_tuz5'],
            '42k' => ['veta' => 4, 'base' => 'dov_cu',   'vat' => 'odp_cu'],
        ];

        // Base i daň sčítáme NUMERICKY (ne string-set), protože krácené řádky 40k/41k/42k
        // (§ 76) míří na TENTÝŽ atribut základu (pln23/pln5/dov_cu) jako plná verze 40/41/42
        // — sdílený sloupec základu se musí PŘIČÍST, ne přepsat. Daň jde do odlišných
        // atributů (odp_tuz23_nar vs odp_tuz23), tam ke kolizi nedochází. Formát až při emitu.
        $totalDanZdanitelne = 0.0;
        $totalDanOdpNar     = 0.0;  // ř.46 „V plné výši" (odp_sum_nar) — BEZ krácených řádků
        $totalDanOdpKraceny = 0.0;  // ř.46 „Krácený odpočet" (odp_sum_kr) = Σ ř.40k/41k/42k
        $veta1Raw = [];
        $veta2Raw = [];
        $veta3Raw = [];
        $veta4Raw = [];
        $veta5Raw = [];

        foreach ($lines as $lineNum => $data) {
            $lineKey = (string) $lineNum;
            // Řádek mimo lineMap (builder ho neumí vykreslit — např. custom kód na ř.45)
            // se NEvykreslí ANI nezapočítá do rekapitulace. Dřív se tiše přičítal do
            // ř.46/62/63, aniž by byl v detailu → EPO hlásilo nekonzistenci (audit 2026-07).
            //
            // Zahození ale NESMÍ být tiché: `dphdp3_line` je v číselníku klasifikací volný
            // text, takže překlep nebo nepodporovaný řádek jinak zmizí i se základem —
            // v náhledu ho uživatel VIDÍ (summary nese nefiltrovanou projekci), ve staženém
            // XML ne. Varování ten rozpor pojmenuje dřív, než se přiznání podá.
            if (!isset($lineMap[$lineKey])) {
                $warnings[] = sprintf(
                    'Řádek %s (základ %s Kč, daň %s Kč) nemá v generátoru mapování a do XML '
                        . 'se NEPROMÍTNE — zkontroluj dphdp3_line u klasifikace DPH.',
                    $lineKey,
                    number_format((float) $data['base'], 2, ',', ' '),
                    number_format((float) $data['vat'], 2, ',', ' '),
                );
                continue;
            }
            $m = $lineMap[$lineKey];
            $target = &${'veta' . $m['veta'] . 'Raw'};
            $target[$m['base']] = ($target[$m['base']] ?? 0.0) + (float) $data['base'];
            if ($m['vat'] !== null) {
                $target[$m['vat']] = ($target[$m['vat']] ?? 0.0) + (float) $data['vat'];
            }
            unset($target);

            // Rekapitulace jen z řádků, které NESOU daň (mají vat atribut). Řádky jen se
            // základem — oddíl C (ř.20-31), osvobozené (ř.50), majetek (ř.47, vat=null) —
            // do ř.62/63 nepatří; jinak by zbloudilá daň na základovém řádku nafoukla ř.62.
            // Sčítáme zaokrouhleně na celé Kč (jak se vykazují), aby ř.62/63 seděly s detailem.
            if ($m['vat'] === null) {
                continue;
            }
            $lineVat = round($data['vat']);
            if ($this->isOutputLine($lineKey)) {
                $totalDanZdanitelne += $lineVat;
            } elseif (str_ends_with($lineKey, 'k')) {
                // Krácené řádky § 76 → samostatný součet „Krácený odpočet" (odp_sum_kr).
                // Z ř.46 „V plné výši" se MUSÍ vyloučit, jinak by se krácená daň počítala
                // 2× (jednou nekráceně, podruhé jako ř.52 odp_uprav_kf).
                $totalDanOdpKraceny += $lineVat;
            } else {
                // Ostatní odpočtové řádky → ř.46 „V plné výši" (odp_sum_nar).
                $totalDanOdpNar += $lineVat;
            }
        }

        // ── § 76 krácení: zálohový koeficient (ř.52) + roční vypořádání (ř.53) ──
        $isLastPeriodOfYear = ($quarter === null && $month === 12) || $quarter === 4;
        $provisionalPercent = null;
        $oduprav            = 0.0;   // ř.52 „Odpočet" (odp_uprav_kf) za TOTO období
        $vyporOdp           = 0.0;   // ř.53 „Změna odpočtu" (vypor_odp) — jen poslední období
        $annualCoef         = null;

        // Roční koeficient je potřeba v posledním období roku i tehdy, když TOTO období
        // žádné krácené řádky nemá — vypořádání se počítá z celoročních dat.
        if ($isLastPeriodOfYear) {
            $annualCoef = $this->computeAnnualCoefficient($supplierId, $year);
        }
        $needsCoefficient = $totalDanOdpKraceny > 0.0
            || ($annualCoef !== null && $annualCoef['kr_year'] > 0.0);
        if ($needsCoefficient) {
            // Žádný tichý default (0/100 %) — účetní MUSÍ koeficient explicitně nastavit
            // (nebo se převezme z vypořádaného předchozího roku, § 76 odst. 6).
            $provisionalPercent = $this->coefficients->resolveProvisionalPercent($supplierId, $year);
            if ($provisionalPercent === null) {
                throw new PostingException(
                    'vat_coefficient_missing',
                    "Za rok {$year} jsou přijaté doklady s kráceným nárokem na odpočet dle § 76, "
                        . 'ale není nastaven zálohový koeficient. Zadej koeficient (%) pro daný rok '
                        . '— jinak nelze krácený odpočet (ř. 52) vyčíslit.',
                    422,
                );
            }
        }

        // ř.52 (odp_uprav_kf) = ROUND(ř.46 „Krácený odpočet" × koeficient/100). Uplatní se
        // KAŽDÉ zdaňovací období roku (§ 76 odst. 1). Koeficient (koef_p20_nov, levý sloupec
        // ř.52) proto vykazujeme spolu s ním v každém období — jinak by nešlo částku ověřit
        // (XSD anotace odp_uprav_kf: „součin ř.46 pravý sloupec a koeficientu z ř.52").
        if ($totalDanOdpKraceny > 0.0) {
            $oduprav = (float) round($totalDanOdpKraceny * $provisionalPercent / 100);
            $veta4Raw['odp_sum_kr']   = $totalDanOdpKraceny;
            $veta5Raw['koef_p20_nov'] = (float) $provisionalPercent;
            $veta5Raw['odp_uprav_kf'] = $oduprav;
        }

        // ř.53 roční vypořádání (§ 76 odst. 7) — jen v posledním zdaňovacím období roku.
        // vypor_odp = ROUND(Σ krácený odpočet za ROK × vypořádací koef/100)
        //             − Σ uplatněných ř.52 (odp_uprav_kf) za všechna období roku.
        // Vypořádací koeficient (koef_p20_vypor) ze skutečných dat celého roku (viz
        // computeAnnualCoefficient). Hodnota může být kladná i záporná.
        if ($annualCoef !== null && $annualCoef['kr_year'] > 0.0) {
            $annualAtFinal = (float) round($annualCoef['kr_year'] * $annualCoef['final_percent'] / 100);
            $appliedSum    = $this->sumAppliedReducedDeduction($supplierId, $year, $quarter !== null, (int) $provisionalPercent);
            $vyporOdp      = $annualAtFinal - $appliedSum;
            $veta5Raw['koef_p20_vypor'] = (float) $annualCoef['final_percent'];
            $veta5Raw['vypor_odp']      = $vyporOdp;
        }

        // ř.60 (uprav_odp) — úprava odpočtu u dlouhodobého majetku (§ 78 a násl.).
        // Uvádí se JEN v posledním zdaňovacím období kalendářního roku (XSD anotace);
        // jednorázové úpravy § 78d/78da/78e patří do období, kdy nastaly, a systém je
        // zatím neřeší (viz VatDeductionAdjustmentService). Hodnota může být záporná.
        $upravOdp = 0.0;
        if ($isLastPeriodOfYear) {
            $upravOdp = $this->deductionAdjustments->totalForReturn(
                $supplierId,
                $year,
                $annualCoef !== null ? (int) $annualCoef['final_percent'] : null,
            );
            // POZOR: `uprav_odp` je atribut Veta6 (rekapitulace), NE Veta5. Na Veta5
            // ho XSD odmítne — ověřeno validací, ne odhadem.
        }

        // ř.45 (odp_rez_nar) — korekce odpočtu při registraci a zrušení registrace
        // (§ 79 / § 79a). Znaménko i období určuje anotace XSD: nárok při registraci
        // KLADNĚ v období, do něhož spadá den vzniku plátcovství, snížení při zrušení
        // ZÁPORNĚ v posledním období registrace. Období proto řídí `effective_on` položky,
        // ne datum pořízení majetku — tady stačí předat hranice období.
        //
        // Řádek se dřív negeneroval vůbec (viz komentář u lineMap): klasifikace na něj
        // mířit nemůže, protože nejde o vlastnost dokladu, ale o jednorázovou událost
        // registrace. Proto se plní z vlastní evidence, ne z ledgeru dokladů.
        $registrationCorrection = $this->section79->totalForReturn($supplierId, $vatStart, $vatEnd);
        if ($registrationCorrection !== 0.0) {
            $veta4Raw['odp_rez_nar'] = $registrationCorrection;
            // ř.46 je součet ř.40–45 „V plné výši", takže korekce do něj patří — jinak by
            // rekapitulace neseděla na detail a EPO by hlásilo nekonzistenci.
            $totalDanOdpNar += $registrationCorrection;
        }

        // ř.63 (odp_zocelk) = ř.46 „V plné výši" + ř.52 „Odpočet" + ř.53 „Změna odpočtu"
        // + ř.60 „Úprava odpočtu" (XSD anotace odp_zocelk). NENÍ to prostý součet všech
        // řádků odpočtu — krácená daň se do celku promítá jen přes ř.52/53, ne nekráceně.
        $totalDanOdpocitatelne = $totalDanOdpNar + $oduprav + $vyporOdp + $upravOdp;

        if ($veta4Raw !== []) {
            // ř.46 (odp_sum_nar) = součet ř.40-45 „V plné výši" (bez krácených řádků). Bez
            // něj EPO hlásí propustnou chybu „Odpočet daně celkem nevyplněn".
            $veta4Raw['odp_sum_nar'] = $totalDanOdpNar;
        }

        $vlastniDan = $totalDanZdanitelne - $totalDanOdpocitatelne;

        // ── Dodatečné přiznání (§ 141 DŘ): pouze ROZDÍLY proti poslední známé dani ──
        // XSD anotace dapdph_forma: „V dodatečném přiznání se uvádí pouze rozdíly od údajů,
        // ze kterých byla stanovena poslední známá daň." Základnou je POSLEDNÍ archivované
        // řádné/opravné (B/O) přiznání téhož období — parsujeme jeho SKUTEČNĚ podané XML,
        // ať se diff počítá proti tomu, co bylo odesláno, ne proti čerstvému přepočtu.
        $baseline = null;
        $referenceSubmissionId = null;
        if ($isAmendment) {
            $baseline = $this->loadAmendmentBaseline($supplierId, $year, $month, $quarter, $forma);
            $referenceSubmissionId = (int) $baseline['submission_id'];
            $pairs = self::attributePairs($lineMap);
            $veta1Raw = $this->diffElement($veta1Raw, $baseline['veta']['Veta1'] ?? [], $pairs);
            $veta2Raw = $this->diffElement($veta2Raw, $baseline['veta']['Veta2'] ?? [], $pairs);
            $veta3Raw = $this->diffElement($veta3Raw, $baseline['veta']['Veta3'] ?? [], $pairs);
            $veta4Raw = $this->diffElement($veta4Raw, $baseline['veta']['Veta4'] ?? [], $pairs);
            $veta5Raw = $this->diffElement($veta5Raw, $baseline['veta']['Veta5'] ?? [], $pairs);
        }

        // § 76 odst. 6/9: opravuje-li se rok, který už byl VYPOŘÁDÁN, patří do ř.52
        // vypořádací (ne zálohový) koeficient. Builder umí jen zálohový koeficient roku
        // období, takže se nedopočítá sám — ale mlčet o tom nelze, výsledek ř.52 by byl
        // jiný, než formulář požaduje. Varování jen tehdy, když se krácený odpočet
        // dodatečného vůbec dotkl.
        if ($isAmendment && ($totalDanOdpKraceny > 0.0 || isset($veta5Raw['odp_uprav_kf']))) {
            $coefRow = $this->coefficients->get($supplierId, $year);
            $settledPercent = ($coefRow !== null && $coefRow['settled_at'] !== null)
                ? $coefRow['final_percent']
                : null;
            if ($settledPercent !== null && (int) $settledPercent !== (int) $provisionalPercent) {
                $warnings[] = sprintf(
                    'Dodatečné přiznání za rok %d se dotýká kráceného odpočtu (§ 76), ale rok už '
                        . 'byl vypořádán koeficientem %d %%. Do ř. 52 patří podle § 76 odst. 6 '
                        . 'vypořádací koeficient, aplikace počítá se zálohovým (%s %%) — ř. 52 '
                        . 'ověřte a případně upravte ručně.',
                    $year,
                    (int) $settledPercent,
                    $provisionalPercent === null ? '—' : (string) (int) $provisionalPercent,
                );
            }
        }

        // ── Emit Veta1-5 (formátování na celé Kč / % až tady) ───────────
        $emit = function (string $name, array $raw) use ($dom, $dphdp3): void {
            if ($raw === []) return;
            $el = $dom->createElement($name);
            foreach ($raw as $k => $v) $el->setAttribute($k, $this->formatAmount((float) $v));
            $dphdp3->appendChild($el);
        };
        // XSD pořadí Veta1 → Veta2 → Veta3 → Veta4 → Veta5 → Veta6.
        $emit('Veta1', $veta1Raw);
        $emit('Veta2', $veta2Raw);
        $emit('Veta3', $veta3Raw);
        $emit('Veta4', $veta4Raw);
        $emit('Veta5', $veta5Raw);

        // ── Veta6: rekapitulace (XSD pořadí Veta4 → Veta6 → VetaR) ───────
        // ř.62 dan_zocelk = daň na výstupu celkem, ř.63 odp_zocelk = odpočet celkem,
        // ř.64 dano_da = vlastní daň (jen když výstup > odpočet),
        // ř.65 dano_no = nadměrný odpočet (kladné číslo, jen když odpočet > výstup),
        // ř.66 dano = rozdíl proti poslední známé dani (JEN dodatečné, kladná i záporná).
        $lastKnownTax  = null;
        $taxDifference = null;
        $veta6 = $dom->createElement('Veta6');
        if ($isAmendment) {
            // Dodatečné: ř.62/63 nesou ROZDÍL sum, dano_da/dano_no se NEvyplňují (XSD anotace),
            // ř.66 dano = Δř.62 − Δř.63.
            //
            // Rozdíl se počítá ze ZAOKROUHLENÝCH složek, ne jako round(vlastní daň) − poslední
            // známá: round(A−B) ≠ round(A)−round(B), jakmile některá složka není celé číslo
            // (do odpočtu vstupují `vypor_odp` i `oduprav` jako float). Rekapitulace pak
            // neseděla sama se sebou — ř.66 se o korunu rozešlo s ř.62 − ř.63.
            $base6 = $baseline['veta']['Veta6'] ?? [];
            $baseVlastni = round((float) ($base6['dan_zocelk'] ?? 0.0)) - round((float) ($base6['odp_zocelk'] ?? 0.0));
            $deltaOutput = round($totalDanZdanitelne) - round((float) ($base6['dan_zocelk'] ?? 0.0));
            $deltaInput  = round($totalDanOdpocitatelne) - round((float) ($base6['odp_zocelk'] ?? 0.0));
            $lastKnownTax  = $baseVlastni;
            $taxDifference = $deltaOutput - $deltaInput;
            // ř.60 úprava odpočtu (§ 78) se i v dodatečném vykazuje — ROZDÍLEM. Bez ní
            // nesedí kontrola ř.63 = ř.46 + 52 + 53 + 60 a EPO hlásí nekonzistenci
            // (delta ř.63 úpravu obsahuje, ale ř.60 by zůstal prázdný).
            $upravOdpDelta = round($upravOdp) - round((float) ($base6['uprav_odp'] ?? 0.0));
            if ($upravOdpDelta !== 0.0) {
                $veta6->setAttribute('uprav_odp', $this->formatAmount($upravOdpDelta));
            }
            // § 141 DŘ: dodatečné přiznání se podává na ZMĚNU údajů. Když se nezměnilo nic —
            // žádný rozdíl v Veta1-5 ani v rekapitulaci — vzniklo by prázdné podání, které
            // XSD propustí (Veta1-6 mají minOccurs=0) a správce daně nemá jak zpracovat.
            // Tvrdá brzda: prázdné dodatečné se nesmí dostat ven ani ke stažení.
            $hasDetailDiff = $veta1Raw !== [] || $veta2Raw !== [] || $veta3Raw !== []
                || $veta4Raw !== [] || $veta5Raw !== [];
            if (!$hasDetailDiff && $deltaOutput === 0.0 && $deltaInput === 0.0 && $upravOdpDelta === 0.0) {
                throw new PostingException(
                    'vat_amendment_no_change',
                    'Dodatečné přiznání nemá co vykázat — proti poslední známé dani se nezměnil '
                        . 'žádný údaj. Podle § 141 daňového řádu se dodatečné přiznání podává jen '
                        . 'na změnu údajů; nejdřív opravte doklady daného období.',
                    422,
                );
            }
            $veta6->setAttribute('dan_zocelk', $this->formatAmount((float) $deltaOutput));
            $veta6->setAttribute('odp_zocelk', $this->formatAmount((float) $deltaInput));
            $veta6->setAttribute('dano', $this->formatAmount((float) $taxDifference));
        } else {
            $veta6->setAttribute('dan_zocelk', $this->formatAmount($totalDanZdanitelne));
            $veta6->setAttribute('odp_zocelk', $this->formatAmount($totalDanOdpocitatelne));
            // ř.60 úprava odpočtu (§ 78 a násl.) — jen v posledním období roku a jen
            // když je nenulová.
            if ($upravOdp !== 0.0) {
                $veta6->setAttribute('uprav_odp', $this->formatAmount($upravOdp));
            }
            // Stejný důvod jako u dodatečného: ř.64/65 musí sedět na ř.62 − ř.63 přesně
            // tak, jak jsou v XML zaokrouhlené, ne na nezaokrouhlený mezivýpočet.
            $vlastniDanRounded = round($totalDanZdanitelne) - round($totalDanOdpocitatelne);
            if ($vlastniDanRounded > 0) {
                $veta6->setAttribute('dano_da', $this->formatAmount($vlastniDanRounded));
            } elseif ($vlastniDanRounded < 0) {
                $veta6->setAttribute('dano_no', $this->formatAmount(-$vlastniDanRounded));
            }
        }
        $dphdp3->appendChild($veta6);

        // ── VetaR: textová příloha ────────────────────────────────────────
        // U dodatečného přiznání sem patří DŮVODY pro jeho podání (§ 141 odst. 5 DŘ):
        // kod_sekce='D', jeden řádek textu = jedna věta, max. 72 znaků (XSD t_prilohy).
        // Bez nich EPO hlásí „Přiznání je označeno jako dodatečné a nejsou vyplněny
        // důvody pro dodatečné podání" (ověřeno zkušebním předáním).
        if ($isAmendment) {
            $reasonText = trim((string) $reason);
            if ($reasonText === '') {
                // Neutrální, věcně pravdivý text — lepší než prázdná příloha, ale vlastní
                // formulace účetní je vždycky lepší, proto zároveň varujeme.
                $reasonText = sprintf(
                    'Oprava údajů zjištěná dne %s; rozdíl proti poslední známé dani %s Kč.',
                    (new \DateTimeImmutable((string) $dZjist))->format('d.m.Y'),
                    $this->formatAmount((float) $taxDifference),
                );
                $warnings[] = 'Dodatečné přiznání nemá vyplněné důvody podání (§ 141 odst. 5 DŘ) '
                    . '— doplnil se obecný text. Popište důvod vlastními slovy, správce daně '
                    . 'obecnou formulaci může rozporovat.';
            }
            $poradi = 0;
            foreach ($this->wrapAttachmentLines($reasonText) as $line) {
                $poradi++;
                $row = $dom->createElement('VetaR');
                $row->setAttribute('poradi', (string) $poradi);
                $row->setAttribute('kod_sekce', 'D');
                $row->setAttribute('t_prilohy', $line);
                $dphdp3->appendChild($row);
            }
        } else {
            $vetaR = $dom->createElement('VetaR');
            $vetaR->setAttribute('poradi', '1');
            $dphdp3->appendChild($vetaR);
        }

        // ⚠️ `trans` NENÍ znaménko daně, ale zaškrtávátko „Neexistují-li údaje
        // pro C. oddíl". EPO podle něj oddíl C buď vykreslí, nebo PŘEŠKRTNE —
        // a přeškrtnutý oddíl obsahovou kontrolou neprojde:
        //
        //   CHYBA 36 — JE ZAŠKRTNUTO, ŽE NEEXISTUJÍ ÚDAJE PRO C. ODDÍL,
        //   NESMÍ BÝT VYPLNĚNY ÚDAJE V ODDÍLE C.
        //
        // Dokud se odvozovalo jen ze znaménka vlastní daně, dostalo přiznání
        // složené výhradně ze samovyměření (daň na výstupu i zrcadlový odpočet
        // ve stejné výši, ř. 64 = 0) hodnotu `N` — a bylo bez ručního zásahu
        // nepodatelné, přestože Veta1 i Veta4 byly vyplněné správně. Totéž
        // hrozilo u nadměrného odpočtu.
        //
        // Proto: máme-li v oddílu C cokoliv vyplněné, `trans` je `A`. `N`
        // zůstává pro období, ve kterém se opravdu nic nestalo. Znaménko daně
        // (u dodatečného rozdíl proti poslední známé) hodnotu už jen posiluje,
        // nikdy ji nesnižuje.
        $sectionCHasData = $veta1Raw !== [] || $veta2Raw !== [] || $veta3Raw !== []
            || $veta4Raw !== [] || $veta5Raw !== [];
        $transBasis = $isAmendment ? (float) $taxDifference : $vlastniDan;
        $vetaD->setAttribute('trans', ($transBasis > 0 || $sectionCHasData) ? 'A' : 'N');

        // Termín podání: 25. den následujícího měsíce po skončení období
        $deadlineMonth = $quarter !== null ? ($quarter * 3 + 1) : ($month + 1);
        $deadlineYear  = $year;
        if ($deadlineMonth > 12) {
            $deadlineMonth -= 12;
            $deadlineYear += 1;
        }
        // § 33/4 DŘ: termín padající na víkend/svátek se posouvá na další pracovní den.
        $deadline = CzechWorkingDays::deadline($deadlineYear, $deadlineMonth);

        // Dodatečné přiznání má vlastní lhůtu: do konce měsíce NÁSLEDUJÍCÍHO po měsíci
        // zjištění (§ 141 odst. 1 DŘ), ne 25. den po skončení zdaňovacího období. Ta by
        // u dodatečného byla dávno v minulosti a UI ji ukazovalo jako „po termínu".
        if ($isAmendment && $dZjist !== null) {
            $deadline = CzechWorkingDays::shiftToWorkingDay(
                (new \DateTimeImmutable($dZjist))->modify('last day of next month')
            )->format('Y-m-d');
        }

        $summary = [
            'period'                  => sprintf('%04d-%02d', $year, $month),
            'period_type'             => $period,
            'typ_platce'              => $isIdentified ? 'I' : 'P',
            'quarter'                 => $quarter,
            'lines'                   => $lines,
            'total_vat_output'        => round($totalDanZdanitelne, 2),
            'total_vat_input'         => round($totalDanOdpocitatelne, 2),
            'tax_due'                 => round($vlastniDan, 2),
            'is_excess_deduction'     => $vlastniDan < 0,
            'submission_deadline'     => $deadline,
            // C7' — typ podání (řádné/opravné/dodatečné) + podklady dodatečného přiznání.
            'variant'                 => $variant,
            'dapdph_forma'            => $forma,
            'is_amendment'            => $isAmendment,
            'd_zjist'                 => $dZjist,
            'last_known_tax'          => $lastKnownTax,
            'tax_difference'          => $taxDifference,
            'reference_submission_id' => $referenceSubmissionId,
            'supplier_vat_period'     => (string) ($supplier['vat_period'] ?? ''),
            // Snapshot dokladů zahrnutých do podání. Fronta změn ho používá i po stornu
            // nebo přesunu DUZP, kdy doklad z aktuální projekce období úplně zmizí.
            'document_refs'           => $this->documentRefs($supplierId, $vatRows),
            // § 76 krácený nárok na odpočet — informativně pro UI/summary.
            'vat_reduced_deduction'   => round($totalDanOdpKraceny, 2),   // odp_sum_kr (ř.46 krácený)
            'vat_coefficient_percent' => $provisionalPercent,             // zálohový koeficient (ř.52)
            'vat_reduced_applied'     => round($oduprav, 2),              // odp_uprav_kf (ř.52 odpočet)
            'vat_settlement'          => ($annualCoef !== null && $annualCoef['kr_year'] > 0.0)
                ? [
                    'final_percent' => $annualCoef['final_percent'],
                    'numerator'     => $annualCoef['numerator'],
                    'denominator'   => $annualCoef['denominator'],
                    'vypor_odp'     => round($vyporOdp, 2),
                ]
                : null,
        ];

        return [
            'xml'      => $dom->saveXML() ?: '',
            'summary'  => $summary,
            'warnings' => $warnings,
            'missing_rates' => $missingRates,
        ];
    }

    /**
     * @param list<string> $warnings
     */
    private function appendSalesDataWarnings(int $supplierId, int $year, int $month, string $period, array &$warnings): void
    {
        if ($period === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $startMonth = ($quarter - 1) * 3 + 1;
            $endMonth = $quarter * 3;
        } else {
            $startMonth = $endMonth = $month;
        }
        $start = sprintf('%04d-%02d-01', $year, $startMonth);
        $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endMonth)))
            ->modify('last day of this month')->format('Y-m-d');

        // Čistě OSS dobropis nesnižuje tuzemskou daň na výstupu (jeho záporná DPH je zahraniční),
        // takže by § 42 varování jen mátlo. Vyžadujeme aspoň jeden ne-OSS řádek.
        $creditNoteOssFilter = $this->db->hasColumn('invoice_items', 'oss_applicable')
            ? "AND EXISTS (SELECT 1 FROM invoice_items cii
                            WHERE cii.invoice_id = invoices.id
                              AND COALESCE(cii.oss_applicable, 0) = 0)"
            : '';
        $creditNotes = $this->db->pdo()->prepare(
            "SELECT varsymbol
               FROM invoices
              WHERE supplier_id = ?
                AND status NOT IN ('draft', 'cancelled')
                AND invoice_type = 'credit_note'
                AND (total_without_vat < 0 OR total_vat < 0)
                AND COALESCE(tax_date, issue_date) BETWEEN ? AND ?
                {$creditNoteOssFilter}
           ORDER BY COALESCE(tax_date, issue_date), id"
        );
        $creditNotes->execute([$supplierId, $start, $end]);
        foreach ($creditNotes->fetchAll(\PDO::FETCH_COLUMN) as $number) {
            $warnings[] = "Dobropis {$number} snižuje daň na výstupu. Ověřte, že datum zařazení odpovídá doručení opravného daňového dokladu nebo vynaložení rozumného úsilí o jeho doručení (§ 42 ZDPH).";
        }

        $ossFilter = $this->db->hasColumn('invoice_items', 'oss_applicable')
            ? 'AND COALESCE(ii.oss_applicable, 0) = 0'
            : '';
        $unclassifiedZero = $this->db->pdo()->prepare(
            "SELECT DISTINCT i.varsymbol
               FROM invoices i
               JOIN invoice_items ii ON ii.invoice_id = i.id
              WHERE i.supplier_id = ?
                AND i.status NOT IN ('draft', 'cancelled')
                AND i.invoice_type <> 'proforma'
                AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?
                AND COALESCE(i.reverse_charge, 0) = 0
                AND ii.vat_rate_snapshot = 0
                {$ossFilter}
                AND ii.vat_classification_code IS NULL
                AND i.vat_classification_code IS NULL
           ORDER BY i.varsymbol"
        );
        $unclassifiedZero->execute([$supplierId, $start, $end]);
        foreach ($unclassifiedZero->fetchAll(\PDO::FETCH_COLUMN) as $number) {
            $warnings[] = "Doklad {$number} obsahuje neklasifikovaný řádek se sazbou 0 %. Řádek nebyl zahrnut na ř. 50; zvolte výslovnou klasifikaci DPH.";
        }

        // Řádky s povoleným „nevím" (mimo OSS, ale označené k ručnímu posouzení). Do
        // přiznání vstupují jako tuzemské plnění — což je zatím jediná možnost, protože
        // OSS řádkem prokazatelně nejsou. Varování je poslední brána před odesláním na
        // EPO: systém sám ví, že si jimi není jistý, a nesmí to zamlčet. Definici „co je
        // nejistý řádek" vlastní VatLedgerService, aby ji filtr v seznamu faktur
        // (`filter[oss_review]`) a tohle varování nemohly vidět jinak.
        $uncertain = $this->ledger->uncertainOssDocuments($supplierId, $start, $end);
        foreach ($uncertain as $doc) {
            $number = $doc['doc_number'] ?? ('#' . $doc['invoice_id']);
            $warnings[] = sprintf(
                'Doklad %s obsahuje %d řádk%s, u kterých se nepodařilo určit místo plnění (OSS). '
                    . 'Vstupují do tuzemského přiznání na ř. 1/2 — ověřte, jestli tam patří. '
                    . 'Najdete je v seznamu faktur pod filtrem „Nejisté místo plnění (OSS)".',
                $number,
                $doc['items'],
                $doc['items'] === 1 ? '' : ($doc['items'] < 5 ? 'y' : 'ů'),
            );
        }
    }

    /**
     * Řádky povolené identifikované osobě (§ 6g–6l, issue #94): jen samovyměření
     * z přeshraničních přijatých plnění. Cokoli jiného (tuzemské výstupy ř. 1/2,
     * oddíl C ř. 20-31, odpočty ř. 40+ vč. zrcadlového ř. 43 z RC mirroru) IO
     * nevyplňuje — vyhazujeme s warningem, ať uživatel ví, co a proč vypadlo.
     *
     * Vyloučené řádky se vznikem povinnosti, které IO věcně nemá:
     *   ř. 7/8 (dovoz zboží — DPH u neplátce vybírá celní úřad),
     *   ř. 10/11 (tuzemský RC § 92a — jen mezi plátci).
     *
     * @param array<string, array{base:float, vat:float, count:int, label:string}> $lines
     * @param list<string> $warnings by-ref
     * @return array<string, array{base:float, vat:float, count:int, label:string}>
     */
    private function filterLinesForIdentified(array $lines, array &$warnings): array
    {
        $allowed = ['3', '4', '5', '6', '12', '13'];
        // Zrcadlový odpočet ř. 43 (dphdp3_line_secondary klasifikací 23/24/25)
        // a navázaný doplňující ř. 47 vznikají u IO automaticky z klasifikace —
        // jejich vyřazení JE pointa režimu (IO nemá nárok na odpočet), žádný warning.
        $silentDrop = ['43', '47'];
        $kept = [];
        foreach ($lines as $line => $data) {
            $key = (string) $line;
            if (in_array($key, $allowed, true)) {
                $kept[$line] = $data;
                continue;
            }
            if (in_array($key, $silentDrop, true)) {
                continue;
            }
            $warnings[] = sprintf(
                'Řádek %s (%s, základ %s Kč) identifikovaná osoba nevyplňuje — vynechán. Zkontroluj klasifikaci dokladů.',
                $key,
                $data['label'],
                number_format($data['base'], 0, ',', ' '),
            );
        }
        return $kept;
    }

    /**
     * Roční vypořádací koeficient § 76 odst. 7 (bez zálohové části) + roční krácený
     * odpočet. Vrací procento zaokrouhlené NAHORU na celé % (§ 76 odst. 5; ≥ 95 % → 100 %),
     * čitatel/jmenovatel (celé Kč, audit stopa) a roční daň krácených řádků (Σ ř.40k/41k/42k).
     *
     * Čitatel   = ř.1,2 (základ) − ř.14(=0, neimpl.) + ř.20-26 (hodnota) + ř.31 − ř.51.
     * Jmenovatel = čitatel + ř.50 − ř.51 bez nároku.
     * Vše z údajů za CELÝ vypořádávaný rok (1.1.–31.12.).
     *
     * Public — sdílí ho i explicitní vypořádání ({@see \MyInvoice\Action\Report\VatCoefficientAction}).
     *
     * @return array{final_percent:int, numerator:int, denominator:int, kr_year:float}
     */
    public function computeAnnualCoefficient(int $supplierId, int $year): array
    {
        $fullThreshold = (int) ($this->constants->forYear($year)['vat_coefficient_full_threshold_pct'] ?? 95);
        $yl = $this->mapper->aggregateForYear($supplierId, $year);
        $base = static fn (string $l): float => (float) ($yl[$l]['base'] ?? 0.0);

        $citatel = $base('1') + $base('2')
            + $base('20') + $base('21') + $base('22') + $base('23') + $base('24') + $base('25') + $base('26')
            + $base('31') - $base('51');
        $jmenovatel = $citatel + $base('50') - $base('51b');

        if ($jmenovatel <= 0.0) {
            $final = 100; // žádná osvobozená plnění bez nároku (ř.50 = 0) → plný nárok
        } else {
            $final = (int) ceil($citatel / $jmenovatel * 100.0); // § 76 odst. 5: nahoru na celé %
            if ($final >= $fullThreshold) $final = 100;
            if ($final < 0)   $final = 0;
        }

        // Zaokrouhlení PER ŘÁDEK (round(40k)+round(41k)+round(42k)), shodně s build()
        // ř.46 „Krácený odpočet" (odp_sum_kr). Sum-then-round by se v obdobích s víc krácenými
        // sazbovými buckety (40k i 41k/42k) rozešlo o ≤ (počet bucketů−1) Kč se skutečně
        // podaným ř.46/52 → identita „Σ podaných ř.52 + vypor_odp = roční nárok" by neplatila.
        $krYear = round((float) ($yl['40k']['vat'] ?? 0.0))
            + round((float) ($yl['41k']['vat'] ?? 0.0))
            + round((float) ($yl['42k']['vat'] ?? 0.0));

        return [
            'final_percent' => $final,
            'numerator'     => (int) round($citatel),
            'denominator'   => (int) round($jmenovatel),
            'kr_year'       => $krYear,
        ];
    }

    /**
     * @return list<array{source:string, invoice_id:int, document_kind:?string, status:string,
     *                    tax_date:?string, updated_at:?string, total:float}>
     */
    private function documentRefs(int $supplierId, array $rows): array
    {
        $refs = [];
        foreach ($rows as $row) {
            if (($row['code'] ?? null) === null || ($row['dphdp3_line'] ?? null) === null) {
                continue;
            }
            $source = ($row['document_kind'] ?? null) === 'cash' ? 'cash' : (string) $row['source'];
            $key = $source . ':' . (int) $row['invoice_id'];
            $refs[$key] = [
                'source'        => $source,
                'invoice_id'    => (int) $row['invoice_id'],
                'document_kind' => $row['document_kind'] !== null ? (string) $row['document_kind'] : null,
                'status'        => (string) $row['status'],
                'tax_date'      => $row['tax_date'] !== null ? (string) $row['tax_date'] : null,
                'updated_at'    => null,
                'total'         => (float) $row['document_total'],
            ];
        }
        foreach (['sale' => 'invoices', 'purchase' => 'purchase_invoices', 'cash' => 'cash_documents'] as $source => $table) {
            $keys = array_keys(array_filter($refs, static fn (array $ref): bool => $ref['source'] === $source));
            if ($keys === []) {
                continue;
            }
            $ids = array_map(static fn (string $key): int => (int) substr($key, strpos($key, ':') + 1), $keys);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT id, updated_at FROM {$table} WHERE supplier_id = ? AND id IN ({$ph})"
            );
            $stmt->execute([$supplierId, ...$ids]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $key = $source . ':' . (int) $row['id'];
                $refs[$key]['updated_at'] = $row['updated_at'] !== null ? (string) $row['updated_at'] : null;
            }
        }
        ksort($refs);
        return array_values($refs);
    }

    /** @return array{0:string,1:string} */
    private function periodRange(int $year, int $month, string $period): array
    {
        if ($period === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $startMonth = ($quarter - 1) * 3 + 1;
            $endMonth = $quarter * 3;
        } else {
            $startMonth = $month;
            $endMonth = $month;
        }
        $start = sprintf('%04d-%02d-01', $year, $startMonth);
        $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $endMonth)))
            ->modify('last day of this month')->format('Y-m-d');
        return [$start, $end];
    }

    /**
     * Σ uplatněných krácených odpočtů (ř.52, odp_uprav_kf) za všechna zdaňovací období roku
     * — menšenec vypořádání (§ 76 odst. 7). Každé období zvlášť: ROUND(Σ ř.40k/41k/42k daň ×
     * zálohový koeficient/100), sečteno po obdobích (stejné zaokrouhlení jako reálně uplatněná
     * ř.52). Zálohový koeficient je per rok konstantní.
     */
    private function sumAppliedReducedDeduction(int $supplierId, int $year, bool $quarterly, int $provisionalPercent): float
    {
        $sum = 0.0;
        $periods = $quarterly ? [3, 6, 9, 12] : [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
        foreach ($periods as $m) {
            $lines = $this->mapper->aggregateForDphPriznani($supplierId, $year, $m, $quarterly ? 'quarterly' : 'monthly');
            // Per-line round, shodně s build() ř.46 (odp_sum_kr) — viz computeAnnualCoefficient.
            $kr = round((float) ($lines['40k']['vat'] ?? 0.0))
                + round((float) ($lines['41k']['vat'] ?? 0.0))
                + round((float) ($lines['42k']['vat'] ?? 0.0));
            $sum += round($kr * $provisionalPercent / 100);
        }
        return $sum;
    }

    /**
     * Načti tax-relevantní info o tenantovi. S $statusDate (poslední den období
     * výkazu) je is_vat_payer stavem k tomuto datu, ne živou cache dneška.
     * @return array<string,mixed>
     */
    private function loadSupplier(int $supplierId, ?string $statusDate = null): array
    {
        return EpoSupplierBlockBuilder::loadSupplier($this->db->pdo(), $supplierId, $statusDate);
    }

    // VetaP a normalizeOkec přesunuto do EpoSupplierBlockBuilder (sdíleno s KH/SHV),
    // obálka Pisemnost a čtení VERSION do EpoEnvelope (sdíleno se všemi podáními).

    /**
     * Output lines (DPH na výstupu): 1-29 dle DPHDP3.
     * Input lines (DPH na vstupu, odpočet): 40+ dle DPHDP3.
     */
    private function isOutputLine(string $line): bool
    {
        return (int) $line < 40;
    }

    /**
     * Přimíchá EVIDOVANÉ korekce §74b (dlužník) do projekce řádků DPHDP3 — ř. 40/41 (základ
     * i daň) a informativní ř. 34 opr_dluz. Merge do TÉHOŽ agregátu jako běžný odpočet, aby
     * sdílené sčítání i rekapitulace (round per řádek → ř. 46 = Σ ř. 40-45) zůstaly konzistentní.
     * Znaménka určuje {@see Section74bService::periodCorrectionLines()} (snížení záporně na
     * ř. 40/41 a kladně na ř. 34; obnova opačně). Přičítáme jen nenulové příspěvky.
     *
     * @param array<string, array{base:float, vat:float, count:int, label:string}> $lines by-ref
     */
    private function applySection74bCorrections(array &$lines, int $supplierId, int $year, int $month, string $period): void
    {
        $s74b = $this->section74b->periodCorrectionLines($supplierId, $year, $month, $period);
        $add = static function (array &$lines, string $line, float $base, float $vat, string $label): void {
            if (round($base, 2) == 0.0 && round($vat, 2) == 0.0) {
                return;
            }
            if (!isset($lines[$line])) {
                $lines[$line] = ['base' => 0.0, 'vat' => 0.0, 'count' => 0, 'label' => $label];
            }
            $lines[$line]['base'] += $base;
            $lines[$line]['vat']  += $vat;
        };
        $add($lines, '40', $s74b['basic']['base'],   $s74b['basic']['vat'],   'Oprava odpočtu §74b (21 %)');
        $add($lines, '41', $s74b['reduced']['base'], $s74b['reduced']['vat'], 'Oprava odpočtu §74b (12 %)');
        // ř. 34 opr_dluz — informativní DPH (base slot, vat=0 → mimo rekapitulaci ř. 62/63).
        $add($lines, '34', $s74b['opr_dluz'], 0.0, 'Oprava odpočtu dlužníka §74b (ř. 34)');
    }

    /**
     * Přimíchá EVIDOVANÉ opravy § 46 (věřitel, nedobytná pohledávka) do projekce řádků —
     * ř. 1/2 (základ i daň) a informativní ř. 33 opr_verit. Merge do TÉHOŽ agregátu jako
     * běžná uskutečněná plnění, aby sdílené sčítání i rekapitulace zůstaly konzistentní.
     * Znaménka určuje {@see Section46Service::periodCorrectionLines()}: oprava snižuje
     * ř. 1/2 a zvyšuje ř. 33, obnova po úhradě (§ 46e) opačně.
     *
     * @param array<string, array{base:float, vat:float, count:int, label:string}> $lines by-ref
     */
    private function applySection46Corrections(array &$lines, int $supplierId, int $year, int $month, string $period): void
    {
        $s46 = $this->section46->periodCorrectionLines($supplierId, $year, $month, $period);
        $this->addToLine($lines, '1', $s46['basic']['base'],   $s46['basic']['vat'],   'Oprava §46 nedobytná pohledávka (21 %)');
        $this->addToLine($lines, '2', $s46['reduced']['base'], $s46['reduced']['vat'], 'Oprava §46 nedobytná pohledávka (12 %)');
        // ř. 33 opr_verit — informativní DPH (base slot, vat=0 → mimo rekapitulaci ř. 62/63).
        $this->addToLine($lines, '33', $s46['opr_verit'], 0.0, 'Oprava věřitele §46 (ř. 33)');
    }

    /**
     * Přičte evidovanou opravu k řádku přiznání. Nulová oprava řádek NEZALOŽÍ — prázdný
     * řádek by ve výkazu budil dojem, že se něco opravovalo.
     *
     * Sdílí ho § 46 i § 43; obojí míří do týchž řádků 1/2 a musí se tam sčítat, ne
     * přepisovat (za jedno období můžou nastat obě opravy najednou).
     *
     * @param array<string, array{base:float, vat:float, count:int, label:string}> $lines by-ref
     */
    private function addToLine(array &$lines, string $line, float $base, float $vat, string $label): void
    {
        if (round($base, 2) == 0.0 && round($vat, 2) == 0.0) {
            return;
        }
        if (!isset($lines[$line])) {
            $lines[$line] = ['base' => 0.0, 'vat' => 0.0, 'count' => 0, 'label' => $label];
        }
        $lines[$line]['base'] += $base;
        $lines[$line]['vat']  += $vat;
    }

    /**
     * Formátování částky pro EPO XML — celé číslo Kč (zaokrouhleno).
     */
    private function formatAmount(float $amount): string
    {
        return (string) (int) round($amount);
    }

    /**
     * Rozdílový přepočet jednoho elementu (Veta1-5) pro dodatečné přiznání: delta = round(nová)
     * − round(základna z podaného XML) per atribut. Koeficient §76 (NON_DIFF_ATTRS) se nediffuje
     * (procento, ne částka) — přebírá se nová hodnota. Vypouští atributy s nulovým rozdílem
     * (§ 141 DŘ: „uvádí se pouze rozdíly"); koeficient se ponechá jen když v elementu zbyl
     * nějaký nenulový rozdíl (jinak by osiřel bez odpovídajícího odpočtu).
     *
     * ── Koeficient musí přežít i to, že krácený odpočet z období ZMIZEL ──────────────
     * Dřív se koeficient bral VÝHRADNĚ z nových hodnot. Jenže `koef_p20_nov` se plní jen
     * když `totalDanOdpKraceny > 0`: dodatečné, které jediný krácený doklad vyřadí, tak
     * vygenerovalo `odp_sum_kr="-10000"` a `odp_uprav_kf="-4000"` BEZ koeficientu, kterým
     * se ř.52 ověřuje. XSD to propustí (vše optional), EPO ne. Proto fallback na hodnotu
     * ze základny — procento popisuje období, ne rozdíl, takže převzít to, s čím se ř.52
     * původně počítal, je věcně správně.
     *
     * @param array<string,float> $new      nové (přepočtené) absolutní hodnoty
     * @param array<string,float> $baseline  hodnoty z posledního podaného přiznání
     * @return array<string,float>
     */
    private function diffElement(array $new, array $baseline, array $pairs = []): array
    {
        $out = [];
        $keys = array_unique(array_merge(array_keys($new), array_keys($baseline)));
        $coef = [];
        foreach ($keys as $k) {
            if (isset(self::NON_DIFF_ATTRS[$k])) {
                if (isset($new[$k])) {
                    $coef[$k] = (float) $new[$k];
                } elseif (isset($baseline[$k])) {
                    $coef[$k] = (float) $baseline[$k];
                }
                continue;
            }
            $delta = round((float) ($new[$k] ?? 0.0)) - round((float) ($baseline[$k] ?? 0.0));
            if ($delta !== 0.0) {
                $out[$k] = $delta;
            }
        }
        // Základ daně a daň tvoří na řádku DVOJICI: opravím-li sazbu, změní se daň, ale
        // základ zůstane stejný — a jeho nulový rozdíl by z výkazu vypadl. EPO na to hlásí
        // „Je zadána pouze jedna z hodnot základ daně/daň na ř. 01. Musí být zadány obě."
        // (ověřeno zkušebním předáním). Nulu proto u protějšku doplníme.
        // Protějšek se doplní jen tehdy, když na tomhle elementu VŮBEC existuje (v nových
        // datech nebo v základně). Bez té podmínky by u ř. 40, kde plný i krácený odpočet
        // sdílejí základ `pln23`, mohl vzniknout atribut, který v přiznání nemá co dělat.
        foreach ($out as $k => $_) {
            $partner = $pairs[$k] ?? null;
            if ($partner === null || array_key_exists($partner, $out)) {
                continue;
            }
            if (array_key_exists($partner, $new) || array_key_exists($partner, $baseline)) {
                $out[$partner] = 0.0;
            }
        }
        if ($out !== []) {
            foreach ($coef as $k => $v) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Ploché „atribut → jeho protějšek" z mapy řádků ($lineMap): základ ↔ daň.
     *
     * @param array<string,array{veta:int, base:string, vat:?string}> $lineMap
     * @return array<string,string>
     */
    private static function attributePairs(array $lineMap): array
    {
        $pairs = [];
        foreach ($lineMap as $line) {
            $base = $line['base'] ?? null;
            $vat  = $line['vat'] ?? null;
            if (is_string($base) && is_string($vat) && $base !== '' && $vat !== '') {
                $pairs[$base] = $vat;
                $pairs[$vat]  = $base;
            }
        }
        return $pairs;
    }

    /**
     * Základna dodatečného přiznání = „poslední známá daň" téhož období, rekonstruovaná jako
     * ABSOLUTNÍ hodnoty: poslední archivované ŘÁDNÉ/OPRAVNÉ (B/O) přiznání + Σ ROZDÍLŮ všech
     * následujících dodatečných (druh D). Diff nového přiznání se pak počítá proti tomuto
     * kumulativnímu stavu — nikoli proti původnímu řádnému (C7' HIGH fix: 2.+ dodatečné jinak
     * dvakrát vykáže rozdíl už podaný v předchozím dodatečném).
     *
     * Parsuje XML na mapu {Veta1..Veta6 => {atribut => částka}}. Bez B/O základny nelze diff
     * počítat → tvrdá chyba 'no_prior_submission_to_amend'.
     *
     * Opravné dodatečné (druh E) má NÁHRADOVOU (replacement) sémantiku — nahrazuje předchozí
     * dodatečné, nikoli kumuluje. Poslední známou daň nelze bezpečně určit prostým součtem delt,
     * proto (konzervativně, § 141 DŘ) tvrdá chyba místo tiché aproximace: buď se právě staví E,
     * nebo už E v řetězci existuje.
     *
     * @return array{submission_id:int, veta:array<string,array<string,float>>}
     */
    private function loadAmendmentBaseline(int $supplierId, int $year, int $month, ?int $quarter, string $forma): array
    {
        $prior = $this->submissions->findLatestForPeriod(
            $supplierId,
            'dphdp3',
            $year,
            $quarter !== null ? null : $month,
            $quarter,
            ['B', 'O'],
        );
        if ($prior === null || empty($prior['xml_content'])) {
            throw new PostingException(
                'no_prior_submission_to_amend',
                'Pro dané období neexistuje dřívější řádné/opravné přiznání — dodatečné přiznání '
                    . 'se počítá jako rozdíl proti poslední známé dani, kterou nelze bez základny určit.',
                422,
            );
        }

        $veta   = $this->parseBaselineXml((string) $prior['xml_content']);
        $baseId = (int) $prior['id'];

        // Řetězec předchozích dodatečných přiznání téhož období (chronologicky).
        $chain = $this->submissions->findAmendmentsForPeriod(
            $supplierId,
            'dphdp3',
            $year,
            $quarter !== null ? null : $month,
            $quarter,
        );
        $chainHasCorrection = false;
        foreach ($chain as $c) {
            if ((string) ($c['form_variant'] ?? '') === 'E') {
                $chainHasCorrection = true;
                break;
            }
        }
        if ($forma === 'E' || $chainHasCorrection) {
            throw new PostingException(
                'amendment_correction_unsupported',
                'Opravné dodatečné přiznání (druh E) zatím není podporováno — nahrazuje předchozí '
                    . 'dodatečné přiznání a poslední známou daň nelze bezpečně zrekonstruovat součtem '
                    . 'rozdílů. Sestavte jej ručně / s daňovým poradcem.',
                422,
            );
        }

        // 2.+ dodatečné: přičti ROZDÍLY všech předchozích dodatečných (D) k absolutní B/O základně.
        foreach ($chain as $c) {
            if (empty($c['xml_content'])) {
                throw new PostingException(
                    'amendment_baseline_incomplete',
                    'Archivované předchozí dodatečné přiznání nemá uložené XML — poslední známou daň '
                        . 'nelze zrekonstruovat.',
                    422,
                );
            }
            $veta   = $this->addBaselineDeltas($veta, $this->parseBaselineXml((string) $c['xml_content']));
            $baseId = (int) $c['id'];
        }

        return [
            'submission_id' => $baseId,
            'veta'          => $veta,
        ];
    }

    /**
     * Přičte ROZDÍLY jednoho dodatečného přiznání ({Veta => {attr => delta}}) k dosud
     * rekonstruovanému absolutnímu stavu. Koeficient §76 (NON_DIFF_ATTRS) se nesčítá
     * (procento, ne částka; v diffElement se navíc čte jen z nových hodnot, ne ze základny).
     *
     * @param array<string,array<string,float>> $base   dosud rekonstruovaný absolutní stav
     * @param array<string,array<string,float>> $delta  rozdíly z archivovaného dodatečného XML
     * @return array<string,array<string,float>>
     */
    private function addBaselineDeltas(array $base, array $delta): array
    {
        foreach ($delta as $vetaName => $attrs) {
            foreach ($attrs as $k => $v) {
                if (isset(self::NON_DIFF_ATTRS[$k])) {
                    continue;
                }
                $base[$vetaName][$k] = (float) ($base[$vetaName][$k] ?? 0.0) + (float) $v;
            }
        }
        return $base;
    }

    /**
     * Rozparsuje archivované DPHDP3 XML na {elementName => {atribut => částka}} pro Veta1-6.
     *
     * @return array<string,array<string,float>>
     */
    private function parseBaselineXml(string $xml): array
    {
        $out = [];
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml)) {
            return $out;
        }
        foreach (['Veta1', 'Veta2', 'Veta3', 'Veta4', 'Veta5', 'Veta6'] as $name) {
            $nodes = $dom->getElementsByTagName($name);
            if ($nodes->length === 0) {
                continue;
            }
            $el = $nodes->item(0);
            if (!$el instanceof \DOMElement) {
                continue;
            }
            $attrs = [];
            foreach ($el->attributes as $attr) {
                if (is_numeric($attr->nodeValue)) {
                    $attrs[$attr->nodeName] = (float) $attr->nodeValue;
                }
            }
            $out[$name] = $attrs;
        }
        return $out;
    }

    /**
     * Rozseká text přílohy na řádky po 72 znacích (XSD limit t_prilohy), pokud možno na
     * hranicích slov. Prázdné řádky se vynechávají.
     *
     * @return list<string>
     */
    private function wrapAttachmentLines(string $text): array
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
        if ($text === '') {
            return [];
        }
        $wrapped = wordwrap($text, 72, "\n", true);
        $lines = [];
        foreach (explode("\n", $wrapped) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = mb_substr($line, 0, 72);
            }
        }
        return $lines;
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
}
