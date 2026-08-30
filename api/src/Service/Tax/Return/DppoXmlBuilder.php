<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

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
     * @param array<string,mixed> $supplier row ze supplier (loadSupplier v service)
     * @param array<string,mixed> $calc     výstup DppoReturnCalculator::compute
     * @param array<string,mixed> $meta     verzeSW, typ_dapdpp, dapdpp_forma, typ_zo, typ_popldpp
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

        $creditsEntitlement = max(0, (int) round((float) ($calc['summary']['credits_entitlement'] ?? 0)));
        if ($creditsEntitlement > 0) {
            $vetaM = $dom->createElement('VetaM');
            $vetaM->setAttribute('kc_dpp_f1', (string) max(0, (int) round((float) ($calc['summary']['disabled_employee_credit_amount'] ?? 0))));
            $vetaM->setAttribute('kc_dpp_f2', (string) max(0, (int) round((float) ($calc['summary']['disabled_employee_severe_credit_amount'] ?? 0))));
            $vetaM->setAttribute('kc_dpp_f4', (string) $creditsEntitlement);
            $root->appendChild($vetaM);
        }

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
