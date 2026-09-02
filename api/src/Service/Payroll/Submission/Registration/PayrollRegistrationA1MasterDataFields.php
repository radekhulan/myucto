<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Který údaj z formuláře A1 se dá zapsat ZPĚT do kmenových dat — a u kterého
 * to nejde a proč.
 *
 * Formulář A1 se z části plní z evidence osoby a pracovního vztahu
 * ({@see PayrollRegistrationA1DraftBuilder} to u každé hodnoty říká). Když
 * účetní hodnotu ve formuláři opraví, chce ji opravit i tam, odkud se bere —
 * jinak se rozdíl vrací pořád dokola. Tenhle katalog je jediný seznam, podle
 * kterého se to řídí; UI z něj bere jak nabídku tlačítka, tak větu „proč to
 * u tohohle údaje nejde".
 *
 * Pole, která kmenová data vůbec nevedou (režim práce, důchod, doklad
 * totožnosti), se sem NEDOSTANOU: nemají `source()`, takže se ani nehlásí jako
 * rozejité. Tady jsou jen ty, které zdroj mají, ale zapsat se do něj nedají.
 */
final class PayrollRegistrationA1MasterDataFields
{
    public const TARGET_PERSON_ADDRESS = 'person_address';
    public const TARGET_PERSON_IDENTITY = 'person_identity';
    public const TARGET_PERSON_IDENTIFIER = 'person_identifier';
    public const TARGET_TAX_RESIDENCE = 'tax_residence';
    public const TARGET_HEALTH_COVERAGE = 'health_coverage';
    public const TARGET_EMPLOYMENT_TERMS = 'employment_terms';

    /**
     * Cesta ve snímku → kam se hodnota zapíše.
     *
     * `scope` upřesňuje cíl uvnitř skupiny (druh adresy, typ identifikátoru),
     * `column` je sloupec kmenové evidence.
     *
     * @var array<string,array{target:string,scope:?string,column:string}>
     */
    private const WRITABLE = [
        'permanent_address.street' => [
            'target' => self::TARGET_PERSON_ADDRESS,
            'scope' => 'residence',
            'column' => 'street_line',
        ],
        'permanent_address.city' => [
            'target' => self::TARGET_PERSON_ADDRESS,
            'scope' => 'residence',
            'column' => 'city',
        ],
        'permanent_address.postal_code' => [
            'target' => self::TARGET_PERSON_ADDRESS,
            'scope' => 'residence',
            'column' => 'postal_code',
        ],
        'permanent_address.country_code' => [
            'target' => self::TARGET_PERSON_ADDRESS,
            'scope' => 'residence',
            'column' => 'country_code',
        ],
        'contact_address.street' => [
            'target' => self::TARGET_PERSON_ADDRESS,
            'scope' => 'mailing',
            'column' => 'street_line',
        ],
        'contact_address.city' => [
            'target' => self::TARGET_PERSON_ADDRESS,
            'scope' => 'mailing',
            'column' => 'city',
        ],
        'contact_address.postal_code' => [
            'target' => self::TARGET_PERSON_ADDRESS,
            'scope' => 'mailing',
            'column' => 'postal_code',
        ],
        'contact_address.country_code' => [
            'target' => self::TARGET_PERSON_ADDRESS,
            'scope' => 'mailing',
            'column' => 'country_code',
        ],
        'proof_identity.country_code' => [
            'target' => self::TARGET_PERSON_IDENTITY,
            'scope' => null,
            'column' => 'citizenship_country_code',
        ],
        'tax_residency.identifier' => [
            'target' => self::TARGET_PERSON_IDENTIFIER,
            'scope' => 'foreign_tax_identifier',
            'column' => 'value',
        ],
        'tax_residency.country_code' => [
            'target' => self::TARGET_TAX_RESIDENCE,
            'scope' => null,
            'column' => 'country_code',
        ],
        'health_insurance_code' => [
            'target' => self::TARGET_HEALTH_COVERAGE,
            'scope' => null,
            'column' => 'insurer_code',
        ],
        'employment.activity_code' => [
            'target' => self::TARGET_EMPLOYMENT_TERMS,
            'scope' => null,
            'column' => 'activity_code',
        ],
        'employment.relationship_detail_code' => [
            'target' => self::TARGET_EMPLOYMENT_TERMS,
            'scope' => null,
            'column' => 'jmhz_relationship_detail_code',
        ],
        'employment.contract_start_on' => [
            'target' => self::TARGET_EMPLOYMENT_TERMS,
            'scope' => null,
            'column' => 'planned_start_on',
        ],
        'employment.contract_workplace' => [
            'target' => self::TARGET_EMPLOYMENT_TERMS,
            'scope' => null,
            'column' => 'work_place',
        ],
        'employment.workplace_municipality_code' => [
            'target' => self::TARGET_EMPLOYMENT_TERMS,
            'scope' => null,
            'column' => 'jmhz_workplace_municipality_code',
        ],
        'employment.profession_code' => [
            'target' => self::TARGET_EMPLOYMENT_TERMS,
            'scope' => null,
            'column' => 'cz_isco_code',
        ],
        'foreign_legislation.country_code' => [
            'target' => self::TARGET_EMPLOYMENT_TERMS,
            'scope' => null,
            'column' => 'foreign_legislation_country_code',
        ],
    ];

    /**
     * Údaje, které se z kmenových dat berou, ale zpátky do nich nevedou.
     *
     * Věta musí říct, co se stane místo toho — „nejde to" bez pokračování
     * pošle účetní hledat tlačítko, které nikde není.
     *
     * @var array<string,string>
     */
    private const BLOCKED = [
        'employment.actual_start_on' =>
            'Skutečné datum nástupu je den, ke kterému se celá registrace '
            . 'zmrazuje. Kdyby ho zápis z tohohle formuláře posunul, profil by '
            . 'zůstal uložený k jinému dni, než ke kterému patří. Opravte '
            . 'datum na kartě pracovního vztahu a formulář otevřete znovu.',
        'employment.small_scale' =>
            'Zaměstnání malého rozsahu se v evidenci nevede jako samostatný '
            . 'příznak — plyne z druhu pracovního vztahu. Změňte druh vztahu '
            . 'na kartě pracovního vztahu.',
        'foreign_legislation.applies' =>
            'Příznak zahraniční legislativy se v evidenci nevede samostatně — '
            . 'plyne z toho, jestli je vyplněný stát. Zapište do kmenových dat '
            . 'stát zahraniční legislativy.',
        'foreign_worker.permit_from' =>
            'Pracovní oprávnění se do evidence zakládá jedině společně '
            . 's naskenovaným rozhodnutím úřadu, takže se jeho platnost odsud '
            . 'nepřepisuje. Opravte ji na kartě osoby → Oprávnění.',
        'foreign_worker.permit_to' =>
            'Pracovní oprávnění se do evidence zakládá jedině společně '
            . 's naskenovaným rozhodnutím úřadu, takže se jeho platnost odsud '
            . 'nepřepisuje. Opravte ji na kartě osoby → Oprávnění.',
    ];

    public static function writable(string $path): bool
    {
        return array_key_exists($path, self::WRITABLE);
    }

    /** @return array{target:string,scope:?string,column:string}|null */
    public static function target(string $path): ?array
    {
        return self::WRITABLE[$path] ?? null;
    }

    /**
     * Proč se údaj do kmenových dat nezapíše. `null` u zapsatelného pole.
     *
     * Neznámá cesta dostane obecnou větu — mlčet by vypadalo jako by tlačítko
     * jen chybělo.
     */
    public static function blockedReason(string $path): ?string
    {
        if (self::writable($path)) {
            return null;
        }

        return self::BLOCKED[$path]
            ?? 'Aplikace tenhle údaj o osobě nevede, zůstává jen ve snímku.';
    }

    /** @return list<string> */
    public static function writablePaths(): array
    {
        return array_keys(self::WRITABLE);
    }
}
