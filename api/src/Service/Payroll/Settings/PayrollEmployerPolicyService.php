<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Settings;

use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;

final class PayrollEmployerPolicyService
{
    private const ENUMS = [
        'payday_business_day_rule' => [
            'none',
            'previous_business_day',
            'next_business_day',
        ],
        'balance_rounding_mode' => [
            'exact_minor_units',
            'nearest_crown',
            'up_to_crown',
        ],
        'home_office_policy' => [
            'not_used',
            'manual_review',
            'configured',
        ],
        'travel_expense_policy' => [
            'not_used',
            'manual_review',
            'configured',
        ],
        'delivery_channel' => [
            'disabled',
            'employee_portal',
            'smime_email',
            'manual_handover',
        ],
        'source_kind' => [
            'manual',
            'import',
            'migration',
            'system',
        ],
    ];

    public function __construct(
        private readonly PayrollEmployerPolicyRepository $repository,
    ) {}

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(
        int $supplierId,
        ?int $id,
        array $input,
        int $expectedVersion,
        ?int $actorUserId,
    ): array {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musí být určena.');
        }
        if ($expectedVersion < 0) {
            throw new \InvalidArgumentException('row_version nesmí být záporné.');
        }
        $data = $this->normalize($input);

        if ($id === null) {
            if ($expectedVersion !== 0) {
                throw new \InvalidArgumentException(
                    'Nová politika musí mít row_version 0.',
                );
            }

            return $this->repository->create(
                $supplierId,
                $data,
                $actorUserId,
            );
        }
        if ($id <= 0 || $expectedVersion <= 0) {
            throw new \InvalidArgumentException(
                'Upravovaná politika musí mít platné ID a row_version.',
            );
        }

        return $this->repository->update(
            $supplierId,
            $id,
            $data,
            $expectedVersion,
            $actorUserId,
        ) ?? throw new \RuntimeException(
            'Zaměstnavatelská politika nebyla nalezena.',
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $validFrom = $this->date($input['valid_from'] ?? null, 'valid_from');
        $validTo = $this->nullableDate(
            $input['valid_to'] ?? null,
            'valid_to',
        );
        if ($validTo !== null && $validTo < $validFrom) {
            throw new \InvalidArgumentException(
                'Konec platnosti nesmí předcházet začátku.',
            );
        }
        $paydayDay = $this->integer($input, 'payday_day', 1, 31);
        $paydayMonthOffset = $this->integer(
            $input,
            'payday_month_offset',
            0,
            1,
        );

        $result = [
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'payday_day' => $paydayDay,
            'payday_month_offset' => $paydayMonthOffset,
            'leave_entitlement_weeks' => $this->integer(
                $input + ['leave_entitlement_weeks' => 4],
                'leave_entitlement_weeks',
                4,
                12,
            ),
        ];
        foreach (self::ENUMS as $field => $allowed) {
            $rawValue = $input[$field] ?? null;
            if (!is_string($rawValue)) {
                throw new \InvalidArgumentException(
                    "Pole {$field} musí být text.",
                );
            }
            $value = trim($rawValue);
            if (!in_array($value, $allowed, true)) {
                throw new \InvalidArgumentException(
                    "Pole {$field} nemá podporovanou hodnotu.",
                );
            }
            $result[$field] = $value;
        }
        /*
         * Z politiky zbyl jediný přepínač automatiky, `automatic_posting_enabled`
         * — jako jediný má konzumenta (automatické zaúčtování schválené revize).
         * `four_eyes_required` bylo uzavřeným rozhodnutím trvale vypnuté a
         * `automatic_calculation_enabled` ani `automatic_payments_enabled`
         * nikdo nečetl, takže obrazovka nabízela tři přepínače, z nichž dva
         * nic nedělaly a jeden nešel zapnout. Vstup je snáší mlčky (starší
         * klienti je ještě posílají), ale neukládají se.
         */
        if (!is_bool($input['automatic_posting_enabled'] ?? null)) {
            throw new \InvalidArgumentException(
                'Pole automatic_posting_enabled musí být boolean.',
            );
        }
        $result['automatic_posting_enabled'] = $input['automatic_posting_enabled'];

        $deliveryVerifiedOn = $this->nullableDate(
            $input['delivery_verified_on'] ?? null,
            'delivery_verified_on',
        );
        if ($result['delivery_channel'] === 'disabled') {
            if ($deliveryVerifiedOn !== null) {
                throw new \InvalidArgumentException(
                    'Vypnutý kanál doručení nesmí nést datum ověření.',
                );
            }
        } elseif ($deliveryVerifiedOn === null) {
            throw new \InvalidArgumentException(
                'Bezpečný kanál doručení musí mít datum ověření.',
            );
        }
        $result['delivery_verified_on'] = $deliveryVerifiedOn;

        $rawSourceReference = $input['source_reference'] ?? null;
        if ($rawSourceReference !== null && !is_string($rawSourceReference)) {
            throw new \InvalidArgumentException(
                'Reference zdroje politiky musí být text.',
            );
        }
        $sourceReference = trim($rawSourceReference ?? '');
        if (mb_strlen($sourceReference) > 255) {
            throw new \InvalidArgumentException(
                'Reference zdroje politiky je příliš dlouhá.',
            );
        }
        $result['source_reference'] = $sourceReference === ''
            ? null
            : $sourceReference;

        return $result;
    }

    /** @param array<string,mixed> $input */
    private function integer(
        array $input,
        string $field,
        int $min,
        int $max,
    ): int {
        $value = $input[$field] ?? null;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new \InvalidArgumentException(
                "Pole {$field} musí být celé číslo od {$min} do {$max}.",
            );
        }

        return $value;
    }

    private function nullableDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->date($value, $field);
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(
                "Pole {$field} musí být datum YYYY-MM-DD.",
            );
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new \InvalidArgumentException(
                "Pole {$field} musí být datum YYYY-MM-DD.",
            );
        }

        return $value;
    }
}
