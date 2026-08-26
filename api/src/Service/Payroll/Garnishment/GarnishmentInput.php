<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use InvalidArgumentException;

final readonly class GarnishmentInput
{
    /**
     * `eligibleDependants` already excludes a dependant whose maintenance is
     * enforced by an active order in this payroll calculation.
     *
     * @param list<DeductionClaim> $claims
     */
    public function __construct(
        public string $period,
        public string $paymentDate,
        public GarnishableIncomeResult $income,
        public array $claims,
        public int $eligibleDependants,
        public bool $dependantsEvidenceComplete,
        public bool $eligibleSpouse,
        public bool $spouseEvidenceComplete,
        public PensionEvidence $pensionEvidence,
        public bool $hasMultiplePayers,
        public ?int $protectedAmountOverrideMinorUnits,
        public InsolvencyInstruction $insolvency,
        public bool $protectedAmountOverrideVerified,
        public bool $claimRegisterEvidenceComplete,
    ) {
        if ($eligibleDependants < 0) {
            throw new InvalidArgumentException('Eligible dependant count cannot be negative.');
        }
        if ($protectedAmountOverrideMinorUnits !== null && $protectedAmountOverrideMinorUnits < 0) {
            throw new InvalidArgumentException('Protected amount override cannot be negative.');
        }
    }

    /** @return array<string,mixed> */
    public function toCanonicalArray(): array
    {
        $claims = $this->claims;
        usort(
            $claims,
            static fn (DeductionClaim $left, DeductionClaim $right): int =>
                $left->id <=> $right->id,
        );

        return [
            'claims' => array_map(
                static fn (DeductionClaim $claim): array =>
                    $claim->toCanonicalArray(),
                $claims,
            ),
            'evidence' => [
                'claim_register_complete' => $this->claimRegisterEvidenceComplete,
                'dependants_complete' => $this->dependantsEvidenceComplete,
                'eligible_dependants' => $this->eligibleDependants,
                'eligible_spouse' => $this->eligibleSpouse,
                'has_multiple_payers' => $this->hasMultiplePayers,
                'pension' => $this->pensionEvidence->value,
                'protected_amount_override_minor_units' =>
                    $this->protectedAmountOverrideMinorUnits,
                'protected_amount_override_verified' =>
                    $this->protectedAmountOverrideVerified,
                'spouse_complete' => $this->spouseEvidenceComplete,
            ],
            'income' => $this->income->jsonSerialize(),
            'insolvency' => [
                'court_determined_amount_minor_units' =>
                    $this->insolvency->courtDeterminedAmountMinorUnits,
                'decision_verified' => $this->insolvency->decisionVerified,
                'mode' => $this->insolvency->mode->value,
                'recipient_verified' => $this->insolvency->recipientVerified,
                'payment_instruction_id' =>
                    $this->insolvency->paymentInstructionId,
                'payment_instruction_hash' =>
                    $this->insolvency->paymentInstructionHash,
                'employment_id' => $this->insolvency->employmentId,
            ],
            'payment_date' => $this->paymentDate,
            'period' => $this->period,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromCanonicalArray(array $data): self
    {
        $claims = $data['claims'] ?? null;
        $evidence = $data['evidence'] ?? null;
        $income = $data['income'] ?? null;
        $insolvency = $data['insolvency'] ?? null;
        if (!is_array($claims) || !array_is_list($claims)
            || !is_array($evidence) || array_is_list($evidence)
            || !is_array($income) || array_is_list($income)
            || !is_array($insolvency) || array_is_list($insolvency)
        ) {
            throw new InvalidArgumentException('Garnishment input snapshot is invalid.');
        }
        $evidence = self::row($evidence, 'evidence');
        $income = self::row($income, 'income');
        $insolvency = self::row($insolvency, 'insolvency');

        return new self(
            self::string($data, 'period'),
            self::string($data, 'payment_date'),
            GarnishableIncomeResult::fromCanonicalArray($income),
            array_map(
                static function (mixed $claim): DeductionClaim {
                    return DeductionClaim::fromCanonicalArray(
                        self::row($claim, 'claim'),
                    );
                },
                $claims,
            ),
            self::int($evidence, 'eligible_dependants'),
            self::bool($evidence, 'dependants_complete'),
            self::bool($evidence, 'eligible_spouse'),
            self::bool($evidence, 'spouse_complete'),
            PensionEvidence::from(self::string($evidence, 'pension')),
            self::bool($evidence, 'has_multiple_payers'),
            self::nullableInt(
                $evidence,
                'protected_amount_override_minor_units',
            ),
            new InsolvencyInstruction(
                InsolvencyMode::from(self::string($insolvency, 'mode')),
                self::bool($insolvency, 'decision_verified'),
                self::bool($insolvency, 'recipient_verified'),
                self::nullableInt(
                    $insolvency,
                    'court_determined_amount_minor_units',
                ),
                self::nullableInt($insolvency, 'payment_instruction_id'),
                self::nullableString(
                    $insolvency,
                    'payment_instruction_hash',
                ),
                self::nullableInt($insolvency, 'employment_id'),
            ),
            self::bool($evidence, 'protected_amount_override_verified'),
            self::bool($evidence, 'claim_register_complete'),
        );
    }

    /** @return array<string,mixed> */
    private static function row(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException("{$field} must be an object.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new InvalidArgumentException(
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
            throw new InvalidArgumentException("{$key} must be a string.");
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException("{$key} must be an integer.");
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new InvalidArgumentException("{$key} must be a nullable integer.");
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException(
                "{$key} must be a nullable string.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new InvalidArgumentException("{$key} must be a boolean.");
        }
        return $value;
    }
}
