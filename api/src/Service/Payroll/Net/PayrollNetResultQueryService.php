<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Service\Payroll\Garnishment\EnforcementEvidenceScope;

/**
 * Výsledkové API čisté mzdy (MZ-13, akceptační kritérium „srozumitelný rozklad
 * bez odhalení údajů jiných osob").
 *
 * Čte VÝHRADNĚ neměnné snapshoty schválené revize, takže rozklad odpovídá tomu,
 * co bylo schváleno, i když se dohody o srážkách nebo výplatní pravidla později
 * změnily. Vrací vždy právě jednu osobu — cizí osoby ze snapshotu se do odpovědi
 * nedostanou. Bankovní cíl se vydává jen jako maska ze zmrazeného snapshotu;
 * plaintext účtu ani jeho `bank_account_hash` v odpovědi nikdy není.
 */
final class PayrollNetResultQueryService
{
    public function __construct(
        private readonly PayrollRunRepository $runs,
        private readonly PayoutAllocationService $allocations,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function breakdown(int $supplierId, int $revisionId, int $employeeId): array
    {
        if ($supplierId <= 0 || $revisionId <= 0 || $employeeId <= 0) {
            throw new \InvalidArgumentException(
                'Firma, revize i osoba musí mít kladná ID.',
            );
        }
        $revision = $this->runs->revision($supplierId, $revisionId);
        if ($revision === null) {
            throw new \OutOfBoundsException('Mzdová revize nebyla nalezena.');
        }
        if (($revision['status'] ?? null) !== 'approved') {
            throw new \DomainException(
                'Rozklad čisté mzdy je dostupný jen ze schválené revize.',
            );
        }
        $input = self::object($revision['input_snapshot'] ?? null, 'input_snapshot');
        $result = self::object($revision['result_snapshot'] ?? null, 'result_snapshot');

        $personSnapshot = $this->snapshotPerson($input, $employeeId);
        $personResult = $this->resultPerson($result, $employeeId);
        $statutory = self::object($personResult['statutory'] ?? null, 'person.statutory');
        if (($statutory['status'] ?? null) !== 'calculated') {
            throw new \DomainException(
                'Osoba nemá ve schválené revizi uzavřený zákonný výpočet.',
            );
        }
        $net = self::object($statutory['net_pay'] ?? null, 'person.statutory.net_pay');
        if ((string) ($net['person_reference'] ?? '') !== (string) $employeeId) {
            throw new \DomainException('Výsledek čisté mzdy nepatří zadané osobě.');
        }

        // Obě částky smí být záporné — přeplatek čisté mzdy u osoby bez
        // peněžního příjmu s doplatkem ZP do minimálního vyměřovacího základu
        // (§ 3 odst. 10 z. č. 592/1992 Sb.). Rozpad je read model; kdyby tady
        // padal, účetní by se o dluhu zaměstnance nedozvěděla z obrazovky,
        // jen z deníku. Vztah „po exekucích ≤ čistá mzda" platí dál.
        $netPayable = self::integer($net, 'net_payable_minor_units');
        $payableAfterEnforcement = self::integer(
            $personResult,
            'payable_after_enforcement_minor',
        );
        if ($payableAfterEnforcement > $netPayable) {
            throw new \DomainException(
                'Výplata po exekucích nesmí převýšit čistou mzdu.',
            );
        }
        $employee = self::object($personSnapshot['employee'] ?? null, 'person.employee');
        $payout = $this->payout(
            $personSnapshot,
            $payableAfterEnforcement,
        );

        return [
            'revision' => [
                'id' => (int) $revision['id'],
                'run_id' => (int) $revision['run_id'],
                'revision_no' => (int) $revision['revision_no'],
                'revision_kind' => (string) $revision['revision_kind'],
                'status' => (string) $revision['status'],
            ],
            'person' => [
                'employee_id' => $employeeId,
                'full_name' => (string) ($employee['full_name'] ?? ''),
            ],
            'income' => [
                'cash_minor' => self::nonNegativeInt($net, 'cash_income_minor_units'),
                'non_cash_minor' => self::nonNegativeInt($net, 'non_cash_income_minor_units'),
                'gross_minor' => self::nonNegativeInt($net, 'cash_income_minor_units')
                    + self::nonNegativeInt($net, 'non_cash_income_minor_units'),
                'relationships' => $this->relationships($net),
            ],
            'contributions' => [
                'employee_social_minor' => self::nonNegativeInt($net, 'employee_social_minor_units'),
                'employee_health_minor' => self::nonNegativeInt($net, 'employee_health_minor_units'),
            ],
            'tax' => [
                'advance_minor' => self::nonNegativeInt($net, 'advance_tax_minor_units'),
                'withholding_minor' => self::nonNegativeInt($net, 'withholding_tax_minor_units'),
                'bonus_minor' => self::nonNegativeInt($net, 'tax_bonus_minor_units'),
            ],
            'correction_minor' => self::integer($net, 'correction_minor_units'),
            'net_before_deductions_minor' => self::integer(
                $net,
                'net_before_deductions_minor_units',
            ),
            'deductions' => $this->deductions($net, $personSnapshot),
            'deducted_minor' => self::nonNegativeInt($net, 'deducted_minor_units'),
            'net_payable_minor' => $netPayable,
            'enforcement_withheld_minor' => $netPayable - $payableAfterEnforcement,
            'enforcement_evidence_source' => self::evidenceSource($personResult),
            'payable_after_enforcement_minor' => $payableAfterEnforcement,
            'allocation_status' => $payout['status'],
            'allocations' => $payout['allocations'],
            'allocations_total_minor' => $payout['total_minor'],
        ];
    }

    /**
     * Rozsah exekuční evidence ze zmrazeného snímku výsledku.
     *
     * Bez něj stojí nesražená dohoda o srážkách v rozpadu bez důvodu, a přitom
     * jsou to dva různé případy se dvěma různými nápravami: „nevešlo se to do
     * nezabavitelné částky" se řeší penězi, kdežto „nezabavitelná částka stojí
     * na nároku, který nikdo nedoložil" se řeší doložením nároku. V číslech
     * vypadají stejně — {@see EnforcementEvidenceScope::protectedAmountIsUnattested()}
     * je od sebe odliší.
     *
     * Chybějící klíč = revize spočtená dřív, než se rozsah začal ukládat.
     * Nedopočítává se: tehdejší kód evidenci vyžadoval bezpodmínečně, takže
     * o jejím rozsahu netvrdil nic — vrací se null a obrazovka o důvodu mlčí.
     * Stejný důvod i tvar jako u `top_up_responsibility_source`.
     *
     * @param array<string,mixed> $personResult
     * @return array<string,string>|null
     */
    private static function evidenceSource(array $personResult): ?array
    {
        $enforcement = $personResult['enforcement'] ?? null;
        if (!is_array($enforcement) || array_is_list($enforcement)) {
            return null;
        }
        $result = $enforcement['result'] ?? null;
        if (!is_array($result) || array_is_list($result)) {
            return null;
        }
        if (($result['evidence_source'] ?? null) === null) {
            return null;
        }

        /*
         * Přes hodnotový objekt, ne přímým opsáním klíčů: `fromCanonicalArray()`
         * ověří, že hodnoty jsou skutečné případy enumu, takže se do odpovědi
         * nedostane text, kterému obrazovka nebude rozumět.
         */
        return EnforcementEvidenceScope::fromCanonicalArray(
            self::object(
                $result['evidence_source'],
                'person.enforcement.result.evidence_source',
            ),
        )->toCanonicalArray();
    }

    /**
     * @param array<string,mixed> $net
     * @return list<array<string,mixed>>
     */
    private function relationships(array $net): array
    {
        $result = [];
        foreach (self::rows($net['relationships'] ?? null, 'net_pay.relationships') as $relationship) {
            $result[] = [
                'relationship_reference' => (string) ($relationship['relationship_reference'] ?? ''),
                'cash_minor' => self::nonNegativeInt($relationship, 'cash_income_minor_units'),
                'non_cash_minor' => self::nonNegativeInt($relationship, 'non_cash_income_minor_units'),
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $net
     * @param array<string,mixed> $personSnapshot
     * @return list<array<string,mixed>>
     */
    private function deductions(array $net, array $personSnapshot): array
    {
        $titles = [];
        foreach (self::rows(
            $personSnapshot['deduction_agreements'] ?? null,
            'person.deduction_agreements',
        ) as $agreement) {
            $id = self::positiveInt($agreement, 'id');
            $titles[$id] = [
                'title' => (string) ($agreement['title'] ?? ''),
                'deduction_kind' => (string) ($agreement['deduction_kind'] ?? 'other'),
                'agreement_reference' => (string) ($agreement['agreement_reference'] ?? ''),
                'total_limit_minor' => $agreement['total_limit_minor'] === null
                    ? null
                    : self::nonNegativeInt($agreement, 'total_limit_minor'),
            ];
        }

        $result = [];
        foreach (self::rows($net['deductions'] ?? null, 'net_pay.deductions') as $deduction) {
            $reference = (string) ($deduction['deduction_reference'] ?? '');
            $agreementId = preg_match('/^agreement:([1-9][0-9]*)$/D', $reference, $match) === 1
                ? (int) $match[1]
                : null;
            $meta = $agreementId === null ? null : ($titles[$agreementId] ?? null);
            $result[] = [
                'agreement_id' => $agreementId,
                'deduction_reference' => $reference,
                'agreement_reference' => $meta['agreement_reference'] ?? null,
                'title' => $meta['title'] ?? $reference,
                'deduction_kind' => $meta['deduction_kind'] ?? 'other',
                'total_limit_minor' => $meta['total_limit_minor'] ?? null,
                'priority_no' => self::nonNegativeInt($deduction, 'priority'),
                'requested_minor' => self::nonNegativeInt($deduction, 'requested_minor_units'),
                'applied_minor' => self::nonNegativeInt($deduction, 'applied_minor_units'),
                'unapplied_minor' => self::nonNegativeInt($deduction, 'unapplied_minor_units'),
            ];
        }
        usort(
            $result,
            static fn (array $left, array $right): int => $left['priority_no'] <=> $right['priority_no']
                ?: strcmp((string) $left['deduction_reference'], (string) $right['deduction_reference']),
        );

        return $result;
    }

    /**
     * @param array<string,mixed> $personSnapshot
     * @return array{status:string,allocations:list<array<string,mixed>>,total_minor:int}
     */
    private function payout(array $personSnapshot, int $payableMinorUnits): array
    {
        if ($payableMinorUnits < 0) {
            // Není co rozdělovat: přeplatek se neposílá na účty, vede se jako
            // pohledávka za zaměstnancem. Vlastní stav, ne „no_rules" —
            // pravidla osoba má, jen tenhle měsíc nic nedostane.
            return [
                'status' => 'employee_receivable',
                'allocations' => [],
                'total_minor' => 0,
            ];
        }
        $rules = self::rows($personSnapshot['payout_rules'] ?? [], 'person.payout_rules');
        if ($rules === []) {
            return ['status' => 'no_rules', 'allocations' => [], 'total_minor' => 0];
        }
        $accounts = [];
        foreach (self::rows(
            $personSnapshot['payout_accounts'] ?? [],
            'person.payout_accounts',
        ) as $account) {
            $accounts[self::positiveInt($account, 'id')] = [
                'label' => (string) ($account['label'] ?? ''),
                'masked' => (string) ($account['bank_account_masked'] ?? ''),
            ];
        }

        $requests = [];
        foreach ($rules as $rule) {
            $reference = (string) ($rule['allocation_reference'] ?? '');
            $destinationKind = (string) ($rule['destination_kind'] ?? '');
            $destinationReference = $rule['destination_reference'] ?? null;
            if ($destinationReference !== null && !is_string($destinationReference)) {
                throw new \DomainException('Reference platebního cíle není text.');
            }
            $priority = self::nonNegativeInt($rule, 'priority_no');
            $requests[] = match ((string) ($rule['allocation_kind'] ?? '')) {
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

        $allocationResult = $this->allocations->allocate($payableMinorUnits, $requests);
        $allocations = [];
        $total = 0;
        foreach ($allocationResult->allocations as $index => $allocation) {
            $accountId = null;
            if ($allocation->destinationKind === 'bank'
                && is_string($allocation->destinationReference)
                && preg_match('/^account:([1-9][0-9]*)$/D', $allocation->destinationReference, $match) === 1
            ) {
                $accountId = (int) $match[1];
            }
            $account = $accountId === null ? null : ($accounts[$accountId] ?? null);
            $allocations[] = [
                'allocation_order' => $index + 1,
                'allocation_reference' => $allocation->allocationReference,
                'allocation_kind' => $allocation->allocationKind,
                'destination_kind' => $allocation->destinationKind,
                'destination_label' => $account['label'] ?? null,
                'destination_masked' => $account['masked'] ?? null,
                'payout_account_id' => $accountId,
                'amount_minor' => $allocation->amountMinorUnits,
            ];
            $total += $allocation->amountMinorUnits;
        }
        if ($total !== $payableMinorUnits) {
            throw new \DomainException(
                'Součet platebních alokací neodpovídá vyplácené čisté mzdě.',
            );
        }

        return [
            'status' => 'resolved',
            'allocations' => $allocations,
            'total_minor' => $total,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function snapshotPerson(array $input, int $employeeId): array
    {
        foreach (self::rows($input['people'] ?? null, 'input_snapshot.people') as $person) {
            $employee = self::object($person['employee'] ?? null, 'input_snapshot.person.employee');
            if (self::positiveInt($employee, 'id') === $employeeId) {
                return $person;
            }
        }

        throw new \OutOfBoundsException('Osoba není součástí schválené revize.');
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function resultPerson(array $result, int $employeeId): array
    {
        foreach (self::rows($result['people'] ?? null, 'result_snapshot.people') as $person) {
            if (self::positiveInt($person, 'employee_id') === $employeeId) {
                return $person;
            }
        }

        throw new \OutOfBoundsException('Osoba není součástí výsledku schválené revize.');
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být objekt.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException("{$field} musí mít textové klíče.");
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být seznam.");
        }
        $result = [];
        foreach ($value as $index => $row) {
            $result[] = self::object($row, "{$field}.{$index}");
        }

        return $result;
    }

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException("{$field} musí být celé číslo.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nonNegativeInt(array $row, string $field): int
    {
        $value = self::integer($row, $field);
        if ($value < 0) {
            throw new \UnexpectedValueException("{$field} musí být nezáporné celé číslo.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function positiveInt(array $row, string $field): int
    {
        $value = self::integer($row, $field);
        if ($value <= 0) {
            throw new \UnexpectedValueException("{$field} musí být kladné celé číslo.");
        }

        return $value;
    }
}
