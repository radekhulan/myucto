<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use PDO;

final class SavepointStatus
{
    /** @return array{Com_savepoint:int,Com_rollback_to_savepoint:int} */
    public static function read(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SHOW SESSION STATUS WHERE Variable_name IN ('Com_savepoint', 'Com_rollback_to_savepoint')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        return [
            'Com_savepoint' => (int) $rows['Com_savepoint'],
            'Com_rollback_to_savepoint' => (int) $rows['Com_rollback_to_savepoint'],
        ];
    }
}
