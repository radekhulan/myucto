<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Match;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\BankNoticeConfirmationScope;
use PDO;

final class MatchMetricsService
{
    public function __construct(private readonly Connection $db) {}

    /** @return array{precision:?float,recall:?float,auto:int,suggest:int,accepted:int,rejected:int,reverted:int,window_days:int} */
    public function metrics(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT decision, COUNT(*) AS total,
                    SUM(decision = 'auto' AND reverted_at IS NOT NULL
                        AND reverted_at <= DATE_ADD(a.created_at, INTERVAL 60 DAY)) AS reverted
               FROM bank_match_audit a
               JOIN bank_transactions bt ON bt.id = a.bank_transaction_id
              WHERE a.supplier_id = ? AND a.created_at >= ? AND a.created_at < DATE_ADD(?, INTERVAL 1 DAY)
                AND " . BankNoticeConfirmationScope::activeSql('bt') . "
              GROUP BY decision"
        );
        $stmt->execute([$supplierId, $from, $to]);
        $counts = ['auto' => 0, 'suggest' => 0, 'accept' => 0, 'reject' => 0, 'manual' => 0];
        $reverted = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $decision = (string) $row['decision'];
            if (array_key_exists($decision, $counts)) $counts[$decision] = (int) $row['total'];
            if ($decision === 'auto') $reverted = (int) $row['reverted'];
        }
        $auto = $counts['auto'];
        $recallBase = $auto + $counts['accept'] + $counts['manual'];
        $days = max(0, (int) floor(((strtotime($to) ?: 0) - (strtotime($from) ?: 0)) / 86400) + 1);
        return [
            'precision' => $auto === 0 ? null : round(($auto - $reverted) / $auto, 4),
            'recall' => $recallBase === 0 ? null : round($auto / $recallBase, 4),
            'auto' => $auto,
            'suggest' => $counts['suggest'],
            'accepted' => $counts['accept'],
            'rejected' => $counts['reject'],
            'reverted' => $reverted,
            'window_days' => $days,
        ];
    }
}
