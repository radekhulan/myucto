<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Čtení pro průvodce postupem po 20. dni: řádná měsíční hlášení firmy
 * v rozsahu období. Povinností řádného hlášení je jedna za měsíc a účtárnu,
 * takže výběr přes `uq_payroll_obligations_regular_period` (firma,
 * prostředí, agenda) zůstává malý i u velké firmy.
 */
final class PayrollJmhzPost20Repository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array{submission_id:int,status:string,period_start:string}>
     */
    public function regularSubmissions(
        int $supplierId,
        string $environment,
        string $agendaCode,
        string $fromPeriod,
        string $toPeriod,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT submission.id AS submission_id, submission.status, obligation.period_start
               FROM payroll_obligations obligation
               JOIN payroll_submissions submission
                 ON submission.supplier_id = obligation.supplier_id
                AND submission.environment = obligation.environment
                AND submission.obligation_id = obligation.id
              WHERE obligation.supplier_id = ?
                AND obligation.environment = ?
                AND obligation.agenda_code = ?
                AND obligation.obligation_kind = \'regular\'
                AND obligation.period_start >= ?
                AND obligation.period_start <= ?
                AND submission.submission_kind = \'regular\'
                AND submission.status IN (\'submitted\', \'accepted\', \'partially_accepted\')
              ORDER BY obligation.period_start, submission.id',
        );
        $statement->execute([$supplierId, $environment, $agendaCode, $fromPeriod, $toPeriod]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'submission_id' => (int) $row['submission_id'],
                'status' => (string) $row['status'],
                'period_start' => (string) $row['period_start'],
            ];
        }

        return $rows;
    }
}
