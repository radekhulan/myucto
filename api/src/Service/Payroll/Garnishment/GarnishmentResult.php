<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use JsonSerializable;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final readonly class GarnishmentResult implements JsonSerializable
{
    /**
     * @param list<GarnishmentAllocation> $allocations
     * @param list<string> $issues
     * @param list<array<string, int|string|bool>> $roundingTrace
     */
    public function __construct(
        public string $period,
        public GarnishmentStatus $status,
        public int $garnishableIncomeMinorUnits,
        public int $protectedAmountMinorUnits,
        public int $thirdMinorUnits,
        public int $fullyAttachableExcessMinorUnits,
        public int $employerFlatFeeMinorUnits,
        public int $totalWithheldMinorUnits,
        public int $employeePaymentMinorUnits,
        public bool $fourEnforcementRuleApplied,
        public bool $insolvencyApplied,
        public array $allocations,
        public array $issues,
        public array $roundingTrace,
        public string $rulesetId,
        public string $rulesetHash,
        /**
         * `null` je legacy: revize spočítaná kódem, který evidenci vyžadoval
         * bezpodmínečně, o jejím rozsahu netvrdila nic a dopočítat se to
         * zpětně NESMÍ. Viz {@see EnforcementEvidenceSource}.
         */
        public ?EnforcementEvidenceScope $evidenceSource = null,
    ) {
        if (!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new \InvalidArgumentException('Garnishment period must use YYYY-MM.');
        }
        foreach ([
            $garnishableIncomeMinorUnits,
            $protectedAmountMinorUnits,
            $thirdMinorUnits,
            $fullyAttachableExcessMinorUnits,
            $employerFlatFeeMinorUnits,
            $totalWithheldMinorUnits,
            $employeePaymentMinorUnits,
        ] as $amount) {
            if ($amount < 0) {
                throw new \InvalidArgumentException(
                    'Garnishment result amounts cannot be negative.',
                );
            }
        }
        if (trim($rulesetId) === '' || !preg_match('/^[0-9a-f]{64}$/', $rulesetHash)) {
            throw new \InvalidArgumentException('Garnishment ruleset identity is invalid.');
        }

        $allocationTotal = 0;
        $claimIds = [];
        foreach ($allocations as $allocation) {
            if (isset($claimIds[$allocation->claimId])) {
                throw new \InvalidArgumentException(
                    'Garnishment allocation claim IDs must be unique.',
                );
            }
            $claimIds[$allocation->claimId] = true;
            if ($allocationTotal > PHP_INT_MAX - $allocation->totalMinorUnits) {
                throw new \OverflowException('Garnishment allocation sum exceeds the integer range.');
            }
            $allocationTotal += $allocation->totalMinorUnits;
        }
        if ($allocationTotal > PHP_INT_MAX - $employerFlatFeeMinorUnits) {
            throw new \OverflowException('Garnishment withholding exceeds the integer range.');
        }
        if ($allocationTotal + $employerFlatFeeMinorUnits !== $totalWithheldMinorUnits) {
            throw new \InvalidArgumentException(
                'Garnishment allocations and employer fee must equal total withholding.',
            );
        }
        if ($employeePaymentMinorUnits > PHP_INT_MAX - $totalWithheldMinorUnits) {
            throw new \OverflowException('Garnishment payout balance exceeds the integer range.');
        }
        if ($employeePaymentMinorUnits + $totalWithheldMinorUnits !== $garnishableIncomeMinorUnits) {
            throw new \InvalidArgumentException(
                'Employee payment and withholding must equal garnishable income.',
            );
        }
        // § 281 o. s. ř.: „Provádět srážky ze mzdy ve větším rozsahu, než
        // dovolují ustanovení tohoto zákona, je nepřípustné, a to i když s tím
        // povinný souhlasí." Zákonný strop je součet obou třetin zbytku čisté
        // mzdy a plně zabavitelné části (§ 279 odst. 1 a 3); víc než to nesmí
        // odejít ze mzdy ani při pravidle čtyř exekucí, ani v oddlužení,
        // a paušál plátce mzdy ho podle § 270 odst. 3 o. s. ř. nezvyšuje —
        // ukrajuje se ze sražené částky. Invariant se hlídá tady, protože
        // výsledek vzniká na několika cestách (výpočet, oddlužení, načtení
        // snímku) a strop musí platit na všech (nález E-16).
        if ($thirdMinorUnits > PHP_INT_MAX - $thirdMinorUnits
            || $thirdMinorUnits + $thirdMinorUnits > PHP_INT_MAX - $fullyAttachableExcessMinorUnits
        ) {
            throw new \OverflowException('Garnishment statutory ceiling exceeds the integer range.');
        }
        if ($totalWithheldMinorUnits
            > $thirdMinorUnits + $thirdMinorUnits + $fullyAttachableExcessMinorUnits
        ) {
            throw new \InvalidArgumentException(
                'Garnishment withholding exceeds the statutory ceiling of § 281 o. s. ř.',
            );
        }
    }

    public function allocationFor(string $claimId): ?GarnishmentAllocation
    {
        foreach ($this->allocations as $allocation) {
            if ($allocation->claimId === $claimId) {
                return $allocation;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'allocations' => array_map(
                static fn (GarnishmentAllocation $allocation): array => $allocation->jsonSerialize(),
                $this->allocations,
            ),
            'employee_payment_minor_units' => $this->employeePaymentMinorUnits,
            'employer_flat_fee_minor_units' => $this->employerFlatFeeMinorUnits,
            // Bez tohohle klíče by ze snímku nešlo poznat, jestli evidence
            // chyběla, nebo jestli v tom měsíci neměla co dokládat.
            // Viz EnforcementEvidenceSource.
            'evidence_source' => $this->evidenceSource?->toCanonicalArray(),
            'four_enforcement_rule_applied' => $this->fourEnforcementRuleApplied,
            'fully_attachable_excess_minor_units' => $this->fullyAttachableExcessMinorUnits,
            'garnishable_income_minor_units' => $this->garnishableIncomeMinorUnits,
            'insolvency_applied' => $this->insolvencyApplied,
            'issues' => $this->issues,
            'period' => $this->period,
            'protected_amount_minor_units' => $this->protectedAmountMinorUnits,
            'rounding_trace' => $this->roundingTrace,
            'ruleset_hash' => $this->rulesetHash,
            'ruleset_id' => $this->rulesetId,
            'status' => $this->status->value,
            'third_minor_units' => $this->thirdMinorUnits,
            'total_withheld_minor_units' => $this->totalWithheldMinorUnits,
        ];
    }

    public function toCanonicalJson(): string
    {
        return CanonicalJson::encode($this->jsonSerialize());
    }

    /** @param array<string,mixed> $data */
    public static function fromCanonicalArray(array $data): self
    {
        $allocations = $data['allocations'] ?? null;
        $issues = $data['issues'] ?? null;
        $trace = $data['rounding_trace'] ?? null;
        if (!is_array($allocations) || !array_is_list($allocations)
            || !is_array($issues) || !array_is_list($issues)
            || !is_array($trace) || !array_is_list($trace)
        ) {
            throw new \InvalidArgumentException('Garnishment result snapshot is invalid.');
        }
        foreach ($issues as $issue) {
            if (!is_string($issue)) {
                throw new \InvalidArgumentException(
                    'Garnishment result issue must be a string.',
                );
            }
        }
        $validatedTrace = [];
        foreach ($trace as $row) {
            $row = self::row($row, 'rounding_trace');
            $validatedRow = [];
            foreach ($row as $key => $value) {
                if (!is_int($value) && !is_string($value) && !is_bool($value)) {
                    throw new \InvalidArgumentException(
                        'Garnishment rounding trace contains an invalid value.',
                    );
                }
                $validatedRow[$key] = $value;
            }
            $validatedTrace[] = $validatedRow;
        }

        return new self(
            self::string($data, 'period'),
            GarnishmentStatus::from(self::string($data, 'status')),
            self::int($data, 'garnishable_income_minor_units'),
            self::int($data, 'protected_amount_minor_units'),
            self::int($data, 'third_minor_units'),
            self::int($data, 'fully_attachable_excess_minor_units'),
            self::int($data, 'employer_flat_fee_minor_units'),
            self::int($data, 'total_withheld_minor_units'),
            self::int($data, 'employee_payment_minor_units'),
            self::bool($data, 'four_enforcement_rule_applied'),
            self::bool($data, 'insolvency_applied'),
            array_map(
                static function (mixed $allocation): GarnishmentAllocation {
                    return GarnishmentAllocation::fromCanonicalArray(
                        self::row($allocation, 'allocation'),
                    );
                },
                $allocations,
            ),
            $issues,
            $validatedTrace,
            self::string($data, 'ruleset_id'),
            self::string($data, 'ruleset_hash'),
            // Chybějící nebo prázdný klíč = starší revize. Nedopočítává se.
            ($data['evidence_source'] ?? null) === null
                ? null
                : EnforcementEvidenceScope::fromCanonicalArray(
                    self::row($data['evidence_source'], 'evidence_source'),
                ),
        );
    }

    /** @return array<string,mixed> */
    private static function row(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException("{$field} must be an object.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException(
                    "{$field} must use string keys.",
                );
            }
            $result[$key] = $item;
        }
        return $result;
    }

    /** @param array<string,mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$key} must be a string.");
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new \InvalidArgumentException("{$key} must be an integer.");
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("{$key} must be a boolean.");
        }
        return $value;
    }
}
