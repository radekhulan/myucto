<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Kanonická podoba OSOBY převzaté z předchozího mzdového systému.
 *
 * Tohle je hranice mezi čtečkou konkrétního programu (PAMICA, PREMIER, …) a zápisem
 * ({@see PayrollTakeoverPersonWriter}): čtečka údaje jen přeloží sem, zápis o jejím
 * programu neví nic. Co zdroj nenese, zůstane na výchozí hodnotě a zápis to přeskočí,
 * takže nový zdroj může začít s pár poli a přidávat další bez zásahu do zápisu.
 *
 * Tvar vnořených polí je tvar, který přijímají karty osoby (adresa) a vyživované
 * osoby (dítě); čtečka je plní už normalizované a oříznuté na délku sloupců.
 */
final readonly class PayrollTakeoverPerson
{
    /**
     * @param array<string,?string> $identity údaje identity ČSSZ, které se doplňují jen
     *        do prázdných polí: `title_prefix`, `title_suffix`, `birth_date`, `birth_place`,
     *        `birth_country_code`, `citizenship_country_code`, `sex`
     * @param ?string $birthSurname rodné příjmení; čtečka ho nechá `null`, když je
     *        shodné s příjmením
     * @param array{street_line:string,city:string,postal_code:string,country_code:string}|null $residence trvalý pobyt
     * @param array{street_line:string,city:string,postal_code:string,country_code:string}|null $mailing kontaktní adresa
     * @param list<PayrollTakeoverPayoutAccount> $payoutAccounts první aktivní účet je hlavní
     * @param ?string $payoutAccountsPaidOn den poslední výplaty na účet (doklad pro ověření)
     * @param ?PayrollTakeoverEvidencePeriod $taxResidence stav `czech-resident` nebo `non-resident`
     * @param ?PayrollTakeoverEvidencePeriod $healthCoverage stav = kód zdravotní pojišťovny
     * @param list<PayrollTakeoverEvidencePeriod> $healthCoverageHistory zdravotní pojišťovny po úsecích
     *        (celé měsíce, navazující); zdroj, který historii zná, ji nese místo `$healthCoverage`
     * @param ?PayrollTakeoverEvidencePeriod $socialJurisdiction stav `czech` nebo `foreign`
     * @param list<PayrollTakeoverEvidencePeriod> $taxDeclarations prohlášení poplatníka po úsecích
     * @param list<PayrollTakeoverEvidencePeriod> $socialDiscountClaims sleva pracujícího důchodce po úsecích
     * @param list<array{order:int,code:string,reference:string,given_name:?string,family_name:?string,birth_number:?string,from:?string,to:?string}> $children
     *        děti s daňovým zvýhodněním
     * @param int $childrenWithoutCredit děti vedené bez zvýhodnění (jen do počtů)
     * @param ?string $firstSignedPeriod první měsíc (`YYYY-MM`) s podepsaným prohlášením
     * @param array<int,PayrollTakeoverOpeningMonth> $openingMonths úhrny měsíců roku podle čísla měsíce
     * @param list<PayrollTakeoverEvidencePeriod> $healthMinimumExemptions úseky, ve kterých zdroj
     *        nedoplácel zdravotní pojištění do minimálního vyměřovacího základu; stav = důvod
     *        podle {@see \MyInvoice\Service\Payroll\HealthInsurance\HealthMinimumReductionReason}
     *        (`unverified`, když ho zdroj nevede)
     * @param array<string,string> $identifiers identifikátory karty osoby podle typu
     *        (`foreign_tax_identifier` ve tvaru `CC:HODNOTA`); typ, který karta už vede, se nepřepisuje
     */
    public function __construct(
        public string $key,
        public array $identity = [],
        public ?string $birthSurname = null,
        public ?array $residence = null,
        public ?array $mailing = null,
        public ?string $email = null,
        public ?string $phone = null,
        public array $payoutAccounts = [],
        public ?string $payoutAccountsPaidOn = null,
        public ?PayrollTakeoverEvidencePeriod $taxResidence = null,
        public ?PayrollTakeoverEvidencePeriod $healthCoverage = null,
        public ?PayrollTakeoverEvidencePeriod $socialJurisdiction = null,
        public array $taxDeclarations = [],
        public array $socialDiscountClaims = [],
        public array $children = [],
        public int $childrenWithoutCredit = 0,
        public ?string $firstSignedPeriod = null,
        public array $openingMonths = [],
        public array $healthCoverageHistory = [],
        public array $healthMinimumExemptions = [],
        public array $identifiers = [],
    ) {}
}
