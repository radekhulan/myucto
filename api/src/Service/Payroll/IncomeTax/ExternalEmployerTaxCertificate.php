<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Potvrzení o zdanitelných příjmech od jiného (předchozího) plátce daně.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Co po dokladu žádá § 38ch odst. 3
 * ─────────────────────────────────────────────────────────────────────────────
 * „Plátce daně provede roční zúčtování záloh a daňového zvýhodnění JEN na základě
 * dokladů za uplynulé zdaňovací období od všech předchozích plátců daně
 *  o zúčtované nebo vyplacené mzdě,
 *  sražených zálohách na daň z těchto příjmů,
 *  poskytnuté měsíční slevě na dani podle § 35ba a 35c
 *  a vyplacených měsíčních daňových bonusech."
 *
 * Čtyři skupiny, uzavřený výčet — váže se na ně slovo „jen", ne „zejména".
 * Slevy jsou dvě samostatné položky (§ 35ba a § 35c), ne jedna.
 *
 * Podobu dokladu určuje § 38j odst. 3 („doklad o souhrnných údajích uvedených ve
 * mzdovém listě") a obsah mzdového listu § 38j odst. 2 písm. f) a g). Tiskopisem
 * je 25 5460 MFin 5460 — vzor č. 33.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Proč jsou částky nullable
 * ─────────────────────────────────────────────────────────────────────────────
 * `null` znamená „na potvrzení to není", `0` znamená „je tam nula". Kdyby se
 * chybějící údaj nesl jako nula, roční porovnání podle § 35d odst. 7 by vyšlo
 * z nižšího úhrnu už vyplacených bonusů (nebo nižšího úhrnu záloh) a vznikl by
 * PŘEPLATEK, KTERÝ POPLATNÍKOVI NENÁLEŽÍ. Proto se neúplné potvrzení nedopočítává
 * — shodí zúčtování na `external_certificate_incomplete`.
 *
 * Pozn. k tiskopisu: řádek „úhrn poskytnutých měsíčních slev" formulář nemá.
 * Vypovídá je nepřímo — ř. 8 je záloha SKUTEČNĚ sražená, tedy už po slevách,
 * a ř. 11, ř. 12 a údaj o prohlášení v záhlaví nesou MĚSÍCE nároku. Účetní tu
 * částku z potvrzení odečte; modul si ji nedomýšlí a žádá ji zadat.
 */
final readonly class ExternalEmployerTaxCertificate implements JsonSerializable
{
    /**
     * Údaje, které § 38ch odst. 3 vyjmenovává jako podmínku provedení zúčtování.
     * Klíč = název pole, hodnota = kód do i18n a do snapshotu.
     */
    public const REQUIRED_STATUTORY_FIELDS = [
        'grossIncomeMinorUnits' => 'gross_income',
        'advanceBaseMinorUnits' => 'advance_base',
        'advanceTaxMinorUnits' => 'advance_tax',
        'nonRefundableCreditMinorUnits' => 'credit_35ba',
        'childCreditMinorUnits' => 'credit_35c',
        'taxBonusMinorUnits' => 'tax_bonus',
    ];

    public function __construct(
        public string $certificateReference,
        /** ř. 5 tiskopisu — základ daně, tj. základ pro výpočet zálohy (§ 38j odst. 2 písm. f) bod 3). */
        public ?int $advanceBaseMinorUnits,
        /** ř. 8 tiskopisu — záloha na daň celkem, tj. skutečně sražená (§ 38j odst. 2 písm. f) bod 7). */
        public ?int $advanceTaxMinorUnits,
        public TaxEvidenceStatus $evidenceStatus,
        public ?string $evidenceReference = null,
        /** ř. 1 tiskopisu — úhrn zúčtovaných příjmů („zúčtovaná nebo vyplacená mzda"). */
        public ?int $grossIncomeMinorUnits = null,
        /** Úhrn měsíčně poskytnutých slev podle § 35ba. */
        public ?int $nonRefundableCreditMinorUnits = null,
        /** Úhrn měsíčně poskytnutých slev podle § 35c (daňové zvýhodnění formou slevy). */
        public ?int $childCreditMinorUnits = null,
        /** ř. 9 tiskopisu — úhrn vyplacených měsíčních daňových bonusů. */
        public ?int $taxBonusMinorUnits = null,
        public ?string $payerName = null,
        public ?string $payerTaxIdentification = null,
        /** § 38ch odst. 3 věta druhá — do 15. února po uplynutí zdaňovacího období. */
        public ?string $receivedOn = null,
        /**
         * Tiskopis „za období od“ — začátek zaměstnání u předchozího plátce.
         *
         * § 38ch odst. 1 i § 38g odst. 2 mluví o plátcích „POSTUPNĚ“. Bez
         * období nejde poznat, jestli plátci šli za sebou, nebo se překrývali —
         * a překryv znamená povinnost podat přiznání a zákaz zúčtování.
         * `null` proto znamená „nevíme“, ne „souběh nebyl“.
         */
        public ?string $employmentFrom = null,
        /** Tiskopis „za období do“; `null` u vztahu, který k 31. 12. trval. */
        public ?string $employmentTo = null,
    ) {
        if (trim($certificateReference) === '') {
            throw new InvalidArgumentException('External tax certificate reference must not be empty.');
        }
        foreach (self::REQUIRED_STATUTORY_FIELDS as $field => $_code) {
            $value = $this->{$field};
            if ($value !== null && $value < 0) {
                throw new InvalidArgumentException('External tax certificate amounts cannot be negative.');
            }
        }
        if ($receivedOn !== null && preg_match('~^\d{4}-\d{2}-\d{2}$~', $receivedOn) !== 1) {
            throw new InvalidArgumentException(
                'External tax certificate receipt date must be an ISO date.',
            );
        }
        foreach ([$employmentFrom, $employmentTo] as $date) {
            if ($date !== null && preg_match('~^\d{4}-\d{2}-\d{2}$~', $date) !== 1) {
                throw new InvalidArgumentException(
                    'External tax certificate employment period must use ISO dates.',
                );
            }
        }
        if ($employmentFrom !== null && $employmentTo !== null && $employmentTo < $employmentFrom) {
            throw new InvalidArgumentException(
                'External tax certificate employment period must not end before it starts.',
            );
        }
    }

    /**
     * Překrývá se zaměstnání u tohohle plátce se zaměstnáním u jiného?
     *
     * `null` = nevíme, protože aspoň jedno z potvrzení období nenese. Rozdíl
     * proti `false` je podstatný: `false` znamená „plátci šli postupně“, `null`
     * znamená „z podkladu to nejde říct“.
     */
    public function overlapsPeriodOf(self $other, int $taxYear): ?bool
    {
        if ($this->employmentFrom === null || $other->employmentFrom === null) {
            return null;
        }
        $yearEnd = sprintf('%04d-12-31', $taxYear);
        $thisTo = $this->employmentTo ?? $yearEnd;
        $otherTo = $other->employmentTo ?? $yearEnd;

        return $this->employmentFrom <= $otherTo && $other->employmentFrom <= $thisTo;
    }

    /**
     * Kódy údajů, které § 38ch odst. 3 žádá a potvrzení je nenese.
     *
     * Prázdný seznam = doklad je úplný. Cokoli jiného je překážka, ne důvod
     * k dopočtu nulou.
     *
     * @return list<string>
     */
    public function missingStatutoryFields(): array
    {
        $missing = [];
        foreach (self::REQUIRED_STATUTORY_FIELDS as $field => $code) {
            if ($this->{$field} === null) {
                $missing[] = $code;
            }
        }

        return $missing;
    }

    /** Nese potvrzení všechny čtyři skupiny údajů podle § 38ch odst. 3? */
    public function isComplete(): bool
    {
        return $this->missingStatutoryFields() === [];
    }

    /** § 38ch odst. 4: do úhrnu se smí vzít jen doložené potvrzení. */
    public function isVerified(): bool
    {
        return $this->evidenceStatus === TaxEvidenceStatus::Verified;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'certificate_reference' => $this->certificateReference,
            'payer_name' => $this->payerName,
            'payer_tax_identification' => $this->payerTaxIdentification,
            'received_on' => $this->receivedOn,
            'employment_from' => $this->employmentFrom,
            'employment_to' => $this->employmentTo,
            'gross_income_minor_units' => $this->grossIncomeMinorUnits,
            'advance_base_minor_units' => $this->advanceBaseMinorUnits,
            'advance_tax_minor_units' => $this->advanceTaxMinorUnits,
            'non_refundable_credit_minor_units' => $this->nonRefundableCreditMinorUnits,
            'child_credit_minor_units' => $this->childCreditMinorUnits,
            'tax_bonus_minor_units' => $this->taxBonusMinorUnits,
            'evidence_status' => $this->evidenceStatus->value,
            'evidence_reference' => $this->evidenceReference,
            'missing_statutory_fields' => $this->missingStatutoryFields(),
        ];
    }
}
