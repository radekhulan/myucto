<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * @phpstan-type DependantInput array{
 *   relation:string,
 *   full_name:string,
 *   given_name:?string,
 *   family_name:?string,
 *   birth_date:string,
 *   birth_number_present:bool,
 *   birth_number:?string,
 *   ztp_p:bool,
 *   student:bool,
 *   existence_from:string,
 *   existence_to:?string,
 *   note:?string
 * }
 * @phpstan-type ClaimInput array{
 *   child_order:int,
 *   claim_reason:?string,
 *   evidence_status:string,
 *   evidence_reference:?string,
 *   shared_household_confirmed:bool,
 *   other_claimant_excluded:bool,
 *   ztp_p:bool,
 *   effective_from:string,
 *   effective_to:?string
 * }
 */
final class PayrollDependantValidator
{
    public const RELATIONS = [
        'child_own',
        'child_adopted',
        'child_in_care',
        'child_of_spouse',
        'grandchild',
        'spouse',
        'partner',
    ];

    /** Vztahy, na které lze měsíčně uplatnit daňové zvýhodnění podle § 35c. */
    public const CHILD_RELATIONS = [
        'child_own',
        'child_adopted',
        'child_in_care',
        'child_of_spouse',
        'grandchild',
    ];

    public const CLAIM_REASONS = [
        'own_household',
        'shared_custody',
        'adoption',
        'foster_care',
        'study_continues',
        'other',
    ];

    /**
     * @param array<string,mixed> $input
     * @return DependantInput
     */
    public function validateDependant(array $input): array
    {
        $relation = $this->enum($input, 'relation', self::RELATIONS);
        $birthDate = $this->date($input, 'birth_date');
        $existenceFrom = $this->date($input, 'existence_from');
        $existenceTo = $this->nullableDate($input, 'existence_to');
        if ($existenceTo !== null && $existenceTo < $existenceFrom) {
            throw new InvalidArgumentException(
                'existence_to nesmí předcházet existence_from.',
            );
        }
        if ($existenceFrom < $birthDate) {
            throw new InvalidArgumentException(
                'Vyživovaná osoba nemůže být vyživovaná před datem narození.',
            );
        }

        $present = array_key_exists('birth_number', $input)
            && $input['birth_number'] !== null;
        $birthNumber = null;
        if ($present) {
            $raw = $input['birth_number'];
            if (!is_string($raw) || trim($raw) === '') {
                throw new InvalidArgumentException(
                    'birth_number musí být neprázdný text.',
                );
            }
            $birthNumber = CzechBirthNumber::normalize($raw);
            if (CzechBirthNumber::birthDate($birthNumber) !== $birthDate) {
                throw new InvalidArgumentException(
                    'Rodné číslo neodpovídá zadanému datu narození.',
                );
            }
        }

        return [
            'relation' => $relation,
            'full_name' => $this->text($input, 'full_name', 191),
            'given_name' => $this->nullableText($input, 'given_name', 100),
            'family_name' => $this->nullableText($input, 'family_name', 100),
            'birth_date' => $birthDate,
            'birth_number_present' => $present,
            'birth_number' => $birthNumber,
            'ztp_p' => $this->bool($input, 'ztp_p'),
            'student' => $this->bool($input, 'student'),
            'existence_from' => $existenceFrom,
            'existence_to' => $existenceTo,
            'note' => $this->nullableText($input, 'note', 500),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return ClaimInput
     */
    public function validateClaim(array $input): array
    {
        $order = $input['child_order'] ?? null;
        $order = filter_var($order, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 20],
        ]);
        if (!is_int($order)) {
            throw new InvalidArgumentException(
                'child_order musí být celé číslo 1 až 20.',
            );
        }

        $status = $this->enum($input, 'evidence_status', ['verified', 'unverified']);
        $reference = $this->nullableCanonical($input, 'evidence_reference');
        if ($status === 'unverified' && $reference !== null) {
            throw new InvalidArgumentException(
                'Nedoložený nárok nesmí nést odkaz na doklad.',
            );
        }

        $from = $this->date($input, 'effective_from');
        $to = $this->nullableDate($input, 'effective_to');
        if ($to !== null && $to < $from) {
            throw new InvalidArgumentException(
                'effective_to nesmí předcházet effective_from.',
            );
        }
        if (substr($from, 8, 2) !== '01') {
            throw new InvalidArgumentException(
                'Nárok na daňové zvýhodnění začíná vždy prvním dnem měsíce.',
            );
        }
        if ($to !== null && $to !== $this->monthEnd($to)) {
            throw new InvalidArgumentException(
                'Nárok na daňové zvýhodnění končí vždy posledním dnem měsíce.',
            );
        }

        $reason = null;
        if (($input['claim_reason'] ?? null) !== null) {
            $reason = $this->enum($input, 'claim_reason', self::CLAIM_REASONS);
        }

        return [
            'child_order' => $order,
            'claim_reason' => $reason,
            'evidence_status' => $status,
            'evidence_reference' => $reference,
            'shared_household_confirmed' => $this->bool(
                $input,
                'shared_household_confirmed',
            ),
            'other_claimant_excluded' => $this->bool(
                $input,
                'other_claimant_excluded',
            ),
            'ztp_p' => $this->bool($input, 'ztp_p'),
            'effective_from' => $from,
            'effective_to' => $to,
        ];
    }

    public function monthEnd(string $date): string
    {
        return (new DateTimeImmutable(substr($date, 0, 7) . '-01'))
            ->modify('last day of this month')
            ->format('Y-m-d');
    }

    /** @param array<string,mixed> $input @param list<string> $allowed */
    private function enum(array $input, string $key, array $allowed): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Pole {$key} má nepovolenou hodnotu.");
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function text(array $input, string $key, int $maximum): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException("Pole {$key} musí být text.");
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maximum) {
            throw new InvalidArgumentException(
                "Pole {$key} musí mít 1 až {$maximum} znaků.",
            );
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException("Pole {$key} obsahuje řídicí znak.");
        }
        CzechBirthNumber::rejectMaskPlaceholder($value);

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function nullableText(array $input, string $key, int $maximum): ?string
    {
        $value = $input[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return $this->text($input, $key, $maximum);
    }

    /** @param array<string,mixed> $input */
    private function nullableCanonical(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException("Pole {$key} musí být text.");
        }
        $value = trim($value);
        if (strlen($value) > 500
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]*$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException(
                "Pole {$key} není kanonická reference na doklad.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function bool(array $input, string $key): bool
    {
        $value = $input[$key] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (bool) (int) $value;
        }
        throw new InvalidArgumentException("Pole {$key} musí být true nebo false.");
    }

    /** @param array<string,mixed> $input */
    private function date(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException("Pole {$key} musí být datum YYYY-MM-DD.");
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("Pole {$key} musí být datum YYYY-MM-DD.");
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function nullableDate(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return $this->date($input, $key);
    }
}
