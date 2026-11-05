<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

final class PayrollComponentValidator
{
    /**
     * @param array<string,mixed> $input
     * @return array{
     *   code:string,
     *   name:string,
     *   component_kind:string,
     *   value_kind:string,
     *   frequency_kind:string,
     *   tax_treatment:string,
     *   social_participation_treatment:string,
     *   social_treatment:string,
     *   health_participation_treatment:string,
     *   health_treatment:string,
     *   average_earning_treatment:string,
     *   enforcement_treatment:string,
     *   jmhz_treatment:string,
     *   statistics_treatment:string,
     *   minimum_wage_treatment?:?string,
     *   minimum_wage_reason?:?string,
     *   accounting_debit_code:?string,
     *   accounting_credit_code:?string,
     *   annual_limit_minor:?int,
     *   exemption_basket:?string,
     *   exemption_basis:?string,
     *   valid_from:string,
     *   valid_to:?string,
     *   is_active:bool
     * }
     */
    public function validate(array $input): array
    {
        $definition = new PayrollComponentDefinition(
            code: $this->code($input['code'] ?? null),
            name: $this->requiredString($input['name'] ?? null, 'name', 190),
            kind: $this->enum(
                PayrollComponentKind::class,
                $input['component_kind'] ?? null,
                'component_kind',
            ),
            valueKind: $this->enum(
                PayrollComponentValueKind::class,
                $input['value_kind'] ?? null,
                'value_kind',
            ),
            frequency: $this->enum(
                PayrollComponentFrequency::class,
                $input['frequency_kind'] ?? null,
                'frequency_kind',
            ),
            taxTreatment: $this->enum(
                PayrollComponentTaxTreatment::class,
                $input['tax_treatment'] ?? null,
                'tax_treatment',
            ),
            socialParticipationTreatment: $this->enum(
                PayrollComponentInclusion::class,
                $input['social_participation_treatment'] ?? null,
                'social_participation_treatment',
            ),
            socialTreatment: $this->enum(
                PayrollComponentInclusion::class,
                $input['social_treatment'] ?? null,
                'social_treatment',
            ),
            healthParticipationTreatment: $this->enum(
                PayrollComponentInclusion::class,
                $input['health_participation_treatment'] ?? null,
                'health_participation_treatment',
            ),
            healthTreatment: $this->enum(
                PayrollComponentInclusion::class,
                $input['health_treatment'] ?? null,
                'health_treatment',
            ),
            averageEarningTreatment: $this->enum(
                PayrollComponentInclusion::class,
                $input['average_earning_treatment'] ?? null,
                'average_earning_treatment',
            ),
            enforcementTreatment: $this->enum(
                PayrollComponentInclusion::class,
                $input['enforcement_treatment'] ?? null,
                'enforcement_treatment',
            ),
            jmhzTreatment: $this->enum(
                PayrollComponentInclusion::class,
                $input['jmhz_treatment'] ?? null,
                'jmhz_treatment',
            ),
            statisticsTreatment: $this->enum(
                PayrollComponentInclusion::class,
                $input['statistics_treatment'] ?? null,
                'statistics_treatment',
            ),
            accountingDebitCode: $this->optionalAccount(
                $input['accounting_debit_code'] ?? null,
            ),
            accountingCreditCode: $this->optionalAccount(
                $input['accounting_credit_code'] ?? null,
            ),
            annualLimitMinor: $this->optionalPositiveInt(
                $input['annual_limit_minor'] ?? null,
                'annual_limit_minor',
            ),
            exemptionBasket: $this->optionalEnum(
                PayrollBenefitExemptionBasket::class,
                $input['exemption_basket'] ?? null,
                'exemption_basket',
            ),
            exemptionBasis: $this->optionalEnum(
                PayrollExemptionBasis::class,
                $input['exemption_basis'] ?? null,
                'exemption_basis',
            ),
        );
        // Osvobození bez uvedeného podkladu se sice uložit dá — legacy složky
        // takové jsou — ale mzdový běh na něm skončí v ručním posouzení. Nová
        // ani upravovaná složka se do toho stavu dostat nemá.
        if ($definition->taxTreatment === PayrollComponentTaxTreatment::EXEMPT
            && $definition->exemptionBasis === null
        ) {
            throw new \InvalidArgumentException(
                'U složky osvobozené od daně je nutné uvést podklad osvobození.'
            );
        }
        $validFrom = $this->date($input['valid_from'] ?? null, 'valid_from');
        $validTo = $this->optionalDate($input['valid_to'] ?? null, 'valid_to');
        if ($validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException(
                'Konec platnosti nesmí předcházet začátku.'
            );
        }

        $data = [
            'code' => $definition->code,
            'name' => $definition->name,
            'component_kind' => $definition->kind->value,
            'value_kind' => $definition->valueKind->value,
            'frequency_kind' => $definition->frequency->value,
            'tax_treatment' => $definition->taxTreatment->value,
            'social_participation_treatment' =>
                $definition->socialParticipationTreatment->value,
            'social_treatment' => $definition->socialTreatment->value,
            'health_participation_treatment' =>
                $definition->healthParticipationTreatment->value,
            'health_treatment' => $definition->healthTreatment->value,
            'average_earning_treatment' => $definition->averageEarningTreatment->value,
            'enforcement_treatment' => $definition->enforcementTreatment->value,
            'jmhz_treatment' => $definition->jmhzTreatment->value,
            'statistics_treatment' => $definition->statisticsTreatment->value,
            'annual_limit_minor' => $definition->annualLimitMinor,
            'exemption_basket' => $definition->exemptionBasket?->value,
            'exemption_basis' => $definition->exemptionBasis?->value,
            'accounting_debit_code' => $definition->accountingDebitCode,
            'accounting_credit_code' => $definition->accountingCreditCode,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'is_active' => $this->bool($input['is_active'] ?? true, 'is_active'),
        ];
        // Vynechaná nová pole při úpravě zachovají dosavadní posouzení verze.
        if (array_key_exists('minimum_wage_treatment', $input)) {
            $data['minimum_wage_treatment'] = $this->optionalEnum(
                PayrollComponentInclusion::class,
                $input['minimum_wage_treatment'],
                'minimum_wage_treatment',
            )?->value;
        }
        if (array_key_exists('minimum_wage_reason', $input)) {
            $reason = $input['minimum_wage_reason'];
            $data['minimum_wage_reason'] = $reason === null || $reason === ''
                ? null : $this->requiredString($reason, 'minimum_wage_reason', 500);
        }
        return $data;
    }

    private function code(mixed $value): string
    {
        return strtoupper($this->requiredString($value, 'code', 64));
    }

    private function requiredString(mixed $value, string $field, int $max): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Pole {$field} musí být řetězec.");
        }
        $normalized = trim($value);
        if ($normalized === '' || mb_strlen($normalized) > $max) {
            throw new \InvalidArgumentException("Pole {$field} není platné.");
        }
        return $normalized;
    }

    private function optionalAccount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Účet musí být řetězec.');
        }
        $normalized = trim($value);
        return $normalized === '' ? null : $normalized;
    }

    private function optionalPositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $result = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($result === false) {
            throw new \InvalidArgumentException("Pole {$field} musí být kladné celé číslo.");
        }
        return (int) $result;
    }

    private function bool(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("Pole {$field} musí být boolean.");
        }
        return $value;
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                "Pole {$field} musí být datum YYYY-MM-DD."
            );
        }
        $normalized = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $normalized);
        if ($date === false || $date->format('Y-m-d') !== $normalized) {
            throw new \InvalidArgumentException(
                "Pole {$field} musí být datum YYYY-MM-DD."
            );
        }
        return $normalized;
    }

    private function optionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->date($value, $field);
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T|null
     */
    private function optionalEnum(string $enum, mixed $value, string $field): ?\BackedEnum
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->enum($enum, $value, $field);
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T
     */
    private function enum(string $enum, mixed $value, string $field): \BackedEnum
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Pole {$field} není podporované.");
        }
        $result = $enum::tryFrom($value);
        if ($result === null) {
            throw new \InvalidArgumentException("Pole {$field} není podporované.");
        }
        return $result;
    }
}
