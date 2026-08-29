<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\TaxBonus;

use MyInvoice\Service\Report\EpoEnvelope;
use MyInvoice\Service\Report\EpoSupplierBlockBuilder;

/**
 * Generátor EPO XML žádostí o poukázání chybějící částky na daňovém bonusu.
 *
 * Dvě písemnosti, jeden generátor — schémata `dpzmb1_epo2.xsd` a `dpzdb1_epo2.xsd`
 * se liší jen kořenem, kódem dokumentu a tvarem období ve `VetaD`:
 *
 *   Pisemnost(nazevSW,verzeSW) > DPZMB1|DPZDB1(verzePis) > VetaD + VetaP [+ VetaV …]
 *
 * - **DPZMB1** (§ 35d odst. 5) — měsíční daňový bonus, období = `bonus_mesic` + `bonus_rok`.
 * - **DPZDB1** (§ 35d odst. 9) — doplatek z ročního zúčtování, období = `bonus_zdobd`.
 *
 * Zbytek je znak po znaku shodný, včetně tří peněžních řádků a kritických kontrol
 * (ř. 1 > 0, 0 ≤ ř. 2 ≤ ř. 1). Dvě kopie by se rozešly stejně, jako se rozešla
 * obálka EPO popsaná v {@see EpoEnvelope}.
 *
 * ## Co ZÁMĚRNĚ negeneruje
 *
 * `VetaV` (způsob vrácení bonusu), `VetaS` a `VetaJ` (převedení na nedoplatek
 * vlastní či cizí) jsou v obou schématech volitelné a jsou to ROZHODNUTÍ plátce,
 * ne výpočet: kam peníze poslat a jestli je místo výplaty započíst. Bez UI, které
 * se na to zeptá, není z čeho je odvodit; vynechaná věta znamená výplatu běžnou
 * cestou, dosazená by tvrdila volbu, kterou uživatel neudělal.
 */
final class TaxBonusRequestXmlBuilder
{
    /**
     * Verze písemnosti. Schéma `verzePis` nijak neomezuje (`xs:string` bez
     * restrikce) a EPO ji používá jen k rozlišení vzorů tiskopisu.
     */
    private const VERZE_PIS = '01.01';

    /** Kód ULADIS je pro obě žádosti týž — liší se až `dokument`. */
    private const K_ULADIS = 'DPZ';

    /** @var array<string,array{root:string,dokument:string}> */
    private const FORMS = [
        TaxBonusClaim::FORM_MONTHLY => ['root' => 'DPZMB1', 'dokument' => 'MB1'],
        TaxBonusClaim::FORM_ANNUAL => ['root' => 'DPZDB1', 'dokument' => 'DB1'],
    ];

    /** Typ žádosti: běžná (§ 35d odst. 5/9) vs. dodatečná (po aplikaci § 38i). */
    public const ZAD_TYP_BEZNA = 'B';
    public const ZAD_TYP_DODATECNA = 'D';

    /**
     * @param array<string,mixed> $supplier Řádek dodavatele z
     *        {@see EpoSupplierBlockBuilder::loadSupplier()}.
     * @param array{verze_sw?:string,verze_pis?:string,zad_typ?:string,kc_ponech?:int} $meta
     * @return array{xml:string,warnings:list<string>}
     */
    public function build(array $supplier, TaxBonusClaim $claim, array $meta = []): array
    {
        $form = self::FORMS[$claim->formCode];
        $warnings = $claim->warnings;

        [$dom, $root] = EpoEnvelope::create(
            $form['root'],
            (string) ($meta['verze_pis'] ?? self::VERZE_PIS),
            isset($meta['verze_sw']) ? (string) $meta['verze_sw'] : null,
        );

        // ── VetaD — hlavička žádosti ────────────────────────────────────────
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', self::K_ULADIS);
        $vetaD->setAttribute('dokument', $form['dokument']);
        // Cílový FÚ. Na rozdíl od DPH/KH tu `VetaP` atribut `c_ufo` VŮBEC nemá —
        // správce daně je jen tady, takže chybějící kód nelze dohnat jinde.
        $office = trim((string) ($supplier['financial_office_code'] ?? ''));
        if ($office === '') {
            $office = '451';
            $warnings[] = 'Firma nemá vyplněný finanční úřad — '
                . 'v žádosti je předvyplněný FÚ pro Prahu 1, ověřte ho.';
        }
        $vetaD->setAttribute('c_ufo_cil', $office);
        $zadTyp = (string) ($meta['zad_typ'] ?? self::ZAD_TYP_BEZNA);
        if (!in_array($zadTyp, [self::ZAD_TYP_BEZNA, self::ZAD_TYP_DODATECNA], true)) {
            throw new \InvalidArgumentException(
                'Typ žádosti musí být B (běžná) nebo D (dodatečná).',
            );
        }
        $vetaD->setAttribute('zad_typ', $zadTyp);

        if ($claim->formCode === TaxBonusClaim::FORM_MONTHLY) {
            $vetaD->setAttribute('bonus_mesic', (string) $claim->bonusMonth);
            $vetaD->setAttribute('bonus_rok', (string) $claim->bonusYear);
        } else {
            $vetaD->setAttribute('bonus_zdobd', (string) $claim->bonusYear);
        }

        $vetaD->setAttribute('kc_bonus_celk', (string) $claim->bonusTotalCzk);
        $vetaD->setAttribute('kc_zalohy', (string) $claim->advancesCzk);
        $vetaD->setAttribute('kc_bonus_vl', (string) $claim->ownFundsCzk);
        $vetaD->setAttribute('d_bonus', $claim->bonusDateEpo());

        // Ponechání části částky na úhradu splatných povinností na dani ze závislé
        // činnosti — volba plátce, ne výpočet. Nejvýš to, o co se žádá.
        $ponech = (int) ($meta['kc_ponech'] ?? 0);
        if ($ponech > 0) {
            if ($ponech > $claim->ownFundsCzk) {
                throw new \DomainException(
                    'Ponechaná částka nesmí převýšit částku z ř. 3.',
                );
            }
            $vetaD->setAttribute('kc_ponech', (string) $ponech);
        }
        $root->appendChild($vetaD);

        // ── VetaP — identifikace plátce daně ────────────────────────────────
        $vetaP = $dom->createElement('VetaP');
        $this->fillVetaP($vetaP, $supplier);
        $root->appendChild($vetaP);

        return [
            'xml' => (string) $dom->saveXML(),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * `VetaP` žádostí DPZ NENÍ ta z DPH/KH/SH, proto sem nesmí
     * {@see EpoSupplierBlockBuilder::fillVetaP()}: ta emituje `c_ufo`, `stat`,
     * `email` i `c_telef`, které schémata DPZMB1/DPZDB1 neznají, a podání by
     * na nich spadlo. Sdílejí se jen normalizační primitiva, kde je hodnota
     * stejná bez ohledu na tiskopis.
     *
     * @param array<string,mixed> $supplier
     */
    private function fillVetaP(\DOMElement $vetaP, array $supplier): void
    {
        $vetaP->setAttribute('dic', EpoSupplierBlockBuilder::normalizeDic(
            isset($supplier['dic']) ? (string) $supplier['dic'] : null,
        ));
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
    }
}
