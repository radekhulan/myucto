<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\ForeignIncome;

use DOMElement;
use MyInvoice\Service\Report\EpoDate;
use MyInvoice\Service\Report\EpoEnvelope;
use MyInvoice\Service\Report\EpoPayerBlockBuilder;

/**
 * Generátor EPO XML obou písemností správce daně k příjmům nerezidentů —
 * DPSHL1 (oznámení podle § 38da) a DPSZD1 (hlášení o zajištění daně podle § 38e).
 *
 * Obě mají kód ULADIS `DPS`, obě mají `VetaP` z rodiny DPZ/DPS (bez `c_ufo`,
 * `stat`, `email` a `c_telef`, zato se `sest_email`), takže plátce plní společný
 * {@see EpoPayerBlockBuilder} — stejně jako vyúčtování a žádosti o bonus.
 *
 * ## Pořadí vět
 *
 * Obě schémata mají `xs:sequence`, ne `xs:all`:
 *
 * - DPSHL1: `VetaD, VetaP, VetaR, VetaH, VetaU…, Prilohy`
 * - DPSZD1: `VetaD, VetaP, VetaR, Prilohy`
 *
 * ## Co ZÁMĚRNĚ negeneruje
 *
 * - **`VetaR` (textová příloha)** a **`Prilohy/ObecnaPriloha`** — obojí je
 *   volitelné a je to obsah, který píše člověk. Prázdná příloha je pravdivá.
 * - **`zast_*` ve `VetaP`** (podepisující zástupce) — plní se jen tam, kde
 *   podání podává zástupce; aplikace podává za plátce samotného.
 * - **`pohlavi`** — od zdaňovacího období 2024 je položka podle popisu
 *   tiskopisu neobsazená, takže by ji vyplňovala jen setrvačnost.
 */
final class ForeignIncomeXmlBuilder
{
    /**
     * Verze písemnosti. Schéma `verzePis` neomezuje (`xs:string` bez restrikce),
     * EPO ji používá jen k rozlišení vzorů tiskopisu.
     */
    private const VERZE_PIS = '01.01';

    /** Kód ULADIS je pro obě písemnosti týž — liší se až `dokument`. */
    private const K_ULADIS = 'DPS';

    /**
     * @param array<string,mixed> $supplier Řádek z
     *        {@see \MyInvoice\Service\Report\EpoSupplierBlockBuilder::loadSupplier()}.
     * @param array{verze_sw?:string,verze_pis?:string} $meta
     * @return array{xml:string,warnings:list<string>}
     */
    public function buildIncomeNotice(
        array $supplier,
        ForeignIncomeNotice $notice,
        array $meta = [],
    ): array {
        $warnings = $notice->warnings;

        [$dom, $root] = EpoEnvelope::create(
            'DPSHL1',
            (string) ($meta['verze_pis'] ?? self::VERZE_PIS),
            isset($meta['verze_sw']) ? (string) $meta['verze_sw'] : null,
        );

        // ── VetaD — hlavička oznámení ───────────────────────────────────────
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', self::K_ULADIS);
        $vetaD->setAttribute('dokument', 'HL1');
        $vetaD->setAttribute('c_ufo_cil', $this->financialOffice($supplier, $warnings));
        $vetaD->setAttribute('hl_typ', $notice->variant);
        if ($notice->variant === ForeignIncomeNotice::TYP_NASLEDNE) {
            if ($notice->discoveredOn !== null) {
                $vetaD->setAttribute('d_zjist', EpoDate::toEpo(
                    $notice->discoveredOn,
                    'Datum zjištění důvodů pro následné oznámení',
                ));
            } else {
                $warnings[] = 'Následné oznámení má uvádět datum zjištění důvodů'
                    . ' pro jeho podání.';
            }
            if ($notice->note === null) {
                $warnings[] = 'U následného oznámení se do poznámky uvádí, ke kterému'
                    . ' řádnému oznámení se vztahuje.';
            }
        }
        $root->appendChild($vetaD);

        // ── VetaP — plátce daně ─────────────────────────────────────────────
        $vetaP = $dom->createElement('VetaP');
        EpoPayerBlockBuilder::fillVetaP($vetaP, $supplier, true);
        $root->appendChild($vetaP);

        // ── VetaH — poplatník a jeden druh příjmu ───────────────────────────
        $payee = $notice->payee;
        $vetaH = $dom->createElement('VetaH');
        $vetaH->setAttribute('typ_popl', $payee->taxpayerType);
        if ($payee->isIndividual()) {
            $this->set($vetaH, 'jmeno_popl', $payee->firstName);
            $this->set($vetaH, 'prijmeni_popl', $payee->lastName);
            if ($payee->birthDate !== null) {
                $vetaH->setAttribute('d_narozeni', EpoDate::toEpo(
                    $payee->birthDate,
                    'Datum narození poplatníka',
                ));
            }
            $this->set($vetaH, 'misto_nar', $payee->birthPlace);
            $this->set($vetaH, 'k_stat_nar', $payee->birthCountry);
        } else {
            $this->set($vetaH, 'obch_jm_popl', $payee->companyName);
        }
        $this->set($vetaH, 'dic_popl', $payee->taxId);
        $this->set($vetaH, 'typ_dic', $payee->taxIdType);
        $this->set($vetaH, 'k_stat_dic', $payee->taxIdCountry);
        $vetaH->setAttribute('k_stat_dr', $payee->residenceCountry);
        $vetaH->setAttribute('typ_adr', $payee->addressType);
        $vetaH->setAttribute('naz_obce_popl', $payee->city);
        $this->set($vetaH, 'psc_popl', $payee->postalCode);
        $this->set($vetaH, 'ulice_c_popl', $payee->street);

        $vetaH->setAttribute('druh_prij', (string) $notice->incomeKind);
        $vetaH->setAttribute(
            'k_rozl_prij',
            ForeignIncomeKindCatalog::group($notice->incomeKind),
        );
        $vetaH->setAttribute('sazba', $this->decimal($notice->rateTenthsOfPercent, 1));
        $vetaH->setAttribute('zp_uhrady', $notice->paymentMode);
        if ($notice->paymentDate !== null) {
            $vetaH->setAttribute('d_uhrady', EpoDate::toEpo(
                $notice->paymentDate,
                'Datum úhrady poplatníkovi',
            ));
        } else {
            $vetaH->setAttribute('r_uhrady', (string) $notice->paymentYear);
        }
        $vetaH->setAttribute('kc_uhrady', $this->decimal($notice->paidAmountMinor, 2));
        $vetaH->setAttribute('kc_zakldane', $this->decimal($notice->taxBaseMinor, 2));
        $vetaH->setAttribute('sraz_dan', (string) $notice->withheldTaxCzk);
        if ($notice->grossIncomeMinor !== null) {
            $vetaH->setAttribute('kc_hrubprij', $this->decimal($notice->grossIncomeMinor, 2));
            $vetaH->setAttribute('kc_pojistne', (string) $notice->mandatoryInsuranceCzk);
        }
        if ($notice->foreignGrossMinor !== null) {
            $vetaH->setAttribute(
                'kc_hrubprij_zahr',
                $this->decimal($notice->foreignGrossMinor, 2),
            );
            $vetaH->setAttribute('mena_hr_prij', (string) $notice->foreignGrossCurrency);
        }
        $this->set($vetaH, 'k_meny', $notice->paymentCurrency);
        if ($notice->exchangeRateThousandths !== null) {
            $vetaH->setAttribute('kurz', $this->decimal($notice->exchangeRateThousandths, 3));
        }
        if ($notice->withholdingDueOn !== null) {
            $vetaH->setAttribute('d_povsraz', EpoDate::toEpo(
                $notice->withholdingDueOn,
                'Den povinnosti srazit daň',
            ));
        }
        if ($notice->remittanceDueOn !== null) {
            $vetaH->setAttribute('d_splat', EpoDate::toEpo(
                $notice->remittanceDueOn,
                'Datum splatnosti odvodu',
            ));
        }
        $this->set($vetaH, 'poznamka', $notice->note);
        $root->appendChild($vetaH);

        // ── VetaU — odvody sražené daně (ř. 32 až 34) ───────────────────────
        // U osvobozeného příjmu se tyhle řádky podle pokynů nevyplňují: daň se
        // nesrazila, takže se ani neodváděla.
        if ($notice->isExemptIncome()) {
            if ($notice->remittances !== []) {
                throw new \DomainException(
                    'U osvobozeného příjmu se odvody sražené daně nevyplňují.',
                );
            }
        } else {
            if ($notice->remittances === []) {
                $warnings[] = 'Oznámení neuvádí žádný odvod sražené daně'
                    . ' — doplňte datum a částku platby správci daně.';
            }
            foreach ($notice->remittances as $remittance) {
                $vetaU = $dom->createElement('VetaU');
                $vetaU->setAttribute('d_odv', EpoDate::toEpo(
                    $remittance->paidOn,
                    'Datum odvodu sražené daně',
                ));
                $vetaU->setAttribute('kc_odv', (string) $remittance->amountCzk);
                $this->set($vetaU, 'ucet', $remittance->account);
                $root->appendChild($vetaU);
            }
            $remitted = $notice->remittedTotalCzk();
            if ($remitted !== 0 && $remitted !== $notice->withheldTaxCzk) {
                $warnings[] = sprintf(
                    'Úhrn odvodů (%d Kč) se neshoduje se sraženou daní (%d Kč).',
                    $remitted,
                    $notice->withheldTaxCzk,
                );
            }
        }

        return [
            'xml' => (string) $dom->saveXML(),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param array<string,mixed> $supplier
     * @param array{verze_sw?:string,verze_pis?:string} $meta
     * @return array{xml:string,warnings:list<string>}
     */
    public function buildSecurityNotice(
        array $supplier,
        TaxSecurityNotice $notice,
        array $meta = [],
    ): array {
        $warnings = $notice->warnings;

        [$dom, $root] = EpoEnvelope::create(
            'DPSZD1',
            (string) ($meta['verze_pis'] ?? self::VERZE_PIS),
            isset($meta['verze_sw']) ? (string) $meta['verze_sw'] : null,
        );

        // ── VetaD — hlavička i celá věcná část ──────────────────────────────
        // Na rozdíl od DPSHL1 tu poplatník nemá vlastní větu; tiskopis 25 5544
        // ho nese přímo v hlavičce.
        $payee = $notice->payee;
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', self::K_ULADIS);
        $vetaD->setAttribute('dokument', 'ZD1');
        $vetaD->setAttribute('c_ufo_cil', $this->financialOffice($supplier, $warnings));
        $vetaD->setAttribute('hl_typ', $notice->variant);

        if ($payee->isIndividual()) {
            $this->set($vetaD, 'jmeno_popl', $payee->firstName);
            $this->set($vetaD, 'prijmeni_popl', $payee->lastName);
        } else {
            $this->set($vetaD, 'obch_jm_popl', $payee->companyName);
        }
        $this->set($vetaD, 'dic_popl', $payee->taxId);
        if ($payee->birthDate !== null) {
            $vetaD->setAttribute('d_nar_popl', EpoDate::toEpo(
                $payee->birthDate,
                'Datum narození poplatníka',
            ));
        }
        $vetaD->setAttribute('naz_obce_popl', $payee->city);
        $this->set($vetaD, 'psc_popl', $payee->postalCode);
        $this->set($vetaD, 'ulice_c_popl', $payee->street);
        $vetaD->setAttribute('k_stat_dr', $payee->residenceCountry);
        $this->set($vetaD, 'adr_provozovny_cr', $notice->permanentEstablishmentAddress);

        $vetaD->setAttribute('druh_prij', $notice->incomeDescription);
        $vetaD->setAttribute('sazba', $notice->rate);
        $vetaD->setAttribute('kc_prijem', $this->decimal($notice->incomeMinor, 2));
        $vetaD->setAttribute('kc_zajisteni', (string) $notice->securedTaxCzk);
        $vetaD->setAttribute('d_ucpripadu', EpoDate::toEpo(
            $notice->receivableOn,
            'Den vzniku pohledávky',
        ));
        $vetaD->setAttribute('d_rozhodne', EpoDate::toEpo(
            $notice->decisiveOn,
            'Rozhodné datum',
        ));
        if ($notice->remittedOn !== null) {
            $vetaD->setAttribute('d_odvodu', EpoDate::toEpo(
                $notice->remittedOn,
                'Datum odvodu zajištění daně',
            ));
        } else {
            $warnings[] = 'Hlášení neuvádí datum odvodu zajištění daně'
                . ' — doplňte ho, jakmile bude odvedeno.';
        }
        $this->set($vetaD, 'poznamka', $notice->note);
        if ($notice->variant === TaxSecurityNotice::TYP_NASLEDNE && $notice->note === null) {
            $warnings[] = 'U následného hlášení se do poznámky uvádí, ke kterému'
                . ' řádnému hlášení se vztahuje a proč se podává.';
        }
        $root->appendChild($vetaD);

        // ── VetaP — plátce daně ─────────────────────────────────────────────
        $vetaP = $dom->createElement('VetaP');
        EpoPayerBlockBuilder::fillVetaP($vetaP, $supplier, true);
        $root->appendChild($vetaP);

        return [
            'xml' => (string) $dom->saveXML(),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function set(DOMElement $element, string $name, ?string $value): void
    {
        if ($value === null || trim($value) === '') {
            return;
        }
        $element->setAttribute($name, $value);
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
            $warnings[] = 'Firma nemá vyplněný finanční úřad — v podání je'
                . ' předvyplněný FÚ pro Prahu 1, ověřte ho.';

            return '451';
        }

        return $office;
    }

    /**
     * Celé číslo v jednotkách 10^-$scale → desetinné číslo. Přes celá čísla,
     * ne přes float: dělení by u velkých částek posunulo poslední místo.
     */
    private function decimal(int $value, int $scale): string
    {
        $divisor = 10 ** $scale;
        $sign = $value < 0 ? '-' : '';
        $abs = abs($value);

        return sprintf(
            '%s%d.%0' . $scale . 'd',
            $sign,
            intdiv($abs, $divisor),
            $abs % $divisor,
        );
    }
}
