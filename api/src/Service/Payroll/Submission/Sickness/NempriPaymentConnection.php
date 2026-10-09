<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Sickness;

/**
 * `CtPlatebniSpojeni` — způsob výplaty mzdy zaměstnance.
 *
 * § 97 odst. 2 věta druhá zák. č. 187/2006 Sb. ukládá zaměstnavateli předat
 * spolu s podklady „údaje o způsobu výplaty mzdy, platu nebo odměny“. Bez nich
 * si je ČSSZ vyžádá výzvou a výplata dávky se zdrží. Údaj se proto bere
 * z výplatního profilu zaměstnance: bankovní účet, na který chodí mzda, nebo
 * výplata v hotovosti (`vyplatitHotovost`), po níž si ÚSSZ způsob výplaty
 * dávky vyžádá od pojištěnce (Všeobecné zásady NEMPRI 2025).
 */
final readonly class NempriPaymentConnection
{
    public const KIND_ACCOUNT_CZ = 'account_cz';
    public const KIND_ACCOUNT_FOREIGN = 'account_foreign';
    public const KIND_ADDRESS = 'address';
    public const KIND_CASH = 'cash';

    public function __construct(
        public string $kind,
        public ?string $accountPrefix = null,
        public ?string $accountNumber = null,
        public ?string $bankCode = null,
        public ?string $iban = null,
        public ?string $countryCode = null,
        public ?string $city = null,
        public ?string $street = null,
        public ?string $houseNumber = null,
        public ?string $orientationNumber = null,
        public ?string $postalCode = null,
    ) {}
}
