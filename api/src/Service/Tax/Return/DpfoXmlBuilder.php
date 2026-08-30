<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Report\EpoEnvelope;
use MyInvoice\Service\Report\EpoSupplierBlockBuilder;

/**
 * Generátor EPO XML formuláře DPFDP7 (daň z příjmů FO) — Epic DP (issue #18).
 *
 * Struktura dle `api/xsd/dpfdp7_epo2.xsd` (bez namespace, xs:sequence — pořadí vět!):
 *   Pisemnost(nazevSW,verzeSW) > DPFDP7(verzePis) > VetaD, VetaP, VetaO, VetaS, VetaA,
 *   VetaB, VetaT, Vetac, VetaU, VetaN
 *
 * - VetaD: povinné (fixní k_uladis="DPF", dokument="DP7", rok, dap_typ, c_ufo_cil,
 *   pln_moc, audit) + slevy/zvýhodnění/zálohy/doplatek (kc_op15_1a, da_slevy, kc_danbonus,
 *   kc_zbyvpred…).
 * - VetaP: identifikace FO (jmeno/prijmeni, rod_c, dic, adresa).
 * - VetaO: dílčí základy §6–§10 + úhrn + základ daně.
 * - VetaS: §15 nezdanitelné části + daň (da_dan16 se 2 desetinnými místy).
 * - VetaB: příznaky vložených příloh (priloha1 — Příloha 1 §7, staví se vždy s VetaT).
 * - VetaT: Příloha 1 §7 (kc_prij7, kc_vyd7, kc_zd7p, vyd7proc, pr_sazba).
 * - VetaN: žádost o vrácení přeplatku (jen když vyjde přeplatek, {@see buildVetaN}).
 *
 * Hodnoty mapuje z výstupu {@see DpfoReturnCalculator} (klíč fields + s7 + bank_account).
 */
final class DpfoXmlBuilder
{
    private const VERZE_PIS = '07.01';

    /** field → Veta (int Kč jako string; da_dan16 se řeší zvlášť). */
    private const VETA_O = ['kc_prij6', 'kc_zd6', 'kc_zd6p', 'kc_zd7', 'kc_zakldan8', 'kc_zd9', 'kc_zd10', 'kc_uhrn', 'kc_ztrata2', 'kc_zakldan', 'kc_zakldan23'];
    private const VETA_S_INT = ['kc_op15_8', 'kc_op28_5', 'kc_op15_12', 'kc_op15_13', 'kc_op15_inpr', 'kc_op15_pece', 'kc_odcelk', 'kc_zdsniz', 'kc_zdzaokr'];
    private const VETA_D_FIELDS = ['kc_op15_1a', 'kc_op15_1c', 'kc_op15_1d', 'kc_op15_1e1', 'kc_op15_1e2', 'uhrn_slevy35ba', 'da_slevy', 'da_slevy35ba', 'da_slevy35c', 'kc_dazvyhod', 'kc_slevy35c', 'kc_danbonus', 'kc_dan_po_db', 'kc_dan_celk', 'da_slezap', 'da_celod13', 'kc_zalzavc', 'kc_zalpred', 'kc_zbyvpred', 'm_invduch', 'm_cinvduch', 'm_ztpp', 'm_manz', 'kc_dztrata', 'kc_manztpp'];

    /**
     * Slevy a měsíční počty, které se při nule vynechávají — viz komentář u jejich
     * plnění níže. Jde výhradně o položky, jejichž hodnotu EPO kontroluje vzorcem
     * „počet měsíců × sazba"; součtové a daňové řádky se plní i nulou, protože na
     * nich naopak stojí křížové kontroly.
     */
    private const VETA_D_OMIT_WHEN_ZERO = [
        'kc_op15_1c', 'kc_op15_1d', 'kc_op15_1e1', 'kc_op15_1e2', 'kc_manztpp',
        'kc_dazvyhod', 'kc_slevy35c', 'kc_danbonus',
        'm_manz', 'm_ztpp', 'm_invduch', 'm_cinvduch',
    ];

    /**
     * @param array<string,mixed> $supplier
     * @param array<string,mixed> $calc  výstup DpfoReturnCalculator::compute
     * @param array<string,mixed> $meta  volitelně `representation` (výstup {@see TaxRepresentationService::at()},
     *   jinak 'N' bez zástupce) a `pln_moc` (explicitní přebití, BC)
     * @return array{xml:string,warnings:list<string>}
     */
    public function build(array $supplier, int $year, array $calc, array $meta = []): array
    {
        $warnings = [];
        $fields = (array) ($calc['fields'] ?? []);
        $s7 = (array) ($calc['s7'] ?? []);

        [$dom, $root] = EpoEnvelope::create(
            'DPFDP7',
            (string) ($meta['verze_pis'] ?? self::VERZE_PIS),
            isset($meta['verze_sw']) ? (string) $meta['verze_sw'] : null,
        );

        // ── VetaD — hlavička (povinné) + slevy/zálohy/doplatek ──────────────
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'DPF');   // fixní
        $vetaD->setAttribute('dokument', 'DP7');    // fixní
        $vetaD->setAttribute('rok', (string) $year);
        $vetaD->setAttribute('dap_typ', (string) ($meta['dap_typ'] ?? 'B')); // B = řádné
        $vetaD->setAttribute('c_ufo_cil', (string) ($supplier['financial_office_code'] ?: '451'));
        // pln_moc — podal DAP daňový poradce na plnou moc? Čte se z evidence zastoupení
        // (TaxRepresentationService) k datu $meta['representation_date'], stejně jako
        // DPPO `dan_por` (viz DppoXmlBuilder — sdílené zdůvodnění datování). Explicitní
        // $meta['pln_moc'] má přednost (BC pro volající, které si hodnotu dosazují samy).
        $representation = (array) ($meta['representation'] ?? ['represented' => false]);
        $vetaD->setAttribute('pln_moc', (string) ($meta['pln_moc'] ?? EpoSupplierBlockBuilder::representationFlag($representation)));
        $vetaD->setAttribute('audit', (string) ($meta['audit'] ?? 'N'));
        $vetaD->setAttribute('zdobd_od', sprintf('1.1.%04d', $year));
        $vetaD->setAttribute('zdobd_do', sprintf('31.12.%04d', $year));
        foreach (self::VETA_D_FIELDS as $f) {
            if (!array_key_exists($f, $fields)) {
                continue;
            }
            // Nulovou slevu a nulový počet měsíců do podání NEPOSÍLÁME.
            // Zkušební EPO 31. 8. 2026: s `kc_op15_1c="0"` a `m_manz="0"` (nulová
            // sleva na manžela za nula měsíců) hlásilo, že ř.65a neodpovídá vzorci
            // „počet měsíců × 2 070" — přestože 0 = 0 × 2 070. Po vynechání obou
            // atributů výtka zmizela a objevila se táž hláška o ř.65b, tedy o dalším
            // nulovém páru; po vynechání všech nulových slev a měsíců zmizely ř.65a
            // i ř.72. Úřad nulu nečte jako „nic", ale jako vyplněný údaj, který musí
            // projít kontrolou — prázdný a chybějící atribut nejsou totéž. Lokální
            // XSD tuhle třídu chyb nechytí, nulu klidně povolí.
            if (in_array($f, self::VETA_D_OMIT_WHEN_ZERO, true) && (float) $fields[$f] === 0.0) {
                continue;
            }
            $vetaD->setAttribute($f, $this->int($fields[$f]));
        }
        $family = (array) ($calc['family'] ?? []);
        $spouse = is_array($family['spouse'] ?? null) ? $family['spouse'] : null;
        if ($spouse !== null) {
            $vetaD->setAttribute('manz_jmeno', (string) ($spouse['first_name'] ?? ''));
            $vetaD->setAttribute('manz_prijmeni', (string) ($spouse['last_name'] ?? ''));
            if (!empty($spouse['birth_number'])) {
                $vetaD->setAttribute('manz_r_cislo', preg_replace('/\D/', '', (string) $spouse['birth_number']) ?? '');
            } elseif (!empty($spouse['birth_date'])) {
                $vetaD->setAttribute('manz_d_nar', $this->formatDate($spouse['birth_date']));
            }
            // m_manz se plní vždy (viz VETA_D_FIELDS výše, fields['m_manz']), i bez identity —
            // EPO ověřuje ř.65a jako počet měsíců × sazba nezávisle na tom.
            // m_ztpp patří poplatníkovi (viz VETA_D_FIELDS výše), ne manželovi/manželce —
            // ZTP/P manžela zdvojnásobuje kc_op15_1c už v Kč (DpfoCalculator::spouseCreditFromClaim),
            // XSD pro manžela žádné samostatné pole měsíců ZTP/P nemá.
        }

        // Dodatečné/opravné přiznání (dap_typ = O/D/E). U dodatečného (D/E) datum
        // zjištění důvodů (§141 DŘ, XSD kritická kontrola). DPFDP7 se podává s NOVÝMI
        // (správnými) hodnotami; rozdíl řeší správce daně. POZOR: DPFDP7 NEMÁ ve větě
        // pole pro textové důvody dodatečného — ty se přikládají jako e-příloha (řeší
        // uživatel při odeslání). `duvpoddapdpf` je jednoznakový KÓD (G/I, §239b/§245 DŘ),
        // NE volný text — proto ho z důvodů podání NEplníme.
        $dapTyp = (string) ($meta['dap_typ'] ?? 'B');
        if (in_array($dapTyp, ['D', 'E'], true)) {
            $dZjist = $this->formatDate($meta['d_zjist'] ?? null);
            if ($dZjist !== '') {
                $vetaD->setAttribute('d_zjist', $dZjist);
            } else {
                $warnings[] = 'Dodatečné přiznání: chybí datum zjištění důvodů (d_zjist) — EPO ho vyžaduje.';
            }
        }
        $root->appendChild($vetaD);

        if (empty($supplier['financial_office_code'])) {
            $warnings[] = 'Chybí kód finančního úřadu — použit fallback 451; ověřte v Nastavení firmy.';
        }

        // da_slevy je daň PO uplatnění slev (mezisoučet), ne částka slevy na manžela —
        // sleva na manžela/manželku samotná je kc_op15_1c (viz DpfoReturnCalculator).
        if ((float) ($fields['kc_op15_1c'] ?? 0) > 0 && $spouse === null) {
            $warnings[] = 'Uplatněna sleva na manžela/manželku (kc_op15_1c), ale chybí jeho identifikace — EPO ji bez identity odmítne, doplňte před podáním.';
        }
        if (((float) ($fields['kc_dazvyhod'] ?? 0) > 0 || (float) ($fields['kc_danbonus'] ?? 0) > 0)
            && (array) ($family['children'] ?? []) === []) {
            $warnings[] = 'Uplatněno daňové zvýhodnění / bonus na děti, ale chybí identifikace dětí a měsíce — EPO je bez identity odmítne, doplňte před podáním.';
        }

        // ── VetaP — poplatník FO ─────────────────────────────────────────────
        $root->appendChild($this->buildVetaP($dom, $supplier, $warnings, $representation));

        // ── VetaO — dílčí základy ────────────────────────────────────────────
        $vetaO = $dom->createElement('VetaO');
        foreach (self::VETA_O as $f) {
            if (array_key_exists($f, $fields)) {
                $vetaO->setAttribute($f, $this->int($fields[$f]));
            }
        }
        $root->appendChild($vetaO);

        // ── VetaS — §15 + daň ────────────────────────────────────────────────
        $vetaS = $dom->createElement('VetaS');
        foreach (self::VETA_S_INT as $f) {
            if (array_key_exists($f, $fields)) {
                $vetaS->setAttribute($f, $this->int($fields[$f]));
            }
        }
        if (array_key_exists('da_dan16', $fields)) {
            // XSD dovoluje 2 desetinná místa, ale § 16 ZDP se zaokrouhluje na celé Kč
            // nahoru (DpfoReturnCalculator::tax16 = ceil(...)) — hodnota je vždy celá.
            $vetaS->setAttribute('da_dan16', $this->int($fields['da_dan16']));
        }
        $root->appendChild($vetaS);

        foreach ((array) ($family['children'] ?? []) as $child) {
            if (!is_array($child)) {
                continue;
            }
            $vetaA = $dom->createElement('VetaA');
            $vetaA->setAttribute('vyzdite_jmeno', (string) ($child['first_name'] ?? ''));
            $vetaA->setAttribute('vyzdite_prijmeni', (string) ($child['last_name'] ?? ''));
            if (!empty($child['birth_number'])) {
                $vetaA->setAttribute('vyzdite_r_cislo', preg_replace('/\D/', '', (string) $child['birth_number']) ?? '');
            } elseif (!empty($child['birth_date'])) {
                $vetaA->setAttribute('vyzdite_d_nar', $this->formatDate($child['birth_date']));
            }
            $months = [1 => [0, 0], 2 => [0, 0], 3 => [0, 0]];
            foreach ((array) ($child['months'] ?? []) as $month) {
                if (!is_array($month) || empty($month['claimed'])) {
                    continue;
                }
                $order = max(1, min(3, (int) ($month['order'] ?? 1)));
                $months[$order][!empty($month['ztpp']) ? 1 : 0]++;
            }
            $vetaA->setAttribute('vyzdite_pocmes', (string) $months[1][0]);
            $vetaA->setAttribute('vyzdite_ztpp', (string) $months[1][1]);
            $vetaA->setAttribute('vyzdite_pocmes2', (string) $months[2][0]);
            $vetaA->setAttribute('vyzdite_ztpp2', (string) $months[2][1]);
            $vetaA->setAttribute('vyzdite_pocmes3', (string) $months[3][0]);
            $vetaA->setAttribute('vyzdite_ztpp3', (string) $months[3][1]);
            $root->appendChild($vetaA);
        }

        // ── VetaB — příznaky vložených příloh ────────────────────────────────
        // XSD (priloha1, kritická kontrola): "pokud je vyplněna hodnota kc_zd7 věty O,
        // musí být naplněny položky věty T pro Přílohu č. 1 a položka priloha1 musí
        // být naplněna hodnotou 1." VetaO.kc_zd7 a VetaT se níže staví VŽDY (ne jen
        // podmíněně), takže příznak odpovídá tomu, co se do XML skutečně dostává —
        // ne co by teoreticky mohlo (priloha2/VetaV se nestaví, proto ho neoznačujeme).
        if (array_key_exists('kc_zd7', $fields)) {
            $vetaB = $dom->createElement('VetaB');
            $vetaB->setAttribute('priloha1', '1');
            $root->appendChild($vetaB);
        }
        // Zkušební EPO 30. 8. 2026 (po zavedení VetaB výše) nově hlásí: „Jsou vykázány
        // příjmy ze ZČ a v tabulce příloh není vloženo potvrzení od zaměstnavatele/ů"
        // (VetaB.potv_zam) — dřív se to neprojevilo, protože VetaB vůbec neexistovala.
        // Appka žádné e-přílohy (scan potvrzení) nepřikládá (viz VetaB výše, priloha2/
        // Prilohy mimo rozsah) — potv_zam=1 bez skutečně vloženého dokladu by byla lež,
        // proto jen varujeme, ať to poplatník doloží v EPO portálu sám.
        if ((float) ($fields['kc_prij6'] ?? 0) > 0) {
            $warnings[] = 'Vykázán příjem ze závislé činnosti (§6) — EPO u něj vyžaduje přiložené '
                . 'potvrzení od zaměstnavatele (Příloha, VetaB.potv_zam); appka scan/PDF potvrzení '
                . 'nepřikládá, doplňte ho ručně v portálu EPO před podáním.';
        }

        // ── VetaT — Příloha 1 §7 ─────────────────────────────────────────────
        $vetaT = $dom->createElement('VetaT');
        $vetaT->setAttribute('kc_prij7', $this->int((float) ($s7['income'] ?? 0)));
        $vetaT->setAttribute('kc_vyd7', $this->int((float) ($s7['expenses'] ?? 0)));
        // ř.101/102 (kc_prij7/kc_vyd7) musí sedět na „Celkem" řádek tabulky B. Druh
        // činnosti (VetaT.pr_prij7/pr_vyd7 hlavní + Vetac.prijmy7/vydaje7 vedlejší) —
        // úhrn §7 je definičně součtem těch samých činností, takže je to táž hodnota.
        $vetaT->setAttribute('celk_pr_prij7', $this->int((float) ($s7['income'] ?? 0)));
        $vetaT->setAttribute('celk_pr_vyd7', $this->int((float) ($s7['expenses'] ?? 0)));
        $vetaT->setAttribute('kc_zd7p', $this->int((float) ($s7['base'] ?? 0)));
        $vetaT->setAttribute('kc_uhzvys', $this->int((float) ($s7['increase'] ?? 0)));
        $vetaT->setAttribute('kc_uhsniz', $this->int((float) ($s7['decrease'] ?? 0)));
        if (($s7['expense_mode'] ?? '') === 'pausal') {
            $vetaT->setAttribute('vyd7proc', 'A');
            if ((int) ($s7['expense_rate'] ?? 0) > 0) {
                $vetaT->setAttribute('pr_sazba', (string) (int) $s7['expense_rate']);
            }
        } else {
            $vetaT->setAttribute('vyd7proc', 'N');
            // uc_soust: 1 = daňová evidence, 2 = účetnictví (FO s podvojným účetnictvím, §23/2).
            $vetaT->setAttribute('uc_soust', ($s7['accounting_mode'] ?? '') === 'double_entry' ? '2' : '1');
        }
        $activities = (array) ($s7['activities'] ?? []);
        $firstActivity = is_array($activities[0] ?? null) ? $activities[0] : [];
        $naceRaw = (string) ($firstActivity['nace_code'] ?? $supplier['cz_nace_code'] ?? '');
        $nace = EpoSupplierBlockBuilder::normalizeOkec($naceRaw);
        if ($nace !== null) {
            $vetaT->setAttribute('c_nace', $nace);
            $naceWarning = EpoSupplierBlockBuilder::okecWarning($naceRaw);
            if ($naceWarning !== null) {
                $warnings[] = $naceWarning;
            }
        } elseif ((float) ($s7['income'] ?? 0) > 0) {
            $warnings[] = 'Chybí nebo neplatný kód NACE u hlavní činnosti — EPO má na c_nace kritickou kontrolu, doplňte před podáním.';
        }
        if ($firstActivity !== []) {
            $vetaT->setAttribute('m_podnik', (string) max(0, min(12, (int) ($firstActivity['active_months'] ?? 12))));
            $vetaT->setAttribute('pr_prij7', $this->int((float) ($firstActivity['income'] ?? 0)));
            $vetaT->setAttribute('pr_vyd7', $this->int((float) ($firstActivity['expenses'] ?? 0)));
        }
        $closing = is_array($s7['closing'] ?? null) ? $s7['closing'] : null;
        if ($closing !== null) {
            $end = (array) ($closing['closing_balances'] ?? []);
            if (isset($end['depreciation'])) {
                $vetaT->setAttribute('kc_odpcelk', $this->int((float) $end['depreciation']));
            }
        }
        $root->appendChild($vetaT);

        foreach (array_slice($activities, 1) as $activity) {
            if (!is_array($activity)) {
                continue;
            }
            $vetac = $dom->createElement('Vetac');
            $vetac->setAttribute('prijmy7', $this->int((float) ($activity['income'] ?? 0)));
            $vetac->setAttribute('vydaje7', $this->int((float) ($activity['expenses'] ?? 0)));
            $vetac->setAttribute('sazba_dal', (string) (int) ($activity['expense_rate'] ?? 0));
            $activityNace = EpoSupplierBlockBuilder::normalizeOkec((string) ($activity['nace_code'] ?? ''));
            if ($activityNace !== null) {
                $vetac->setAttribute('c_nace_dal', $activityNace);
                $activityNaceWarning = EpoSupplierBlockBuilder::okecWarning((string) ($activity['nace_code'] ?? ''));
                if ($activityNaceWarning !== null) {
                    $warnings[] = $activityNaceWarning;
                }
            } elseif ((float) ($activity['income'] ?? 0) > 0) {
                $warnings[] = 'Chybí nebo neplatný kód NACE u vedlejší činnosti §7 — EPO má na c_nace_dal kritickou kontrolu, doplňte před podáním.';
            }
            $root->appendChild($vetac);
        }

        if ($closing !== null && ($closing['status'] ?? '') === 'final') {
            $opening = (array) ($closing['opening_balances'] ?? []);
            $ending = (array) ($closing['closing_balances'] ?? []);
            $map = [
                'fixed_assets' => '02', 'cash' => '03', 'receivables' => '04', 'bank' => '05a',
                'inventory' => '06', 'other_assets' => '08', 'liabilities' => '10', 'reserves' => '11',
            ];
            $vetaU = $dom->createElement('VetaU');
            foreach ($map as $key => $code) {
                if (array_key_exists($key, $opening)) {
                    $vetaU->setAttribute('kc_dpfmz' . $code, $this->int((float) $opening[$key]));
                }
                if (array_key_exists($key, $ending)) {
                    $vetaU->setAttribute('kc_z_dpfmz' . $code, $this->int((float) $ending[$key]));
                }
            }
            $root->appendChild($vetaU);
        }

        // ── VetaN — žádost o vrácení přeplatku (§155 DŘ) — poslední věta, staví se
        // jen když z přiznání vyjde přeplatek (viz buildVetaN).
        $vetaN = $this->buildVetaN($dom, $calc, $warnings);
        if ($vetaN !== null) {
            $root->appendChild($vetaN);
        }

        return ['xml' => $dom->saveXML() ?: '', 'warnings' => $warnings];
    }

    /**
     * VetaN — žádost o vrácení přeplatku (§155 daňového řádu), přesná obdoba DPPO
     * `VetaNP` ({@see DppoXmlBuilder::buildVetaNP}). Staví se JEN když z přiznání
     * vyjde přeplatek (kc_zbyvpred záporné, tj. `balance_due` < 0) — bez ní si
     * poplatník o vrácení přeplatku vůbec nežádá, přeplatek jen zůstane na osobním
     * daňovém účtu. Bankovní spojení bere ze STEJNÉHO zdroje jako DPPO
     * ({@see DpfoReturnDataProvider::bankAccount} — tabulka `currencies`, výchozí
     * CZK účet) — u OSVČ je to týž jediný podnikatelský účet, věcně správný zdroj
     * i pro vrácení daně FO. Zahraniční účet (zp_vrac='Z') systém nepodporuje —
     * poplatník ho v appce nevede.
     *
     * @param array<string,mixed> $calc
     * @param list<string>        $warnings
     */
    private function buildVetaN(\DOMDocument $dom, array $calc, array &$warnings): ?\DOMElement
    {
        $overpayment = (int) round(-(float) ($calc['balance_due'] ?? 0.0));
        if ($overpayment <= 0) {
            return null;
        }

        $account = $calc['bank_account'] ?? null;
        if (!is_array($account) || empty($account['account_number'])) {
            $warnings[] = 'Vznikl přeplatek ' . number_format($overpayment, 0, ',', ' ') . ' Kč, ale v Nastavení '
                . 'firmy chybí výchozí CZK bankovní účet — žádost o jeho vrácení (VetaN) se do přiznání '
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
                . 'vrácení (VetaN) se do přiznání nedostala. Ověřte formát účtu v Nastavení firmy.';
            return null;
        }

        $vetaN = $dom->createElement('VetaN');
        $vetaN->setAttribute('zp_vrac', 'U'); // U = na účet v ČR (zahraniční Z appka nevede)
        if ($prefix !== null) {
            $vetaN->setAttribute('zvp_pbu', $prefix);
        }
        $vetaN->setAttribute('zvp_c_komds', $base);
        $vetaN->setAttribute('zvp_k_bank', $bankCode);
        $bankName = trim((string) ($account['bank_name'] ?? ''));
        if ($bankName !== '') {
            $vetaN->setAttribute('zvp_naz_bank', mb_substr($bankName, 0, 30)); // XSD maxLength 30
        }
        $vetaN->setAttribute('kc_preplatek', (string) $overpayment);

        return $vetaN;
    }

    /**
     * @param array<string,mixed> $supplier
     * @param list<string> $warnings
     * @param array<string,mixed> $representation výstup {@see TaxRepresentationService::at()}
     */
    private function buildVetaP(\DOMDocument $dom, array $supplier, array &$warnings, array $representation = ['represented' => false]): \DOMElement
    {
        $vetaP = $dom->createElement('VetaP');

        // Územní pracoviště FÚ. Sdílený EpoSupplierBlockBuilder::fillVetaP() ho plní,
        // tahle vlastní kopie věty P na něj zapomněla — stejná mezera jako u DPPO,
        // zkušební EPO ji hlásí jako „Číslo územního pracoviště není vyplněno".
        if (!empty($supplier['workplace_code'])) {
            $vetaP->setAttribute('c_pracufo', (string) $supplier['workplace_code']);
        }

        $dic = EpoSupplierBlockBuilder::normalizeDic($supplier['dic'] ?? null);
        if ($dic !== '') {
            $vetaP->setAttribute('dic', $dic);
        }
        // Rodné číslo FO: často shodné s kmenovou částí DIČ (9–10 číslic). Jinak vynecháme.
        if (preg_match('/^[0-9]{9,10}$/', $dic)) {
            $vetaP->setAttribute('rod_c', $dic);
        } else {
            $warnings[] = 'Chybí rodné číslo poplatníka — EPO ho u FO vyžaduje, doplňte před podáním.';
        }

        $firstName = trim((string) ($supplier['opr_jmeno'] ?? ''));
        $lastName = trim((string) ($supplier['opr_prijmeni'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            [$fallbackFirst, $fallbackLast] = EpoSupplierBlockBuilder::splitPersonName((string) ($supplier['company_name'] ?? ''));
            $firstName = $firstName !== '' ? $firstName : $fallbackFirst;
            $lastName = $lastName !== '' ? $lastName : $fallbackLast;
        }
        $vetaP->setAttribute('jmeno', mb_substr($firstName, 0, 30));
        $vetaP->setAttribute('prijmeni', mb_substr($lastName, 0, 36));

        [$ulice, $cpop, $corient] = $this->parseStreet($supplier);
        if ($ulice !== '') {
            $vetaP->setAttribute('ulice', mb_substr($ulice, 0, 38));
        }
        if ($cpop !== '') {
            $vetaP->setAttribute('c_pop', $cpop);
        }
        if ($corient !== '') {
            $vetaP->setAttribute('c_orient', mb_substr($corient, 0, 4));
        }
        $vetaP->setAttribute('naz_obce', mb_substr((string) ($supplier['city'] ?? ''), 0, 48));
        $vetaP->setAttribute('psc', preg_replace('/\s/', '', (string) ($supplier['zip'] ?? '')) ?? '');
        $iso2 = (string) ($supplier['country_iso2'] ?? 'CZ');
        $vetaP->setAttribute('k_stat', $iso2);
        $countryName = EpoSupplierBlockBuilder::countryName($iso2);
        if ($countryName !== null) {
            $vetaP->setAttribute('stat', $countryName);
        }
        if (!empty($supplier['phone'])) {
            $vetaP->setAttribute('c_telef', EpoSupplierBlockBuilder::normalizePhone((string) $supplier['phone']));
        }

        EpoSupplierBlockBuilder::fillRepresentationAttributes($vetaP, $representation);

        return $vetaP;
    }

    /**
     * Adresní parsing má jediný zdroj pravdy — {@see EpoSupplierBlockBuilder::parseStreet}.
     *
     * @param array<string,mixed> $supplier
     * @return array{0:string,1:string,2:string}
     */
    private function parseStreet(array $supplier): array
    {
        return EpoSupplierBlockBuilder::parseStreet($supplier);
    }

    private function int(mixed $v): string
    {
        return (string) (int) round((float) $v);
    }

    /** ISO YYYY-MM-DD → D.M.YYYY (EPO dateInMultiFormat, shodně se zdobd_od/do); '' když neplatné. */
    private function formatDate(mixed $v): string
    {
        $s = trim((string) $v);
        if ($s === '') {
            return '';
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($s, 0, 10));
        return $d === false ? '' : $d->format('j.n.Y');
    }
}
