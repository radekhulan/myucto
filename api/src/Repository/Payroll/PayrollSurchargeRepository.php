<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use DomainException;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Čtení podkladů pro zákonné příplatky § 114 až § 118 ZP.
 *
 * Vlastní repozitář, ne rozšíření {@see PayrollTimeRepository}: ten vrací
 * zápisy docházky ZA CELOU FIRMU v okně a volající si je sám seskupuje, protože
 * ho staví přehled měsíce. Výpočet příplatku se naopak ptá na JEDEN pracovní
 * vztah a načítat kvůli němu celou firmu by u větší firmy znamenalo desítky
 * tisíc řádků na jeden mzdový list.
 */
final class PayrollSurchargeRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Zápisy docházky jednoho vztahu v UTC okně.
     *
     * Filtruje se `status <> 'superseded'` stejně jako v {@see PayrollTimeRepository::entries()}:
     * schvalování docházky je na úrovni MĚSÍCE (`payroll_time_months`), ne
     * jednotlivého zápisu, takže `draft` řádky jsou platná evidence, jen dosud
     * neuzavřená. Uzavřenost měsíce si hlídá volající.
     *
     * @return list<array<string,mixed>>
     */
    public function entries(
        int $supplierId,
        int $employmentId,
        string $startsAtUtc,
        string $endsAtUtc,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, category, starts_at_utc, ends_at_utc, timezone_name,
                    break_minutes, difficulty_factor_count, status
               FROM payroll_time_entries
              WHERE supplier_id = ?
                AND employment_id = ?
                AND status <> \'superseded\'
                AND starts_at_utc >= ?
                AND starts_at_utc < ?
              ORDER BY starts_at_utc, id'
        );
        $stmt->execute([$supplierId, $employmentId, $startsAtUtc, $endsAtUtc]);

        return PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'payroll_time_entries',
        );
    }

    /**
     * Náhradní volno za přesčas podle dne PŘESČASU (migrace 1492).
     *
     * @return list<array<string,mixed>>
     */
    public function overtimeCompensations(
        int $supplierId,
        int $employmentId,
        string $fromDate,
        string $toDate,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT overtime_date, minutes, granted_on
               FROM payroll_overtime_compensations
              WHERE supplier_id = ?
                AND employment_id = ?
                AND overtime_date BETWEEN ? AND ?
              ORDER BY overtime_date'
        );
        $stmt->execute([$supplierId, $employmentId, $fromDate, $toDate]);

        return PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'payroll_overtime_compensations',
        );
    }

    /**
     * Verze zásady účinná k danému dni, nebo `null`, není-li sjednána žádná.
     *
     * `null` NENÍ chyba a nesmí se překládat na nulu — výchozí zákonný režim
     * dosadí {@see \MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargePolicy::statutoryDefault()}.
     *
     * @return array<string,mixed>|null
     */
    public function policy(int $supplierId, int $employmentId, string $effectiveOn): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_employment_surcharge_policies
              WHERE supplier_id = ?
                AND employment_id = ?
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $effectiveOn, $effectiveOn]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : PayrollTimeValue::row($row, 'payroll_employment_surcharge_policy');
    }

    public function employmentExists(int $supplierId, int $employmentId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_employments WHERE supplier_id = ? AND id = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Všechny verze zásady jednoho vztahu, od nejnovější.
     *
     * Historie se ukazuje celá, ne jen účinná verze: mzda spočítaná podle staré
     * zásady na ni dál ukazuje, takže „proč vyšel příplatek zrovna takhle" se
     * bez ní nedá zodpovědět.
     *
     * @return list<array<string,mixed>>
     */
    public function policiesForEmployment(int $supplierId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_employment_surcharge_policies
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY valid_from DESC, id DESC'
        );
        $stmt->execute([$supplierId, $employmentId]);

        return PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'payroll_employment_surcharge_policies',
        );
    }

    /**
     * Založí novou verzi zásady a předchozí OTEVŘENÉ verzi dopočítá `valid_to`
     * na den před účinností nové.
     *
     * Historie se nepřepisuje: mzda spočítaná podle staré zásady na ni dál
     * ukazuje. Stejný vzor jako u verzí mzdových složek
     * ({@see PayrollComponentRepository::ensureDefaults()}).
     *
     * @param array<string,mixed> $data
     */
    public function savePolicy(
        int $supplierId,
        int $employmentId,
        string $validFrom,
        array $data,
        ?int $userId,
    ): int {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $close = $pdo->prepare(
                'UPDATE payroll_employment_surcharge_policies
                    SET valid_to = DATE_SUB(?, INTERVAL 1 DAY),
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND employment_id = ?
                    AND valid_from < ? AND valid_to IS NULL'
            );
            $close->execute([$validFrom, $supplierId, $employmentId, $validFrom]);

            $exists = $pdo->prepare(
                'SELECT 1 FROM payroll_employment_surcharge_policies
                  WHERE supplier_id = ? AND employment_id = ? AND valid_from >= ?
                  LIMIT 1'
            );
            $exists->execute([$supplierId, $employmentId, $validFrom]);
            if ($exists->fetchColumn() !== false) {
                throw new DomainException(
                    'Pro tento pracovní vztah už existuje zásada příplatků platná od téhož '
                    . 'nebo pozdějšího dne. Upravte ji místo zakládání nové.',
                );
            }

            $stmt = $pdo->prepare(
                'INSERT INTO payroll_employment_surcharge_policies
                    (supplier_id, employment_id, valid_from, overtime_mode, holiday_mode,
                     difficult_environment_factors, overtime_rate_bp, holiday_rate_bp,
                     night_rate_bp, weekend_rate_bp, difficult_environment_rate_bp,
                     agreement_reference, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $employmentId,
                $validFrom,
                $data['overtime_mode'] ?? 'surcharge',
                $data['holiday_mode'] ?? 'compensatory_time_off',
                $data['difficult_environment_factors'] ?? null,
                $data['overtime_rate_bp'] ?? null,
                $data['holiday_rate_bp'] ?? null,
                $data['night_rate_bp'] ?? null,
                $data['weekend_rate_bp'] ?? null,
                $data['difficult_environment_rate_bp'] ?? null,
                $data['agreement_reference'] ?? null,
                $data['note'] ?? null,
                $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $id;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
