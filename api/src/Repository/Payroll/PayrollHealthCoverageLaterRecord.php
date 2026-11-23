<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Zdravotní pojištění zapsané až od pozdějšího měsíce.
 *
 * Agentura s DPP zapsala pojišťovnu brigádníkovi s pozdějším vztahem (od dubna)
 * a únorová dohoda zůstala bez pojištění. Kontrola hlásila jen „chybí platné
 * zdravotní pojištění", karta osoby přitom pojišťovnu ukazovala a hromadný
 * dialog ji nenabídl. Tahle třída najde první pozdější záznam, aby hláška
 * řekla „zapsaná až od 04/2026" a dialog pojišťovnu předvyplnil.
 */
final class PayrollHealthCoverageLaterRecord
{
    public function __construct(private readonly Connection $db) {}

    /**
     * První záznam zdravotního pojištění, který začíná po prvním dni měsíce.
     *
     * @param list<int> $employeeIds
     * @return array<int, array{insurer_code:?string, effective_from:string}>
     */
    public function firstAfter(int $supplierId, string $monthStart, array $employeeIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $employeeIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'WITH later AS (
                 SELECT employee_id, insurer_code, effective_from,
                        ROW_NUMBER() OVER (PARTITION BY employee_id ORDER BY effective_from, id) AS position
                   FROM payroll_person_health_coverage_history
                  WHERE supplier_id = ?
                    AND employee_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')
                    AND effective_from > ?
             )
             SELECT employee_id, insurer_code, effective_from FROM later WHERE position = 1',
        );
        $statement->execute([$supplierId, ...$ids, $monthStart]);
        $records = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = is_string($row['insurer_code'] ?? null) && $row['insurer_code'] !== '' ? $row['insurer_code'] : null;
            $records[(int) $row['employee_id']] = [
                'insurer_code' => $code,
                'effective_from' => substr((string) $row['effective_from'], 0, 10),
            ];
        }

        return $records;
    }

    /**
     * K nálezu „chybí platné zdravotní pojištění" připíše, od kdy je pojišťovna
     * u osoby zapsaná. Klíče pole zůstávají.
     *
     * @template T of array{entity_type:string, entity_id:?int, message:string, issue_code?:string}
     * @param array<array-key,T> $details
     * @return array<array-key,T>
     */
    public function annotate(int $supplierId, string $monthStart, array $details): array
    {
        $employeeIds = [];
        foreach ($details as $detail) {
            if (self::isMissingCoverage($detail)) {
                $employeeIds[] = (int) $detail['entity_id'];
            }
        }
        if ($employeeIds === []) {
            return $details;
        }
        $records = $this->firstAfter($supplierId, $monthStart, $employeeIds);
        foreach ($details as &$detail) {
            $record = self::isMissingCoverage($detail) ? ($records[(int) $detail['entity_id']] ?? null) : null;
            if ($record !== null) {
                $detail['message'] .= ' ' . self::hint($record);
            }
        }
        unset($detail);

        return $details;
    }

    /** @param iterable<array{entity_type:string, entity_id:?int, issue_code?:string}> $details */
    public static function concerns(iterable $details): bool
    {
        foreach ($details as $detail) {
            if (self::isMissingCoverage($detail)) {
                return true;
            }
        }

        return false;
    }

    /** @param array{entity_type:string, entity_id:?int, issue_code?:string} $detail */
    private static function isMissingCoverage(array $detail): bool
    {
        return $detail['entity_type'] === 'employee'
            && $detail['entity_id'] !== null
            && str_contains((string) ($detail['issue_code'] ?? ''), 'health_coverage_evidence_missing');
    }

    /** @param array{insurer_code:?string, effective_from:string} $record */
    public static function hint(array $record): string
    {
        $from = substr($record['effective_from'], 5, 2) . '/' . substr($record['effective_from'], 0, 4);

        return sprintf(
            'Zdravotní pojišťovna%s je u osoby zapsaná až od %s, tento měsíc nepokrývá. '
            . 'Platí-li i pro tento měsíc, doplňte ji v Mzdy → Zaměstnanci → Další funkce → '
            . 'Zdravotní pojišťovny hromadně; dialog ji předvyplní.',
            $record['insurer_code'] === null ? '' : ' ' . $record['insurer_code'],
            $from,
        );
    }
}
