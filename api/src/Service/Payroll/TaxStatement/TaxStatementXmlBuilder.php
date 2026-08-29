<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\TaxStatement;

use MyInvoice\Service\Report\EpoEnvelope;
use MyInvoice\Service\Report\EpoPayerBlockBuilder;

/**
 * Generátor EPO XML obou vyúčtování — DPZVD6 (zálohová daň) a DPSVD2 (srážková).
 *
 * Jsou to DVĚ samostatné písemnosti s vlastním kódem ULADIS (`DPZ` vs. `DPS`),
 * vlastním schématem a vlastní lhůtou; sdílejí jen obálku a `VetaP` plátce.
 * Přílohy nejsou druhé podání — jsou to věty UVNITŘ téže písemnosti
 * (`VetaB` = příloha č. 1, `VetaG` = přehled vrácených přeplatků).
 *
 * ## Pořadí vět
 *
 * Obě schémata mají `xs:sequence`, ne `xs:all`. Prohozené pořadí vět tedy
 * schéma shodí, i kdyby byly všechny hodnoty správně:
 *
 * - DPZVD6: `VetaD, VetaP, VetaO, VetaF, VetaE, VetaC, VetaB, VetaR, VetaG, VetaS, VetaH`
 * - DPSVD2: `VetaD, VetaP, VetaR, VetaO, VetaS, VetaA, VetaB`
 *
 * ## Dodatečné vyúčtování
 *
 * U typu `D`/`E` se podle pokynů nevyplňuje sl. 2 a sl. 11 části I. ani celá
 * část II.; rozdíl proti původnímu vyúčtování jde do sl. 10 (u DPSVD2 do sl. 9).
 * Rozdíl aplikace nepočítá — musela by k tomu znát původní podání jako celek,
 * ne jen aktuální stav mezd.
 */
final class TaxStatementXmlBuilder
{
    /**
     * Verze písemnosti. Schéma ji neomezuje (`xs:string`), EPO ji používá jen
     * k rozlišení vzorů tiskopisu.
     */
    private const VERZE_PIS = '01.01';

    /**
     * @param array<string,mixed> $supplier Řádek z
     *        {@see \MyInvoice\Service\Report\EpoSupplierBlockBuilder::loadSupplier()}.
     * @param array{verze_sw?:string,verze_pis?:string,d_zjist?:string} $meta
     * @return array{xml:string,warnings:list<string>}
     */
    public function buildDependentActivity(
        array $supplier,
        DependentActivityStatement $statement,
        array $meta = [],
    ): array {
        $warnings = $statement->warnings;
        $additional = $this->isAdditional($statement->variant);

        [$dom, $root] = EpoEnvelope::create(
            'DPZVD6',
            (string) ($meta['verze_pis'] ?? self::VERZE_PIS),
            isset($meta['verze_sw']) ? (string) $meta['verze_sw'] : null,
        );

        $total = $statement->total();

        // ── VetaD — hlavička a část II. ─────────────────────────────────────
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'DPZ');
        $vetaD->setAttribute('dokument', 'VD6');
        $vetaD->setAttribute('c_ufo_cil', $this->financialOffice($supplier, $warnings));
        $vetaD->setAttribute('vdadpz_typ', $statement->variant);
        $vetaD->setAttribute('zdobd_od', sprintf('1.1.%d', $statement->year));
        $vetaD->setAttribute('zdobd_do', sprintf('31.12.%d', $statement->year));
        $this->applyDiscoveryDate($vetaD, $additional, $meta, $warnings);

        foreach ($statement->headcounts as $month => $headcount) {
            $vetaD->setAttribute('poc_zam' . $month, (string) $headcount);
        }

        if (!$additional) {
            // Část II. Řádky 3 a 5 („z toho finanční úřad na žádost vrátil,
            // převedl nebo použil") nemají v mzdách zdroj — jsou to částky
            // z rozhodnutí správce daně o žádosti podle § 35d odst. 5 a 9,
            // které aplikace neeviduje. Nula znamená „správce daně nic nevrátil",
            // což je stav drtivé většiny plátců.
            $row3 = 0;
            $row5 = 0;
            $row1 = $total->advanceDue;
            $row2 = $total->annualOverpayment;
            $row4 = $total->bonusPaid;
            $row8 = $row1 - $row2 + $row3 - $row4 + $row5;
            $row9 = $total->remitted;

            $vetaD->setAttribute('kc_dpzii01', (string) $row1);
            $vetaD->setAttribute('kc_dpzii02', (string) $row2);
            $vetaD->setAttribute('kc_dpzii03', (string) $row3);
            $vetaD->setAttribute('kc_dpzii03a', (string) $row4);
            $vetaD->setAttribute('kc_dpzii04a', (string) $row5);
            $vetaD->setAttribute('kc_dpzii08', (string) $row8);
            $vetaD->setAttribute('kc_dpzii09', (string) $row9);
            $vetaD->setAttribute('kc_dpzii10', (string) ($row9 - $row8));

            $vetaD->setAttribute('uhrnprepl', (string) $statement->annualOverpaymentTotal);
            $vetaD->setAttribute('uhrndopl', (string) $statement->annualBonusTopUpTotal);
        }
        $root->appendChild($vetaD);

        // ── VetaP — plátce daně ─────────────────────────────────────────────
        $vetaP = $dom->createElement('VetaP');
        EpoPayerBlockBuilder::fillVetaP($vetaP, $supplier, true);
        $root->appendChild($vetaP);

        // ── VetaO — část I. po měsících ─────────────────────────────────────
        foreach ($statement->months as $month => $row) {
            $vetaO = $dom->createElement('VetaO');
            $vetaO->setAttribute('mesic', (string) $month);
            $vetaO->setAttribute('kc_dpzi01', (string) $row->advanceDue);
            if (!$additional) {
                $vetaO->setAttribute('kc_dpzi02', (string) $row->advanceWithheld);
            }
            $vetaO->setAttribute('kc_dpzi04', (string) $row->annualOverpayment);
            $vetaO->setAttribute('kc_dpzi05', (string) $row->bonusPaid);
            $vetaO->setAttribute('kc_dpzi08', (string) $row->adjustments());
            $vetaO->setAttribute('kc_dpzi09', (string) $row->settledAmount());
            if ($additional) {
                $vetaO->setAttribute('kc_dpzi10', (string) $row->correctionDifference);
            } else {
                $vetaO->setAttribute('kc_dpzi11', (string) $row->remitted);
            }
            $root->appendChild($vetaO);
        }

        // ── VetaB — příloha č. 1, počet zaměstnanců k 1. prosinci ───────────
        // Podle § 38j odst. 8 písm. a) je povinná vždy, ne jen když se změnila.
        foreach ($statement->workplaces as $place) {
            $vetaB = $dom->createElement('VetaB');
            $vetaB->setAttribute('c_obce_zuj', (string) $place->municipalityCode);
            if ($place->municipalityName !== null && $place->municipalityName !== '') {
                $vetaB->setAttribute('naz_obce_zuj', $place->municipalityName);
                $vetaB->setAttribute('naz_vykonu', $place->municipalityName);
            }
            if ($place->districtName !== null && $place->districtName !== '') {
                $vetaB->setAttribute('naz_zko', $place->districtName);
            }
            $vetaB->setAttribute('poc_zam', (string) $place->headcount);
            $root->appendChild($vetaB);
        }
        if ($statement->workplaces === []) {
            $warnings[] = 'Příloha č. 1 (počet zaměstnanců podle místa výkonu práce) '
                . 'je prázdná — doplňte u vztahů obec místa výkonu práce.';
        }

        // ── VetaG — časové rozlišení vrácených přeplatků z ročního zúčtování ─
        // Třetí a čtvrtý sloupec (co finanční úřad na žádost vrátil a kdy byla
        // žádost podána) zůstávají prázdné ze stejného důvodu jako ř. 3 a 5.
        if (!$additional) {
            foreach ($statement->overpaymentPayouts as $payout) {
                $vetaG = $dom->createElement('VetaG');
                $vetaG->setAttribute('mesic_06', (string) $payout['month']);
                $vetaG->setAttribute('uhrnprepl_c', (string) $payout['amount']);
                $root->appendChild($vetaG);
            }
        }

        // ── VetaS — ÚHRN (ř. 13 části I.) ───────────────────────────────────
        $vetaS = $dom->createElement('VetaS');
        $vetaS->setAttribute('s_kc_dpzi01', (string) $total->advanceDue);
        if (!$additional) {
            $vetaS->setAttribute('s_kc_dpzi02', (string) $total->advanceWithheld);
        }
        $vetaS->setAttribute('s_kc_dpzi04', (string) $total->annualOverpayment);
        $vetaS->setAttribute('s_kc_dpzi05', (string) $total->bonusPaid);
        $vetaS->setAttribute('s_kc_dpzi08', (string) $total->adjustments());
        $vetaS->setAttribute('s_kc_dpzi09', (string) $total->settledAmount());
        if ($additional) {
            $vetaS->setAttribute('s_kc_dpzi10', (string) $total->correctionDifference);
        } else {
            $vetaS->setAttribute('s_kc_dpzi11', (string) $total->remitted);
        }
        $root->appendChild($vetaS);

        return [
            'xml' => (string) $dom->saveXML(),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param array<string,mixed> $supplier
     * @param array{verze_sw?:string,verze_pis?:string,d_zjist?:string} $meta
     * @return array{xml:string,warnings:list<string>}
     */
    public function buildWithholdingTax(
        array $supplier,
        WithholdingTaxStatement $statement,
        array $meta = [],
    ): array {
        $warnings = $statement->warnings;
        $additional = $this->isAdditional($statement->variant);

        [$dom, $root] = EpoEnvelope::create(
            'DPSVD2',
            (string) ($meta['verze_pis'] ?? self::VERZE_PIS),
            isset($meta['verze_sw']) ? (string) $meta['verze_sw'] : null,
        );

        $total = $statement->total();

        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'DPS');
        $vetaD->setAttribute('dokument', 'VD2');
        $vetaD->setAttribute('c_ufo_cil', $this->financialOffice($supplier, $warnings));
        $vetaD->setAttribute('c_drp', $statement->incomeKind);
        $vetaD->setAttribute('dapdps_forma', $statement->variant);
        $vetaD->setAttribute('zdobd_od', sprintf('1.1.%d', $statement->year));
        $vetaD->setAttribute('zdobd_do', sprintf('31.12.%d', $statement->year));
        $this->applyDiscoveryDate($vetaD, $additional, $meta, $warnings);
        if (!$additional) {
            // Část II. má jen tři obsazené řádky: 1 = mělo být sraženo,
            // 4 = bylo odvedeno, 5 = jejich rozdíl. Řádky 2 a 3 se od
            // zdaňovacího období 2013 nevyplňují.
            $vetaD->setAttribute('kc_dpsii01', $this->money($total->taxDueMinor));
            $vetaD->setAttribute('kc_dpsii04', $this->money($total->remittedMinor));
            $vetaD->setAttribute('kc_dpsii05', $this->money($statement->balanceMinor()));
        }
        $root->appendChild($vetaD);

        $vetaP = $dom->createElement('VetaP');
        EpoPayerBlockBuilder::fillVetaP($vetaP, $supplier, true);
        $root->appendChild($vetaP);

        foreach ($statement->months as $month => $row) {
            $vetaO = $dom->createElement('VetaO');
            $vetaO->setAttribute('mesic', (string) $month);
            $vetaO->setAttribute('kc_dpsi01', $this->money($row->taxDueMinor));
            if (!$additional) {
                $vetaO->setAttribute('kc_dpsi02', $this->money($row->taxWithheldMinor));
            }
            $vetaO->setAttribute('kc_dpsi03', $this->money($row->dueWithReturnMinor));
            $vetaO->setAttribute('kc_dpsi06', $this->money($row->declarationLinkedMinor));
            $vetaO->setAttribute('kc_dpsi08a', $this->money($row->settledAmountMinor()));
            if ($additional) {
                $vetaO->setAttribute('kc_dpsi09', $this->money($row->correctionDifferenceMinor));
            } else {
                $vetaO->setAttribute('kc_dpsi10', $this->money($row->remittedMinor));
            }
            $root->appendChild($vetaO);
        }

        $vetaS = $dom->createElement('VetaS');
        $vetaS->setAttribute('s_kc_dpsi01', $this->money($total->taxDueMinor));
        if (!$additional) {
            $vetaS->setAttribute('s_kc_dpsi02', $this->money($total->taxWithheldMinor));
        }
        $vetaS->setAttribute('s_kc_dpsi03', $this->money($total->dueWithReturnMinor));
        $vetaS->setAttribute('s_kc_dpsi06', $this->money($total->declarationLinkedMinor));
        $vetaS->setAttribute('s_kc_dpsi08a', $this->money($total->settledAmountMinor()));
        if ($additional) {
            $vetaS->setAttribute('s_kc_dpsi09', $this->money($total->correctionDifferenceMinor));
        } else {
            $vetaS->setAttribute('s_kc_dpsi10', $this->money($total->remittedMinor));
        }
        $root->appendChild($vetaS);

        return [
            'xml' => (string) $dom->saveXML(),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function isAdditional(string $variant): bool
    {
        return in_array($variant, [
            DependentActivityStatement::TYP_DODATECNE,
            DependentActivityStatement::TYP_DODATECNE_OPRAVNE,
        ], true);
    }

    /**
     * @param array<string,mixed> $supplier
     * @param list<string> $warnings
     */
    private function financialOffice(array $supplier, array &$warnings): string
    {
        $office = trim((string) ($supplier['financial_office_code'] ?? ''));
        if ($office === '') {
            // `c_ufo_cil` je povinné a `VetaP` téhle rodiny atribut `c_ufo` NEMÁ,
            // takže chybějící kód se nedá dohnat jinde v podání.
            $warnings[] = 'Firma nemá vyplněný finanční úřad — ve vyúčtování je '
                . 'předvyplněný FÚ pro Prahu 1, ověřte ho.';

            return '451';
        }

        return $office;
    }

    /**
     * @param array{d_zjist?:string} $meta
     * @param list<string> $warnings
     */
    private function applyDiscoveryDate(
        \DOMElement $vetaD,
        bool $additional,
        array $meta,
        array &$warnings,
    ): void {
        if (!$additional) {
            return;
        }
        $raw = trim((string) ($meta['d_zjist'] ?? ''));
        if ($raw === '') {
            $warnings[] = 'Dodatečné vyúčtování má uvádět datum zjištění důvodů '
                . 'pro podání (§ 141 odst. 5 daňového řádu).';

            return;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false) {
            throw new \InvalidArgumentException(
                'Datum zjištění důvodů pro dodatečné vyúčtování není platné datum.',
            );
        }
        $vetaD->setAttribute('d_zjist', $date->format('j.n.Y'));
    }

    /**
     * Haléře → desetinné číslo se dvěma místy. DPSVD2 má u peněžních položek
     * `fractionDigits="2"`, takže se na celé koruny nezaokrouhluje. Přes celá
     * čísla, ne přes float — dělení 100 by u velkých úhrnů posunulo haléř.
     */
    private function money(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }
}
