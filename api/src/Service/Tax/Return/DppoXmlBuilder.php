<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Report\EpoEnvelope;
use MyInvoice\Service\Report\EpoSupplierBlockBuilder;
use MyInvoice\Service\Tax\TaxConstants;

/**
 * Generátor EPO XML formuláře DPPDP9 (daň z příjmů PO) — Epic DP (issue #18).
 *
 * Struktura dle `api/xsd/dppdp9_epo2.xsd` (bez namespace):
 *   Pisemnost(nazevSW,verzeSW) > DPPDP9(verzePis) > VetaD + VetaP + VetaO
 *
 * - VetaD: povinné atributy (fixní dokument="DP9", k_uladis="DPP", typ_dapdpp,
 *   dapdpp_forma, typ_popldpp, typ_zo, c_ufo_cil, zdobd_od/do ve formátu dd.mm.rrrr).
 * - VetaP: identifikace poplatníka (PO: zkrobchjm, rod_c=IČO, dic bez CZ, adresa).
 * - VetaO: řádky II. oddílu jako atributy `kc_ii{starý}_{aktuální}` (int Kč,
 *   totalDigits 14), sazba `kc_ii270_280` (celé %).
 *
 * Řádky mapuje z výstupu {@see DppoReturnCalculator}. Aditivní, upstream-safe.
 */
final class DppoXmlBuilder
{
    /** Verze formuláře (verzePis) — ověřit proti aktuálnímu EPO vzoru DPPDP9. */
    private const VERZE_PIS = '09.01';

    /**
     * Mapa čísla řádku → atribut VetaO (druhé číslo v kc_ii{X}_{Y} = aktuální řádek).
     * Public — sdíleno s {@see DppoEpoXmlParser} (Featura A: rekonciliace proti podanému
     * přiznání), aby reverse-mapa atribut→řádek nebyla duplikovaná a nerozjela se s exportem.
     */
    public const LINE_ATTR = [
        10 => 'kc_ii10_10',
        40 => 'kc_ii50_40',
        50 => 'kc_ii60_50',
        62 => 'kc_ii72_62',
        70 => 'kc_ii80_70',
        112 => 'kc_ii_112',
        150 => 'kc_ii170_150',
        162 => 'kc_ii182_162',
        170 => 'kc_ii190_170',
        200 => 'kc_ii200_200',
        230 => 'kc_ii210_230',
        // § 34 odst. 4 — odečty na podporu VaV (§ 34a–34e) a odborného vzdělávání
        // (§ 34f–34h). Atributy byly v XSD připravené, ale nemapované, takže odpočet
        // nešlo do přiznání vůbec dostat — a parser ho z podaného přiznání tiše odkládal
        // do `extra` bez diffu, takže se rozdíl v rekonciliaci ani neukázal.
        242 => 'kc_ii_242',
        243 => 'kc_ii_243',
        250 => 'kc_ii230_250',
        260 => 'kc_ii240_260',
        270 => 'kc_ii260_270',
        290 => 'kc_ii280_290',
        300 => 'kc_ii290_300',
        310 => 'kc_ii300_310',
        340 => 'kc_ii_340',
        360 => 'kc_ii_360',
    ];

    /** Řádky, které se vypíší vždy (i s 0); ostatní jen když ≠ 0. */
    private const ALWAYS = [10, 200, 250, 270, 290, 310, 340, 360];

    /**
     * Příloha účetní závěrky — mapa `row_code` (FinancialStatementService::balanceSheet,
     * section AKTIVA) → `c_radku` EPO tiskopisu, úroveň 1 (souhrnné řádky). Číselně
     * ověřeno proti dvěma reálně podaným přiznáním (private/APPENDIX-XML-MAPPING-SPEC.md
     * §1.1) i proti oficiálnímu číselníku MF ČR (daňový portál, idpr_pub/hlib/uv_info,
     * tabulka 23810 „Rozvaha", platnost=2026 — stejný zdroj, na který odkazuje
     * dokumentace atributu c_radku v dppdp9_epo2.xsd).
     */
    private const AKTIVA_C_RADKU = [
        ['AKTIVA', 1],
        ['B.', 3],
        ['C.', 37],
        ['D.', 74],
    ];

    /**
     * Úroveň 2 rozvahy-aktiv pod souhrnnými řádky B./C./D. — dřív záměrně chyběla
     * (komentář „Hlubší úrovně (level ≥ 2) NEJSOU ověřené"), zkušební EPO 31. 8. 2026 ji
     * vytklo jako křížovou kontrolu součtu („Hodnota řádku B. rozvahy-aktiv není rovna
     * součtu řádků aktiv B.I.+B.II.+B.III." a obdobně pro C./D.). Čísla řádků ze stejného
     * číselníku (tabulka 23810, platnost=2026) jako AKTIVA_C_RADKU výše — NE z pořadí v
     * `statement_rows` (to má mezery oproti oficiálnímu číselníku, např. C.II.1. má
     * v oficiálním číselníku 9 podpoložek, my mapujeme jen 3 — proto NELZE čísla řádků
     * odvodit aritmeticky z naší position, jen vyhledat v číselníku pro každý řádek zvlášť).
     * Klíč = row_code rodiče (musí být v AKTIVA_C_RADKU); hodnota = děti v pořadí, v jakém
     * je číselník vypisuje.
     */
    private const AKTIVA_DETAIL_C_RADKU = [
        'B.' => [['B.I.', 4], ['B.II.', 14], ['B.III.', 27]],
        'C.' => [['C.I.', 38], ['C.II.', 46], ['C.III.', 68], ['C.IV.', 71]],
        'D.' => [['D.1.', 75], ['D.2.', 76], ['D.3.', 77]],
        // Úroveň 3 pod C.II. — {@see \MyInvoice\Service\Accounting\Reports\FinancialStatementService::$smallExtraRows}
        // je vrací i pro scope='small' jako výslovnou výjimku z „jen do úrovně 2" (§3a
        // odst. 2 písm. b) vyhl. 500/2002 Sb.), takže data pro malou ÚJ existují stejně
        // jako pro plný rozsah. Bez nich zkušební EPO 31. 8. 2026 vytklo „Hodnota řádku
        // C.II. rozvahy-aktiv není rovna součtu řádků aktiv C.II.1 + C.II.2. + C.II.3."
        // — C.II.3. v `statement_rows` nemáme (viz PASIVA_C_C_RADKU komentář k obdobné
        // mezeře u pasiv), posílá se tedy jen C.II.1.+C.II.2., což na součet stačí (chybí
        // třetí složka přispívá nulou, ne chybou).
        'C.II.' => [['C.II.1.', 47], ['C.II.2.', 57]],
        // Dodatek 2026-08-31 (pokračování, úroveň 3 zbytku rozvahy-aktiv) — jakmile
        // předchozí doplnění poslalo B./C. skupiny jako hodnoty, zkušební EPO u `large`
        // začalo navíc vytýkat i JEJICH vlastní součty: „Hodnota řádku B.I./B.II./B.III./
        // C.I./C.III./C.IV. rozvahy-aktiv není rovna součtu…". `buildAktivaDetailElements`
        // je čistě rekurzivní podle téhle mapy (rowCode → děti), takže stačí doplnit klíče
        // — žádná změna kódu. Čísla řádků ověřena stejným zdrojem (tabulka 23810 „Rozvaha —
        // A K T I V A", platnost stabilní napříč roky — anchor hodnoty B./C./D./B.I./B.II./
        // B.III./C.I./C.II./C.III./C.IV./D.1.-D.3./C.II.1./C.II.2. výše se shodují přesně).
        'B.I.'   => [['B.I.1.', 5], ['B.I.2.', 6], ['B.I.3.', 9], ['B.I.4.', 10], ['B.I.5.', 11]],
        'B.II.'  => [['B.II.1.', 15], ['B.II.2.', 18], ['B.II.3.', 19], ['B.II.4.', 20], ['B.II.5.', 24]],
        'B.III.' => [
            ['B.III.1.', 28], ['B.III.2.', 29], ['B.III.3.', 30], ['B.III.4.', 31],
            ['B.III.5.', 32], ['B.III.6.', 33], ['B.III.7.', 34],
        ],
        'C.I.'   => [['C.I.1.', 39], ['C.I.2.', 40], ['C.I.3.', 41], ['C.I.4.', 44], ['C.I.5.', 45]],
        'C.III.' => [['C.III.1.', 69], ['C.III.2.', 70]],
        'C.IV.'  => [['C.IV.1.', 72], ['C.IV.2.', 73]],
        // Úroveň 4 — pod řádky výše, které jsou samy podřádkem se svými vlastními
        // podřádky (B.I.2./B.I.5./B.II.1./B.II.4./B.II.5./B.III.7./C.I.3.). Data pro ně v
        // `statement_rows` existují (viz migrace 1012), takže jde stále o skupinu (a) —
        // bez nich by se stejná past jen posunula o úroveň hlouběji, jakmile výše uvedené
        // řádky začnou nést nenulovou hodnotu.
        'B.I.2.'   => [['B.I.2.1.', 7], ['B.I.2.2.', 8]],
        'B.I.5.'   => [['B.I.5.1.', 12], ['B.I.5.2.', 13]],
        'B.II.1.'  => [['B.II.1.1.', 16], ['B.II.1.2.', 17]],
        'B.II.4.'  => [['B.II.4.1.', 21], ['B.II.4.2.', 22], ['B.II.4.3.', 23]],
        'B.II.5.'  => [['B.II.5.1.', 25], ['B.II.5.2.', 26]],
        'B.III.7.' => [['B.III.7.1.', 35], ['B.III.7.2.', 36]],
        'C.I.3.'   => [['C.I.3.1.', 42], ['C.I.3.2.', 43]],
    ];

    /**
     * Úroveň 2 rozvahy-pasiv — stejný důvod a stejný zdroj čísel (tabulka 24810
     * „Rozvaha-pasiva", platnost=2026) jako AKTIVA_DETAIL_C_RADKU výše. U `P.A.V.`
     * (Výsledek hospodaření běžného účetního období) je klíčové, že zaokrouhlovací
     * rozdíl součtu A.I.–A.VI. se do něj NIKDY nesmí absorbovat (viz buildVetaUD) — jeho
     * hodnota musí zůstat přesně stejná jako `VH` z výkazu zisku a ztráty (buildVetaUB),
     * na kterou navazuje samostatná křížová kontrola EPO („Hodnota řádku 'Výsledek
     * hospodaření za účetní období' … se nerovná …").
     */
    private const PASIVA_A_C_RADKU = [
        ['P.A.I.', 3], ['P.A.II.', 7], ['P.A.III.', 15], ['P.A.IV.', 18], ['P.A.V.', 22], ['P.A.VI.', 23],
    ];

    /** Úroveň 2 pod P.C. (Závazky) — tabulka 24810, platnost=2026. */
    private const PASIVA_C_C_RADKU = [
        ['P.C.I.', 31], ['P.C.II.', 46],
    ];

    /** Úroveň 2 pod P.D. (Časové rozlišení pasiv) — tabulka 24810, platnost=2026. */
    private const PASIVA_D_C_RADKU = [
        ['P.D.1.', 65], ['P.D.2.', 66],
    ];

    /**
     * Úroveň 2 pod P.B. (Rezervy) — tabulka 24810, platnost=2026. `P.B.1.` (Rezerva na
     * důchody a podobné závazky, ř. 26) v `statement_rows` nemáme (žádná mapa účtů na ni
     * neukazuje — chybějící DATA, ne chybějící mapování, viz AUDIT-DPPO-XML.md dodatek 11),
     * posílají se tedy jen tři ze čtyř oficiálních podřádků; chybějící přispívá součtu
     * nulou, ne chybou (stejný princip jako C.II.3. u aktiv/C.III. u pasiv výše).
     */
    private const PASIVA_B_C_RADKU = [
        ['P.B.2.', 27], ['P.B.3.', 28], ['P.B.4.', 29],
    ];

    /**
     * Dodatek 2026-08-31 (pokračování, úroveň 3 zbytku rozvahy-pasiv) — stejný nález a
     * stejný zdroj čísel jako u AKTIVA_DETAIL_C_RADKU výše: jakmile PASIVA_A_C_RADKU/
     * PASIVA_C_C_RADKU/PASIVA_B_C_RADKU začaly posílat A.I.–A.VI./C.I./C.II./B. jako
     * hodnoty, zkušební EPO u `large` navíc vytklo JEJICH vlastní součty („Hodnota řádku
     * A.I./A.II./A.III./A.IV./B./C.I./C.II. rozvahy-pasiv není rovna součtu…"). Klíč =
     * row_code rodiče; `buildPasivaDetailElements` do téhle mapy rekurzivně nahlíží pro
     * KAŽDÝ řádek, který sama vypíše (stejný princip jako AKTIVA_DETAIL_C_RADKU), takže
     * pokrývá i úroveň 4 (A.II.2./C.II.8.) jedním mechanismem beze změny volajícího kódu.
     * `A.IV.2.` (ř. 21, „Jiný výsledek hospodaření minulých let") a `C.I.4./C.I.5./C.I.7.`/
     * `C.II.7.`/`P.B.1.` chybí ve `statement_rows` (skupina (b), viz AUDIT-DPPO-XML.md
     * dodatek 11) — u nich se posílá jen to, co v datech je; chybějící přispívá nulou.
     */
    private const PASIVA_DETAIL_C_RADKU = [
        'P.A.I.'    => [['P.A.I.1.', 4], ['P.A.I.2.', 5], ['P.A.I.3.', 6]],
        'P.A.II.'   => [['P.A.II.1.', 8], ['P.A.II.2.', 9]],
        'P.A.II.2.' => [['P.A.II.2.1.', 10], ['P.A.II.2.2.', 11]],
        'P.A.III.'  => [['P.A.III.1.', 16], ['P.A.III.2.', 17]],
        'P.A.IV.'   => [['P.A.IV.1.', 19]],
        'P.C.I.'    => [
            ['P.C.I.1.', 32], ['P.C.I.2.', 35], ['P.C.I.3.', 36],
            ['P.C.I.6.', 39], ['P.C.I.8.', 41], ['P.C.I.9.', 42],
        ],
        'P.C.II.'   => [
            ['P.C.II.1.', 47], ['P.C.II.2.', 50], ['P.C.II.3.', 51], ['P.C.II.4.', 52],
            ['P.C.II.5.', 53], ['P.C.II.6.', 54], ['P.C.II.8.', 56],
        ],
        'P.C.II.8.' => [
            ['P.C.II.8.1.', 57], ['P.C.II.8.2.', 58], ['P.C.II.8.3.', 59], ['P.C.II.8.4.', 60],
            ['P.C.II.8.5.', 61], ['P.C.II.8.6.', 62], ['P.C.II.8.7.', 63],
        ],
    ];

    /**
     * Příloha — mapa `row_code` (FinancialStatementService::incomeStatement) → `c_radku`
     * EPO tiskopisu VZZ (druhové členění, plný rozsah), tabulka 25810, platnost=2026
     * (stejný číselník jako AKTIVA_C_RADKU výše). `VI.` se v tiskopisu tiskne dvakrát
     * (ř.39 „VI." celkem, ř.41 „VI.2. Ostatní") — náš statement_rows seed nerozlišuje
     * VI.1./VI.2. (spec §3 pozn., §7.d): VI.1. (úroky od ovládané/ovládající osoby) je
     * v ověřených datech vždy 0 a nemáme jej v chart_of_accounts odděleně, proto oba
     * řádky čerpají ze stejné hodnoty `VI.`. Obdobně `I.n` (interní row_code, viz
     * {@see \MyInvoice\Service\Accounting\Reports\FinancialStatementService::DISPLAY_CODE_ALIAS})
     * je v tiskopisu znovu jen „I." (ř.42) — druhý výskyt písmene I. ve VZZ.
     *
     * Zkušební EPO 31. 8. 2026 vytklo chybějící řádky II./B./C./III./IV./G./V./H./I.n/J.
     * jako křížové kontroly „Provozní/Finanční výsledek hospodaření neodpovídá výpočtu"
     * — bez nich formule (I.+II.-A.-B.-C.-D.-E.+III.-F. a IV.-G.+V.-H.+VI.-I.n-J.+VII.-K.)
     * neměla co sečíst. Doplněno; zaokrouhlovací absorpce součtu do PVH/FVH viz
     * buildVetaUB/absorbRoundingDiff.
     *
     * Zbývající neověřené řádky (v obou referenčních letech vždy nulové, bez kotvy —
     * E.1.2., E.2., E.3., III.1.-III.3., F.1., F.2., F.4., M.) záměrně chybí (spec §7.a);
     * nejde o formulové součty, které EPO cituje, jen o dílčí rozpady již pokrytých
     * mezisoučtů.
     *
     * `L.2.` (odložená daň) mezi nimi BÝVALO — a to jen proto, že o odložené dani systém
     * neúčtoval, takže řádek vycházel vždy nulový. Po doplnění kroku uzávěrky (ČÚS 003,
     * 592/481) už nulový být nemusí, takže kotvu má: bez ní by se zaúčtovaná odložená daň
     * do přílohy přiznání vůbec nepřenesla.
     */
    private const VZZ_C_RADKU = [
        ['I.', 1], ['II.', 2], ['A.', 3], ['A.2.', 5], ['A.3.', 6],
        ['B.', 7], ['C.', 8],
        ['D.', 9], ['D.1.', 10], ['D.2.', 11], ['D.2.1.', 12], ['D.2.2.', 13],
        ['E.', 14], ['E.1.', 15], ['E.1.1.', 16],
        ['III.', 20],
        ['F.', 24], ['F.3.', 27], ['F.5.', 29],
        ['PVH', 30],
        ['IV.', 31], ['G.', 34], ['V.', 35], ['H.', 38],
        ['VI.', 39], ['VI.', 41],
        ['I.n', 42], ['J.', 43],
        ['VII.', 46], ['K.', 47],
        ['FVH', 48], ['VHPZ', 49],
        ['L.', 50], ['L.1.', 51], ['L.2.', 52],
        ['VHPO', 53], ['VH', 55], ['OBRAT', 56],
    ];

    /**
     * Řádky vzorce, který EPO doslova cituje v chybové hlášce (viz VZZ_C_RADKU výše):
     * klíč = row_code mezisoučtu, hodnota = [row_code složky => znaménko ve vzorci].
     * Používá se jen k zaokrouhlovací absorpci (buildVetaUB) — PVH/FVH samy o sobě se
     * NIKDY neupravují (z nich se navíc dopočítává VHPZ = PVH+FVH), rozdíl mezi jejich
     * nezávisle zaokrouhlenou hodnotou a součtem složek se absorbuje do největší složky.
     */
    private const VZZ_FORMULA_PARTS = [
        'PVH' => ['I.' => 1, 'II.' => 1, 'A.' => -1, 'B.' => -1, 'C.' => -1, 'D.' => -1, 'E.' => -1, 'III.' => 1, 'F.' => -1],
        'FVH' => ['IV.' => 1, 'G.' => -1, 'V.' => 1, 'H.' => -1, 'VI.' => 1, 'I.n' => -1, 'J.' => -1, 'VII.' => 1, 'K.' => -1],
    ];

    /**
     * Řádky VZZ (druhové členění), které tvoří „roční úhrn čistého obratu" podle § 1d
     * odst. 2 zákona o účetnictví — VetaS/kc_dpp_i1 (chyba EPO 1703). 'I.' = Tržby
     * z prodeje výrobků a služeb (účty 601+602), 'II.' = Tržby za prodej zboží (účet 604).
     * Shodné s {@see \MyInvoice\Service\Accounting\Reports\EntityCategoryService::TURNOVER_CODES}
     * (601/602/604), který stejnou dvojici řádků používá pro kategorizaci ÚJ podle §1b —
     * jde o tentýž zákonný pojem. Záměrně NEbereme `checks.net_turnover`/řádek 'OBRAT'
     * z {@see \MyInvoice\Service\Accounting\Reports\FinancialStatementService} — ten je
     * širší (calc_key sčítá I.–VII., tj. i finanční a ostatní provozní výnosy), a čistému
     * obratu dle §1d neodpovídá.
     */
    private const NET_TURNOVER_ROW_CODES = ['I.', 'II.'];

    /**
     * @param array<string,mixed> $supplier row ze supplier (loadSupplier v service)
     * @param array<string,mixed> $calc     výstup DppoReturnCalculator::compute
     * @param array<string,mixed> $meta     verzeSW, typ_dapdpp, dapdpp_forma, typ_zo, typ_popldpp,
     *   volitelně `poc_zam` (viz {@see buildVetaS} — přebije hodnotu ze $appendix['settings']),
     *   volitelně `representation` (výstup {@see TaxRepresentationService::at()}, jinak 'N' bez zástupce)
     * @param array<string,mixed> $appendix Příloha účetní závěrky (Epic DP — VetaUA/UB/UD/UZ,
     *   volitelné): {balance_sheet: array (FinancialStatementService::balanceSheet výstup),
     *   income_statement: array (…::incomeStatement výstup), category: array
     *   (EntityCategoryService::evaluate výstup), settings: array
     *   (AccountingSupplierSettingsRepository::get výstup), statement_notes_attachment?:
     *   array{content:string,filename:string,label:string}, cash_flow_attachment?: stejný
     *   tvar, equity_changes_attachment?: stejný tvar — SKUTEČNĚ přiložené soubory (viz
     *   buildPrilohy()/PREDEPSANA_PRILOHA_KODY), ne strukturovaná data; každý nezávisle
     *   volitelný, i s appendixem, když daná příloha není kompletní/povinná}. Prázdné
     *   (default) = appendix se nevygeneruje (zpětná kompatibilita se stávajícími
     *   voláními/testy).
     * @return array{xml:string,warnings:list<string>}
     */
    public function build(array $supplier, int $year, array $calc, array $meta = [], array $appendix = []): array
    {
        $warnings = [];
        [$dom, $root] = EpoEnvelope::create(
            'DPPDP9',
            (string) ($meta['verze_pis'] ?? self::VERZE_PIS),
            isset($meta['verze_sw']) ? (string) $meta['verze_sw'] : null,
        );

        // ── VetaD — hlavička (povinné atributy) ─────────────────────────────
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'DPP');   // fixní
        $vetaD->setAttribute('dokument', 'DP9');    // fixní
        $vetaD->setAttribute('typ_dapdpp', (string) ($meta['typ_dapdpp'] ?? 'A'));   // A = za zdaňovací období
        $vetaD->setAttribute('dapdpp_forma', (string) ($meta['dapdpp_forma'] ?? 'B')); // B = řádné
        // Typ poplatníka (§ 17): 1 = ostatní, tedy běžná obchodní korporace. Ostatní kódy
        // (veřejně prospěšný poplatník, daňový nerezident, investiční fond, penzijní
        // společnost, nositel investiční pobídky) aplikace neeviduje a nepodporuje — viz
        // private/DANE-PODPORA-HRANICE.md, kategorie C. Kód je přebitelný z `$meta`, ale
        // nikdo ho zatím nepředává, takže se u nestandardního poplatníka musí ověřit ručně.
        $typPoplatnika = (string) ($meta['typ_popldpp'] ?? '1');
        $vetaD->setAttribute('typ_popldpp', $typPoplatnika);
        // Jediný signál, který z dat máme: sídlo mimo ČR. Nerezident má vlastní kód 2
        // a přiznání se mu počítá jinak, takže tiše tvrdit „ostatní poplatník" by bylo
        // nepravdivé. Nepřepisujeme to automaticky — sídlo v cizině samo o sobě
        // nerezidenta nedělá (rozhoduje místo vedení podle § 17 odst. 3).
        $seat = strtoupper(trim((string) ($supplier['country_iso2'] ?? 'CZ')));
        if ($typPoplatnika === '1' && $seat !== '' && $seat !== 'CZ') {
            $warnings[] = 'Sídlo poplatníka je mimo ČR (' . $seat . '), ale v přiznání je uvedený '
                . 'typ poplatníka „ostatní" (kód 1). Daňový nerezident má kód 2 a jiný rozsah '
                . 'zdanění (§ 17 odst. 4) — ověřte, zda je poplatník daňovým rezidentem ČR.';
        }
        // typ_zo dle §21a: A = kalendářní rok, B = hospodářský rok, D = období > 12 měsíců.
        // Velké písmeno dle reálně podaného přiznání (EPO konvence) — malé „a" bylo chybně.
        $vetaD->setAttribute('typ_zo', (string) ($meta['typ_zo'] ?? 'A'));
        $ufo = (string) ($supplier['financial_office_code'] ?: '451');
        $vetaD->setAttribute('c_ufo_cil', $ufo);
        // Datumy zdaňovacího období — reálné hranice účetního období (hospodářský rok),
        // fallback na kalendářní rok když období není předáno. Fallback NESMÍ být tichý:
        // u poplatníka s hospodářským rokem by 1.1.–31.12. + typ_zo 'a' bylo věcně chybné
        // přiznání (§21a). Když období chybí, doplň warning, ať to účetní vidí a ověří.
        if (!isset($meta['zdobd_od']) || (string) $meta['zdobd_od'] === '') {
            $warnings[] = 'Účetní období nebylo předáno — do přiznání se dosadil kalendářní rok '
                . sprintf('01.01.%04d–31.12.%04d', $year, $year) . ' (typ_zo „A"). Má-li poplatník '
                . 'hospodářský rok nebo zkrácené období, zdaňovací období v přiznání ověřte a opravte.';
        }
        // Formát dd.mm.rrrr s vedoucími nulami (EPO konvence dle reálně podaného přiznání;
        // XSD dateInMultiFormat sice toleruje i „1.3.2024", ale zaběhlá podoba je zero-padded).
        $zdobdDo = (string) ($meta['zdobd_do'] ?? sprintf('31.12.%04d', $year));
        $vetaD->setAttribute('zdobd_od', (string) ($meta['zdobd_od'] ?? sprintf('01.01.%04d', $year)));
        $vetaD->setAttribute('zdobd_do', $zdobdDo);
        $nace = EpoSupplierBlockBuilder::normalizeOkec((string) ($supplier['cz_nace_code'] ?? ''));
        if ($nace !== null) {
            $vetaD->setAttribute('c_nace', $nace);
        }
        $naceWarning = EpoSupplierBlockBuilder::okecWarning((string) ($supplier['cz_nace_code'] ?? ''));
        if ($naceWarning !== null) {
            $warnings[] = $naceWarning;
        }

        // Dodatečné/opravné přiznání (dapdpp_forma = O/D/E). U dodatečného (D/E)
        // datum zjištění důvodů (§141 DŘ, XSD kritická kontrola) + V. oddíl rozdíl
        // daně: kc_dppiv1 = nově zjištěná daň (ř.340), kc_dppiv2 = poslední známá,
        // kc_dppiv3 = rozdíl. Vše celé Kč. Řádné (B) tyto atributy nemá.
        $forma = (string) ($meta['dapdpp_forma'] ?? 'B');
        if (in_array($forma, ['D', 'E'], true)) {
            $dZjist = $this->formatDate($meta['d_zjist'] ?? null);
            if ($dZjist !== '') {
                $vetaD->setAttribute('d_zjist', $dZjist);
            } else {
                $warnings[] = 'Dodatečné přiznání: chybí datum zjištění důvodů (d_zjist) — EPO ho vyžaduje.';
            }
            foreach (['kc_dppiv1', 'kc_dppiv2', 'kc_dppiv3'] as $ivAttr) {
                if (array_key_exists($ivAttr, $meta)) {
                    $vetaD->setAttribute($ivAttr, (string) (int) round((float) $meta[$ivAttr]));
                }
            }
        }
        // kc_v_1 (zaplacené zálohy) — vypustit, když je 0 (EPO konvence: reálně podané
        // přiznání nulové atributy nemá, jen je při zálohách > 0 vyplní).
        $advancesPaidV1 = max(0, (int) round((float) ($calc['advances_paid'] ?? 0)));
        if ($advancesPaidV1 > 0) {
            $vetaD->setAttribute('kc_v_1', (string) $advancesPaidV1);
        }
        $vetaD->setAttribute('kc_v_4', (string) (-(int) round((float) ($calc['balance_due'] ?? 0))));

        $hasAppendix = !empty($appendix['balance_sheet']) && !empty($appendix['income_statement']);
        if ($hasAppendix) {
            $this->applyAppendixMetaToVetaD(
                $vetaD,
                (array) ($appendix['category'] ?? []),
                (array) ($appendix['settings'] ?? []),
                (array) $appendix['balance_sheet'],
            );
        }

        // zvl_pr — počet zvláštních příloh (VetaR, viz buildVetaR). Musí odpovídat počtu
        // SKUTEČNĚ vygenerovaných vět, jinak zkušební EPO hlásí nesoulad — proto se plní
        // až po sestavení $vetaRList níže, ne odhadem.
        $vetaRList = $this->buildVetaR($dom, $calc);
        $vetaD->setAttribute('zvl_pr', (string) count($vetaRList));

        // spoj_zahr — § 23 odst. 7 ZDP, transakce se spojenou osobou (tuzemskou/zahraniční)
        // z faktur označených clients.related_party (viz DppoReturnDataProvider). 'N', když
        // se v období žádné takové transakce nevyskytly.
        $relatedPartyFlag = (string) ($calc['related_party_country_flag'] ?? 'N');
        if (in_array($relatedPartyFlag, ['N', 'T', 'Z', 'A'], true)) {
            $vetaD->setAttribute('spoj_zahr', $relatedPartyFlag);
        }

        // dan_por — zpracoval a podává přiznání daňový poradce na plnou moc (§29/2 DŘ)?
        // Čte se z evidence zastoupení (TaxRepresentationService) k datu $meta['representation_date']
        // (viz TaxReturnService — finalizované přiznání nese stav K TEHDEJŠÍMU DATU, ne
        // dnešní). Bez evidence (nezastoupená firma) je to konstantně 'N' jako dřív.
        $representation = (array) ($meta['representation'] ?? ['represented' => false]);
        $vetaD->setAttribute('dan_por', EpoSupplierBlockBuilder::representationFlag($representation));

        // ── VetaE/VetaF — přílohy č. 1 II. oddílu (tabulka a) a b)) — musí být hotové
        // dřív než se p_pr_2od zapíše na VetaD (počet příloh II. oddílu = kolik z
        // VetaE/F/G se skutečně vygenerovalo; VetaG nestavíme).
        $vetaE = $this->buildVetaE($dom, $calc);
        $vetaF = $this->buildVetaF($dom, $calc, $warnings);
        $vetaD->setAttribute('p_pr_2od', (string) (($vetaE !== null ? 1 : 0) + ($vetaF !== null ? 1 : 0)));

        // sam_pr — počet samostatných příloh. VetaA (přehled transakcí se spojenými osobami)
        // NENÍ ani „příloha II. oddílu" (p_pr_2od výše — to jsou tabulky a)-j) VetaE/F/G/…,
        // XSD sekvence je řadí PŘED VetaS), ani „zvláštní příloha" (zvl_pr — VetaR, volný
        // text). Ověřeno na zkušebním EPO 31. 8. 2026 (private/AUDIT-DPPO-XML.md §11): reálné
        // odpovědi VetaA výslovně jmenují „List č. N sam. přílohy k pol. 12" — VetaA PATŘÍ do
        // sam_pr (samostatné přílohy, položka 12 I. oddílu), dřív natvrdo '0' (VetaZ/T/B/C/H,
        // které nestavíme, jsou taky sam_pr, ale jediná sam_pr věta, kterou builder skutečně
        // staví, je VetaA). Musí se plnit počtem SKUTEČNĚ vygenerovaných vět, ne odhadem —
        // proto až po sestavení $vetaAList níže.
        $vetaAList = $this->buildVetaA($dom, $calc, $warnings);
        $vetaD->setAttribute('sam_pr', (string) count($vetaAList));

        $root->appendChild($vetaD);

        if (empty($supplier['financial_office_code'])) {
            $warnings[] = 'Chybí kód finančního úřadu — použit fallback 451; ověřte v Nastavení firmy.';
        }
        if (empty($supplier['dic'])) {
            $warnings[] = 'Chybí DIČ poplatníka.';
        }
        if (empty($supplier['ic'])) {
            $warnings[] = 'Chybí IČO poplatníka.';
        }

        // ── VetaP — poplatník (DPPDP9 tvar: rod_c=IČO, zkrobchjm, bez typ_ds/c_ufo) ─
        $root->appendChild($this->buildVetaP($dom, $supplier, $representation));

        // ── VetaO — řádky II. oddílu ────────────────────────────────────────
        $root->appendChild($this->buildVetaO($dom, $calc, $year, $zdobdDo));

        // ── VetaE — tabulka a) (rozpad ř. 40), viz buildVetaE; XSD sekvence ji chce
        // hned za VetaO/VetaU, před VetaF.
        if ($vetaE !== null) {
            $root->appendChild($vetaE);
        }

        // ── VetaF — tabulka B (odpisy), viz buildVetaF; XSD sekvence ji chce hned
        // za VetaE, před VetaM.
        if ($vetaF !== null) {
            $root->appendChild($vetaF);
        }

        $creditsEntitlement = max(0, (int) round((float) ($calc['summary']['credits_entitlement'] ?? 0)));
        if ($creditsEntitlement > 0) {
            $vetaM = $dom->createElement('VetaM');
            $vetaM->setAttribute('kc_dpp_f1', (string) max(0, (int) round((float) ($calc['summary']['disabled_employee_credit_amount'] ?? 0))));
            $vetaM->setAttribute('kc_dpp_f2', (string) max(0, (int) round((float) ($calc['summary']['disabled_employee_severe_credit_amount'] ?? 0))));
            $vetaM->setAttribute('kc_dpp_f4', (string) $creditsEntitlement);
            $root->appendChild($vetaM);
        }

        // ── VetaS — poc_zam/kc_dpp_i1/cisobr_mena (chyby EPO 1704+1703, viz buildVetaS).
        // XSD sekvence: VetaS patří mezi VetaM a VetaUA (za VetaN/VetaQ, které nestavíme).
        $root->appendChild($this->buildVetaS($dom, $meta, $appendix, $warnings));

        // ── VetaR — zvláštní (textová) příloha k ř. 62 II. oddílu, viz buildVetaR výše
        // (zvl_pr už spočítáno na VetaD). XSD sekvence ji chce hned za VetaS, před VetaW
        // (nestavíme) a VetaUA.
        foreach ($vetaRList as $vetaR) {
            $root->appendChild($vetaR);
        }

        // ── Příloha účetní závěrky (Epic DP) — VetaUA (aktiva) + VetaUB (VZZ) +
        // VetaUD (pasiva) + VetaUZ (sbírka listin). XSD sekvence vyžaduje přesně toto
        // pořadí bloků (ověřeno na reálném podání: …VetaUA*, VetaUB*, VetaUD*, VetaUZ).
        // VetaA (přehled transakcí se spojenými osobami) patří v sekvenci schématu AŽ ZA
        // celý blok VetaUA–VetaUU (dppdp9_epo2.xsd:4083, hned před VetaB/VetaC, které
        // nestavíme) — tedy před VetaUZ, ne za ni. Je NEZÁVISLÁ na appendixu (staví se,
        // i když se příloha účetní závěrky vůbec negeneruje) — $vetaAList je už sestavený
        // výš (sam_pr na VetaD ho potřeboval dřív), sem se jen vkládá na svou sekvenční pozici.
        if ($hasAppendix) {
            $balanceSheet = (array) $appendix['balance_sheet'];
            $incomeStatement = (array) $appendix['income_statement'];
            // VZZ se počítá JEDNOU (computeVzzThousands) a sdílí se s VetaUD: 'VH' musí
            // sedět bit přesně na 'P.A.V.' rozvahy (křížová kontrola EPO), a jediný způsob,
            // jak to garantovat i po dopočtu VHPZ/VHPO (viz computeVzzThousands), je nechat
            // P.A.V. PŘEVZÍT stejnou (už zaokrouhlenou) hodnotu, ne počítat obě strany
            // nezávisle — dvě nezávislá zaokrouhlení stejného reálného čísla se od sebe
            // uměla lišit o tisícikorunu.
            $vzz = $this->computeVzzThousands($incomeStatement);
            foreach ($this->buildVetaUA($dom, $balanceSheet) as $el) {
                $root->appendChild($el);
            }
            foreach ($this->buildVetaUB($dom, $vzz['sled'], $vzz['min']) as $el) {
                $root->appendChild($el);
            }
            foreach ($this->buildVetaUD($dom, $balanceSheet, $vzz['sled']['VH'] ?? null, $vzz['min']['VH'] ?? null) as $el) {
                $root->appendChild($el);
            }
            foreach ($vetaAList as $vetaA) {
                $root->appendChild($vetaA);
            }
            $root->appendChild($this->buildVetaUZ(
                $dom,
                (array) ($appendix['settings'] ?? []),
                $supplier,
            ));
        } else {
            foreach ($vetaAList as $vetaA) {
                $root->appendChild($vetaA);
            }
        }

        // ── VetaNP — žádost o vrácení přeplatku (§155 DŘ) — poslední věta před Přílohy,
        // staví se jen když z přiznání vyjde přeplatek (viz buildVetaNP).
        $vetaNP = $this->buildVetaNP($dom, $calc, $warnings);
        if ($vetaNP !== null) {
            $root->appendChild($vetaNP);
        }

        // ── Prilohy/PredepsanaPriloha — SKUTEČNĚ přiložené soubory (dppdp9.xsd:6180),
        // poslední element sekvence. Viz buildPrilohy(): dokládá přílohy účetní závěrky
        // (§39 vyhl. 500/2002 pro Přílohu; §18/2 ZoÚ pro peněžní toky/vlastní kapitál) jako
        // dokumenty, což VetaUA/UB/UD/UZ výše NEDĚLAJÍ (jsou to strukturovaná data, ne
        // dokument). NEŘEŠÍ ale EPO chybu 2602 „Není vložena příloha účetní závěrky" —
        // ověřeno proti zkušebnímu EPO 31. 8. 2026, výtka je se souborem i bez něj identická
        // (AUDIT-DPPO-XML.md dodatek 13, §13.3) — příčina zůstává neznámá (viz tam),
        // přiložení souborů je i tak samostatně správné.
        $prilohy = $this->buildPrilohy($dom, $appendix);
        if ($prilohy !== null) {
            $root->appendChild($prilohy);
        }

        return ['xml' => $dom->saveXML() ?: '', 'warnings' => $warnings];
    }

    /**
     * Mapa appendix klíč → `kod` `PredepsanaPriloha` (dppdp9.xsd:6234, výčet
     * `PP_ZVKAP|PP_UZMUS|PP_PTOK|PP_OPISPUV`). Pořadí v poli = pořadí v dokumentu = pořadí
     * číslování `cislo` (viz buildPrilohy()).
     *
     * Skutečný zadavatelem ručně vyplněný vzor v EPO (ověřený fakt, AUDIT-DPPO-XML.md)
     * ukázal, že „Příloha v účetní závěrce" patří do PŘEDEPSANÉ přílohy s kódem
     * `PP_OPISPUV` — každý kód odpovídá jednomu řádku v tabulce příloh EPO — NE do obecné
     * přílohy, jak dřív stavěl `ObecnaPriloha` bez kódu. `PP_UZMUS` (účetní závěrka dle
     * mezinárodních účetních standardů) se záměrně NESTAVÍ — IFRS aplikace nepodporuje
     * (private/DANE-PODPORA-HRANICE.md, kategorie C) a předstírat sestavenou IFRS závěrku
     * by bylo nepravdivé.
     */
    private const PREDEPSANA_PRILOHA_KODY = [
        'statement_notes_attachment' => 'PP_OPISPUV',
        'cash_flow_attachment'       => 'PP_PTOK',
        'equity_changes_attachment'  => 'PP_ZVKAP',
    ];

    /**
     * `Prilohy/PredepsanaPriloha` — skutečně přiložené soubory (base64), NE strukturovaná
     * data. Zdroj obsahu je `$appendix[...]` dle {@see PREDEPSANA_PRILOHA_KODY} — každý klíč
     * volitelný, sestavuje a kontroluje (kompletnost, limit velikosti — SOUČTOVĚ napříč
     * všemi přílohami) volající {@see \MyInvoice\Service\Tax\Return\TaxReturnService},
     * builder tu žádnou z těch kontrol neopakuje, jen mechanicky sestaví věty v pevném
     * pořadí PREDEPSANA_PRILOHA_KODY. Chybí-li všechny klíče, `Prilohy` se vůbec nepostaví —
     * prázdná/poloprázdná příloha v ostrém podání je horší než žádná.
     *
     * `cislo` (pořadové číslo přílohy) musí být v rámci podání unikátní (XSD) a je
     * PRŮBĚŽNÉ napříč přítomnými přílohami (1, 2, 3, …) bez ohledu na to, které z nich
     * se pro dané období skutečně vygenerovaly — přeskočená příloha (např. „Příloha v
     * účetní závěrce" nekompletní, ale peněžní toky ano) nenechává v číslování mezeru,
     * jen posune číslo těch, co následují.
     *
     * @param array<string,mixed> $appendix volitelně nese klíče z PREDEPSANA_PRILOHA_KODY,
     *   každý {content:string (syrový binární obsah PDF, NE base64), filename:string, label:string}
     */
    private function buildPrilohy(\DOMDocument $dom, array $appendix): ?\DOMElement
    {
        $items = [];
        foreach (self::PREDEPSANA_PRILOHA_KODY as $key => $kod) {
            $attachment = $appendix[$key] ?? null;
            if (!is_array($attachment) || !isset($attachment['content']) || $attachment['content'] === '') {
                continue;
            }
            $items[] = [$kod, $attachment];
        }
        if ($items === []) {
            return null;
        }

        $prilohy = $dom->createElement('Prilohy');
        $cislo = 1;
        foreach ($items as [$kod, $attachment]) {
            $el = $dom->createElement('PredepsanaPriloha', base64_encode((string) $attachment['content']));
            $el->setAttribute('cislo', (string) $cislo++);
            $nazev = mb_substr((string) ($attachment['label'] ?? ''), 0, 255);
            if ($nazev !== '') {
                $el->setAttribute('nazev', $nazev);
            }
            $jmSouboru = mb_substr((string) ($attachment['filename'] ?? ''), 0, 255);
            if ($jmSouboru !== '') {
                $el->setAttribute('jm_souboru', $jmSouboru);
            }
            $el->setAttribute('kodovani', 'base64');
            $el->setAttribute('kod', $kod);
            $prilohy->appendChild($el);
        }
        return $prilohy;
    }

    /**
     * Rozšíří VetaD o metadata účetní závěrky (spec §5) — jen když appendix skutečně
     * generujeme (aditivní, zpětně kompatibilní).
     *
     * @param array<string,mixed> $category    výstup EntityCategoryService::evaluate()
     * @param array<string,mixed> $settings    výstup AccountingSupplierSettingsRepository::get()
     * @param array<string,mixed> $balanceSheet výstup FinancialStatementService::balanceSheet()
     */
    private function applyAppendixMetaToVetaD(\DOMElement $vetaD, array $category, array $settings, array $balanceSheet): void
    {
        $vetaD->setAttribute('uc_zav', 'A'); // příloha (účetní závěrka) se generuje — vždy „ano"
        $vetaD->setAttribute('audit', !empty($settings['statutory_audit']) ? 'A' : 'N');
        // Účetní vyhláška, dle níž je závěrka sestavena — pro podnikatele 500/2002 Sb.
        // EPO ji vyžaduje vyplněnou, je-li žádost o předání závěrky do sbírky listin
        // (VetaUZ). Bez ní hlásí „Číslo vyhlášky musí být vyplněno" (2736) a „na zvolenou
        // účetní vyhlášku se nevztahuje možnost žádat o předání do sbírky listin" (2798).
        $vetaD->setAttribute('uv_vyhl', '500');

        // Kategorie účetní jednotky podle § 1b zákona o účetnictví. Dřív se plnila jen
        // u mikro ÚJ s odůvodněním, že kódy pro ostatní velikosti nejsou ověřené —
        // jenže schéma je má vypsané přímo v dokumentaci atributu (M/L/S/V) a zkušební
        // EPO bez nich hlásí „Kategorie účetní jednotky musí být vyplněna". Malá,
        // střední a velká ÚJ tedy podávaly přiznání s prázdnou kategorií.
        $katUj = match ((string) ($category['category'] ?? '')) {
            'micro' => 'M',
            'small' => 'L',
            'medium' => 'S',
            'large' => 'V',
            default => null,
        };
        if ($katUj !== null) {
            $vetaD->setAttribute('kat_uj', $katUj);
        }
        // Rozsah rozvahy se řídí tím, co jsme SKUTEČNĚ vygenerovali, ne velikostí ÚJ:
        // P = plný, Z = zkrácený pro malou ÚJ, M = zkrácený pro mikro ÚJ. Povinný audit
        // nebo ruční volba účetní posunou rozsah na plný i u malé firmy — proto se čte
        // `scope`, ne `category` (viz EntityCategoryService::evaluate).
        // Bez `scope` (volající předal jen kategorii) se rozsah odvodí z ní — to je
        // stav před R11/R12, kdy rozsah kategorii kopíroval.
        $scope = (string) ($category['scope'] ?? $category['category'] ?? '');
        $vetaD->setAttribute('uv_rozsah_rozv', match ($scope) {
            'micro' => 'M',
            'small' => 'Z',
            default => 'P',
        });
        // uv_rozsah_vzz='P' (plný rozsah) konstantně — appendix generuje VZZ vždy v plném
        // rozsahu bez ohledu na kategorii ÚJ (spec §6.c/§7.c, ověřeno na obou vzorcích).
        $vetaD->setAttribute('uv_rozsah_vzz', 'P');
        // uv_rozsah — souhrnný rozsah účetních výkazů; oba reálně podaná referenční přiznání
        // (ověřeno lokálně mimo repo) ho nesou vždy 'P' vedle rozdílného
        // uv_rozsah_rozv/uv_rozsah_vzz — není to alternativa k nim, EPO chce oba páry
        // vyplněné zároveň.
        $vetaD->setAttribute('uv_rozsah', 'P');

        $dUv = $this->formatDate($balanceSheet['period']['ends_on'] ?? null);
        if ($dUv !== '') {
            $vetaD->setAttribute('d_uv', $dUv);
        }
        $vetaD->setAttribute('uv_mena', 'CZK'); // multi-currency účetnictví zatím nemáme
        $vetaD->setAttribute('uz_dle_mus', 'N'); // IFRS nepodporujeme
        $vetaD->setAttribute('uz_rad', 'T'); // T = řádná závěrka (mimořádná/mezitímní mimo MVP)
        // sam_pr (počet samostatných příloh) se plní JEDNOU, v build() podle $vetaAList —
        // viz komentář tam. Dřív se přepisovalo natvrdo na '0' TADY, což by při appendixu +
        // VetaA přepsalo správný počet zpátky na nulu; proto se tu už nenastavuje vůbec.
    }

    /**
     * VetaUA — rozvaha AKTIVA (spec §1). Řádek se vypíše, jen když netto ≠ 0 NEBO
     * netto minulého období ≠ 0; hodnoty v celých tisících Kč.
     *
     * @param array<string,mixed> $balanceSheet výstup FinancialStatementService::balanceSheet()
     * @return list<\DOMElement>
     */
    private function buildVetaUA(\DOMDocument $dom, array $balanceSheet): array
    {
        $byCode = [];
        foreach ((array) ($balanceSheet['assets'] ?? []) as $row) {
            $byCode[(string) $row['row_code']] = $row;
        }

        $elements = [];
        foreach (self::AKTIVA_C_RADKU as [$rowCode, $cRadku]) {
            $row = $byCode[$rowCode] ?? null;
            if ($row === null) {
                continue;
            }
            $netto = $this->toThousands((float) $row['net']);
            $nettoMin = $this->toThousands((float) $row['prev_net']);
            if ($netto !== 0 || $nettoMin !== 0) {
                $elements[] = $this->vetaUaElement(
                    $dom,
                    $cRadku,
                    $this->toThousands((float) $row['gross']),
                    $this->toThousands((float) $row['correction']),
                    $netto,
                    $nettoMin,
                );
            }
            foreach ($this->buildAktivaDetailElements($dom, $byCode, $rowCode, $netto, $nettoMin) as $detailEl) {
                $elements[] = $detailEl;
            }
        }
        return $elements;
    }

    /**
     * Úroveň 2 rozvahy-aktiv (AKTIVA_DETAIL_C_RADKU) pod jedním souhrnným řádkem —
     * zaokrouhlovací past (viz absorbRoundingDiff) se řeší stejně jako u VetaUD: rodič
     * (`$parentNetto`/`$parentNettoMin`, už zaokrouhlený, případně sám absorbovaný o
     * úroveň výš) se NEMĚNÍ, rozdíl jde do dítěte s největší abs. hodnotou. Brutto se
     * upraví o STEJNÝ rozdíl jako netto (korekce beze změny), aby uvnitř upraveného
     * řádku dál platilo netto = brutto − korekce.
     *
     * @param array<string,array<string,mixed>> $byCode
     * @return list<\DOMElement>
     */
    private function buildAktivaDetailElements(\DOMDocument $dom, array $byCode, string $parentRowCode, int $parentNetto, int $parentNettoMin): array
    {
        $children = self::AKTIVA_DETAIL_C_RADKU[$parentRowCode] ?? [];
        if ($children === []) {
            return [];
        }

        $gross = [];
        $correction = [];
        $netto = [];
        $nettoMin = [];
        foreach ($children as [$rowCode, ]) {
            $row = $byCode[$rowCode] ?? null;
            if ($row === null) {
                continue;
            }
            $n = $this->toThousands((float) $row['net']);
            $nMin = $this->toThousands((float) $row['prev_net']);
            if ($n === 0 && $nMin === 0) {
                continue;
            }
            $gross[$rowCode] = $this->toThousands((float) $row['gross']);
            $correction[$rowCode] = $this->toThousands((float) $row['correction']);
            $netto[$rowCode] = $n;
            $nettoMin[$rowCode] = $nMin;
        }
        if ($netto === []) {
            return [];
        }

        $originalNetto = $netto;
        $netto = $this->absorbRoundingDiff($parentNetto, $netto);
        $nettoMin = $this->absorbRoundingDiff($parentNettoMin, $nettoMin);
        foreach ($netto as $rowCode => $n) {
            $gross[$rowCode] += $n - $originalNetto[$rowCode];
        }

        $elements = [];
        foreach ($children as [$rowCode, $cRadku]) {
            if (!isset($netto[$rowCode])) {
                continue;
            }
            $elements[] = $this->vetaUaElement($dom, $cRadku, $gross[$rowCode], $correction[$rowCode], $netto[$rowCode], $nettoMin[$rowCode]);
            // Úroveň 3 (jen C.II. — viz AKTIVA_DETAIL_C_RADKU komentář): cíl je hodnota
            // TOHOTO řádku PO absorpci na úrovni 2, ne jeho nezávisle zaokrouhlená hodnota
            // — jinak by se součet C.II.1.+C.II.2. mohl rozejít i s absorbovaným C.II.
            foreach ($this->buildAktivaDetailElements($dom, $byCode, $rowCode, $netto[$rowCode], $nettoMin[$rowCode]) as $nestedEl) {
                $elements[] = $nestedEl;
            }
        }
        return $elements;
    }

    private function vetaUaElement(\DOMDocument $dom, int $cRadku, int $gross, int $correction, int $netto, int $nettoMin): \DOMElement
    {
        $el = $dom->createElement('VetaUA');
        $el->setAttribute('c_radku', (string) $cRadku);
        $el->setAttribute('kc_brutto', (string) $gross);
        $el->setAttribute('kc_korekce', (string) $correction);
        $el->setAttribute('kc_netto', (string) $netto);
        $el->setAttribute('kc_netto_min', (string) $nettoMin);
        return $el;
    }

    /**
     * VetaUB — výkaz zisku a ztráty (spec §3). Řádek se vypíše, jen když sledované ≠ 0
     * NEBO minulé ≠ 0; hodnoty v celých tisících Kč.
     *
     * @param array<string,mixed> $incomeStatement výstup FinancialStatementService::incomeStatement()
     * @return list<\DOMElement>
     */
    private function buildVetaUB(\DOMDocument $dom, array $sled, array $min): array
    {
        $elements = [];
        foreach (self::VZZ_C_RADKU as [$rowCode, $cRadku]) {
            $s = $sled[$rowCode] ?? null;
            $m = $min[$rowCode] ?? null;
            if ($s === null || $m === null || ($s === 0 && $m === 0)) {
                continue;
            }
            $el = $dom->createElement('VetaUB');
            $el->setAttribute('c_radku', (string) $cRadku);
            $el->setAttribute('kc_min', (string) $m);
            $el->setAttribute('kc_sled', (string) $s);
            $elements[] = $el;
        }
        return $elements;
    }

    /**
     * Tisíce po zaokrouhlovací absorpci pro VZZ — společné jádro pro buildVetaUB i pro
     * P.A.V. z buildVetaUD (viz volání v build()). Řetězec dopočtů PVH→VHPZ→VHPO→VH drží
     * VZZ vnitřně konzistentní (každý mezisoučtový vzorec, který EPO cituje, sedí přesně
     * — „Výsledek hospodaření za účetní období neodpovídá výpočtu (Výsledek hospodaření
     * po zdanění − M.)" zjištěno křížovým ověřením proti zkušebnímu EPO 31. 8. 2026, když
     * `VH` zůstávalo nezávisle zaokrouhlené). Křížová kontrola vůči `P.A.V.` v rozvaze
     * (VetaUD) tím NENÍ ohrožena, protože P.A.V. tuhle (dopočítanou) hodnotu `VH` prostě
     * PŘEVEZME místo vlastního nezávislého zaokrouhlení — obě věty tak vždy nesou stejné
     * číslo, ať dopočet dopadne jakkoli.
     *
     * @param array<string,mixed> $incomeStatement výstup FinancialStatementService::incomeStatement()
     * @return array{sled: array<string,int>, min: array<string,int>}
     */
    private function computeVzzThousands(array $incomeStatement): array
    {
        $byCode = [];
        foreach ((array) ($incomeStatement['rows'] ?? []) as $row) {
            $byCode[(string) $row['row_code']] = $row;
        }

        // Tisíce PŘED absorpcí; 'VI.' se v poli vyskytuje 2× (viz VZZ_C_RADKU) — hodnota
        // se počítá jen jednou, oba c_radku ji pak níže sdílí.
        $sled = [];
        $min = [];
        foreach (self::VZZ_C_RADKU as [$rowCode, ]) {
            if (array_key_exists($rowCode, $sled)) {
                continue;
            }
            $row = $byCode[$rowCode] ?? null;
            if ($row === null) {
                continue;
            }
            $sled[$rowCode] = $this->toThousands((float) $row['amount']);
            $min[$rowCode] = $this->toThousands((float) $row['prev_amount']);
        }

        // Zaokrouhlovací past (stejný princip jako VetaUD/absorbRoundingDiff): PVH a FVH
        // zůstávají nezávisle zaokrouhlené beze změny — do NICH se rozdíl neabsorbuje,
        // protože z nich se níže dopočítává VHPZ. Rozdíl mezi PVH/FVH a součtem jejich
        // vlastních složek (VZZ_FORMULA_PARTS) se naopak absorbuje do největší složky.
        foreach (self::VZZ_FORMULA_PARTS as $totalCode => $signs) {
            if (!isset($sled[$totalCode])) {
                continue;
            }
            $parts = array_intersect_key($sled, $signs);
            if ($parts !== []) {
                $sled = array_replace($sled, $this->absorbRoundingDiff($sled[$totalCode], $parts, $signs));
            }
            $partsMin = array_intersect_key($min, $signs);
            if ($partsMin !== [] && isset($min[$totalCode])) {
                $min = array_replace($min, $this->absorbRoundingDiff($min[$totalCode], $partsMin, $signs));
            }
        }

        // VHPZ = PVH + FVH musí sedět přesně (chyba EPO „Výsledek hospodaření před
        // zdaněním neodpovídá výpočtu") — místo nezávislého zaokrouhlení skutečné hodnoty
        // VHPZ se proto DOPOČÍTÁ z už zaokrouhlených (a případně absorbovaných) PVH/FVH.
        if (isset($sled['PVH'], $sled['FVH'])) {
            $sled['VHPZ'] = $sled['PVH'] + $sled['FVH'];
        }
        if (isset($min['PVH'], $min['FVH'])) {
            $min['VHPZ'] = $min['PVH'] + $min['FVH'];
        }
        // VHPO = VHPZ − L. a VH = VHPO − M. musí sedět přesně stejně (chyby EPO „Výsledek
        // hospodaření po zdanění/za účetní období neodpovídá výpočtu"), obě zjištěné
        // křížovým ověřením proti zkušebnímu EPO 31. 8. 2026 poté, co předchozí oprava
        // nechala vždy jen NÁSLEDUJÍCÍ řádek řetězce nezávisle zaokrouhlený. M. se
        // nemapuje (viz VZZ_C_RADKU) — chybí-li, přispívá nulou, ne chybou.
        if (isset($sled['VHPZ'], $sled['L.'])) {
            $sled['VHPO'] = $sled['VHPZ'] - $sled['L.'];
        }
        if (isset($min['VHPZ'], $min['L.'])) {
            $min['VHPO'] = $min['VHPZ'] - $min['L.'];
        }
        if (isset($sled['VHPO'])) {
            $sled['VH'] = $sled['VHPO'] - ($sled['M.'] ?? 0);
        }
        if (isset($min['VHPO'])) {
            $min['VH'] = $min['VHPO'] - ($min['M.'] ?? 0);
        }

        return ['sled' => $sled, 'min' => $min];
    }

    /**
     * VetaUD — rozvaha PASIVA (spec §2). Vlastní jmenný prostor `c_radku` od 1 (nenavazuje
     * na AKTIVA). Řádek `c_radku=24` „B.+C. Cizí zdroje" v našem `statement_rows` neexistuje
     * jako samostatný uzel (spec §2.1, §7.e) — musí se dopočítat P.B.+P.C. za běhu.
     *
     * @param array<string,mixed> $balanceSheet výstup FinancialStatementService::balanceSheet()
     * @param ?int $vhSled `VH` (VetaUB, kc_sled) po zaokrouhlovací absorpci VZZ — je-li
     *   k dispozici, PŘEVEZME ho `P.A.V.` místo nezávislého zaokrouhlení vlastní hodnoty
     *   (viz computeVzzThousands docblock a build()).
     * @param ?int $vhMin totéž pro `kc_min` (minulé období)
     * @return list<\DOMElement>
     */
    private function buildVetaUD(\DOMDocument $dom, array $balanceSheet, ?int $vhSled = null, ?int $vhMin = null): array
    {
        $byCode = [];
        foreach ((array) ($balanceSheet['liabilities'] ?? []) as $row) {
            $byCode[(string) $row['row_code']] = $row;
        }

        // Součtová kontrola příjemce: PASIVA CELKEM = A. + (B.+C.) + D. Každý řádek se
        // ale zaokrouhluje na tisíce zvlášť, takže součet zaokrouhlených částí se od
        // zaokrouhleného celku umí lišit o tisícikorunu — a EPO to vytkne (chyba 2408
        // „Hodnota řádku PASIVA CELKEM rozvahy není rovna součtu řádků pasiv
        // A.+(B.+C.)+D."). Nejde o chybu účetnictví, jen o rozjetí při zaokrouhlení,
        // proto se rozdíl absorbuje do největší složky, ne do celku: celek musí sedět
        // na rozvahu a na stranu aktiv.
        $parts = [
            2 => [(float) ($byCode['P.A.']['amount'] ?? 0.0), (float) ($byCode['P.A.']['prev_amount'] ?? 0.0)],
            24 => [
                (float) ($byCode['P.B.']['amount'] ?? 0.0) + (float) ($byCode['P.C.']['amount'] ?? 0.0),
                (float) ($byCode['P.B.']['prev_amount'] ?? 0.0) + (float) ($byCode['P.C.']['prev_amount'] ?? 0.0),
            ],
            64 => [(float) ($byCode['P.D.']['amount'] ?? 0.0), (float) ($byCode['P.D.']['prev_amount'] ?? 0.0)],
        ];
        $partsT = [];
        foreach ($parts as $cRadku => [$sled, $min]) {
            $partsT[$cRadku] = [$this->toThousands($sled), $this->toThousands($min)];
        }
        if (isset($byCode['PASIVA'])) {
            foreach ([0, 1] as $column) {
                $totalT = $this->toThousands((float) $byCode['PASIVA'][$column === 0 ? 'amount' : 'prev_amount']);
                $sum = 0;
                foreach ($partsT as $values) {
                    $sum += $values[$column];
                }
                $diff = $totalT - $sum;
                if ($diff === 0) {
                    continue;
                }
                $largest = null;
                foreach ($partsT as $cRadku => $values) {
                    if ($largest === null || abs($values[$column]) > abs($partsT[$largest][$column])) {
                        $largest = $cRadku;
                    }
                }
                $partsT[$largest][$column] += $diff;
            }
        }

        // „B." (Rezervy, řádek 25) samostatně — bez ní zkušební EPO u kontroly „B.+C. =
        // B. + C." předpokládá B.=0, což při nenulových rezervách rozjede součet, i když
        // je řádek 24 sám o sobě spočítaný správně (obsahuje P.B.+P.C. dohromady). „C."
        // (řádek 30) zůstává STABILNÍ — na něm navazuje i skupina C.I.+C.II. níže — rozdíl
        // proto absorbuje jen „B.".
        $bSled = $bMin = null;
        if (isset($byCode['P.B.'])) {
            $cSledForB = $this->toThousands((float) ($byCode['P.C.']['amount'] ?? 0.0));
            $cMinForB = $this->toThousands((float) ($byCode['P.C.']['prev_amount'] ?? 0.0));
            $bSledParts = $this->absorbRoundingDiff(
                $partsT[24][0],
                ['B.' => $this->toThousands((float) $byCode['P.B.']['amount']), 'C.' => $cSledForB],
                [],
                ['C.'],
            );
            $bMinParts = $this->absorbRoundingDiff(
                $partsT[24][1],
                ['B.' => $this->toThousands((float) $byCode['P.B.']['prev_amount']), 'C.' => $cMinForB],
                [],
                ['C.'],
            );
            $bSled = $bSledParts['B.'];
            $bMin = $bMinParts['B.'];
        }

        $rows = [];
        if (isset($byCode['PASIVA'])) {
            $rows[] = [1, $this->toThousands((float) $byCode['PASIVA']['amount']), $this->toThousands((float) $byCode['PASIVA']['prev_amount'])];
        }
        if (isset($byCode['P.A.'])) {
            $rows[] = [2, $partsT[2][0], $partsT[2][1]];
        }
        if (isset($byCode['P.B.']) || isset($byCode['P.C.'])) {
            $rows[] = [24, $partsT[24][0], $partsT[24][1]];
        }
        if ($bSled !== null) {
            $rows[] = [25, $bSled, $bMin];
        }
        if (isset($byCode['P.C.'])) {
            $rows[] = [30, $this->toThousands((float) $byCode['P.C.']['amount']), $this->toThousands((float) $byCode['P.C.']['prev_amount'])];
        }
        if (isset($byCode['P.D.'])) {
            $rows[] = [64, $partsT[64][0], $partsT[64][1]];
        }

        $elements = [];
        foreach ($rows as [$cRadku, $sledT, $minT]) {
            if ($sledT === 0 && $minT === 0) {
                continue;
            }
            $elements[] = $this->vetaUdElement($dom, $cRadku, $sledT, $minT);
        }

        // Úroveň 2 (spec doplněk 2026-08-31): A.I.–A.VI. musí sedět na finální
        // reportovanou hodnotu „A." (tj. $partsT[2], po případné absorpci na úrovni
        // PASIVA CELKEM výše) — 'P.A.V.' je z absorpce VYLOUČENO, viz PASIVA_A_C_RADKU.
        // C.I.+C.II. sedí přímo na „C." (řádek 30 výše, ten se top-level absorpcí nikdy
        // nemění). D.1.+D.2. sedí na finální „D." ($partsT[64]).
        if (isset($byCode['P.A.'])) {
            // P.A.V. přebírá 'VH' z VZZ, je-li k dispozici (viz docblock computeVzzThousands
            // — proč se to řeší převzetím hodnoty, ne dvěma nezávislými zaokrouhleními).
            $overrides = ($vhSled !== null && $vhMin !== null) ? ['P.A.V.' => [$vhSled, $vhMin]] : [];
            foreach ($this->buildPasivaDetailElements($dom, $byCode, self::PASIVA_A_C_RADKU, $partsT[2][0], $partsT[2][1], ['P.A.V.'], $overrides) as $el) {
                $elements[] = $el;
            }
        }
        if (isset($byCode['P.C.'])) {
            $cSled = $this->toThousands((float) $byCode['P.C.']['amount']);
            $cMin = $this->toThousands((float) $byCode['P.C.']['prev_amount']);
            foreach ($this->buildPasivaDetailElements($dom, $byCode, self::PASIVA_C_C_RADKU, $cSled, $cMin) as $el) {
                $elements[] = $el;
            }
        }
        if (isset($byCode['P.D.'])) {
            foreach ($this->buildPasivaDetailElements($dom, $byCode, self::PASIVA_D_C_RADKU, $partsT[64][0], $partsT[64][1]) as $el) {
                $elements[] = $el;
            }
        }
        // „B." (Rezervy) samostatně — cíl je $bSled/$bMin, TÍŽ hodnota, do které se výše
        // (řádek 25) absorbuje případný rozdíl vůči „B.+C.", ne nezávisle zaokrouhlené P.B.
        if ($bSled !== null) {
            foreach ($this->buildPasivaDetailElements($dom, $byCode, self::PASIVA_B_C_RADKU, $bSled, $bMin) as $el) {
                $elements[] = $el;
            }
        }

        return $elements;
    }

    /**
     * Úroveň 2 rozvahy-pasiv pod jedním souhrnným řádkem (A./B./C./D.) — stejná
     * zaokrouhlovací absorpce jako buildAktivaDetailElements, jen bez brutto/korekce
     * (VetaUD schéma je nemá). `$exclude` chrání řádky s vlastní křížovou vazbou (viz
     * PASIVA_A_C_RADKU) před tím, aby do nich absorpce zasáhla. `$overrides` (row_code =>
     * [sled, min]) nahradí nezávisle zaokrouhlenou hodnotu řádku, který ji MUSÍ převzít
     * odjinud (jen `P.A.V.` ← `VH`, viz build()) — řádek pak dál neúčastní na absorpci
     * jinak (musí být zároveň v `$exclude`), jen v ní figuruje jako pevný bod součtu.
     *
     * Dodatek 2026-08-31 (pokračování): PO absorpci se metoda REKURZIVNĚ zavolá sama pro
     * každý vypsaný řádek, který je zároveň klíčem v PASIVA_DETAIL_C_RADKU (úroveň 3/4,
     * viz komentář u konstanty) — stejný princip jako buildAktivaDetailElements, jen bez
     * brutto/korekce. Cíl vnořeného volání je hodnota TOHOTO řádku PO absorpci na úrovni
     * výš, ne jeho nezávisle zaokrouhlená hodnota (jinak by se součet dětí mohl rozejít i
     * s absorbovaným rodičem). `$exclude`/`$overrides` se do rekurze nepředávají — týkají
     * se výhradně `P.A.V.`, což je vždy list (žádné vlastní podřádky v PASIVA_DETAIL_C_RADKU).
     *
     * @param array<string,array<string,mixed>> $byCode
     * @param list<array{0:string,1:int}>       $children
     * @param list<string>                      $exclude
     * @param array<string,array{0:int,1:int}>  $overrides
     * @return list<\DOMElement>
     */
    private function buildPasivaDetailElements(\DOMDocument $dom, array $byCode, array $children, int $targetSled, int $targetMin, array $exclude = [], array $overrides = []): array
    {
        $sled = [];
        $min = [];
        foreach ($children as [$rowCode, ]) {
            $row = $byCode[$rowCode] ?? null;
            if ($row === null) {
                continue;
            }
            if (isset($overrides[$rowCode])) {
                [$s, $m] = $overrides[$rowCode];
            } else {
                $s = $this->toThousands((float) $row['amount']);
                $m = $this->toThousands((float) $row['prev_amount']);
            }
            if ($s === 0 && $m === 0) {
                continue;
            }
            $sled[$rowCode] = $s;
            $min[$rowCode] = $m;
        }
        if ($sled === []) {
            return [];
        }

        $sled = $this->absorbRoundingDiff($targetSled, $sled, [], $exclude);
        $min = $this->absorbRoundingDiff($targetMin, $min, [], $exclude);

        $elements = [];
        foreach ($children as [$rowCode, $cRadku]) {
            if (!isset($sled[$rowCode])) {
                continue;
            }
            $elements[] = $this->vetaUdElement($dom, $cRadku, $sled[$rowCode], $min[$rowCode]);
            $nested = self::PASIVA_DETAIL_C_RADKU[$rowCode] ?? [];
            if ($nested !== []) {
                foreach ($this->buildPasivaDetailElements($dom, $byCode, $nested, $sled[$rowCode], $min[$rowCode]) as $nestedEl) {
                    $elements[] = $nestedEl;
                }
            }
        }
        return $elements;
    }

    private function vetaUdElement(\DOMDocument $dom, int $cRadku, int $sled, int $min): \DOMElement
    {
        $el = $dom->createElement('VetaUD');
        $el->setAttribute('kc_sled', (string) $sled);
        $el->setAttribute('c_radku', (string) $cRadku);
        $el->setAttribute('kc_min', (string) $min);
        return $el;
    }

    /**
     * Zaokrouhlovací past mezisoučtů (spec §2.1 u VetaUD, teď konzistentně i pro nově
     * doplněné úrovně VetaUA/VetaUB — „jádro úkolu" 2026-08-31): každá hodnota jde do XML
     * zaokrouhlená na tisíce ZVLÁŠŤ (viz toThousands), takže součet zaokrouhlených dětí se
     * umí od zaokrouhleného rodiče lišit o tisícikorunu — EPO to vytkne jako „hodnota
     * řádku X se nerovná součtu". Rodič (`$target`) se NIKDY neupravuje — může na něj
     * navazovat další křížová kontrola o úroveň výš (P.A. → PASIVA CELKEM) nebo úplně
     * jinde (P.A.V. → VH z VetaUB). Rozdíl se přičte k té složce z `$parts`, která smí
     * být upravena (mimo `$exclude`) a má největší absolutní hodnotu.
     *
     * @param int                $target  zaokrouhlený rodič (tisíce Kč)
     * @param array<string,int>  $parts   row_code => zaokrouhlená hodnota (tisíce Kč)
     * @param array<string,int>  $signs   row_code => +1/-1 dle vzorce; chybějící klíč = +1
     *                                    (prostý součet, jako u rozvahy)
     * @param list<string>       $exclude row_code, které absorpci nesmí přijmout
     * @return array<string,int> $parts s absorbovaným rozdílem
     */
    private function absorbRoundingDiff(int $target, array $parts, array $signs = [], array $exclude = []): array
    {
        if ($parts === []) {
            return $parts;
        }
        $sum = 0;
        foreach ($parts as $code => $value) {
            $sum += $value * ($signs[$code] ?? 1);
        }
        $diff = $target - $sum;
        if ($diff === 0) {
            return $parts;
        }
        $candidates = $exclude === [] ? $parts : array_diff_key($parts, array_flip($exclude));
        if ($candidates === []) {
            $candidates = $parts;
        }
        $largest = array_key_first($candidates);
        foreach ($candidates as $code => $value) {
            if (abs($value) > abs($candidates[$largest])) {
                $largest = $code;
            }
        }
        $parts[$largest] += $diff * ($signs[$largest] ?? 1);
        return $parts;
    }

    /**
     * VetaUZ — žádost o předání účetní závěrky do sbírky listin veřejného rejstříku
     * (spec §4). Ne totéž jako „co je součástí přiznání" — rozvaha se předává vždy,
     * VZZ jen u ÚJ s povinným auditem (přesné pravidlo pro small/medium bez auditu
     * k ověření, spec §7.g).
     *
     * `pr11_puz` (má se „Příloha účetní závěrky" zahrnout do ŽÁDOSTI o předání do sbírky
     * listin) ZŮSTÁVÁ 'N', i teď, když appendix umí přílohu jako soubor skutečně přiložit
     * (viz buildPrilohy() / TaxReturnService::buildStatementNotesAttachment()) — je to JINÁ
     * otázka, ne totéž rozhodnuté podruhé:
     *   - Připojení souboru (`Prilohy/PredepsanaPriloha`) je o tom, aby DPPDP9 neslo přílohu
     *     v účetní závěrce jako dokument (§39 vyhl. 500/2002) — samo o sobě NEŘEŠÍ EPO
     *     chybu 2602 „Není vložena příloha účetní závěrky" (ověřeno proti zkušebnímu EPO
     *     31. 8. 2026: se souborem i bez něj vrací IDENTICKOU výtku — viz AUDIT-DPPO-XML.md
     *     dodatek 13, §13.3). Přiložit ho je přesto správné (dokumentuje se, o co jde), jen
     *     to není lék na 2602.
     *   - `pr11_puz` je VOLITELNÁ žádost, aby FÚ tenhle konkrétní dokument JEŠTĚ NAVÍC
     *     přeposlal do sbírky listin veřejného rejstříku MÍSTO toho, aby ho poplatník podal
     *     zvlášť u rejstříkového soudu — samostatný právní úkon s vlastními důsledky
     *     (dokument se zveřejní), ne mechanický důsledek toho, že teď máme PDF po ruce.
     *     Experiment (dočasně `pr11_puz='A'`, zkušební EPO, dodatek 13 §13.4) navíc ukázal,
     *     že zapnutí vyvolá VLASTNÍ novou výtku („Chcete odeslat žádost… není však přiložen
     *     odpovídající počet příloh") nezávislou na 2602 — další důvod nechat 'N', dokud
     *     appka nenabídne vědomou volbu (checkbox „požádat o předání do sbírky listin") a
     *     neumí spočítat, kolik příloh EPO pro tuhle žádost očekává. Stejně jako
     *     `pr11_pzvk`/`pr11_ppt`/`pr11_uzmus` níže (ty navíc nemají ani obsah, který by šlo
     *     nabídnout).
     *
     * @param array<string,mixed> $settings výstup AccountingSupplierSettingsRepository::get()
     * @param array<string,mixed> $supplier
     */
    private function buildVetaUZ(\DOMDocument $dom, array $settings, array $supplier): \DOMElement
    {
        $vetaUZ = $dom->createElement('VetaUZ');
        $vetaUZ->setAttribute('pr11_rozv', 'A');
        $vetaUZ->setAttribute('pr11_vzz', !empty($settings['statutory_audit']) ? 'A' : 'N');
        $vetaUZ->setAttribute('pr11_puz', 'N');
        $vetaUZ->setAttribute('pr11_pzvk', 'N');
        $vetaUZ->setAttribute('pr11_ppt', 'N');
        $vetaUZ->setAttribute('pr11_uzmus', 'N');
        $email = trim((string) ($supplier['email'] ?? ''));
        if ($email !== '') {
            $vetaUZ->setAttribute('pr11_email', $email);
        }
        return $vetaUZ;
    }

    /**
     * VetaS — příloha K II. oddílu: `poc_zam` (chyba EPO 1704), `kc_dpp_i1` +
     * `cisobr_mena` (chyba EPO 1703). Zjištěno na reálném podání 30. 8. 2026 — builder
     * VetaS dřív vůbec nestavěl.
     *
     * @param array<string,mixed> $meta     smí nést explicitní `poc_zam` (přednost před
     *   settings — volající zná přesné číslo pro dané podání)
     * @param array<string,mixed> $appendix `settings` (AccountingSupplierSettingsRepository::get,
     *   nese `avg_employees`) a `income_statement` (FinancialStatementService::incomeStatement)
     * @param list<string>        $warnings
     */
    private function buildVetaS(\DOMDocument $dom, array $meta, array $appendix, array &$warnings): \DOMElement
    {
        $vetaS = $dom->createElement('VetaS');

        // poc_zam: XSD dokumentace k atributu žádá vyplnění VŽDY, i hodnotou 0 — proto se
        // atribut nikdy nevynechává (na rozdíl od zbytku VetaO/VetaM). Zdroj čísla systém
        // nedopočítává (mzdový modul úvazek nenese, viz StatementNotesService::autoValues) —
        // bere se buď z $meta, nebo z téhož nastavení, které používá i příloha v účetní
        // závěrce (Účetnictví → Uzávěrka → Příloha v účetní závěrce, sekce „Průměrný
        // přepočtený počet zaměstnanců").
        if (array_key_exists('poc_zam', $meta)) {
            $pocZam = (int) round((float) $meta['poc_zam']);
        } else {
            $avgEmployees = $appendix['settings']['avg_employees'] ?? null;
            if ($avgEmployees !== null) {
                $pocZam = (int) $avgEmployees;
            } else {
                $pocZam = 0;
                $warnings[] = 'Průměrný přepočtený počet zaměstnanců nebyl nalezen — do přiznání '
                    . 'se dosadila hodnota 0 (chyba EPO 1704 vyžaduje vyplnění vždy, i nulou). '
                    . 'Účetní má hodnotu doplnit v Účetnictví → Uzávěrka → Příloha v účetní '
                    . 'závěrce, sekce „Průměrný přepočtený počet zaměstnanců", a přiznání '
                    . 'vygenerovat znovu.';
            }
        }
        $vetaS->setAttribute('poc_zam', (string) max(0, $pocZam));

        // cisobr_mena se plní VŽDY, jakmile VetaS existuje: zkušební EPO 30. 8. 2026
        // vrátilo KRITICKOU chybu „Měna čistého obratu v tabulce K musí být vyplněna"
        // u věty, která nesla jen poc_zam. Měna tedy nevisí na obratu, ale na existenci
        // tabulky K. Multi-currency účetnictví zatím nemáme (shodně s VetaD/uv_mena).
        $vetaS->setAttribute('cisobr_mena', 'CZK');

        // kc_dpp_i1: jen když je k dispozici VZZ (příloha účetní závěrky).
        $incomeStatement = (array) ($appendix['income_statement'] ?? []);
        if ($incomeStatement !== []) {
            $byCode = [];
            foreach ((array) ($incomeStatement['rows'] ?? []) as $row) {
                $byCode[(string) $row['row_code']] = $row;
            }
            $turnover = 0.0;
            foreach (self::NET_TURNOVER_ROW_CODES as $rowCode) {
                $turnover += (float) ($byCode[$rowCode]['amount'] ?? 0.0);
            }
            $vetaS->setAttribute('kc_dpp_i1', (string) (int) round($turnover));
        } else {
            $warnings[] = 'Roční úhrn čistého obratu (kc_dpp_i1) nebyl vyplněn — příloha účetní '
                . 'závěrky (výkaz zisku a ztráty) nebyla k dispozici; EPO to vytkne jako chybu '
                . '1703 „Roční úhrn čistého obratu není naplněn". Ověřte uzavřené účetní období '
                . 'a přiznání vygenerujte znovu.';
        }

        return $vetaS;
    }

    /**
     * VetaE — příloha č. 1 II. oddílu, tabulka a) (§23/3 písm. b) — rozpad výdajů
     * neuznávaných za náklady). Jediný atribut `kc_dpp_a12` musí být shodný s ř. 40
     * II. oddílu (XSD dokumentace, `dppdp9_epo2.xsd`: „Výsledná částka na řádku 13
     * tabulky musí být shodná s částkou na ř. 40 II. oddílu."), jinak zkušební EPO
     * hlásí „Hodnota ř. 40 II. oddílu se nerovná hodnotě celkem přílohy A." Staví se
     * jen když je na ř. 40 nenulová částka (stejné pravidlo jako u `kc_ii50_40` ve
     * VetaO, viz buildVetaO) — žádná prázdná věta naslepo.
     *
     * @param array<string,mixed> $calc výstup DppoReturnCalculator::compute (nese lines)
     */
    private function buildVetaE(\DOMDocument $dom, array $calc): ?\DOMElement
    {
        $line40 = null;
        foreach (($calc['lines'] ?? []) as $line) {
            if ((int) ($line['line'] ?? 0) === 40) {
                $line40 = (int) round((float) $line['value']);
                break;
            }
        }
        if ($line40 === null || $line40 === 0) {
            return null;
        }

        $vetaE = $dom->createElement('VetaE');
        $vetaE->setAttribute('kc_dpp_a12', (string) $line40);

        return $vetaE;
    }

    /**
     * VetaR — zvláštní (textová) příloha k ř. 62 II. oddílu (§23), jeden řádek na
     * ruční položku z `manual_increase_items_line62` (viz DppoReturnCalculator::compute
     * — už vyfiltrované o paušál dopravy, který jde na ř. 40/VetaE). Bez ní zkušební
     * EPO hlásí „Zvláštní příloha ř. 62 II. odd. není vyplněna." Počet vrácených vět
     * se promítá do VetaD.zvl_pr (viz build()) — žádné položky = žádná VetaR a
     * zvl_pr zůstává 0, ne odhad.
     *
     * @param array<string,mixed> $calc výstup DppoReturnCalculator::compute
     * @return list<\DOMElement>
     */
    private function buildVetaR(\DOMDocument $dom, array $calc): array
    {
        $items = (array) ($calc['manual_increase_items_line62'] ?? []);
        $elements = [];
        $poradi = 1;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = trim((string) ($item['text'] ?? ''));
            $amount = (float) ($item['amount'] ?? 0);
            if ($text === '' && $amount === 0.0) {
                continue;
            }
            $label = $text !== ''
                ? $text . ' (' . number_format($amount, 0, ',', ' ') . ' Kč)'
                : number_format($amount, 0, ',', ' ') . ' Kč';

            $vetaR = $dom->createElement('VetaR');
            $vetaR->setAttribute('c_radku', '62');
            $vetaR->setAttribute('t_prilohy', mb_substr($label, 0, 72)); // XSD maxLength 72
            $vetaR->setAttribute('kod_sekce', '2'); // 2 = II. oddíl (XSD dokumentace)
            $vetaR->setAttribute('poradi', (string) $poradi);
            $elements[] = $vetaR;
            $poradi++;
        }

        return $elements;
    }

    /**
     * VetaF — příloha č. 1 II. oddílu, tabulka B (odpisy hmotného a nehmotného majetku).
     * Staví jen řádky, které jde vzít spolehlivě z karet majetku (Epic F3,
     * {@see DppoReturnDataProvider::depreciationByGroup}): ř. 1–6 = uplatněné daňové
     * odpisy hmotného majetku podle odpisové skupiny (assets.tax_group 1–6), ř. 10
     * (kc_dpp_b_onm) = daňové odpisy nehmotného majetku (§32a) a ř. 11 (kc_dppb6_b8) =
     * celkem tabulka B. Fotovoltaika dle §30b (ř. 9, kc_dpp_b_ohm_30_6) a účetní odpisy
     * majetku nevymezeného zákonem dle §24/2/v (ř. 12, kc_dpp_b10) systém neeviduje
     * samostatně — necháváme nevyplněné, ne odhadnuté.
     *
     * kc_dppb6_b8 = ř.11 celkem: zkušební EPO bez něj vytýká „Hodnota ř. 11 Př.1/B
     * II. oddílu není naplněna" i když jsou dílčí řádky 1–10 vyplněné správně; v obou
     * lokálně ověřených referenčních podáních (mimo repo) nese stejnou hodnotu jako
     * jediná vyplněná dílčí odpisová skupina, tedy součet, ne jen jeden z řádků.
     *
     * @param array<string,mixed> $calc výstup DppoReturnCalculator::compute (nese depreciation_by_group)
     * @param list<string>        $warnings
     */
    private function buildVetaF(\DOMDocument $dom, array $calc, array &$warnings): ?\DOMElement
    {
        $byGroup = (array) ($calc['depreciation_by_group'] ?? []);
        $tangible = (array) ($byGroup['tangible'] ?? []);
        $intangible = round((float) ($byGroup['intangible'] ?? 0.0), 2);
        $unclassified = round((float) ($byGroup['unclassified'] ?? 0.0), 2);

        // Řádky 1–6 tabulky B = odpisové skupiny 1–6 (assets.tax_group); XSD nepojmenovává
        // atributy podle skupiny 1:1 (jen kc_dpp_b6 má "b6" prefix, zbytek kc_dppbN).
        $groupAttr = [1 => 'kc_dppb1', 2 => 'kc_dppb2', 3 => 'kc_dppb3', 4 => 'kc_dppb4', 5 => 'kc_dppb5', 6 => 'kc_dpp_b6'];

        $vetaF = $dom->createElement('VetaF');
        $any = false;
        $total = 0.0;
        foreach ($groupAttr as $group => $attr) {
            $amount = round((float) ($tangible[$group] ?? 0.0), 2);
            if ($amount === 0.0) {
                continue;
            }
            $vetaF->setAttribute($attr, (string) (int) round($amount));
            $total = round($total + $amount, 2);
            $any = true;
        }
        if ($intangible !== 0.0) {
            $vetaF->setAttribute('kc_dpp_b_onm', (string) (int) round($intangible));
            $total = round($total + $intangible, 2);
            $any = true;
        }
        if ($any) {
            $vetaF->setAttribute('kc_dppb6_b8', (string) (int) round($total));
        }
        if ($unclassified !== 0.0) {
            $warnings[] = 'Daňové odpisy hmotného majetku ' . number_format($unclassified, 0, ',', ' ')
                . ' Kč nemají na kartě majetku vyplněnou odpisovou skupinu — do přílohy č. 1 II. oddílu '
                . '(tabulka B, VetaF) se nepromítly. Doplňte odpisovou skupinu na kartě majetku.';
        }

        return $any ? $vetaF : null;
    }

    /**
     * VetaA — přehled transakcí se spojenými osobami (§ 23 odst. 7 ZDP, převodní ceny),
     * dppdp9_epo2.xsd:4083, `maxOccurs="unbounded"` — jedna věta na spojenou osobu, žádný
     * atribut v XSD není `use="required"`, ale dokumentace `naz_spojos`/`stat_spojos` nese
     * vlastní kritickou kontrolu ("musí být vyplněn"/"musí být vyplněna") a zákaz duplicitní
     * dvojice (naz_spojos, stat_spojos) — v praxi tedy oba atributy vyplňujeme VŽDY, ne
     * podmíněně. Podklad: {@see DppoReturnDataProvider::relatedPartyAppendix()} (přes
     * DppoReturnCalculator::compute, klíč `related_party_appendix`) — objem transakcí PO
     * PROTISTRANĚ ze stejné množiny dokladů jako `spoj_zahr` (VetaD), aby si příznak a
     * příloha nikdy neodporovaly.
     *
     * Aplikace transakci NEKATEGORIZUJE podle vzoru přílohy (služby/licence/úroky/nájem/
     * úvěry/dlouhodobý majetek/zásoby/pohledávky-závazky/podíly na zisku — 30+ specifických
     * atributů) — na fakturách ani na přijatých dokladech není žádný takový druhový příznak.
     * Jediné pole, které bez odhadu sedí, je katalogové „ostatní transakce" (ost_trans_sl1 =
     * výnos, ost_trans_sl2 = náklad, v tis. Kč jako zbytek VetaA). Bezúplatná plnění (A/N),
     * záruky (A/N), cash-pooling (A/N) a stavy pohledávek/závazků appka neeviduje vůbec —
     * zůstávají v XML prázdné (nikdy '' ani 'N' naslepo, viz DANE-PODPORA-HRANICE.md zásada
     * „raději prázdný atribut a varování než odhadnutá hodnota"), varování níže to shrnuje.
     *
     * Dvojice (naz_spojos, stat_spojos) se dedupuje SEM — dva klientské záznamy stejné
     * protistrany (stejný název i stát, např. duplicitně založený kontakt) by jinak porušily
     * XSD kritickou kontrolu proti duplicitám; částky se v tom případě sečtou.
     *
     * @param array<string,mixed> $calc     výstup DppoReturnCalculator::compute (nese related_party_appendix)
     * @param list<string>        $warnings
     * @return list<\DOMElement>
     */
    private function buildVetaA(\DOMDocument $dom, array $calc, array &$warnings): array
    {
        $rows = (array) ($calc['related_party_appendix'] ?? []);
        if ($rows === []) {
            return [];
        }

        $merged = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $country = strtoupper(trim((string) ($row['country_iso2'] ?? '')));
            if ($name === '' || $country === '') {
                continue;
            }
            $key = mb_strtolower($name) . '|' . $country;
            if (!isset($merged[$key])) {
                $merged[$key] = ['name' => $name, 'country' => $country, 'ic' => '', 'issued' => 0.0, 'received' => 0.0];
            }
            $merged[$key]['issued'] += (float) ($row['issued_total'] ?? 0);
            $merged[$key]['received'] += (float) ($row['received_total'] ?? 0);
            $ic = trim((string) ($row['ic'] ?? ''));
            if ($merged[$key]['ic'] === '' && $ic !== '') {
                $merged[$key]['ic'] = $ic;
            }
        }

        $elements = [];
        foreach ($merged as $partner) {
            $sl1 = $this->toThousands($partner['issued']);
            $sl2 = $this->toThousands($partner['received']);
            if ($sl1 === 0 && $sl2 === 0) {
                continue;
            }
            $vetaA = $dom->createElement('VetaA');
            $vetaA->setAttribute('naz_spojos', mb_substr($partner['name'], 0, 255)); // XSD maxLength 255
            $vetaA->setAttribute('stat_spojos', $partner['country']);
            if ($partner['ic'] !== '') {
                $vetaA->setAttribute('ic_spojos', mb_substr($partner['ic'], 0, 20)); // XSD maxLength 20
            }
            if ($sl1 !== 0) {
                $vetaA->setAttribute('ost_trans_sl1', (string) $sl1);
            }
            if ($sl2 !== 0) {
                $vetaA->setAttribute('ost_trans_sl2', (string) $sl2);
            }
            $elements[] = $vetaA;
        }

        if ($elements !== []) {
            $warnings[] = 'Přehled transakcí se spojenými osobami (VetaA, ' . count($elements) . 'x) nese jen '
                . 'souhrn objemu transakcí za protistranu, rozdělený na výnos/náklad do pole „ostatní '
                . 'transakce" (ost_trans_sl1/sl2) — účetnictví nerozlišuje jednotlivé druhy podle vzoru '
                . 'přílohy (služby, licence, úroky, nájem, úvěry, dlouhodobý majetek, zásoby, '
                . 'pohledávky/závazky, podíly na zisku). Bezúplatná plnění, záruky, cash-pooling a stavy '
                . 'pohledávek/závazků appka neeviduje — tyto atributy zůstávají prázdné. Ověřte a doplňte '
                . 'ručně, je-li to pro danou spojenou osobu relevantní.';
        }

        return $elements;
    }

    /**
     * VetaNP — žádost o vrácení přeplatku (§155 daňového řádu). Staví se JEN když z
     * přiznání vyjde přeplatek (kc_v_4 = -balance_due > 0, viz VetaD výše) — bez ní si
     * poplatník o vrácení přeplatku vůbec nežádá, přeplatek jen zůstane na osobním
     * daňovém účtu. Bankovní spojení bere ze STEJNÉHO zdroje jako platební příkazy
     * ({@see \MyInvoice\Repository\PaymentOrderRepository::payerAccounts} — tabulka
     * `currencies`, výchozí CZK účet), přes {@see DppoReturnDataProvider::bankAccount}.
     * Zahraniční účet (zp_vrac='Z') systém nepodporuje — poplatník ho v appce nevede.
     *
     * @param array<string,mixed> $calc
     * @param list<string>        $warnings
     */
    private function buildVetaNP(\DOMDocument $dom, array $calc, array &$warnings): ?\DOMElement
    {
        $overpayment = (int) round(-(float) ($calc['balance_due'] ?? 0.0));
        if ($overpayment <= 0) {
            return null;
        }

        $account = $calc['bank_account'] ?? null;
        if (!is_array($account) || empty($account['account_number'])) {
            $warnings[] = 'Vznikl přeplatek ' . number_format($overpayment, 0, ',', ' ') . ' Kč, ale v Nastavení '
                . 'firmy chybí výchozí CZK bankovní účet — žádost o jeho vrácení (VetaNP) se do přiznání '
                . 'nedostala. Bez ní si poplatník o vrácení přeplatku nežádá; doplňte účet a přiznání '
                . 'vygenerujte znovu.';
            return null;
        }

        $accountNumber = (string) $account['account_number'];
        $prefix = AccountNumberNormalizer::czechAccountPrefix($accountNumber);
        $base = AccountNumberNormalizer::czechAccountBase($accountNumber);
        $bankCode = AccountNumberNormalizer::canonicalBankCode(
            isset($account['bank_code']) ? (string) $account['bank_code'] : null,
            isset($account['iban']) ? (string) $account['iban'] : null,
        );
        if ($base === null || $bankCode === null) {
            $warnings[] = 'Vznikl přeplatek ' . number_format($overpayment, 0, ',', ' ') . ' Kč, ale výchozí '
                . 'bankovní účet v Nastavení firmy nejde rozebrat na číslo účtu a kód banky — žádost o jeho '
                . 'vrácení (VetaNP) se do přiznání nedostala. Ověřte formát účtu v Nastavení firmy.';
            return null;
        }

        $vetaNP = $dom->createElement('VetaNP');
        $vetaNP->setAttribute('zp_vrac', 'U'); // U = na účet v ČR (zahraniční Z appka nevede)
        if ($prefix !== null) {
            $vetaNP->setAttribute('zvp_pbu', $prefix);
        }
        $vetaNP->setAttribute('zvp_c_komds', $base);
        $vetaNP->setAttribute('zvp_k_bank', $bankCode);
        $bankName = trim((string) ($account['bank_name'] ?? ''));
        if ($bankName !== '') {
            $vetaNP->setAttribute('zvp_naz_bank', mb_substr($bankName, 0, 30)); // XSD maxLength 30
        }
        $vetaNP->setAttribute('kc_preplatek', (string) $overpayment);

        return $vetaNP;
    }

    /** Zaokrouhlení Kč (haléře) na celé tisíce Kč — EPO konvence VetaUA/UB/UD (spec §1). */
    private function toThousands(float $czk): int
    {
        return (int) round($czk / 1000);
    }

    /**
     * @param array<string,mixed> $supplier
     * @param array<string,mixed> $representation výstup {@see TaxRepresentationService::at()}
     */
    private function buildVetaP(\DOMDocument $dom, array $supplier, array $representation = ['represented' => false]): \DOMElement
    {
        $vetaP = $dom->createElement('VetaP');

        // Územní pracoviště FÚ. Sdílený EpoSupplierBlockBuilder::fillVetaP() ho plní,
        // tahle vlastní kopie věty P na něj zapomněla — DPH přiznání ho tedy posílá,
        // přiznání k dani z příjmů ne, a zkušební EPO 30. 8. 2026 to vytklo („Číslo
        // územního pracoviště není vyplněno"). Hodnota v databázi přitom byla.
        if (!empty($supplier['workplace_code'])) {
            $vetaP->setAttribute('c_pracufo', (string) $supplier['workplace_code']);
        }
        $dic = EpoSupplierBlockBuilder::normalizeDic($supplier['dic'] ?? null);
        if ($dic !== '') {
            $vetaP->setAttribute('dic', $dic);
        }
        $ic = (string) ($supplier['ic'] ?? '');
        if ($ic !== '') {
            $vetaP->setAttribute('rod_c', $ic); // DPPDP9: IČO se plní do rod_c
        }
        $vetaP->setAttribute('zkrobchjm', (string) ($supplier['company_name'] ?? ''));

        [$ulice, $cpop, $corient] = $this->parseStreet($supplier);
        if ($ulice !== '') {
            $vetaP->setAttribute('ulice', $ulice);
        }
        if ($cpop !== '') {
            $vetaP->setAttribute('c_pop', $cpop);
        }
        if ($corient !== '') {
            $vetaP->setAttribute('c_orient', $corient);
        }
        $vetaP->setAttribute('naz_obce', (string) ($supplier['city'] ?? ''));
        $vetaP->setAttribute('psc', preg_replace('/\s/', '', (string) ($supplier['zip'] ?? '')) ?? '');
        $iso2 = (string) ($supplier['country_iso2'] ?? 'CZ');
        // Stát se vyplňuje JEN u zahraniční právnické osoby — u tuzemské ho zkušební
        // EPO 30. 8. 2026 vytklo jako propustnou chybu 300 („Kód státu vyplňují pouze
        // zahraniční právnické osoby"). Tuzemskému poplatníkovi tedy oba atributy
        // vynecháváme; prázdné se neposílají vůbec, ne jako prázdný řetězec.
        $foreign = $iso2 !== '' && strtoupper($iso2) !== 'CZ';
        if ($foreign) {
            $vetaP->setAttribute('k_stat', $iso2);
        }
        // `stat` = NÁZEV státu z číselníku Země, NE ISO2 kód — to je přesně chyba #201,
        // opravená v EpoSupplierBlockBuilder, ale v téhle větvi přežila: zahraniční
        // subjekt sem dostal 'SK' místo 'SLOVENSKO' a i tuzemský 'Česká republika'
        // místo číselníkového 'ČESKÁ REPUBLIKA'. Číselník je jediný zdroj pravdy;
        // neznámou zemi raději vynecháme (atribut je optional) než poslat neplatnou hodnotu.
        $statName = EpoSupplierBlockBuilder::countryName($iso2);
        if ($foreign && $statName !== null) {
            $vetaP->setAttribute('stat', $statName);
        }

        if (!empty($supplier['phone'])) {
            $vetaP->setAttribute('c_telef', EpoSupplierBlockBuilder::normalizePhone((string) $supplier['phone']));
        }
        // Oprávněná osoba (jednatel) — povinné pro EPO podání PO, ALE JEN když
        // podepisující osobou není fyzická osoba zástupce (zast_typ='F'): zkušební
        // EPO to vytýká („Je-li podepisující osobou fyzická osoba, pak se jméno
        // oprávněné osoby nevyplňuje") a reálné referenční podání se zast_typ='F'
        // opr_jmeno/opr_prijmeni/opr_postaveni skutečně nemá vůbec — podepisující
        // osobou je tam poradce (zast_*), ne jednatel (opr_*), oba naráz EPO odmítá.
        // Zastoupení právnickou osobou (zast_typ='P') opr_* naopak potřebuje —
        // identifikuje fyzickou osobu, která jménem té poradenské firmy podepisuje.
        $signerIsRepresentativeNaturalPerson = !empty($representation['represented'])
            && ($representation['type'] ?? null) === 'F';
        if (!$signerIsRepresentativeNaturalPerson) {
            if (!empty($supplier['opr_jmeno'])) {
                $vetaP->setAttribute('opr_jmeno', (string) $supplier['opr_jmeno']);
            }
            if (!empty($supplier['opr_prijmeni'])) {
                $vetaP->setAttribute('opr_prijmeni', (string) $supplier['opr_prijmeni']);
            }
            if (!empty($supplier['opr_postaveni'])) {
                $vetaP->setAttribute('opr_postaveni', (string) $supplier['opr_postaveni']);
            }
        }

        EpoSupplierBlockBuilder::fillRepresentationAttributes($vetaP, $representation);

        return $vetaP;
    }

    /**
     * @param array<string,mixed> $calc
     * @param string              $zdobdDo konec zdaňovacího období (dd.mm.rrrr) pro d_hospvysl
     */
    private function buildVetaO(\DOMDocument $dom, array $calc, int $year, string $zdobdDo = ''): \DOMElement
    {
        $vetaO = $dom->createElement('VetaO');

        $values = [];
        foreach (($calc['lines'] ?? []) as $line) {
            $values[(int) $line['line']] = (float) $line['value'];
        }

        foreach (self::LINE_ATTR as $lineNo => $attr) {
            if (!array_key_exists($lineNo, $values)) {
                continue;
            }
            $val = (int) round($values[$lineNo]);
            if (in_array($lineNo, [250, 270, 290, 310, 340, 360], true)) {
                $val = max(0, $val);
            }
            if ($val === 0 && !in_array($lineNo, self::ALWAYS, true)) {
                continue;
            }
            $vetaO->setAttribute($attr, (string) $val);
        }

        // ř.220 základ daně (v1 bez úprav ř.201/210 = ř.200) a sazba ř.280.
        if (array_key_exists(200, $values)) {
            $vetaO->setAttribute('kc_ii_220', (string) (int) round($values[200]));
        }
        $rate = (float) ($calc['summary']['rate'] ?? 0);
        if ($rate <= 0) {
            // Chybějící/nulová sazba ve $calc znamená volajícího, který přeskočil
            // DppoReturnCalculator — dosaď sazbu § 21 ZDP platnou pro dané zdaňovací
            // období z roční sady, ne natvrdo dnešní hodnotu.
            $rate = self::corporateTaxRateFor($year);
        }
        $vetaO->setAttribute('kc_ii270_280', (string) (int) round($rate * 100));

        // kc_ii320_330 — daň (shodná s kc_ii280_290/ř.290). XSD kritická kontrola: nesmí
        // být vyplněna, je-li na ř. 220 vykázána daňová ztráta — proto jen když daň > 0.
        if (array_key_exists(290, $values)) {
            $danRadek290 = max(0, (int) round($values[290]));
            if ($danRadek290 > 0) {
                $vetaO->setAttribute('kc_ii320_330', (string) $danRadek290);
            }
        }
        // d_hospvysl — datum, ke kterému se vztahuje výsledek hospodaření (konec ZO).
        if ($zdobdDo !== '') {
            $vetaO->setAttribute('d_hospvysl', $zdobdDo);
        }

        return $vetaO;
    }

    /**
     * § 21 ZDP sazba DPPO pro dané zdaňovací období — nouzový fallback, kdyby $calc
     * nenesla dopočtenou sazbu (běžně dodá {@see DppoReturnCalculator}). Builder nemá DB
     * závislost, čte proto přímo {@see TaxConstants} (bez admin override z `tax_constants`);
     * rok mimo pokrytou sadu spadne na nejbližší dřívější známý rok, ať fallback nikdy
     * neshodí generování podání výjimkou.
     */
    private static function corporateTaxRateFor(int $year): float
    {
        $years = TaxConstants::availableYears();
        if (!in_array($year, $years, true)) {
            $below = array_filter($years, static fn (int $y): bool => $y < $year);
            $year = $below !== [] ? max($below) : min($years);
        }

        return (float) (TaxConstants::forYear($year)['corporate_tax_rate'] ?? 0.21);
    }

    /** ISO YYYY-MM-DD → dd.mm.rrrr (EPO dateInMultiFormat, shodně se zdobd_od/do); '' když neplatné. */
    private function formatDate(mixed $v): string
    {
        $s = trim((string) $v);
        if ($s === '') {
            return '';
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($s, 0, 10));
        return $d === false ? '' : $d->format('d.m.Y');
    }

    /**
     * Rozparsuje ulici na [ulice, č.p., č.o.]. Komentář tu dřív tvrdil „Odpovídá logice
     * EpoSupplierBlockBuilder" — což byla shoda náhodou, ne kontrakt; teď tam metoda
     * skutečně DELEGUJE ({@see EpoSupplierBlockBuilder::parseStreet}).
     *
     * @param array<string,mixed> $supplier
     * @return array{0:string,1:string,2:string}
     */
    private function parseStreet(array $supplier): array
    {
        return EpoSupplierBlockBuilder::parseStreet($supplier);
    }
}
