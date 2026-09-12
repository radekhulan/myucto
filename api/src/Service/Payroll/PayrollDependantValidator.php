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
 *   credit_status:string,
 *   claim_reason:?string,
 *   evidence_status:string,
 *   evidence_reference:?string,
 *   shared_household_confirmed:bool,
 *   other_claimant_excluded:bool,
 *   ztp_p:bool,
 *   effective_from:string,
 *   effective_to:?string,
 *   other_household_caregiver_status:string,
 *   other_caregiver_given_name:?string,
 *   other_caregiver_family_name:?string,
 *   other_caregiver_birth_date:?string
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

    /** JMHZ 10453: vyživuje tytéž děti i jiná osoba v téže domácnosti. */
    public const OTHER_CAREGIVER_STATUSES = ['unknown', 'none', 'present'];

    /**
     * Kdo zvýhodnění na dítě uplatňuje. `claimed_by_other` = dítě s pořadím
     * „N" (JMHZ 10440): drží pořadí v domácnosti, zvýhodnění uplatňuje jiná
     * osoba a tomuto zaměstnanci z něj nevzniká žádná částka.
     */
    public const CREDIT_STATUSES = ['claimed', 'claimed_by_other'];

    private const OTHER_CAREGIVER_FIELDS = [
        'other_caregiver_given_name',
        'other_caregiver_family_name',
        'other_caregiver_birth_date',
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
     * Hláška musí jmenovat POLE VE FORMULÁŘI, ne klíč v payloadu. „Pole
     * existence_from musí být datum YYYY-MM-DD." účetní neřekne, kam se podívat.
     *
     * @var array<string,string>
     */
    private const FIELD_LABELS = [
        'relation' => 'Vztah k zaměstnanci',
        'full_name' => 'Jméno a příjmení',
        'given_name' => 'Jméno',
        'family_name' => 'Příjmení',
        'birth_date' => 'Datum narození',
        'birth_number' => 'Rodné číslo',
        'existence_from' => 'Vyživovaná od',
        'existence_to' => 'Vyživovaná do',
        'note' => 'Poznámka',
        'ztp_p' => 'Držitel průkazu ZTP/P',
        'student' => 'Studium',
        'child_order' => 'Pořadí dítěte',
        'credit_status' => 'Zvýhodnění uplatňuje',
        'claim_reason' => 'Důvod nároku',
        'evidence_status' => 'Doloženost nároku',
        'evidence_reference' => 'Odkaz na doklad',
        'shared_household_confirmed' => 'Společně hospodařící domácnost',
        'other_claimant_excluded' => 'Nikdo jiný zvýhodnění neuplatňuje',
        'other_household_caregiver_status' => 'Jiná osoba vyživující tytéž děti',
        'other_caregiver_given_name' => 'Jméno jiné vyživující osoby',
        'other_caregiver_family_name' => 'Příjmení jiné vyživující osoby',
        'other_caregiver_birth_date' => 'Datum narození jiné vyživující osoby',
        'effective_from' => 'Nárok od',
        'effective_to' => 'Nárok do',
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
                'Konec vyživování nesmí předcházet jeho začátku.',
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
                    'Rodné číslo nesmí být prázdné. Když ho zatím nemáte, nechte pole prázdné.',
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
                'Pořadí dítěte musí být číslo 1 až 20.',
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
                'Konec nároku nesmí předcházet jeho začátku.',
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

        $creditStatus = ($input['credit_status'] ?? null) === null
            ? 'claimed'
            : $this->enum($input, 'credit_status', self::CREDIT_STATUSES);
        $sharedHousehold = $this->bool($input, 'shared_household_confirmed');
        $otherClaimantExcluded = $this->bool($input, 'other_claimant_excluded');
        $otherCaregiver = $this->otherCaregiver($input);
        if ($creditStatus === 'claimed_by_other') {
            $this->assertClaimedByOther(
                $sharedHousehold,
                $otherClaimantExcluded,
                $otherCaregiver['other_household_caregiver_status'],
            );
        }

        return [
            'child_order' => $order,
            'credit_status' => $creditStatus,
            'claim_reason' => $reason,
            'evidence_status' => $status,
            'evidence_reference' => $reference,
            'shared_household_confirmed' => $sharedHousehold,
            'other_claimant_excluded' => $otherClaimantExcluded,
            'ztp_p' => $this->bool($input, 'ztp_p'),
            'effective_from' => $from,
            'effective_to' => $to,
        ] + $otherCaregiver;
    }

    /**
     * Dítě „N" (§ 35c odst. 9, pokyny MPSV k 10440): pořadí se určuje za
     * společně hospodařící domácnost, zvýhodnění na dítě ale uplatňuje někdo
     * jiný. Takový nárok tedy nemůže zároveň tvrdit, že „nikdo jiný
     * neuplatňuje", a musí tu jinou osobu jmenovat — ČSSZ ji u 10453 = ANO
     * vyžaduje kontrolou 127.
     */
    private function assertClaimedByOther(
        bool $sharedHousehold,
        bool $otherClaimantExcluded,
        string $caregiverStatus,
    ): void {
        if (!$sharedHousehold) {
            throw new InvalidArgumentException(
                'Dítě, na které zvýhodnění uplatňuje jiná osoba, se uvádí jen'
                . ' ve společně hospodařící domácnosti — potvrďte ji.',
            );
        }
        if ($otherClaimantExcluded) {
            throw new InvalidArgumentException(
                'Zvýhodnění na toto dítě uplatňuje jiná osoba, nárok proto'
                . ' nemůže zároveň tvrdit, že je nikdo jiný neuplatňuje.',
            );
        }
        if ($caregiverStatus !== 'present') {
            throw new InvalidArgumentException(
                'U dítěte, na které zvýhodnění uplatňuje jiná osoba, uveďte'
                . ' tu osobu (jméno, příjmení a datum narození).',
            );
        }
    }

    /**
     * JMHZ 10453 + kontrola 127 (blocking): vyživuje-li tytéž děti v téže
     * domácnosti i jiná osoba, chce ji ČSSZ pojmenovat. Nevyplněno zůstává
     * `unknown` a měsíční hlášení na něm stojí — vydávat mlčení za „ne" by
     * znamenalo podat tvrzení, které účetní neudělala.
     *
     * @param array<string,mixed> $input
     * @return array{
     *   other_household_caregiver_status:string,
     *   other_caregiver_given_name:?string,
     *   other_caregiver_family_name:?string,
     *   other_caregiver_birth_date:?string
     * }
     */
    private function otherCaregiver(array $input): array
    {
        $status = ($input['other_household_caregiver_status'] ?? null) === null
            ? 'unknown'
            : $this->enum(
                $input,
                'other_household_caregiver_status',
                self::OTHER_CAREGIVER_STATUSES,
            );
        if ($status !== 'present') {
            foreach (self::OTHER_CAREGIVER_FIELDS as $key) {
                if (($input[$key] ?? null) !== null) {
                    throw new InvalidArgumentException(
                        'Údaje jiné vyživující osoby lze vyplnit jen tehdy, když ji nárok uvádí.',
                    );
                }
            }

            return [
                'other_household_caregiver_status' => $status,
                'other_caregiver_given_name' => null,
                'other_caregiver_family_name' => null,
                'other_caregiver_birth_date' => null,
            ];
        }

        return [
            'other_household_caregiver_status' => $status,
            'other_caregiver_given_name' => $this->text(
                $input,
                'other_caregiver_given_name',
                100,
            ),
            'other_caregiver_family_name' => $this->text(
                $input,
                'other_caregiver_family_name',
                100,
            ),
            'other_caregiver_birth_date' => $this->date(
                $input,
                'other_caregiver_birth_date',
            ),
        ];
    }

    public function monthEnd(string $date): string
    {
        return (new DateTimeImmutable(substr($date, 0, 7) . '-01'))
            ->modify('last day of this month')
            ->format('Y-m-d');
    }

    /** Popisek pole tak, jak stojí ve formuláři; neznámý klíč zůstane sám sebou. */
    private static function label(string $key): string
    {
        return self::FIELD_LABELS[$key] ?? $key;
    }

    /** @param array<string,mixed> $input @param list<string> $allowed */
    private function enum(array $input, string $key, array $allowed): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf('„%s“ má nepovolenou hodnotu.', self::label($key)));
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private function text(array $input, string $key, int $maximum): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('„%s“ musí být text.', self::label($key)));
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value, 'UTF-8') > $maximum) {
            throw new InvalidArgumentException(
                sprintf('„%s“ musí mít 1 až %d znaků.', self::label($key), $maximum),
            );
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException(sprintf('„%s“ obsahuje nepovolený znak.', self::label($key)));
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
            throw new InvalidArgumentException(sprintf('„%s“ musí být text.', self::label($key)));
        }
        $value = trim($value);
        if (strlen($value) > 500
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]*$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    '„%s“ smí obsahovat jen písmena, číslice a znaky . : / _ - bez mezer.',
                    self::label($key),
                ),
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
        throw new InvalidArgumentException(sprintf('„%s“ musí být ano, nebo ne.', self::label($key)));
    }

    /** @param array<string,mixed> $input */
    private function date(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('„%s“ musí být datum ve tvaru DD. MM. RRRR.', self::label($key)));
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException(sprintf('„%s“ musí být datum ve tvaru DD. MM. RRRR.', self::label($key)));
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
