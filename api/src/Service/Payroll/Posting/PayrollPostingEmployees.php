<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Posting;

/**
 * Komu patří alokace mzdového předpisu — podklad zaúčtování po zaměstnancích.
 *
 * Klíč alokace nese buď pracovní vztah (`…employment:{id}…`), nebo osobu
 * (`employee:{id}:…`). Vztah se převede na osobu podle zmrazeného snapshotu revize.
 * Alokace bez osoby (firemní závazek zaměstnavatelského pojistného, vztah, který
 * v revizi už není) zůstávají souhrnné.
 *
 * Text řádku nese jen osobní číslo (kód hlavního vztahu osoby), ne jméno: deník
 * čtou i role bez práva k mzdám. Jméno ukazuje až mzdová sestava.
 */
final class PayrollPostingEmployees
{
    /**
     * @param array<int,int> $employmentOwners employment_id → employee_id
     * @param array<int,string> $codes employee_id → osobní číslo
     */
    public function __construct(
        private readonly array $employmentOwners,
        private readonly array $codes,
    ) {}

    /** @param array<string,mixed> $snapshot vstupní snapshot revize */
    public static function fromSnapshot(array $snapshot): self
    {
        $owners = [];
        $codes = [];
        foreach (is_array($snapshot['people'] ?? null) ? $snapshot['people'] : [] as $person) {
            $employeeId = $person['employee']['id'] ?? null;
            if (!is_int($employeeId)) {
                continue;
            }
            $primary = null;
            $first = null;
            foreach (is_array($person['employments'] ?? null) ? $person['employments'] : [] as $employment) {
                $identity = $employment['employment'] ?? null;
                if (!is_array($identity) || !is_int($identity['id'] ?? null)) {
                    continue;
                }
                $owners[$identity['id']] = $employeeId;
                $code = is_string($identity['code'] ?? null) && $identity['code'] !== '' ? $identity['code'] : null;
                $first ??= $code;
                if ($primary === null && ($identity['is_primary'] ?? null) === true) {
                    $primary = $code;
                }
            }
            $codes[$employeeId] = $primary ?? $first ?? (string) $employeeId;
        }

        return new self($owners, $codes);
    }

    /** Osoba, které alokace patří, nebo null u souhrnné částky. */
    public function employeeOf(string $allocationKey): ?int
    {
        if (preg_match('/^employee:(\d+):/', $allocationKey, $match) === 1) {
            return (int) $match[1];
        }
        if (preg_match('/(?:^|:)employment:(\d+):/', $allocationKey, $match) === 1) {
            return $this->employmentOwners[(int) $match[1]] ?? null;
        }

        return null;
    }

    /**
     * @return array{sort:string,label:string}|null řadicí klíč a text řádku osoby
     */
    public function forAllocationKey(string $allocationKey): ?array
    {
        $employeeId = $this->employeeOf($allocationKey);
        if ($employeeId === null) {
            return null;
        }

        return [
            'sort' => sprintf('%010d', $employeeId),
            'label' => self::label($this->codes[$employeeId] ?? (string) $employeeId),
        ];
    }

    /** @return array<int,string> employee_id → osobní číslo */
    public function codes(): array
    {
        return $this->codes;
    }

    public static function label(string $code): string
    {
        return 'Os. č. ' . $code;
    }
}
