<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollPersonProfileRepository;

/** @phpstan-import-type TermsInput from PayrollEmploymentValidator */
final class PayrollPersonQuickEditService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollPersonProfileRepository $profiles,
        private readonly PayrollPersonProfileValidator $profileValidator,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $employmentValidator,
    ) {}

    /**
     * @param array<string,mixed> $input
     * @return array{profile:array<string,mixed>,employment:?array<string,mixed>}
     */
    public function save(
        int $supplierId,
        int $employeeId,
        array $input,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $profileInput = $this->object($input['profile'] ?? null, 'profile');
        $profileVersion = $this->version($profileInput['row_version'] ?? null, true);
        $normalizedProfile = $this->profileValidator->validate($profileInput);

        $employmentInput = null;
        if (($input['employment'] ?? null) !== null) {
            $employmentInput = $this->object($input['employment'], 'employment');
        }
        $normalizedEmployment = $employmentInput === null
            ? null
            : $this->employment($employmentInput, $supplierId);
        $codeChange = ($input['employment_code'] ?? null) === null
            ? null
            : $this->codeChange($this->object($input['employment_code'], 'employment_code'));

        return $this->transaction(function () use (
            $supplierId,
            $employeeId,
            $normalizedProfile,
            $profileVersion,
            $normalizedEmployment,
            $codeChange,
            $userId,
            $ip,
            $userAgent,
        ): array {
            $profile = $this->profiles->save(
                $supplierId,
                $employeeId,
                $normalizedProfile,
                $profileVersion,
                $userId,
                $ip,
                $userAgent,
            );

            $employment = null;
            if ($normalizedEmployment !== null) {
                $this->ownedEmployment($supplierId, $employeeId, $normalizedEmployment['id']);
                $employment = $this->employments->addTerms(
                    $supplierId,
                    $normalizedEmployment['id'],
                    $normalizedEmployment['terms'],
                    $normalizedEmployment['row_version'],
                    $userId,
                    $ip,
                    $userAgent,
                    $normalizedEmployment['replace_monthly_gross'],
                    $normalizedEmployment['monthly_gross_minor'],
                );
            }

            /*
             * Osobní číslo (kód vztahu) se mění přejmenováním, ne novou verzí
             * podmínek — jinak by změna čísla založila v historii vztahu verzi,
             * ve které se nic nesjednalo. Verze vztahu se bere čerstvá, protože
             * uložení podmínek o řádek výš ji právě posunulo.
             */
            if ($codeChange !== null) {
                $current = $this->ownedEmployment($supplierId, $employeeId, $codeChange['employment_id']);
                if ($this->requiredString($current, 'code') !== $codeChange['code']) {
                    $employment = $this->employments->rename(
                        $supplierId,
                        $codeChange['employment_id'],
                        $codeChange['code'],
                        $this->requiredInt($current, 'row_version'),
                        $userId,
                        $ip,
                        $userAgent,
                    );
                }
            }

            return [
                'profile' => $profile,
                'employment' => $employment ?? $this->primaryEmployment($supplierId, $employeeId),
            ];
        });
    }

    /**
     * @param array<string,mixed> $input
     * @return array{employment_id:int,code:string}
     */
    private function codeChange(array $input): array
    {
        return [
            'employment_id' => $this->version($input['employment_id'] ?? null, false),
            'code' => $this->employmentValidator->code($input),
        ];
    }

    /** @return array<string,mixed> */
    private function ownedEmployment(int $supplierId, int $employeeId, int $employmentId): array
    {
        foreach ($this->employments->listForEmployee($supplierId, $employeeId) as $employment) {
            if ($this->requiredInt($employment, 'id') === $employmentId) {
                return $employment;
            }
        }
        throw new PayrollEmploymentNotFoundException(
            'Pracovní vztah zaměstnance nebyl nalezen.',
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   id:int,
     *   row_version:int,
     *   monthly_gross_minor:?int,
     *   replace_monthly_gross:bool,
     *   terms:TermsInput
     * }
     */
    private function employment(array $input, int $supplierId): array
    {
        $id = $this->version($input['id'] ?? null, false);
        $rowVersion = $this->version($input['row_version'] ?? null, false);
        $replaceMonthlyGross = array_key_exists('monthly_gross_minor', $input);
        $monthlyGross = $input['monthly_gross_minor'] ?? null;
        if ($monthlyGross !== null && (!is_int($monthlyGross) || $monthlyGross < 0)) {
            throw new \InvalidArgumentException(
                'Pravidelná hrubá mzda musí být nezáporná částka v haléřích.',
            );
        }
        $termsBody = $this->object($input['terms'] ?? null, 'employment.terms');
        $current = $this->employments->currentTerms($supplierId, $id)
            ?? throw new \DomainException('Pracovní vztah nemá verzi sjednaných podmínek.');
        // Rychlá editace způsob odměňování nenabízí; změna patří na kartu vztahu.
        foreach (['remuneration_type', 'hourly_rate_minor', 'remuneration_basis'] as $key) {
            $termsBody[$key] = $current[$key];
        }
        $terms = $this->employmentValidator->terms(
            $termsBody,
            // Rychlá editace mění hlavně mzdu a osobní údaje — historický kód
            // CZ-ISCO mimo číselník ji nesmí zablokovat, dokud na něj nikdo nesáhne.
            $this->employments->currentCzIscoCode($supplierId, $id),
            // Zařazení pro srážkovou daň rychlá editace vůbec nenabízí, takže
            // ho nesmí přepsat na „neurčeno" — přebírá se uložené.
            $this->employments->currentOtherWithholdingEligibility($supplierId, $id),
            $this->employments->currentRelationType($supplierId, $id),
        );

        return [
            'id' => $id,
            'row_version' => $rowVersion,
            'monthly_gross_minor' => $monthlyGross,
            'replace_monthly_gross' => $replaceMonthlyGross,
            'terms' => $terms,
        ];
    }

    /** @return ?array<string,mixed> */
    private function primaryEmployment(int $supplierId, int $employeeId): ?array
    {
        $fallback = null;
        foreach ($this->employments->listForEmployee($supplierId, $employeeId) as $employment) {
            if ($this->requiredBool($employment, 'is_primary') === false) {
                continue;
            }
            $fallback ??= $employment;
            if (in_array(
                $this->requiredString($employment, 'status'),
                ['planned', 'preregistered', 'active', 'suspended'],
                true,
            )) {
                return $employment;
            }
        }

        return $fallback;
    }

    /** @return array<string,mixed> */
    private function object(mixed $value, string $path): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException("{$path} musí být objekt.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException("{$path} musí být objekt.");
            }
            $result[$key] = $item;
        }

        return $result;
    }

    private function version(mixed $value, bool $allowZero): int
    {
        $minimum = $allowZero ? 0 : 1;
        if (!is_int($value) || $value < $minimum) {
            $kind = $allowZero ? 'nezáporné' : 'kladné';
            throw new \InvalidArgumentException(
                "row_version a identifikátory musí být {$kind} celé číslo.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function requiredInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException("Pracovní vztah neobsahuje platné pole {$key}.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function requiredBool(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (!is_bool($value)) {
            throw new \UnexpectedValueException("Pracovní vztah neobsahuje platné pole {$key}.");
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Pracovní vztah neobsahuje platné pole {$key}.");
        }

        return $value;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_person_quick_edit');
        }

        try {
            $result = $callback();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_person_quick_edit');
            }

            return $result;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($owns) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_person_quick_edit');
                $pdo->exec('RELEASE SAVEPOINT payroll_person_quick_edit');
            }
            throw $e;
        }
    }
}
