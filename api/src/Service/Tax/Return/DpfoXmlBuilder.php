<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Service\Report\EpoEnvelope;
use MyInvoice\Service\Report\EpoSupplierBlockBuilder;

/**
 * Generátor EPO XML formuláře DPFDP7 (daň z příjmů FO) — Epic DP (issue #18).
 *
 * Struktura dle `api/xsd/dpfdp7_epo2.xsd` (bez namespace, xs:sequence — pořadí vět!):
 *   Pisemnost(nazevSW,verzeSW) > DPFDP7(verzePis) > VetaD, VetaP, VetaO, VetaS, VetaT
 *
 * - VetaD: povinné (fixní k_uladis="DPF", dokument="DP7", rok, dap_typ, c_ufo_cil,
 *   pln_moc, audit) + slevy/zvýhodnění/zálohy/doplatek (kc_op15_1a, da_slevy, kc_danbonus,
 *   kc_zbyvpred…).
 * - VetaP: identifikace FO (jmeno/prijmeni, rod_c, dic, adresa).
 * - VetaO: dílčí základy §6–§10 + úhrn + základ daně.
 * - VetaS: §15 nezdanitelné části + daň (da_dan16 se 2 desetinnými místy).
 * - VetaT: Příloha 1 §7 (kc_prij7, kc_vyd7, kc_zd7p, vyd7proc, pr_sazba).
 *
 * Hodnoty mapuje z výstupu {@see DpfoReturnCalculator} (klíč fields + s7).
 */
final class DpfoXmlBuilder
{
    private const VERZE_PIS = '07.01';

    /** field → Veta (int Kč jako string; da_dan16 se řeší zvlášť). */
    private const VETA_O = ['kc_prij6', 'kc_zd6', 'kc_zd7', 'kc_zakldan8', 'kc_zd9', 'kc_zd10', 'kc_uhrn', 'kc_ztrata2', 'kc_zakldan', 'kc_zakldan23'];
    private const VETA_S_INT = ['kc_op15_8', 'kc_op28_5', 'kc_op15_12', 'kc_op15_13', 'kc_op15_inpr', 'kc_op15_pece', 'kc_odcelk', 'kc_zdsniz', 'kc_zdzaokr'];
    private const VETA_D_FIELDS = ['kc_op15_1a', 'kc_op15_1c', 'kc_op15_1d', 'kc_op15_1e1', 'kc_op15_1e2', 'uhrn_slevy35ba', 'da_slevy', 'kc_dazvyhod', 'kc_slevy35c', 'kc_danbonus', 'kc_dan_po_db', 'kc_dan_celk', 'kc_zalzavc', 'kc_zalpred', 'kc_zbyvpred'];

    /**
     * @param array<string,mixed> $supplier
     * @param array<string,mixed> $calc  výstup DpfoReturnCalculator::compute
     * @param array<string,mixed> $meta
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
        $vetaD->setAttribute('pln_moc', (string) ($meta['pln_moc'] ?? 'N'));
        $vetaD->setAttribute('audit', (string) ($meta['audit'] ?? 'N'));
        $vetaD->setAttribute('zdobd_od', sprintf('1.1.%04d', $year));
        $vetaD->setAttribute('zdobd_do', sprintf('31.12.%04d', $year));
        foreach (self::VETA_D_FIELDS as $f) {
            if (array_key_exists($f, $fields)) {
                $vetaD->setAttribute($f, $this->int($fields[$f]));
            }
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
            $vetaD->setAttribute('m_manz', (string) max(0, min(12, (int) ($spouse['eligible_months'] ?? 0))));
            if (!empty($spouse['ztpp'])) {
                $vetaD->setAttribute('m_ztpp', (string) max(0, min(12, (int) ($spouse['eligible_months'] ?? 0))));
            }
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

        if ((float) ($fields['da_slevy'] ?? 0) > 0 && $spouse === null) {
            $warnings[] = 'Uplatněna sleva na manžela/manželku (da_slevy), ale chybí jeho identifikace — EPO ji bez identity odmítne, doplňte před podáním.';
        }
        if (((float) ($fields['kc_dazvyhod'] ?? 0) > 0 || (float) ($fields['kc_danbonus'] ?? 0) > 0)
            && (array) ($family['children'] ?? []) === []) {
            $warnings[] = 'Uplatněno daňové zvýhodnění / bonus na děti, ale chybí identifikace dětí a měsíce — EPO je bez identity odmítne, doplňte před podáním.';
        }

        // ── VetaP — poplatník FO ─────────────────────────────────────────────
        $root->appendChild($this->buildVetaP($dom, $supplier, $warnings));

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
            $vetaS->setAttribute('da_dan16', number_format((float) $fields['da_dan16'], 2, '.', '')); // 2 desetinná místa
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

        // ── VetaT — Příloha 1 §7 ─────────────────────────────────────────────
        $vetaT = $dom->createElement('VetaT');
        $vetaT->setAttribute('kc_prij7', $this->int((float) ($s7['income'] ?? 0)));
        $vetaT->setAttribute('kc_vyd7', $this->int((float) ($s7['expenses'] ?? 0)));
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

        return ['xml' => $dom->saveXML() ?: '', 'warnings' => $warnings];
    }

    /**
     * @param array<string,mixed> $supplier
     * @param list<string> $warnings
     */
    private function buildVetaP(\DOMDocument $dom, array $supplier, array &$warnings): \DOMElement
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
