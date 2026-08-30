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
     * section AKTIVA) → `c_radku` EPO tiskopisu. Jen mikro-ÚJ ověřené řádky, číselně
     * ověřeno proti dvěma reálně podaným přiznáním (viz private/APPENDIX-XML-MAPPING-SPEC.md
     * §1.1). Hlubší úrovně (level ≥ 2) NEJSOU ověřené a záměrně chybí (spec §7.a) —
     * doplnit až po dohledání oficiálního tiskopisu „Rozvaha v plném rozsahu".
     */
    private const AKTIVA_C_RADKU = [
        ['AKTIVA', 1],
        ['B.', 3],
        ['C.', 37],
        ['D.', 74],
    ];

    /**
     * Příloha — mapa `row_code` (FinancialStatementService::incomeStatement) → `c_radku`
     * EPO tiskopisu VZZ (druhové členění, plný rozsah) — kompletně ověřeno, 27 řádků vč.
     * 4 computed mezisoučtů (spec §3). `VI.` se v tiskopisu tiskne dvakrát (ř.39 „VI." celkem,
     * ř.41 „VI.2. Ostatní") — náš statement_rows seed nerozlišuje VI.1./VI.2. (spec §3 pozn.,
     * §7.d): VI.1. (úroky od ovládané/ovládající osoby) je v ověřených datech vždy 0 a nemáme
     * jej v chart_of_accounts odděleně, proto oba řádky čerpají ze stejné hodnoty `VI.`.
     * Neověřené řádky (v obou letech vždy nulové, tudíž bez kotvy: B., C., E.1.2., E.2., E.3.,
     * III., III.1-3., F.1., F.2., F.4., IV., G., V., H., I.n, J.) záměrně chybí (spec §7.a).
     *
     * `L.2.` (odložená daň) mezi nimi BÝVALO — a to jen proto, že o odložené dani systém
     * neúčtoval, takže řádek vycházel vždy nulový. Po doplnění kroku uzávěrky (ČÚS 003,
     * 592/481) už nulový být nemusí, takže kotvu má: bez ní by se zaúčtovaná odložená daň
     * do přílohy přiznání vůbec nepřenesla.
     */
    private const VZZ_C_RADKU = [
        ['I.', 1], ['A.', 3], ['A.2.', 5], ['A.3.', 6],
        ['D.', 9], ['D.1.', 10], ['D.2.', 11], ['D.2.1.', 12], ['D.2.2.', 13],
        ['E.', 14], ['E.1.', 15], ['E.1.1.', 16],
        ['F.', 24], ['F.3.', 27], ['F.5.', 29],
        ['PVH', 30],
        ['VI.', 39], ['VI.', 41],
        ['VII.', 46], ['K.', 47],
        ['FVH', 48], ['VHPZ', 49],
        ['L.', 50], ['L.1.', 51], ['L.2.', 52],
        ['VHPO', 53], ['VH', 55], ['OBRAT', 56],
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
     *   volitelně `poc_zam` (viz {@see buildVetaS} — přebije hodnotu ze $appendix['settings'])
     * @param array<string,mixed> $appendix Příloha účetní závěrky (Epic DP — VetaUA/UB/UD/UZ,
     *   volitelné): {balance_sheet: array (FinancialStatementService::balanceSheet výstup),
     *   income_statement: array (…::incomeStatement výstup), category: array
     *   (EntityCategoryService::evaluate výstup), settings: array
     *   (AccountingSupplierSettingsRepository::get výstup)}. Prázdné (default) = appendix se
     *   nevygeneruje (zpětná kompatibilita se stávajícími voláními/testy).
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
        $vetaD->setAttribute('typ_popldpp', (string) ($meta['typ_popldpp'] ?? '1'));  // 1 = ostatní poplatník
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

        // zvl_pr — počet zvláštních příloh: systém žádnou negeneruje (žádný volný text
        // k řádkům jako ř. 62 II. oddílu), proto konstantně 0, ne odhad. Reálně podané
        // přiznání ho má také (buď 0, nebo počet, který ručně přidal daňový poradce) —
        // atribut vyplňuje EPO samo, ale bez explicitní hodnoty ho vytýká jako chybějící.
        $vetaD->setAttribute('zvl_pr', '0');

        // spoj_zahr — § 23 odst. 7 ZDP, transakce se spojenou osobou (tuzemskou/zahraniční)
        // z faktur označených clients.related_party (viz DppoReturnDataProvider). 'N', když
        // se v období žádné takové transakce nevyskytly.
        $relatedPartyFlag = (string) ($calc['related_party_country_flag'] ?? 'N');
        if (in_array($relatedPartyFlag, ['N', 'T', 'Z', 'A'], true)) {
            $vetaD->setAttribute('spoj_zahr', $relatedPartyFlag);
        }

        // dan_por — zpracoval a podává přiznání daňový poradce na plnou moc (§29/2 DŘ)?
        // Systém plnou moc neeviduje, přiznání staví a podává přímo poplatník přes appku,
        // proto konstantně 'N' (kdyby bylo 'A', EPO by navíc vyžadovalo údaje o poradci
        // v I. oddílu, které nemáme).
        $vetaD->setAttribute('dan_por', 'N');

        // ── VetaF — příloha č. 1 II. oddílu, tabulka B (odpisy) — musí být hotová dřív
        // než se p_pr_2od zapíše na VetaD (počet příloh II. oddílu = kolik z VetaE/F/G
        // se skutečně vygenerovalo; VetaE/G nestavíme, takže 0 nebo 1).
        $vetaF = $this->buildVetaF($dom, $calc, $warnings);
        $vetaD->setAttribute('p_pr_2od', (string) ($vetaF !== null ? 1 : 0));

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
        $root->appendChild($this->buildVetaP($dom, $supplier));

        // ── VetaO — řádky II. oddílu ────────────────────────────────────────
        $root->appendChild($this->buildVetaO($dom, $calc, $year, $zdobdDo));

        // ── VetaF — tabulka B (odpisy), viz buildVetaF; XSD sekvence ji chce hned
        // za VetaO/VetaU (VetaE), před VetaM.
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

        // ── Příloha účetní závěrky (Epic DP) — VetaUA (aktiva) + VetaUB (VZZ) +
        // VetaUD (pasiva) + VetaUZ (sbírka listin). XSD sekvence vyžaduje přesně toto
        // pořadí bloků (ověřeno na reálném podání: …VetaUA*, VetaUB*, VetaUD*, VetaUZ).
        if ($hasAppendix) {
            $balanceSheet = (array) $appendix['balance_sheet'];
            $incomeStatement = (array) $appendix['income_statement'];
            foreach ($this->buildVetaUA($dom, $balanceSheet) as $el) {
                $root->appendChild($el);
            }
            foreach ($this->buildVetaUB($dom, $incomeStatement) as $el) {
                $root->appendChild($el);
            }
            foreach ($this->buildVetaUD($dom, $balanceSheet) as $el) {
                $root->appendChild($el);
            }
            $root->appendChild($this->buildVetaUZ(
                $dom,
                (array) ($appendix['settings'] ?? []),
                $supplier,
            ));
        }

        // ── VetaNP — žádost o vrácení přeplatku (§155 DŘ) — poslední věta před Přílohy,
        // staví se jen když z přiznání vyjde přeplatek (viz buildVetaNP).
        $vetaNP = $this->buildVetaNP($dom, $calc, $warnings);
        if ($vetaNP !== null) {
            $root->appendChild($vetaNP);
        }

        return ['xml' => $dom->saveXML() ?: '', 'warnings' => $warnings];
    }

    /**
     * Rozšíří VetaD o metadata účetní závěrky (spec §5) — jen když appendix skutečně
     * generujeme (aditivní, zpětně kompatibilní). Kódy `kat_uj`/`uv_rozsah_rozv` pro
     * small/medium/large NEJSOU ověřené (spec §7.f) — nastaví se jen pro kategorii 'micro'.
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

        if ((string) ($category['category'] ?? '') === 'micro') {
            $vetaD->setAttribute('kat_uj', 'M');
            $vetaD->setAttribute('uv_rozsah_rozv', 'M');
        }
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
        $vetaD->setAttribute('sam_pr', '0'); // „samostatné přílohy" (zápočet daně ze zahraničí) mimo rozsah
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
            if ($netto === 0 && $nettoMin === 0) {
                continue;
            }
            $el = $dom->createElement('VetaUA');
            $el->setAttribute('c_radku', (string) $cRadku);
            $el->setAttribute('kc_brutto', (string) $this->toThousands((float) $row['gross']));
            $el->setAttribute('kc_korekce', (string) $this->toThousands((float) $row['correction']));
            $el->setAttribute('kc_netto', (string) $netto);
            $el->setAttribute('kc_netto_min', (string) $nettoMin);
            $elements[] = $el;
        }
        return $elements;
    }

    /**
     * VetaUB — výkaz zisku a ztráty (spec §3). Řádek se vypíše, jen když sledované ≠ 0
     * NEBO minulé ≠ 0; hodnoty v celých tisících Kč.
     *
     * @param array<string,mixed> $incomeStatement výstup FinancialStatementService::incomeStatement()
     * @return list<\DOMElement>
     */
    private function buildVetaUB(\DOMDocument $dom, array $incomeStatement): array
    {
        $byCode = [];
        foreach ((array) ($incomeStatement['rows'] ?? []) as $row) {
            $byCode[(string) $row['row_code']] = $row;
        }

        $elements = [];
        foreach (self::VZZ_C_RADKU as [$rowCode, $cRadku]) {
            $row = $byCode[$rowCode] ?? null;
            if ($row === null) {
                continue;
            }
            $sled = $this->toThousands((float) $row['amount']);
            $min = $this->toThousands((float) $row['prev_amount']);
            if ($sled === 0 && $min === 0) {
                continue;
            }
            $el = $dom->createElement('VetaUB');
            $el->setAttribute('c_radku', (string) $cRadku);
            $el->setAttribute('kc_min', (string) $min);
            $el->setAttribute('kc_sled', (string) $sled);
            $elements[] = $el;
        }
        return $elements;
    }

    /**
     * VetaUD — rozvaha PASIVA (spec §2). Vlastní jmenný prostor `c_radku` od 1 (nenavazuje
     * na AKTIVA). Řádek `c_radku=24` „B.+C. Cizí zdroje" v našem `statement_rows` neexistuje
     * jako samostatný uzel (spec §2.1, §7.e) — musí se dopočítat P.B.+P.C. za běhu.
     *
     * @param array<string,mixed> $balanceSheet výstup FinancialStatementService::balanceSheet()
     * @return list<\DOMElement>
     */
    private function buildVetaUD(\DOMDocument $dom, array $balanceSheet): array
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
            $el = $dom->createElement('VetaUD');
            $el->setAttribute('kc_sled', (string) $sledT);
            $el->setAttribute('c_radku', (string) $cRadku);
            $el->setAttribute('kc_min', (string) $minT);
            $elements[] = $el;
        }
        return $elements;
    }

    /**
     * VetaUZ — žádost o předání účetní závěrky do sbírky listin veřejného rejstříku
     * (spec §4). Ne totéž jako „co je součástí přiznání" — rozvaha se předává vždy,
     * VZZ jen u ÚJ s povinným auditem (přesné pravidlo pro small/medium bez auditu
     * k ověření, spec §7.g). `pr11_puz` (příloha k účetní závěrce, volný text/soubor) je
     * feature, kterou zatím nemáme (spec §6.d) — vždy 'N', dokud nebude implementována.
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

    /** @param array<string,mixed> $supplier */
    private function buildVetaP(\DOMDocument $dom, array $supplier): \DOMElement
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
        // Oprávněná osoba (jednatel) — povinné pro EPO podání PO.
        if (!empty($supplier['opr_jmeno'])) {
            $vetaP->setAttribute('opr_jmeno', (string) $supplier['opr_jmeno']);
        }
        if (!empty($supplier['opr_prijmeni'])) {
            $vetaP->setAttribute('opr_prijmeni', (string) $supplier['opr_prijmeni']);
        }
        if (!empty($supplier['opr_postaveni'])) {
            $vetaP->setAttribute('opr_postaveni', (string) $supplier['opr_postaveni']);
        }

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
