<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;

/**
 * Evidence ročně uplatňovaných položek (§ 38h odst. 6): nezdanitelné části
 * základu daně podle § 15, sleva za zastavenou exekuci, sleva na manžela a od
 * roku 2027 sleva za umístění dítěte (§ 35bd).
 *
 * Ukládá se celá sada za rok najednou, stejně jako potvrzení od předchozích
 * plátců — uplatňuje se jako celek a půlka sady by zúčtování zkreslila.
 *
 * Validace běží přes konstruktory domény, ne přes vlastní pravidla: co se
 * dá uložit, to výpočet také přijme. Doložení se tu nevynucuje (nedoložená
 * položka se uložit smí a zúčtování ji zastaví překážkou); vynucuje se jen to,
 * co hlídají CHECK constrainty, aby neplatný vstup skončil jako 4xx, ne jako
 * chyba databáze.
 */
final class AnnualSettlementClaimsService
{
    private const MAX_DEDUCTIONS = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAnnualSettlementRepository $settlements,
    ) {}

    /**
     * Uložené nároky za rok jako doménové objekty i jako řádky pro API.
     *
     * @return array{
     *   claims:list<AnnualNonTaxableClaim>,
     *   spouse:?AnnualSpouseCreditClaim,
     *   preschool:list<AnnualPreschoolCreditClaim>,
     *   deductions:list<array<string,mixed>>,
     *   spouse_row:?array<string,mixed>,
     *   preschool_rows:list<array<string,mixed>>
     * }
     */
    public function load(int $supplierId, int $employeeId, int $taxYear): array
    {
        $stored = $this->settlements->annualClaimsForYear($supplierId, $employeeId, $taxYear);

        $claims = [];
        $deductionRows = [];
        foreach ($stored['deductions'] as $row) {
            $claims[] = new AnnualNonTaxableClaim(
                'deduction-' . $row['id'],
                AnnualNonTaxableKind::from((string) $row['kind']),
                $row['amount_minor'],
                $row['quantity'],
                $row['months_mask'],
                $row['housing_acquired_before_2021'],
                TaxEvidenceStatus::tryFrom((string) $row['evidence_status'])
                    ?? TaxEvidenceStatus::Unverified,
                self::text($row['evidence_reference'] ?? null),
                self::text($row['counterparty_name'] ?? null),
            );
            $deductionRows[] = [
                'id' => $row['id'],
                'kind' => (string) $row['kind'],
                'amount_minor_units' => $row['amount_minor'],
                'quantity' => $row['quantity'],
                'months_mask' => $row['months_mask'],
                'housing_acquired_before_2021' => $row['housing_acquired_before_2021'],
                'counterparty_name' => self::text($row['counterparty_name'] ?? null),
                'evidence_status' => (string) $row['evidence_status'],
                'evidence_reference' => self::text($row['evidence_reference'] ?? null),
                'note' => self::text($row['note'] ?? null),
            ];
        }

        $spouse = null;
        $spouseRow = null;
        if ($stored['spouse'] !== null) {
            $row = $stored['spouse'];
            $spouse = new AnnualSpouseCreditClaim(
                'dependant-' . $row['spouse_dependant_id'],
                (string) $row['spouse_relation'],
                self::day($row['spouse_existence_from']),
                $row['spouse_existence_to'] === null ? null : self::day($row['spouse_existence_to']),
                (bool) $row['spouse_ztp_p'],
                'dependant-' . $row['child_dependant_id'],
                (string) $row['child_relation'],
                self::day($row['child_birth_date']),
                $row['months_mask'],
                $row['ztp_p_months_mask'],
                $row['spouse_income_minor'],
                TaxEvidenceStatus::tryFrom((string) $row['evidence_status'])
                    ?? TaxEvidenceStatus::Unverified,
                self::text($row['evidence_reference'] ?? null),
            );
            $spouseRow = [
                'spouse_dependant_id' => $row['spouse_dependant_id'],
                'child_dependant_id' => $row['child_dependant_id'],
                'months_mask' => $row['months_mask'],
                'ztp_p_months_mask' => $row['ztp_p_months_mask'],
                'spouse_income_minor_units' => $row['spouse_income_minor'],
                'evidence_status' => (string) $row['evidence_status'],
                'evidence_reference' => self::text($row['evidence_reference'] ?? null),
                'note' => self::text($row['note'] ?? null),
            ];
        }

        $preschool = [];
        $preschoolRows = [];
        foreach ($stored['preschool'] as $row) {
            $preschool[] = new AnnualPreschoolCreditClaim(
                'dependant-' . $row['child_dependant_id'],
                (string) $row['child_relation'],
                self::day($row['child_birth_date']),
                self::day($row['child_existence_from']),
                $row['child_existence_to'] === null ? null : self::day($row['child_existence_to']),
                AnnualPreschoolFacilityKind::from((string) $row['facility_kind']),
                self::text($row['facility_name'] ?? null),
                $row['expenses_minor'],
                $row['households_count'],
                $row['agreed_cap_minor'],
                $row['claimed_by_other_taxpayer'],
                $row['claimed_as_business_expense'],
                $row['grandchild_care_replaces_parents'],
                TaxEvidenceStatus::tryFrom((string) $row['evidence_status'])
                    ?? TaxEvidenceStatus::Unverified,
                self::text($row['evidence_reference'] ?? null),
                $row['other_supporting_taxpayer'],
                self::text($row['other_taxpayer_name'] ?? null),
                $row['other_taxpayer_claimed_minor'],
                self::text($row['other_taxpayer_confirmation_reference'] ?? null),
                self::text($row['agreement_reference'] ?? null),
            );
            $preschoolRows[] = [
                'child_dependant_id' => $row['child_dependant_id'],
                'facility_kind' => (string) $row['facility_kind'],
                'facility_name' => self::text($row['facility_name'] ?? null),
                'expenses_minor_units' => $row['expenses_minor'],
                'households_count' => $row['households_count'],
                'agreed_cap_minor_units' => $row['agreed_cap_minor'],
                'claimed_by_other_taxpayer' => $row['claimed_by_other_taxpayer'],
                'claimed_as_business_expense' => $row['claimed_as_business_expense'],
                'grandchild_care_replaces_parents' => $row['grandchild_care_replaces_parents'],
                'other_supporting_taxpayer' => $row['other_supporting_taxpayer'],
                'other_taxpayer_name' => self::text($row['other_taxpayer_name'] ?? null),
                'other_taxpayer_claimed_minor_units' => $row['other_taxpayer_claimed_minor'],
                'other_taxpayer_confirmation_reference' => self::text($row['other_taxpayer_confirmation_reference'] ?? null),
                'agreement_reference' => self::text($row['agreement_reference'] ?? null),
                'evidence_status' => (string) $row['evidence_status'],
                'evidence_reference' => self::text($row['evidence_reference'] ?? null),
                'note' => self::text($row['note'] ?? null),
            ];
        }

        return [
            'claims' => $claims,
            'spouse' => $spouse,
            'preschool' => $preschool,
            'deductions' => $deductionRows,
            'spouse_row' => $spouseRow,
            'preschool_rows' => $preschoolRows,
        ];
    }

    /**
     * Osoby z evidence pro výběr manžela a dítěte (bez rodného čísla).
     *
     * @return list<array<string,mixed>>
     */
    public function dependants(int $supplierId, int $employeeId): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => $row['id'],
                'relation' => (string) $row['relation'],
                'full_name' => (string) $row['full_name'],
                'birth_date' => substr((string) $row['birth_date'], 0, 10),
                'ztp_p' => $row['ztp_p'],
            ],
            $this->settlements->dependantsForEmployee($supplierId, $employeeId),
        );
    }

    /**
     * Přepíše celou sadu nároků za rok.
     *
     * @param list<array<string,mixed>> $deductions
     * @param array<string,mixed>|null $spouse
     * @param list<array<string,mixed>> $preschool
     * @return array{
     *   deductions:list<array<string,mixed>>,
     *   spouse:?array<string,mixed>,
     *   preschool:list<array<string,mixed>>
     * }
     * @throws \InvalidArgumentException neplatný vstup, cizí osoba v evidenci
     */
    public function save(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        array $deductions,
        ?array $spouse,
        ?int $actorUserId,
        array $preschool = [],
    ): array {
        if (count($deductions) > self::MAX_DEDUCTIONS) {
            throw new \InvalidArgumentException('Ročních položek je příliš mnoho.');
        }

        $preparedDeductions = [];
        foreach (array_values($deductions) as $index => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Roční položka není platná.');
            }
            $kind = AnnualNonTaxableKind::tryFrom((string) ($row['kind'] ?? ''));
            if ($kind === null) {
                throw new \InvalidArgumentException('Druh roční položky není platný.');
            }
            $status = self::status($row['evidence_status'] ?? null);
            $reference = self::bounded($row['evidence_reference'] ?? null, 500, 'Doklad');
            $counterparty = self::bounded($row['counterparty_name'] ?? null, 191, 'Protistrana');
            $note = self::bounded($row['note'] ?? null, 500, 'Poznámka');
            self::assertEvidence($status, $reference);

            $amount = self::integer($row['amount_minor_units'] ?? null, 'Částka');
            $quantity = self::integer($row['quantity'] ?? null, 'Počet');
            $months = self::integer($row['months_mask'] ?? null, 'Měsíce');
            $housing = self::flag($row['housing_acquired_before_2021'] ?? false);

            $claim = new AnnualNonTaxableClaim(
                'deduction-new-' . ($index + 1),
                $kind,
                $amount,
                $quantity,
                $months,
                $housing,
                $status,
                $reference,
                $counterparty,
            );
            $preparedDeductions[] = [
                'kind' => $claim->kind->value,
                'amount_minor' => $claim->amountMinorUnits,
                'quantity' => $claim->quantity,
                'months_mask' => $claim->monthsMask,
                'housing_acquired_before_2021' => $claim->housingAcquiredBefore2021,
                'counterparty_name' => $claim->counterpartyName,
                'evidence_status' => $claim->evidenceStatus->value,
                'evidence_reference' => $claim->evidenceReference,
                'note' => $note,
            ];
        }

        $preparedSpouse = null;
        if ($spouse !== null) {
            $preparedSpouse = $this->prepareSpouse($supplierId, $employeeId, $spouse);
        }

        if ($preschool !== [] && !AnnualPreschoolCreditStatute::isEffective($taxYear)) {
            throw new \InvalidArgumentException(
                'Slevu za umístění dítěte lze uplatnit až za zdaňovací období '
                . AnnualPreschoolCreditStatute::FIRST_YEAR . '.',
            );
        }
        if (count($preschool) > self::MAX_DEDUCTIONS) {
            throw new \InvalidArgumentException('Slev za umístění dítěte je příliš mnoho.');
        }
        $preparedPreschool = [];
        $preschoolChildren = [];
        foreach (array_values($preschool) as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Sleva za umístění dítěte není platná.');
            }
            $prepared = $this->preparePreschool($supplierId, $employeeId, $row);
            if (isset($preschoolChildren[$prepared['child_dependant_id']])) {
                throw new \InvalidArgumentException(
                    'Slevu za umístění téhož dítěte lze uplatnit jen jednou.',
                );
            }
            $preschoolChildren[$prepared['child_dependant_id']] = true;
            $preparedPreschool[] = $prepared;
        }

        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $this->settlements->replaceAnnualClaims(
                $supplierId,
                $employeeId,
                $taxYear,
                $preparedDeductions,
                $preparedSpouse,
                $actorUserId,
                $preparedPreschool,
            );
            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $loaded = $this->load($supplierId, $employeeId, $taxYear);

        return [
            'deductions' => $loaded['deductions'],
            'spouse' => $loaded['spouse_row'],
            'preschool' => $loaded['preschool_rows'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function preparePreschool(int $supplierId, int $employeeId, array $row): array
    {
        $childId = self::integer($row['child_dependant_id'] ?? null, 'Dítě');
        if ($childId === null || $childId <= 0) {
            throw new \InvalidArgumentException('U slevy za umístění dítěte je potřeba vybrat dítě.');
        }
        $child = $this->settlements->findDependant($supplierId, $employeeId, $childId);
        if ($child === null) {
            throw new \InvalidArgumentException(
                'Vybraná osoba není v evidenci vyživovaných osob tohoto zaměstnance.',
            );
        }
        $kind = AnnualPreschoolFacilityKind::tryFrom((string) ($row['facility_kind'] ?? ''));
        if ($kind === null) {
            throw new \InvalidArgumentException('Druh předškolního zařízení není platný.');
        }
        $status = self::status($row['evidence_status'] ?? null);
        $reference = self::bounded($row['evidence_reference'] ?? null, 500, 'Doklad');
        $facilityName = self::bounded($row['facility_name'] ?? null, 191, 'Zařízení');
        $note = self::bounded($row['note'] ?? null, 500, 'Poznámka');
        $otherName = self::bounded($row['other_taxpayer_name'] ?? null, 191, 'Jméno jiného poplatníka');
        $otherConfirmation = self::bounded(
            $row['other_taxpayer_confirmation_reference'] ?? null,
            500,
            'Potvrzení jiného poplatníka',
        );
        $agreement = self::bounded($row['agreement_reference'] ?? null, 500, 'Dohoda poplatníků');
        self::assertEvidence($status, $reference);

        // Konstruktor domény odmítne nulové výdaje a neplatný počet domácností.
        $claim = new AnnualPreschoolCreditClaim(
            'dependant-' . $childId,
            (string) $child['relation'],
            self::day($child['birth_date']),
            self::day($child['existence_from']),
            $child['existence_to'] === null ? null : self::day($child['existence_to']),
            $kind,
            $facilityName,
            self::integer($row['expenses_minor_units'] ?? null, 'Výdaje') ?? 0,
            self::integer($row['households_count'] ?? 1, 'Počet domácností') ?? 1,
            self::integer($row['agreed_cap_minor_units'] ?? null, 'Dohodnutý strop'),
            self::flag($row['claimed_by_other_taxpayer'] ?? false),
            self::flag($row['claimed_as_business_expense'] ?? false),
            self::flag($row['grandchild_care_replaces_parents'] ?? false),
            $status,
            $reference,
            self::flag($row['other_supporting_taxpayer'] ?? false),
            $otherName,
            self::integer($row['other_taxpayer_claimed_minor_units'] ?? null, 'Sleva jiného poplatníka'),
            $otherConfirmation,
            $agreement,
        );

        return [
            'child_dependant_id' => $childId,
            'facility_kind' => $claim->facilityKind->value,
            'facility_name' => $claim->facilityName,
            'expenses_minor' => $claim->expensesMinorUnits,
            'households_count' => $claim->householdsCount,
            'agreed_cap_minor' => $claim->agreedCapMinorUnits,
            'claimed_by_other_taxpayer' => $claim->claimedByOtherTaxpayer,
            'claimed_as_business_expense' => $claim->claimedAsBusinessExpense,
            'grandchild_care_replaces_parents' => $claim->grandchildCareReplacesParents,
            'other_supporting_taxpayer' => $claim->otherSupportingTaxpayer,
            'other_taxpayer_name' => $claim->otherTaxpayerName,
            'other_taxpayer_claimed_minor' => $claim->otherTaxpayerClaimedMinorUnits,
            'other_taxpayer_confirmation_reference' => $claim->otherTaxpayerConfirmationReference,
            'agreement_reference' => $claim->agreementReference,
            'evidence_status' => $claim->evidenceStatus->value,
            'evidence_reference' => $claim->evidenceReference,
            'note' => $note,
        ];
    }

    /**
     * @param array<string,mixed> $spouse
     * @return array<string,mixed>
     */
    private function prepareSpouse(int $supplierId, int $employeeId, array $spouse): array
    {
        $spouseId = self::integer($spouse['spouse_dependant_id'] ?? null, 'Manžel/ka');
        $childId = self::integer($spouse['child_dependant_id'] ?? null, 'Dítě');
        if ($spouseId === null || $childId === null || $spouseId <= 0 || $childId <= 0) {
            throw new \InvalidArgumentException('U slevy na manžela je potřeba vybrat manžela i dítě.');
        }
        if ($spouseId === $childId) {
            throw new \InvalidArgumentException('Manžel/ka a dítě musí být různé osoby.');
        }
        // Osoba musí patřit témuž zaměstnanci i firmě; cizí ID se tváří jako neexistující.
        $spouseRow = $this->settlements->findDependant($supplierId, $employeeId, $spouseId);
        $childRow = $this->settlements->findDependant($supplierId, $employeeId, $childId);
        if ($spouseRow === null || $childRow === null) {
            throw new \InvalidArgumentException(
                'Vybraná osoba není v evidenci vyživovaných osob tohoto zaměstnance.',
            );
        }

        $status = self::status($spouse['evidence_status'] ?? null);
        $reference = self::bounded($spouse['evidence_reference'] ?? null, 500, 'Doklad');
        $note = self::bounded($spouse['note'] ?? null, 500, 'Poznámka');
        self::assertEvidence($status, $reference);

        $months = self::integer($spouse['months_mask'] ?? null, 'Měsíce');
        $ztpMonths = self::integer($spouse['ztp_p_months_mask'] ?? 0, 'Měsíce ZTP/P') ?? 0;
        $income = self::integer($spouse['spouse_income_minor_units'] ?? null, 'Příjem manžela');

        // Konstruktor domény odmítne prázdné měsíce a záporný příjem.
        $claim = new AnnualSpouseCreditClaim(
            'dependant-' . $spouseId,
            (string) $spouseRow['relation'],
            self::day($spouseRow['existence_from']),
            $spouseRow['existence_to'] === null ? null : self::day($spouseRow['existence_to']),
            (int) $spouseRow['ztp_p'] === 1,
            'dependant-' . $childId,
            (string) $childRow['relation'],
            self::day($childRow['birth_date']),
            $months ?? 0,
            $ztpMonths,
            $income,
            $status,
            $reference,
        );

        return [
            'spouse_dependant_id' => $spouseId,
            'child_dependant_id' => $childId,
            'months_mask' => $claim->monthsMask,
            'ztp_p_months_mask' => $claim->ztpPMonthsMask,
            'spouse_income_minor' => $claim->spouseIncomeMinorUnits,
            'evidence_status' => $claim->evidenceStatus->value,
            'evidence_reference' => $claim->evidenceReference,
            'note' => $note,
        ];
    }

    private static function status(mixed $value): TaxEvidenceStatus
    {
        if ($value === null || $value === '') {
            return TaxEvidenceStatus::Unverified;
        }
        $status = TaxEvidenceStatus::tryFrom((string) $value);
        if ($status === null) {
            throw new \InvalidArgumentException('Stav doložení není platný.');
        }

        return $status;
    }

    /** Doložená položka nese doklad — stejně jako CHECK v databázi. */
    private static function assertEvidence(TaxEvidenceStatus $status, ?string $reference): void
    {
        if ($status === TaxEvidenceStatus::Verified && $reference === null) {
            throw new \InvalidArgumentException('Doložená položka musí nést odkaz na doklad.');
        }
    }

    private static function bounded(mixed $value, int $max, string $label): ?string
    {
        $text = self::text($value);
        if ($text !== null && mb_strlen($text) > $max) {
            throw new \InvalidArgumentException("{$label} je delší než {$max} znaků.");
        }

        return $text;
    }

    private static function integer(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('~^-?\d{1,18}$~', $value) === 1) {
            return (int) $value;
        }

        throw new \InvalidArgumentException("{$label} není celé číslo.");
    }

    private static function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (int) $value === 1;
        }
        if ($value === null) {
            return false;
        }

        throw new \InvalidArgumentException('Příznak bytové potřeby není platný.');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function day(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }
}
