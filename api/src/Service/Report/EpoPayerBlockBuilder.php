<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use DOMElement;

/**
 * `VetaP` písemností PLÁTCE DANĚ ze závislé činnosti — DPZVD6, DPSVD2,
 * DPZMB1 a DPZDB1; také DPSHL1 a DPSZD1 pro příjmy nerezidentů.
 *
 * Není to `VetaP` z DPH/KH/SH. Schémata téhle rodiny NEMAJÍ atributy `c_ufo`,
 * `stat`, `email` ani `c_telef`, které {@see EpoSupplierBlockBuilder::fillVetaP()}
 * emituje bezpodmínečně — podání by na nich spadlo. Správce daně se u nich
 * uvádí jen v `VetaD` (`c_ufo_cil`).
 *
 * `sest_email` znají vyúčtování a hlášení nerezidentů, žádosti o bonus ne.
 * Hlášení nerezidentů naopak nemají `titul`. Rozdíly schémat řídí
 * `$includeSestEmail` a `$includePersonTitle` místo samostatných kopií plniče.
 *
 * Vzniklo to sloučením kopie, kterou si napsala vlna DPZMB1/DPZDB1, s tou,
 * kterou by jinak potřebovala vlna vyúčtování. Normalizační primitiva
 * (DIČ, adresa, telefon, rozpad jména) zůstávají v {@see EpoSupplierBlockBuilder}
 * — tam je hodnota stejná bez ohledu na tiskopis.
 */
final class EpoPayerBlockBuilder
{
    /**
     * @param array<string,mixed> $supplier Řádek z {@see EpoSupplierBlockBuilder::loadSupplier()}.
     * @param bool $includeSestEmail Emitovat `sest_email`? Vyúčtování a hlášení nerezidentů.
     * @param bool $includePersonTitle DPSHL1/DPSZD1 atribut `titul` nemají; ostatní
     *                                formuláře této rodiny dovolují titul FO do 10 znaků.
     * @param array<string,mixed> $representation Výstup {@see \MyInvoice\Service\Tax\Return\TaxRepresentationService::at()}.
     *                                Zastoupený plátce dostane `zast_*` a `opr_*` přes
     *                                {@see EpoSupplierBlockBuilder::fillSignerAttributes()}, stejně jako DPH, DPPO a DPFO.
     */
    public static function fillVetaP(
        DOMElement $vetaP,
        array $supplier,
        bool $includeSestEmail = false,
        bool $includePersonTitle = true,
        array $representation = [],
    ): void {
        $vetaP->setAttribute('dic', EpoSupplierBlockBuilder::normalizeDic(
            isset($supplier['dic']) ? (string) $supplier['dic'] : null,
        ));

        // typ_ds = typ daňového subjektu (F/P), NE typ datové schránky. Hodnota
        // „L" (plátcova pokladna) je od zdaňovacího období 2024 zakázaná, takže
        // se nenabízí ani jako varianta.
        $isPravnickaOsoba = ($supplier['taxpayer_type'] ?? null) === 'po';
        $vetaP->setAttribute('typ_ds', $isPravnickaOsoba ? 'P' : 'F');

        if ($isPravnickaOsoba) {
            $vetaP->setAttribute('zkrobchjm', (string) ($supplier['company_name'] ?? ''));
        } else {
            $jmeno = trim((string) ($supplier['opr_jmeno'] ?? ''));
            $prijmeni = trim((string) ($supplier['opr_prijmeni'] ?? ''));
            if ($jmeno === '' || $prijmeni === '') {
                [$jmeno, $prijmeni] = EpoSupplierBlockBuilder::splitPersonName(
                    (string) ($supplier['company_name'] ?? ''),
                );
            }
            $vetaP->setAttribute('jmeno', $jmeno);
            $vetaP->setAttribute('prijmeni', $prijmeni !== '' ? $prijmeni : $jmeno);
            if ($includePersonTitle) {
                EpoSupplierBlockBuilder::fillPersonTitle($vetaP, $supplier);
            }
        }

        [$ulice, $cpop, $corient] = EpoSupplierBlockBuilder::parseStreet($supplier);
        $vetaP->setAttribute('ulice', $ulice);
        if ($cpop !== '') {
            $vetaP->setAttribute('c_pop', $cpop);
        }
        if ($corient !== '') {
            $vetaP->setAttribute('c_orient', $corient);
        }
        $vetaP->setAttribute('naz_obce', (string) ($supplier['city'] ?? ''));
        $vetaP->setAttribute(
            'psc',
            preg_replace('/\s/', '', (string) ($supplier['zip'] ?? '')) ?? '',
        );

        if (!empty($supplier['workplace_code'])) {
            $vetaP->setAttribute('c_pracufo', (string) $supplier['workplace_code']);
        }
        if (!empty($representation['represented'])) {
            EpoSupplierBlockBuilder::fillSignerAttributes($vetaP, $supplier, $representation);
        } else {
            foreach (['opr_jmeno', 'opr_prijmeni', 'opr_postaveni'] as $key) {
                if (!empty($supplier[$key])) {
                    $vetaP->setAttribute($key, (string) $supplier[$key]);
                }
            }
        }

        if (!empty($supplier['sest_jmeno'])) {
            $vetaP->setAttribute('sest_jmeno', (string) $supplier['sest_jmeno']);
            if (!empty($supplier['sest_prijmeni'])) {
                $vetaP->setAttribute('sest_prijmeni', (string) $supplier['sest_prijmeni']);
            }
        }
        if (!empty($supplier['sest_telefon'])) {
            $vetaP->setAttribute('sest_telef', EpoSupplierBlockBuilder::normalizePhone(
                (string) $supplier['sest_telefon'],
            ));
        }
        if ($includeSestEmail && !empty($supplier['sest_email'])) {
            $vetaP->setAttribute('sest_email', (string) $supplier['sest_email']);
        }
    }
}
