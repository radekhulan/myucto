<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;
use MyInvoice\Repository\PayrollEmployeeRepository;
use MyInvoice\Service\ActivityLogger;

final class PayrollPersonCreateService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollPersonCreateValidator $validator,
        private readonly PayrollEmployeeRepository $employees,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollPeopleRepository $people,
        private readonly PayrollPersonProfileRepository $profiles,
        private readonly ActivityLogger $activityLogger,
        private readonly PayrollPersonHealthInsurerSeedService $healthInsurer,
    ) {}

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(
        int $supplierId,
        array $input,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $validated = $this->validator->validate($input);

        return $this->transactional(function () use (
            $supplierId,
            $validated,
            $userId,
            $ip,
            $userAgent,
        ): array {
            $employeeId = $this->employees->insert($supplierId, $validated['employee']);
            $this->db->pdo()->prepare(
                "INSERT INTO payroll_employee_profiles
                    (supplier_id, employee_id, profile_status)
                 VALUES (?, ?, 'setup')"
            )->execute([$supplierId, $employeeId]);

            $employment = $validated['employment'];
            $employment['code'] = 'ZAM-' . $employeeId;
            $this->employments->create(
                $supplierId,
                $employeeId,
                $employment,
                $userId,
                $ip,
                $userAgent,
            );

            /*
             * Historická identita a rodné číslo jdou týmž zápisem jako osoba —
             * stejný argument jako u zdravotní pojišťovny níž. Bez identity
             * účinné k rozhodnému dni hlásí měsíční JMHZ jen obecné
             * `jmhz_identity_incomplete` a účetní z toho nepozná, že chybí
             * právě ona; rodné číslo zadané ve formuláři zase nemělo kam
             * dopadnout, protože legacy sloupec na kartě se nepoužívá.
             */
            $this->profiles->seedInitialPersonalData(
                $supplierId,
                $employeeId,
                $validated['employee']['full_name'],
                $validated['employee']['birth_date'],
                $validated['birth_number'],
                (string) $employment['terms']['planned_start_on'],
            );

            $this->activityLogger->log(
                'payroll.person.created',
                $userId,
                'payroll_employee',
                $employeeId,
                [
                    'relation_type' => $employment['relation_type'],
                    'planned_start_on' => $employment['terms']['planned_start_on'],
                ],
                $ip,
                $userAgent,
                $supplierId,
            );

            /*
             * Zdravotní pojišťovna patří do TÉHOŽ zápisu jako zaměstnanec.
             * Jde o zákonnou evidenci — kdyby se dopisovala až druhým
             * požadavkem, jeho selhání by nechalo osobu bez ní.
             */
            if ($validated['health_insurer_code'] !== null) {
                $this->healthInsurer->seed(
                    $supplierId,
                    $employeeId,
                    $validated['health_insurer_code'],
                    (string) $employment['terms']['planned_start_on'],
                    $userId,
                    $ip,
                    $userAgent,
                );
            }

            return $this->people->findForTenant($supplierId, $employeeId)
                ?? throw new \LogicException('Nově založený zaměstnanec nebyl nalezen.');
        });
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_person_create');
        }

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_person_create');
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_person_create');
                $pdo->exec('RELEASE SAVEPOINT payroll_person_create');
            }
            throw $e;
        }
    }
}
