<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Posting;

use MyInvoice\Service\Payroll\Accounting\PayrollAccountCode;
use MyInvoice\Service\Payroll\Net\PayoutAllocationRequest;
use MyInvoice\Service\Payroll\Net\PayoutAllocationService;
use MyInvoice\Service\Payroll\Net\PayrollPartnerSettlement;

/**
 * Zápočet čisté mzdy na účet společníka — JEDINÉ místo, které ze zmrazených
 * výplatních pravidel počítá započtené částky.
 *
 * Vzniklo vyčleněním z {@see PayrollPostingLineBuilder}, protože tutéž částku
 * potřebuje i {@see PayrollPostingReconciliationService}: mzdová strana
 * kontrolních součtů MZ-13 o způsobu vypořádání čisté mzdy nic neví, takže bez
 * ní firmě se zápočtem trvale svítil rozdíl proti deníku i proti platbám.
 * Kdyby si obě místa počítala zápočet zvlášť, rozešly by se dřív nebo později.
 */
final class PayrollPartnerSettlementResolver
{
    public function __construct(
        private readonly PayoutAllocationService $payoutAllocations =
            new PayoutAllocationService(),
    ) {}

    /**
     * Zápočty jedné osoby: relační závazkový účet mzdy (331/366) MD proti účtu
     * zápočtu (365.x) D.
     *
     * Proč je tenhle způsob výplaty jiný než hotovost a banka: nevyplácí se.
     * Nevzniká platba, platební příkaz ani pokladní doklad — je to čistě účetní
     * překlasifikace závazku. Účetní zápis proto vzniká v můstku, kdežto závazek
     * čisté mzdy a řádek platební dávky vzniknout NESMÍ (viz
     * PayrollNetWageLiabilityMaterializer). Kdyby vznikly, firma by vyplatila
     * peníze, které jsou už vypořádané.
     *
     * @param list<string> $relationTypes
     * @return list<array{
     *   allocation_reference:string,
     *   account_code:string,
     *   amount_minor:int
     * }>
     */
    public function forEmployee(
        int $employeeId,
        mixed $payoutRules,
        array $relationTypes,
        int $payableAfterEnforcement,
    ): array {
        if (!is_array($payoutRules) || !array_is_list($payoutRules)) {
            return [];
        }
        $hasSettlement = false;
        foreach ($payoutRules as $rule) {
            if (is_array($rule)
                && ($rule['destination_kind'] ?? null) === PayrollPartnerSettlement::KIND
            ) {
                $hasSettlement = true;
                break;
            }
        }
        if (!$hasSettlement) {
            // Bez zápočtu se výplatní pravidla vůbec nerozpočítávají. Účetní
            // zápis tak zůstává pro všechny dosavadní i budoucí revize bez
            // zápočtu byte-identický — zmrazené snapshoty bez klíče payout_rules
            // nevyjímaje.
            return [];
        }
        PayrollPartnerSettlement::assertEligible($relationTypes, $employeeId);
        if ($payableAfterEnforcement <= 0) {
            // Není co započíst — přeplatek se řeší pohledávkou za
            // zaměstnancem, ne zápočtem na účet společníka.
            return [];
        }

        $result = [];
        foreach ($this->payoutAllocations->allocate(
            $payableAfterEnforcement,
            $this->requests($payoutRules),
        )->allocations as $allocation) {
            if ($allocation->destinationKind !== PayrollPartnerSettlement::KIND
                || $allocation->amountMinorUnits === 0
            ) {
                continue;
            }
            $result[] = [
                'allocation_reference' => $allocation->allocationReference,
                'account_code' => $this->account(
                    $allocation->destinationReference,
                ),
                'amount_minor' => $allocation->amountMinorUnits,
            ];
        }

        return $result;
    }

    /**
     * Součet zápočtů celé revize.
     *
     * Čte se z TÝCHŽ zmrazených dat, ze kterých účtuje můstek: výplatní pravidla
     * ze vstupního snapshotu a čistá výplata po exekuci z výsledku. Nezávisí to
     * tedy na tom, jestli už se zaúčtovalo — v daňové evidenci, kde deník není,
     * to platí stejně jako v podvojném účetnictví.
     *
     * @param array<string,mixed> $inputSnapshot zmrazený vstup revize
     * @param array<string,mixed> $result ověřený výsledný snapshot revize
     */
    public function totalForRevision(array $inputSnapshot, array $result): int
    {
        $payableByEmployee = [];
        foreach (self::objectList($result['people'] ?? null) as $person) {
            $employeeId = $person['employee_id'] ?? null;
            $payable = $person['payable_after_enforcement_minor'] ?? null;
            if (!is_int($employeeId) || !is_int($payable)) {
                continue;
            }
            $payableByEmployee[$employeeId] = $payable;
        }

        $total = 0;
        foreach (self::objectList($inputSnapshot['people'] ?? null) as $person) {
            $employee = $person['employee'] ?? null;
            $employeeId = is_array($employee) ? ($employee['id'] ?? null) : null;
            if (!is_int($employeeId)
                || !array_key_exists($employeeId, $payableByEmployee)
            ) {
                continue;
            }
            $relationTypes = [];
            foreach (self::objectList($person['employments'] ?? null) as $employment) {
                $identity = $employment['employment'] ?? null;
                $relationType = is_array($identity)
                    ? ($identity['relation_type'] ?? null)
                    : null;
                if (is_string($relationType)) {
                    $relationTypes[] = $relationType;
                }
            }
            foreach ($this->forEmployee(
                $employeeId,
                $person['payout_rules'] ?? null,
                $relationTypes,
                $payableByEmployee[$employeeId],
            ) as $settlement) {
                $total += $settlement['amount_minor'];
            }
        }

        return $total;
    }

    /**
     * @param list<mixed> $payoutRules
     * @return list<PayoutAllocationRequest>
     */
    private function requests(array $payoutRules): array
    {
        $requests = [];
        foreach ($payoutRules as $rule) {
            if (!is_array($rule) || array_is_list($rule)) {
                throw new \UnexpectedValueException(
                    'snapshot.payout_rule musí být objekt.',
                );
            }
            $reference = self::requiredString($rule, 'allocation_reference');
            $destinationKind = self::requiredString($rule, 'destination_kind');
            $destinationReference = $rule['destination_reference'] ?? null;
            if ($destinationReference !== null && !is_string($destinationReference)) {
                throw new \DomainException(
                    'Reference platebního cíle není text.',
                );
            }
            $priority = self::nonNegativeInt($rule, 'priority_no');
            $requests[] = match (self::requiredString($rule, 'allocation_kind')) {
                'fixed' => PayoutAllocationRequest::fixed(
                    $reference,
                    $destinationKind,
                    $destinationReference,
                    self::nonNegativeInt($rule, 'amount_minor'),
                    $priority,
                ),
                'percentage' => PayoutAllocationRequest::percentage(
                    $reference,
                    $destinationKind,
                    $destinationReference,
                    self::nonNegativeInt($rule, 'basis_points'),
                    $priority,
                ),
                'remainder' => PayoutAllocationRequest::remainder(
                    $reference,
                    $destinationKind,
                    $destinationReference,
                    $priority,
                ),
                default => throw new \DomainException(
                    'Zmrazené výplatní pravidlo má nepodporovaný typ alokace.',
                ),
            };
        }

        return $requests;
    }

    private function account(mixed $value): string
    {
        if (!is_string($value) || !PayrollAccountCode::isValid($value)) {
            throw new \DomainException(
                'Účet cíl zápočtu na účet společníka není platný.',
            );
        }

        return $value;
    }

    /** @return list<array<string,mixed>> */
    private static function objectList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /** @param array<string,mixed> $row */
    private static function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException("{$field} musí být text.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nonNegativeInt(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException("{$field} musí být celé číslo.");
        }
        if ($value < 0) {
            throw new \UnexpectedValueException("{$field} musí být nezáporné.");
        }

        return $value;
    }
}
