<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bulk;

use MyInvoice\Infrastructure\Database\TransactionFailure;
use PDO;

/**
 * Transakce jedné položky hromadné akce: vlastní, nebo savepoint uvnitř transakce
 * volajícího. `$discard` = výsledek jen spočítat a změny zahodit (běh nanečisto).
 * Odvolanou transakci (deadlock, změněný snapshot) neodvolává savepointem, ten po ní
 * už neexistuje ({@see TransactionFailure::rollbackIfAborted()}).
 */
final class BulkTransaction
{
    private static int $savepoint = 0;

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function run(PDO $pdo, callable $work, bool $discard = false): mixed
    {
        $own = !$pdo->inTransaction();
        $savepoint = 'bulk_item_' . ++self::$savepoint;
        $own ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT ' . $savepoint);
        try {
            $result = $work();
        } catch (\Throwable $e) {
            if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $pdo->inTransaction()) {
                $own ? $pdo->rollBack() : $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }
            throw $e;
        }
        if ($discard) {
            $own ? $pdo->rollBack() : $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
        } else {
            $own ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
        return $result;
    }
}
