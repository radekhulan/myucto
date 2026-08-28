<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use DateTimeImmutable;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\PayrollApprovedPeriodFreeze;
use MyInvoice\Service\Payroll\PayrollDependantCreditPreview;
use MyInvoice\Service\Payroll\PayrollDependantValidator;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use PDO;

/**
 * Evidence vyživovaných osob (MZ-04-W05).
 *
 * Nárok na daňové zvýhodnění se NEUKLÁDÁ do nové tabulky — zapisuje se do
 * `payroll_person_tax_child_claims` (migrace 1256), tedy přesně tam, odkud ho
 * čte snímek mzdové revize a měsíční výpočet daně. Tady přibyla jen osoba
 * (`payroll_dependants`) a vazba `dependant_id`.
 *
 * @phpstan-import-type DependantInput from \MyInvoice\Service\Payroll\PayrollDependantValidator
 * @phpstan-import-type ClaimInput from \MyInvoice\Service\Payroll\PayrollDependantValidator
 * @phpstan-type DependantsView array{
 *   employee_id:int,
 *   effective_on:string,
 *   frozen_through:?string,
 *   dependants:list<array<string,mixed>>
 * }
 */
final class PayrollDependantRepository
{
    private const SAVEPOINT = 'payroll_dependant_save';
    private const OPEN_END = '9999-12-31';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollSensitiveData $sensitiveData,
        private readonly PayrollDependantValidator $validator,
        private readonly PayrollDependantCreditPreview $preview,
        private readonly ActivityLogger $activityLogger,
        private readonly PayrollApprovedPeriodFreeze $freeze,
    ) {}

    /** @return DependantsView|null */
    public function overview(
        int $supplierId,
        int $employeeId,
        string $effectiveOn,
    ): ?array {
        $exists = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employees WHERE supplier_id = ? AND id = ?'
        );
        $exists->execute([$supplierId, $employeeId]);
        if ($exists->fetchColumn() === false) {
            return null;
        }

        $frozenThrough = $this->frozenThrough($supplierId);
        $claimsByDependant = $this->claimRows($supplierId, $employeeId);

        $statement = $this->db->pdo()->prepare(
            'SELECT id, relation, full_name, given_name, family_name,
                    birth_date, birth_number_masked,
                    birth_number_hash, ztp_p, student, existence_from,
                    existence_to, note, row_version, created_at, updated_at
               FROM payroll_dependants
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY birth_date ASC, id ASC'
        );
        $statement->execute([$supplierId, $employeeId]);

        $dependants = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            $row = $this->assoc($fetched);
            $id = (int) $row['id'];
            $relation = (string) $row['relation'];
            $existenceFrom = (string) $row['existence_from'];
            $existenceTo = $row['existence_to'] === null
                ? null
                : (string) $row['existence_to'];
            $claims = [];
            foreach ($claimsByDependant[$id] ?? [] as $claim) {
                $claims[] = $this->presentClaim(
                    $supplierId,
                    $employeeId,
                    $claim,
                    $relation,
                    $existenceFrom,
                    $existenceTo,
                    $effectiveOn,
                    $frozenThrough,
                );
            }

            $dependants[] = [
                'id' => $id,
                'relation' => $relation,
                'full_name' => (string) $row['full_name'],
                'given_name' => $row['given_name'] === null
                    ? null
                    : (string) $row['given_name'],
                'family_name' => $row['family_name'] === null
                    ? null
                    : (string) $row['family_name'],
                'birth_date' => (string) $row['birth_date'],
                'birth_number_masked' => $row['birth_number_masked'] === null
                    ? null
                    : (string) $row['birth_number_masked'],
                'has_birth_number' => $row['birth_number_hash'] !== null,
                'ztp_p' => (bool) $row['ztp_p'],
                'student' => (bool) $row['student'],
                'existence_from' => $existenceFrom,
                'existence_to' => $existenceTo,
                'note' => $row['note'] === null ? null : (string) $row['note'],
                'can_claim_monthly' => in_array(
                    $relation,
                    PayrollDependantValidator::CHILD_RELATIONS,
                    true,
                ),
                'row_version' => (int) $row['row_version'],
                'claims' => $claims,
            ];
        }

        return [
            'employee_id' => $employeeId,
            'effective_on' => $effectiveOn,
            'frozen_through' => $frozenThrough,
            'dependants' => $dependants,
        ];
    }

    /**
     * @param DependantInput $data
     * @return DependantsView
     */
    public function createDependant(
        int $supplierId,
        int $employeeId,
        array $data,
        string $effectiveOn,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        return $this->transactional(
            $supplierId,
            $employeeId,
            $effectiveOn,
            function () use ($supplierId, $employeeId, $data, $userId, $ip, $userAgent): void {
                $insert = $this->db->pdo()->prepare(
                    'INSERT INTO payroll_dependants
                        (supplier_id, employee_id, relation, full_name,
                         given_name, family_name, birth_date,
                         ztp_p, student, existence_from, existence_to, note,
                         created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $supplierId,
                    $employeeId,
                    $data['relation'],
                    $data['full_name'],
                    $data['given_name'],
                    $data['family_name'],
                    $data['birth_date'],
                    (int) $data['ztp_p'],
                    (int) $data['student'],
                    $data['existence_from'],
                    $data['existence_to'],
                    $data['note'],
                    $userId,
                    $userId,
                ]);
                $id = (int) $this->db->pdo()->lastInsertId();
                if ($data['birth_number'] !== null) {
                    $this->sealBirthNumber(
                        $supplierId,
                        $employeeId,
                        $id,
                        $data['birth_number'],
                    );
                }
                $this->activityLogger->log(
                    'payroll.dependant.created',
                    $userId,
                    'payroll_employee',
                    $employeeId,
                    [
                        'dependant_id' => $id,
                        'relation' => $data['relation'],
                        'has_birth_number' => $data['birth_number'] !== null,
                    ],
                    $ip,
                    $userAgent,
                    $supplierId,
                );
            },
        );
    }

    /**
     * @param DependantInput $data
     * @return DependantsView
     */
    public function updateDependant(
        int $supplierId,
        int $employeeId,
        int $dependantId,
        array $data,
        int $expectedVersion,
        string $effectiveOn,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        return $this->transactional(
            $supplierId,
            $employeeId,
            $effectiveOn,
            function () use (
                $supplierId,
                $employeeId,
                $dependantId,
                $data,
                $expectedVersion,
                $userId,
                $ip,
                $userAgent,
            ): void {
                $current = $this->lockDependant($supplierId, $employeeId, $dependantId);
                if ((int) $current['row_version'] !== $expectedVersion) {
                    throw new PayrollDependantConflictException(
                        (int) $current['row_version'],
                    );
                }

                $claims = $this->claimRows($supplierId, $employeeId)[$dependantId] ?? [];
                if ($claims !== []
                    && !in_array(
                        $data['relation'],
                        PayrollDependantValidator::CHILD_RELATIONS,
                        true,
                    )
                ) {
                    throw new \InvalidArgumentException(
                        'Osoba s evidovaným nárokem na zvýhodnění nemůže mít vztah'
                        . ' manžel/partner — zvýhodnění náleží jen dítěti.',
                    );
                }
                foreach ($claims as $claim) {
                    if ($claim['superseded_by_id'] !== null) {
                        continue;
                    }
                    $this->assertWithinExistence(
                        (string) $claim['effective_from'],
                        $claim['effective_to'] === null
                            ? null
                            : (string) $claim['effective_to'],
                        $data['existence_from'],
                        $data['existence_to'],
                    );
                    if ((bool) $claim['ztp_p'] && !$data['ztp_p']) {
                        throw new \InvalidArgumentException(
                            'Osobu nelze odznačit jako ZTP/P, dokud existuje nárok'
                            . ' uplatněný v dvojnásobné výši.',
                        );
                    }
                }

                $update = $this->db->pdo()->prepare(
                    'UPDATE payroll_dependants
                        SET relation = ?, full_name = ?, given_name = ?,
                            family_name = ?, birth_date = ?,
                            ztp_p = ?, student = ?, existence_from = ?,
                            existence_to = ?, note = ?, updated_by = ?,
                            row_version = row_version + 1
                      WHERE supplier_id = ? AND employee_id = ? AND id = ?
                        AND row_version = ?'
                );
                $update->execute([
                    $data['relation'],
                    $data['full_name'],
                    $data['given_name'],
                    $data['family_name'],
                    $data['birth_date'],
                    (int) $data['ztp_p'],
                    (int) $data['student'],
                    $data['existence_from'],
                    $data['existence_to'],
                    $data['note'],
                    $userId,
                    $supplierId,
                    $employeeId,
                    $dependantId,
                    $expectedVersion,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new PayrollDependantConflictException(
                        (int) $this->lockDependant(
                            $supplierId,
                            $employeeId,
                            $dependantId,
                        )['row_version'],
                    );
                }
                if ($data['birth_number'] !== null) {
                    $this->sealBirthNumber(
                        $supplierId,
                        $employeeId,
                        $dependantId,
                        $data['birth_number'],
                    );
                }
                $this->activityLogger->log(
                    'payroll.dependant.updated',
                    $userId,
                    'payroll_employee',
                    $employeeId,
                    [
                        'dependant_id' => $dependantId,
                        'relation' => $data['relation'],
                        'birth_number_replaced' => $data['birth_number'] !== null,
                    ],
                    $ip,
                    $userAgent,
                    $supplierId,
                );
            },
        );
    }

    /**
     * @param ClaimInput $data
     * @return DependantsView
     */
    public function createClaim(
        int $supplierId,
        int $employeeId,
        int $dependantId,
        array $data,
        string $effectiveOn,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        return $this->transactional(
            $supplierId,
            $employeeId,
            $effectiveOn,
            function () use (
                $supplierId,
                $employeeId,
                $dependantId,
                $data,
                $userId,
                $ip,
                $userAgent,
            ): void {
                $dependant = $this->lockDependant($supplierId, $employeeId, $dependantId);
                $this->assertClaimable($supplierId, $employeeId, $dependant, $data, 0);
                $id = $this->insertClaim(
                    $supplierId,
                    $employeeId,
                    $dependantId,
                    $data,
                    $userId,
                );
                $this->activityLogger->log(
                    'payroll.dependant_claim.created',
                    $userId,
                    'payroll_employee',
                    $employeeId,
                    [
                        'dependant_id' => $dependantId,
                        'claim_id' => $id,
                        'child_order' => $data['child_order'],
                        'ztp_p' => $data['ztp_p'],
                        'effective_from' => $data['effective_from'],
                        'effective_to' => $data['effective_to'],
                    ],
                    $ip,
                    $userAgent,
                    $supplierId,
                );
            },
        );
    }

    /**
     * Změna nebo ukončení nároku.
     *
     * Zasahuje-li nárok do období zmrazeného schválenou mzdovou revizí, věcná
     * změna historii nepřepíše: původní řádek se ukončí posledním zmrazeným
     * měsícem a vznikne nová účinná verze od následujícího měsíce.
     *
     * @param ClaimInput $data
     * @return DependantsView
     */
    public function saveClaim(
        int $supplierId,
        int $employeeId,
        int $dependantId,
        int $claimId,
        array $data,
        int $expectedVersion,
        string $effectiveOn,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        return $this->transactional(
            $supplierId,
            $employeeId,
            $effectiveOn,
            function () use (
                $supplierId,
                $employeeId,
                $dependantId,
                $claimId,
                $data,
                $expectedVersion,
                $userId,
                $ip,
                $userAgent,
            ): void {
                $dependant = $this->lockDependant($supplierId, $employeeId, $dependantId);
                $claim = $this->lockClaim(
                    $supplierId,
                    $employeeId,
                    $dependantId,
                    $claimId,
                );
                if ((int) $claim['row_version'] !== $expectedVersion) {
                    throw new PayrollDependantConflictException(
                        (int) $claim['row_version'],
                    );
                }
                if ($claim['superseded_by_id'] !== null) {
                    throw new \InvalidArgumentException(
                        'Nahrazený nárok už nelze měnit — uprav jeho novější verzi.',
                    );
                }

                $frozenThrough = $this->frozenThrough($supplierId);
                $frozen = $frozenThrough !== null
                    && (string) $claim['effective_from'] <= $frozenThrough;
                $substantive = $this->substantiveChange($claim, $data);

                if ($frozen && (string) $claim['effective_from'] !== $data['effective_from']) {
                    throw new \InvalidArgumentException(
                        'Začátek nároku už je zmrazený schválenou mzdovou revizí'
                        . ' a nelze jej posunout.',
                    );
                }
                if ($frozen && !$substantive
                    && $data['effective_to'] !== null
                    && $data['effective_to'] < $frozenThrough
                ) {
                    throw new \InvalidArgumentException(
                        'Nárok nelze ukončit uvnitř období zmrazeného schválenou'
                        . ' mzdovou revizí.',
                    );
                }

                if ($frozen && $substantive) {
                    $this->supersedeClaim(
                        $supplierId,
                        $employeeId,
                        $dependantId,
                        $claimId,
                        $claim,
                        $dependant,
                        $data,
                        (string) $frozenThrough,
                        $userId,
                        $ip,
                        $userAgent,
                    );

                    return;
                }

                $this->assertClaimable(
                    $supplierId,
                    $employeeId,
                    $dependant,
                    $data,
                    $claimId,
                );
                $update = $this->db->pdo()->prepare(
                    'UPDATE payroll_person_tax_child_claims
                        SET child_order = ?, claim_reason = ?, ztp_p = ?,
                            evidence_status = ?, evidence_reference = ?,
                            shared_household_confirmed = ?,
                            other_claimant_excluded = ?,
                            effective_from = ?, effective_to = ?,
                            updated_by = ?, row_version = row_version + 1
                      WHERE supplier_id = ? AND employee_id = ?
                        AND dependant_id = ? AND id = ? AND row_version = ?'
                );
                $update->execute([
                    $data['child_order'],
                    $data['claim_reason'],
                    (int) $data['ztp_p'],
                    $data['evidence_status'],
                    $data['evidence_reference'],
                    (int) $data['shared_household_confirmed'],
                    (int) $data['other_claimant_excluded'],
                    $data['effective_from'],
                    $data['effective_to'],
                    $userId,
                    $supplierId,
                    $employeeId,
                    $dependantId,
                    $claimId,
                    $expectedVersion,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new PayrollDependantConflictException(
                        (int) $this->lockClaim(
                            $supplierId,
                            $employeeId,
                            $dependantId,
                            $claimId,
                        )['row_version'],
                    );
                }
                $this->activityLogger->log(
                    'payroll.dependant_claim.updated',
                    $userId,
                    'payroll_employee',
                    $employeeId,
                    [
                        'dependant_id' => $dependantId,
                        'claim_id' => $claimId,
                        'child_order' => $data['child_order'],
                        'effective_to' => $data['effective_to'],
                    ],
                    $ip,
                    $userAgent,
                    $supplierId,
                );
            },
        );
    }

    /**
     * @param array<string,mixed> $claim
     * @param array<string,mixed> $dependant
     * @param ClaimInput $data
     */
    private function supersedeClaim(
        int $supplierId,
        int $employeeId,
        int $dependantId,
        int $claimId,
        array $claim,
        array $dependant,
        array $data,
        string $frozenThrough,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $nextFrom = (new DateTimeImmutable($frozenThrough))
            ->modify('+1 day')
            ->format('Y-m-d');
        if ($data['effective_to'] !== null && $data['effective_to'] < $nextFrom) {
            throw new \InvalidArgumentException(
                'Nová verze nároku musí platit nejdříve od ' . $nextFrom . '.',
            );
        }
        $previousTo = $claim['effective_to'] === null
            ? null
            : (string) $claim['effective_to'];
        if ($previousTo !== null && $previousTo < $frozenThrough) {
            throw new \InvalidArgumentException(
                'Ukončený nárok mimo zmrazené období už nelze nahradit.',
            );
        }

        $close = $this->db->pdo()->prepare(
            'UPDATE payroll_person_tax_child_claims
                SET effective_to = ?, updated_by = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        );
        $close->execute([
            $frozenThrough,
            $userId,
            $supplierId,
            $employeeId,
            $claimId,
        ]);
        if ($close->rowCount() !== 1) {
            throw new PayrollDependantNotFoundException();
        }

        $replacement = $data;
        $replacement['effective_from'] = $nextFrom;
        $this->assertClaimable(
            $supplierId,
            $employeeId,
            $dependant,
            $replacement,
            $claimId,
        );
        $newId = $this->insertClaim(
            $supplierId,
            $employeeId,
            $dependantId,
            $replacement,
            $userId,
        );

        $link = $this->db->pdo()->prepare(
            'UPDATE payroll_person_tax_child_claims
                SET superseded_by_id = ?
              WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        );
        $link->execute([$newId, $supplierId, $employeeId, $claimId]);

        $this->activityLogger->log(
            'payroll.dependant_claim.superseded',
            $userId,
            'payroll_employee',
            $employeeId,
            [
                'dependant_id' => $dependantId,
                'claim_id' => $claimId,
                'replacement_claim_id' => $newId,
                'frozen_through' => $frozenThrough,
                'effective_from' => $nextFrom,
            ],
            $ip,
            $userAgent,
            $supplierId,
        );
    }

    /** @param ClaimInput $data */
    private function insertClaim(
        int $supplierId,
        int $employeeId,
        int $dependantId,
        array $data,
        ?int $userId,
    ): int {
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_tax_child_claims
                (supplier_id, employee_id, dependant_id, child_reference,
                 child_order, claim_reason, ztp_p, evidence_status,
                 shared_household_confirmed, other_claimant_excluded,
                 effective_from, effective_to, evidence_reference,
                 created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $supplierId,
            $employeeId,
            $dependantId,
            $this->childReference($dependantId),
            $data['child_order'],
            $data['claim_reason'],
            (int) $data['ztp_p'],
            $data['evidence_status'],
            (int) $data['shared_household_confirmed'],
            (int) $data['other_claimant_excluded'],
            $data['effective_from'],
            $data['effective_to'],
            $data['evidence_reference'],
            $userId,
            $userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function childReference(int $dependantId): string
    {
        return 'dependant-' . $dependantId;
    }

    /**
     * @param array<string,mixed> $dependant
     * @param ClaimInput $data
     */
    private function assertClaimable(
        int $supplierId,
        int $employeeId,
        array $dependant,
        array $data,
        int $excludeClaimId,
    ): void {
        $relation = (string) $dependant['relation'];
        if (!in_array($relation, PayrollDependantValidator::CHILD_RELATIONS, true)) {
            throw new \InvalidArgumentException(
                'Měsíční daňové zvýhodnění lze uplatnit jen na dítě; slevu na'
                . ' manžela/partnera řeší až roční zúčtování.',
            );
        }
        if ($data['ztp_p'] && !(bool) $dependant['ztp_p']) {
            throw new \InvalidArgumentException(
                'Dvojnásobné zvýhodnění vyžaduje, aby osoba byla vedena jako ZTP/P.',
            );
        }
        $this->assertWithinExistence(
            $data['effective_from'],
            $data['effective_to'],
            (string) $dependant['existence_from'],
            $dependant['existence_to'] === null
                ? null
                : (string) $dependant['existence_to'],
        );

        if ($data['evidence_status'] === 'verified'
            && !$this->hasSignedDeclaration(
                $supplierId,
                $employeeId,
                $data['effective_from'],
            )
        ) {
            throw new \InvalidArgumentException(
                'Doložený nárok vyžaduje podepsané prohlášení poplatníka platné'
                . ' k počátku nároku.',
            );
        }

        $dependantId = (int) $dependant['id'];
        $to = $data['effective_to'] ?? self::OPEN_END;

        $overlap = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_person_tax_child_claims
              WHERE supplier_id = ? AND employee_id = ? AND dependant_id = ?
                AND id <> ?
                AND effective_from <= ?
                AND COALESCE(effective_to, ?) >= ?
              LIMIT 1'
        );
        $overlap->execute([
            $supplierId,
            $employeeId,
            $dependantId,
            $excludeClaimId,
            $to,
            self::OPEN_END,
            $data['effective_from'],
        ]);
        if ($overlap->fetchColumn() !== false) {
            throw new \InvalidArgumentException(
                'Nárok na totéž dítě se u tohoto poplatníka překrývá s jiným'
                . ' obdobím uplatnění.',
            );
        }

        $order = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_person_tax_child_claims
              WHERE supplier_id = ? AND employee_id = ? AND child_order = ?
                AND id <> ?
                AND effective_from <= ?
                AND COALESCE(effective_to, ?) >= ?
              LIMIT 1'
        );
        $order->execute([
            $supplierId,
            $employeeId,
            $data['child_order'],
            $excludeClaimId,
            $to,
            self::OPEN_END,
            $data['effective_from'],
        ]);
        if ($order->fetchColumn() !== false) {
            throw new \InvalidArgumentException(
                'Pořadí dítěte ' . $data['child_order'] . ' už je ve stejném období'
                . ' u tohoto poplatníka obsazené.',
            );
        }

        if ($dependant['birth_number_hash'] !== null) {
            $shared = $this->db->pdo()->prepare(
                'SELECT claim.id
                   FROM payroll_person_tax_child_claims claim
                   JOIN payroll_dependants person
                     ON person.supplier_id = claim.supplier_id
                    AND person.id = claim.dependant_id
                  WHERE claim.supplier_id = ?
                    AND claim.employee_id <> ?
                    AND person.birth_number_hash = ?
                    AND claim.superseded_by_id IS NULL
                    AND claim.effective_from <= ?
                    AND COALESCE(claim.effective_to, ?) >= ?
                  LIMIT 1'
            );
            $shared->execute([
                $supplierId,
                $employeeId,
                $dependant['birth_number_hash'],
                $to,
                self::OPEN_END,
                $data['effective_from'],
            ]);
            if ($shared->fetchColumn() !== false) {
                throw new \InvalidArgumentException(
                    'Totéž dítě už ve stejném období uplatňuje jiný poplatník'
                    . ' u tohoto zaměstnavatele.',
                );
            }
        }
    }

    private function assertWithinExistence(
        string $from,
        ?string $to,
        string $existenceFrom,
        ?string $existenceTo,
    ): void {
        if ($from < $existenceFrom) {
            throw new \InvalidArgumentException(
                'Nárok nemůže začít dříve, než je osoba vedena jako vyživovaná.',
            );
        }
        if ($existenceTo !== null && ($to === null || $to > $existenceTo)) {
            throw new \InvalidArgumentException(
                'Nárok nemůže trvat déle, než je osoba vedena jako vyživovaná.',
            );
        }
    }

    private function hasSignedDeclaration(
        int $supplierId,
        int $employeeId,
        string $on,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            "SELECT id
               FROM payroll_person_tax_declarations
              WHERE supplier_id = ? AND employee_id = ?
                AND status = 'signed'
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              LIMIT 1"
        );
        $statement->execute([$supplierId, $employeeId, $on, $on]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string,mixed> $claim
     * @param ClaimInput $data
     */
    private function substantiveChange(array $claim, array $data): bool
    {
        return (int) $claim['child_order'] !== $data['child_order']
            || (bool) $claim['ztp_p'] !== $data['ztp_p']
            || (string) $claim['evidence_status'] !== $data['evidence_status']
            || ($claim['evidence_reference'] === null
                ? null
                : (string) $claim['evidence_reference']) !== $data['evidence_reference']
            || ($claim['claim_reason'] === null
                ? null
                : (string) $claim['claim_reason']) !== $data['claim_reason']
            || (bool) $claim['shared_household_confirmed']
                !== $data['shared_household_confirmed']
            || (bool) $claim['other_claimant_excluded']
                !== $data['other_claimant_excluded'];
    }

    private function sealBirthNumber(
        int $supplierId,
        int $employeeId,
        int $dependantId,
        string $plaintext,
    ): void {
        $sealed = $this->sensitiveData->seal(
            $plaintext,
            PayrollSensitiveField::PERSONAL_IDENTIFIER,
            $supplierId,
            $dependantId,
        );
        $update = $this->db->pdo()->prepare(
            'UPDATE payroll_dependants
                SET birth_number_ciphertext = ?, birth_number_hash = ?,
                    birth_number_masked = ?
              WHERE supplier_id = ? AND employee_id = ? AND id = ?'
        );
        $update->execute([
            $sealed->ciphertext,
            $sealed->lookupHash,
            $sealed->masked,
            $supplierId,
            $employeeId,
            $dependantId,
        ]);
        if ($update->rowCount() < 1) {
            throw new PayrollDependantNotFoundException();
        }
    }

    /** @return array<string,mixed> */
    private function lockDependant(
        int $supplierId,
        int $employeeId,
        int $dependantId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, relation, ztp_p, existence_from, existence_to,
                    birth_number_hash, row_version
               FROM payroll_dependants
              WHERE supplier_id = ? AND employee_id = ? AND id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $employeeId, $dependantId]);
        $fetched = $statement->fetch(PDO::FETCH_ASSOC);
        if ($fetched === false) {
            throw new PayrollDependantNotFoundException();
        }

        return $this->assoc($fetched);
    }

    /** @return array<string,mixed> */
    private function lockClaim(
        int $supplierId,
        int $employeeId,
        int $dependantId,
        int $claimId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, child_order, claim_reason, ztp_p, evidence_status,
                    evidence_reference, shared_household_confirmed,
                    other_claimant_excluded, effective_from, effective_to,
                    superseded_by_id, row_version
               FROM payroll_person_tax_child_claims
              WHERE supplier_id = ? AND employee_id = ? AND dependant_id = ?
                AND id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $employeeId, $dependantId, $claimId]);
        $fetched = $statement->fetch(PDO::FETCH_ASSOC);
        if ($fetched === false) {
            throw new PayrollDependantNotFoundException();
        }

        return $this->assoc($fetched);
    }

    /** @return array<int,list<array<string,mixed>>> */
    private function claimRows(int $supplierId, int $employeeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, dependant_id, child_reference, child_order, claim_reason,
                    ztp_p, evidence_status, evidence_reference,
                    shared_household_confirmed, other_claimant_excluded,
                    effective_from, effective_to, superseded_by_id, row_version
               FROM payroll_person_tax_child_claims
              WHERE supplier_id = ? AND employee_id = ? AND dependant_id IS NOT NULL
              ORDER BY effective_from DESC, id DESC'
        );
        $statement->execute([$supplierId, $employeeId]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            $row = $this->assoc($fetched);
            $result[(int) $row['dependant_id']][] = $row;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $claim
     * @return array<string,mixed>
     */
    private function presentClaim(
        int $supplierId,
        int $employeeId,
        array $claim,
        string $relation,
        string $existenceFrom,
        ?string $existenceTo,
        string $effectiveOn,
        ?string $frozenThrough,
    ): array {
        $from = (string) $claim['effective_from'];
        $to = $claim['effective_to'] === null ? null : (string) $claim['effective_to'];
        $reference = $this->referenceMonth($effectiveOn, $from, $to);

        $blockers = [];
        if (!in_array($relation, PayrollDependantValidator::CHILD_RELATIONS, true)) {
            $blockers[] = 'relation_not_child';
        }
        if ((string) $claim['evidence_status'] !== 'verified') {
            $blockers[] = 'evidence_unverified';
        }
        if (!(bool) $claim['shared_household_confirmed']) {
            $blockers[] = 'shared_household_unconfirmed';
        }
        if (!(bool) $claim['other_claimant_excluded']) {
            $blockers[] = 'other_claimant_not_excluded';
        }
        if (!$this->hasSignedDeclaration($supplierId, $employeeId, $reference)) {
            $blockers[] = 'declaration_missing';
        }
        if ($from < $existenceFrom
            || ($existenceTo !== null && ($to === null || $to > $existenceTo))
        ) {
            $blockers[] = 'outside_existence';
        }
        if ($claim['superseded_by_id'] !== null) {
            $blockers[] = 'superseded';
        }

        return [
            'id' => (int) $claim['id'],
            'child_reference' => (string) $claim['child_reference'],
            'child_order' => (int) $claim['child_order'],
            'claim_reason' => $claim['claim_reason'] === null
                ? null
                : (string) $claim['claim_reason'],
            'ztp_p' => (bool) $claim['ztp_p'],
            'evidence_status' => (string) $claim['evidence_status'],
            'evidence_reference' => $claim['evidence_reference'] === null
                ? null
                : (string) $claim['evidence_reference'],
            'shared_household_confirmed' => (bool) $claim['shared_household_confirmed'],
            'other_claimant_excluded' => (bool) $claim['other_claimant_excluded'],
            'effective_from' => $from,
            'effective_to' => $to,
            'superseded_by_id' => $claim['superseded_by_id'] === null
                ? null
                : (int) $claim['superseded_by_id'],
            'row_version' => (int) $claim['row_version'],
            'is_frozen' => $frozenThrough !== null && $from <= $frozenThrough,
            'blockers' => $blockers,
            'credit' => $this->preview->monthly(
                (int) $claim['child_order'],
                (bool) $claim['ztp_p'],
                $reference,
            ),
        ];
    }

    private function referenceMonth(string $effectiveOn, string $from, ?string $to): string
    {
        $monthStart = substr($effectiveOn, 0, 7) . '-01';
        if ($monthStart < $from) {
            return $from;
        }
        if ($to !== null && $monthStart > $to) {
            return substr($to, 0, 7) . '-01';
        }

        return $monthStart;
    }

    private function frozenThrough(int $supplierId): ?string
    {
        return $this->freeze->frozenThrough($supplierId);
    }

    /**
     * @param callable():void $work
     * @return DependantsView
     */
    private function transactional(
        int $supplierId,
        int $employeeId,
        string $effectiveOn,
        callable $work,
    ): array {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }

        try {
            $this->lockEmployee($supplierId, $employeeId);
            $work();
            $view = $this->overview($supplierId, $employeeId, $effectiveOn)
                ?? throw new PayrollPersonNotFoundException();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }

            return $view;
        } catch (\Throwable $exception) {
            if ($owns) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            if ($exception instanceof \PDOException
                && (string) $exception->getCode() === '23000'
            ) {
                throw new \InvalidArgumentException(
                    'Evidence vyživovaných osob obsahuje duplicitní záznam'
                    . ' (rodné číslo nebo období nároku).',
                    0,
                    $exception,
                );
            }
            throw $exception;
        }
    }

    private function lockEmployee(int $supplierId, int $employeeId): void
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $employeeId]);
        if ($statement->fetchColumn() === false) {
            throw new PayrollPersonNotFoundException();
        }
    }

    /** @return array<string,mixed> */
    private function assoc(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                'Databáze vrátila neplatný řádek evidence vyživovaných osob.',
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Databáze vrátila řádek bez textových klíčů.',
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
