<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\ForeignIncome;

use MyInvoice\Service\Report\EpoDate;

/**
 * Poplatník — daňový nerezident, kterému příjem plyne.
 *
 * Jedna věta pro obě písemnosti. DPSHL1 ji nese ve `VetaH`, DPSZD1 přímo ve
 * `VetaD`, ale jádro (jméno nebo název, daňová identifikace, datum narození,
 * zahraniční adresa, stát daňové rezidence) je znak po znaku shodné. DPSHL1
 * navíc zná typ poplatníka, místo a stát narození, typ daňové identifikace
 * a typ adresy; ty jsou tu volitelné a do DPSZD1 se prostě nepromítnou.
 *
 * ## Co se tu ZÁMĚRNĚ nedopočítává
 *
 * Nic. Všechny hodnoty pocházejí od uživatele nebo z evidence, žádná se
 * neodhaduje. Chybějící volitelný údaj se do XML nezapíše — poloprázdný atribut
 * by tvrdil něco, co plátce neuvedl.
 */
final readonly class ForeignPayee
{
    /** Typ poplatníka (`typ_popl`) — číselník DPSHL1. */
    public const TYP_FYZICKA_OSOBA = '01';
    public const TYP_OBCHODNI_SPOLECNOST = '02';
    public const TYP_SDRUZENI = '03';
    public const TYP_JINA_PRAVNICKA_OSOBA = '04';
    public const TYP_STATNI_ORGANIZACE = '05';
    public const TYP_OSTATNI = '06';

    /** @var list<string> */
    public const TYPY_POPLATNIKA = [
        self::TYP_FYZICKA_OSOBA,
        self::TYP_OBCHODNI_SPOLECNOST,
        self::TYP_SDRUZENI,
        self::TYP_JINA_PRAVNICKA_OSOBA,
        self::TYP_STATNI_ORGANIZACE,
        self::TYP_OSTATNI,
    ];

    /** Typ daňové identifikace (`typ_dic`). */
    public const DIC_TYP_DIC = 'D';
    public const DIC_TYP_RODNE_CISLO = 'R';
    public const DIC_TYP_SOCIALNI_POJISTENI = 'S';
    public const DIC_TYP_JINE = 'J';

    /** @var list<string> */
    public const TYPY_DIC = [
        self::DIC_TYP_DIC,
        self::DIC_TYP_RODNE_CISLO,
        self::DIC_TYP_SOCIALNI_POJISTENI,
        self::DIC_TYP_JINE,
    ];

    /** Typ adresy (`typ_adr`). */
    public const ADRESA_BYDLISTE = '01';
    public const ADRESA_SIDLO = '02';
    public const ADRESA_NESPECIFIKOVANO = '03';

    /** @var list<string> */
    public const TYPY_ADRESY = [
        self::ADRESA_BYDLISTE,
        self::ADRESA_SIDLO,
        self::ADRESA_NESPECIFIKOVANO,
    ];

    /**
     * @param string  $taxpayerType   `typ_popl`.
     * @param ?string $firstName      `jmeno_popl` — jen u fyzické osoby.
     * @param ?string $lastName       `prijmeni_popl` — jen u fyzické osoby.
     * @param ?string $companyName    `obch_jm_popl` — jen u právnické osoby.
     * @param ?string $birthDate      `d_narozeni` / `d_nar_popl`, ISO `Y-m-d`.
     * @param ?string $taxId          `dic_popl` — identifikace ve státě rezidence.
     * @param ?string $taxIdType      `typ_dic`.
     * @param ?string $taxIdCountry   `k_stat_dic` — stát, který identifikaci vydal.
     * @param string  $residenceCountry `k_stat_dr` — stát daňové rezidence, ISO2.
     * @param string  $city           `naz_obce_popl` — zahraniční adresa, obec.
     * @param ?string $postalCode     `psc_popl`.
     * @param ?string $street         `ulice_c_popl`.
     * @param string  $addressType    `typ_adr`.
     * @param ?string $birthPlace     `misto_nar` — jen u fyzické osoby.
     * @param ?string $birthCountry   `k_stat_nar` — jen u fyzické osoby, ISO2.
     */
    public function __construct(
        public string $taxpayerType,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $companyName,
        public ?string $birthDate,
        public ?string $taxId,
        public ?string $taxIdType,
        public ?string $taxIdCountry,
        public string $residenceCountry,
        public string $city,
        public ?string $postalCode,
        public ?string $street,
        public string $addressType = self::ADRESA_BYDLISTE,
        public ?string $birthPlace = null,
        public ?string $birthCountry = null,
    ) {
        if (!in_array($taxpayerType, self::TYPY_POPLATNIKA, true)) {
            throw new \InvalidArgumentException(
                'Typ poplatníka musí být 01 až 06 podle číselníku tiskopisu.',
            );
        }
        if (!in_array($addressType, self::TYPY_ADRESY, true)) {
            throw new \InvalidArgumentException(
                'Typ adresy musí být 01 (bydliště), 02 (sídlo) nebo 03 (nespecifikováno).',
            );
        }

        // Kritická kontrola obou schémat: buď jméno a příjmení fyzické osoby,
        // nebo název právnické osoby — nikdy obojí a nikdy nic.
        $hasPerson = $firstName !== null && $lastName !== null;
        $hasCompany = $companyName !== null;
        if ($hasPerson === $hasCompany) {
            throw new \InvalidArgumentException(
                'Vyplň buď jméno a příjmení fyzické osoby, nebo název právnické osoby'
                . ' — ne obojí současně.',
            );
        }
        if (($firstName === null) !== ($lastName === null)) {
            throw new \InvalidArgumentException(
                'U fyzické osoby musí být vyplněno jméno i příjmení.',
            );
        }
        if ($this->isIndividual() !== $hasPerson) {
            throw new \InvalidArgumentException(
                'Typ poplatníka 01 znamená fyzickou osobu; ostatní typy vyžadují'
                . ' název právnické osoby.',
            );
        }

        // Kritická kontrola DPSHL1: u fyzické osoby musí být vyplněno datum
        // narození NEBO daňová identifikace ve státě rezidence.
        if ($hasPerson && $birthDate === null && ($taxId === null || $taxId === '')) {
            throw new \InvalidArgumentException(
                'U fyzické osoby vyplň datum narození nebo daňovou identifikaci'
                . ' ve státě daňové rezidence.',
            );
        }

        self::assertCountry($residenceCountry, 'Stát daňové rezidence');
        if ($residenceCountry === 'CZ') {
            throw new \InvalidArgumentException(
                'Poplatníkem je daňový nerezident — stát daňové rezidence nesmí být CZ.',
            );
        }
        if ($taxIdCountry !== null) {
            self::assertCountry($taxIdCountry, 'Stát vydávající daňovou identifikaci');
        }
        if ($birthCountry !== null) {
            self::assertCountry($birthCountry, 'Stát narození');
        }
        if ($taxIdType !== null && !in_array($taxIdType, self::TYPY_DIC, true)) {
            throw new \InvalidArgumentException(
                'Typ daňové identifikace musí být D, R, S nebo J.',
            );
        }
        if ($taxIdType !== null && ($taxId === null || $taxId === '')) {
            throw new \InvalidArgumentException(
                'Typ daňové identifikace nelze uvést bez samotné identifikace.',
            );
        }
        if (trim($city) === '') {
            throw new \InvalidArgumentException(
                'Zahraniční adresa poplatníka musí obsahovat obec.',
            );
        }
        if ($birthDate !== null) {
            EpoDate::requireIso($birthDate, 'Datum narození poplatníka');
        }
    }

    public function isIndividual(): bool
    {
        return $this->taxpayerType === self::TYP_FYZICKA_OSOBA;
    }

    /** @return array<string,mixed> */
    public function toSummary(): array
    {
        return [
            'taxpayer_type' => $this->taxpayerType,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'company_name' => $this->companyName,
            'birth_date' => $this->birthDate,
            'tax_id' => $this->taxId,
            'tax_id_type' => $this->taxIdType,
            'tax_id_country' => $this->taxIdCountry,
            'residence_country' => $this->residenceCountry,
            'city' => $this->city,
            'postal_code' => $this->postalCode,
            'street' => $this->street,
            'address_type' => $this->addressType,
            'birth_place' => $this->birthPlace,
            'birth_country' => $this->birthCountry,
        ];
    }

    private static function assertCountry(string $code, string $label): void
    {
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            throw new \InvalidArgumentException(
                $label . ' musí být dvoumístný kód státu velkými písmeny.',
            );
        }
    }
}
