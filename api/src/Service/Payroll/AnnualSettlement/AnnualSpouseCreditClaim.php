<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use DateTimeImmutable;
use JsonSerializable;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;

/**
 * Nárok na slevu na manžela (§ 35ba odst. 1 písm. b), § 35bb).
 *
 * Identita manžela i dítěte žije v `payroll_dependants`; tady jsou jen odkazy
 * a údaje, podle kterých se posoudí podmínky. Konstruktor odmítá jen nesmysl
 * (prázdné měsíce, záporný příjem); podmínky nároku posuzuje
 * {@see conditionFailures()} a nesplnění zastaví zúčtování překážkou, ne
 * uložení formuláře.
 */
final readonly class AnnualSpouseCreditClaim implements JsonSerializable
{
    /** Vyživované dítě poplatníka podle § 35bb odst. 2 a 3 — vnuk jen v péči nahrazující péči rodičů. */
    private const CHILD_RELATIONS = ['child_own', 'child_adopted', 'child_in_care', 'child_of_spouse'];

    public function __construct(
        public string $spouseReference,
        public string $spouseRelation,
        /** Od kdy je manžel v evidenci vyživovaných osob (typicky den sňatku). */
        public string $spouseExistenceFrom,
        public ?string $spouseExistenceTo,
        /** Má manžel v evidenci průkaz ZTP/P. */
        public bool $spouseZtpP,
        public string $childReference,
        public string $childRelation,
        public string $childBirthDate,
        /** Bit 0 = leden. Měsíce, na jejichž počátku byly podmínky splněny (§ 35ba odst. 3). */
        public int $monthsMask,
        public int $ztpPMonthsMask,
        /** § 35bb odst. 2 písm. b) a odst. 4: vlastní příjem manžela za rok; `null` = nevíme. */
        public ?int $spouseIncomeMinorUnits,
        public TaxEvidenceStatus $evidenceStatus = TaxEvidenceStatus::Unverified,
        public ?string $evidenceReference = null,
    ) {
        foreach ([$spouseExistenceFrom, $childBirthDate] as $date) {
            self::assertDate($date);
        }
        if ($spouseExistenceTo !== null) {
            self::assertDate($spouseExistenceTo);
        }
        if ($monthsMask < 1 || $monthsMask > AnnualNonTaxableClaim::ALL_MONTHS_MASK) {
            throw new \InvalidArgumentException(
                'U slevy na manžela je potřeba označit alespoň jeden měsíc.',
            );
        }
        if ($ztpPMonthsMask < 0 || $ztpPMonthsMask > AnnualNonTaxableClaim::ALL_MONTHS_MASK) {
            throw new \InvalidArgumentException('Měsíce ZTP/P nejsou platné.');
        }
        if ($spouseIncomeMinorUnits !== null && $spouseIncomeMinorUnits < 0) {
            throw new \InvalidArgumentException('Vlastní příjem manžela nesmí být záporný.');
        }
    }

    public function isVerified(): bool
    {
        return $this->evidenceStatus === TaxEvidenceStatus::Verified;
    }

    public function months(): int
    {
        return self::bitCount($this->monthsMask);
    }

    public function ztpPMonths(): int
    {
        return self::bitCount($this->ztpPMonthsMask);
    }

    /**
     * Které podmínky § 35bb nejsou splněné. Prázdný seznam = nárok trvá.
     *
     * Posuzuje se každý označený měsíc k jeho PRVNÍMU dni (§ 35ba odst. 3):
     * dítě se musí narodit nejpozději ten den a nesmí ještě mít 3 roky,
     * manžel musí být v evidenci od toho dne. Dítě, které dovrší 3 roky
     * 15. května, zakládá nárok ještě za květen, ne za červen.
     *
     * @return list<string>
     */
    public function conditionFailures(int $taxYear, int $incomeLimitMinorUnits): array
    {
        $failures = [];
        if ($this->spouseRelation !== 'spouse') {
            $failures[] = 'spouse_relation';
        }
        if (!in_array($this->childRelation, self::CHILD_RELATIONS, true)) {
            $failures[] = 'child_relation';
        }
        if ($this->spouseIncomeMinorUnits === null) {
            $failures[] = 'spouse_income_missing';
        } elseif ($this->spouseIncomeMinorUnits > $incomeLimitMinorUnits) {
            $failures[] = 'spouse_income_over_limit';
        }
        if (($this->ztpPMonthsMask & ~$this->monthsMask) !== 0) {
            $failures[] = 'ztp_p_month_outside_claim';
        }
        if ($this->ztpPMonthsMask !== 0 && !$this->spouseZtpP) {
            $failures[] = 'ztp_p_not_in_evidence';
        }

        $childBirth = new DateTimeImmutable($this->childBirthDate);
        $childThird = $childBirth->modify('+3 years');
        $spouseFrom = new DateTimeImmutable($this->spouseExistenceFrom);
        $spouseTo = $this->spouseExistenceTo === null
            ? null
            : new DateTimeImmutable($this->spouseExistenceTo);
        for ($month = 1; $month <= 12; $month++) {
            if (($this->monthsMask & (1 << ($month - 1))) === 0) {
                continue;
            }
            $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $taxYear, $month));
            if ($childBirth > $first || $childThird <= $first) {
                $failures[] = 'child_not_under_three';
            }
            if ($spouseFrom > $first || ($spouseTo !== null && $spouseTo < $first)) {
                $failures[] = 'spouse_not_in_evidence';
            }
        }

        return array_values(array_unique($failures));
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'spouse_reference' => $this->spouseReference,
            'child_reference' => $this->childReference,
            'months_mask' => $this->monthsMask,
            'months' => $this->months(),
            'ztp_p_months_mask' => $this->ztpPMonthsMask,
            'ztp_p_months' => $this->ztpPMonths(),
            'spouse_income_minor_units' => $this->spouseIncomeMinorUnits,
            'evidence_status' => $this->evidenceStatus->value,
            'evidence_reference' => $this->evidenceReference,
        ];
    }

    public static function bitCount(int $mask): int
    {
        $count = 0;
        for ($bit = 0; $bit < 12; $bit++) {
            if (($mask & (1 << $bit)) !== 0) {
                ++$count;
            }
        }

        return $count;
    }

    private static function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Datum u slevy na manžela není platné.');
        }
    }
}
