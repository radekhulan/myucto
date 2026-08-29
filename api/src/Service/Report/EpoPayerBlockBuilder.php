<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use DOMElement;

/**
 * `VetaP` písemností PLÁTCE DANĚ ze závislé činnosti — DPZVD6, DPSVD2,
 * DPZMB1 a DPZDB1.
 *
 * Není to `VetaP` z DPH/KH/SH. Schémata téhle rodiny NEMAJÍ atributy `c_ufo`,
 * `stat`, `email` ani `c_telef`, které {@see EpoSupplierBlockBuilder::fillVetaP()}
 * emituje bezpodmínečně — podání by na nich spadlo. Správce daně se u nich
 * uvádí jen v `VetaD` (`c_ufo_cil`).
 *
 * Sada atributů je napříč všemi čtyřmi písemnostmi znak po znaku shodná
 * s jedinou výjimkou: `sest_email` znají jen vyúčtování (DPZVD6, DPSVD2),
 * žádosti o bonus ne. Proto `$includeSestEmail`, ne čtvrtá kopie plniče.
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
     * @param bool $includeSestEmail Emitovat `sest_email`? Jen DPZVD6/DPSVD2.
     */
    public static function fillVetaP(
        DOMElement $vetaP,
        array $supplier,
        bool $includeSestEmail = false,
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
        foreach (['opr_jmeno', 'opr_prijmeni', 'opr_postaveni'] as $key) {
            if (!empty($supplier[$key])) {
                $vetaP->setAttribute($key, (string) $supplier[$key]);
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
