<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use InvalidArgumentException;

final readonly class EnforcementPersonMonthEvidence
{
    /** @param list<DeductionClaim> $claims */
    public function __construct(
        public array $claims,
        public int $eligibleDependants,
        public bool $dependantsEvidenceComplete,
        public bool $eligibleSpouse,
        public bool $spouseEvidenceComplete,
        public PensionEvidence $pensionEvidence,
        public bool $hasMultiplePayers,
        public ?int $protectedAmountOverrideMinorUnits,
        public bool $protectedAmountOverrideVerified,
        public bool $claimRegisterEvidenceComplete,
        public InsolvencyInstruction $insolvency,
        public SpousePensionEvidence $spousePensionEvidence =
            SpousePensionEvidence::Unknown,
    ) {
        if ($eligibleDependants < 0) {
            throw new InvalidArgumentException('Eligible dependant count cannot be negative.');
        }
    }

    /**
     * Klíč `spouse_pension_evidence` se serializuje jen tehdy, když někdo
     * doložení důchodu podle nař. vlády č. 441/2024 Sb. skutečně zodpověděl.
     *
     * Kanonický tvar evidence se na několika místech porovnává BAJTOVĚ —
     * potvrzený JMHZ ordinary profil ({@see \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOrdinaryEvidenceBuilder}),
     * idempotence uložených výsledků srážek, zmrazené snímky běhu. Zmrazené
     * záznamy pořízené před novelou klíč neobsahují, takže kdyby ho tvar
     * přidával bezpodmínečně, rozešel by se sám se sebou a zablokoval podání.
     * Vynechaný klíč se čte jako {@see SpousePensionEvidence::Unknown}, což je
     * přesně stav těch záznamů; jakmile účetní doložení vyplní, klíč přibude
     * a změna tvaru je záměrná.
     *
     * @return array<string,mixed>
     */
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
            'eligible_dependants' => $this->eligibleDependants,
            'dependants_evidence_complete' => $this->dependantsEvidenceComplete,
            'eligible_spouse' => $this->eligibleSpouse,
            'spouse_evidence_complete' => $this->spouseEvidenceComplete,
            ...($this->spousePensionEvidence === SpousePensionEvidence::Unknown
                ? []
                : ['spouse_pension_evidence' => $this->spousePensionEvidence->value]),
            'pension_evidence' => $this->pensionEvidence->value,
            'has_multiple_payers' => $this->hasMultiplePayers,
            'protected_amount_override_minor_units' =>
                $this->protectedAmountOverrideMinorUnits,
            'protected_amount_override_verified' =>
                $this->protectedAmountOverrideVerified,
            'claim_register_evidence_complete' =>
                $this->claimRegisterEvidenceComplete,
            'insolvency' => [
                'mode' => $this->insolvency->mode->value,
                'decision_verified' => $this->insolvency->decisionVerified,
                'recipient_verified' => $this->insolvency->recipientVerified,
                'court_determined_amount_minor_units' =>
                    $this->insolvency->courtDeterminedAmountMinorUnits,
                'payment_instruction_id' =>
                    $this->insolvency->paymentInstructionId,
                'payment_instruction_hash' =>
                    $this->insolvency->paymentInstructionHash,
                'employment_id' => $this->insolvency->employmentId,
            ],
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromCanonicalArray(array $data): self
    {
        $claims = $data['claims'] ?? null;
        $insolvency = $data['insolvency'] ?? null;
        if (!is_array($claims) || !array_is_list($claims)
            || !is_array($insolvency) || array_is_list($insolvency)
        ) {
            throw new InvalidArgumentException('Enforcement evidence snapshot is invalid.');
        }
        $insolvency = self::row($insolvency, 'insolvency');

        return new self(
            array_map(
                static function (mixed $claim): DeductionClaim {
                    return DeductionClaim::fromCanonicalArray(
                        self::row($claim, 'claim'),
                    );
                },
                $claims,
            ),
            self::int($data, 'eligible_dependants'),
            self::bool($data, 'dependants_evidence_complete'),
            self::bool($data, 'eligible_spouse'),
            self::bool($data, 'spouse_evidence_complete'),
            PensionEvidence::from(self::string($data, 'pension_evidence')),
            self::bool($data, 'has_multiple_payers'),
            self::nullableInt($data, 'protected_amount_override_minor_units'),
            self::bool($data, 'protected_amount_override_verified'),
            self::bool($data, 'claim_register_evidence_complete'),
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
            self::spousePension($data),
        );
    }

    /**
     * Snímky pořízené před nařízením vlády č. 441/2024 Sb. klíč neobsahují —
     * chybějící hodnota je fail-closed {@see SpousePensionEvidence::Unknown}.
     *
     * @param array<string,mixed> $data
     */
    private static function spousePension(array $data): SpousePensionEvidence
    {
        $value = $data['spouse_pension_evidence'] ?? null;
        if ($value === null) {
            return SpousePensionEvidence::Unknown;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException(
                'spouse_pension_evidence must be a string.',
            );
        }

        return SpousePensionEvidence::from($value);
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
